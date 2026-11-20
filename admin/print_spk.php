<?php
/**
 * Cetak surat SPK (Surat Perintah Kirim Barang) ke supplier.
 * Buka: print_spk.php?id=<ID PO>. Boleh dilihat peran dengan hak Lihat pada "buyer" atau "inbound".
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/barcode.php';
require_once __DIR__ . '/photo_helper.php';
requireLogin();
if (!canAccess('buyer', 'view') && !canAccess('inbound', 'view')) {
    http_response_code(403);
    die('Akses ditolak untuk peran Anda.');
}
$showPrice = canSeePrice('buyer');

$id = (int)($_GET['id'] ?? 0);
$st = mysqli_prepare($connect, "SELECT p.*, s.company_name, s.contact_person, s.phone, s.supplier_code,
        cu.full_name AS creator
    FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id JOIN users cu ON cu.id = p.created_by
    WHERE p.id = ?");
mysqli_stmt_bind_param($st, 'i', $id);
mysqli_stmt_execute($st);
$po = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
if (!$po) {
    http_response_code(404);
    die('PO tidak ditemukan.');
}

$docNo = (string)($po['spk_number'] ?: $po['po_number']);

$st = mysqli_prepare($connect, "SELECT l.qty_ordered, l.unit_price_idr, i.sku, i.item_name, u.unit_code,
        i.reference_photo_path AS ref_photo, i.specification AS spec
    FROM purchase_order_lines l JOIN items i ON i.id = l.item_id
    JOIN units u ON u.id = i.unit_id WHERE l.po_id = ? ORDER BY l.id");
mysqli_stmt_bind_param($st, 'i', $id);
mysqli_stmt_execute($st);
$lines = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

$hasPrice = false;
foreach ($lines as $l) if ((float)$l['unit_price_idr'] > 0) $hasPrice = true;
$hasPrice = $hasPrice && $showPrice;

$phone = '';
if (!empty($po['phone'])) {
    $dec = decryptData($po['phone']);
    if ($dec !== null) $phone = $dec;
    elseif (preg_match('/^[0-9+()\s.-]{6,}$/', $po['phone'])) $phone = $po['phone'];
}
function spkTgl($d)
{
    if (!$d) return '-';
    $b = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $ts = strtotime($d);
    return (int)date('j', $ts) . ' ' . $b[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}
function spkQty($v) { $s = rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.'); return $s === '' ? '0' : $s; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Surat <?= e($docNo) ?></title>
<style>
  *{box-sizing:border-box}
  body{font-family:Arial,Helvetica,sans-serif;font-size:12.5px;color:#000;margin:0;background:#eee;line-height:1.45}
  .page{width:210mm;min-height:280mm;margin:12px auto;background:#fff;padding:14mm 16mm;box-shadow:0 0 8px rgba(0,0,0,.2)}
  .bar{max-width:210mm;margin:12px auto 0;display:flex;gap:8px}
  .bar button,.bar a{padding:8px 14px;border:0;border-radius:6px;background:#7a4b1e;color:#fff;font-size:13px;cursor:pointer;text-decoration:none}
  .head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:2px solid #000;padding-bottom:8px;margin-bottom:12px}
  .head h1{font-size:17px;margin:0 0 2px} .head small{display:block;color:#333}
  h2{font-size:15px;margin:0 0 10px;text-align:center;text-transform:uppercase;letter-spacing:.5px}
  table{width:100%;border-collapse:collapse}
  .meta td{padding:2px 4px;vertical-align:top} .meta td:first-child{width:105px;color:#333}
  .items{margin-top:10px} .items th,.items td{border:1px solid #000;padding:5px 7px;text-align:left;vertical-align:top}
  .items th{background:#f0f0f0} .num{text-align:right}
  .items img{width:90px;height:70px;object-fit:cover;border:1px solid #888}
  ol{margin:6px 0 0 18px;padding:0} li{margin-bottom:3px}
  .sign{display:flex;gap:10px;margin-top:26px} .sign div{flex:1;text-align:center;font-size:11.5px}
  .sign .line{height:62px;border-bottom:1px solid #000;margin-bottom:3px}
  .foot{margin-top:12px;color:#444;font-size:10px}
  @media print{body{background:#fff}.page{box-shadow:none;margin:0;width:auto;min-height:0;padding:0}.bar{display:none}@page{size:A4;margin:12mm}}
</style>
<?php require_once __DIR__ . '/theme.php'; wmsThemeEmit(); ?>
</head>
<body>
<div class="bar"><button onclick="window.print()">Cetak</button><a href="manage_po.php" onclick="if (history.length > 1) { history.back(); } else { window.close(); } return false;">Kembali</a></div>
<div class="page">
  <div class="head">
    <div><h1>PT. DEKOR ASIA JAYAKARYA</h1><small>Warehouse Management System</small></div>
    <div style="text-align:right"><?= code128Svg($docNo, 40, 1) ?><small><?= e($docNo) ?></small></div>
  </div>
  <h2>Surat Perintah Kirim Barang (SPK)</h2>
  <table class="meta">
    <tr><td>No. Surat</td><td>: <b><?= e($docNo) ?></b></td><td>Tanggal terbit</td><td>: <?= e(spkTgl($po['order_date'])) ?></td></tr>
    <tr><td>Ref. PO</td><td>: <?= e($po['po_number']) ?></td><td>Batas kirim</td><td>: <b><?= e(!empty($po['deliver_by']) ? spkTgl($po['deliver_by']) : 'secepatnya') ?></b></td></tr>
  </table>
  <p style="margin:14px 0 2px">Kepada Yth.<br><b><?= e($po['company_name']) ?></b><br>Up. <?= e($po['contact_person']) ?><?= $phone !== '' ? ' (Telp. ' . e($phone) . ')' : '' ?></p>
  <p style="margin:12px 0 0">Dengan hormat,<br>Bersama surat ini kami memerintahkan pengiriman barang berikut ke gudang PT. Dekor Asia Jayakarya
    <?= !empty($po['deliver_by']) ? 'paling lambat <b>' . e(spkTgl($po['deliver_by'])) . '</b>' : 'secepatnya' ?>. Foto pada kolom &ldquo;Foto acuan&rdquo; adalah contoh barang yang kami maksud;
    barang yang dikirim harus sesuai dengan foto, spesifikasi, dan jumlah di bawah ini.</p>
  <table class="items">
    <tr><th style="width:26px">No</th><th style="width:100px">Foto acuan</th><th>Kode / Nama barang</th><th class="num" style="width:90px">Jumlah</th><?php if ($hasPrice): ?><th class="num" style="width:100px">Harga satuan (Rp)</th><?php endif; ?></tr>
    <?php foreach ($lines as $n => $l): $ph = wmsValidRequestPath($l['ref_photo'] ?? ''); ?>
      <tr><td><?= $n + 1 ?></td>
        <td><?php if ($ph !== ''): ?><img src="<?= e($ph) ?>" alt="Foto acuan"><?php else: ?><small>-</small><?php endif; ?></td>
        <td><b><?= e($l['sku']) ?></b><br><?= e($l['item_name']) ?><?php if (!empty($l['spec'])): ?><br><small>Spesifikasi: <?= e($l['spec']) ?></small><?php endif; ?></td>
        <td class="num"><b><?= e(spkQty($l['qty_ordered'])) ?></b> <?= e($l['unit_code']) ?></td>
        <?php if ($hasPrice): ?><td class="num"><?= e(number_format((float)$l['unit_price_idr'], 0, ',', '.')) ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
  </table>
  <p style="margin:14px 0 0"><b>Ketentuan pengiriman</b></p>
  <ol>
    <li>Bawa surat SPK ini beserta surat jalan supplier saat menyerahkan barang kepada petugas gudang.</li>
    <li>Saat barang tiba, petugas gudang memotret barang. Sistem mencatat otomatis hari, tanggal, tahun, jam, menit, dan detik kedatangan pada foto tersebut sebagai bukti serah terima.</li>
    <li>Barang yang tidak sesuai foto, spesifikasi, atau jumlah dapat ditolak atau ditahan di Karantina QC.</li>
    <li>Bahan baku kayu: sertakan dokumen SVLK dan pastikan kadar air (MC) tidak lebih dari 12%.</li>
  </ol>
  <?php if (!empty($po['spk_note'])): ?><p style="margin:10px 0 0"><b>Catatan:</b> <?= e($po['spk_note']) ?></p><?php endif; ?>
  <p style="margin:12px 0 0">Demikian surat ini kami sampaikan. Atas perhatian dan kerja samanya kami ucapkan terima kasih.</p>
  <div class="sign">
    <div><div class="line"></div>Dibuat oleh (Purchasing)<br><b><?= e($po['creator']) ?></b></div>
    <div><div class="line"></div>Mengetahui<br>Kepala Gudang<br>(........................)</div>
    <div><div class="line"></div>Diterima supplier<br>Nama / tanggal / cap<br>(........................)</div>
  </div>
  <div class="foot">Dicetak <?= e(date('Y-m-d H:i')) ?> oleh <?= e($_SESSION['full_name'] ?? $_SESSION['username'] ?? '') ?>. Dokumen dihasilkan sistem; keaslian dapat diperiksa dengan nomor surat di menu Purchase Order.</div>
</div>
</body>
</html>
