<?php
/**
 * Helper Hutang Supplier (v38): faktur supplier, pembayaran, kredit / klaim retur.
 * Aturan nilai:
 *   sisa tagihan faktur = amount_idr - (pembayaran tidak batal) - (kredit retur yang diterapkan, tidak batal)
 *   sisa kredit retur   = amount_idr - (penerapan ke faktur, tidak batal)
 * Semua fungsi tulis dipanggil di dalam transaksi database yang dibuka pemanggil.
 */
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/schema_ext.php';

class PayException extends Exception {}

/** Format rupiah: tanpa desimal bila bulat, 2 desimal bila ada sen. */
function payRp($v)
{
    $f = (float)$v;
    return number_format($f, abs($f - round($f)) < 0.005 ? 0 : 2, ',', '.');
}

/** Angka dari input (titik/koma desimal). Kembalikan null bila bukan angka. */
function payNum($s)
{
    $s = trim((string)$s);
    if ($s === '') return null;
    $s = str_replace(' ', '', $s);
    if (strpos($s, ',') !== false && strpos($s, '.') !== false) {
        // "1.234,56" -> 1234.56
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } elseif (strpos($s, ',') !== false) {
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? round((float)$s, 4) : null;
}

function payValidDate($s)
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$s, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** Termin pembayaran bawaan supplier (hari). */
function payTermsFor($supplierId)
{
    global $connect;
    $st = mysqli_prepare($connect, "SELECT terms_days FROM supplier_terms WHERE supplier_id = ?");
    $sid = (int)$supplierId;
    mysqli_stmt_bind_param($st, 'i', $sid);
    mysqli_stmt_execute($st);
    $r = mysqli_fetch_row(mysqli_stmt_get_result($st));
    return $r ? (int)$r[0] : 30;
}

/** Hitung ulang total bayar, kredit terapan, dan status sebuah faktur (baris dikunci di sini). */
function payRecalcInvoice($invId)
{
    global $connect;
    $id = (int)$invId;
    $st = mysqli_prepare($connect, "SELECT amount_idr, status FROM supplier_invoices WHERE id = ? FOR UPDATE");
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $inv = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if (!$inv) throw new PayException('Faktur tidak ditemukan.');
    if ($inv['status'] === 'CANCELLED') return;

    $q = mysqli_prepare($connect, "SELECT COALESCE(SUM(amount_idr),0) FROM supplier_payments WHERE invoice_id = ? AND is_void = 0");
    mysqli_stmt_bind_param($q, 'i', $id);
    mysqli_stmt_execute($q);
    $paid = round((float)mysqli_fetch_row(mysqli_stmt_get_result($q))[0], 2);

    $q = mysqli_prepare($connect, "SELECT COALESCE(SUM(amount_idr),0) FROM supplier_credit_apps WHERE invoice_id = ? AND is_void = 0");
    mysqli_stmt_bind_param($q, 'i', $id);
    mysqli_stmt_execute($q);
    $cred = round((float)mysqli_fetch_row(mysqli_stmt_get_result($q))[0], 2);

    $out = round((float)$inv['amount_idr'] - $paid - $cred, 2);
    $status = $out <= 0.005 ? 'PAID' : (($paid + $cred) > 0.005 ? 'PARTIAL' : 'UNPAID');
    $paidS = number_format($paid, 2, '.', '');
    $credS = number_format($cred, 2, '.', '');
    $u = mysqli_prepare($connect, "UPDATE supplier_invoices SET paid_idr = ?, credit_applied_idr = ?, status = ? WHERE id = ?");
    mysqli_stmt_bind_param($u, 'sssi', $paidS, $credS, $status, $id);
    mysqli_stmt_execute($u);
}

/** Total kredit yang sudah diterapkan ke faktur (tidak batal). */
function payCreditApplied($creditId)
{
    global $connect;
    $id = (int)$creditId;
    $q = mysqli_prepare($connect, "SELECT COALESCE(SUM(amount_idr),0) FROM supplier_credit_apps WHERE credit_id = ? AND is_void = 0");
    mysqli_stmt_bind_param($q, 'i', $id);
    mysqli_stmt_execute($q);
    return round((float)mysqli_fetch_row(mysqli_stmt_get_result($q))[0], 2);
}

/** Hitung ulang status kredit bertipe CREDIT (lunas dipakai = SETTLED). Baris dikunci di sini. */
function payRecalcCredit($creditId)
{
    global $connect;
    $id = (int)$creditId;
    $st = mysqli_prepare($connect, "SELECT amount_idr, resolution, status FROM supplier_credits WHERE id = ? FOR UPDATE");
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $c = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if (!$c) throw new PayException('Kredit tidak ditemukan.');
    if ($c['status'] === 'CANCELLED' || $c['resolution'] !== 'CREDIT') return;

    $applied = payCreditApplied($id);
    $full = (float)$c['amount_idr'] > 0 && $applied >= (float)$c['amount_idr'] - 0.005;
    if ($full) {
        $u = mysqli_prepare($connect, "UPDATE supplier_credits SET status = 'SETTLED', settled_at = COALESCE(settled_at, NOW()) WHERE id = ?");
    } else {
        $u = mysqli_prepare($connect, "UPDATE supplier_credits SET status = 'OPEN', settled_at = NULL WHERE id = ?");
    }
    mysqli_stmt_bind_param($u, 'i', $id);
    mysqli_stmt_execute($u);
}

/**
 * Catat kredit / klaim dari retur ke supplier. Nilai hitungan = jumlah x harga lot.
 * Dipanggil di dalam transaksi retur (manage_return.php); mengembalikan kode kredit.
 */
function payCreateReturnCredit($supplierId, $trxId, $returnRef, $itemId, $batchId, $qty, $unitCost, $notes, $createdBy = null)
{
    global $connect;
    $calc  = round((float)$qty * (float)$unitCost, 2);
    $calcS = number_format($calc, 2, '.', '');
    $qS    = number_format((float)$qty, 4, '.', '');
    $cS    = number_format((float)$unitCost, 4, '.', '');
    $sid   = (int)$supplierId;
    $tid   = $trxId !== null ? (int)$trxId : null;
    $iid   = $itemId !== null ? (int)$itemId : null;
    $bid   = $batchId !== null ? (int)$batchId : null;
    $me    = (int)($createdBy ?? ($GLOBALS['WMS_TRX_CREATOR'] ?? ($_SESSION['user_id'] ?? 0)));
    $notesV = mb_substr((string)$notes, 0, 300);
    $retV  = $returnRef !== null && $returnRef !== '' ? (string)$returnRef : null;

    for ($try = 0; $try < 5; $try++) {
        $code = 'KRD-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $st = mysqli_prepare($connect, "INSERT INTO supplier_credits
            (credit_code, supplier_id, trx_id, return_ref, item_id, batch_id, qty, unit_cost_idr, calc_idr, amount_idr,
             resolution, status, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'CREDIT', 'OPEN', ?, ?)");
        if (!$st) throw new PayException('Tabel kredit supplier belum siap: ' . mysqli_error($connect));
        mysqli_stmt_bind_param($st, 'siisiisssssi', $code, $sid, $tid, $retV, $iid, $bid, $qS, $cS, $calcS, $calcS, $notesV, $me);
        if (mysqli_stmt_execute($st)) return $code;
        if (mysqli_errno($connect) !== 1062) throw new PayException('Gagal mencatat kredit retur: ' . mysqli_error($connect));
    }
    throw new PayException('Gagal membuat nomor kredit unik.');
}

// =====================================================================
// Lampiran scan faktur (PDF / JPG / PNG, maks. 5 MB) di admin/uploads/invoices
// File TIDAK bisa dibuka langsung lewat URL (folder diblokir); dibuka lewat invoice_file.php yang memeriksa hak akses.
// =====================================================================
const PAY_ATTACH_MAX_BYTES = 5242880;

function payInvoiceDir()
{
    $d = __DIR__ . '/uploads/invoices';
    if (!is_dir($d)) @mkdir($d, 0775, true);
    if (is_dir($d) && !is_file("$d/.htaccess")) @file_put_contents("$d/.htaccess", "Require all denied\n");
    if (is_dir($d) && !is_file("$d/index.html")) @file_put_contents("$d/index.html", '');
    return $d;
}

/** Simpan file unggahan; kembalikan [nama file tersimpan, nama asli]. Lempar PayException bila tidak valid. */
function payStoreAttachment(array $f)
{
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new PayException('Ukuran file melebihi batas server (upload_max_filesize). Maksimal lampiran 5 MB.');
    if ($err !== UPLOAD_ERR_OK) throw new PayException('Unggahan lampiran gagal (kode ' . $err . '). Coba lagi.');
    $tmp = (string)($f['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) throw new PayException('File lampiran tidak valid.');
    $size = (int)($f['size'] ?? 0);
    if ($size <= 0) throw new PayException('File lampiran kosong.');
    if ($size > PAY_ATTACH_MAX_BYTES) throw new PayException('Lampiran terlalu besar (maks. 5 MB).');

    $fh = @fopen($tmp, 'rb');
    $head = $fh ? (string)fread($fh, 8) : '';
    if ($fh) fclose($fh);
    if (strncmp($head, '%PDF', 4) === 0) $ext = 'pdf';
    elseif (strncmp($head, "\xFF\xD8\xFF", 3) === 0) $ext = 'jpg';
    elseif (strncmp($head, "\x89PNG\r\n\x1a\n", 8) === 0) $ext = 'png';
    else throw new PayException('Format lampiran harus PDF, JPG, atau PNG.');

    $dir = payInvoiceDir();
    if (!is_dir($dir) || !is_writable($dir)) throw new PayException('Folder admin\\uploads\\invoices tidak bisa ditulis.');
    $name = 'inv_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($tmp, "$dir/$name")) throw new PayException('Gagal menyimpan file lampiran.');

    $orig = preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u', '', basename(str_replace('\\', '/', (string)($f['name'] ?? 'faktur.' . $ext))));
    $orig = mb_substr(trim((string)$orig), 0, 150);
    if ($orig === '') $orig = 'faktur.' . $ext;
    return [$name, $orig];
}

/** Hapus file lampiran (nama file dicek ketat, tidak bisa keluar folder). */
function payDeleteAttachment($file)
{
    $file = (string)$file;
    if (!preg_match('/^inv_\d{8}_\d{6}_[a-f0-9]{12}\.(?:pdf|jpg|png)$/', $file)) return false;
    $p = payInvoiceDir() . '/' . $file;
    return is_file($p) ? @unlink($p) : false;
}
