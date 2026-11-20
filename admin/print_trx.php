<?php
/**
 * Cetak bukti transaksi (surat jalan / bukti penerimaan / BA opname, dll).
 * Buka: print_trx.php?code=<trx_code>. Harga hanya tampil bila peran boleh melihat harga.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/barcode.php';
require_once __DIR__ . '/photo_helper.php';
requirePermission('catalog', 'view');
$showCost = canSeePrice('catalog');

$code = trim($_GET['code'] ?? '');
$st = mysqli_prepare($connect, "SELECT t.*, cu.full_name AS creator, au.full_name AS approver
    FROM inventory_transactions t JOIN users cu ON cu.id = t.created_by LEFT JOIN users au ON au.id = t.approved_by
    WHERE t.trx_code = ?");
mysqli_stmt_bind_param($st, 's', $code);
mysqli_stmt_execute($st);
$t = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
if (!$t) {
    http_response_code(404);
    die('Transaksi tidak ditemukan.');
}

$st = mysqli_prepare($connect, "SELECT i.sku, i.item_name, u.unit_code, b.batch_number, b.expiry_date,
        w.warehouse_code, bn.bin_code, l.entry_direction, l.signed_qty, l.unit_cost_idr,
        b.arrival_photo_path, b.arrival_photo_at
    FROM inventory_ledger_entries l JOIN items i ON i.id = l.item_id JOIN units u ON u.id = i.unit_id
    JOIN item_batches b ON b.id = l.batch_id JOIN storage_bins bn ON bn.id = l.bin_id
    JOIN warehouses w ON w.id = l.warehouse_id WHERE l.transaction_id = ? ORDER BY l.id");
$tid = (int)$t['id'];
mysqli_stmt_bind_param($st, 'i', $tid);
mysqli_stmt_execute($st);
$lines = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

$titles = [
    'INBOUND_PURCHASE'      => ['Bukti Penerimaan Barang', 'Penerima / Gudang', 'Pengirim / Supplier'],
    'OUTBOUND_PRODUCTION'   => ['Bukti Pengeluaran Bahan Produksi', 'Penerima / Produksi', 'Petugas Gudang'],
    'OUTBOUND_EXPORT_SALE'  => ['Surat Jalan Ekspor', 'Penerima / Sopir', 'Petugas Gudang'],
    'INTERNAL_BIN_TRANSFER' => ['Bukti Transfer Antar Rak', 'Penerima / Gudang', 'Petugas Gudang'],
    'OPNAME_ADJUSTMENT'     => ['Berita Acara Penyesuaian Stock Opname', 'Saksi / Auditor', 'Petugas Gudang'],
    'PRODUCTION_OUTPUT'     => ['Bukti Hasil Produksi', 'Penerima / Gudang Barang Jadi', 'Supervisor Produksi'],
    'SCRAP_WRITE_OFF'       => ['Berita Acara Scrap / Penghapusan', 'Saksi / Auditor', 'Petugas Gudang'],
];
[$title, $sigRecv, $sigExtra] = $titles[$t['trx_type']] ?? ['Bukti Transaksi Stok', 'Penerima', 'Petugas Gudang'];
if ($t['trx_type'] === 'SCRAP_WRITE_OFF' && strpos((string)$t['reference_doc_no'], 'RTR-') === 0) {
    [$title, $sigRecv, $sigExtra] = ['Bukti Retur Barang ke Supplier', 'Diterima Supplier / Sopir', 'Petugas Gudang'];
}

function pq($v) { $s = rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.'); return $s === '' ? '0' : $s; }
$totalIn = 0.0; $totalOut = 0.0;

// Bukti penerimaan: foto kedatangan + waktu (hari, tanggal, tahun, jam:menit:detik)
$arrPhoto = ''; $arrAt = null;
if ($t['trx_type'] === 'INBOUND_PURCHASE') {
    foreach ($lines as $l) {
        $cand = wmsValidArrivalPath($l['arrival_photo_path'] ?? '');
        if ($cand !== '') {
            $arrPhoto = $cand;
            $arrAt = $l['arrival_photo_at'] ?? wmsPhotoTimeFromPath($cand);
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title . ' ' . $t['trx_code']) ?></title>
<style>
  *{box-sizing:border-box}
  body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#000;margin:0;background:#eee}
  .page{width:210mm;min-height:148mm;margin:12px auto;background:#fff;padding:12mm 14mm;box-shadow:0 0 8px rgba(0,0,0,.2)}
  .bar{max-width:210mm;margin:12px auto 0;display:flex;gap:8px}
  .bar button,.bar a{padding:8px 14px;border:0;border-radius:6px;background:#7a4b1e;color:#fff;font-size:13px;cursor:pointer;text-decoration:none}
  .head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #000;padding-bottom:8px;margin-bottom:10px}
  .head h1{font-size:16px;margin:0 0 2px} .head small{display:block;color:#333}
  h2{font-size:15px;margin:0 0 8px;text-align:center;text-transform:uppercase}
  table{width:100%;border-collapse:collapse}
  .meta td{padding:2px 4px;vertical-align:top} .meta td:first-child{width:110px;color:#333}
  .items{margin-top:10px} .items th,.items td{border:1px solid #000;padding:5px 6px;text-align:left}
  .items th{background:#f0f0f0} .num{text-align:right}
  .sign{display:flex;gap:10px;margin-top:22px} .sign div{flex:1;text-align:center;font-size:11px}
  .sign .line{height:56px;border-bottom:1px solid #000;margin-bottom:3px}
  .foot{margin-top:10px;color:#444;font-size:10px}
  @media print{body{background:#fff}.page{box-shadow:none;margin:0;width:auto;padding:0}.bar{display:none}@page{size:A4;margin:12mm}}
</style>
<?php require_once __DIR__ . '/theme.php'; wmsThemeEmit(); ?>
</head>
<body>
<div class="bar"><button onclick="window.print()">Cetak</button><a href="manage_ledger.php" onclick="if (history.length > 1) { history.back(); } else { window.close(); } return false;">Kembali</a></div>
<div class="page">
  <div class="head">
    <div><h1>PT. DEKOR ASIA JAYAKARYA</h1><small>Warehouse Management System</small></div>
    <div style="text-align:right"><?= code128Svg($t['trx_code'], 40, 1) ?><small><?= e($t['trx_code']) ?></small></div>
  </div>
  <h2><?= e($title) ?></h2>
  <table class="meta">
    <tr><td>No. Transaksi</td><td>: <b><?= e($t['trx_code']) ?></b></td><td>Tanggal</td><td>: <?= e($t['created_at']) ?></td></tr>
    <tr><td>Referensi</td><td>: <?= e($t['reference_doc_no'] ?? '-') ?></td><td>Status</td><td>: <?= e($t['trx_status']) ?></td></tr>
    <?php if (!empty($t['notes'])): ?><tr><td>Catatan</td><td colspan="3">: <?= e($t['notes']) ?></td></tr><?php endif; ?>
  </table>
  <?php if ($arrPhoto !== ''): ?>
  <table class="meta" style="margin-top:6px"><tr><td>Waktu datang</td><td>: <b><?= e($arrAt ? wmsFmtWaktu($arrAt) : '-') ?></b><br><small>dicatat server saat foto barang diunggah</small></td>
    <td style="width:130px;text-align:right"><img src="<?= e($arrPhoto) ?>" alt="Foto barang datang" style="width:120px;height:90px;object-fit:cover;border:1px solid #888"></td></tr></table>
  <?php endif; ?>
  <table class="items">
    <tr><th style="width:26px">No</th><th>SKU / Barang</th><th>Lot</th><th>Gudang / Rak</th><th>D/K</th><th class="num">Jumlah</th><?php if ($showCost): ?><th class="num">Nilai (Rp)</th><?php endif; ?></tr>
    <?php foreach ($lines as $n => $l):
        $val = abs((float)$l['signed_qty']) * (float)$l['unit_cost_idr'];
        if ($l['entry_direction'] === 'DEBIT_IN') $totalIn += $val; else $totalOut += $val; ?>
      <tr><td><?= $n + 1 ?></td>
        <td><b><?= e($l['sku']) ?></b><br><?= e($l['item_name']) ?></td>
        <td><?= e($l['batch_number']) ?><?= $l['expiry_date'] ? '<br><small>Exp ' . e($l['expiry_date']) . '</small>' : '' ?></td>
        <td><?= e($l['warehouse_code']) ?><br><?= e($l['bin_code']) ?></td>
        <td><?= $l['entry_direction'] === 'DEBIT_IN' ? 'Masuk' : 'Keluar' ?></td>
        <td class="num"><b><?= e(pq(abs($l['signed_qty']))) ?></b> <?= e($l['unit_code']) ?></td>
        <?php if ($showCost): ?><td class="num"><?= e(number_format($val, 0, ',', '.')) ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    <?php if (!$lines): ?><tr><td colspan="<?= $showCost ? 7 : 6 ?>">Tidak ada baris mutasi.</td></tr><?php endif; ?>
    <?php if ($showCost && $lines && ($totalIn == 0.0 || $totalOut == 0.0)): $isIn = $totalOut == 0.0; ?>
      <tr><td colspan="6" class="num"><b>Total nilai <?= $isIn ? 'masuk' : 'keluar' ?></b></td><td class="num"><b><?= e(number_format($isIn ? $totalIn : $totalOut, 0, ',', '.')) ?></b></td></tr>
    <?php endif; ?>
  </table>
  <div class="sign">
    <div><div class="line"></div>Dibuat oleh<br><b><?= e($t['creator']) ?></b></div>
    <div><div class="line"></div>Disetujui oleh<br><b><?= e($t['approver'] ?? '(........................)') ?></b></div>
    <div><div class="line"></div><?= e($sigExtra) ?><br>(........................)</div>
    <div><div class="line"></div><?= e($sigRecv) ?><br>(........................)</div>
  </div>
  <div class="foot">Dicetak <?= e(date('Y-m-d H:i')) ?> oleh <?= e($_SESSION['full_name'] ?? $_SESSION['username'] ?? '') ?>. Dokumen dihasilkan sistem; keaslian dapat diperiksa dengan No. Transaksi di Buku Besar.</div>
</div>
</body>
</html>