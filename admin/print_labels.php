<?php
/**
 * Cetak label barcode: rak (kode rak) dan lot (SKU/NO.LOT), Code 128.
 * print_labels.php?type=bin|lot[&q=cari][&id=...]
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/barcode.php';
requirePermission('catalog', 'view');

$type = ($_GET['type'] ?? 'bin') === 'lot' ? 'lot' : 'bin';
$q    = trim($_GET['q'] ?? '');
$id   = (int)($_GET['id'] ?? 0);
$like = '%' . $q . '%';

if ($type === 'bin') {
    $st = mysqli_prepare($connect, "SELECT b.id, b.bin_code, b.zone_name, w.warehouse_code, w.warehouse_name
        FROM storage_bins b JOIN warehouses w ON w.id = b.warehouse_id
        WHERE b.is_active = 1 AND (? = 0 OR b.id = ?) AND (? = '' OR b.bin_code LIKE ? OR b.zone_name LIKE ?)
        ORDER BY b.bin_code LIMIT 300");
    mysqli_stmt_bind_param($st, 'iisss', $id, $id, $q, $like, $like);
} else {
    // hanya lot yang masih ada stoknya
    $st = mysqli_prepare($connect, "SELECT b.id, b.batch_number, b.received_date, b.expiry_date, b.qc_status,
            i.sku, i.item_name, u.unit_code, COALESCE(SUM(sb.qty_on_hand), 0) AS qty
        FROM item_batches b JOIN items i ON i.id = b.item_id JOIN units u ON u.id = i.unit_id
        JOIN stock_balances sb ON sb.batch_id = b.id
        WHERE (? = 0 OR b.id = ?) AND (? = '' OR i.sku LIKE ? OR i.item_name LIKE ? OR b.batch_number LIKE ?)
        GROUP BY b.id HAVING qty > 0 ORDER BY i.sku, b.batch_number LIMIT 300");
    mysqli_stmt_bind_param($st, 'iissss', $id, $id, $q, $like, $like, $like);
}
mysqli_stmt_execute($st);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

function ql($v) { $s = rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.'); return $s === '' ? '0' : $s; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Label <?= $type === 'bin' ? 'Rak' : 'Lot' ?> - WMS Dekor Asia Jayakarya</title>
<style>
  *{box-sizing:border-box}
  body{font-family:Arial,Helvetica,sans-serif;margin:0;background:#eee;color:#000}
  .tool{background:#fff;padding:10px 16px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;border-bottom:1px solid #ccc}
  .tool a,.tool button{padding:8px 12px;border:0;border-radius:6px;background:#6c757d;color:#fff;font-size:13px;text-decoration:none;cursor:pointer}
  .tool a.on{background:#7a4b1e} .tool input{padding:8px;border:1px solid #ccc;border-radius:6px}
  .sheet{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:14px;max-width:210mm;margin:auto}
  .label{background:#fff;border:1px solid #000;border-radius:4px;padding:8px 10px;break-inside:avoid;page-break-inside:avoid;text-align:center;min-height:42mm}
  .label .big{font-size:20px;font-weight:700;letter-spacing:.5px;margin:2px 0}
  .label .sm{font-size:11px;color:#222} .label svg{max-width:100%;height:auto}
  .label .nm{font-size:12px;font-weight:700;margin-top:2px}
  @media print{body{background:#fff}.tool{display:none}.sheet{padding:0;gap:6mm}@page{size:A4;margin:10mm}}
</style>
<?php require_once __DIR__ . '/theme.php'; wmsThemeEmit(); ?>
</head>
<body>
<form class="tool" method="get">
  <a class="<?= $type === 'bin' ? 'on' : '' ?>" href="?type=bin">Label Rak</a>
  <a class="<?= $type === 'lot' ? 'on' : '' ?>" href="?type=lot">Label Lot</a>
  <input type="hidden" name="type" value="<?= e($type) ?>">
  <input type="text" name="q" value="<?= e($q) ?>" placeholder="<?= $type === 'bin' ? 'Cari kode rak / zona' : 'Cari SKU / nama / no. lot' ?>">
  <button type="submit">Cari</button>
  <button type="button" onclick="window.print()" style="background:#7a4b1e">Cetak (<?= count($rows) ?> label)</button>
  <a href="manage_stock_balance.php">Kembali</a>
</form>
<div class="sheet">
<?php if (!$rows): ?><p style="grid-column:1/-1;text-align:center">Tidak ada data untuk dicetak.</p><?php endif; ?>
<?php foreach ($rows as $r): ?>
  <?php if ($type === 'bin'): ?>
  <div class="label">
    <div class="sm"><?= e($r['warehouse_code'] . ' - ' . $r['warehouse_name']) ?></div>
    <div class="big"><?= e($r['bin_code']) ?></div>
    <div class="sm"><?= e($r['zone_name']) ?></div>
    <?= code128Svg($r['bin_code'], 46, 2) ?>
  </div>
  <?php else: ?>
  <div class="label">
    <div class="nm"><?= e($r['sku']) ?></div>
    <div class="sm"><?= e($r['item_name']) ?></div>
    <div class="big" style="font-size:16px">Lot <?= e($r['batch_number']) ?></div>
    <div class="sm">Terima <?= e($r['received_date']) ?><?= $r['expiry_date'] ? ' &middot; Exp ' . e($r['expiry_date']) : '' ?> &middot; QC <?= e($r['qc_status']) ?></div>
    <?= code128Svg($r['sku'] . '/' . $r['batch_number'], 46, 2) ?>
    <div class="sm"><?= e($r['sku'] . '/' . $r['batch_number']) ?> &middot; stok <?= e(ql($r['qty'])) ?> <?= e($r['unit_code']) ?></div>
  </div>
  <?php endif; ?>
<?php endforeach; ?>
</div>
</body>
</html>
