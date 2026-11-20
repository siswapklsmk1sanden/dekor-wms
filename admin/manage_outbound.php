<?php
/**
 * Barang Keluar: pengeluaran bahan ke produksi atau stuffing kontainer ekspor.
 * Rak & lot dipilih otomatis sesuai strategi kategori:
 *   FIFO = lot terlama diterima dulu, FEFO = lot paling cepat kedaluwarsa dulu.
 * Hanya lot QC PASSED, rak tidak beku, dan lot belum kedaluwarsa.
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/reservation_helper.php';
require_once __DIR__ . '/layout.php';
requirePermission('outbound', 'view');
wmsAutoMaintenance();
wmsEnsureSchemaExt();   // tabel reservasi (v38); tidak fatal bila gagal

$canCreate = canAccess('outbound', 'create');
$csrf      = stockCsrf();
$errors    = [];
$form      = ['item_id' => '', 'qty' => '', 'trx_type' => 'OUTBOUND_PRODUCTION', 'ref' => '', 'notes' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    stockCheckCsrf();
    draftGate('outbound', 'Barang Keluar');
    foreach ($form as $k => $_) $form[$k] = trim((string)($_POST[$k] ?? ''));

    $itemId = (int)$form['item_id'];
    $qty    = $form['qty'];
    $type   = in_array($form['trx_type'], ['OUTBOUND_PRODUCTION', 'OUTBOUND_EXPORT_SALE'], true)
            ? $form['trx_type'] : 'OUTBOUND_PRODUCTION';

    $st = mysqli_prepare($connect, "SELECT i.*, c.allocation_strategy, c.category_type
        FROM items i JOIN categories c ON c.id = i.category_id WHERE i.id = ? AND i.is_active = 1");
    mysqli_stmt_bind_param($st, 'i', $itemId);
    mysqli_stmt_execute($st);
    $item = mysqli_fetch_assoc(mysqli_stmt_get_result($st));

    if (!$item) $errors[] = 'Barang wajib dipilih.';
    if (!is_numeric($qty) || (float)$qty <= 0) $errors[] = 'Jumlah harus lebih dari 0.';
    if ($item) {
        $isFg = $item['category_type'] === 'FINISHED_GOOD';
        if ($type === 'OUTBOUND_EXPORT_SALE' && !$isFg) $errors[] = 'Pengiriman ekspor hanya untuk barang jadi.';
        if ($type === 'OUTBOUND_PRODUCTION' && $isFg) $errors[] = 'Barang jadi tidak dikeluarkan ke produksi; pilih jenis Ekspor.';
    }
    if ($type === 'OUTBOUND_EXPORT_SALE' && $form['ref'] === '') {
        $errors[] = 'No. kontainer / invoice ekspor wajib diisi untuk pengiriman ekspor.';
    }
    if ($type === 'OUTBOUND_PRODUCTION' && $form['ref'] === '') {
        $errors[] = 'No. SPK / work order wajib diisi untuk pengeluaran ke produksi.';
    }

    if (!$errors) {
        try {
            stockBegin();
            // Reservasi aktif untuk barang + No. SPK yang sama dipakai dulu (dilepas supaya terhitung tersedia)
            $rsv = rsvConsume($itemId, $form['ref'], $qty, 'Barang Keluar');
            $plan = planAllocation($itemId, $qty, $item['allocation_strategy']);
            [$trxId, $trxCode] = stockCreateTrx($type, $form['ref'], $form['notes']);
            $detail = [];
            foreach ($plan as $p) {
                $bin = stockGetBin((int)$p['bin_id']);
                stockMove($trxId, $itemId, $bin, (int)$p['batch_id'], -$p['take'], (float)$p['unit_cost_idr']);
                $detail[] = $p['bin_code'] . '/' . $p['batch_number'] . ' x' . fmtQty($p['take']);
            }
            stockCommit();
            logActivity('CREATE', 'Outbound',
                "Pengeluaran $trxCode ($type): {$item['sku']} x " . fmtQty($qty) . ' [' . implode(', ', $detail) . ']' . rsvConsumeNote($rsv), $trxId);
            $_SESSION['flash'] = ['ok', "Pengeluaran tercatat: $trxCode (" . count($plan) . ' baris lot/rak, strategi ' . $item['allocation_strategy'] . ').' . rsvConsumeNote($rsv)];
            header('Location: manage_outbound.php');
            exit;
        } catch (StockException $ex) {
            stockRollback();
            $errors[] = $ex->getMessage();
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$items = mysqli_fetch_all(mysqli_query($connect, "SELECT i.id, i.sku, i.barcode_ean13, i.item_name, u.unit_code, c.allocation_strategy,
        COALESCE(SUM(CASE WHEN b.qc_status = 'PASSED' AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
                          THEN sb.qty_on_hand - sb.qty_reserved END), 0) AS avail
    FROM items i JOIN units u ON u.id = i.unit_id JOIN categories c ON c.id = i.category_id
    LEFT JOIN stock_balances sb ON sb.item_id = i.id
    LEFT JOIN item_batches b ON b.id = sb.batch_id
    WHERE i.is_active = 1 GROUP BY i.id ORDER BY i.sku"), MYSQLI_ASSOC);
$recent = mysqli_fetch_all(mysqli_query($connect, "SELECT t.trx_code, t.trx_type, t.created_at, t.reference_doc_no,
        i.sku, i.item_name, b.batch_number, bn.bin_code, l.signed_qty, u.unit_code, uu.username
    FROM inventory_transactions t
    JOIN inventory_ledger_entries l ON l.transaction_id = t.id
    JOIN items i ON i.id = l.item_id JOIN units u ON u.id = i.unit_id
    JOIN item_batches b ON b.id = l.batch_id JOIN storage_bins bn ON bn.id = l.bin_id
    JOIN users uu ON uu.id = t.created_by
    WHERE t.trx_type IN ('OUTBOUND_PRODUCTION', 'OUTBOUND_EXPORT_SALE') ORDER BY t.id DESC, l.id DESC LIMIT 30"), MYSQLI_ASSOC);

renderHeader('Barang Keluar');
?>
<h2>Barang Keluar</h2>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($canCreate): ?>
<?php renderScanBox(); ?>
<div class="panel">
  <strong>Keluarkan barang</strong> <small>(rak &amp; lot dipilih otomatis: FIFO / FEFO)</small>
  <form method="post" autocomplete="off" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="grid">
      <div><label>Jenis pengeluaran *</label>
        <select name="trx_type">
          <option value="OUTBOUND_PRODUCTION" <?= $form['trx_type'] === 'OUTBOUND_PRODUCTION' ? 'selected' : '' ?>>Ke lini produksi</option>
          <option value="OUTBOUND_EXPORT_SALE" <?= $form['trx_type'] === 'OUTBOUND_EXPORT_SALE' ? 'selected' : '' ?>>Ekspor (stuffing kontainer)</option>
        </select></div>
      <div><label>Barang *</label>
        <select name="item_id" required><option value="">- pilih -</option>
        <?php foreach ($items as $i): ?><option value="<?= (int)$i['id'] ?>" data-ean="<?= e($i['barcode_ean13'] ?? '') ?>" <?= (string)$i['id'] === $form['item_id'] ? 'selected' : '' ?>><?= e($i['sku'] . ' - ' . $i['item_name'] . ' | tersedia ' . fmtQty($i['avail']) . ' ' . $i['unit_code'] . ' | ' . $i['allocation_strategy']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label>Jumlah *</label><input type="number" name="qty" step="0.0001" min="0.0001" value="<?= e($form['qty']) ?>" required></div>
      <div><label>No. SPK / kontainer / invoice *</label><input type="text" name="ref" maxlength="60" value="<?= e($form['ref']) ?>" required></div>
      <div><label>Catatan</label><input type="text" name="notes" maxlength="500" value="<?= e($form['notes']) ?>"></div>
    </div>
    <p style="margin:14px 0 0"><button type="submit"><?= canAccess('outbound', 'approve') ? 'Proses Pengeluaran' : 'Ajukan Draft Pengeluaran' ?></button></p>
  </form>
</div>
<?php endif; ?>

<div class="panel scroll">
  <strong>Pengeluaran terakhir</strong>
  <table>
    <tr><th>Waktu</th><th>No. Transaksi</th><th>Jenis</th><th>Barang</th><th>Lot</th><th>Rak</th><th>Jumlah</th><th>Ref</th><th>Oleh</th></tr>
    <?php if (!$recent): ?><tr><td colspan="9">Belum ada pengeluaran.</td></tr><?php endif; ?>
    <?php foreach ($recent as $r): ?>
      <tr><td><?= e($r['created_at']) ?></td><td><a href="print_trx.php?code=<?= urlencode($r['trx_code']) ?>" target="_blank" title="Cetak bukti"><?= e($r['trx_code']) ?></a></td>
        <td><?= $r['trx_type'] === 'OUTBOUND_EXPORT_SALE' ? 'Ekspor' : 'Produksi' ?></td>
        <td><?= e($r['sku']) ?><br><small><?= e($r['item_name']) ?></small></td>
        <td><?= e($r['batch_number']) ?></td><td><?= e($r['bin_code']) ?></td>
        <td><?= e(fmtQty(abs($r['signed_qty']))) ?> <?= e($r['unit_code']) ?></td>
        <td><?= e($r['reference_doc_no']) ?></td><td><?= e($r['username']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php renderFooter();
