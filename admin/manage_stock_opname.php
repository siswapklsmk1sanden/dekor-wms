<?php
/**
 * Stock Opname per rak.
 *  1. Pembuat sesi membekukan rak (transaksi lain ditolak).
 *  2. Staf lapangan memasukkan hitung fisik BUTA (saldo sistem tidak ditampilkan).
 *  3. Pemegang hak "Setujui" meninjau selisih dan memposting penyesuaian
 *     (OPNAME_ADJUSTMENT ke buku besar), lalu rak dicairkan. Atau membatalkan sesi.
 * Peran dengan hak lihat harga (Auditor / Finance, Kepala Gudang) juga melihat "Audit Valuasi":
 * nilai rupiah selisih per lot (selisih x harga lot) dan totalnya; Finance hanya membaca.
 * Butuh tabel opname_sessions & opname_counts (database/migration_opname.sql).
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/layout.php';
requirePermission('opname', 'view');

$canCreate  = canAccess('opname', 'create');
$canApprove = canAccess('opname', 'approve');
$showValue  = canSeePrice('opname');                // Audit Valuasi: nilai rupiah selisih
$canReview  = $canApprove || $showValue;            // tinjauan selisih (hitung buta tetap tertutup untuk staf gudang)
$csrf       = stockCsrf();
$errors     = [];
$uid        = (int)$_SESSION['user_id'];

function opnameSession($id)
{
    global $connect;
    $st = mysqli_prepare($connect, "SELECT s.*, b.bin_code, b.zone_name FROM opname_sessions s
        JOIN storage_bins b ON b.id = s.bin_id WHERE s.id = ?");
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($st));
}

/** Gabungan baris saldo sistem + hasil hitung, kunci "item:batch". */
function opnameRows($sessionId, $binId)
{
    global $connect;
    $rows = [];
    $st = mysqli_prepare($connect, "SELECT sb.item_id, sb.batch_id, sb.qty_on_hand, i.sku, i.item_name, u.unit_code, b.batch_number, b.unit_cost_idr
        FROM stock_balances sb JOIN items i ON i.id = sb.item_id JOIN units u ON u.id = i.unit_id
        JOIN item_batches b ON b.id = sb.batch_id WHERE sb.bin_id = ? AND sb.qty_on_hand > 0
        ORDER BY i.sku, b.batch_number");
    mysqli_stmt_bind_param($st, 'i', $binId);
    mysqli_stmt_execute($st);
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC) as $r) {
        $r['sys'] = (float)$r['qty_on_hand'];
        $r['counted'] = null;
        $rows[$r['item_id'] . ':' . $r['batch_id']] = $r;
    }
    $st = mysqli_prepare($connect, "SELECT c.item_id, c.batch_id, c.counted_qty, c.system_qty, i.sku, i.item_name, u.unit_code, b.batch_number, b.unit_cost_idr
        FROM opname_counts c JOIN items i ON i.id = c.item_id JOIN units u ON u.id = i.unit_id
        JOIN item_batches b ON b.id = c.batch_id WHERE c.session_id = ?");
    mysqli_stmt_bind_param($st, 'i', $sessionId);
    mysqli_stmt_execute($st);
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC) as $r) {
        $k = $r['item_id'] . ':' . $r['batch_id'];
        if (!isset($rows[$k])) {
            $r['sys'] = $r['system_qty'] !== null ? (float)$r['system_qty'] : 0.0;
            $rows[$k] = $r;
        }
        $rows[$k]['counted'] = (float)$r['counted_qty'];
        // Setelah posting, saldo sistem sudah disesuaikan: pakai snapshot saat posting agar selisih tetap terbaca.
        if ($r['system_qty'] !== null) $rows[$k]['sys'] = (float)$r['system_qty'];
    }
    ksort($rows);
    return $rows;
}

function goto_($sid, $type, $msg)
{
    $_SESSION['flash'] = [$type, $msg];
    header('Location: manage_stock_opname.php' . ($sid ? '?s=' . (int)$sid : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    stockCheckCsrf();
    $act = $_POST['act'] ?? '';
    $sid = (int)($_POST['sid'] ?? 0);

    // ---- Buka sesi baru & bekukan rak
    if ($act === 'open') {
        requirePermission('opname', 'create');
        $binId = (int)($_POST['bin_id'] ?? 0);
        $notes = mb_substr(trim($_POST['notes'] ?? ''), 0, 500);
        try {
            stockBegin();
            $st = mysqli_prepare($connect, "SELECT b.*, w.warehouse_type FROM storage_bins b
                JOIN warehouses w ON w.id = b.warehouse_id WHERE b.id = ? FOR UPDATE");
            mysqli_stmt_bind_param($st, 'i', $binId);
            mysqli_stmt_execute($st);
            $bin = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
            if (!$bin || !(int)$bin['is_active'] || $bin['warehouse_type'] === 'VIRTUAL_COUNTERPART') throw new StockException('Rak tidak valid.');
            if ((int)$bin['is_frozen_for_opname']) throw new StockException('Rak sudah dibekukan (ada opname lain yang berjalan).');
            $code = 'OPN-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
            $st = mysqli_prepare($connect, "INSERT INTO opname_sessions (session_code, bin_id, notes, created_by) VALUES (?,?,?,?)");
            mysqli_stmt_bind_param($st, 'sisi', $code, $binId, $notes, $uid);
            if (!mysqli_stmt_execute($st)) throw new StockException('Gagal membuat sesi: ' . mysqli_error($connect));
            $newId = mysqli_insert_id($connect);
            mysqli_query($connect, "UPDATE storage_bins SET is_frozen_for_opname = 1 WHERE id = " . (int)$binId);
            stockCommit();
            logActivity('CREATE', 'Stock Opname', "Membuka opname $code, rak {$bin['bin_code']} dibekukan", $newId);
            goto_($newId, 'ok', "Sesi $code dibuka; rak {$bin['bin_code']} dibekukan.");
        } catch (StockException $ex) {
            stockRollback();
            $errors[] = $ex->getMessage();
        }
    }

    $sess = $sid ? opnameSession($sid) : null;
    if (in_array($act, ['count', 'post', 'cancel'], true)) {
        if (!$sess) goto_(0, 'err', 'Sesi tidak ditemukan.');
        if ($sess['status'] !== 'OPEN') goto_($sid, 'err', 'Sesi sudah ditutup.');
    }

    // ---- Simpan hasil hitung buta
    if ($act === 'count') {
        requirePermission('opname', 'create');
        $saved = 0;
        $st = mysqli_prepare($connect, "INSERT INTO opname_counts (session_id, item_id, batch_id, counted_qty, counted_by)
            VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE counted_qty = VALUES(counted_qty), counted_by = VALUES(counted_by), counted_at = NOW()");
        $entries = [];
        foreach (($_POST['counts'] ?? []) as $key => $val) $entries[$key] = $val;
        if (!empty($_POST['extra_lot']) && ($_POST['extra_qty'] ?? '') !== '') $entries[$_POST['extra_lot']] = $_POST['extra_qty'];
        foreach ($entries as $key => $val) {
            $val = trim((string)$val);
            if ($val === '') continue;
            $p = explode(':', (string)$key);
            if (count($p) !== 2 || !is_numeric($val) || (float)$val < 0) { $errors[] = "Jumlah tidak valid untuk baris $key."; continue; }
            [$iid, $bid] = array_map('intval', $p);
            $chk = mysqli_prepare($connect, "SELECT 1 FROM item_batches WHERE id = ? AND item_id = ?");
            mysqli_stmt_bind_param($chk, 'ii', $bid, $iid);
            mysqli_stmt_execute($chk);
            if (!mysqli_fetch_row(mysqli_stmt_get_result($chk))) { $errors[] = 'Lot tidak valid.'; continue; }
            $q = number_format((float)$val, 4, '.', '');
            mysqli_stmt_bind_param($st, 'iiisi', $sid, $iid, $bid, $q, $uid);
            if (mysqli_stmt_execute($st)) $saved++;
        }
        if (!$errors) {
            logActivity('UPDATE', 'Stock Opname', "Hitung fisik {$sess['session_code']}: $saved baris disimpan", $sid);
            goto_($sid, 'ok', "$saved baris hitung disimpan.");
        }
        $_GET['s'] = $sid;
    }

    // ---- Batalkan sesi
    if ($act === 'cancel') {
        requirePermission('opname', 'approve');
        mysqli_begin_transaction($connect);
        $st = mysqli_prepare($connect, "UPDATE opname_sessions SET status='CANCELLED', closed_by=?, closed_at=NOW() WHERE id=? AND status='OPEN'");
        mysqli_stmt_bind_param($st, 'ii', $uid, $sid);
        mysqli_stmt_execute($st);
        mysqli_query($connect, "UPDATE storage_bins SET is_frozen_for_opname = 0 WHERE id = " . (int)$sess['bin_id']);
        mysqli_commit($connect);
        logActivity('UPDATE', 'Stock Opname', "Membatalkan opname {$sess['session_code']}, rak dicairkan", $sid);
        goto_($sid, 'ok', 'Sesi dibatalkan dan rak dicairkan.');
    }

    // ---- Posting selisih
    if ($act === 'post') {
        requirePermission('opname', 'approve');
        try {
            stockBegin();
            $bin = stockGetBin((int)$sess['bin_id'], true, true);
            $rows = opnameRows($sid, (int)$sess['bin_id']);
            $missing = [];
            foreach ($rows as $r) if ($r['counted'] === null) $missing[] = $r['sku'] . ' / ' . $r['batch_number'];
            if ($missing) throw new StockException('Belum dihitung: ' . implode(', ', $missing) . '.');

            [$trxId, $trxCode] = stockCreateTrx('OPNAME_ADJUSTMENT', $sess['session_code'], 'Penyesuaian stock opname ' . $sess['session_code']);
            $adj = 0;
            $snap = mysqli_prepare($connect, "UPDATE opname_counts SET system_qty = ? WHERE session_id = ? AND item_id = ? AND batch_id = ?");
            foreach ($rows as $r) {
                $diff = round($r['counted'] - $r['sys'], 4);
                $q = number_format($r['sys'], 4, '.', '');
                mysqli_stmt_bind_param($snap, 'siii', $q, $sid, $r['item_id'], $r['batch_id']);
                mysqli_stmt_execute($snap);
                if ($diff == 0.0) continue;
                $cs = mysqli_prepare($connect, "SELECT unit_cost_idr FROM item_batches WHERE id = ?");
                mysqli_stmt_bind_param($cs, 'i', $r['batch_id']);
                mysqli_stmt_execute($cs);
                $cost = (float)mysqli_fetch_row(mysqli_stmt_get_result($cs))[0];
                stockMove($trxId, (int)$r['item_id'], $bin, (int)$r['batch_id'], $diff, $cost);
                $adj++;
            }
            $st = mysqli_prepare($connect, "UPDATE opname_sessions SET status='POSTED', closed_by=?, closed_at=NOW(), transaction_id=? WHERE id=?");
            mysqli_stmt_bind_param($st, 'iii', $uid, $trxId, $sid);
            mysqli_stmt_execute($st);
            mysqli_query($connect, "UPDATE storage_bins SET is_frozen_for_opname = 0 WHERE id = " . (int)$sess['bin_id']);
            stockCommit();
            logActivity('CREATE', 'Stock Opname', "Posting opname {$sess['session_code']} ($trxCode): $adj baris disesuaikan, rak dicairkan", $sid);
            goto_($sid, 'ok', "Posting selesai ($trxCode): $adj baris disesuaikan, rak dicairkan.");
        } catch (StockException $ex) {
            stockRollback();
            $errors[] = $ex->getMessage();
            $_GET['s'] = $sid;
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$viewId = (int)($_GET['s'] ?? 0);
$sess = $viewId ? opnameSession($viewId) : null;

renderHeader('Stock Opname');
?>
<h2>Stock Opname</h2>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($sess): $rows = opnameRows((int)$sess['id'], (int)$sess['bin_id']); $open = $sess['status'] === 'OPEN'; ?>
<p><a class="btn sec" href="manage_stock_opname.php">&laquo; Semua sesi</a></p>
<div class="panel">
  <strong><?= e($sess['session_code']) ?></strong> &middot; Rak <?= e($sess['bin_code']) ?> (<?= e($sess['zone_name']) ?>)
  <span class="tag <?= $open ? '' : 'off' ?>"><?= e($sess['status']) ?></span><br>
  <small>Dibuka <?= e($sess['created_at']) ?><?= $sess['notes'] ? ' &middot; ' . e($sess['notes']) : '' ?></small>
  <?php $done = count(array_filter($rows, fn($r) => $r['counted'] !== null)); ?>
  <div style="margin-top:8px">Progres hitung: <b><?= $done ?></b> / <?= count($rows) ?> baris</div>
</div>

<?php if ($open && $canCreate): ?>
<div class="panel scroll">
  <strong>Hitung fisik</strong> <small>(buta: isi jumlah yang benar-benar Anda hitung di rak)</small>
  <form method="post" style="margin-top:10px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="count"><input type="hidden" name="sid" value="<?= (int)$sess['id'] ?>">
    <table>
      <tr><th>SKU</th><th>Barang</th><th>Lot</th><th>Hitung fisik</th></tr>
      <?php if (!$rows): ?><tr><td colspan="4">Tidak ada saldo tercatat di rak ini. Tambahkan lot yang ditemukan di bawah.</td></tr><?php endif; ?>
      <?php foreach ($rows as $k => $r): ?>
        <tr><td><?= e($r['sku']) ?></td><td><?= e($r['item_name']) ?></td><td><?= e($r['batch_number']) ?></td>
          <td><input type="number" step="0.0001" min="0" name="counts[<?= e($k) ?>]" value="<?= $r['counted'] === null ? '' : e(fmtQty($r['counted'])) ?>" style="max-width:140px"> <?= e($r['unit_code']) ?></td></tr>
      <?php endforeach; ?>
    </table>
    <?php $lots = mysqli_fetch_all(mysqli_query($connect, "SELECT b.id, b.item_id, b.batch_number, i.sku FROM item_batches b JOIN items i ON i.id = b.item_id ORDER BY i.sku, b.batch_number"), MYSQLI_ASSOC); ?>
    <div class="grid" style="margin-top:14px">
      <div><label>Lot lain yang ditemukan di rak (opsional)</label>
        <select name="extra_lot"><option value="">-</option>
        <?php foreach ($lots as $l): ?><option value="<?= (int)$l['item_id'] . ':' . (int)$l['id'] ?>"><?= e($l['sku'] . ' | ' . $l['batch_number']) ?></option><?php endforeach; ?></select></div>
      <div><label>Jumlah ditemukan</label><input type="number" step="0.0001" min="0" name="extra_qty"></div>
    </div>
    <p><button type="submit">Simpan Hitungan</button></p>
  </form>
</div>
<?php endif; ?>

<?php if ($canReview): ?>
<div class="panel scroll">
  <strong><?= $showValue ? 'Audit valuasi selisih' : 'Tinjauan selisih' ?></strong> <small>(hanya terlihat oleh peran berwenang)</small>
  <table>
    <tr><th>SKU</th><th>Lot</th><th>Sistem</th><th>Fisik</th><th>Selisih</th><?php if ($showValue): ?><th>Harga lot (Rp)</th><th>Nilai selisih (Rp)</th><?php endif; ?></tr>
    <?php $sumLoss = 0.0; $sumGain = 0.0;
    foreach ($rows as $r):
        $d = $r['counted'] === null ? null : round($r['counted'] - $r['sys'], 4);
        $cost = (float)($r['unit_cost_idr'] ?? 0);
        $val = $d === null ? null : round($d * $cost, 2);
        if ($val !== null) { if ($val < 0) $sumLoss += $val; else $sumGain += $val; } ?>
      <tr><td><?= e($r['sku']) ?></td><td><?= e($r['batch_number']) ?></td><td><?= e(fmtQty($r['sys'])) ?></td>
        <td><?= $r['counted'] === null ? '<i>belum</i>' : e(fmtQty($r['counted'])) ?></td>
        <td><?= $d === null ? '-' : '<b style="color:' . ($d < 0 ? '#b3261e' : ($d > 0 ? '#1e6b34' : '#555')) . '">' . ($d > 0 ? '+' : '') . e(fmtQty($d)) . '</b>' ?></td>
        <?php if ($showValue): ?>
        <td><?= e(number_format($cost, 0, ',', '.')) ?></td>
        <td><?= $val === null ? '-' : '<b style="color:' . ($val < 0 ? '#b3261e' : ($val > 0 ? '#1e6b34' : '#555')) . '">' . ($val > 0 ? '+' : '') . e(number_format($val, 0, ',', '.')) . '</b>' ?></td>
        <?php endif; ?></tr>
    <?php endforeach; ?>
    <?php if ($showValue): ?>
      <tr><td colspan="6" style="text-align:right"><b>Total kekurangan (kerugian)</b></td><td><b style="color:#b3261e"><?= e(number_format($sumLoss, 0, ',', '.')) ?></b></td></tr>
      <tr><td colspan="6" style="text-align:right"><b>Total kelebihan</b></td><td><b style="color:#1e6b34">+<?= e(number_format($sumGain, 0, ',', '.')) ?></b></td></tr>
      <tr><td colspan="6" style="text-align:right"><b>Nilai selisih bersih</b></td><td><b><?= e(number_format($sumLoss + $sumGain, 0, ',', '.')) ?></b></td></tr>
    <?php endif; ?>
  </table>
  <?php if ($showValue): ?><small>Nilai = selisih &times; harga pokok lot saat diterima. Untuk sesi yang belum diposting, saldo sistem adalah saldo saat ini.</small><?php endif; ?>
  <?php if ($open && $canApprove): ?>
  <div style="display:flex;gap:8px;margin-top:14px">
    <form method="post" onsubmit="return confirm('Posting selisih ke buku besar? Tindakan ini tidak dapat dibatalkan.')">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="post"><input type="hidden" name="sid" value="<?= (int)$sess['id'] ?>">
      <button type="submit">Posting Selisih &amp; Cairkan Rak</button></form>
    <form method="post" onsubmit="return confirm('Batalkan sesi opname?')">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="cancel"><input type="hidden" name="sid" value="<?= (int)$sess['id'] ?>">
      <button class="danger" type="submit">Batalkan Sesi</button></form>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php else:
    $sessions = mysqli_fetch_all(mysqli_query($connect, "SELECT s.id, s.session_code, s.status, s.created_at, s.closed_at, b.bin_code, u.username,
        (SELECT COUNT(*) FROM opname_counts c WHERE c.session_id = s.id) AS counted,
        (SELECT COALESCE(SUM((c.counted_qty - COALESCE(c.system_qty, c.counted_qty)) * b.unit_cost_idr), 0)
           FROM opname_counts c JOIN item_batches b ON b.id = c.batch_id WHERE c.session_id = s.id) AS diff_value
        FROM opname_sessions s JOIN storage_bins b ON b.id = s.bin_id JOIN users u ON u.id = s.created_by
        ORDER BY s.id DESC LIMIT 50"), MYSQLI_ASSOC);
    $bins = stockBinOptions(); ?>
<?php if ($canCreate): ?>
<div class="panel">
  <strong>Buka sesi opname baru</strong> <small>(rak akan dibekukan: tidak ada transaksi masuk/keluar/transfer selama opname)</small>
  <form method="post" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="open">
    <div class="grid">
      <div><label>Rak *</label><select name="bin_id" required><option value="">- pilih -</option>
        <?php foreach ($bins as $b): ?><option value="<?= (int)$b['id'] ?>"><?= e($b['bin_code'] . ' - ' . $b['zone_name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Catatan</label><input type="text" name="notes" maxlength="500"></div>
    </div>
    <p style="margin:14px 0 0"><button type="submit">Buka &amp; Bekukan Rak</button></p>
  </form>
</div>
<?php endif; ?>
<div class="panel scroll">
  <table>
    <tr><th>Kode</th><th>Rak</th><th>Status</th><th>Baris dihitung</th><?php if ($showValue): ?><th>Nilai selisih (Rp)</th><?php endif; ?><th>Dibuka</th><th>Oleh</th><th></th></tr>
    <?php if (!$sessions): ?><tr><td colspan="8">Belum ada sesi.</td></tr><?php endif; ?>
    <?php foreach ($sessions as $s): ?>
      <tr><td><?= e($s['session_code']) ?></td><td><?= e($s['bin_code']) ?></td>
        <td><span class="tag <?= $s['status'] === 'OPEN' ? '' : 'off' ?>"><?= e($s['status']) ?></span></td>
        <td><?= (int)$s['counted'] ?></td>
        <?php if ($showValue): ?><td><?= $s['status'] === 'POSTED' ? e(number_format((float)$s['diff_value'], 0, ',', '.')) : '-' ?></td><?php endif; ?>
        <td><?= e($s['created_at']) ?></td><td><?= e($s['username']) ?></td>
        <td><a class="btn sec" href="?s=<?= (int)$s['id'] ?>">Buka</a></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
<?php renderFooter();
