<?php
/**
 * Reservasi Stok: menahan stok untuk SPK / work order / invoice supaya tidak terpakai pesanan lain.
 *  - Per barang, atau sekaligus dari BoM (semua komponen + toleransi scrap, sama seperti Produksi).
 *  - Stok yang ditahan tampil di kolom "Dipesan" Saldo Stok dan tidak dihitung "tersedia".
 *  - Saat Barang Keluar / Produksi memakai No. SPK yang sama, reservasi otomatis dipakai (terpenuhi).
 *  - Jalur Persetujuan sama dengan Barang Keluar (modul outbound): peran tanpa hak Setujui mengajukan draft.
 *  - Reservasi bisa dilepas (batal) oleh pembuatnya atau pemegang hak Setujui; alasan wajib.
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/reservation_helper.php';
require_once __DIR__ . '/layout.php';
requirePermission('outbound', 'view');
wmsRequireSchemaExt();
wmsAutoMaintenance(true);

$canCreate  = canAccess('outbound', 'create');
$canApprove = canAccess('outbound', 'approve');
$me         = (int)$_SESSION['user_id'];
$csrf       = stockCsrf();
$errors     = [];
$form       = ['mode' => 'item', 'item_id' => '', 'bom_id' => '', 'qty' => '', 'ref' => '', 'needed_date' => '', 'notes' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    stockCheckCsrf();
    $act = (string)($_POST['act'] ?? '');

    // ---------- Lepas reservasi (satu, atau semua milik satu SPK) ----------
    if ($act === 'release' || $act === 'release_ref') {
        requireLogin();
        $reason = trim((string)($_POST['reason'] ?? ''));
        try {
            if (mb_strlen($reason) < 3) throw new StockException('Alasan pelepasan wajib diisi (min. 3 karakter).');
            if (mb_strlen($reason) > 150) throw new StockException('Alasan pelepasan terlalu panjang (maks. 150 karakter).');
            stockBegin();
            $ids = [];
            if ($act === 'release') {
                $ids[] = (int)($_POST['id'] ?? 0);
            } else {
                $refIn = trim((string)($_POST['ref'] ?? ''));
                $q = mysqli_prepare($connect, "SELECT id FROM stock_reservations
                    WHERE status = 'ACTIVE' AND reference_doc_no = ? ORDER BY id");
                mysqli_stmt_bind_param($q, 's', $refIn);
                mysqli_stmt_execute($q);
                foreach (mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC) as $r) $ids[] = (int)$r['id'];
            }
            if (!$ids) throw new StockException('Tidak ada reservasi aktif yang dilepas.');
            $total = 0.0;
            $codes = [];
            foreach ($ids as $rid) {
                [$r, $taken] = rsvRelease($rid, $reason, $me, $canApprove);
                $total   += $taken;
                $codes[]  = $r['res_code'];
            }
            stockCommit();
            logActivity('UPDATE', 'Reservasi Stok', 'Melepas reservasi ' . implode(', ', $codes) . ' (' . fmtQty($total) . '). Alasan: ' . $reason, $ids[0]);
            $_SESSION['flash'] = ['ok', count($codes) . ' reservasi dilepas (' . implode(', ', $codes) . '); stok kembali tersedia.'];
            header('Location: manage_reservation.php');
            exit;
        } catch (StockException $ex) {
            stockRollback();
            $errors[] = $ex->getMessage();
        }
    } else {
        // ---------- Buat reservasi ----------
        draftGate('outbound', 'Reservasi Stok');
        foreach ($form as $k => $_) $form[$k] = trim((string)($_POST[$k] ?? ''));
        $mode = $form['mode'] === 'bom' ? 'bom' : 'item';
        $form['mode'] = $mode;

        if (!is_numeric($form['qty']) || (float)$form['qty'] <= 0) $errors[] = 'Jumlah harus lebih dari 0.';
        if ($form['ref'] === '') $errors[] = 'No. SPK / work order wajib diisi.';
        elseif (mb_strlen($form['ref']) > 60) $errors[] = 'No. SPK / work order terlalu panjang (maks. 60 karakter).';
        if ($form['needed_date'] !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['needed_date'])) $errors[] = 'Format tanggal dibutuhkan tidak valid.';
            elseif ($form['needed_date'] < date('Y-m-d')) $errors[] = 'Tanggal dibutuhkan tidak boleh sebelum hari ini.';
        }
        if ($mode === 'item' && (int)$form['item_id'] <= 0) $errors[] = 'Barang wajib dipilih.';
        if ($mode === 'bom' && (int)$form['bom_id'] <= 0) $errors[] = 'BoM wajib dipilih.';

        if (!$errors) {
            try {
                stockBegin();
                $made  = [];
                $notes = $form['notes'];
                $need  = $form['needed_date'] !== '' ? $form['needed_date'] : null;
                if ($mode === 'item') {
                    $made[] = rsvCreate((int)$form['item_id'], $form['qty'], $form['ref'], $need, $notes, null);
                } else {
                    $bid = (int)$form['bom_id'];
                    $st = mysqli_prepare($connect, "SELECT h.id, h.bom_code, h.output_batch_size FROM bom_headers h
                        JOIN items i ON i.id = h.target_item_id WHERE h.id = ? AND h.is_active = 1 AND i.is_active = 1");
                    mysqli_stmt_bind_param($st, 'i', $bid);
                    mysqli_stmt_execute($st);
                    $bom = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
                    if (!$bom) throw new StockException('BoM aktif tidak ditemukan.');
                    $cs = mysqli_prepare($connect, "SELECT component_item_id, required_qty, scrap_tolerance_pct
                        FROM bom_components WHERE bom_id = ? ORDER BY id");
                    mysqli_stmt_bind_param($cs, 'i', $bid);
                    mysqli_stmt_execute($cs);
                    $comps = mysqli_fetch_all(mysqli_stmt_get_result($cs), MYSQLI_ASSOC);
                    if (!$comps) throw new StockException('BoM tidak memiliki komponen.');
                    $group = 'GRP-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
                    foreach ($comps as $c) {
                        $qtyC = round((float)$c['required_qty'] / (float)$bom['output_batch_size'] * (float)$form['qty']
                                * (1 + (float)$c['scrap_tolerance_pct'] / 100), 4);
                        $made[] = rsvCreate((int)$c['component_item_id'], $qtyC, $form['ref'], $need,
                            trim('BoM ' . $bom['bom_code'] . ' x ' . fmtQty($form['qty']) . ' ' . $notes), $group);
                    }
                }
                stockCommit();
                $codes = array_map(function ($m) { return $m['code']; }, $made);
                $skus  = array_map(function ($m) { return $m['sku']; }, $made);
                logActivity('CREATE', 'Reservasi Stok', 'Reservasi ' . implode(', ', $codes) . ' untuk ' . $form['ref'] . ' (' . implode(', ', $skus) . ')', $made[0]['id']);
                $_SESSION['flash'] = ['ok', 'Reservasi tercatat: ' . implode(', ', $codes) . '. Stok ditahan untuk ' . $form['ref'] . '.'];
                header('Location: manage_reservation.php');
                exit;
            } catch (StockException $ex) {
                stockRollback();
                $errors[] = $ex->getMessage();
            }
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Barang + stok yang masih bebas (sudah dikurangi reservasi lain)
$items = mysqli_fetch_all(mysqli_query($connect, "SELECT i.id, i.sku, i.barcode_ean13, i.item_name, u.unit_code,
        COALESCE(SUM(CASE WHEN b.qc_status = 'PASSED' AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
                          THEN sb.qty_on_hand - sb.qty_reserved END), 0) AS avail
    FROM items i JOIN units u ON u.id = i.unit_id
    LEFT JOIN stock_balances sb ON sb.item_id = i.id
    LEFT JOIN item_batches b ON b.id = sb.batch_id
    WHERE i.is_active = 1 GROUP BY i.id, i.sku, i.barcode_ean13, i.item_name, u.unit_code ORDER BY i.sku"), MYSQLI_ASSOC);
$boms = mysqli_fetch_all(mysqli_query($connect, "SELECT h.id, h.bom_code, i.sku, i.item_name
    FROM bom_headers h JOIN items i ON i.id = h.target_item_id WHERE h.is_active = 1 AND i.is_active = 1 ORDER BY h.bom_code"), MYSQLI_ASSOC);

$active = mysqli_fetch_all(mysqli_query($connect, "SELECT r.*, i.sku, i.item_name, u.unit_code, cu.username,
        (SELECT COUNT(*) FROM stock_reservation_allocs a JOIN item_batches b ON b.id = a.batch_id
          WHERE a.reservation_id = r.id AND (b.qc_status <> 'PASSED' OR (b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE()))) AS bad_alloc
    FROM stock_reservations r JOIN items i ON i.id = r.item_id JOIN units u ON u.id = i.unit_id JOIN users cu ON cu.id = r.created_by
    WHERE r.status = 'ACTIVE' ORDER BY (r.needed_date IS NULL), r.needed_date, r.id"), MYSQLI_ASSOC);

$allocText = [];
if ($active) {
    $idList = implode(',', array_map(function ($r) { return (int)$r['id']; }, $active));   // angka dari database
    $rs = mysqli_query($connect, "SELECT a.reservation_id, bn.bin_code, b.batch_number, a.qty
        FROM stock_reservation_allocs a JOIN storage_bins bn ON bn.id = a.bin_id JOIN item_batches b ON b.id = a.batch_id
        WHERE a.reservation_id IN ($idList) ORDER BY a.id");
    while ($rs && ($a = mysqli_fetch_assoc($rs))) {
        $allocText[(int)$a['reservation_id']][] = $a['bin_code'] . ' / ' . $a['batch_number'] . ' x' . fmtQty($a['qty']);
    }
}
$activeRefs = [];
foreach ($active as $r) $activeRefs[$r['reference_doc_no']] = true;

$hist = mysqli_fetch_all(mysqli_query($connect, "SELECT r.*, i.sku, i.item_name, u.unit_code, cu.username
    FROM stock_reservations r JOIN items i ON i.id = r.item_id JOIN units u ON u.id = i.unit_id JOIN users cu ON cu.id = r.created_by
    WHERE r.status <> 'ACTIVE' ORDER BY r.closed_at DESC, r.id DESC LIMIT 30"), MYSQLI_ASSOC);

renderHeader('Reservasi Stok');
$inp = 'style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px"';
$today = date('Y-m-d');
?>
<h2>Reservasi Stok</h2>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($canCreate): ?>
<?php renderScanBox(); ?>
<div class="panel">
  <strong>Pesan stok untuk SPK / work order</strong>
  <small>(stok ditahan di rak &amp; lot FIFO / FEFO; tidak bisa dipakai pesanan lain sampai dipakai atau dilepas)</small>
  <form method="post" autocomplete="off" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="grid">
      <div><label>Cara memesan *</label>
        <select name="mode" id="rsvMode" <?= $inp ?>>
          <option value="item" <?= $form['mode'] === 'item' ? 'selected' : '' ?>>Per barang</option>
          <option value="bom" <?= $form['mode'] === 'bom' ? 'selected' : '' ?>>Dari BoM (semua komponen)</option>
        </select></div>
      <div id="rsvItemBox"><label>Barang *</label>
        <select name="item_id" <?= $inp ?>><option value="">- pilih -</option>
        <?php foreach ($items as $i): ?><option value="<?= (int)$i['id'] ?>" data-ean="<?= e($i['barcode_ean13'] ?? '') ?>" <?= (string)$i['id'] === $form['item_id'] ? 'selected' : '' ?>><?= e($i['sku'] . ' - ' . $i['item_name'] . ' | bebas ' . fmtQty($i['avail']) . ' ' . $i['unit_code']) ?></option><?php endforeach; ?>
        </select></div>
      <div id="rsvBomBox" style="display:none"><label>BoM *</label>
        <select name="bom_id" <?= $inp ?>><option value="">- pilih -</option>
        <?php foreach ($boms as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (string)$b['id'] === $form['bom_id'] ? 'selected' : '' ?>><?= e($b['bom_code'] . ' - ' . $b['sku'] . ' ' . $b['item_name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label id="rsvQtyLbl">Jumlah *</label><input type="number" name="qty" step="0.0001" min="0.0001" value="<?= e($form['qty']) ?>" required <?= $inp ?>></div>
      <div><label>No. SPK / work order / invoice *</label><input type="text" name="ref" maxlength="60" value="<?= e($form['ref']) ?>" required <?= $inp ?>></div>
      <div><label>Dibutuhkan tanggal (opsional)</label><input type="date" name="needed_date" min="<?= e($today) ?>" value="<?= e($form['needed_date']) ?>" <?= $inp ?>></div>
      <div><label>Catatan</label><input type="text" name="notes" maxlength="200" value="<?= e($form['notes']) ?>" <?= $inp ?>></div>
    </div>
    <p style="margin:14px 0 0"><button type="submit"><?= $canApprove ? 'Pesan Stok' : 'Ajukan Draft Reservasi' ?></button></p>
    <small>Mode BoM: jumlah = jumlah produksi; kebutuhan tiap komponen dihitung dari BoM + toleransi scrap, sama seperti menu Produksi.
      Barang Keluar / Produksi dengan No. SPK yang sama otomatis memakai reservasi ini.</small>
  </form>
</div>
<script>
(function () {
  var m = document.getElementById('rsvMode'), a = document.getElementById('rsvItemBox'), b = document.getElementById('rsvBomBox'), l = document.getElementById('rsvQtyLbl');
  function sync() { var bom = m.value === 'bom'; a.style.display = bom ? 'none' : ''; b.style.display = bom ? '' : 'none'; l.textContent = bom ? 'Jumlah produksi *' : 'Jumlah *'; }
  m.addEventListener('change', sync); sync();
})();
</script>
<?php endif; ?>

<div class="panel scroll">
  <strong>Reservasi aktif</strong> <small>(<?= count($active) ?> reservasi)</small>
  <table>
    <tr><th>Kode</th><th>Referensi</th><th>Barang</th><th>Ditahan</th><th>Rak / Lot</th><th>Dibutuhkan</th><th>Dibuat</th><th>Aksi</th></tr>
    <?php if (!$active): ?><tr><td colspan="8">Belum ada reservasi aktif.</td></tr><?php endif; ?>
    <?php foreach ($active as $r):
        $late = !empty($r['needed_date']) && $r['needed_date'] < $today;
        $mayRelease = $canApprove || (int)$r['created_by'] === $me; ?>
      <tr>
        <td><?= e($r['res_code']) ?><?php if (!empty($r['group_code'])): ?><br><small>kelompok <?= e($r['group_code']) ?></small><?php endif; ?></td>
        <td><b><?= e($r['reference_doc_no']) ?></b><?php if (!empty($r['notes'])): ?><br><small><?= e($r['notes']) ?></small><?php endif; ?></td>
        <td><?= e($r['sku']) ?><br><small><?= e($r['item_name']) ?></small></td>
        <td><?= e(fmtQty($r['qty_open'])) ?> <?= e($r['unit_code']) ?><?php if ((float)$r['qty_open'] + 0.00005 < (float)$r['qty_total']): ?><br><small>dari <?= e(fmtQty($r['qty_total'])) ?></small><?php endif; ?></td>
        <td><small><?= e(implode('; ', $allocText[(int)$r['id']] ?? ['-'])) ?></small>
          <?php if ((int)$r['bad_alloc'] > 0): ?><br><span class="tag off">Ada lot tidak layak (QC / kedaluwarsa): lepas lalu pesan ulang</span><?php endif; ?></td>
        <td><?php if (!empty($r['needed_date'])): ?><?= e($r['needed_date']) ?><?php if ($late): ?><br><span class="tag off">Lewat <?= (int)((strtotime($today) - strtotime($r['needed_date'])) / 86400) ?> hari</span><?php endif; ?><br><small>dilepas otomatis mulai <?= e(date('Y-m-d', strtotime($r['needed_date'] . ' +' . (RSV_AUTO_RELEASE_GRACE_DAYS + 1) . ' days'))) ?></small><?php else: ?>-<?php endif; ?></td>
        <td><?= e(substr((string)$r['created_at'], 0, 16)) ?><br><small><?= e($r['username']) ?></small></td>
        <td style="min-width:190px">
          <?php if ($mayRelease): ?>
          <form method="post" class="inline" style="display:block">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="act" value="release">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="text" name="reason" maxlength="150" placeholder="Alasan lepas *" required style="width:120px;padding:6px">
            <button type="submit" class="danger" onclick="return confirm('Lepas reservasi <?= e($r['res_code']) ?>? Stok kembali tersedia.')">Lepas</button>
          </form>
          <?php else: ?>-<?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php if ($active && ($canApprove || $canCreate)): ?>
  <form method="post" style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="act" value="release_ref">
    <small>Lepas semua reservasi satu SPK:</small>
    <select name="ref" style="max-width:240px" required>
      <option value="">- pilih SPK -</option>
      <?php foreach (array_keys($activeRefs) as $rf): ?><option value="<?= e($rf) ?>"><?= e($rf) ?></option><?php endforeach; ?>
    </select>
    <input type="text" name="reason" maxlength="150" placeholder="Alasan *" required style="max-width:240px">
    <button type="submit" class="danger" onclick="return confirm('Lepas semua reservasi aktif untuk SPK ini?')">Lepas Semua</button>
  </form>
  <?php endif; ?>
</div>

<div class="panel scroll">
  <strong>Riwayat reservasi</strong> <small>(30 terakhir yang sudah ditutup)</small>
  <table>
    <tr><th>Kode</th><th>Referensi</th><th>Barang</th><th>Jumlah</th><th>Status</th><th>Ditutup</th><th>Keterangan</th></tr>
    <?php if (!$hist): ?><tr><td colspan="7">Belum ada riwayat.</td></tr><?php endif; ?>
    <?php foreach ($hist as $r): ?>
      <tr><td><?= e($r['res_code']) ?></td><td><?= e($r['reference_doc_no']) ?></td>
        <td><?= e($r['sku']) ?><br><small><?= e($r['item_name']) ?></small></td>
        <td><?= e(fmtQty($r['qty_total'])) ?> <?= e($r['unit_code']) ?></td>
        <td><span class="tag <?= $r['status'] === 'FULFILLED' ? '' : 'off' ?>"><?= $r['status'] === 'FULFILLED' ? 'Terpenuhi' : 'Dilepas' ?></span></td>
        <td><?= e(substr((string)$r['closed_at'], 0, 16)) ?></td>
        <td><small><?= e($r['close_note'] ?? '') ?></small></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php renderFooter();
