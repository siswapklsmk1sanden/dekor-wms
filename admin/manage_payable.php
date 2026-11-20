<?php
/**
 * Hutang Supplier (v38): faktur supplier, pengingat jatuh tempo, pembayaran, selisih harga vs PO,
 * dan kredit / klaim dari Retur Supplier.
 *  - Faktur boleh dikaitkan ke PO: jumlah per barang dibatasi sebesar yang SUDAH DITERIMA dan belum difakturkan,
 *    dan harga di faktur dibandingkan dengan harga PO (selisih tercatat per faktur).
 *  - Jatuh tempo = tanggal faktur + termin (bawaan per supplier, bisa diubah). Faktur yang terlambat / hampir jatuh
 *    tempo muncul di lonceng notifikasi bagi peran yang berhak melihat modul ini.
 *  - Retur Supplier otomatis membuat KREDIT (nilai = jumlah x harga lot). Finance mengubah nilainya sesuai nota kredit
 *    supplier, lalu memilih penyelesaian: potong faktur (CREDIT), barang pengganti (REPLACEMENT), atau uang kembali (REFUND).
 *  - Hak akses modul 'payable': Lihat, Buat (catat/bayar/terapkan), Setujui (batalkan faktur, pembayaran, kredit).
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/payable_helper.php';
require_once __DIR__ . '/layout.php';
requirePermission('payable', 'view');
wmsRequireSchemaExt();

$canCreate  = canAccess('payable', 'create');
$canApprove = canAccess('payable', 'approve');
$me         = (int)$_SESSION['user_id'];
$csrf       = stockCsrf();
$today      = date('Y-m-d');
$errors     = [];
$methods    = ['Transfer', 'Tunai', 'Giro / Cek', 'Lainnya'];
$resLabel   = ['CREDIT' => 'Potong faktur (nota kredit)', 'REPLACEMENT' => 'Barang pengganti', 'REFUND' => 'Uang dikembalikan'];

function payLockInvoice($id)
{
    global $connect;
    $st = mysqli_prepare($connect, "SELECT * FROM supplier_invoices WHERE id = ? FOR UPDATE");
    $i = (int)$id;
    mysqli_stmt_bind_param($st, 'i', $i);
    mysqli_stmt_execute($st);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if (!$r) throw new PayException('Faktur tidak ditemukan.');
    $r['outstanding'] = round((float)$r['amount_idr'] - (float)$r['paid_idr'] - (float)$r['credit_applied_idr'], 2);
    return $r;
}

function payLockCredit($id)
{
    global $connect;
    $st = mysqli_prepare($connect, "SELECT * FROM supplier_credits WHERE id = ? FOR UPDATE");
    $i = (int)$id;
    mysqli_stmt_bind_param($st, 'i', $i);
    mysqli_stmt_execute($st);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if (!$r) throw new PayException('Kredit tidak ditemukan.');
    $r['applied']   = payCreditApplied($i);
    $r['remaining'] = round((float)$r['amount_idr'] - $r['applied'], 2);
    return $r;
}

// =====================================================================
// AKSI (POST)
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    $_SESSION['flash'] = ['err', 'Ukuran file melebihi batas server (post_max_size). Perkecil file atau naikkan batas di php.ini.'];
    header('Location: manage_payable.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    stockCheckCsrf();
    $act  = (string)($_POST['act'] ?? '');
    $back = 'manage_payable.php';
    $okMsg = '';
    $logMsg = '';
    $logId = 0;
    $logAct = 'UPDATE';
    $pendingFile = null;      // file lampiran baru: dihapus bila transaksi gagal
    $oldToDelete = null;      // file lampiran lama: dihapus setelah transaksi berhasil
    try {
        $needCreate  = ['new_invoice', 'attach_invoice', 'pay', 'apply_credit', 'credit_edit', 'credit_settle', 'credit_new', 'credit_from_return'];
        $needApprove = ['remove_attachment', 'void_pay', 'unapply_credit', 'cancel_invoice', 'credit_cancel', 'credit_reopen'];
        if (in_array($act, $needCreate, true) && !$canCreate) throw new PayException('Akses ditolak: butuh hak Buat pada Hutang Supplier.');
        if (in_array($act, $needApprove, true) && !$canApprove) throw new PayException('Akses ditolak: butuh hak Setujui pada Hutang Supplier.');
        if (!in_array($act, array_merge($needCreate, $needApprove), true)) throw new PayException('Aksi tidak dikenal.');

        mysqli_begin_transaction($connect);

        if ($act === 'new_invoice') {
            $supplierId = (int)($_POST['supplier_id'] ?? 0);
            $poId       = (int)($_POST['po_id'] ?? 0);
            $invNo      = trim((string)($_POST['supplier_invoice_no'] ?? ''));
            $invDate    = trim((string)($_POST['invoice_date'] ?? ''));
            $terms      = (int)($_POST['terms_days'] ?? 30);
            $dueIn      = trim((string)($_POST['due_date'] ?? ''));
            $notes      = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 300);
            $extraRaw   = trim((string)($_POST['extra_idr'] ?? ''));
            $extra      = $extraRaw === '' ? 0.0 : payNum($extraRaw);
            $back       = 'manage_payable.php?new=1&supplier_id=' . $supplierId . ($poId > 0 ? '&po_id=' . $poId : '');

            $q = mysqli_prepare($connect, "SELECT company_name FROM suppliers WHERE id = ?");
            mysqli_stmt_bind_param($q, 'i', $supplierId);
            mysqli_stmt_execute($q);
            $sup = mysqli_fetch_row(mysqli_stmt_get_result($q));
            if (!$sup) throw new PayException('Supplier wajib dipilih.');
            if ($invNo === '') throw new PayException('No. faktur dari supplier wajib diisi.');
            if (mb_strlen($invNo) > 60) throw new PayException('No. faktur terlalu panjang (maks. 60 karakter).');
            if (!payValidDate($invDate)) throw new PayException('Tanggal faktur tidak valid.');
            if ($terms < 0 || $terms > 365) throw new PayException('Termin harus 0 - 365 hari.');
            if ($dueIn !== '' && !payValidDate($dueIn)) throw new PayException('Tanggal jatuh tempo tidak valid.');
            if ($extra === null) throw new PayException('Biaya lain harus berupa angka (boleh negatif untuk potongan).');

            $q = mysqli_prepare($connect, "SELECT COUNT(*) FROM supplier_invoices
                WHERE supplier_id = ? AND supplier_invoice_no = ? AND status <> 'CANCELLED'");
            mysqli_stmt_bind_param($q, 'is', $supplierId, $invNo);
            mysqli_stmt_execute($q);
            if ((int)mysqli_fetch_row(mysqli_stmt_get_result($q))[0] > 0) {
                throw new PayException("Faktur $invNo dari {$sup[0]} sudah tercatat.");
            }

            $lines = [];
            $goods = 0.0;
            $vat = 0.0;
            $poValue = 0.0;
            if ($poId > 0) {
                $q = mysqli_prepare($connect, "SELECT id FROM purchase_orders WHERE id = ? AND supplier_id = ? AND status <> 'CANCELLED'");
                mysqli_stmt_bind_param($q, 'ii', $poId, $supplierId);
                mysqli_stmt_execute($q);
                if (!mysqli_fetch_row(mysqli_stmt_get_result($q))) throw new PayException('PO tidak ditemukan atau bukan milik supplier ini.');

                $ids    = (array)($_POST['pl_id'] ?? []);
                $qtys   = (array)($_POST['pl_qty'] ?? []);
                $prices = (array)($_POST['pl_price'] ?? []);
                $vats   = (array)($_POST['pl_vat'] ?? []);
                foreach ($ids as $k => $lidRaw) {
                    $lid = (int)$lidRaw;
                    $qty = payNum($qtys[$k] ?? '');
                    if ($lid <= 0 || $qty === null || $qty <= 0) continue;       // baris yang tidak difakturkan dilewati
                    $price = payNum($prices[$k] ?? '');
                    if ($price === null || $price < 0) throw new PayException('Harga di faktur harus berupa angka 0 atau lebih.');
                    $vpRaw = trim((string)($vats[$k] ?? ''));
                    $vpct  = $vpRaw === '' ? 0.0 : payNum($vpRaw);
                    if ($vpct === null || $vpct < 0 || $vpct > 100) throw new PayException('PPN (%) harus berupa angka 0 - 100.');

                    $q = mysqli_prepare($connect, "SELECT l.id, l.item_id, l.qty_received, l.unit_price_idr, i.sku
                        FROM purchase_order_lines l JOIN items i ON i.id = l.item_id WHERE l.id = ? AND l.po_id = ? FOR UPDATE");
                    mysqli_stmt_bind_param($q, 'ii', $lid, $poId);
                    mysqli_stmt_execute($q);
                    $pl = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
                    if (!$pl) throw new PayException('Baris PO tidak ditemukan.');

                    $q = mysqli_prepare($connect, "SELECT COALESCE(SUM(il.qty), 0) FROM supplier_invoice_lines il
                        JOIN supplier_invoices si ON si.id = il.invoice_id WHERE il.po_line_id = ? AND si.status <> 'CANCELLED'");
                    mysqli_stmt_bind_param($q, 'i', $lid);
                    mysqli_stmt_execute($q);
                    $already = (float)mysqli_fetch_row(mysqli_stmt_get_result($q))[0];
                    $room = round((float)$pl['qty_received'] - $already, 4);
                    if ($qty > $room + 0.00005) {
                        throw new PayException($pl['sku'] . ': jumlah di faktur (' . fmtQty($qty) . ') melebihi yang sudah diterima dan belum difakturkan ('
                            . fmtQty(max(0, $room)) . '). Terima barangnya dulu di Barang Masuk.');
                    }
                    $lines[]  = ['po_line_id' => $lid, 'item_id' => (int)$pl['item_id'], 'qty' => $qty, 'po_price' => (float)$pl['unit_price_idr'], 'inv_price' => $price, 'vat_pct' => $vpct];
                    $goods   += $qty * $price;
                    $vat     += round($qty * $price * $vpct / 100, 2);
                    $poValue += $qty * (float)$pl['unit_price_idr'];
                }
                if (!$lines) throw new PayException('Isi jumlah pada minimal satu barang yang difakturkan.');
            } else {
                $g = payNum($_POST['goods_idr'] ?? '');
                if ($g === null || $g <= 0) throw new PayException('Nilai faktur wajib diisi (lebih dari 0).');
                $goods = $g;
                $vpRaw = trim((string)($_POST['vat_pct'] ?? ''));
                $vpAll = $vpRaw === '' ? 0.0 : payNum($vpRaw);
                if ($vpAll === null || $vpAll < 0 || $vpAll > 100) throw new PayException('PPN (%) harus berupa angka 0 - 100.');
                $vat = round($g * $vpAll / 100, 2);
            }
            $goods   = round($goods, 2);
            $vat     = round($vat, 2);
            $poValue = round($poValue, 2);
            $amount  = round($goods + $vat + $extra, 2);
            if ($amount <= 0) throw new PayException('Total tagihan harus lebih dari 0.');
            $due = $dueIn !== '' ? $dueIn : date('Y-m-d', strtotime($invDate . ' +' . $terms . ' days'));
            if ($due < $invDate) throw new PayException('Jatuh tempo tidak boleh sebelum tanggal faktur.');

            $goodsS = number_format($goods, 2, '.', '');
            $extraS = number_format($extra, 2, '.', '');
            $vatS   = number_format($vat, 2, '.', '');
            $amtS   = number_format($amount, 2, '.', '');
            $poVS   = number_format($poValue, 2, '.', '');
            $poIdV  = $poId > 0 ? $poId : null;
            $notesV = $notes !== '' ? $notes : null;
            $newId  = 0;
            $code   = '';
            for ($try = 0; $try < 5; $try++) {
                $code = 'FAK-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
                $ins = mysqli_prepare($connect, "INSERT INTO supplier_invoices
                    (inv_code, supplier_invoice_no, supplier_id, po_id, invoice_date, terms_days, due_date,
                     goods_idr, vat_idr, extra_idr, amount_idr, po_value_idr, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($ins, 'ssiisisssssssi', $code, $invNo, $supplierId, $poIdV, $invDate, $terms, $due, $goodsS, $vatS, $extraS, $amtS, $poVS, $notesV, $me);
                if (mysqli_stmt_execute($ins)) { $newId = (int)mysqli_insert_id($connect); break; }
                if (mysqli_errno($connect) !== 1062) throw new PayException('Gagal mencatat faktur: ' . mysqli_error($connect));
            }
            if (!$newId) throw new PayException('Gagal membuat nomor faktur unik.');
            foreach ($lines as $ln) {
                $qS = number_format($ln['qty'], 4, '.', '');
                $pS = number_format($ln['po_price'], 4, '.', '');
                $iS = number_format($ln['inv_price'], 4, '.', '');
                $vpS = number_format($ln['vat_pct'], 2, '.', '');
                $pl_id = $ln['po_line_id'];
                $it_id = $ln['item_id'];
                $li = mysqli_prepare($connect, "INSERT INTO supplier_invoice_lines (invoice_id, po_line_id, item_id, qty, po_price_idr, invoice_price_idr, vat_pct)
                    VALUES (?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($li, 'iiissss', $newId, $pl_id, $it_id, $qS, $pS, $iS, $vpS);
                if (!mysqli_stmt_execute($li)) throw new PayException('Gagal mencatat baris faktur: ' . mysqli_error($connect));
            }
            if (isset($_FILES['attachment']) && (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                [$attFile, $attName] = payStoreAttachment($_FILES['attachment']);
                $pendingFile = $attFile;
                $au = mysqli_prepare($connect, "UPDATE supplier_invoices SET attachment_file = ?, attachment_name = ?, attachment_at = NOW() WHERE id = ?");
                mysqli_stmt_bind_param($au, 'ssi', $attFile, $attName, $newId);
                if (!mysqli_stmt_execute($au)) throw new PayException('Gagal mencatat lampiran: ' . mysqli_error($connect));
            }
            if (!empty($_POST['save_terms'])) {
                $tu = mysqli_prepare($connect, "INSERT INTO supplier_terms (supplier_id, terms_days) VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE terms_days = VALUES(terms_days)");
                mysqli_stmt_bind_param($tu, 'ii', $supplierId, $terms);
                mysqli_stmt_execute($tu);
            }
            $diff = round($goods - $poValue, 2);
            $logAct = 'CREATE'; $logId = $newId;
            $logMsg = "Faktur $code ($invNo) dari {$sup[0]}: Rp " . payRp($amount) . ($vat > 0 ? ' (PPN Rp ' . payRp($vat) . ')' : '') . ", jatuh tempo $due" . ($poId > 0 ? ', selisih harga vs PO Rp ' . payRp($diff) : '');
            $okMsg  = "Faktur $code tercatat, jatuh tempo $due." . ($poId > 0 && abs($diff) >= 0.005 ? ' Ada selisih harga vs PO Rp ' . payRp($diff) . '.' : '');
            $back   = 'manage_payable.php?inv=' . $newId;

        } elseif ($act === 'attach_invoice') {
            $invId = (int)($_POST['invoice_id'] ?? 0);
            $back  = 'manage_payable.php?inv=' . $invId;
            $inv   = payLockInvoice($invId);
            if ($inv['status'] === 'CANCELLED') throw new PayException('Faktur sudah dibatalkan; lampiran tidak bisa diubah.');
            if (!isset($_FILES['attachment']) || (int)($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                throw new PayException('Pilih file lampiran (PDF, JPG, atau PNG).');
            }
            [$attFile, $attName] = payStoreAttachment($_FILES['attachment']);
            $pendingFile = $attFile;
            $oldToDelete = (string)($inv['attachment_file'] ?? '');
            $au = mysqli_prepare($connect, "UPDATE supplier_invoices SET attachment_file = ?, attachment_name = ?, attachment_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($au, 'ssi', $attFile, $attName, $invId);
            if (!mysqli_stmt_execute($au)) throw new PayException('Gagal mencatat lampiran: ' . mysqli_error($connect));
            $logId = $invId;
            $logMsg = "Lampiran scan faktur {$inv['inv_code']} " . ($oldToDelete !== '' ? 'diganti' : 'diunggah') . ": $attName";
            $okMsg  = 'Lampiran tersimpan.';

        } elseif ($act === 'remove_attachment') {
            $invId = (int)($_POST['invoice_id'] ?? 0);
            $back  = 'manage_payable.php?inv=' . $invId;
            $inv   = payLockInvoice($invId);
            if (empty($inv['attachment_file'])) throw new PayException('Faktur ini tidak punya lampiran.');
            $oldToDelete = (string)$inv['attachment_file'];
            $au = mysqli_prepare($connect, "UPDATE supplier_invoices SET attachment_file = NULL, attachment_name = NULL, attachment_at = NULL WHERE id = ?");
            mysqli_stmt_bind_param($au, 'i', $invId);
            mysqli_stmt_execute($au);
            $logId = $invId;
            $logMsg = "Lampiran scan faktur {$inv['inv_code']} dihapus";
            $okMsg  = 'Lampiran dihapus.';

        } elseif ($act === 'pay') {
            $invId = (int)($_POST['invoice_id'] ?? 0);
            $back  = 'manage_payable.php?inv=' . $invId;
            $inv   = payLockInvoice($invId);
            if (!in_array($inv['status'], ['UNPAID', 'PARTIAL'], true)) throw new PayException('Faktur ini tidak menerima pembayaran (status ' . $inv['status'] . ').');
            $amt = payNum($_POST['amount'] ?? '');
            $date = trim((string)($_POST['paid_date'] ?? ''));
            $method = in_array(($_POST['method'] ?? ''), $methods, true) ? $_POST['method'] : 'Transfer';
            $refNo = mb_substr(trim((string)($_POST['ref_no'] ?? '')), 0, 60);
            $pnotes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 200);
            if ($amt === null || $amt <= 0) throw new PayException('Jumlah pembayaran harus lebih dari 0.');
            if (!payValidDate($date)) throw new PayException('Tanggal bayar tidak valid.');
            if ($date > $today) throw new PayException('Tanggal bayar tidak boleh di masa depan.');
            if ($amt > $inv['outstanding'] + 0.005) throw new PayException('Jumlah melebihi sisa tagihan (Rp ' . payRp($inv['outstanding']) . ').');
            $aS = number_format($amt, 2, '.', '');
            $refV = $refNo !== '' ? $refNo : null;
            $pnV = $pnotes !== '' ? $pnotes : null;
            $ins = mysqli_prepare($connect, "INSERT INTO supplier_payments (invoice_id, paid_date, amount_idr, method, ref_no, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($ins, 'isssssi', $invId, $date, $aS, $method, $refV, $pnV, $me);
            if (!mysqli_stmt_execute($ins)) throw new PayException('Gagal mencatat pembayaran: ' . mysqli_error($connect));
            payRecalcInvoice($invId);
            $logAct = 'CREATE'; $logId = $invId;
            $logMsg = "Pembayaran Rp " . payRp($amt) . " untuk faktur {$inv['inv_code']} ($method)";
            $okMsg  = 'Pembayaran Rp ' . payRp($amt) . ' tercatat untuk ' . $inv['inv_code'] . '.';

        } elseif ($act === 'void_pay') {
            $payId = (int)($_POST['payment_id'] ?? 0);
            $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 200);
            if (mb_strlen($reason) < 3) throw new PayException('Alasan pembatalan pembayaran wajib diisi.');
            $q = mysqli_prepare($connect, "SELECT * FROM supplier_payments WHERE id = ? FOR UPDATE");
            mysqli_stmt_bind_param($q, 'i', $payId);
            mysqli_stmt_execute($q);
            $p = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
            if (!$p) throw new PayException('Pembayaran tidak ditemukan.');
            $back = 'manage_payable.php?inv=' . (int)$p['invoice_id'];
            if ((int)$p['is_void'] === 1) throw new PayException('Pembayaran ini sudah dibatalkan.');
            $inv = payLockInvoice((int)$p['invoice_id']);
            if ($inv['status'] === 'CANCELLED') throw new PayException('Faktur sudah dibatalkan.');
            $u = mysqli_prepare($connect, "UPDATE supplier_payments SET is_void = 1, void_note = ? WHERE id = ?");
            mysqli_stmt_bind_param($u, 'si', $reason, $payId);
            mysqli_stmt_execute($u);
            payRecalcInvoice((int)$p['invoice_id']);
            $logId = (int)$p['invoice_id'];
            $logMsg = 'Membatalkan pembayaran Rp ' . payRp($p['amount_idr']) . " faktur {$inv['inv_code']}. Alasan: $reason";
            $okMsg  = 'Pembayaran dibatalkan; sisa tagihan dihitung ulang.';

        } elseif ($act === 'apply_credit') {
            $invId = (int)($_POST['invoice_id'] ?? 0);
            $crId  = (int)($_POST['credit_id'] ?? 0);
            $back  = 'manage_payable.php?inv=' . $invId;
            $inv   = payLockInvoice($invId);
            $cr    = payLockCredit($crId);
            if (!in_array($inv['status'], ['UNPAID', 'PARTIAL'], true)) throw new PayException('Faktur ini tidak bisa dipotong kredit (status ' . $inv['status'] . ').');
            if ($cr['status'] !== 'OPEN' || $cr['resolution'] !== 'CREDIT') throw new PayException('Kredit ini tidak bisa diterapkan ke faktur.');
            if ((int)$cr['supplier_id'] !== (int)$inv['supplier_id']) throw new PayException('Kredit dan faktur harus dari supplier yang sama.');
            $amt = payNum($_POST['amount'] ?? '');
            if ($amt === null || $amt <= 0) throw new PayException('Jumlah kredit yang diterapkan harus lebih dari 0.');
            if ($amt > $cr['remaining'] + 0.005) throw new PayException('Melebihi sisa kredit (Rp ' . payRp($cr['remaining']) . ').');
            if ($amt > $inv['outstanding'] + 0.005) throw new PayException('Melebihi sisa tagihan faktur (Rp ' . payRp($inv['outstanding']) . ').');
            $aS = number_format($amt, 2, '.', '');
            $ins = mysqli_prepare($connect, "INSERT INTO supplier_credit_apps (credit_id, invoice_id, amount_idr, created_by) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($ins, 'iisi', $crId, $invId, $aS, $me);
            if (!mysqli_stmt_execute($ins)) throw new PayException('Gagal menerapkan kredit: ' . mysqli_error($connect));
            payRecalcInvoice($invId);
            payRecalcCredit($crId);
            $logAct = 'CREATE'; $logId = $invId;
            $logMsg = 'Kredit ' . $cr['credit_code'] . ' Rp ' . payRp($amt) . " diterapkan ke faktur {$inv['inv_code']}";
            $okMsg  = 'Kredit ' . $cr['credit_code'] . ' Rp ' . payRp($amt) . ' memotong ' . $inv['inv_code'] . '.';

        } elseif ($act === 'unapply_credit') {
            $appId = (int)($_POST['app_id'] ?? 0);
            $q = mysqli_prepare($connect, "SELECT * FROM supplier_credit_apps WHERE id = ? FOR UPDATE");
            mysqli_stmt_bind_param($q, 'i', $appId);
            mysqli_stmt_execute($q);
            $a = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
            if (!$a) throw new PayException('Penerapan kredit tidak ditemukan.');
            $back = 'manage_payable.php?inv=' . (int)$a['invoice_id'];
            if ((int)$a['is_void'] === 1) throw new PayException('Penerapan ini sudah dibatalkan.');
            $inv = payLockInvoice((int)$a['invoice_id']);
            if ($inv['status'] === 'CANCELLED') throw new PayException('Faktur sudah dibatalkan.');
            $u = mysqli_prepare($connect, "UPDATE supplier_credit_apps SET is_void = 1 WHERE id = ?");
            mysqli_stmt_bind_param($u, 'i', $appId);
            mysqli_stmt_execute($u);
            payRecalcInvoice((int)$a['invoice_id']);
            payRecalcCredit((int)$a['credit_id']);
            $logId = (int)$a['invoice_id'];
            $logMsg = 'Membatalkan penerapan kredit Rp ' . payRp($a['amount_idr']) . " dari faktur {$inv['inv_code']}";
            $okMsg  = 'Penerapan kredit dibatalkan.';

        } elseif ($act === 'cancel_invoice') {
            $invId = (int)($_POST['invoice_id'] ?? 0);
            $back  = 'manage_payable.php?inv=' . $invId;
            $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 200);
            if (mb_strlen($reason) < 3) throw new PayException('Alasan pembatalan faktur wajib diisi.');
            $inv = payLockInvoice($invId);
            if ($inv['status'] === 'CANCELLED') throw new PayException('Faktur ini sudah dibatalkan.');
            $q = mysqli_prepare($connect, "SELECT (SELECT COUNT(*) FROM supplier_payments WHERE invoice_id = ? AND is_void = 0)
                + (SELECT COUNT(*) FROM supplier_credit_apps WHERE invoice_id = ? AND is_void = 0)");
            mysqli_stmt_bind_param($q, 'ii', $invId, $invId);
            mysqli_stmt_execute($q);
            if ((int)mysqli_fetch_row(mysqli_stmt_get_result($q))[0] > 0) {
                throw new PayException('Batalkan dulu semua pembayaran dan penerapan kredit pada faktur ini.');
            }
            $u = mysqli_prepare($connect, "UPDATE supplier_invoices SET status = 'CANCELLED', cancelled_note = ? WHERE id = ?");
            mysqli_stmt_bind_param($u, 'si', $reason, $invId);
            mysqli_stmt_execute($u);
            $logId = $invId;
            $logMsg = "Membatalkan faktur {$inv['inv_code']}. Alasan: $reason";
            $okMsg  = "Faktur {$inv['inv_code']} dibatalkan.";

        } elseif ($act === 'credit_edit') {
            $crId = (int)($_POST['credit_id'] ?? 0);
            $cr   = payLockCredit($crId);
            if ($cr['status'] === 'CANCELLED') throw new PayException('Kredit ini sudah dibatalkan.');
            $res  = (string)($_POST['resolution'] ?? '');
            if (!isset($resLabel[$res])) throw new PayException('Penyelesaian tidak valid.');
            $amt  = payNum($_POST['amount_idr'] ?? '');
            if ($amt === null || $amt < 0) throw new PayException('Nilai kredit harus berupa angka 0 atau lebih.');
            if ($cr['applied'] > 0.005 && $res !== 'CREDIT') throw new PayException('Kredit sudah dipakai memotong faktur; penyelesaiannya tidak bisa diganti.');
            if ($amt < $cr['applied'] - 0.005) throw new PayException('Nilai tidak boleh di bawah kredit yang sudah diterapkan (Rp ' . payRp($cr['applied']) . ').');
            $docNo = mb_substr(trim((string)($_POST['supplier_doc_no'] ?? '')), 0, 60);
            $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 300);
            $aS = number_format($amt, 2, '.', '');
            $dV = $docNo !== '' ? $docNo : null;
            $nV = $notes !== '' ? $notes : null;
            $u = mysqli_prepare($connect, "UPDATE supplier_credits SET resolution = ?, amount_idr = ?, supplier_doc_no = ?, notes = ? WHERE id = ?");
            mysqli_stmt_bind_param($u, 'ssssi', $res, $aS, $dV, $nV, $crId);
            mysqli_stmt_execute($u);
            payRecalcCredit($crId);
            $logId = $crId;
            $logMsg = "Memperbarui kredit {$cr['credit_code']}: {$resLabel[$res]}, nilai Rp " . payRp($amt) . ' (hitungan sistem Rp ' . payRp($cr['calc_idr']) . ')';
            $okMsg  = "Kredit {$cr['credit_code']} diperbarui.";

        } elseif ($act === 'credit_settle') {
            $crId = (int)($_POST['credit_id'] ?? 0);
            $cr   = payLockCredit($crId);
            if ($cr['status'] !== 'OPEN') throw new PayException('Kredit ini tidak berstatus terbuka.');
            if ($cr['resolution'] === 'CREDIT') throw new PayException('Kredit bertipe potong faktur selesai otomatis saat habis diterapkan.');
            $u = mysqli_prepare($connect, "UPDATE supplier_credits SET status = 'SETTLED', settled_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($u, 'i', $crId);
            mysqli_stmt_execute($u);
            $logId = $crId;
            $logMsg = "Kredit {$cr['credit_code']} ditandai selesai ({$resLabel[$cr['resolution']]})";
            $okMsg  = "Kredit {$cr['credit_code']} ditandai selesai.";

        } elseif ($act === 'credit_reopen') {
            $crId = (int)($_POST['credit_id'] ?? 0);
            $cr   = payLockCredit($crId);
            if ($cr['status'] !== 'SETTLED' || $cr['resolution'] === 'CREDIT') throw new PayException('Hanya kredit penggantian / uang kembali yang sudah selesai yang bisa dibuka lagi.');
            $u = mysqli_prepare($connect, "UPDATE supplier_credits SET status = 'OPEN', settled_at = NULL WHERE id = ?");
            mysqli_stmt_bind_param($u, 'i', $crId);
            mysqli_stmt_execute($u);
            $logId = $crId;
            $logMsg = "Kredit {$cr['credit_code']} dibuka kembali";
            $okMsg  = "Kredit {$cr['credit_code']} dibuka kembali.";

        } elseif ($act === 'credit_cancel') {
            $crId = (int)($_POST['credit_id'] ?? 0);
            $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 150);
            if (mb_strlen($reason) < 3) throw new PayException('Alasan pembatalan kredit wajib diisi.');
            $cr = payLockCredit($crId);
            if ($cr['status'] === 'CANCELLED') throw new PayException('Kredit ini sudah dibatalkan.');
            if ($cr['applied'] > 0.005) throw new PayException('Batalkan dulu penerapan kredit ini pada faktur.');
            $note = mb_substr(trim(($cr['notes'] ?? '') . ' [Dibatalkan: ' . $reason . ']'), 0, 300);
            $u = mysqli_prepare($connect, "UPDATE supplier_credits SET status = 'CANCELLED', notes = ? WHERE id = ?");
            mysqli_stmt_bind_param($u, 'si', $note, $crId);
            mysqli_stmt_execute($u);
            $logId = $crId;
            $logMsg = "Membatalkan kredit {$cr['credit_code']}. Alasan: $reason";
            $okMsg  = "Kredit {$cr['credit_code']} dibatalkan.";

        } elseif ($act === 'credit_new') {
            $supplierId = (int)($_POST['supplier_id'] ?? 0);
            $amt  = payNum($_POST['amount_idr'] ?? '');
            $res  = (string)($_POST['resolution'] ?? 'CREDIT');
            $docNo = mb_substr(trim((string)($_POST['supplier_doc_no'] ?? '')), 0, 60);
            $notes = mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 300);
            $q = mysqli_prepare($connect, "SELECT company_name FROM suppliers WHERE id = ?");
            mysqli_stmt_bind_param($q, 'i', $supplierId);
            mysqli_stmt_execute($q);
            $sup = mysqli_fetch_row(mysqli_stmt_get_result($q));
            if (!$sup) throw new PayException('Supplier wajib dipilih.');
            if (!isset($resLabel[$res])) throw new PayException('Penyelesaian tidak valid.');
            if ($amt === null || $amt <= 0) throw new PayException('Nilai klaim harus lebih dari 0.');
            if (mb_strlen($notes) < 3) throw new PayException('Keterangan klaim wajib diisi (min. 3 karakter).');
            $aS = number_format($amt, 2, '.', '');
            $dV = $docNo !== '' ? $docNo : null;
            $code = '';
            $ok = false;
            for ($try = 0; $try < 5; $try++) {
                $code = 'KRD-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
                $ins = mysqli_prepare($connect, "INSERT INTO supplier_credits (credit_code, supplier_id, calc_idr, amount_idr, resolution, status, supplier_doc_no, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, 'OPEN', ?, ?, ?)");
                mysqli_stmt_bind_param($ins, 'sisssssi', $code, $supplierId, $aS, $aS, $res, $dV, $notes, $me);
                if (mysqli_stmt_execute($ins)) { $ok = true; $logId = (int)mysqli_insert_id($connect); break; }
                if (mysqli_errno($connect) !== 1062) throw new PayException('Gagal mencatat klaim: ' . mysqli_error($connect));
            }
            if (!$ok) throw new PayException('Gagal membuat nomor kredit unik.');
            $logAct = 'CREATE';
            $logMsg = "Klaim manual $code ke {$sup[0]}: {$resLabel[$res]}, Rp " . payRp($amt);
            $okMsg  = "Klaim $code tercatat.";

        } elseif ($act === 'credit_from_return') {
            $trxCode = trim((string)($_POST['trx_code'] ?? ''));
            $q = mysqli_prepare($connect, "SELECT t.id, t.reference_doc_no, l.item_id, l.batch_id, SUM(-l.signed_qty) AS qty,
                    MAX(l.unit_cost_idr) AS unit_cost, b.supplier_id
                FROM inventory_transactions t JOIN inventory_ledger_entries l ON l.transaction_id = t.id
                JOIN item_batches b ON b.id = l.batch_id
                WHERE t.trx_code = ? AND t.trx_type = 'SCRAP_WRITE_OFF' AND t.reference_doc_no LIKE 'RTR-%' AND b.supplier_id IS NOT NULL
                GROUP BY t.id, t.reference_doc_no, l.item_id, l.batch_id, b.supplier_id");
            mysqli_stmt_bind_param($q, 's', $trxCode);
            mysqli_stmt_execute($q);
            $r = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
            if (!$r) throw new PayException('Transaksi retur tidak ditemukan.');
            $q = mysqli_prepare($connect, "SELECT COUNT(*) FROM supplier_credits WHERE return_ref = ? AND status <> 'CANCELLED'");
            mysqli_stmt_bind_param($q, 's', $r['reference_doc_no']);
            mysqli_stmt_execute($q);
            if ((int)mysqli_fetch_row(mysqli_stmt_get_result($q))[0] > 0) throw new PayException('Retur ini sudah punya kredit.');
            $code = payCreateReturnCredit((int)$r['supplier_id'], (int)$r['id'], $r['reference_doc_no'], (int)$r['item_id'], (int)$r['batch_id'],
                (float)$r['qty'], (float)$r['unit_cost'], 'Dibuat dari retur yang tercatat sebelum fitur kredit.', $me);
            $logAct = 'CREATE'; $logId = (int)$r['id'];
            $logMsg = "Kredit $code dibuat dari retur {$r['reference_doc_no']}";
            $okMsg  = "Kredit $code dibuat dari retur {$r['reference_doc_no']}. Sesuaikan nilainya dengan nota kredit supplier.";
        }

        mysqli_commit($connect);
        if (!empty($oldToDelete)) payDeleteAttachment($oldToDelete);
        logActivity($logAct, 'Hutang Supplier', $logMsg, $logId);
        $_SESSION['flash'] = ['ok', $okMsg];
        header('Location: ' . $back);
        exit;
    } catch (Throwable $ex) {
        try { mysqli_rollback($connect); } catch (Throwable $e2) {}
        if (!empty($pendingFile)) payDeleteAttachment($pendingFile);
        $errors[] = $ex instanceof PayException ? $ex->getMessage() : 'Terjadi kesalahan: ' . $ex->getMessage();
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$invId = (int)($_GET['inv'] ?? 0);
$isNew = isset($_GET['new']) || (($_SERVER['REQUEST_METHOD'] === 'POST') && (($_POST['act'] ?? '') === 'new_invoice'));

// Pilihan supplier (dipakai beberapa tampilan)
$suppliers = mysqli_fetch_all(mysqli_query($connect, "SELECT id, company_name FROM suppliers WHERE is_active = 1 ORDER BY company_name"), MYSQLI_ASSOC);

function payStatusTag($inv)
{
    $map = ['UNPAID' => ['Belum dibayar', 'off'], 'PARTIAL' => ['Dibayar sebagian', 'off'], 'PAID' => ['Lunas', ''], 'CANCELLED' => ['Dibatalkan', 'off']];
    $m = $map[$inv['status']] ?? [$inv['status'], 'off'];
    return '<span class="tag ' . $m[1] . '">' . e($m[0]) . '</span>';
}

function payDueText($inv, $today)
{
    if (!in_array($inv['status'], ['UNPAID', 'PARTIAL'], true)) return '';
    $d = (int)round((strtotime($today) - strtotime($inv['due_date'])) / 86400);
    if ($d > 0) return '<br><span class="tag off" style="background:#FDECEA;color:#B3261E">Terlambat ' . $d . ' hari</span>';
    if ($d === 0) return '<br><span class="tag off" style="background:#FFF4D6;color:#8a5a00">Jatuh tempo hari ini</span>';
    if ($d >= -7) return '<br><span class="tag off" style="background:#FFF4D6;color:#8a5a00">' . (-$d) . ' hari lagi</span>';
    return '';
}

$inp = 'style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px"';
renderHeader('Hutang Supplier');
?>
<h2>Hutang Supplier</h2>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>

<?php
// =====================================================================
// TAMPILAN: DETAIL FAKTUR
// =====================================================================
if ($invId > 0 && !$isNew):
    $st = mysqli_prepare($connect, "SELECT si.*, s.company_name, p.po_number, cu.username
        FROM supplier_invoices si JOIN suppliers s ON s.id = si.supplier_id
        LEFT JOIN purchase_orders p ON p.id = si.po_id JOIN users cu ON cu.id = si.created_by WHERE si.id = ?");
    mysqli_stmt_bind_param($st, 'i', $invId);
    mysqli_stmt_execute($st);
    $inv = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if (!$inv):
?>
<div class="err">Faktur tidak ditemukan.</div><p><a class="btn sec" href="manage_payable.php">Kembali</a></p>
<?php
    else:
        $outstanding = round((float)$inv['amount_idr'] - (float)$inv['paid_idr'] - (float)$inv['credit_applied_idr'], 2);
        $open = in_array($inv['status'], ['UNPAID', 'PARTIAL'], true);

        $st = mysqli_prepare($connect, "SELECT il.*, i.sku, i.item_name, u.unit_code FROM supplier_invoice_lines il
            JOIN items i ON i.id = il.item_id JOIN units u ON u.id = i.unit_id WHERE il.invoice_id = ? ORDER BY il.id");
        mysqli_stmt_bind_param($st, 'i', $invId);
        mysqli_stmt_execute($st);
        $ilines = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

        $st = mysqli_prepare($connect, "SELECT pm.*, u.username FROM supplier_payments pm JOIN users u ON u.id = pm.created_by
            WHERE pm.invoice_id = ? ORDER BY pm.id");
        mysqli_stmt_bind_param($st, 'i', $invId);
        mysqli_stmt_execute($st);
        $pays = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

        $st = mysqli_prepare($connect, "SELECT a.*, c.credit_code, c.return_ref FROM supplier_credit_apps a
            JOIN supplier_credits c ON c.id = a.credit_id WHERE a.invoice_id = ? ORDER BY a.id");
        mysqli_stmt_bind_param($st, 'i', $invId);
        mysqli_stmt_execute($st);
        $apps = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

        $availCredits = [];
        if ($open) {
            $st = mysqli_prepare($connect, "SELECT c.id, c.credit_code, c.return_ref, c.amount_idr,
                    c.amount_idr - COALESCE((SELECT SUM(a.amount_idr) FROM supplier_credit_apps a WHERE a.credit_id = c.id AND a.is_void = 0), 0) AS remaining
                FROM supplier_credits c WHERE c.supplier_id = ? AND c.status = 'OPEN' AND c.resolution = 'CREDIT' ORDER BY c.id");
            $sid = (int)$inv['supplier_id'];
            mysqli_stmt_bind_param($st, 'i', $sid);
            mysqli_stmt_execute($st);
            foreach (mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC) as $c) {
                if ((float)$c['remaining'] > 0.005) $availCredits[] = $c;
            }
        }
        $diff = round((float)$inv['goods_idr'] - (float)$inv['po_value_idr'], 2);
        $hasActive = false;
        foreach ($pays as $p) if (!(int)$p['is_void']) $hasActive = true;
        foreach ($apps as $a) if (!(int)$a['is_void']) $hasActive = true;
?>
<p><a class="btn sec" href="manage_payable.php">&lsaquo; Semua faktur</a></p>
<div class="panel">
  <strong><?= e($inv['inv_code']) ?></strong> <?= payStatusTag($inv) ?><?= payDueText($inv, $today) ?>
  <div class="grid" style="margin-top:10px">
    <div><small>Supplier</small><br><b><?= e($inv['company_name']) ?></b></div>
    <div><small>No. faktur supplier</small><br><b><?= e($inv['supplier_invoice_no']) ?></b></div>
    <div><small>PO terkait</small><br><?php if ($inv['po_number']): ?><a href="manage_po.php?id=<?= (int)$inv['po_id'] ?>"><?= e($inv['po_number']) ?></a><?php else: ?>-<?php endif; ?></div>
    <div><small>Tanggal faktur / termin</small><br><?= e($inv['invoice_date']) ?> / <?= (int)$inv['terms_days'] ?> hari</div>
    <div><small>Jatuh tempo</small><br><b><?= e($inv['due_date']) ?></b></div>
    <div><small>Dicatat oleh</small><br><?= e($inv['username']) ?> <small><?= e(substr((string)$inv['created_at'], 0, 16)) ?></small></div>
  </div>
  <?php if (!empty($inv['notes'])): ?><p style="margin:10px 0 0"><small>Catatan:</small> <?= e($inv['notes']) ?></p><?php endif; ?>
  <?php if ($inv['status'] === 'CANCELLED'): ?><p style="margin:10px 0 0"><small>Dibatalkan:</small> <?= e($inv['cancelled_note'] ?? '') ?></p><?php endif; ?>
</div>

<div class="cards">
  <div class="card"><b>Rp <?= e(payRp($inv['amount_idr'])) ?></b>Total tagihan</div>
  <div class="card"><b>Rp <?= e(payRp($inv['paid_idr'])) ?></b>Sudah dibayar</div>
  <div class="card"><b>Rp <?= e(payRp($inv['credit_applied_idr'])) ?></b>Dipotong kredit retur</div>
  <div class="card <?= ($open && $outstanding > 0 && $inv['due_date'] < $today) ? 'warn' : '' ?>"><b>Rp <?= e(payRp($inv['status'] === 'CANCELLED' ? 0 : $outstanding)) ?></b>Sisa tagihan</div>
</div>

<div class="panel scroll">
  <strong>Rincian barang &amp; selisih harga vs PO</strong>
  <?php if (!$ilines): ?>
    <p style="margin:8px 0 0">Faktur ini dicatat tanpa PO: nilai barang Rp <?= e(payRp($inv['goods_idr'])) ?><?= (float)($inv['vat_idr'] ?? 0) >= 0.005 ? ', PPN Rp ' . e(payRp($inv['vat_idr'])) : '' ?><?= abs((float)$inv['extra_idr']) >= 0.005 ? ', biaya lain Rp ' . e(payRp($inv['extra_idr'])) : '' ?>.</p>
  <?php else: ?>
  <table>
    <tr><th>Barang</th><th>Jumlah</th><th>Harga PO</th><th>Harga di faktur</th><th>PPN</th><th>Selisih / satuan</th><th>Selisih total</th></tr>
    <?php foreach ($ilines as $l):
        $d1 = round((float)$l['invoice_price_idr'] - (float)$l['po_price_idr'], 4);
        $d2 = round($d1 * (float)$l['qty'], 2); ?>
      <tr><td><?= e($l['sku']) ?><br><small><?= e($l['item_name']) ?></small></td>
        <td><?= e(fmtQty($l['qty'])) ?> <?= e($l['unit_code']) ?></td>
        <td><?= e(payRp($l['po_price_idr'])) ?></td><td><?= e(payRp($l['invoice_price_idr'])) ?></td>
        <td><?= e(payRp($l['vat_pct'] ?? 0)) ?>%<br><small>Rp <?= e(payRp(round((float)$l['qty'] * (float)$l['invoice_price_idr'] * (float)($l['vat_pct'] ?? 0) / 100, 2))) ?></small></td>
        <td style="<?= $d1 > 0.00005 ? 'color:#B3261E' : ($d1 < -0.00005 ? 'color:#1E6B34' : '') ?>"><?= $d1 > 0 ? '+' : '' ?><?= e(payRp($d1)) ?></td>
        <td style="<?= $d2 > 0.004 ? 'color:#B3261E' : ($d2 < -0.004 ? 'color:#1E6B34' : '') ?>"><b><?= $d2 > 0 ? '+' : '' ?><?= e(payRp($d2)) ?></b></td></tr>
    <?php endforeach; ?>
    <tr><td colspan="6" style="text-align:right">Nilai barang menurut faktur / menurut harga PO</td><td>Rp <?= e(payRp($inv['goods_idr'])) ?> / Rp <?= e(payRp($inv['po_value_idr'])) ?></td></tr>
    <tr><td colspan="6" style="text-align:right"><b>Selisih harga (faktur - PO)</b><?= $diff > 0.004 ? ' <small>(supplier menagih lebih mahal dari PO)</small>' : '' ?></td>
      <td><b><?= $diff > 0 ? '+' : '' ?><?= e(payRp($diff)) ?></b></td></tr>
    <tr><td colspan="6" style="text-align:right">PPN (jumlah per baris)</td><td><?= e(payRp($inv['vat_idr'] ?? 0)) ?></td></tr>
    <tr><td colspan="6" style="text-align:right">Biaya lain (ongkir / pembulatan / potongan)</td><td><?= e(payRp($inv['extra_idr'])) ?></td></tr>
  </table>
  <?php endif; ?>
</div>

<div class="panel" id="lampiran">
  <strong>Lampiran scan faktur</strong>
  <?php if (!empty($inv['attachment_file'])): ?>
    <p style="margin:8px 0"><a href="invoice_file.php?id=<?= (int)$invId ?>" target="_blank" rel="noopener"><?= e($inv['attachment_name'] ?: 'Buka lampiran') ?></a>
      <small>diunggah <?= e(substr((string)$inv['attachment_at'], 0, 16)) ?></small></p>
  <?php else: ?>
    <p style="margin:8px 0">Belum ada lampiran.</p>
  <?php endif; ?>
  <?php if ($canCreate && $inv['status'] !== 'CANCELLED'): ?>
  <form method="post" enctype="multipart/form-data" action="manage_payable.php?inv=<?= (int)$invId ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="attach_invoice"><input type="hidden" name="invoice_id" value="<?= (int)$invId ?>">
    <input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" required>
    <button type="submit" class="sec"><?= !empty($inv['attachment_file']) ? 'Ganti lampiran' : 'Unggah lampiran' ?></button>
    <small>PDF / JPG / PNG, maks. 5 MB</small>
  </form>
  <?php endif; ?>
  <?php if ($canApprove && !empty($inv['attachment_file'])): ?>
  <form method="post" class="inline" action="manage_payable.php?inv=<?= (int)$invId ?>" style="margin-top:8px" onsubmit="return confirm('Hapus lampiran faktur ini?')">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="remove_attachment"><input type="hidden" name="invoice_id" value="<?= (int)$invId ?>">
    <button type="submit" class="sec">Hapus lampiran</button>
  </form>
  <?php endif; ?>
</div>

<div class="panel scroll">
  <strong>Pembayaran</strong>
  <table>
    <tr><th>Tanggal</th><th>Jumlah (Rp)</th><th>Cara</th><th>Referensi</th><th>Dicatat</th><th>Aksi</th></tr>
    <?php if (!$pays): ?><tr><td colspan="6">Belum ada pembayaran.</td></tr><?php endif; ?>
    <?php foreach ($pays as $p): $void = (int)$p['is_void'] === 1; ?>
      <tr style="<?= $void ? 'opacity:.55;text-decoration:line-through' : '' ?>">
        <td><?= e($p['paid_date']) ?></td><td><?= e(payRp($p['amount_idr'])) ?></td><td><?= e($p['method']) ?></td>
        <td><?= e($p['ref_no'] ?? '') ?><?php if (!empty($p['notes'])): ?><br><small><?= e($p['notes']) ?></small><?php endif; ?></td>
        <td><?= e($p['username']) ?><br><small><?= e(substr((string)$p['created_at'], 0, 16)) ?></small></td>
        <td style="text-decoration:none">
          <?php if ($void): ?><small>Batal: <?= e($p['void_note'] ?? '') ?></small>
          <?php elseif ($canApprove && $inv['status'] !== 'CANCELLED'): ?>
            <form method="post" action="manage_payable.php?inv=<?= (int)$invId ?>" class="inline">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="void_pay"><input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
              <input type="text" name="reason" maxlength="200" placeholder="Alasan batal *" required style="width:120px;padding:6px">
              <button type="submit" class="danger" onclick="return confirm('Batalkan pembayaran ini?')">Batalkan</button>
            </form>
          <?php else: ?>-<?php endif; ?>
        </td></tr>
    <?php endforeach; ?>
  </table>
  <?php if ($open && $canCreate): ?>
  <form method="post" action="manage_payable.php?inv=<?= (int)$invId ?>" autocomplete="off" style="margin-top:14px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="pay"><input type="hidden" name="invoice_id" value="<?= (int)$invId ?>">
    <div class="grid">
      <div><label>Tanggal bayar *</label><input type="date" name="paid_date" max="<?= e($today) ?>" value="<?= e($today) ?>" required <?= $inp ?>></div>
      <div><label>Jumlah (Rp) *</label><input type="number" name="amount" step="0.01" min="0.01" max="<?= e(number_format($outstanding, 2, '.', '')) ?>" value="<?= e(number_format($outstanding, 2, '.', '')) ?>" required <?= $inp ?>></div>
      <div><label>Cara bayar</label><select name="method" <?= $inp ?>><?php foreach ($methods as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></div>
      <div><label>No. bukti / referensi</label><input type="text" name="ref_no" maxlength="60" <?= $inp ?>></div>
      <div><label>Catatan</label><input type="text" name="notes" maxlength="200" <?= $inp ?>></div>
    </div>
    <p style="margin:12px 0 0"><button type="submit">Catat Pembayaran</button></p>
  </form>
  <?php endif; ?>
</div>

<div class="panel scroll">
  <strong>Kredit retur yang memotong faktur ini</strong>
  <table>
    <tr><th>Kredit</th><th>Jumlah (Rp)</th><th>Aksi</th></tr>
    <?php if (!$apps): ?><tr><td colspan="3">Belum ada kredit yang diterapkan.</td></tr><?php endif; ?>
    <?php foreach ($apps as $a): $void = (int)$a['is_void'] === 1; ?>
      <tr style="<?= $void ? 'opacity:.55;text-decoration:line-through' : '' ?>">
        <td><?= e($a['credit_code']) ?><?= $a['return_ref'] ? ' <small>(' . e($a['return_ref']) . ')</small>' : '' ?></td>
        <td><?= e(payRp($a['amount_idr'])) ?></td>
        <td style="text-decoration:none"><?php if ($void): ?><small>dibatalkan</small>
          <?php elseif ($canApprove && $inv['status'] !== 'CANCELLED'): ?>
            <form method="post" action="manage_payable.php?inv=<?= (int)$invId ?>" class="inline">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="unapply_credit"><input type="hidden" name="app_id" value="<?= (int)$a['id'] ?>">
              <button type="submit" class="danger" onclick="return confirm('Batalkan penerapan kredit ini?')">Batalkan</button>
            </form>
          <?php else: ?>-<?php endif; ?></td></tr>
    <?php endforeach; ?>
  </table>
  <?php if ($open && $canCreate): ?>
    <?php if (!$availCredits): ?>
      <p style="margin:10px 0 0"><small>Tidak ada kredit retur terbuka dari supplier ini.</small></p>
    <?php else: ?>
      <?php foreach ($availCredits as $c):
          $maxApply = min((float)$c['remaining'], $outstanding); ?>
      <form method="post" action="manage_payable.php?inv=<?= (int)$invId ?>" autocomplete="off" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="apply_credit">
        <input type="hidden" name="invoice_id" value="<?= (int)$invId ?>"><input type="hidden" name="credit_id" value="<?= (int)$c['id'] ?>">
        <span><?= e($c['credit_code']) ?><?= $c['return_ref'] ? ' (' . e($c['return_ref']) . ')' : '' ?> - sisa Rp <?= e(payRp($c['remaining'])) ?></span>
        <input type="number" name="amount" step="0.01" min="0.01" max="<?= e(number_format($maxApply, 2, '.', '')) ?>" value="<?= e(number_format($maxApply, 2, '.', '')) ?>" style="max-width:160px" required>
        <button type="submit" class="sec">Potong ke faktur ini</button>
      </form>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($canApprove && $inv['status'] !== 'CANCELLED' && !$hasActive): ?>
<div class="panel">
  <form method="post" action="manage_payable.php?inv=<?= (int)$invId ?>" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="cancel_invoice"><input type="hidden" name="invoice_id" value="<?= (int)$invId ?>">
    <small>Faktur salah input?</small>
    <input type="text" name="reason" maxlength="200" placeholder="Alasan pembatalan *" required style="max-width:320px">
    <button type="submit" class="danger" onclick="return confirm('Batalkan faktur ini?')">Batalkan Faktur</button>
  </form>
</div>
<?php endif; ?>

<?php
    endif;   // faktur ditemukan

// =====================================================================
// TAMPILAN: CATAT FAKTUR BARU
// =====================================================================
elseif ($isNew):
    if (!$canCreate): ?>
<div class="err">Akses ditolak: butuh hak Buat pada Hutang Supplier.</div>
<?php else:
    $fSup = (int)($_POST['supplier_id'] ?? $_GET['supplier_id'] ?? 0);
    $fPo  = (int)($_POST['po_id'] ?? $_GET['po_id'] ?? 0);
    $supName = '';
    foreach ($suppliers as $s) if ((int)$s['id'] === $fSup) $supName = $s['company_name'];
    if ($fSup && $supName === '') $fSup = 0;
?>
<p><a class="btn sec" href="manage_payable.php">&lsaquo; Semua faktur</a></p>
<div class="panel">
  <strong>Catat faktur supplier</strong>
  <form method="get" style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:end">
    <input type="hidden" name="new" value="1">
    <div><label>Supplier *</label>
      <select name="supplier_id" required onchange="this.form.submit()" style="min-width:260px">
        <option value="">- pilih supplier -</option>
        <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $fSup ? 'selected' : '' ?>><?= e($s['company_name']) ?></option><?php endforeach; ?>
      </select></div>
    <?php if ($fSup):
        $st = mysqli_prepare($connect, "SELECT p.id, p.po_number, p.order_date, p.status FROM purchase_orders p
            WHERE p.supplier_id = ? AND p.status <> 'CANCELLED'
              AND EXISTS (SELECT 1 FROM purchase_order_lines l WHERE l.po_id = p.id AND l.qty_received > 0)
            ORDER BY p.id DESC LIMIT 100");
        mysqli_stmt_bind_param($st, 'i', $fSup);
        mysqli_stmt_execute($st);
        $pos = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC); ?>
    <div><label>PO yang difakturkan (opsional)</label>
      <select name="po_id" onchange="this.form.submit()" style="min-width:260px">
        <option value="">- tanpa PO (isi nilai manual) -</option>
        <?php foreach ($pos as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $fPo ? 'selected' : '' ?>><?= e($p['po_number'] . ' (' . $p['order_date'] . ', ' . $p['status'] . ')') ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <noscript><button type="submit">Lanjut</button></noscript>
  </form>
  <?php if ($fSup && $fPo): ?><small>Hanya barang yang sudah diterima di Barang Masuk yang bisa difakturkan; harga di faktur dibandingkan dengan harga PO.</small><?php endif; ?>
</div>

<?php if ($fSup):
    $f = [
        'supplier_invoice_no' => trim((string)($_POST['supplier_invoice_no'] ?? '')),
        'invoice_date' => trim((string)($_POST['invoice_date'] ?? $today)),
        'terms_days'   => (string)($_POST['terms_days'] ?? payTermsFor($fSup)),
        'due_date'     => trim((string)($_POST['due_date'] ?? '')),
        'goods_idr'    => trim((string)($_POST['goods_idr'] ?? '')),
        'extra_idr'    => trim((string)($_POST['extra_idr'] ?? '')),
        'vat_pct'      => trim((string)($_POST['vat_pct'] ?? '')),
        'notes'        => trim((string)($_POST['notes'] ?? '')),
    ];
    $plines = [];
    if ($fPo) {
        $st = mysqli_prepare($connect, "SELECT l.id, l.item_id, l.qty_ordered, l.qty_received, l.unit_price_idr, i.sku, i.item_name, u.unit_code,
                COALESCE((SELECT SUM(il.qty) FROM supplier_invoice_lines il JOIN supplier_invoices si ON si.id = il.invoice_id
                          WHERE il.po_line_id = l.id AND si.status <> 'CANCELLED'), 0) AS invoiced
            FROM purchase_order_lines l JOIN items i ON i.id = l.item_id JOIN units u ON u.id = i.unit_id
            JOIN purchase_orders p ON p.id = l.po_id
            WHERE l.po_id = ? AND p.supplier_id = ? ORDER BY l.id");
        mysqli_stmt_bind_param($st, 'ii', $fPo, $fSup);
        mysqli_stmt_execute($st);
        $plines = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
    }
    $postQty = (array)($_POST['pl_qty'] ?? []);
    $postPrice = (array)($_POST['pl_price'] ?? []);
    $postVat = (array)($_POST['pl_vat'] ?? []);
    $postIds = array_map('intval', (array)($_POST['pl_id'] ?? []));
?>
<div class="panel">
  <form method="post" enctype="multipart/form-data" autocomplete="off" action="manage_payable.php?new=1&amp;supplier_id=<?= (int)$fSup ?><?= $fPo ? '&amp;po_id=' . (int)$fPo : '' ?>">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="new_invoice">
    <input type="hidden" name="supplier_id" value="<?= (int)$fSup ?>"><input type="hidden" name="po_id" value="<?= (int)$fPo ?>">
    <div class="grid">
      <div><label>Supplier</label><input type="text" value="<?= e($supName) ?>" disabled <?= $inp ?>></div>
      <div><label>No. faktur dari supplier *</label><input type="text" name="supplier_invoice_no" maxlength="60" value="<?= e($f['supplier_invoice_no']) ?>" required <?= $inp ?>></div>
      <div><label>Tanggal faktur *</label><input type="date" name="invoice_date" id="invDate" value="<?= e($f['invoice_date']) ?>" required <?= $inp ?>></div>
      <div><label>Termin (hari) *</label><input type="number" name="terms_days" id="invTerms" min="0" max="365" value="<?= e($f['terms_days']) ?>" required <?= $inp ?>></div>
      <div><label>Jatuh tempo <small>(otomatis = tanggal + termin)</small></label><input type="date" name="due_date" id="invDue" value="<?= e($f['due_date']) ?>" <?= $inp ?>></div>
      <?php if (!$fPo): ?>
      <div><label>Nilai barang di faktur (Rp) *</label><input type="number" name="goods_idr" step="0.01" min="0.01" value="<?= e($f['goods_idr']) ?>" required <?= $inp ?>></div>
      <div><label>PPN (%) <small>dari nilai barang</small></label><input type="number" name="vat_pct" id="invVat" step="0.01" min="0" max="100" value="<?= e($f['vat_pct']) ?>" placeholder="0" <?= $inp ?>></div>
      <?php endif; ?>
      <div><label>Biaya lain (Rp) <small>ongkir / pembulatan; negatif = potongan</small></label><input type="number" name="extra_idr" id="invExtra" step="0.01" value="<?= e($f['extra_idr']) ?>" <?= $inp ?>></div>
      <div><label>Catatan</label><input type="text" name="notes" maxlength="300" value="<?= e($f['notes']) ?>" <?= $inp ?>></div>
      <div><label>Lampiran scan faktur <small>PDF / JPG / PNG, maks. 5 MB (opsional)</small></label><input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" <?= $inp ?>></div>
    </div>

    <?php if ($fPo): ?>
    <p style="margin:14px 0 0"><label style="display:inline">PPN (%) untuk semua baris <input type="number" id="vatAll" step="0.01" min="0" max="100" placeholder="mis. 11" style="max-width:90px;padding:6px"></label>
      <button type="button" class="sec" id="vatAllBtn">Terapkan</button> <small>atau isi PPN tiap baris sendiri; kosong = 0%</small></p>
    <div class="scroll" style="margin-top:8px">
      <table>
        <tr><th>Barang</th><th>Diterima</th><th>Sudah difakturkan</th><th>Jumlah di faktur</th><th>Harga PO</th><th>Harga di faktur</th><th>PPN %</th></tr>
        <?php if (!$plines): ?><tr><td colspan="7">PO tidak punya baris.</td></tr><?php endif; ?>
        <?php foreach ($plines as $l):
            $room = max(0, round((float)$l['qty_received'] - (float)$l['invoiced'], 4));
            $k = array_search((int)$l['id'], $postIds, true);
            $qVal = ($k !== false) ? (string)($postQty[$k] ?? '') : ($room > 0 ? fmtQty($room) : '');
            $vVal = ($k !== false) ? (string)($postVat[$k] ?? '') : '';
            $pVal = ($k !== false) ? (string)($postPrice[$k] ?? '') : rtrim(rtrim(number_format((float)$l['unit_price_idr'], 4, '.', ''), '0'), '.'); ?>
        <tr class="plr" data-po="<?= e((string)(float)$l['unit_price_idr']) ?>">
          <td><?= e($l['sku']) ?><br><small><?= e($l['item_name']) ?></small><input type="hidden" name="pl_id[]" value="<?= (int)$l['id'] ?>"></td>
          <td><?= e(fmtQty($l['qty_received'])) ?> <?= e($l['unit_code']) ?><br><small>dipesan <?= e(fmtQty($l['qty_ordered'])) ?></small></td>
          <td><?= e(fmtQty($l['invoiced'])) ?></td>
          <td><input type="number" class="plq" name="pl_qty[]" step="0.0001" min="0" max="<?= e(fmtQty($room)) ?>" value="<?= e($qVal) ?>" <?= $room <= 0 ? 'disabled' : '' ?> style="max-width:130px"><?php if ($room <= 0): ?><input type="hidden" name="pl_qty[]" value=""><?php endif; ?></td>
          <td><?= e(payRp($l['unit_price_idr'])) ?></td>
          <td><input type="number" class="plp" name="pl_price[]" step="0.0001" min="0" value="<?= e($pVal) ?>" style="max-width:150px"></td>
          <td><input type="number" class="plv" name="pl_vat[]" step="0.01" min="0" max="100" value="<?= e($vVal) ?>" placeholder="0" style="max-width:90px"></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <?php endif; ?>

    <p style="margin:12px 0 0"><label style="display:inline"><input type="checkbox" name="save_terms" value="1" <?= !empty($_POST['save_terms']) ? 'checked' : '' ?>> Jadikan termin ini bawaan untuk <?= e($supName) ?></label></p>
    <p style="margin:12px 0 0" id="invTotals"><small>Total tagihan:</small> <b id="invTotal">-</b> <small id="invVatWrap" style="display:none"> (termasuk PPN <b id="invVatTot"></b>)</small> <small id="invDiffWrap" style="display:none"> | selisih harga vs PO: <b id="invDiff"></b></small></p>
    <p style="margin:12px 0 0"><button type="submit">Simpan Faktur</button> <a class="btn sec" href="manage_payable.php">Batal</a></p>
  </form>
</div>
<script>
(function () {
  var d = document.getElementById('invDate'), t = document.getElementById('invTerms'), du = document.getElementById('invDue');
  var ex = document.getElementById('invExtra'), tot = document.getElementById('invTotal'), df = document.getElementById('invDiff'), dfw = document.getElementById('invDiffWrap');
  var gi = document.querySelector('[name=goods_idr]');
  var auto = !du.value;
  function pad(n) { return n < 10 ? '0' + n : '' + n; }
  function fmt(n) { return 'Rp ' + n.toLocaleString('id-ID', { maximumFractionDigits: 2 }); }
  function calcDue() {
    if (!auto || !d.value) return;
    var x = new Date(d.value + 'T00:00:00');
    x.setDate(x.getDate() + (parseInt(t.value, 10) || 0));
    du.value = x.getFullYear() + '-' + pad(x.getMonth() + 1) + '-' + pad(x.getDate());
  }
  function calcTotal() {
    var g = 0, p = 0, v = 0, rows = document.querySelectorAll('tr.plr');
    if (rows.length) {
      for (var i = 0; i < rows.length; i++) {
        var q = parseFloat((rows[i].querySelector('.plq') || {}).value) || 0;
        var pr = parseFloat((rows[i].querySelector('.plp') || {}).value) || 0;
        var vp = parseFloat((rows[i].querySelector('.plv') || {}).value) || 0;
        g += q * pr; p += q * (parseFloat(rows[i].getAttribute('data-po')) || 0);
        v += Math.round(q * pr * vp) / 100;
      }
      dfw.style.display = ''; df.textContent = fmt(g - p);
    } else if (gi) {
      g = parseFloat(gi.value) || 0;
      var iv = document.getElementById('invVat');
      v = Math.round(g * (parseFloat(iv && iv.value) || 0)) / 100;
    }
    var vw = document.getElementById('invVatWrap');
    if (vw) { vw.style.display = v > 0 ? '' : 'none'; document.getElementById('invVatTot').textContent = fmt(v); }
    tot.textContent = fmt(g + v + (parseFloat(ex.value) || 0));
  }
  var vab = document.getElementById('vatAllBtn');
  if (vab) vab.addEventListener('click', function () {
    var val = document.getElementById('vatAll').value, all = document.querySelectorAll('.plv');
    for (var i = 0; i < all.length; i++) all[i].value = val;
    calcTotal();
  });
  d.addEventListener('change', calcDue); t.addEventListener('input', calcDue);
  du.addEventListener('input', function () { auto = false; });
  document.addEventListener('input', calcTotal);
  calcDue(); calcTotal();
})();
</script>
<?php endif; // supplier dipilih ?>
<?php endif; // boleh membuat ?>

<?php
// =====================================================================
// TAMPILAN: DAFTAR (DEFAULT)
// =====================================================================
else:
    $filter = (string)($_GET['st'] ?? 'open');
    if (!in_array($filter, ['open', 'overdue', 'soon', 'paid', 'all'], true)) $filter = 'open';
    $crAll = isset($_GET['cr']) && $_GET['cr'] === 'all';

    $sum = mysqli_fetch_assoc(mysqli_query($connect, "SELECT
        COALESCE(SUM(CASE WHEN status IN ('UNPAID','PARTIAL') THEN amount_idr - paid_idr - credit_applied_idr END), 0) AS outstanding,
        COALESCE(SUM(CASE WHEN status IN ('UNPAID','PARTIAL') AND due_date < CURDATE() THEN amount_idr - paid_idr - credit_applied_idr END), 0) AS overdue_amt,
        COALESCE(SUM(CASE WHEN status IN ('UNPAID','PARTIAL') AND due_date < CURDATE() THEN 1 END), 0) AS overdue_n,
        COALESCE(SUM(CASE WHEN status IN ('UNPAID','PARTIAL') AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN amount_idr - paid_idr - credit_applied_idr END), 0) AS soon_amt,
        COALESCE(SUM(CASE WHEN status IN ('UNPAID','PARTIAL') AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 END), 0) AS soon_n
        FROM supplier_invoices"));
    $credOpen = mysqli_fetch_row(mysqli_query($connect, "SELECT COALESCE(SUM(c.amount_idr - COALESCE(a.applied, 0)), 0), COUNT(*)
        FROM supplier_credits c LEFT JOIN (SELECT credit_id, SUM(amount_idr) AS applied FROM supplier_credit_apps WHERE is_void = 0 GROUP BY credit_id) a ON a.credit_id = c.id
        WHERE c.status = 'OPEN'"));

    $where = "1=1";
    if ($filter === 'open')    $where = "si.status IN ('UNPAID','PARTIAL')";
    if ($filter === 'overdue') $where = "si.status IN ('UNPAID','PARTIAL') AND si.due_date < CURDATE()";
    if ($filter === 'soon')    $where = "si.status IN ('UNPAID','PARTIAL') AND si.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
    if ($filter === 'paid')    $where = "si.status = 'PAID'";
    $invoices = mysqli_fetch_all(mysqli_query($connect, "SELECT si.*, s.company_name, p.po_number,
            (si.amount_idr - si.paid_idr - si.credit_applied_idr) AS outstanding
        FROM supplier_invoices si JOIN suppliers s ON s.id = si.supplier_id LEFT JOIN purchase_orders p ON p.id = si.po_id
        WHERE $where ORDER BY (si.status IN ('PAID','CANCELLED')), si.due_date ASC, si.id DESC LIMIT 200"), MYSQLI_ASSOC);

    $credits = mysqli_fetch_all(mysqli_query($connect, "SELECT c.*, s.company_name, i.sku, i.item_name, b.batch_number, COALESCE(a.applied, 0) AS applied
        FROM supplier_credits c JOIN suppliers s ON s.id = c.supplier_id
        LEFT JOIN items i ON i.id = c.item_id LEFT JOIN item_batches b ON b.id = c.batch_id
        LEFT JOIN (SELECT credit_id, SUM(amount_idr) AS applied FROM supplier_credit_apps WHERE is_void = 0 GROUP BY credit_id) a ON a.credit_id = c.id
        " . ($crAll ? '' : "WHERE c.status = 'OPEN'") . " ORDER BY (c.status <> 'OPEN'), c.id DESC LIMIT 100"), MYSQLI_ASSOC);

    $noCredit = mysqli_fetch_all(mysqli_query($connect, "SELECT t.trx_code, t.reference_doc_no, t.created_at, i.sku, i.item_name, s.company_name,
            SUM(-l.signed_qty) AS qty, MAX(l.unit_cost_idr) AS unit_cost
        FROM inventory_transactions t JOIN inventory_ledger_entries l ON l.transaction_id = t.id
        JOIN items i ON i.id = l.item_id JOIN item_batches b ON b.id = l.batch_id JOIN suppliers s ON s.id = b.supplier_id
        WHERE t.trx_type = 'SCRAP_WRITE_OFF' AND t.reference_doc_no LIKE 'RTR-%'
          AND NOT EXISTS (SELECT 1 FROM supplier_credits c WHERE c.return_ref = t.reference_doc_no AND c.status <> 'CANCELLED')
        GROUP BY t.id, t.trx_code, t.reference_doc_no, t.created_at, i.sku, i.item_name, s.company_name
        ORDER BY t.id DESC LIMIT 30"), MYSQLI_ASSOC);
    $filters = ['open' => 'Belum lunas', 'overdue' => 'Terlambat', 'soon' => 'Jatuh tempo 7 hari', 'paid' => 'Lunas', 'all' => 'Semua'];
?>
<div class="cards">
  <div class="card"><b>Rp <?= e(payRp($sum['outstanding'])) ?></b>Total hutang berjalan</div>
  <a href="?st=overdue"><div class="card <?= (int)$sum['overdue_n'] > 0 ? 'warn' : '' ?>"><b>Rp <?= e(payRp($sum['overdue_amt'])) ?></b>Terlambat (<?= (int)$sum['overdue_n'] ?> faktur) &rsaquo;</div></a>
  <a href="?st=soon"><div class="card"><b>Rp <?= e(payRp($sum['soon_amt'])) ?></b>Jatuh tempo 7 hari (<?= (int)$sum['soon_n'] ?> faktur) &rsaquo;</div></a>
  <a href="#kredit"><div class="card"><b>Rp <?= e(payRp($credOpen[0] ?? 0)) ?></b>Kredit / klaim retur terbuka (<?= (int)($credOpen[1] ?? 0) ?>) &rsaquo;</div></a>
</div>

<div class="panel scroll">
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
    <strong style="margin-right:8px">Faktur supplier</strong>
    <?php foreach ($filters as $k => $lab): ?><a class="btn <?= $k === $filter ? '' : 'sec' ?> sm" href="?st=<?= e($k) ?>"><?= e($lab) ?></a><?php endforeach; ?>
    <?php if ($canCreate): ?><a class="btn" style="margin-left:auto" href="?new=1">+ Catat Faktur</a><?php endif; ?>
  </div>
  <table>
    <tr><th>Faktur</th><th>Supplier</th><th>Tgl faktur</th><th>Jatuh tempo</th><th>Total (Rp)</th><th>Sisa (Rp)</th><th>Selisih vs PO</th><th>Status</th></tr>
    <?php if (!$invoices): ?><tr><td colspan="8">Tidak ada faktur pada filter ini.</td></tr><?php endif; ?>
    <?php foreach ($invoices as $r):
        $diffR = round((float)$r['goods_idr'] - (float)$r['po_value_idr'], 2); ?>
      <tr><td><a href="?inv=<?= (int)$r['id'] ?>"><b><?= e($r['inv_code']) ?></b></a><br><small><?= e($r['supplier_invoice_no']) ?><?= $r['po_number'] ? ' | ' . e($r['po_number']) : '' ?></small></td>
        <td><?= e($r['company_name']) ?></td><td><?= e($r['invoice_date']) ?></td>
        <td><?= e($r['due_date']) ?><?= payDueText($r, $today) ?></td>
        <td><?= e(payRp($r['amount_idr'])) ?></td>
        <td><b><?= e(payRp($r['status'] === 'CANCELLED' ? 0 : $r['outstanding'])) ?></b></td>
        <td><?php if (!empty($r['po_id']) && abs($diffR) >= 0.005): ?><span style="color:<?= $diffR > 0 ? '#B3261E' : '#1E6B34' ?>"><?= $diffR > 0 ? '+' : '' ?><?= e(payRp($diffR)) ?></span><?php else: ?>-<?php endif; ?></td>
        <td><?= payStatusTag($r) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="panel scroll" id="kredit">
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px">
    <strong style="margin-right:8px">Kredit &amp; klaim retur supplier</strong>
    <a class="btn <?= $crAll ? 'sec' : '' ?> sm" href="?#kredit">Terbuka</a><a class="btn <?= $crAll ? '' : 'sec' ?> sm" href="?cr=all#kredit">Semua</a>
  </div>
  <small>Retur Supplier otomatis membuat kredit senilai jumlah x harga lot. Sesuaikan nilainya dengan nota kredit dari supplier, lalu pilih penyelesaiannya.</small>
  <table>
    <tr><th>Kredit</th><th>Supplier</th><th>Asal</th><th>Nilai (Rp)</th><th>Terpakai / Sisa</th><th>Penyelesaian</th><th>Status</th><th>Aksi</th></tr>
    <?php if (!$credits): ?><tr><td colspan="8">Tidak ada kredit<?= $crAll ? '' : ' terbuka' ?>.</td></tr><?php endif; ?>
    <?php foreach ($credits as $c):
        $rem = round((float)$c['amount_idr'] - (float)$c['applied'], 2);
        $delta = round((float)$c['amount_idr'] - (float)$c['calc_idr'], 2);
        $stTxt = ['OPEN' => 'Terbuka', 'SETTLED' => 'Selesai', 'CANCELLED' => 'Dibatalkan'][$c['status']] ?? $c['status']; ?>
      <tr style="<?= $c['status'] === 'CANCELLED' ? 'opacity:.55' : '' ?>">
        <td><b><?= e($c['credit_code']) ?></b><br><small><?= e(substr((string)$c['created_at'], 0, 10)) ?></small>
          <?php if (!empty($c['supplier_doc_no'])): ?><br><small>Nota: <?= e($c['supplier_doc_no']) ?></small><?php endif; ?></td>
        <td><?= e($c['company_name']) ?></td>
        <td><?php if ($c['return_ref']): ?><?= e($c['return_ref']) ?><br><small><?= e(($c['sku'] ?? '') . ' / ' . ($c['batch_number'] ?? '') . ' x' . fmtQty($c['qty'])) ?></small>
          <?php else: ?><small><?= e($c['notes'] ?? 'Klaim manual') ?></small><?php endif; ?></td>
        <td><?= e(payRp($c['amount_idr'])) ?><?php if (abs($delta) >= 0.005): ?><br><small>hitungan sistem <?= e(payRp($c['calc_idr'])) ?> (<?= $delta > 0 ? '+' : '' ?><?= e(payRp($delta)) ?>)</small><?php endif; ?></td>
        <td><?= $c['resolution'] === 'CREDIT' ? e(payRp($c['applied'])) . ' / <b>' . e(payRp($rem)) . '</b>' : '-' ?></td>
        <td><?= e($resLabel[$c['resolution']] ?? $c['resolution']) ?></td>
        <td><span class="tag <?= $c['status'] === 'SETTLED' ? '' : 'off' ?>"><?= e($stTxt) ?></span></td>
        <td style="min-width:200px">
          <?php if ($c['status'] !== 'CANCELLED' && $canCreate): ?>
          <details><summary style="cursor:pointer">Ubah</summary>
            <form method="post" action="manage_payable.php#kredit" autocomplete="off" style="margin-top:6px">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="credit_edit"><input type="hidden" name="credit_id" value="<?= (int)$c['id'] ?>">
              <select name="resolution" style="width:100%;margin-bottom:4px"><?php foreach ($resLabel as $k => $lab): ?><option value="<?= e($k) ?>" <?= $k === $c['resolution'] ? 'selected' : '' ?>><?= e($lab) ?></option><?php endforeach; ?></select>
              <input type="number" name="amount_idr" step="0.01" min="0" value="<?= e(number_format((float)$c['amount_idr'], 2, '.', '')) ?>" style="width:100%;margin-bottom:4px" title="Nilai menurut nota kredit supplier">
              <input type="text" name="supplier_doc_no" maxlength="60" value="<?= e($c['supplier_doc_no'] ?? '') ?>" placeholder="No. nota kredit supplier" style="width:100%;margin-bottom:4px">
              <input type="text" name="notes" maxlength="300" value="<?= e($c['notes'] ?? '') ?>" placeholder="Catatan" style="width:100%;margin-bottom:4px">
              <button type="submit" class="sec">Simpan</button>
            </form></details>
          <?php endif; ?>
          <?php if ($c['status'] === 'OPEN' && $c['resolution'] !== 'CREDIT' && $canCreate): ?>
            <form method="post" action="manage_payable.php#kredit" class="inline" style="display:block;margin-top:6px">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="credit_settle"><input type="hidden" name="credit_id" value="<?= (int)$c['id'] ?>">
              <button type="submit" onclick="return confirm('Tandai selesai? (barang pengganti / uang sudah diterima)')">Tandai selesai</button>
            </form>
          <?php endif; ?>
          <?php if ($c['status'] === 'SETTLED' && $c['resolution'] !== 'CREDIT' && $canApprove): ?>
            <form method="post" action="manage_payable.php#kredit" class="inline" style="display:block;margin-top:6px">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="credit_reopen"><input type="hidden" name="credit_id" value="<?= (int)$c['id'] ?>">
              <button type="submit" class="sec">Buka lagi</button>
            </form>
          <?php endif; ?>
          <?php if ($c['status'] !== 'CANCELLED' && $canApprove && (float)$c['applied'] < 0.005): ?>
            <form method="post" action="manage_payable.php#kredit" style="margin-top:6px">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="credit_cancel"><input type="hidden" name="credit_id" value="<?= (int)$c['id'] ?>">
              <input type="text" name="reason" maxlength="150" placeholder="Alasan batal *" required style="width:110px;padding:6px">
              <button type="submit" class="danger" onclick="return confirm('Batalkan kredit ini?')">Batalkan</button>
            </form>
          <?php endif; ?>
        </td></tr>
    <?php endforeach; ?>
  </table>
</div>

<?php if ($noCredit && $canCreate): ?>
<div class="panel scroll">
  <strong>Retur yang belum punya kredit</strong> <small>(retur yang dicatat sebelum fitur ini)</small>
  <table>
    <tr><th>Retur</th><th>Supplier</th><th>Barang</th><th>Jumlah</th><th>Nilai hitungan (Rp)</th><th></th></tr>
    <?php foreach ($noCredit as $r): ?>
      <tr><td><?= e($r['reference_doc_no']) ?><br><small><?= e(substr((string)$r['created_at'], 0, 16)) ?></small></td>
        <td><?= e($r['company_name']) ?></td><td><?= e($r['sku']) ?><br><small><?= e($r['item_name']) ?></small></td>
        <td><?= e(fmtQty($r['qty'])) ?></td><td><?= e(payRp((float)$r['qty'] * (float)$r['unit_cost'])) ?></td>
        <td><form method="post" action="manage_payable.php#kredit" class="inline">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="credit_from_return"><input type="hidden" name="trx_code" value="<?= e($r['trx_code']) ?>">
          <button type="submit" class="sec">Buat kredit</button></form></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>

<?php if ($canCreate): ?>
<div class="panel">
  <details><summary style="cursor:pointer"><strong>Catat klaim / kredit manual</strong> <small>(selisih harga, penggantian, atau potongan lain dari supplier)</small></summary>
    <form method="post" action="manage_payable.php#kredit" autocomplete="off" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="credit_new">
      <div class="grid">
        <div><label>Supplier *</label><select name="supplier_id" required <?= $inp ?>><option value="">- pilih -</option><?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>"><?= e($s['company_name']) ?></option><?php endforeach; ?></select></div>
        <div><label>Nilai (Rp) *</label><input type="number" name="amount_idr" step="0.01" min="0.01" required <?= $inp ?>></div>
        <div><label>Penyelesaian *</label><select name="resolution" <?= $inp ?>><?php foreach ($resLabel as $k => $lab): ?><option value="<?= e($k) ?>"><?= e($lab) ?></option><?php endforeach; ?></select></div>
        <div><label>No. dokumen supplier</label><input type="text" name="supplier_doc_no" maxlength="60" <?= $inp ?>></div>
        <div><label>Keterangan *</label><input type="text" name="notes" maxlength="300" required <?= $inp ?>></div>
      </div>
      <p style="margin:12px 0 0"><button type="submit">Simpan Klaim</button></p>
    </form>
  </details>
</div>
<?php endif; ?>
<?php endif; // tampilan ?>
<?php renderFooter();
