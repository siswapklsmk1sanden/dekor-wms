<?php
/**
 * Penilaian Supplier (baca saja). Tanpa input baru; semua dihitung dari data yang sudah ada:
 *  - Ketepatan kirim : batas kirim di PO (deliver_by) dibandingkan tanggal penerimaan terakhir di po_receipts.
 *      Tepat      = PO lengkap (atau ditutup) dan penerimaan terakhir <= batas kirim.
 *      Terlambat  = lengkap tetapi lewat batas kirim, atau belum lengkap padahal batas kirim sudah lewat.
 *      PO yang belum lengkap tetapi batas kirimnya belum lewat belum dinilai. PO batal dan PO tanpa batas kirim tidak dihitung.
 *  - Mutu            : persentase LOT diterima yang berstatus ditolak QC (jumlah lot, bukan qty, karena satuannya berbeda-beda).
 *  - Retur           : jumlah retur ke supplier (menu Retur Supplier).
 * Hak akses: modul Barang Masuk (inbound) - lihat.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/layout.php';
requirePermission('inbound', 'view');

// Ambang label (ubah di sini bila kebijakan perusahaan berbeda)
const SC_OK_ONTIME = 90;   // % tepat kirim minimal untuk "Baik"
const SC_OK_REJECT = 5;    // % lot ditolak maksimal untuk "Baik"
const SC_BAD_ONTIME = 70;  // di bawah ini "Perlu evaluasi"
const SC_BAD_REJECT = 15;  // di atas ini "Perlu evaluasi"

$dateOk = fn($d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
$from = isset($_GET['from']) ? trim($_GET['from']) : date('Y-m-d', strtotime('-12 months'));
$to   = isset($_GET['to']) ? trim($_GET['to']) : '';
if ($from !== '' && !$dateOk($from)) $from = '';
if ($to !== '' && !$dateOk($to)) $to = '';
$fromT = $from !== '' ? $from . ' 00:00:00' : '';
$toT   = $to !== '' ? $to . ' 23:59:59' : '';
$detailId = (int)($_GET['sup'] ?? 0);
$today = date('Y-m-d');

$sup = [];
foreach (mysqli_fetch_all(mysqli_query($connect, "SELECT id, supplier_code, company_name FROM suppliers ORDER BY company_name"), MYSQLI_ASSOC) as $s) {
    $sup[(int)$s['id']] = ['code' => $s['supplier_code'], 'name' => $s['company_name'], 'po' => 0, 'ontime' => 0, 'late' => 0, 'late_days' => 0,
                           'lots' => 0, 'rejected' => 0, 'hold' => 0, 'returns' => 0];
}

// --- Ketepatan kirim
$st = mysqli_prepare($connect, "SELECT p.id, p.po_number, p.supplier_id, p.order_date, p.deliver_by, p.status,
        COALESCE(SUM(l.qty_ordered), 0) AS qo, COALESCE(SUM(l.qty_received), 0) AS qr,
        (SELECT MAX(DATE(r.created_at)) FROM po_receipts r JOIN purchase_order_lines l2 ON l2.id = r.po_line_id
            WHERE l2.po_id = p.id AND r.is_reversed = 0) AS last_rcv
    FROM purchase_orders p LEFT JOIN purchase_order_lines l ON l.po_id = p.id
    WHERE p.status <> 'CANCELLED' AND p.deliver_by IS NOT NULL AND (? = '' OR p.order_date >= ?) AND (? = '' OR p.order_date <= ?)
    GROUP BY p.id, p.po_number, p.supplier_id, p.order_date, p.deliver_by, p.status
    ORDER BY p.order_date DESC, p.id DESC");
mysqli_stmt_bind_param($st, 'ssss', $from, $from, $to, $to);
mysqli_stmt_execute($st);
$pos = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
$detail = [];
foreach ($pos as $p) {
    $sid = (int)$p['supplier_id'];
    if (!isset($sup[$sid])) continue;
    $complete = ((float)$p['qo'] > 0 && (float)$p['qr'] >= (float)$p['qo'] - 0.00005) || $p['status'] === 'CLOSED';
    $result = null; $days = 0;
    if ($p['last_rcv'] !== null && $complete) {
        $days = (int)floor((strtotime($p['last_rcv']) - strtotime($p['deliver_by'])) / 86400);
        $result = $days <= 0 ? 'ontime' : 'late';
        $days = max(0, $days);
    } elseif ($p['deliver_by'] < $today) {
        $result = 'late';
        $days = (int)floor((strtotime($today) - strtotime($p['deliver_by'])) / 86400);
    }
    if ($result === null) continue;   // belum jatuh tempo
    $sup[$sid]['po']++;
    $sup[$sid][$result]++;
    if ($result === 'late') $sup[$sid]['late_days'] += $days;
    if ($sid === $detailId) $detail[] = $p + ['result' => $result, 'days' => $days, 'complete' => $complete];
}

// --- Mutu lot
$st = mysqli_prepare($connect, "SELECT supplier_id, COUNT(*) AS lots, SUM(qc_status = 'REJECTED') AS rej, SUM(qc_status = 'HOLD_QUARANTINE') AS hold
    FROM item_batches WHERE supplier_id IS NOT NULL AND (? = '' OR received_date >= ?) AND (? = '' OR received_date <= ?) GROUP BY supplier_id");
mysqli_stmt_bind_param($st, 'ssss', $from, $from, $to, $to);
mysqli_stmt_execute($st);
foreach (mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC) as $r) {
    $sid = (int)$r['supplier_id'];
    if (!isset($sup[$sid])) continue;
    $sup[$sid]['lots'] = (int)$r['lots']; $sup[$sid]['rejected'] = (int)$r['rej']; $sup[$sid]['hold'] = (int)$r['hold'];
}

// --- Retur
$st = mysqli_prepare($connect, "SELECT b.supplier_id, COUNT(DISTINCT t.id) AS n
    FROM inventory_transactions t JOIN inventory_ledger_entries l ON l.transaction_id = t.id JOIN item_batches b ON b.id = l.batch_id
    WHERE t.trx_type = 'SCRAP_WRITE_OFF' AND t.reference_doc_no LIKE 'RTR-%' AND b.supplier_id IS NOT NULL
      AND (? = '' OR t.created_at >= ?) AND (? = '' OR t.created_at <= ?) GROUP BY b.supplier_id");
mysqli_stmt_bind_param($st, 'ssss', $fromT, $fromT, $toT, $toT);
mysqli_stmt_execute($st);
foreach (mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC) as $r) {
    $sid = (int)$r['supplier_id'];
    if (isset($sup[$sid])) $sup[$sid]['returns'] = (int)$r['n'];
}

// --- Turunan: persentase dan label
$rows = [];
foreach ($sup as $sid => $s) {
    if ($s['po'] === 0 && $s['lots'] === 0 && $s['returns'] === 0) continue;   // tidak ada data pada periode ini
    $s['id'] = $sid;
    $s['pct_ontime'] = $s['po'] > 0 ? round($s['ontime'] / $s['po'] * 100, 1) : null;
    $s['avg_late']   = $s['late'] > 0 ? round($s['late_days'] / $s['late'], 1) : null;
    $s['pct_reject'] = $s['lots'] > 0 ? round($s['rejected'] / $s['lots'] * 100, 1) : null;
    $label = 'Belum cukup data';
    if ($s['pct_ontime'] !== null || $s['pct_reject'] !== null) {
        $o = $s['pct_ontime']; $rj = $s['pct_reject'];
        if (($o !== null && $o < SC_BAD_ONTIME) || ($rj !== null && $rj > SC_BAD_REJECT)) $label = 'Perlu evaluasi';
        elseif (($o === null || $o >= SC_OK_ONTIME) && ($rj === null || $rj <= SC_OK_REJECT)) $label = 'Baik';
        else $label = 'Cukup';
    }
    $s['label'] = $label;
    $rows[] = $s;
}
usort($rows, fn($a, $b) => strcasecmp($a['name'], $b['name']));

$fmtP = fn($v) => $v === null ? '-' : rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',') . '%';

if (($_GET['export'] ?? '') === 'csv') {
    $o = [];
    foreach ($rows as $s) {
        $o[] = [$s['code'], $s['name'], $s['po'], $s['ontime'], $s['late'], $s['pct_ontime'] ?? '', $s['avg_late'] ?? '',
                $s['lots'], $s['rejected'], $s['hold'], $s['pct_reject'] ?? '', $s['returns'], $s['label']];
    }
    csvOut('penilaian_supplier_' . date('Ymd_His') . '.csv',
        ['Kode', 'Supplier', 'PO dinilai', 'Tepat', 'Terlambat', '% tepat kirim', 'Rata-rata telat (hari)', 'Lot diterima', 'Lot ditolak', 'Lot ditahan', '% lot ditolak', 'Retur (kali)', 'Penilaian'], $o);
}

$qs = fn(array $x = []) => http_build_query(array_merge(['from' => $from, 'to' => $to], $x));
$tagStyle = ['Baik' => 'background:#e3f4e6;color:#1e6b34', 'Cukup' => 'background:#fff4e0;color:#8a5a00', 'Perlu evaluasi' => 'background:#fdecea;color:#b3261e', 'Belum cukup data' => 'background:#eee;color:#555'];
renderHeader('Penilaian Supplier');
?>
<h2>Penilaian Supplier</h2>
<div class="panel">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
    <div><label>Dari (tanggal PO / terima)</label><input type="date" name="from" value="<?= e($from) ?>" style="padding:8px;border:1px solid #ccc;border-radius:6px"></div>
    <div><label>Sampai</label><input type="date" name="to" value="<?= e($to) ?>" style="padding:8px;border:1px solid #ccc;border-radius:6px"></div>
    <button type="submit">Tampilkan</button>
    <a class="btn sec" href="manage_supplier_score.php">Reset (12 bulan terakhir)</a>
    <?php if ($rows): ?><a class="btn sec" href="?<?= e($qs(['export' => 'csv'])) ?>">Ekspor Excel</a><?php endif; ?>
  </form>
</div>
<div class="panel scroll">
  <table>
    <tr><th>Supplier</th><th>PO dinilai</th><th>Tepat</th><th>Terlambat</th><th>% tepat kirim</th><th>Rata-rata telat</th>
      <th>Lot diterima</th><th>Lot ditolak</th><th>% ditolak</th><th>Retur</th><th>Penilaian</th></tr>
    <?php if (!$rows): ?><tr><td colspan="11">Belum ada data pada periode ini.</td></tr><?php endif; ?>
    <?php foreach ($rows as $s): ?>
      <tr><td><a href="?<?= e($qs(['sup' => $s['id']])) ?>#detail"><b><?= e($s['name']) ?></b></a><br><small><?= e($s['code']) ?></small></td>
        <td><?= (int)$s['po'] ?></td><td><?= (int)$s['ontime'] ?></td><td><?= (int)$s['late'] ?></td>
        <td><b><?= e($fmtP($s['pct_ontime'])) ?></b></td><td><?= $s['avg_late'] !== null ? e(number_format($s['avg_late'], 1, ',', '.')) . ' hr' : '-' ?></td>
        <td><?= (int)$s['lots'] ?></td><td><?= (int)$s['rejected'] ?><?= $s['hold'] ? ' <small>(+' . (int)$s['hold'] . ' ditahan)</small>' : '' ?></td>
        <td><b><?= e($fmtP($s['pct_reject'])) ?></b></td><td><?= (int)$s['returns'] ?></td>
        <td><span class="tag" style="<?= $tagStyle[$s['label']] ?>"><?= e($s['label']) ?></span></td></tr>
    <?php endforeach; ?>
  </table>
  <p><small>Ketepatan kirim: penerimaan terakhir PO dibandingkan batas kirim di PO (PO tanpa batas kirim, PO batal, dan PO yang belum jatuh tempo tidak dihitung).
    Mutu: persentase lot yang ditolak QC dari seluruh lot diterima (lot yang masih ditahan belum dihitung sebagai ditolak).
    Label: Baik = tepat &ge; <?= SC_OK_ONTIME ?>% dan ditolak &le; <?= SC_OK_REJECT ?>%; Perlu evaluasi = tepat &lt; <?= SC_BAD_ONTIME ?>% atau ditolak &gt; <?= SC_BAD_REJECT ?>%; selain itu Cukup.
    Angka dari sedikit PO atau lot belum tentu mewakili. Klik nama supplier untuk melihat PO-nya.</small></p>
</div>

<?php if ($detailId && isset($sup[$detailId])): ?>
<div class="panel scroll" id="detail">
  <strong>PO yang dinilai: <?= e($sup[$detailId]['name']) ?></strong>
  <table>
    <tr><th>No. PO</th><th>Tanggal PO</th><th>Batas kirim</th><th>Terima terakhir</th><th>Status PO</th><th>Hasil</th></tr>
    <?php if (!$detail): ?><tr><td colspan="6">Tidak ada PO yang dinilai pada periode ini.</td></tr><?php endif; ?>
    <?php foreach ($detail as $d): $ok = $d['result'] === 'ontime'; ?>
      <tr><td><a href="manage_po.php?id=<?= (int)$d['id'] ?>"><?= e($d['po_number']) ?></a></td><td><?= e($d['order_date']) ?></td><td><?= e($d['deliver_by']) ?></td>
        <td><?= e($d['last_rcv'] ?? '-') ?><?= !$d['complete'] && $d['last_rcv'] ? ' <small>(belum lengkap)</small>' : '' ?></td><td><?= e($d['status']) ?></td>
        <td><span class="tag" style="<?= $ok ? $tagStyle['Baik'] : $tagStyle['Perlu evaluasi'] ?>"><?= $ok ? 'Tepat' : 'Terlambat ' . (int)$d['days'] . ' hr' ?></span></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
<?php renderFooter();
