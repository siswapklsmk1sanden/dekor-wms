<?php
/**
 * Helper Purchase Order (PO) supplier: pencarian baris PO terbuka, pencatatan penerimaan
 * sebagian, pembalikan saat penerimaan dibatalkan, dan penghitungan ulang status PO.
 * Dipakai oleh manage_po.php, manage_inbound.php, dan manage_reversal.php.
 * Semua fungsi pencatat/pembalik harus dipanggil di dalam transaksi database (stockBegin).
 */
require_once __DIR__ . '/stock.php';

/** Baris PO + header (tanpa kunci); null bila tidak ada. */
function poFindLine($lineId, $forUpdate = false)
{
    global $connect;
    $sql = "SELECT l.*, p.po_number, p.status, p.supplier_id
            FROM purchase_order_lines l JOIN purchase_orders p ON p.id = l.po_id
            WHERE l.id = ?" . ($forUpdate ? ' FOR UPDATE' : '');
    $st = mysqli_prepare($connect, $sql);
    mysqli_stmt_bind_param($st, 'i', $lineId);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    return $row ?: null;
}

/** Daftar baris PO yang masih bisa diterima (untuk dropdown Barang Masuk). */
function poOpenLines()
{
    global $connect;
    $base = "FROM purchase_order_lines l
        JOIN purchase_orders p ON p.id = l.po_id AND p.status IN ('OPEN','PARTIAL')
        JOIN items i ON i.id = l.item_id JOIN units u ON u.id = i.unit_id
        WHERE l.qty_received < l.qty_ordered
        ORDER BY p.order_date, p.po_number, i.sku";
    $cols = "l.id, l.po_id, l.item_id, l.qty_ordered, l.qty_received, p.po_number,
            p.supplier_id, i.sku, i.item_name, u.unit_code";
    // spk_number ada setelah database\migration_buyer_spk.sql dijalankan; tanpa itu tetap berfungsi.
    $rs = mysqli_query($connect, "SELECT $cols, p.spk_number $base");
    if (!$rs) $rs = mysqli_query($connect, "SELECT $cols $base");
    return $rs ? mysqli_fetch_all($rs, MYSQLI_ASSOC) : [];
}

/** Hitung ulang status PO dari baris-barisnya. PO CANCELLED tidak diubah. */
function poRefreshStatus($poId)
{
    global $connect;
    $st = mysqli_prepare($connect, "SELECT status FROM purchase_orders WHERE id = ? FOR UPDATE");
    mysqli_stmt_bind_param($st, 'i', $poId);
    mysqli_stmt_execute($st);
    $cur = mysqli_fetch_row(mysqli_stmt_get_result($st));
    if (!$cur || $cur[0] === 'CANCELLED') return;

    $st = mysqli_prepare($connect, "SELECT COUNT(*), SUM(qty_received >= qty_ordered), SUM(qty_received > 0)
        FROM purchase_order_lines WHERE po_id = ?");
    mysqli_stmt_bind_param($st, 'i', $poId);
    mysqli_stmt_execute($st);
    [$total, $done, $any] = array_map('intval', mysqli_fetch_row(mysqli_stmt_get_result($st)));
    $new = ($total > 0 && $done === $total) ? 'CLOSED' : ($any > 0 ? 'PARTIAL' : 'OPEN');
    if ($new !== $cur[0]) {
        $u = mysqli_prepare($connect, "UPDATE purchase_orders SET status = ? WHERE id = ?");
        mysqli_stmt_bind_param($u, 'si', $new, $poId);
        mysqli_stmt_execute($u);
    }
}

/**
 * Validasi + kunci baris PO untuk sebuah penerimaan; lempar StockException bila tidak boleh.
 * Mengembalikan baris PO terkunci.
 */
function poLockForReceipt($lineId, $itemId, $qty)
{
    $line = poFindLine($lineId, true);
    if (!$line) throw new StockException('Baris PO tidak ditemukan.');
    if (!in_array($line['status'], ['OPEN', 'PARTIAL'], true)) {
        throw new StockException("PO {$line['po_number']} berstatus {$line['status']}; tidak bisa menerima barang lagi.");
    }
    if ((int)$line['item_id'] !== (int)$itemId) {
        throw new StockException('Barang yang diterima tidak sesuai dengan baris PO.');
    }
    $remaining = (float)$line['qty_ordered'] - (float)$line['qty_received'];
    if ((float)$qty > $remaining + 0.00005) {
        throw new StockException("Jumlah melebihi sisa pesanan PO {$line['po_number']} (sisa " . fmtQty($remaining) . ').');
    }
    return $line;
}

/** Catat penerimaan terhadap baris PO (setelah stockMove berhasil) dan perbarui status PO. */
function poRecordReceipt(array $line, $trxId, $qty)
{
    global $connect;
    $lid = (int)$line['id'];
    $q = number_format((float)$qty, 4, '.', '');
    $st = mysqli_prepare($connect, "INSERT INTO po_receipts (po_line_id, transaction_id, qty) VALUES (?, ?, ?)");
    mysqli_stmt_bind_param($st, 'iis', $lid, $trxId, $q);
    if (!mysqli_stmt_execute($st)) throw new StockException('Gagal mencatat penerimaan PO: ' . mysqli_error($connect));
    $u = mysqli_prepare($connect, "UPDATE purchase_order_lines SET qty_received = qty_received + ? WHERE id = ?");
    mysqli_stmt_bind_param($u, 'si', $q, $lid);
    mysqli_stmt_execute($u);
    poRefreshStatus((int)$line['po_id']);
}

/**
 * Pembatalan penerimaan: kurangi qty diterima pada baris PO terkait dan hitung ulang status.
 * Mengembalikan keterangan teks (kosong bila transaksi tidak terkait PO).
 */
function poUndoReceipt($trxId)
{
    global $connect;
    $st = mysqli_prepare($connect, "SELECT r.id, r.po_line_id, r.qty, l.po_id, p.po_number
        FROM po_receipts r JOIN purchase_order_lines l ON l.id = r.po_line_id
        JOIN purchase_orders p ON p.id = l.po_id
        WHERE r.transaction_id = ? AND r.is_reversed = 0 FOR UPDATE");
    mysqli_stmt_bind_param($st, 'i', $trxId);
    mysqli_stmt_execute($st);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
    $note = '';
    foreach ($rows as $r) {
        $u = mysqli_prepare($connect, "UPDATE purchase_order_lines SET qty_received = GREATEST(0, qty_received - ?) WHERE id = ?");
        $q = (string)$r['qty']; $lid = (int)$r['po_line_id'];
        mysqli_stmt_bind_param($u, 'si', $q, $lid);
        mysqli_stmt_execute($u);
        $m = mysqli_prepare($connect, "UPDATE po_receipts SET is_reversed = 1 WHERE id = ?");
        $rid = (int)$r['id'];
        mysqli_stmt_bind_param($m, 'i', $rid);
        mysqli_stmt_execute($m);
        poRefreshStatus((int)$r['po_id']);
        $note = " Qty diterima pada PO {$r['po_number']} dikembalikan.";
    }
    return $note;
}
