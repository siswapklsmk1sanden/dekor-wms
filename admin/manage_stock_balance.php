<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/layout.php';
requirePermission('catalog', 'view');
require_once __DIR__ . '/reservation_helper.php';
wmsAutoMaintenance();
$showValue = canSeePrice('catalog');   // harga & nilai Rupiah: hanya peran dengan hide_price = 0 (Kepala Gudang, Finance, Super Admin)

$q = trim($_GET['q'] ?? '');
$like = '%' . $q . '%';
$st = mysqli_prepare($connect, "SELECT i.sku, i.item_name, u.unit_code, w.warehouse_code, bn.bin_code,
        b.batch_number, b.qc_status, sb.qty_on_hand, sb.qty_reserved, b.unit_cost_idr
        FROM stock_balances sb
        JOIN items i ON i.id = sb.item_id
        JOIN units u ON u.id = i.unit_id
        JOIN warehouses w ON w.id = sb.warehouse_id
        JOIN storage_bins bn ON bn.id = sb.bin_id
        JOIN item_batches b ON b.id = sb.batch_id
        WHERE (? = '' OR i.sku LIKE ? OR i.item_name LIKE ? OR bn.bin_code LIKE ? OR b.batch_number LIKE ?)
        ORDER BY i.sku, bn.bin_code");
mysqli_stmt_bind_param($st, 'sssss', $q, $like, $like, $like, $like);
mysqli_stmt_execute($st);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

if (($_GET['export'] ?? '') === 'csv') {
    $o = [];
    foreach ($rows as $r) {
        $o[] = [$r['sku'], $r['item_name'], $r['warehouse_code'], $r['bin_code'], $r['batch_number'], $r['qc_status'],
                fmtQ2($r['qty_on_hand']), fmtQ2($r['qty_reserved']), fmtQ2((float)$r['qty_on_hand'] - (float)$r['qty_reserved']), $r['unit_code']];
        if ($showValue) {
            $o[count($o) - 1][] = number_format((float)$r['unit_cost_idr'], 2, '.', '');
            $o[count($o) - 1][] = number_format((float)$r['qty_on_hand'] * (float)$r['unit_cost_idr'], 2, '.', '');
        }
    }
    $hdr = ['SKU', 'Barang', 'Gudang', 'Rak', 'No. Lot', 'QC', 'Di tangan', 'Dipesan', 'Tersedia', 'Satuan'];
    if ($showValue) { $hdr[] = 'Harga lot (Rp)'; $hdr[] = 'Nilai (Rp)'; }
    csvOut('saldo_stok_' . date('Ymd_His') . '.csv', $hdr, $o);
}
function fmtQ2($v) { return rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.') ?: '0'; }

renderHeader('Saldo Stok');
?>
<h2>Saldo Stok per Rak &amp; Lot</h2>
<div class="panel">
  <form method="get" style="display:flex;gap:8px">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari SKU, nama, kode rak, atau no. lot" style="max-width:360px">
    <button type="submit">Cari</button>
    <?php if ($q !== ''): ?><a class="btn sec" href="manage_stock_balance.php">Reset</a><?php endif; ?>
    <a class="btn sec" href="?<?= e(http_build_query(['q' => $q, 'export' => 'csv'])) ?>">Ekspor Excel</a>
    <a class="btn sec" href="print_labels.php?type=bin" target="_blank">Label Rak</a>
    <a class="btn sec" href="print_labels.php?type=lot&q=<?= urlencode($q) ?>" target="_blank">Label Lot</a>
  </form>
</div>
<div class="panel scroll">
  <table>
    <tr><th>SKU</th><th>Barang</th><th>Gudang</th><th>Rak</th><th>No. Lot</th><th>QC</th><th>Di tangan</th><th>Dipesan</th><th>Tersedia</th><?php if ($showValue): ?><th>Harga lot (Rp)</th><th>Nilai (Rp)</th><?php endif; ?></tr>
    <?php $totalValue = 0.0; ?>
    <?php if (!$rows): ?><tr><td colspan="11">Tidak ada data stok.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r):
        $avail = (float)$r['qty_on_hand'] - (float)$r['qty_reserved'];
        $lineValue = (float)$r['qty_on_hand'] * (float)$r['unit_cost_idr'];
        $totalValue += $lineValue; ?>
      <tr>
        <td><?= e($r['sku']) ?></td>
        <td><?= e($r['item_name']) ?></td>
        <td><?= e($r['warehouse_code']) ?></td>
        <td><?= e($r['bin_code']) ?></td>
        <td><?= e($r['batch_number']) ?></td>
        <td><span class="tag <?= $r['qc_status'] === 'PASSED' ? '' : 'off' ?>"><?= e($r['qc_status']) ?></span></td>
        <td><?= e(rtrim(rtrim(number_format((float)$r['qty_on_hand'], 4, '.', ''), '0'), '.')) ?> <?= e($r['unit_code']) ?></td>
        <td><?= e(rtrim(rtrim(number_format((float)$r['qty_reserved'], 4, '.', ''), '0'), '.')) ?></td>
        <td><b><?= e(rtrim(rtrim(number_format($avail, 4, '.', ''), '0'), '.')) ?></b></td>
        <?php if ($showValue): ?>
        <td><?= e(number_format((float)$r['unit_cost_idr'], 0, ',', '.')) ?></td>
        <td><?= e(number_format($lineValue, 0, ',', '.')) ?></td>
        <?php endif; ?>
      </tr>
    <?php endforeach; ?>
    <?php if ($showValue && $rows): ?>
      <tr><td colspan="10" style="text-align:right"><b>Total nilai persediaan (sesuai hasil pencarian)</b></td><td><b><?= e(number_format($totalValue, 0, ',', '.')) ?></b></td></tr>
    <?php endif; ?>
  </table>
</div>
<?php renderFooter();