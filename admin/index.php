<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/layout.php';
requireLogin();
require_once __DIR__ . '/reservation_helper.php';
wmsAutoMaintenance();

function scalar($sql) {
    global $connect;
    $rs = mysqli_query($connect, $sql);
    $r = $rs ? mysqli_fetch_row($rs) : null;
    return $r ? $r[0] : 0;
}
function rows($sql) {
    global $connect;
    $rs = mysqli_query($connect, $sql);
    return $rs ? mysqli_fetch_all($rs, MYSQLI_ASSOC) : [];
}
function q($v) { $s = rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.'); return $s === '' ? '0' : $s; }

$showValue = canSeePrice('catalog');
$canPo = canAccess('inbound', 'create');
$totalItems = scalar("SELECT COUNT(*) FROM items WHERE is_active = 1");
$totalBins  = scalar("SELECT COUNT(*) FROM storage_bins WHERE is_active = 1");
$trxToday   = scalar("SELECT COUNT(*) FROM inventory_transactions WHERE DATE(created_at) = CURDATE()");
$holdLots   = scalar("SELECT COUNT(DISTINCT sb.batch_id) FROM stock_balances sb JOIN item_batches b ON b.id = sb.batch_id
                      WHERE sb.qty_on_hand > 0 AND b.qc_status = 'HOLD_QUARANTINE'");
$frozen     = scalar("SELECT COUNT(*) FROM storage_bins WHERE is_frozen_for_opname = 1");
$value      = $showValue ? scalar("SELECT COALESCE(SUM(sb.qty_on_hand * b.unit_cost_idr),0) FROM stock_balances sb
                      JOIN item_batches b ON b.id = sb.batch_id") : 0;

// Stok minimum: hanya stok yang bisa dipakai (QC lolos, belum kedaluwarsa)
$low = rows("SELECT i.id, i.sku, i.item_name, i.reorder_point, i.min_safety_stock,
        COALESCE(SUM(CASE WHEN b.qc_status = 'PASSED' AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
                          THEN sb.qty_on_hand - sb.qty_reserved END), 0) AS qty
        FROM items i LEFT JOIN stock_balances sb ON sb.item_id = i.id LEFT JOIN item_batches b ON b.id = sb.batch_id
        WHERE i.is_active = 1 GROUP BY i.id HAVING qty <= i.reorder_point ORDER BY qty ASC");

// Lot kedaluwarsa / hampir kedaluwarsa (90 hari) yang masih ada stoknya
$expiring = rows("SELECT i.sku, i.item_name, b.batch_number, b.expiry_date, DATEDIFF(b.expiry_date, CURDATE()) AS days_left,
        SUM(sb.qty_on_hand) AS qty, u.unit_code
        FROM item_batches b JOIN stock_balances sb ON sb.batch_id = b.id JOIN items i ON i.id = b.item_id JOIN units u ON u.id = i.unit_id
        WHERE b.expiry_date IS NOT NULL AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
        GROUP BY b.id HAVING qty > 0 ORDER BY b.expiry_date ASC LIMIT 15");

// Keterisian rak
$bins = rows("SELECT b.bin_code, b.zone_name, b.max_capacity_cbm, b.is_frozen_for_opname,
        COALESCE(SUM(sb.qty_on_hand * i.unit_cbm), 0) AS used
        FROM storage_bins b LEFT JOIN stock_balances sb ON sb.bin_id = b.id LEFT JOIN items i ON i.id = sb.item_id
        WHERE b.is_active = 1 GROUP BY b.id ORDER BY b.bin_code");

$recent = rows("SELECT t.trx_code, t.trx_type, t.created_at, u.username, COUNT(l.id) AS n
        FROM inventory_transactions t JOIN users u ON u.id = t.created_by
        LEFT JOIN inventory_ledger_entries l ON l.transaction_id = t.id
        GROUP BY t.id ORDER BY t.id DESC LIMIT 8");

// Tren 14 hari: jumlah transaksi tercatat (COMMITTED) masuk vs keluar per hari.
// Transaksi koreksi "REV:" tidak dihitung (transaksi asalnya sudah berstatus CANCELLED).
$trendDays  = 14;
$trendStart = date('Y-m-d', strtotime('-' . ($trendDays - 1) . ' day'));
$trend = [];
for ($i = $trendDays - 1; $i >= 0; $i--) {
    $trend[date('Y-m-d', strtotime("-$i day"))] = ['in' => 0, 'out' => 0];
}
foreach (rows("SELECT DATE(created_at) AS d,
        SUM(trx_type IN ('INBOUND_PURCHASE','PRODUCTION_OUTPUT')) AS n_in,
        SUM(trx_type IN ('OUTBOUND_PRODUCTION','OUTBOUND_EXPORT_SALE')) AS n_out
        FROM inventory_transactions
        WHERE trx_status = 'COMMITTED' AND COALESCE(reference_doc_no, '') NOT LIKE 'REV:%'
          AND created_at >= '" . $trendStart . " 00:00:00'
        GROUP BY DATE(created_at)") as $r) {
    if (isset($trend[$r['d']])) {
        $trend[$r['d']] = ['in' => (int)$r['n_in'], 'out' => (int)$r['n_out']];
    }
}
$tMax = 1;
foreach ($trend as $t) { $tMax = max($tMax, $t['in'], $t['out']); }
$tTop   = max(4, (int)ceil($tMax / 4) * 4);
$tSumIn = array_sum(array_column($trend, 'in'));
$tSumOut = array_sum(array_column($trend, 'out'));
$cW = 720; $cH = 240; $cL = 34; $cR = 8; $cT = 12; $cB = 34;
$cPw = $cW - $cL - $cR; $cPh = $cH - $cT - $cB;
$cSlot = $cPw / $trendDays; $cBw = min(16, $cSlot * 0.34);
$nf = fn($v) => number_format((float)$v, 1, '.', '');

renderHeader('Dashboard');
?>
<h2>Dashboard</h2>
<style>
  .cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;margin-bottom:22px}
  .cards>a{display:block;text-decoration:none;color:inherit}
  .card{background:#fff;padding:16px;border-radius:14px;min-width:0;height:100%;display:flex;flex-direction:column;justify-content:center;gap:2px;font-size:13.5px;color:#5d6368;box-shadow:0 2px 14px rgba(15,23,42,.07);border-top:3px solid var(--pri)}
  .card b{display:block;font-size:28px;line-height:1.2;font-family:Outfit,Inter,Arial,sans-serif;font-weight:600;color:var(--ink)}
  .card.warn b{color:#b3261e}
  .card b.val{font-size:18px;white-space:nowrap}
  .two{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;align-items:stretch}
  .two .panel{margin-bottom:0}
  .two .wide{grid-column:1/-1}
  @media(max-width:1000px){.two{grid-template-columns:minmax(0,1fr)}}
  .cg{stroke:#e4e4e4}
  .ct{fill:#5d6368}
  html[data-theme=dark] .cg{stroke:rgba(148,163,184,.18)}
  html[data-theme=dark] .ct{fill:#94a3b8}
  .bar{background:#eee;border-radius:6px;height:10px;overflow:hidden;min-width:120px}
  .bar i{display:block;height:100%;background:var(--pri-d)}
  .bar i.hi{background:#b3261e}
</style>
<div class="cards">
  <div class="card"><b><?= (int)$totalItems ?></b>Item aktif</div>
  <div class="card"><b><?= (int)$totalBins ?></b>Rak aktif</div>
  <div class="card"><b><?= (int)$trxToday ?></b>Transaksi hari ini</div>
  <a href="manage_qc.php"><div class="card <?= $holdLots ? 'warn' : '' ?>"><b><?= (int)$holdLots ?></b>Lot ditahan QC &rsaquo;</div></a>
  <div class="card <?= $frozen ? 'warn' : '' ?>"><b><?= (int)$frozen ?></b>Rak dibekukan (opname)</div>
  <?php if ($showValue): ?><div class="card"><b class="val">Rp <?= e(number_format((float)$value, 0, ',', '.')) ?></b>Nilai persediaan</div><?php endif; ?>
</div>

<div class="two">
<div class="panel scroll wide">
  <strong>Tren transaksi <?= (int)$trendDays ?> hari terakhir</strong>
  <svg viewBox="0 0 <?= $cW ?> <?= $cH ?>" role="img" aria-label="Grafik batang jumlah transaksi masuk dan keluar per hari" style="width:100%;min-width:560px;height:auto;display:block;margin-top:8px">
    <?php for ($k = 0; $k <= 4; $k++): $val = $tTop / 4 * $k; $y = $cT + $cPh - $cPh * $k / 4; ?>
      <line x1="<?= $cL ?>" x2="<?= $cW - $cR ?>" y1="<?= $nf($y) ?>" y2="<?= $nf($y) ?>" class="cg" stroke-width="1"/>
      <text x="<?= $cL - 6 ?>" y="<?= $nf($y + 3.5) ?>" text-anchor="end" class="ct" font-size="10"><?= e(rtrim(rtrim(number_format($val, 1, '.', ''), '0'), '.')) ?></text>
    <?php endfor; ?>
    <?php $idx = 0; foreach ($trend as $d => $t):
        $x0 = $cL + $cSlot * $idx + ($cSlot - 2 * $cBw - 2) / 2;
        $hIn = $cPh * $t['in'] / $tTop; $hOut = $cPh * $t['out'] / $tTop;
        $lbl = date('d/m', strtotime($d)); ?>
      <g>
        <title><?= e($lbl . ' - Masuk: ' . $t['in'] . ', Keluar: ' . $t['out']) ?></title>
        <?php if ($t['in'] > 0): ?><rect x="<?= $nf($x0) ?>" y="<?= $nf($cT + $cPh - $hIn) ?>" width="<?= $nf($cBw) ?>" height="<?= $nf($hIn) ?>" rx="2" fill="var(--pri-d)"/><?php endif; ?>
        <?php if ($t['out'] > 0): ?><rect x="<?= $nf($x0 + $cBw + 2) ?>" y="<?= $nf($cT + $cPh - $hOut) ?>" width="<?= $nf($cBw) ?>" height="<?= $nf($hOut) ?>" rx="2" fill="#2b5d8a"/><?php endif; ?>
        <text x="<?= $nf($cL + $cSlot * $idx + $cSlot / 2) ?>" y="<?= $cH - 14 ?>" text-anchor="middle" class="ct" font-size="10"><?= e($lbl) ?></text>
      </g>
    <?php $idx++; endforeach; ?>
  </svg>
  <div class="panel-foot">
    <small>
      <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--pri-d);vertical-align:-1px"></span> Masuk: <?= (int)$tSumIn ?> &nbsp;
      <span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:#2b5d8a;vertical-align:-1px"></span> Keluar: <?= (int)$tSumOut ?>
      &mdash; jumlah transaksi per hari. Masuk = pembelian + hasil produksi; Keluar = pengeluaran produksi + ekspor. Tidak termasuk transfer rak, opname, scrap, dan transaksi yang dibatalkan.
    </small>
  </div>
</div>

<div class="panel scroll wide">
  <strong>Peringatan stok minimum</strong>
  <table>
    <tr><th>SKU</th><th>Nama</th><th class="num">Stok pakai</th><th class="num">Reorder</th><?php if ($canPo): ?><th class="act"></th><?php endif; ?></tr>
    <?php if ($low): foreach ($low as $r): $crit = (float)$r['qty'] <= (float)$r['min_safety_stock']; ?>
      <tr><td class="nw"><?= e($r['sku']) ?></td><td><?= e($r['item_name']) ?></td>
        <td class="num"><b style="color:<?= $crit ? '#b3261e' : 'inherit' ?>"><?= e(q($r['qty'])) ?></b></td><td class="num"><?= e(q($r['reorder_point'])) ?></td>
        <?php if ($canPo): ?><td class="act"><a class="btn sec sm" href="manage_po.php?suggest=1&amp;item=<?= (int)$r['id'] ?>">Buat PO</a></td><?php endif; ?></tr>
    <?php endforeach; else: ?><tr><td colspan="<?= $canPo ? 5 : 4 ?>">Semua stok di atas reorder point.</td></tr><?php endif; ?>
  </table>
  <div class="panel-foot">
    <small>Hanya stok QC lolos dan belum kedaluwarsa. Merah = di bawah safety stock.</small>
    <?php if ($canPo && $low): ?><a class="btn" href="manage_po.php?suggest=1">Buat PO untuk semua barang di atas</a><?php endif; ?>
  </div>
</div>

<div class="panel scroll">
  <strong>Kedaluwarsa dalam 90 hari</strong>
  <table>
    <tr><th>Barang</th><th>Lot</th><th>Kedaluwarsa</th><th class="num">Stok</th></tr>
    <?php if ($expiring): foreach ($expiring as $r): $d = (int)$r['days_left']; ?>
      <tr><td class="nw"><?= e($r['sku']) ?></td><td class="nw"><?= e($r['batch_number']) ?></td>
        <td class="nw"><?= e($r['expiry_date']) ?> <span class="tag <?= $d < 0 ? 'off' : '' ?>" style="<?= $d < 0 ? 'background:#fdecea;color:#b3261e' : '' ?>"><?= $d < 0 ? 'lewat ' . abs($d) . ' hr' : $d . ' hr' ?></span></td>
        <td class="num"><?= e(q($r['qty'])) ?> <?= e($r['unit_code']) ?></td></tr>
    <?php endforeach; else: ?><tr><td colspan="4">Tidak ada lot yang mendekati kedaluwarsa.</td></tr><?php endif; ?>
  </table>
</div>

<div class="panel scroll">
  <strong>Transaksi terakhir</strong>
  <table>
    <tr><th>Waktu</th><th>No.</th><th>Jenis</th><th>Oleh</th></tr>
    <?php if (!$recent): ?><tr><td colspan="4">Belum ada transaksi.</td></tr><?php endif; ?>
    <?php foreach ($recent as $r): ?>
      <tr><td class="nw"><?= e($r['created_at']) ?></td><td class="nw"><?= e($r['trx_code']) ?></td><td><small><?= e($r['trx_type']) ?></small></td><td><?= e($r['username']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="panel scroll wide">
  <strong>Keterisian rak (CBM)</strong>
  <table>
    <tr><th>Rak</th><th>Zona</th><th>Terisi</th><th></th></tr>
    <?php foreach ($bins as $b): $max = (float)$b['max_capacity_cbm']; $pct = $max > 0 ? min(100, (float)$b['used'] / $max * 100) : 0; ?>
      <tr><td class="nw"><?= e($b['bin_code']) ?><?= (int)$b['is_frozen_for_opname'] ? ' <span class="tag off">beku</span>' : '' ?></td><td><?= e($b['zone_name']) ?></td>
        <td class="nw"><?= e(number_format((float)$b['used'], 2, ',', '.')) ?> / <?= e(number_format($max, 0, ',', '.')) ?> (<?= round($pct) ?>%)</td>
        <td><div class="bar"><i class="<?= $pct >= 90 ? 'hi' : '' ?>" style="width:<?= round($pct) ?>%"></i></div></td></tr>
    <?php endforeach; ?>
  </table>
</div>
</div>
<?php renderFooter();