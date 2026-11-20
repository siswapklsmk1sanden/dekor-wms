<?php
/**
 * Pembatalan transaksi lewat JURNAL KOREKSI (buku besar tetap append-only).
 * Yang dibatalkan: barang masuk, pengeluaran (produksi/ekspor), transfer rak.
 * Cara kerja: dibuat transaksi baru bereferensi "REV:<kode asal>" yang membalik tiap baris
 * mutasi (rak & lot yang sama), transaksi asal ditandai CANCELLED.
 * Aturan konservatif: pembatalan hanya berhasil bila saldo rak/lot saat ini masih cukup untuk dibalik
 * (mis. barang masuk yang sudah terpakai/dipindah TIDAK bisa dibatalkan). Rak beku ditolak.
 * Butuh hak Setujui pada modul inbound DAN outbound; alasan wajib; tercatat di Audit Log.
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/po.php';
require_once __DIR__ . '/layout.php';
requireLogin();
if (!canAccess('inbound', 'approve') || !canAccess('outbound', 'approve')) {
    http_response_code(403);
    die('Akses ditolak: butuh hak Setujui pada Barang Masuk dan Barang Keluar.');
}
$csrf   = stockCsrf();
$errors = [];
$allowed = ['INBOUND_PURCHASE', 'OUTBOUND_PRODUCTION', 'OUTBOUND_EXPORT_SALE', 'INTERNAL_BIN_TRANSFER'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    stockCheckCsrf();
    $code   = trim($_POST['code'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    try {
        if (mb_strlen($reason) < 5) throw new StockException('Alasan pembatalan wajib diisi (min. 5 karakter).');
        stockBegin();
        $st = mysqli_prepare($connect, "SELECT * FROM inventory_transactions WHERE trx_code = ? FOR UPDATE");
        mysqli_stmt_bind_param($st, 's', $code);
        mysqli_stmt_execute($st);
        $t = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        if (!$t) throw new StockException('Transaksi tidak ditemukan.');
        if ($t['trx_status'] !== 'COMMITTED') throw new StockException('Transaksi ini sudah dibatalkan.');
        if (!in_array($t['trx_type'], $allowed, true)) {
            throw new StockException('Jenis transaksi ini tidak bisa dibatalkan di sini (opname, produksi-hasil, dan scrap dikoreksi lewat proses masing-masing).');
        }
        if ($t['reference_doc_no'] === 'SALDO-AWAL') throw new StockException('Transaksi saldo awal tidak dapat dibatalkan.');
        if (strpos((string)$t['reference_doc_no'], 'REV:') === 0) throw new StockException('Transaksi koreksi tidak dapat dibatalkan lagi.');
        // Bahan produksi yang menjadi pasangan hasil produksi: batalkan lewat koreksi stok, bukan di sini.
        if ($t['trx_type'] === 'OUTBOUND_PRODUCTION') {
            $pr = mysqli_prepare($connect, "SELECT COUNT(*) FROM inventory_transactions
                WHERE trx_type = 'PRODUCTION_OUTPUT' AND reference_doc_no <=> ? AND id = ? + 1");
            $ref = $t['reference_doc_no']; $tid0 = (int)$t['id'];
            mysqli_stmt_bind_param($pr, 'si', $ref, $tid0);
            mysqli_stmt_execute($pr);
            if ((int)mysqli_fetch_row(mysqli_stmt_get_result($pr))[0] > 0) {
                throw new StockException('Pengeluaran ini bagian dari posting produksi (ada hasil produksinya); tidak dapat dibatalkan sepihak.');
            }
        }

        $st = mysqli_prepare($connect, "SELECT * FROM inventory_ledger_entries WHERE transaction_id = ? ORDER BY id DESC");
        $tid = (int)$t['id'];
        mysqli_stmt_bind_param($st, 'i', $tid);
        mysqli_stmt_execute($st);
        $entries = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
        if (!$entries) throw new StockException('Transaksi tidak punya baris mutasi.');

        [$revId, $revCode] = stockCreateTrx($t['trx_type'], 'REV:' . $t['trx_code'], 'Pembatalan ' . $t['trx_code'] . ': ' . $reason);
        // Kunci rak dalam urutan id agar tidak deadlock antar-pengguna
        $binIds = array_unique(array_map(fn($e) => (int)$e['bin_id'], $entries));
        sort($binIds);
        $bins = [];
        foreach ($binIds as $bid) $bins[$bid] = stockGetBin($bid);

        foreach ($entries as $en) {
            stockMove($revId, (int)$en['item_id'], $bins[(int)$en['bin_id']], (int)$en['batch_id'],
                -(float)$en['signed_qty'], (float)$en['unit_cost_idr']);
        }

        $extra = '';
        if ($t['trx_type'] === 'INBOUND_PURCHASE') {
            $en = $entries[0];
            $bq = mysqli_prepare($connect, "SELECT b.qc_status, b.batch_number, b.unit_cost_idr FROM item_batches b WHERE b.id = ? FOR UPDATE");
            $bid = (int)$en['batch_id'];
            mysqli_stmt_bind_param($bq, 'i', $bid);
            mysqli_stmt_execute($bq);
            $b = mysqli_fetch_assoc(mysqli_stmt_get_result($bq));
            // Rata-rata harga: keluarkan kontribusi lot ini bila lot sudah PASSED
            if ($b['qc_status'] === 'PASSED') {
                $qty = (float)$en['signed_qty'];
                $q = mysqli_prepare($connect, "SELECT COALESCE(SUM(sb.qty_on_hand),0) FROM stock_balances sb
                    JOIN item_batches b ON b.id = sb.batch_id WHERE sb.item_id = ? AND b.qc_status = 'PASSED'");
                $iid = (int)$en['item_id'];
                mysqli_stmt_bind_param($q, 'i', $iid);
                mysqli_stmt_execute($q);
                $newTotal = (float)mysqli_fetch_row(mysqli_stmt_get_result($q))[0];
                $a = mysqli_prepare($connect, "SELECT moving_avg_cost_idr FROM items WHERE id = ? FOR UPDATE");
                mysqli_stmt_bind_param($a, 'i', $iid);
                mysqli_stmt_execute($a);
                $avg = (float)mysqli_fetch_row(mysqli_stmt_get_result($a))[0];
                if ($newTotal > 0) {
                    $newAvg = max(0.0, (($newTotal + $qty) * $avg - $qty * (float)$en['unit_cost_idr']) / $newTotal);
                    $nS = number_format($newAvg, 4, '.', '');
                    $u = mysqli_prepare($connect, "UPDATE items SET moving_avg_cost_idr = ? WHERE id = ?");
                    mysqli_stmt_bind_param($u, 'si', $nS, $iid);
                    mysqli_stmt_execute($u);
                }
            }
            // Lot hasil penerimaan yang dibatalkan dikunci agar tidak terpakai; nomor lot tidak bisa dipakai ulang.
            $u = mysqli_prepare($connect, "UPDATE item_batches SET qc_status = 'REJECTED' WHERE id = ?");
            mysqli_stmt_bind_param($u, 'i', $bid);
            mysqli_stmt_execute($u);
            $extra = " Lot {$b['batch_number']} ditutup (REJECTED); gunakan nomor lot baru bila penerimaan diinput ulang.";
            // Penerimaan yang terkait PO: kembalikan qty diterima pada baris PO dan hitung ulang status PO
            $extra .= poUndoReceipt((int)$t['id']);
        }

        $note = trim(($t['notes'] ?? '') . ' [DIBATALKAN oleh ' . ($_SESSION['username'] ?? '') . ' via ' . $revCode . ': ' . $reason . ']');
        $u = mysqli_prepare($connect, "UPDATE inventory_transactions SET trx_status = 'CANCELLED', notes = ? WHERE id = ?");
        mysqli_stmt_bind_param($u, 'si', $note, $tid);
        mysqli_stmt_execute($u);
        stockCommit();

        logActivity('UPDATE', 'Reversal', "Pembatalan {$t['trx_code']} ({$t['trx_type']}) via $revCode. Alasan: $reason", $tid);
        $_SESSION['flash'] = ['ok', "{$t['trx_code']} dibatalkan lewat jurnal koreksi $revCode.$extra"];
        header('Location: manage_reversal.php');
        exit;
    } catch (StockException $ex) {
        stockRollback();
        $errors[] = $ex->getMessage();
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$q = trim($_GET['q'] ?? '');
$like = '%' . $q . '%';
$in = "'" . implode("','", $allowed) . "'";
$st = mysqli_prepare($connect, "SELECT t.trx_code, t.trx_type, t.created_at, t.reference_doc_no, u.username,
        COUNT(l.id) AS n, SUM(ABS(l.signed_qty)) AS qty
    FROM inventory_transactions t JOIN users u ON u.id = t.created_by
    LEFT JOIN inventory_ledger_entries l ON l.transaction_id = t.id
    WHERE t.trx_status = 'COMMITTED' AND t.trx_type IN ($in) AND (t.reference_doc_no IS NULL OR (t.reference_doc_no NOT LIKE 'REV:%' AND t.reference_doc_no <> 'SALDO-AWAL'))
      AND (? = '' OR t.trx_code LIKE ? OR t.reference_doc_no LIKE ? OR u.username LIKE ?)
    GROUP BY t.id ORDER BY t.id DESC LIMIT 40");
mysqli_stmt_bind_param($st, 'ssss', $q, $like, $like, $like);
mysqli_stmt_execute($st);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
$revs = mysqli_fetch_all(mysqli_query($connect, "SELECT t.trx_code, t.reference_doc_no, t.created_at, t.notes, u.username
    FROM inventory_transactions t JOIN users u ON u.id = t.created_by
    WHERE t.reference_doc_no LIKE 'REV:%' ORDER BY t.id DESC LIMIT 15"), MYSQLI_ASSOC);
$typeLbl = ['INBOUND_PURCHASE' => 'Barang masuk', 'OUTBOUND_PRODUCTION' => 'Keluar produksi',
            'OUTBOUND_EXPORT_SALE' => 'Keluar ekspor', 'INTERNAL_BIN_TRANSFER' => 'Transfer rak'];

renderHeader('Pembatalan');
?>
<h2>Pembatalan Transaksi (Jurnal Koreksi)</h2>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>
<div class="panel">
  <form method="get" style="display:flex;gap:8px">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari no. transaksi / referensi / pembuat" style="max-width:360px">
    <button type="submit">Cari</button>
    <?php if ($q !== ''): ?><a class="btn sec" href="manage_reversal.php">Reset</a><?php endif; ?>
  </form>
  <small>Buku besar tidak pernah diubah: pembatalan membuat transaksi koreksi yang membalik mutasi. Gagal bila stok sudah terpakai/dipindah, atau rak sedang dibekukan.</small>
</div>
<div class="panel scroll">
  <table>
    <tr><th>Waktu</th><th>No. Transaksi</th><th>Jenis</th><th>Baris</th><th>Total qty</th><th>Referensi</th><th>Oleh</th><th>Batalkan</th></tr>
    <?php if (!$rows): ?><tr><td colspan="8">Tidak ada transaksi yang dapat dibatalkan.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr><td><?= e($r['created_at']) ?></td>
        <td><a href="print_trx.php?code=<?= urlencode($r['trx_code']) ?>" target="_blank"><?= e($r['trx_code']) ?></a></td>
        <td><?= e($typeLbl[$r['trx_type']] ?? $r['trx_type']) ?></td><td><?= (int)$r['n'] ?></td><td><?= e(fmtQty($r['qty'])) ?></td>
        <td><?= e($r['reference_doc_no']) ?></td><td><?= e($r['username']) ?></td>
        <td><form method="post" style="display:flex;gap:4px" onsubmit="return confirm('Batalkan <?= e($r['trx_code']) ?>? Dibuat jurnal koreksi dan tidak dapat diulang.')">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="code" value="<?= e($r['trx_code']) ?>">
          <input type="text" name="reason" maxlength="200" placeholder="Alasan *" required style="padding:6px;border:1px solid #ccc;border-radius:6px">
          <button class="danger">Batalkan</button></form></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php if ($revs): ?>
<div class="panel scroll"><strong>Koreksi terakhir</strong>
  <table><tr><th>Waktu</th><th>Jurnal koreksi</th><th>Membatalkan</th><th>Oleh</th><th>Catatan</th></tr>
    <?php foreach ($revs as $r): ?>
      <tr><td><?= e($r['created_at']) ?></td><td><?= e($r['trx_code']) ?></td><td><?= e(substr($r['reference_doc_no'], 4)) ?></td><td><?= e($r['username']) ?></td><td><small><?= e($r['notes']) ?></small></td></tr>
    <?php endforeach; ?>
  </table></div>
<?php endif; ?>
<?php renderFooter();
