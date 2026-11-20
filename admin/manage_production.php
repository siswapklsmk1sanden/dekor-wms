<?php
/**
 * Posting Produksi: memotong komponen sesuai BoM aktif (termasuk toleransi scrap,
 * alokasi FIFO/FEFO) lalu memasukkan barang jadi ke rak gudang barang jadi sebagai lot baru.
 * Mencatat dua transaksi dalam satu transaksi database:
 *   OUTBOUND_PRODUCTION (komponen keluar) + PRODUCTION_OUTPUT (barang jadi masuk).
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/reservation_helper.php';
require_once __DIR__ . '/layout.php';
requirePermission('bom', 'view');
wmsEnsureSchemaExt();   // tabel reservasi (v38); tidak fatal bila gagal

$canCreate = canAccess('bom', 'create');
$csrf      = stockCsrf();
$errors    = [];
$form      = ['bom_id' => '', 'qty' => '', 'bin_id' => '', 'ref' => '', 'notes' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    stockCheckCsrf();
    draftGate('bom', 'Produksi');
    foreach ($form as $k => $_) $form[$k] = trim((string)($_POST[$k] ?? ''));
    $bomId = (int)$form['bom_id'];
    $binId = (int)$form['bin_id'];
    $qty   = $form['qty'];

    $st = mysqli_prepare($connect, "SELECT h.*, i.sku, i.item_name, i.unit_cbm FROM bom_headers h
        JOIN items i ON i.id = h.target_item_id WHERE h.id = ? AND h.is_active = 1 AND i.is_active = 1");
    mysqli_stmt_bind_param($st, 'i', $bomId);
    mysqli_stmt_execute($st);
    $bom = mysqli_fetch_assoc(mysqli_stmt_get_result($st));

    if (!$bom) $errors[] = 'BoM aktif wajib dipilih.';
    if (!is_numeric($qty) || (float)$qty <= 0) $errors[] = 'Jumlah produksi harus lebih dari 0.';
    if (!$binId) $errors[] = 'Rak barang jadi wajib dipilih.';
    if ($form['ref'] === '') $errors[] = 'No. SPK / work order wajib diisi.';

    if (!$errors) {
        try {
            stockBegin();
            $outBin = stockGetBin($binId);
            $wt = mysqli_prepare($connect, "SELECT warehouse_type FROM warehouses WHERE id = ?");
            $wid = (int)$outBin['warehouse_id'];
            mysqli_stmt_bind_param($wt, 'i', $wid);
            mysqli_stmt_execute($wt);
            if (mysqli_fetch_row(mysqli_stmt_get_result($wt))[0] !== 'FINISHED_GOODS') {
                throw new StockException('Barang jadi harus masuk ke rak gudang barang jadi.');
            }
            stockCheckCapacity($outBin, (float)$qty * (float)$bom['unit_cbm']);

            $st = mysqli_prepare($connect, "SELECT c.component_item_id, c.required_qty, c.scrap_tolerance_pct,
                    i.sku, cat.allocation_strategy
                FROM bom_components c JOIN items i ON i.id = c.component_item_id
                JOIN categories cat ON cat.id = i.category_id WHERE c.bom_id = ? ORDER BY c.id");
            mysqli_stmt_bind_param($st, 'i', $bomId);
            mysqli_stmt_execute($st);
            $lines = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
            if (!$lines) throw new StockException('BoM tidak memiliki komponen.');

            [$issueId, $issueCode] = stockCreateTrx('OUTBOUND_PRODUCTION', $form['ref'],
                "Bahan produksi {$bom['bom_code']} x " . fmtQty($qty) . ($form['notes'] !== '' ? ' - ' . $form['notes'] : ''));
            $totalCost = 0.0;
            $summary = [];
            foreach ($lines as $l) {
                $need = round((float)$l['required_qty'] / (float)$bom['output_batch_size'] * (float)$qty
                        * (1 + (float)$l['scrap_tolerance_pct'] / 100), 4);
                try {
                    // Reservasi komponen untuk No. SPK yang sama dipakai dulu
                    rsvConsume((int)$l['component_item_id'], $form['ref'], $need, 'Produksi');
                    $plan = planAllocation((int)$l['component_item_id'], $need, $l['allocation_strategy']);
                } catch (StockException $ex) {
                    throw new StockException("Komponen {$l['sku']} (butuh " . fmtQty($need) . '): ' . $ex->getMessage());
                }
                foreach ($plan as $p) {
                    $bin = stockGetBin((int)$p['bin_id']);
                    stockMove($issueId, (int)$l['component_item_id'], $bin, (int)$p['batch_id'], -$p['take'], (float)$p['unit_cost_idr']);
                    $totalCost += $p['take'] * (float)$p['unit_cost_idr'];
                }
                $summary[] = $l['sku'] . ' x' . fmtQty($need);
            }

            $unitCost = round($totalCost / (float)$qty, 4);
            $batchNo = 'PRD-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
            $ib = mysqli_prepare($connect, "INSERT INTO item_batches (item_id, batch_number, received_date, unit_cost_idr, qc_status)
                VALUES (?, ?, CURDATE(), ?, 'PASSED')");
            $tid = (int)$bom['target_item_id'];
            $ucS = number_format($unitCost, 4, '.', '');
            mysqli_stmt_bind_param($ib, 'iss', $tid, $batchNo, $ucS);
            if (!mysqli_stmt_execute($ib)) throw new StockException('Gagal membuat lot hasil produksi: ' . mysqli_error($connect));
            $batchId = mysqli_insert_id($connect);

            [$outId, $outCode] = stockCreateTrx('PRODUCTION_OUTPUT', $form['ref'], "Hasil produksi {$bom['bom_code']}, lot $batchNo");
            stockMove($outId, $tid, $outBin, $batchId, (float)$qty, $unitCost);
            stockUpdateMovingAvg($tid, (float)$qty, $unitCost);
            stockCommit();

            logActivity('CREATE', 'Production',
                "Produksi {$bom['bom_code']} x " . fmtQty($qty) . " ($issueCode -> $outCode), lot $batchNo ke {$outBin['bin_code']}; bahan: " . implode(', ', $summary), $outId);
            $_SESSION['flash'] = ['ok', "Produksi diposting: $outCode (lot $batchNo), bahan keluar $issueCode."];
            header('Location: manage_production.php');
            exit;
        } catch (StockException $ex) {
            stockRollback();
            $errors[] = $ex->getMessage();
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$boms = mysqli_fetch_all(mysqli_query($connect, "SELECT h.id, h.bom_code, h.output_batch_size, i.sku, i.item_name
    FROM bom_headers h JOIN items i ON i.id = h.target_item_id WHERE h.is_active = 1 ORDER BY h.bom_code"), MYSQLI_ASSOC);
$bins = mysqli_fetch_all(mysqli_query($connect, "SELECT b.id, b.bin_code, b.zone_name FROM storage_bins b
    JOIN warehouses w ON w.id = b.warehouse_id
    WHERE b.is_active = 1 AND w.is_active = 1 AND b.is_frozen_for_opname = 0 AND w.warehouse_type = 'FINISHED_GOODS'
    ORDER BY b.bin_code"), MYSQLI_ASSOC);
$recent = mysqli_fetch_all(mysqli_query($connect, "SELECT t.trx_code, t.created_at, t.reference_doc_no, t.notes,
        i.sku, i.item_name, b.batch_number, bn.bin_code, l.signed_qty, u.unit_code, uu.username
    FROM inventory_transactions t JOIN inventory_ledger_entries l ON l.transaction_id = t.id
    JOIN items i ON i.id = l.item_id JOIN units u ON u.id = i.unit_id
    JOIN item_batches b ON b.id = l.batch_id JOIN storage_bins bn ON bn.id = l.bin_id
    JOIN users uu ON uu.id = t.created_by
    WHERE t.trx_type = 'PRODUCTION_OUTPUT' ORDER BY t.id DESC LIMIT 20"), MYSQLI_ASSOC);

renderHeader('Produksi');
?>
<h2>Posting Produksi</h2>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($canCreate): ?>
<div class="panel">
  <strong>Catat hasil produksi</strong> <small>(komponen otomatis dipotong sesuai BoM + scrap; cek kebutuhan lewat tombol "Hitung" di menu BoM)</small>
  <form method="post" autocomplete="off" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="grid">
      <div><label>BoM aktif *</label><select name="bom_id" required><option value="">- pilih -</option>
        <?php foreach ($boms as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (string)$b['id'] === $form['bom_id'] ? 'selected' : '' ?>><?= e($b['bom_code'] . ' - ' . $b['sku'] . ' ' . $b['item_name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Jumlah jadi *</label><input type="number" name="qty" step="0.0001" min="0.0001" value="<?= e($form['qty']) ?>" required></div>
      <div><label>Rak barang jadi *</label><select name="bin_id" required><option value="">- pilih -</option>
        <?php foreach ($bins as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (string)$b['id'] === $form['bin_id'] ? 'selected' : '' ?>><?= e($b['bin_code'] . ' - ' . $b['zone_name']) ?></option><?php endforeach; ?></select></div>
      <div><label>No. SPK / work order *</label><input type="text" name="ref" maxlength="60" value="<?= e($form['ref']) ?>" required></div>
      <div><label>Catatan</label><input type="text" name="notes" maxlength="300" value="<?= e($form['notes']) ?>"></div>
    </div>
    <p style="margin:14px 0 0"><button type="submit" onclick="return confirm('Posting produksi? Komponen akan dipotong dari stok.')"><?= canAccess('bom', 'approve') ? 'Posting Produksi' : 'Ajukan Draft Produksi' ?></button></p>
  </form>
</div>
<?php endif; ?>

<div class="panel scroll">
  <strong>20 hasil produksi terakhir</strong>
  <table>
    <tr><th>Waktu</th><th>No. Transaksi</th><th>Barang jadi</th><th>Lot</th><th>Rak</th><th>Jumlah</th><th>SPK</th><th>Oleh</th></tr>
    <?php if (!$recent): ?><tr><td colspan="8">Belum ada produksi.</td></tr><?php endif; ?>
    <?php foreach ($recent as $r): ?>
      <tr><td><?= e($r['created_at']) ?></td><td><a href="print_trx.php?code=<?= urlencode($r['trx_code']) ?>" target="_blank" title="Cetak bukti"><?= e($r['trx_code']) ?></a></td>
        <td><?= e($r['sku']) ?><br><small><?= e($r['item_name']) ?></small></td><td><?= e($r['batch_number']) ?></td><td><?= e($r['bin_code']) ?></td>
        <td><?= e(fmtQty($r['signed_qty'])) ?> <?= e($r['unit_code']) ?></td><td><?= e($r['reference_doc_no']) ?></td><td><?= e($r['username']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php renderFooter();
