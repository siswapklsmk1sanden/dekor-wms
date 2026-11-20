<?php
/**
 * Helper posting stok: dipakai manage_inbound / outbound / bin_transfer.
 * Semua mutasi berjalan di dalam satu transaksi database dengan
 * pessimistic lock (SELECT ... FOR UPDATE) pada baris stock_balances,
 * lalu dicatat append-only ke inventory_ledger_entries.
 */
require_once __DIR__ . '/config.php';

class StockException extends Exception {}

function fmtQty($v)
{
    $s = rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.');
    return $s === '' ? '0' : $s;
}

function stockBegin()
{
    global $connect;
    mysqli_begin_transaction($connect);
}

function stockCommit()
{
    global $connect;
    // Bila ini eksekusi draft: tandai draft APPROVED dalam transaksi database yang sama.
    // Jika draft sudah diproses orang lain, seluruh posting dibatalkan.
    if (!empty($GLOBALS['WMS_DRAFT_RUN'])) {
        $id    = (int)$GLOBALS['WMS_DRAFT_RUN'];
        $by    = (int)$_SESSION['user_id'];
        $codes = implode(', ', $GLOBALS['WMS_TRX_CODES'] ?? []);
        $st = mysqli_prepare($connect, "UPDATE trx_drafts SET status = 'APPROVED', decided_by = ?,
            decided_at = NOW(), result_trx_code = ? WHERE id = ? AND status = 'PENDING'");
        mysqli_stmt_bind_param($st, 'isi', $by, $codes, $id);
        mysqli_stmt_execute($st);
        if (mysqli_stmt_affected_rows($st) !== 1) {
            throw new StockException('Draft ini sudah diproses oleh pengguna lain.');
        }
    }
    mysqli_commit($connect);
}

function stockRollback()
{
    global $connect;
    mysqli_rollback($connect);
}

/** Buat header transaksi (langsung COMMITTED), kembalikan [id, trx_code]. */
function stockCreateTrx($type, $refDoc, $notes)
{
    global $connect;
    $me  = (int)$_SESSION['user_id'];
    // Eksekusi draft: pembuat = pengaju draft, penyetuju = pengguna yang sedang login.
    $uid = (int)($GLOBALS['WMS_TRX_CREATOR'] ?? $me);
    $approver = (!empty($GLOBALS['WMS_APPROVER_OK']) || canAccess(trxModuleOf($type), 'approve')) ? $me : null;
    $prefix = [
        'INBOUND_PURCHASE' => 'IN', 'OUTBOUND_PRODUCTION' => 'OUT',
        'OUTBOUND_EXPORT_SALE' => 'EXP', 'INTERNAL_BIN_TRANSFER' => 'TRF',
        'OPNAME_ADJUSTMENT' => 'ADJ', 'SCRAP_WRITE_OFF' => 'SCR',
        'PRODUCTION_OUTPUT' => 'PRD',
    ][$type] ?? 'TRX';

    for ($try = 0; $try < 5; $try++) {
        $code = $prefix . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $st = mysqli_prepare($connect, "INSERT INTO inventory_transactions
            (trx_code, trx_type, trx_status, reference_doc_no, created_by, approved_by, notes)
            VALUES (?, ?, 'COMMITTED', ?, ?, ?, ?)");
        $ref = $refDoc !== '' ? $refDoc : null;
        mysqli_stmt_bind_param($st, 'sssiis', $code, $type, $ref, $uid, $approver, $notes);
        if (mysqli_stmt_execute($st)) {
            $GLOBALS['WMS_TRX_CODES'][] = $code;
            return [mysqli_insert_id($connect), $code];
        }
        if (mysqli_errno($connect) !== 1062) {
            throw new StockException('Gagal membuat transaksi: ' . mysqli_error($connect));
        }
    }
    throw new StockException('Gagal membuat nomor transaksi unik.');
}

function trxModuleOf($type)
{
    return $type === 'INBOUND_PURCHASE' ? 'inbound' : 'outbound';
}

/** Total CBM yang saat ini terisi di sebuah rak. */
function binUsedCbm($binId)
{
    global $connect;
    $st = mysqli_prepare($connect, "SELECT COALESCE(SUM(sb.qty_on_hand * i.unit_cbm), 0)
        FROM stock_balances sb JOIN items i ON i.id = sb.item_id WHERE sb.bin_id = ?");
    mysqli_stmt_bind_param($st, 'i', $binId);
    mysqli_stmt_execute($st);
    return (float)mysqli_fetch_row(mysqli_stmt_get_result($st))[0];
}

/** Ambil rak (dengan kunci) dan pastikan aktif, tidak beku, bukan gudang virtual. */
function stockGetBin($binId, $forWrite = true, $allowFrozen = false)
{
    global $connect;
    $st = mysqli_prepare($connect, "SELECT b.*, w.warehouse_type, w.is_active AS wh_active
        FROM storage_bins b JOIN warehouses w ON w.id = b.warehouse_id
        WHERE b.id = ? " . ($forWrite ? 'FOR UPDATE' : ''));
    mysqli_stmt_bind_param($st, 'i', $binId);
    mysqli_stmt_execute($st);
    $bin = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if (!$bin) throw new StockException('Rak tidak ditemukan.');
    if (!(int)$bin['is_active'] || !(int)$bin['wh_active']) {
        throw new StockException("Rak {$bin['bin_code']} tidak aktif.");
    }
    if (!$allowFrozen && (int)$bin['is_frozen_for_opname']) {
        throw new StockException("Rak {$bin['bin_code']} sedang dibekukan untuk stock opname.");
    }
    if ($bin['warehouse_type'] === 'VIRTUAL_COUNTERPART') {
        throw new StockException('Gudang virtual tidak boleh dipakai untuk stok fisik.');
    }
    return $bin;
}

/**
 * Tambah/kurangi saldo satu rak+lot dan catat ke buku besar.
 * $delta > 0 = DEBIT_IN, $delta < 0 = CREDIT_OUT.
 */
function stockMove($trxId, $itemId, array $bin, $batchId, $delta, $unitCost)
{
    global $connect;
    $binId = (int)$bin['id'];
    $whId  = (int)$bin['warehouse_id'];
    $delta = round((float)$delta, 4);
    if ($delta == 0.0) throw new StockException('Kuantitas tidak boleh nol.');

    // Pastikan baris saldo ada, lalu kunci.
    $z = mysqli_prepare($connect, "INSERT IGNORE INTO stock_balances
        (item_id, warehouse_id, bin_id, batch_id, qty_on_hand, qty_reserved)
        VALUES (?, ?, ?, ?, 0, 0)");
    mysqli_stmt_bind_param($z, 'iiii', $itemId, $whId, $binId, $batchId);
    mysqli_stmt_execute($z);

    $st = mysqli_prepare($connect, "SELECT id, qty_on_hand, qty_reserved FROM stock_balances
        WHERE item_id = ? AND bin_id = ? AND batch_id = ? FOR UPDATE");
    mysqli_stmt_bind_param($st, 'iii', $itemId, $binId, $batchId);
    mysqli_stmt_execute($st);
    $sb = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if (!$sb) throw new StockException('Saldo stok tidak dapat dikunci.');

    $new = round((float)$sb['qty_on_hand'] + $delta, 4);
    if ($new < 0) {
        throw new StockException("Stok tidak cukup di rak {$bin['bin_code']} (tersedia " . fmtQty($sb['qty_on_hand']) . ').');
    }
    if ($delta < 0 && $new < (float)$sb['qty_reserved']) {
        throw new StockException("Stok di rak {$bin['bin_code']} sebagian sudah dipesan (reserved " . fmtQty($sb['qty_reserved']) . ').');
    }

    $up = mysqli_prepare($connect, "UPDATE stock_balances SET qty_on_hand = ? WHERE id = ?");
    $newS = number_format($new, 4, '.', '');
    $sbId = (int)$sb['id'];
    mysqli_stmt_bind_param($up, 'si', $newS, $sbId);
    mysqli_stmt_execute($up);

    $dir = $delta > 0 ? 'DEBIT_IN' : 'CREDIT_OUT';
    $dS  = number_format($delta, 4, '.', '');
    $cS  = number_format((float)$unitCost, 4, '.', '');
    $lg = mysqli_prepare($connect, "INSERT INTO inventory_ledger_entries
        (transaction_id, item_id, warehouse_id, bin_id, batch_id, entry_direction,
         signed_qty, balance_after_qty, unit_cost_idr)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($lg, 'iiiiissss', $trxId, $itemId, $whId, $binId, $batchId, $dir, $dS, $newS, $cS);
    if (!mysqli_stmt_execute($lg)) {
        throw new StockException('Gagal mencatat buku besar: ' . mysqli_error($connect));
    }
    return $new;
}

/** Pastikan muatan baru tidak melebihi kapasitas CBM rak. */
function stockCheckCapacity(array $bin, $addCbm)
{
    $used = binUsedCbm((int)$bin['id']);
    $max  = (float)$bin['max_capacity_cbm'];
    if ($used + $addCbm > $max + 0.00001) {
        throw new StockException(sprintf(
            'Kapasitas rak %s terlampaui: terisi %.4f CBM + %.4f CBM > batas %.4f CBM.',
            $bin['bin_code'], $used, $addCbm, $max));
    }
}

/** Rata-rata bergerak harga pokok setelah penerimaan. */
function stockUpdateMovingAvg($itemId, $inQty, $inCost)
{
    global $connect;
    // total stok lolos QC SETELAH penerimaan (sudah termasuk $inQty); lot tahan/ditolak tidak dihitung
    $st = mysqli_prepare($connect, "SELECT COALESCE(SUM(sb.qty_on_hand),0) FROM stock_balances sb
        JOIN item_batches b ON b.id = sb.batch_id WHERE sb.item_id = ? AND b.qc_status = 'PASSED'");
    mysqli_stmt_bind_param($st, 'i', $itemId);
    mysqli_stmt_execute($st);
    $total = (float)mysqli_fetch_row(mysqli_stmt_get_result($st))[0];

    $st = mysqli_prepare($connect, "SELECT moving_avg_cost_idr FROM items WHERE id = ? FOR UPDATE");
    mysqli_stmt_bind_param($st, 'i', $itemId);
    mysqli_stmt_execute($st);
    $oldAvg = (float)mysqli_fetch_row(mysqli_stmt_get_result($st))[0];

    $oldQty = max(0.0, $total - $inQty);
    $avg = ($oldQty + $inQty) > 0
        ? (($oldQty * $oldAvg) + ($inQty * $inCost)) / ($oldQty + $inQty)
        : $inCost;
    $avgS = number_format($avg, 4, '.', '');
    $up = mysqli_prepare($connect, "UPDATE items SET moving_avg_cost_idr = ? WHERE id = ?");
    mysqli_stmt_bind_param($up, 'si', $avgS, $itemId);
    mysqli_stmt_execute($up);
}

/** Opsi dropdown rak aktif & tidak beku (bukan gudang virtual). */
function stockBinOptions()
{
    global $connect;
    $out = [];
    $rs = mysqli_query($connect, "SELECT b.id, b.bin_code, b.zone_name, w.warehouse_code
        FROM storage_bins b JOIN warehouses w ON w.id = b.warehouse_id
        WHERE b.is_active = 1 AND w.is_active = 1 AND b.is_frozen_for_opname = 0
          AND w.warehouse_type <> 'VIRTUAL_COUNTERPART'
        ORDER BY b.bin_code");
    while ($rs && ($r = mysqli_fetch_assoc($rs))) $out[] = $r;
    return $out;
}

/**
 * Gerbang persetujuan (Draft -> Approve), dipanggil di awal blok POST modul transaksi
 * menggantikan requirePermission($module, 'create').
 *  - Eksekusi draft (run_draft=ID): hanya pemegang hak Setujui; $_POST diganti isi draft,
 *    lalu modul memproses seperti biasa dan stockCommit() menandai draft APPROVED.
 *  - Pemegang hak Setujui: langsung posting seperti sebelumnya.
 *  - Peran lain (punya hak Buat): isian disimpan sebagai draft PENDING, tanpa mengubah stok.
 */
function draftGate($module, $label)
{
    global $connect;
    $page = basename($_SERVER['SCRIPT_NAME']);

    if ((int)($_POST['run_draft'] ?? 0) > 0) {
        requirePermission($module, 'approve');
        $id = (int)$_POST['run_draft'];
        $st = mysqli_prepare($connect, "SELECT * FROM trx_drafts
            WHERE id = ? AND status = 'PENDING' AND module_name = ? AND target_page = ?");
        mysqli_stmt_bind_param($st, 'iss', $id, $module, $page);
        mysqli_stmt_execute($st);
        $d = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        if (!$d) {
            $_SESSION['flash'] = ['err', 'Draft tidak ditemukan atau sudah diproses.'];
            header('Location: manage_approval.php');
            exit;
        }
        $payload = json_decode($d['payload_json'], true);
        $csrf = $_POST['csrf'];
        $_POST = is_array($payload) ? $payload : [];
        $_POST['csrf'] = $csrf;
        $GLOBALS['WMS_DRAFT_RUN']   = $id;
        $GLOBALS['WMS_TRX_CREATOR'] = (int)$d['created_by'];
        $GLOBALS['WMS_APPROVER_OK'] = true;
        return;
    }

    requirePermission($module, 'create');
    if (canAccess($module, 'approve')) {
        $GLOBALS['WMS_APPROVER_OK'] = true;
        return;
    }

    $payload = $_POST;
    unset($payload['csrf'], $payload['run_draft']);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($json === false || strlen($json) > 20000) {
        $_SESSION['flash'] = ['err', 'Isian draft tidak valid atau terlalu besar.'];
        header('Location: ' . $page);
        exit;
    }
    $uid = (int)$_SESSION['user_id'];
    $st = mysqli_prepare($connect, "INSERT INTO trx_drafts (module_name, target_page, label, payload_json, created_by)
        VALUES (?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($st, 'ssssi', $module, $page, $label, $json, $uid);
    mysqli_stmt_execute($st);
    $newId = mysqli_insert_id($connect);
    logActivity('DRAFT', $label, "Mengajukan draft $label #$newId (menunggu persetujuan)", $newId);
    $_SESSION['flash'] = ['ok', "Draft #$newId diajukan dan menunggu persetujuan Kepala Gudang. Stok belum berubah."];
    header('Location: ' . $page);
    exit;
}

function stockCsrf()
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function stockCheckCsrf()
{
    if (!hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'] ?? '')) {
        http_response_code(400);
        die('Token keamanan tidak valid. Muat ulang halaman.');
    }
}

/** Hitung rencana pengambilan (dengan kunci baris saldo). */
function planAllocation($itemId, $qty, $strategy)
{
    global $connect;
    // Kunci semua saldo barang ini dulu supaya perhitungan konsisten.
    $lk = mysqli_prepare($connect, "SELECT id FROM stock_balances WHERE item_id = ? FOR UPDATE");
    mysqli_stmt_bind_param($lk, 'i', $itemId);
    mysqli_stmt_execute($lk);
    mysqli_stmt_get_result($lk);

    $order = $strategy === 'FEFO'
        ? 'b.expiry_date ASC, b.received_date ASC, b.id ASC, bn.bin_code ASC'
        : 'b.received_date ASC, b.id ASC, bn.bin_code ASC';
    $st = mysqli_prepare($connect, "SELECT sb.bin_id, sb.batch_id, b.batch_number, b.unit_cost_idr,
            bn.bin_code, (sb.qty_on_hand - sb.qty_reserved) AS avail
        FROM stock_balances sb
        JOIN item_batches b ON b.id = sb.batch_id
        JOIN storage_bins bn ON bn.id = sb.bin_id
        JOIN warehouses w ON w.id = sb.warehouse_id
        WHERE sb.item_id = ? AND b.qc_status = 'PASSED'
          AND bn.is_active = 1 AND bn.is_frozen_for_opname = 0
          AND w.is_active = 1 AND w.warehouse_type <> 'VIRTUAL_COUNTERPART'
          AND (sb.qty_on_hand - sb.qty_reserved) > 0
          AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
        ORDER BY $order");
    mysqli_stmt_bind_param($st, 'i', $itemId);
    mysqli_stmt_execute($st);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

    $need = round((float)$qty, 4);
    $plan = [];
    foreach ($rows as $r) {
        if ($need <= 0) break;
        $take = min($need, (float)$r['avail']);
        $plan[] = $r + ['take' => $take];
        $need = round($need - $take, 4);
    }
    if ($need > 0) {
        $have = round((float)$qty - $need, 4);
        throw new StockException('Stok tersedia (QC lolos, belum kedaluwarsa, rak tidak beku) hanya ' . fmtQty($have) . ', kurang ' . fmtQty($need) . '.'
            . stockShortageHint($itemId));
    }
    return $plan;
}

/** Penjelasan penyebab stok tidak bisa dipakai, supaya pesan error bisa ditindaklanjuti. */
function stockShortageHint($itemId)
{
    global $connect;
    $itemId = (int)$itemId;
    $name = '';
    $r = mysqli_query($connect, "SELECT sku, item_name FROM items WHERE id = $itemId");
    if ($r && ($row = mysqli_fetch_assoc($r))) $name = $row['sku'] . ' - ' . $row['item_name'];

    $q = mysqli_query($connect, "SELECT
            COUNT(*) AS n,
            SUM(sb.qty_on_hand) AS onhand,
            SUM(CASE WHEN b.qc_status <> 'PASSED' THEN sb.qty_on_hand ELSE 0 END) AS qc_block,
            SUM(CASE WHEN b.qc_status = 'PASSED' AND b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE() THEN sb.qty_on_hand ELSE 0 END) AS expired,
            SUM(CASE WHEN bn.is_frozen_for_opname = 1 THEN sb.qty_on_hand ELSE 0 END) AS frozen,
            SUM(CASE WHEN bn.is_active = 0 OR w.is_active = 0 THEN sb.qty_on_hand ELSE 0 END) AS inactive,
            SUM(sb.qty_reserved) AS reserved
        FROM stock_balances sb
        JOIN item_batches b ON b.id = sb.batch_id
        JOIN storage_bins bn ON bn.id = sb.bin_id
        JOIN warehouses w ON w.id = sb.warehouse_id
        WHERE sb.item_id = $itemId AND w.warehouse_type <> 'VIRTUAL_COUNTERPART'");
    $d = $q ? mysqli_fetch_assoc($q) : null;

    $hint = $name !== '' ? " Barang: $name." : '';
    if (!$d || (int)$d['n'] === 0 || (float)$d['onhand'] <= 0) {
        $isb = mysqli_query($connect, "SELECT COUNT(*) FROM bom_headers WHERE target_item_id = $itemId AND is_active = 1");
        $hasBom = $isb ? (int)mysqli_fetch_row($isb)[0] : 0;
        if ($hasBom > 0) {
            return $hint . ' Penyebab: barang jadi ini belum pernah diproduksi. Posting hasil di menu Produksi (pastikan bahan BoM sudah ada stoknya), baru dikeluarkan/dipesan.';
        }
        return $hint . ' Penyebab: barang ini belum punya stok di gudang. Lakukan penerimaan (Inbound), QC, lalu simpan ke rak.';
    }
    $why = [];
    if ((float)$d['qc_block'] > 0)  $why[] = fmtQty($d['qc_block']) . ' belum/tidak lolos QC';
    if ((float)$d['expired'] > 0)   $why[] = fmtQty($d['expired']) . ' sudah kedaluwarsa';
    if ((float)$d['frozen'] > 0)    $why[] = fmtQty($d['frozen']) . ' di rak yang dibekukan (stock opname)';
    if ((float)$d['inactive'] > 0)  $why[] = fmtQty($d['inactive']) . ' di rak/gudang nonaktif';
    if ((float)$d['reserved'] > 0)  $why[] = fmtQty($d['reserved']) . ' sudah dipesan (reservasi)';
    if (!$why) return $hint;
    return $hint . ' Penyebab: ' . implode('; ', $why) . '.';
}