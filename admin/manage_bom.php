<?php
/**
 * Resep Manufaktur (BoM): kebutuhan komponen per output_batch_size unit barang jadi,
 * dengan toleransi scrap per komponen, plus kalkulator kebutuhan bahan vs stok.
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/layout.php';
requirePermission('bom', 'view');

$canCreate  = canAccess('bom', 'create');
$canApprove = canAccess('bom', 'approve');
$showCost   = canSeePrice('bom');
$csrf       = stockCsrf();
$errors     = [];

$targets = mysqli_fetch_all(mysqli_query($connect, "SELECT i.id, i.sku, i.item_name, u.unit_code
    FROM items i JOIN units u ON u.id = i.unit_id JOIN categories c ON c.id = i.category_id
    WHERE i.is_active = 1 AND c.category_type IN ('FINISHED_GOOD','HALF_FINISHED') ORDER BY i.sku"), MYSQLI_ASSOC);
$comps = mysqli_fetch_all(mysqli_query($connect, "SELECT i.id, i.sku, i.item_name, u.unit_code
    FROM items i JOIN units u ON u.id = i.unit_id JOIN categories c ON c.id = i.category_id
    WHERE i.is_active = 1 AND c.category_type <> 'FINISHED_GOOD' ORDER BY i.sku"), MYSQLI_ASSOC);
$targetIds = array_map('intval', array_column($targets, 'id'));
$compIds   = array_map('intval', array_column($comps, 'id'));

$form = ['id' => 0, 'bom_code' => '', 'target_item_id' => '', 'output_batch_size' => '1', 'lines' => [['', '', '3']]];

function bomRedirect($type, $msg)
{
    $_SESSION['flash'] = [$type, $msg];
    header('Location: manage_bom.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    stockCheckCsrf();
    $act = $_POST['act'] ?? 'save';
    $id  = (int)($_POST['id'] ?? 0);

    if ($act === 'toggle' && $id > 0) {
        requirePermission('bom', 'approve');
        $st = mysqli_prepare($connect, "SELECT target_item_id, is_active, bom_code FROM bom_headers WHERE id = ?");
        mysqli_stmt_bind_param($st, 'i', $id);
        mysqli_stmt_execute($st);
        $h = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        if (!$h) bomRedirect('err', 'BoM tidak ditemukan.');
        if (!(int)$h['is_active']) {
            $c = mysqli_prepare($connect, "SELECT COUNT(*) FROM bom_headers WHERE target_item_id = ? AND is_active = 1 AND id <> ?");
            $t = (int)$h['target_item_id'];
            mysqli_stmt_bind_param($c, 'ii', $t, $id);
            mysqli_stmt_execute($c);
            if ((int)mysqli_fetch_row(mysqli_stmt_get_result($c))[0] > 0) {
                bomRedirect('err', 'Sudah ada BoM aktif lain untuk barang jadi ini. Nonaktifkan dulu.');
            }
        }
        $st = mysqli_prepare($connect, "UPDATE bom_headers SET is_active = 1 - is_active WHERE id = ?");
        mysqli_stmt_bind_param($st, 'i', $id);
        mysqli_stmt_execute($st);
        logActivity('UPDATE', 'Bom', ($h['is_active'] ? 'Menonaktifkan' : 'Mengaktifkan') . " BoM {$h['bom_code']}", $id);
        bomRedirect('ok', 'Status BoM diperbarui.');
    }

    if ($act === 'save') {
        requirePermission('bom', 'create');
        $form['id'] = $id;
        $form['bom_code'] = trim($_POST['bom_code'] ?? '');
        $form['target_item_id'] = trim($_POST['target_item_id'] ?? '');
        $form['output_batch_size'] = trim($_POST['output_batch_size'] ?? '');
        $form['lines'] = [];
        $lines = [];
        $seen = [];
        $ci = $_POST['comp_item'] ?? []; $cq = $_POST['comp_qty'] ?? []; $cs = $_POST['comp_scrap'] ?? [];
        foreach ($ci as $n => $itemId) {
            $itemId = trim((string)$itemId); $q = trim((string)($cq[$n] ?? '')); $sc = trim((string)($cs[$n] ?? ''));
            if ($itemId === '' && $q === '') continue;
            $form['lines'][] = [$itemId, $q, $sc];
            if (!in_array((int)$itemId, $compIds, true)) { $errors[] = 'Komponen tidak valid pada baris ' . ($n + 1) . '.'; continue; }
            if (isset($seen[(int)$itemId])) { $errors[] = 'Komponen ganda pada baris ' . ($n + 1) . '.'; continue; }
            $seen[(int)$itemId] = true;
            if (!is_numeric($q) || (float)$q <= 0) { $errors[] = 'Jumlah harus > 0 pada baris ' . ($n + 1) . '.'; continue; }
            if ($sc === '') $sc = '3';
            if (!is_numeric($sc) || (float)$sc < 0 || (float)$sc > 100) { $errors[] = 'Scrap harus 0-100% pada baris ' . ($n + 1) . '.'; continue; }
            $lines[] = [(int)$itemId, (float)$q, (float)$sc];
        }
        if (!$form['lines']) $form['lines'] = [['', '', '3']];
        if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $form['bom_code'])) $errors[] = 'Kode BoM 3-32 karakter (huruf, angka, . _ -).';
        if (!in_array((int)$form['target_item_id'], $targetIds, true)) $errors[] = 'Barang jadi/setengah jadi wajib dipilih.';
        if (!is_numeric($form['output_batch_size']) || (float)$form['output_batch_size'] <= 0) $errors[] = 'Ukuran batch output harus > 0.';
        if (!$lines && !$errors) $errors[] = 'Minimal satu komponen.';

        if (!$errors) {
            $target = (int)$form['target_item_id'];
            $bs = number_format((float)$form['output_batch_size'], 4, '.', '');
            mysqli_begin_transaction($connect);
            try {
                if ($id > 0) {
                    $st = mysqli_prepare($connect, "UPDATE bom_headers SET bom_code=?, target_item_id=?, output_batch_size=? WHERE id=?");
                    mysqli_stmt_bind_param($st, 'sisi', $form['bom_code'], $target, $bs, $id);
                } else {
                    // BoM baru aktif hanya jika belum ada BoM aktif untuk barang itu
                    $c = mysqli_prepare($connect, "SELECT COUNT(*) FROM bom_headers WHERE target_item_id = ? AND is_active = 1");
                    mysqli_stmt_bind_param($c, 'i', $target);
                    mysqli_stmt_execute($c);
                    $act1 = (int)mysqli_fetch_row(mysqli_stmt_get_result($c))[0] === 0 ? 1 : 0;
                    $st = mysqli_prepare($connect, "INSERT INTO bom_headers (bom_code, target_item_id, output_batch_size, is_active) VALUES (?,?,?,?)");
                    mysqli_stmt_bind_param($st, 'sisi', $form['bom_code'], $target, $bs, $act1);
                }
                if (!mysqli_stmt_execute($st)) throw new Exception(mysqli_errno($connect) === 1062 ? 'Kode BoM sudah dipakai.' : mysqli_error($connect));
                $bomId = $id > 0 ? $id : mysqli_insert_id($connect);
                if ($id > 0) {
                    $d = mysqli_prepare($connect, "DELETE FROM bom_components WHERE bom_id = ?");
                    mysqli_stmt_bind_param($d, 'i', $bomId);
                    mysqli_stmt_execute($d);
                }
                $ins = mysqli_prepare($connect, "INSERT INTO bom_components (bom_id, component_item_id, required_qty, scrap_tolerance_pct) VALUES (?,?,?,?)");
                foreach ($lines as [$iid, $q, $sc]) {
                    if ($iid === $target) throw new Exception('Komponen tidak boleh sama dengan barang jadinya.');
                    $qs = number_format($q, 4, '.', ''); $ss = number_format($sc, 2, '.', '');
                    mysqli_stmt_bind_param($ins, 'iiss', $bomId, $iid, $qs, $ss);
                    if (!mysqli_stmt_execute($ins)) throw new Exception(mysqli_error($connect));
                }
                mysqli_commit($connect);
                logActivity($id ? 'UPDATE' : 'CREATE', 'Bom', ($id ? 'Memperbarui' : 'Membuat') . " BoM {$form['bom_code']} (" . count($lines) . ' komponen)', $bomId);
                bomRedirect('ok', $id ? 'BoM diperbarui.' : 'BoM dibuat.');
            } catch (Exception $ex) {
                mysqli_rollback($connect);
                $errors[] = $ex->getMessage();
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit']) && $canCreate) {
    $eid = (int)$_GET['edit'];
    $st = mysqli_prepare($connect, "SELECT id, bom_code, target_item_id, output_batch_size FROM bom_headers WHERE id = ?");
    mysqli_stmt_bind_param($st, 'i', $eid);
    mysqli_stmt_execute($st);
    if ($h = mysqli_fetch_assoc(mysqli_stmt_get_result($st))) {
        $h['output_batch_size'] = fmtQty($h['output_batch_size']);
        $h['lines'] = [];
        $st = mysqli_prepare($connect, "SELECT component_item_id, required_qty, scrap_tolerance_pct FROM bom_components WHERE bom_id = ? ORDER BY id");
        mysqli_stmt_bind_param($st, 'i', $eid);
        mysqli_stmt_execute($st);
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC) as $l) $h['lines'][] = [$l['component_item_id'], fmtQty($l['required_qty']), fmtQty($l['scrap_tolerance_pct'])];
        $form = $h;
    }
}

// Kalkulator kebutuhan
$calc = null;
if (isset($_GET['calc'])) {
    $cid = (int)$_GET['calc'];
    $cqty = is_numeric($_GET['qty'] ?? '') && (float)$_GET['qty'] > 0 ? (float)$_GET['qty'] : 1.0;
    $st = mysqli_prepare($connect, "SELECT h.*, i.sku, i.item_name, u.unit_code FROM bom_headers h
        JOIN items i ON i.id = h.target_item_id JOIN units u ON u.id = i.unit_id WHERE h.id = ?");
    mysqli_stmt_bind_param($st, 'i', $cid);
    mysqli_stmt_execute($st);
    if ($h = mysqli_fetch_assoc(mysqli_stmt_get_result($st))) {
        $st = mysqli_prepare($connect, "SELECT c.required_qty, c.scrap_tolerance_pct, i.sku, i.item_name, i.moving_avg_cost_idr, u.unit_code,
            COALESCE((SELECT SUM(sb.qty_on_hand - sb.qty_reserved) FROM stock_balances sb
                JOIN item_batches b ON b.id = sb.batch_id JOIN storage_bins bn ON bn.id = sb.bin_id
                WHERE sb.item_id = c.component_item_id AND b.qc_status = 'PASSED'
                  AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE()) AND bn.is_frozen_for_opname = 0), 0) AS avail
            FROM bom_components c JOIN items i ON i.id = c.component_item_id JOIN units u ON u.id = i.unit_id
            WHERE c.bom_id = ? ORDER BY c.id");
        mysqli_stmt_bind_param($st, 'i', $cid);
        mysqli_stmt_execute($st);
        $h['rows'] = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
        $h['qty'] = $cqty;
        $calc = $h;
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$boms = mysqli_fetch_all(mysqli_query($connect, "SELECT h.*, i.sku, i.item_name, u.unit_code,
        (SELECT COUNT(*) FROM bom_components c WHERE c.bom_id = h.id) AS n
    FROM bom_headers h JOIN items i ON i.id = h.target_item_id JOIN units u ON u.id = i.unit_id ORDER BY h.id DESC"), MYSQLI_ASSOC);

renderHeader('BoM');
$inp = 'style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px"';
?>
<h2>Resep Manufaktur (BoM)</h2>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($calc): ?>
<div class="panel scroll">
  <strong>Kebutuhan bahan: <?= e($calc['bom_code']) ?></strong> &middot; <?= e($calc['sku'] . ' - ' . $calc['item_name']) ?>
  <form method="get" style="margin:10px 0;display:flex;gap:8px;align-items:center">
    <input type="hidden" name="calc" value="<?= (int)$calc['id'] ?>">
    <label style="margin:0">Rencana produksi</label>
    <input type="number" name="qty" step="0.0001" min="0.0001" value="<?= e(fmtQty($calc['qty'])) ?>" style="max-width:140px"> <?= e($calc['unit_code']) ?>
    <button type="submit">Hitung</button> <a class="btn sec" href="manage_bom.php">Tutup</a>
  </form>
  <table>
    <tr><th>Komponen</th><th>Resep / <?= e(fmtQty($calc['output_batch_size'])) ?> unit</th><th>Scrap</th><th>Kebutuhan</th><th>Stok tersedia</th><th>Status</th><?php if ($showCost): ?><th>Estimasi biaya</th><?php endif; ?></tr>
    <?php $total = 0; $short = false; foreach ($calc['rows'] as $r):
        $need = (float)$r['required_qty'] / (float)$calc['output_batch_size'] * $calc['qty'] * (1 + (float)$r['scrap_tolerance_pct'] / 100);
        $gap = $need - (float)$r['avail']; if ($gap > 0.00005) $short = true;
        $cost = $need * (float)$r['moving_avg_cost_idr']; $total += $cost; ?>
      <tr><td><?= e($r['sku']) ?><br><small><?= e($r['item_name']) ?></small></td>
        <td><?= e(fmtQty($r['required_qty'])) ?> <?= e($r['unit_code']) ?></td><td><?= e(fmtQty($r['scrap_tolerance_pct'])) ?>%</td>
        <td><b><?= e(fmtQty(round($need, 4))) ?></b> <?= e($r['unit_code']) ?></td>
        <td><?= e(fmtQty($r['avail'])) ?></td>
        <td><?= $gap > 0.00005 ? '<span class="tag off" style="background:#fdecea;color:#b3261e">Kurang ' . e(fmtQty(round($gap, 4))) . '</span>' : '<span class="tag">Cukup</span>' ?></td>
        <?php if ($showCost): ?><td><?= e(number_format($cost, 0, ',', '.')) ?></td><?php endif; ?></tr>
    <?php endforeach; ?>
    <?php if ($showCost): ?><tr><td colspan="6" style="text-align:right"><b>Total estimasi bahan (harga rata-rata)</b></td><td><b><?= e(number_format($total, 0, ',', '.')) ?></b></td></tr><?php endif; ?>
  </table>
  <p><?= $short ? '<span class="err" style="display:inline-block">Stok belum cukup untuk rencana ini.</span>' : '<span class="ok" style="display:inline-block">Stok cukup untuk rencana ini.</span>' ?></p>
</div>
<?php endif; ?>

<?php if ($canCreate): ?>
<div class="panel">
  <strong><?= $form['id'] ? 'Ubah BoM' : 'Buat BoM baru' ?></strong>
  <form method="post" autocomplete="off" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
    <div class="grid">
      <div><label>Kode BoM *</label><input type="text" name="bom_code" value="<?= e($form['bom_code']) ?>" placeholder="BOM-FENCE-001" required></div>
      <div><label>Barang jadi / setengah jadi *</label><select name="target_item_id" required><option value="">- pilih -</option>
        <?php foreach ($targets as $t): ?><option value="<?= (int)$t['id'] ?>" <?= (string)$t['id'] === (string)$form['target_item_id'] ? 'selected' : '' ?>><?= e($t['sku'] . ' - ' . $t['item_name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Output per resep (unit) *</label><input type="number" name="output_batch_size" step="0.0001" min="0.0001" value="<?= e($form['output_batch_size']) ?>" required></div>
    </div>
    <table style="margin-top:14px" id="lines">
      <tr><th>Komponen</th><th style="width:150px">Jumlah / resep</th><th style="width:120px">Scrap %</th><th style="width:60px"></th></tr>
      <?php foreach ($form['lines'] as $l): ?>
      <tr><td><select name="comp_item[]"><option value="">-</option>
          <?php foreach ($comps as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (string)$c['id'] === (string)$l[0] ? 'selected' : '' ?>><?= e($c['sku'] . ' - ' . $c['item_name'] . ' (' . $c['unit_code'] . ')') ?></option><?php endforeach; ?></select></td>
        <td><input type="number" name="comp_qty[]" step="0.0001" min="0" value="<?= e($l[1]) ?>"></td>
        <td><input type="number" name="comp_scrap[]" step="0.01" min="0" max="100" value="<?= e($l[2]) ?>"></td>
        <td><button type="button" class="btn danger" onclick="rmLine(this)">&times;</button></td></tr>
      <?php endforeach; ?>
    </table>
    <p style="margin:12px 0 0"><button type="button" class="btn sec" onclick="addLine()">+ Komponen</button>
      <button type="submit">Simpan BoM</button>
      <?php if ($form['id']): ?><a class="btn sec" href="manage_bom.php">Batal</a><?php endif; ?></p>
  </form>
</div>
<script>
function addLine(){var t=document.getElementById('lines');var r=t.rows[t.rows.length-1].cloneNode(true);
  r.querySelectorAll('select').forEach(function(s){s.value='';});
  r.querySelectorAll('input').forEach(function(i){i.value=i.name==='comp_scrap[]'?'3':'';});t.appendChild(r);}
function rmLine(b){var t=document.getElementById('lines');if(t.rows.length>2){b.closest('tr').remove();}}
</script>
<?php endif; ?>

<div class="panel scroll">
  <table>
    <tr><th>Kode</th><th>Barang jadi</th><th>Output/resep</th><th>Komponen</th><th>Status</th><th></th></tr>
    <?php if (!$boms): ?><tr><td colspan="6">Belum ada BoM.</td></tr><?php endif; ?>
    <?php foreach ($boms as $b): ?>
      <tr><td><?= e($b['bom_code']) ?></td><td><?= e($b['sku']) ?><br><small><?= e($b['item_name']) ?></small></td>
        <td><?= e(fmtQty($b['output_batch_size'])) ?> <?= e($b['unit_code']) ?></td><td><?= (int)$b['n'] ?></td>
        <td><span class="tag <?= $b['is_active'] ? '' : 'off' ?>"><?= $b['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
        <td style="white-space:nowrap"><a class="btn sec" href="?calc=<?= (int)$b['id'] ?>">Hitung</a>
          <?php if ($canCreate): ?><a class="btn sec" href="?edit=<?= (int)$b['id'] ?>">Ubah</a><?php endif; ?>
          <?php if ($canApprove): ?><form class="inline" method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$b['id'] ?>"><button class="danger"><?= $b['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php renderFooter();
