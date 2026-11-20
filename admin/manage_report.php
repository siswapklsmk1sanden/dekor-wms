<?php
/**
 * Laporan & Ekspor (baca saja). Semua angka dibaca dari Buku Besar (inventory_ledger_entries),
 * Purchase Order, dan Saldo Stok, sehingga selalu sama dengan menu Saldo Stok / Buku Besar.
 * Laporan : kartu (kartu stok), masuk (rekap barang masuk per supplier), keluar (rekap barang keluar), po (daftar PO).
 * Keluaran: tampilan layar, ekspor CSV (dibuka di Excel), dan cetak A4 (Ctrl+P > Simpan sebagai PDF).
 * Hak akses : sama dengan Buku Besar (modul 'catalog'); harga/nilai hanya untuk peran hide_price = 0.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/layout.php';
requirePermission('catalog', 'view');
$showCost = canSeePrice('catalog');

const RPT_ROW_LIMIT = 5000;

$reports = [
    'kartu'  => 'Kartu Stok',
    'masuk'  => 'Barang Masuk per Supplier',
    'keluar' => 'Barang Keluar',
    'po'     => 'Daftar Purchase Order',
];
$r = $_GET['r'] ?? 'kartu';
if (!isset($reports[$r])) $r = 'kartu';

$dateOk = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$from = trim($_GET['from'] ?? '');
$to   = trim($_GET['to'] ?? '');
if (!isset($_GET['from']) && !isset($_GET['to']) && $r !== 'kartu') {   // bawaan: bulan berjalan
    $from = date('Y-m-01');
    $to   = date('Y-m-d');
}
if ($from !== '' && !$dateOk($from)) $from = '';
if ($to !== '' && !$dateOk($to)) $to = '';
$fromD = $from !== '' ? $from . ' 00:00:00' : '';
$toD   = $to !== '' ? $to . ' 23:59:59' : '';
$itemId = (int)($_GET['item'] ?? 0);
$supId  = (int)($_GET['supplier'] ?? 0);
$poSt   = $_GET['status'] ?? '';
if (!in_array($poSt, ['OPEN', 'PARTIAL', 'CLOSED', 'CANCELLED'], true)) $poSt = '';

$mode = ($_GET['export'] ?? '') === 'csv' ? 'csv' : ((($_GET['print'] ?? '') === '1') ? 'print' : 'html');
$limit = $mode === 'html' ? RPT_ROW_LIMIT : 50000;

function rq($v) { $s = rtrim(rtrim(number_format((float)$v, 4, '.', ''), '0'), '.'); return $s === '' ? '0' : $s; }

/**
 * Hasil tiap laporan memakai bentuk yang sama:
 * cols = [[judul, tipe]] dengan tipe: s teks, q jumlah, m rupiah, n bilangan bulat.
 */
$rep = ['title' => $reports[$r], 'sub' => [], 'cols' => [], 'rows' => [], 'foot' => null, 'note' => '', 'truncated' => false];
$period = ($from !== '' || $to !== '') ? ('Periode ' . ($from ?: 'awal') . ' s/d ' . ($to ?: 'sekarang')) : 'Semua periode';

$items = mysqli_fetch_all(mysqli_query($connect, "SELECT id, sku, item_name FROM items ORDER BY sku"), MYSQLI_ASSOC);
$suppliers = mysqli_fetch_all(mysqli_query($connect, "SELECT id, supplier_code, company_name FROM suppliers ORDER BY company_name"), MYSQLI_ASSOC);

// ---------------------------------------------------------------- KARTU STOK
if ($r === 'kartu') {
    $item = null;
    foreach ($items as $it) if ((int)$it['id'] === $itemId) $item = $it;
    if ($item) {
        $st = mysqli_prepare($connect, "SELECT u.unit_code FROM items i JOIN units u ON u.id = i.unit_id WHERE i.id = ?");
        mysqli_stmt_bind_param($st, 'i', $itemId);
        mysqli_stmt_execute($st);
        $unit = (string)(mysqli_fetch_row(mysqli_stmt_get_result($st))[0] ?? '');

        // Saldo awal = jumlah semua mutasi sebelum tanggal "dari"
        $open = 0.0;
        if ($fromD !== '') {
            $st = mysqli_prepare($connect, "SELECT COALESCE(SUM(signed_qty),0) FROM inventory_ledger_entries WHERE item_id = ? AND recorded_at < ?");
            mysqli_stmt_bind_param($st, 'is', $itemId, $fromD);
            mysqli_stmt_execute($st);
            $open = (float)mysqli_fetch_row(mysqli_stmt_get_result($st))[0];
        }
        $st = mysqli_prepare($connect, "SELECT l.recorded_at, t.trx_code, t.trx_type, t.reference_doc_no, b.batch_number, bn.bin_code,
                l.entry_direction, l.signed_qty, l.unit_cost_idr
            FROM inventory_ledger_entries l
            JOIN inventory_transactions t ON t.id = l.transaction_id
            JOIN item_batches b ON b.id = l.batch_id JOIN storage_bins bn ON bn.id = l.bin_id
            WHERE l.item_id = ? AND (? = '' OR l.recorded_at >= ?) AND (? = '' OR l.recorded_at <= ?)
            ORDER BY l.recorded_at, l.id LIMIT " . (int)$limit);
        mysqli_stmt_bind_param($st, 'issss', $itemId, $fromD, $fromD, $toD, $toD);
        mysqli_stmt_execute($st);
        $lines = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

        $rep['title'] = 'Kartu Stok: ' . $item['sku'] . ' - ' . $item['item_name'];
        $rep['cols'] = [['Waktu', 's'], ['No. Transaksi', 's'], ['Jenis', 's'], ['Referensi', 's'], ['Lot', 's'], ['Rak', 's'],
                        ['Masuk', 'q'], ['Keluar', 'q'], ['Saldo', 'q']];
        $rep['rows'][] = ['Saldo awal', '', '', '', '', '', '', '', $open];
        $bal = $open; $sumIn = 0.0; $sumOut = 0.0;
        foreach ($lines as $l) {
            $q = (float)$l['signed_qty'];
            $bal += $q;
            if ($q >= 0) $sumIn += $q; else $sumOut += -$q;
            $rep['rows'][] = [$l['recorded_at'], $l['trx_code'], $l['trx_type'], (string)$l['reference_doc_no'], $l['batch_number'], $l['bin_code'],
                              $q >= 0 ? $q : '', $q < 0 ? -$q : '', $bal];
        }
        $rep['truncated'] = count($lines) >= $limit;
        $rep['foot'] = ['Total mutasi / saldo akhir', '', '', '', '', '', $sumIn, $sumOut, $bal];
        $rep['sub'][] = 'Satuan: ' . $unit . '. ' . $period . '.';

        // Pemeriksaan sinkron dengan Saldo Stok (hanya bermakna bila tidak dibatasi tanggal akhir)
        if ($toD === '' && !$rep['truncated']) {
            $st = mysqli_prepare($connect, "SELECT COALESCE(SUM(qty_on_hand),0) FROM stock_balances WHERE item_id = ?");
            mysqli_stmt_bind_param($st, 'i', $itemId);
            mysqli_stmt_execute($st);
            $sb = (float)mysqli_fetch_row(mysqli_stmt_get_result($st))[0];
            $rep['note'] = abs($sb - $bal) < 0.00005
                ? 'Sinkron: saldo akhir kartu stok sama dengan Saldo Stok saat ini (' . rq($sb) . ' ' . $unit . ').'
                : 'PERHATIAN: saldo akhir kartu (' . rq($bal) . ') berbeda dengan Saldo Stok (' . rq($sb) . ' ' . $unit . '). Periksa Buku Besar.';
        }
    } else {
        $rep['note'] = 'Pilih barang terlebih dahulu.';
        $rep['cols'] = [['Waktu', 's']];
    }
}

// ---------------------------------------------------------------- BARANG MASUK PER SUPPLIER
if ($r === 'masuk') {
    $st = mysqli_prepare($connect, "SELECT COALESCE(s.company_name, '(tanpa supplier)') AS supplier, i.sku, i.item_name, u.unit_code,
            COUNT(DISTINCT l.batch_id) AS lots, SUM(l.signed_qty) AS qty, SUM(l.signed_qty * l.unit_cost_idr) AS nilai
        FROM inventory_ledger_entries l
        JOIN inventory_transactions t ON t.id = l.transaction_id
        JOIN items i ON i.id = l.item_id JOIN units u ON u.id = i.unit_id
        JOIN item_batches b ON b.id = l.batch_id LEFT JOIN suppliers s ON s.id = b.supplier_id
        WHERE l.entry_direction = 'DEBIT_IN' AND t.trx_type = 'INBOUND_PURCHASE' AND t.trx_status <> 'CANCELLED'
          AND (t.reference_doc_no IS NULL OR t.reference_doc_no NOT LIKE 'REV:%')
          AND (? = '' OR l.recorded_at >= ?) AND (? = '' OR l.recorded_at <= ?)
          AND (? = 0 OR b.supplier_id = ?) AND (? = 0 OR l.item_id = ?)
        GROUP BY s.id, s.company_name, i.id, i.sku, i.item_name, u.unit_code
        ORDER BY supplier, i.sku LIMIT " . (int)$limit);
    mysqli_stmt_bind_param($st, 'ssssiiii', $fromD, $fromD, $toD, $toD, $supId, $supId, $itemId, $itemId);
    mysqli_stmt_execute($st);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
    $rep['cols'] = [['Supplier', 's'], ['SKU', 's'], ['Barang', 's'], ['Jumlah lot', 'n'], ['Jumlah masuk', 'q'], ['Satuan', 's']];
    if ($showCost) $rep['cols'][] = ['Nilai (Rp)', 'm'];
    $totLot = 0; $totVal = 0.0;
    foreach ($rows as $x) {
        $line = [$x['supplier'], $x['sku'], $x['item_name'], (int)$x['lots'], (float)$x['qty'], $x['unit_code']];
        if ($showCost) $line[] = (float)$x['nilai'];
        $rep['rows'][] = $line;
        $totLot += (int)$x['lots']; $totVal += (float)$x['nilai'];
    }
    if ($showCost && $rows) $rep['foot'] = ['Total', '', '', $totLot, '', '', $totVal];
    $rep['truncated'] = count($rows) >= $limit;
    $rep['sub'][] = $period . '. Sumber: Buku Besar jenis INBOUND_PURCHASE (transaksi pembatalan tidak dihitung).';
}

// ---------------------------------------------------------------- BARANG KELUAR
if ($r === 'keluar') {
    $st = mysqli_prepare($connect, "SELECT (CASE WHEN t.trx_type = 'SCRAP_WRITE_OFF' AND t.reference_doc_no LIKE 'RTR-%' THEN 'SUPPLIER_RETURN' ELSE t.trx_type END) AS kind,
            i.sku, i.item_name, u.unit_code, COUNT(DISTINCT t.id) AS trx,
            SUM(-l.signed_qty) AS qty, SUM(-l.signed_qty * l.unit_cost_idr) AS nilai
        FROM inventory_ledger_entries l
        JOIN inventory_transactions t ON t.id = l.transaction_id
        JOIN items i ON i.id = l.item_id JOIN units u ON u.id = i.unit_id
        WHERE l.entry_direction = 'CREDIT_OUT' AND t.trx_type IN ('OUTBOUND_PRODUCTION','OUTBOUND_EXPORT_SALE','SCRAP_WRITE_OFF')
          AND t.trx_status <> 'CANCELLED' AND (t.reference_doc_no IS NULL OR t.reference_doc_no NOT LIKE 'REV:%')
          AND (? = '' OR l.recorded_at >= ?) AND (? = '' OR l.recorded_at <= ?) AND (? = 0 OR l.item_id = ?)
        GROUP BY kind, i.id, i.sku, i.item_name, u.unit_code
        ORDER BY kind, i.sku LIMIT " . (int)$limit);
    mysqli_stmt_bind_param($st, 'ssssii', $fromD, $fromD, $toD, $toD, $itemId, $itemId);
    mysqli_stmt_execute($st);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
    $labels = ['OUTBOUND_PRODUCTION' => 'Pengeluaran produksi', 'OUTBOUND_EXPORT_SALE' => 'Penjualan / ekspor', 'SCRAP_WRITE_OFF' => 'Scrap / penghapusan', 'SUPPLIER_RETURN' => 'Retur ke supplier'];
    $rep['cols'] = [['Jenis keluar', 's'], ['SKU', 's'], ['Barang', 's'], ['Jumlah transaksi', 'n'], ['Jumlah keluar', 'q'], ['Satuan', 's']];
    if ($showCost) $rep['cols'][] = ['Nilai (Rp)', 'm'];
    $totTrx = 0; $totVal = 0.0;
    foreach ($rows as $x) {
        $line = [$labels[$x['kind']] ?? $x['kind'], $x['sku'], $x['item_name'], (int)$x['trx'], (float)$x['qty'], $x['unit_code']];
        if ($showCost) $line[] = (float)$x['nilai'];
        $rep['rows'][] = $line;
        $totTrx += (int)$x['trx']; $totVal += (float)$x['nilai'];
    }
    if ($showCost && $rows) $rep['foot'] = ['Total', '', '', $totTrx, '', '', $totVal];
    $rep['truncated'] = count($rows) >= $limit;
    $rep['sub'][] = $period . '. Sumber: Buku Besar (sisi KREDIT; transaksi pembatalan tidak dihitung).';
}

// ---------------------------------------------------------------- DAFTAR PO
if ($r === 'po') {
    $st = mysqli_prepare($connect, "SELECT p.po_number, p.order_date, p.deliver_by, p.status, s.company_name,
            COUNT(pl.id) AS baris, COALESCE(SUM(pl.qty_ordered),0) AS q_order, COALESCE(SUM(pl.qty_received),0) AS q_recv,
            COALESCE(SUM(pl.qty_ordered * pl.unit_price_idr),0) AS v_order, COALESCE(SUM(pl.qty_received * pl.unit_price_idr),0) AS v_recv
        FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id
        LEFT JOIN purchase_order_lines pl ON pl.po_id = p.id
        WHERE (? = '' OR p.order_date >= ?) AND (? = '' OR p.order_date <= ?) AND (? = 0 OR p.supplier_id = ?) AND (? = '' OR p.status = ?)
        GROUP BY p.id, p.po_number, p.order_date, p.deliver_by, p.status, s.company_name
        ORDER BY p.order_date DESC, p.id DESC LIMIT " . (int)$limit);
    mysqli_stmt_bind_param($st, 'ssssiiss', $from, $from, $to, $to, $supId, $supId, $poSt, $poSt);
    mysqli_stmt_execute($st);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
    $rep['cols'] = [['No. PO', 's'], ['Tanggal', 's'], ['Batas kirim', 's'], ['Supplier', 's'], ['Status', 's'], ['Item', 'n'],
                    ['Qty dipesan', 'q'], ['Qty diterima', 'q'], ['% diterima', 'n']];
    if ($showCost) { $rep['cols'][] = ['Nilai PO (Rp)', 'm']; $rep['cols'][] = ['Nilai diterima (Rp)', 'm']; }
    $tv = 0.0; $tr = 0.0;
    foreach ($rows as $x) {
        $pct = (float)$x['q_order'] > 0 ? round((float)$x['q_recv'] / (float)$x['q_order'] * 100) : 0;
        $line = [$x['po_number'], $x['order_date'], (string)$x['deliver_by'], $x['company_name'], $x['status'], (int)$x['baris'],
                 (float)$x['q_order'], (float)$x['q_recv'], (int)$pct];
        if ($showCost) { $line[] = (float)$x['v_order']; $line[] = (float)$x['v_recv']; }
        $rep['rows'][] = $line;
        if ($x['status'] !== 'CANCELLED') { $tv += (float)$x['v_order']; $tr += (float)$x['v_recv']; }
    }
    if ($showCost && $rows) $rep['foot'] = ['Total (tanpa PO batal)', '', '', '', '', '', '', '', '', $tv, $tr];
    $rep['truncated'] = count($rows) >= $limit;
    $rep['sub'][] = $period . ' (menurut tanggal PO). Jumlah campuran satuan dijumlahkan apa adanya.';
}

// ---------------------------------------------------------------- FORMAT KELUARAN
$fmt = function ($v, $type) {
    if ($v === '' || $v === null) return '';
    if ($type === 'q') return rq($v);
    if ($type === 'm') return number_format((float)$v, 0, ',', '.');
    if ($type === 'n') return (string)(int)$v;
    return (string)$v;
};

if ($mode === 'csv') {
    $head = array_map(fn($c) => $c[0], $rep['cols']);
    $out = [];
    foreach ($rep['rows'] as $row) {
        $line = [];
        foreach ($row as $k => $v) {
            $t = $rep['cols'][$k][1] ?? 's';
            $line[] = ($t === 'q') ? ($v === '' ? '' : rq($v)) : (($t === 'm' && $v !== '') ? number_format((float)$v, 2, '.', '') : $v);
        }
        $out[] = $line;
    }
    if ($rep['foot']) {
        $line = [];
        foreach ($rep['foot'] as $k => $v) {
            $t = $rep['cols'][$k][1] ?? 's';
            $line[] = ($t === 'q') ? ($v === '' ? '' : rq($v)) : (($t === 'm' && $v !== '') ? number_format((float)$v, 2, '.', '') : $v);
        }
        $out[] = $line;
    }
    if ($rep['truncated']) $out[] = ['Data dipotong di ' . $limit . ' baris. Persempit periode.'];
    csvOut('laporan_' . $r . '_' . date('Ymd_His') . '.csv', $head, $out);
}

$qsBase = ['r' => $r, 'from' => $from, 'to' => $to, 'item' => $itemId ?: '', 'supplier' => $supId ?: '', 'status' => $poSt];
$numTypes = ['q' => 1, 'm' => 1, 'n' => 1];

// Tabel dipakai bersama oleh tampilan layar dan cetak
$renderTable = function () use ($rep, $fmt, $numTypes) {
    ?>
    <table class="rt">
      <tr><?php foreach ($rep['cols'] as $c): ?><th class="<?= isset($numTypes[$c[1]]) ? 'num' : '' ?>"><?= e($c[0]) ?></th><?php endforeach; ?></tr>
      <?php if (!$rep['rows']): ?><tr><td colspan="<?= max(1, count($rep['cols'])) ?>">Tidak ada data.</td></tr><?php endif; ?>
      <?php foreach ($rep['rows'] as $row): ?>
        <tr><?php foreach ($row as $k => $v): $t = $rep['cols'][$k][1] ?? 's'; ?>
          <td class="<?= isset($numTypes[$t]) ? 'num' : '' ?>"><?= e($fmt($v, $t)) ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      <?php if ($rep['foot']): ?>
        <tr class="tot"><?php foreach ($rep['foot'] as $k => $v): $t = $rep['cols'][$k][1] ?? 's'; ?>
          <td class="<?= isset($numTypes[$t]) ? 'num' : '' ?>"><b><?= e($fmt($v, $t)) ?></b></td><?php endforeach; ?></tr>
      <?php endif; ?>
    </table>
    <?php
};

// ---------------------------------------------------------------- CETAK / PDF
if ($mode === 'print') {
    ?><!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($rep['title']) ?></title>
<style>
  *{box-sizing:border-box}
  body{font-family:Arial,Helvetica,sans-serif;font-size:11px;color:#000;margin:0;background:#eee}
  .page{width:297mm;min-height:200mm;margin:12px auto;background:#fff;padding:10mm 12mm;box-shadow:0 0 8px rgba(0,0,0,.2)}
  .bar{max-width:297mm;margin:12px auto 0;display:flex;gap:8px;align-items:center}
  .bar button,.bar a{padding:8px 14px;border:0;border-radius:6px;background:#7a4b1e;color:#fff;font-size:13px;cursor:pointer;text-decoration:none}
  .bar span{color:#555;font-size:12px}
  .head{border-bottom:2px solid #000;padding-bottom:6px;margin-bottom:8px}
  .head h1{font-size:15px;margin:0} .head small{color:#333}
  h2{font-size:14px;margin:0 0 4px} p{margin:2px 0}
  .rt{width:100%;border-collapse:collapse;margin-top:8px}
  .rt th,.rt td{border:1px solid #000;padding:4px 5px;text-align:left}
  .rt th{background:#f0f0f0} .num{text-align:right} .tot td{background:#f7f7f7}
  .foot{margin-top:10px;color:#444;font-size:10px}
  @media print{body{background:#fff}.page{box-shadow:none;margin:0;width:auto;min-height:0;padding:0}.bar{display:none}@page{size:A4 landscape;margin:10mm}}
</style><?php require_once __DIR__ . '/theme.php'; wmsThemeEmit(); ?></head><body>
<div class="bar"><button onclick="window.print()">Cetak / Simpan PDF</button>
  <a href="?<?= e(http_build_query($qsBase)) ?>">Kembali</a><span>Di jendela cetak, pilih printer "Save as PDF" / "Microsoft Print to PDF".</span></div>
<div class="page">
  <div class="head"><h1>PT. DEKOR ASIA JAYAKARYA</h1><small>Warehouse Management System</small></div>
  <h2><?= e($rep['title']) ?></h2>
  <?php foreach ($rep['sub'] as $s): ?><p><?= e($s) ?></p><?php endforeach; ?>
  <?php if ($rep['note'] !== ''): ?><p><b><?= e($rep['note']) ?></b></p><?php endif; ?>
  <?php $renderTable(); ?>
  <?php if ($rep['truncated']): ?><p><b>Data dipotong di <?= (int)$limit ?> baris. Persempit periode.</b></p><?php endif; ?>
  <div class="foot">Dicetak <?= e(date('Y-m-d H:i')) ?> oleh <?= e($_SESSION['full_name'] ?? $_SESSION['username'] ?? '') ?>. Laporan dihasilkan sistem dari Buku Besar, PO, dan Saldo Stok.</div>
</div>
</body></html><?php
    exit;
}

// ---------------------------------------------------------------- TAMPILAN LAYAR
renderHeader('Laporan');
$canFilterItem = in_array($r, ['kartu', 'masuk', 'keluar'], true);
?>
<h2>Laporan &amp; Ekspor</h2>
<style>
  .rtabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
  .rtabs a{padding:7px 12px;border-radius:6px;background:#eee;color:#333;text-decoration:none;font-size:13px}
  .rtabs a.on{background:#7a4b1e;color:#fff}
  .rt{width:100%;border-collapse:collapse}
  .rt th,.rt td{padding:6px 8px;border-bottom:1px solid #ddd;text-align:left}
  .rt .num{text-align:right} .rt .tot td{background:#f7f3ee}
</style>
<div class="rtabs">
  <?php foreach ($reports as $k => $label): ?>
    <a class="<?= $k === $r ? 'on' : '' ?>" href="?r=<?= e($k) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<div class="panel">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
    <input type="hidden" name="r" value="<?= e($r) ?>">
    <?php if ($canFilterItem): ?>
    <div><label>Barang<?= $r === 'kartu' ? ' (wajib)' : '' ?></label>
      <select name="item"><option value=""><?= $r === 'kartu' ? '- pilih barang -' : 'Semua' ?></option>
        <?php foreach ($items as $it): ?><option value="<?= (int)$it['id'] ?>" <?= (int)$it['id'] === $itemId ? 'selected' : '' ?>><?= e($it['sku'] . ' - ' . $it['item_name']) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <?php if ($r === 'masuk' || $r === 'po'): ?>
    <div><label>Supplier</label>
      <select name="supplier"><option value="">Semua</option>
        <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)$s['id'] === $supId ? 'selected' : '' ?>><?= e($s['company_name']) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <?php if ($r === 'po'): ?>
    <div><label>Status</label>
      <select name="status"><option value="">Semua</option>
        <?php foreach (['OPEN', 'PARTIAL', 'CLOSED', 'CANCELLED'] as $s): ?><option <?= $s === $poSt ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?>
      </select></div>
    <?php endif; ?>
    <div><label>Dari</label><input type="date" name="from" value="<?= e($from) ?>" style="padding:8px;border:1px solid #ccc;border-radius:6px"></div>
    <div><label>Sampai</label><input type="date" name="to" value="<?= e($to) ?>" style="padding:8px;border:1px solid #ccc;border-radius:6px"></div>
    <button type="submit">Tampilkan</button>
    <a class="btn sec" href="?r=<?= e($r) ?>">Reset</a>
    <?php if ($rep['rows']): ?>
      <a class="btn sec" href="?<?= e(http_build_query($qsBase + ['export' => 'csv'])) ?>">Ekspor Excel</a>
      <a class="btn sec" href="?<?= e(http_build_query($qsBase + ['print' => '1'])) ?>" target="_blank">Cetak / PDF</a>
    <?php endif; ?>
  </form>
</div>
<div class="panel scroll">
  <b><?= e($rep['title']) ?></b>
  <?php foreach ($rep['sub'] as $s): ?><br><small><?= e($s) ?></small><?php endforeach; ?>
  <?php if ($rep['note'] !== ''): ?><br><small><b><?= e($rep['note']) ?></b></small><?php endif; ?>
  <?php $renderTable(); ?>
  <?php if ($rep['truncated']): ?><small>Tampilan dibatasi <?= (int)$limit ?> baris. Persempit periode atau gunakan ekspor.</small><?php endif; ?>
</div>
<?php renderFooter();
