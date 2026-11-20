<?php
/**
 * Cetak Surat Retur Barang ke Supplier. Buka: print_return.php?code=<trx_code>.
 * Hanya untuk transaksi retur (SCRAP_WRITE_OFF dengan referensi RTR-). Harga hanya tampil bila peran boleh melihat harga.
 */
require_once __DIR__ . '/config.php';
requirePermission('inbound', 'view');
$showCost = canSeePrice('inbound');

function prq($v) { $s = rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.'); return $s === '' ? '0' : $s; }

$code = trim($_GET['code'] ?? '');
$st = mysqli_prepare($connect, "SELECT t.*, cu.full_name AS creator, au.full_name AS approver
    FROM inventory_transactions t JOIN users cu ON cu.id = t.created_by LEFT JOIN users au ON au.id = t.approved_by
    WHERE t.trx_code = ? AND t.trx_type = 'SCRAP_WRITE_OFF' AND t.reference_doc_no LIKE 'RTR-%'");
mysqli_stmt_bind_param($st, 's', $code);
mysqli_stmt_execute($st);
$t = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
if (!$t) {
    http_response_code(404);
    die('Surat retur tidak ditemukan.');
}

$st = mysqli_prepare($connect, "SELECT i.sku, i.item_name, u.unit_code, b.batch_number, b.received_date, s.company_name, s.supplier_code, s.contact_person,
        bn.bin_code, -l.signed_qty AS qty, l.unit_cost_idr
    FROM inventory_ledger_entries l JOIN items i ON i.id = l.item_id JOIN units u ON u.id = i.unit_id
    JOIN item_batches b ON b.id = l.batch_id LEFT JOIN suppliers s ON s.id = b.supplier_id
    JOIN storage_bins bn ON bn.id = l.bin_id WHERE l.transaction_id = ? ORDER BY l.id");
$tid = (int)$t['id'];
mysqli_stmt_bind_param($st, 'i', $tid);
mysqli_stmt_execute($st);
$lines = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
$sup = $lines[0] ?? [];
$total = 0.0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Surat Retur <?= e($t['reference_doc_no']) ?></title>
<style>
  *{box-sizing:border-box}
  body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#000;margin:0;background:#eee}
  .page{width:210mm;min-height:148mm;margin:12px auto;background:#fff;padding:12mm 14mm;box-shadow:0 0 8px rgba(0,0,0,.2)}
  .bar{max-width:210mm;margin:12px auto 0;display:flex;gap:8px}
  .bar button,.bar a{padding:8px 14px;border:0;border-radius:6px;background:#7a4b1e;color:#fff;font-size:13px;cursor:pointer;text-decoration:none}
  .head{border-bottom:2px solid #000;padding-bottom:8px;margin-bottom:10px}
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
<div class="bar"><button onclick="window.print()">Cetak</button><a href="manage_return.php">Kembali</a></div>
<div class="page">
  <div class="head"><h1>PT. DEKOR ASIA JAYAKARYA</h1><small>Warehouse Management System</small></div>
  <h2>Surat Retur Barang ke Supplier</h2>
  <table class="meta">
    <tr><td>No. Retur</td><td>: <b><?= e($t['reference_doc_no']) ?></b></td><td>Tanggal</td><td>: <?= e($t['created_at']) ?></td></tr>
    <tr><td>Kepada</td><td>: <b><?= e($sup['company_name'] ?? '-') ?></b><?= !empty($sup['supplier_code']) ? ' (' . e($sup['supplier_code']) . ')' : '' ?></td><td>No. Transaksi</td><td>: <?= e($t['trx_code']) ?></td></tr>
    <?php if (!empty($sup['contact_person'])): ?><tr><td>U.p.</td><td>: <?= e($sup['contact_person']) ?></td><td></td><td></td></tr><?php endif; ?>
    <?php if (!empty($t['notes'])): ?><tr><td>Keterangan</td><td colspan="3">: <?= e($t['notes']) ?></td></tr><?php endif; ?>
  </table>
  <p style="margin:10px 0 0">Dengan ini kami mengembalikan barang berikut karena tidak lolos pemeriksaan mutu (QC) di gudang kami. Mohon diproses penggantian atau pengembalian dana sesuai kesepakatan.</p>
  <table class="items">
    <tr><th style="width:26px">No</th><th>SKU / Barang</th><th>Lot</th><th>Tgl terima</th><th>Rak asal</th><th class="num">Jumlah</th><?php if ($showCost): ?><th class="num">Nilai (Rp)</th><?php endif; ?></tr>
    <?php foreach ($lines as $n => $l): $val = (float)$l['qty'] * (float)$l['unit_cost_idr']; $total += $val; ?>
      <tr><td><?= $n + 1 ?></td><td><b><?= e($l['sku']) ?></b><br><?= e($l['item_name']) ?></td>
        <td><?= e($l['batch_number']) ?></td><td><?= e($l['received_date']) ?></td><td><?= e($l['bin_code']) ?></td>
        <td class="num"><b><?= e(prq($l['qty'])) ?></b> <?= e($l['unit_code']) ?></td>
        <?php if ($showCost): ?><td class="num"><?= e(number_format($val, 0, ',', '.')) ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    <?php if ($showCost && $lines): ?><tr><td colspan="6" class="num"><b>Total nilai</b></td><td class="num"><b><?= e(number_format($total, 0, ',', '.')) ?></b></td></tr><?php endif; ?>
  </table>
  <div class="sign">
    <div><div class="line"></div>Dibuat oleh<br><b><?= e($t['creator']) ?></b></div>
    <div><div class="line"></div>Disetujui oleh<br><b><?= e($t['approver'] ?? '(........................)') ?></b></div>
    <div><div class="line"></div>Petugas Gudang<br>(........................)</div>
    <div><div class="line"></div>Diterima Supplier / Sopir<br>(........................)</div>
  </div>
  <div class="foot">Dicetak <?= e(date('Y-m-d H:i')) ?> oleh <?= e($_SESSION['full_name'] ?? $_SESSION['username'] ?? '') ?>. Dokumen dihasilkan sistem; keaslian dapat diperiksa dengan No. Transaksi di Buku Besar.</div>
</div>
</body>
</html>
