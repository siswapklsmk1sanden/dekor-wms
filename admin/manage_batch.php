<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/photo_helper.php';
requirePermission('catalog', 'view');

$showPrice = canSeePrice('catalog');
$rs = mysqli_query($connect, "SELECT b.*, i.sku, i.item_name, s.company_name
        FROM item_batches b
        JOIN items i ON i.id = b.item_id
        LEFT JOIN suppliers s ON s.id = b.supplier_id
        ORDER BY b.received_date DESC, b.id DESC");
$rows = [];
while ($rs && ($r = mysqli_fetch_assoc($rs))) $rows[] = $r;

renderHeader('Lot Batch');
?>
<h2>Lot Batch</h2>
<div class="panel scroll">
  <p style="margin-top:0;color:#666;font-size:13px">Lot baru dibuat otomatis saat penerimaan barang (menu Barang Masuk). Halaman ini hanya untuk menelusuri asal dan kondisi stok.</p>
  <table class="fit">
    <tr><th>No. Lot</th><th>Barang</th><th>Supplier</th><th>Tgl Terima</th><th>Waktu Kedatangan</th><th>Kedaluwarsa</th><th>Kadar Air (%)</th><th>QC</th><th>Foto</th><?php if ($showPrice): ?><th>Harga/unit</th><?php endif; ?></tr>
    <?php if (!$rows): ?><tr><td colspan="10">Belum ada lot.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td class="nw"><?= e($r['batch_number']) ?></td>
        <td><span class="nw"><?= e($r['sku']) ?></span><br><small><?= e($r['item_name']) ?></small></td>
        <td><?= e($r['company_name'] ?? '-') ?></td>
        <td class="nw"><?= e($r['received_date']) ?></td>
        <td><?php $at = $r['arrival_photo_at'] ?? wmsPhotoTimeFromPath($r['arrival_photo_path'] ?? ''); echo $at ? e(wmsFmtWaktu($at)) : '-'; ?></td>
        <td class="nw"><?= e($r['expiry_date'] ?? '-') ?></td>
        <td><?= e($r['moisture_content_pct'] ?? '-') ?></td>
        <td><span class="tag <?= $r['qc_status'] === 'PASSED' ? '' : 'off' ?>"><?= e($r['qc_status']) ?></span></td>
        <td><?php $ph = wmsValidArrivalPath($r['arrival_photo_path'] ?? ''); if ($ph !== ''): ?><a href="<?= e($ph) ?>" target="_blank"><img src="<?= e($ph) ?>" alt="Foto" style="width:48px;height:36px;object-fit:cover;border-radius:4px;border:1px solid #ccc"></a><?php else: ?>-<?php endif; ?></td>
        <?php if ($showPrice): ?><td class="nw"><?= e(number_format((float)$r['unit_cost_idr'], 2, ',', '.')) ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php renderFooter();
