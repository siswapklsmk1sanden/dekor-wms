<?php
/**
 * Buku Besar Mutasi (baca saja, append-only): DEBIT (+) dan KREDIT (-).
 * Harga/nilai hanya tampil untuk peran yang hide_price = 0.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/layout.php';
requirePermission('catalog', 'view');
$showCost = canSeePrice('catalog');

$q     = trim($_GET['q'] ?? '');
$type  = trim($_GET['type'] ?? '');
$from  = trim($_GET['from'] ?? '');
$to    = trim($_GET['to'] ?? '');
$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 50;
$types = ['INBOUND_PURCHASE', 'OUTBOUND_PRODUCTION', 'PRODUCTION_OUTPUT', 'OUTBOUND_EXPORT_SALE',
          'INTERNAL_BIN_TRANSFER', 'OPNAME_ADJUSTMENT', 'SCRAP_WRITE_OFF'];
if (!in_array($type, $types, true)) $type = '';
$dateOk = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
if ($from !== '' && !$dateOk($from)) $from = '';
if ($to !== '' && !$dateOk($to)) $to = '';

$like = '%' . $q . '%';
$fromD = $from !== '' ? $from . ' 00:00:00' : '';
$toD   = $to !== '' ? $to . ' 23:59:59' : '';
$where = "WHERE (? = '' OR i.sku LIKE ? OR i.item_name LIKE ? OR t.trx_code LIKE ? OR b.batch_number LIKE ? OR bn.bin_code LIKE ?)
          AND (? = '' OR t.trx_type = ?)
          AND (? = '' OR l.recorded_at >= ?)
          AND (? = '' OR l.recorded_at <= ?)";
$join = "FROM inventory_ledger_entries l
    JOIN inventory_transactions t ON t.id = l.transaction_id
    JOIN items i ON i.id = l.item_id JOIN units u ON u.id = i.unit_id
    JOIN item_batches b ON b.id = l.batch_id
    JOIN storage_bins bn ON bn.id = l.bin_id";
$bindT = 'ssssssssssss';
$bindV = [$q, $like, $like, $like, $like, $like, $type, $type, $fromD, $fromD, $toD, $toD];

$st = mysqli_prepare($connect, "SELECT COUNT(*) $join $where");
mysqli_stmt_bind_param($st, $bindT, ...$bindV);
mysqli_stmt_execute($st);
$total = (int)mysqli_fetch_row(mysqli_stmt_get_result($st))[0];
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);
$off   = ($page - 1) * $per;

$isExport = ($_GET['export'] ?? '') === 'csv';
$limitSql = $isExport ? 'LIMIT 50000' : "LIMIT $per OFFSET $off";
$st = mysqli_prepare($connect, "SELECT l.id, l.recorded_at, t.trx_code, t.trx_type, i.sku, i.item_name, u.unit_code,
        b.batch_number, bn.bin_code, l.entry_direction, l.signed_qty, l.balance_after_qty, l.unit_cost_idr
    $join $where ORDER BY l.id DESC $limitSql");
mysqli_stmt_bind_param($st, $bindT, ...$bindV);
mysqli_stmt_execute($st);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

if ($isExport) {
    $o = [];
    foreach ($rows as $r) {
        $line = [$r['recorded_at'], $r['trx_code'], $r['trx_type'], $r['sku'], $r['item_name'], $r['batch_number'], $r['bin_code'],
                 $r['entry_direction'], fmtQ($r['signed_qty']), fmtQ($r['balance_after_qty']), $r['unit_code']];
        if ($showCost) { $line[] = $r['unit_cost_idr']; $line[] = round(abs((float)$r['signed_qty']) * (float)$r['unit_cost_idr'], 2); }
        $o[] = $line;
    }
    $h = ['Waktu', 'No. Transaksi', 'Jenis', 'SKU', 'Barang', 'Lot', 'Rak', 'Arah', 'Jumlah', 'Saldo rak', 'Satuan'];
    if ($showCost) { $h[] = 'Harga satuan'; $h[] = 'Nilai'; }
    csvOut('buku_besar_' . date('Ymd_His') . '.csv', $h, $o);
}
$qs = fn($p) => http_build_query(['q' => $q, 'type' => $type, 'from' => $from, 'to' => $to, 'page' => $p]);

renderHeader('Buku Besar');
?>
<h2>Buku Besar Mutasi Stok</h2>
<div class="panel">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
    <div><label>Cari</label><input type="text" name="q" value="<?= e($q) ?>" placeholder="SKU, no. transaksi, lot, rak"></div>
    <div><label>Jenis</label><select name="type"><option value="">Semua</option>
      <?php foreach ($types as $t): ?><option value="<?= e($t) ?>" <?= $t === $type ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select></div>
    <div><label>Dari</label><input type="date" name="from" value="<?= e($from) ?>" style="padding:8px;border:1px solid #ccc;border-radius:6px"></div>
    <div><label>Sampai</label><input type="date" name="to" value="<?= e($to) ?>" style="padding:8px;border:1px solid #ccc;border-radius:6px"></div>
    <button type="submit">Filter</button>
    <a class="btn sec" href="manage_ledger.php">Reset</a>
    <a class="btn sec" href="?<?= e(http_build_query(['q' => $q, 'type' => $type, 'from' => $from, 'to' => $to, 'export' => 'csv'])) ?>">Ekspor Excel</a>
  </form>
</div>
<div class="panel scroll">
  <small><?= (int)$total ?> baris jurnal. Jurnal bersifat append-only: tidak bisa diubah atau dihapus.</small>
  <table>
    <tr><th>Waktu</th><th>No. Transaksi</th><th>Jenis</th><th>Barang</th><th>Lot</th><th>Rak</th><th>D/K</th><th>Jumlah</th><th>Saldo rak</th><?php if ($showCost): ?><th>Harga</th><th>Nilai</th><?php endif; ?></tr>
    <?php if (!$rows): ?><tr><td colspan="<?= $showCost ? 11 : 9 ?>">Tidak ada data.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $in = $r['entry_direction'] === 'DEBIT_IN'; ?>
      <tr>
        <td><?= e($r['recorded_at']) ?></td><td><a href="print_trx.php?code=<?= urlencode($r['trx_code']) ?>" target="_blank" title="Cetak bukti"><?= e($r['trx_code']) ?></a></td><td><small><?= e($r['trx_type']) ?></small></td>
        <td><?= e($r['sku']) ?></td><td><?= e($r['batch_number']) ?></td><td><?= e($r['bin_code']) ?></td>
        <td><span class="tag <?= $in ? '' : 'off' ?>"><?= $in ? 'DEBIT' : 'KREDIT' ?></span></td>
        <td style="color:<?= $in ? '#1e6b34' : '#b3261e' ?>"><b><?= $in ? '+' : '' ?><?= e(fmtQ($r['signed_qty'])) ?></b> <?= e($r['unit_code']) ?></td>
        <td><?= e(fmtQ($r['balance_after_qty'])) ?></td>
        <?php if ($showCost): ?><td><?= e(number_format((float)$r['unit_cost_idr'], 2, ',', '.')) ?></td>
        <td><?= e(number_format(abs((float)$r['signed_qty']) * (float)$r['unit_cost_idr'], 2, ',', '.')) ?></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
  </table>
  <p style="display:flex;gap:8px;align-items:center">
    <?php if ($page > 1): ?><a class="btn sec" href="?<?= e($qs($page - 1)) ?>">&laquo; Sebelumnya</a><?php endif; ?>
    <span>Hal. <?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn sec" href="?<?= e($qs($page + 1)) ?>">Berikutnya &raquo;</a><?php endif; ?>
  </p>
</div>
<?php
function fmtQ($v) { $s = rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.'); return $s === '' ? '0' : $s; }
renderFooter();
