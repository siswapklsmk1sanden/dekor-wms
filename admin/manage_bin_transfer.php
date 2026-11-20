<?php
/**
 * Transfer Rak: pindah stok antar rak / zona / gudang. Total saldo tidak berubah;
 * buku besar mencatat pasangan CREDIT_OUT (rak asal) dan DEBIT_IN (rak tujuan).
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/layout.php';
requirePermission('outbound', 'view');
require_once __DIR__ . '/reservation_helper.php';
wmsAutoMaintenance();

$canCreate = canAccess('outbound', 'create');
$csrf      = stockCsrf();
$errors    = [];
$form      = ['src' => '', 'dest_bin_id' => '', 'qty' => '', 'ref' => '', 'notes' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    stockCheckCsrf();
    draftGate('outbound', 'Transfer Rak');
    foreach ($form as $k => $_) $form[$k] = trim((string)($_POST[$k] ?? ''));

    // src = "binId:batchId:itemId"
    $parts = explode(':', $form['src']);
    if (count($parts) !== 3) {
        $errors[] = 'Pilih stok asal (rak + lot).';
    } else {
        [$srcBin, $batchId, $itemId] = array_map('intval', $parts);
        $destBin = (int)$form['dest_bin_id'];
        $qty = $form['qty'];
        if (!is_numeric($qty) || (float)$qty <= 0) $errors[] = 'Jumlah harus lebih dari 0.';
        if (!$destBin) $errors[] = 'Rak tujuan wajib dipilih.';
        if ($destBin === $srcBin) $errors[] = 'Rak tujuan harus berbeda dari rak asal.';

        if (!$errors) {
            try {
                stockBegin();
                // Kunci rak dalam urutan id agar tidak deadlock.
                $ids = [$srcBin, $destBin]; sort($ids);
                $bins = [];
                foreach ($ids as $bid) $bins[$bid] = stockGetBin($bid);

                $st = mysqli_prepare($connect, "SELECT i.*, b.unit_cost_idr, b.qc_status, b.batch_number
                    FROM items i JOIN item_batches b ON b.item_id = i.id AND b.id = ? WHERE i.id = ?");
                mysqli_stmt_bind_param($st, 'ii', $batchId, $itemId);
                mysqli_stmt_execute($st);
                $item = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
                if (!$item) throw new StockException('Lot / barang tidak ditemukan.');

                stockCheckCapacity($bins[$destBin], (float)$qty * (float)$item['unit_cbm']);

                [$trxId, $trxCode] = stockCreateTrx('INTERNAL_BIN_TRANSFER', $form['ref'], $form['notes']);
                $cost = (float)$item['unit_cost_idr'];
                stockMove($trxId, $itemId, $bins[$srcBin], $batchId, -(float)$qty, $cost);
                stockMove($trxId, $itemId, $bins[$destBin], $batchId, (float)$qty, $cost);
                stockCommit();

                logActivity('CREATE', 'Bin Transfer',
                    "Transfer $trxCode: {$item['sku']} lot {$item['batch_number']} x " . fmtQty($qty) .
                    " dari {$bins[$srcBin]['bin_code']} ke {$bins[$destBin]['bin_code']}", $trxId);
                $_SESSION['flash'] = ['ok', "Transfer tercatat: $trxCode."];
                header('Location: manage_bin_transfer.php');
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

$sources = mysqli_fetch_all(mysqli_query($connect, "SELECT sb.bin_id, sb.batch_id, sb.item_id, i.sku, i.item_name,
        b.batch_number, bn.bin_code, u.unit_code, (sb.qty_on_hand - sb.qty_reserved) AS avail
    FROM stock_balances sb JOIN items i ON i.id = sb.item_id JOIN units u ON u.id = i.unit_id
    JOIN item_batches b ON b.id = sb.batch_id JOIN storage_bins bn ON bn.id = sb.bin_id
    WHERE (sb.qty_on_hand - sb.qty_reserved) > 0 AND bn.is_frozen_for_opname = 0 AND bn.is_active = 1
    ORDER BY i.sku, bn.bin_code"), MYSQLI_ASSOC);
$bins = stockBinOptions();
$recent = mysqli_fetch_all(mysqli_query($connect, "SELECT t.id, t.trx_code, t.created_at, t.reference_doc_no, uu.username,
        i.sku, i.item_name, b.batch_number, u.unit_code,
        MAX(CASE WHEN l.entry_direction = 'CREDIT_OUT' THEN bn.bin_code END) AS from_bin,
        MAX(CASE WHEN l.entry_direction = 'DEBIT_IN' THEN bn.bin_code END) AS to_bin,
        MAX(l.signed_qty) AS qty
    FROM inventory_transactions t
    JOIN inventory_ledger_entries l ON l.transaction_id = t.id
    JOIN items i ON i.id = l.item_id JOIN units u ON u.id = i.unit_id
    JOIN item_batches b ON b.id = l.batch_id JOIN storage_bins bn ON bn.id = l.bin_id
    JOIN users uu ON uu.id = t.created_by
    WHERE t.trx_type = 'INTERNAL_BIN_TRANSFER'
    GROUP BY t.id, i.id, b.id ORDER BY t.id DESC LIMIT 20"), MYSQLI_ASSOC);

renderHeader('Transfer Rak');
?>
<h2>Transfer Antar Rak</h2>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($canCreate): ?>
<?php renderScanBox(); ?>
<div class="panel">
  <strong>Pindahkan stok</strong>
  <form method="post" autocomplete="off" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="grid">
      <div><label>Stok asal (barang | lot | rak) *</label>
        <select name="src" required><option value="">- pilih -</option>
        <?php foreach ($sources as $s): $v = $s['bin_id'] . ':' . $s['batch_id'] . ':' . $s['item_id']; ?>
          <option value="<?= e($v) ?>" <?= $v === $form['src'] ? 'selected' : '' ?>><?= e($s['sku'] . ' | ' . $s['batch_number'] . ' | ' . $s['bin_code'] . ' | ' . fmtQty($s['avail']) . ' ' . $s['unit_code']) ?></option>
        <?php endforeach; ?></select></div>
      <div><label>Rak tujuan *</label>
        <select name="dest_bin_id" required><option value="">- pilih -</option>
        <?php foreach ($bins as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (string)$b['id'] === $form['dest_bin_id'] ? 'selected' : '' ?>><?= e($b['bin_code'] . ' - ' . $b['zone_name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label>Jumlah *</label><input type="number" name="qty" step="0.0001" min="0.0001" value="<?= e($form['qty']) ?>" required></div>
      <div><label>No. referensi</label><input type="text" name="ref" maxlength="60" value="<?= e($form['ref']) ?>"></div>
      <div><label>Catatan</label><input type="text" name="notes" maxlength="500" value="<?= e($form['notes']) ?>"></div>
    </div>
    <p style="margin:14px 0 0"><button type="submit"><?= canAccess('outbound', 'approve') ? 'Proses Transfer' : 'Ajukan Draft Transfer' ?></button></p>
  </form>
</div>
<?php endif; ?>

<div class="panel scroll">
  <strong>20 transfer terakhir</strong>
  <table>
    <tr><th>Waktu</th><th>No. Transaksi</th><th>Barang</th><th>Lot</th><th>Dari</th><th>Ke</th><th>Jumlah</th><th>Oleh</th></tr>
    <?php if (!$recent): ?><tr><td colspan="8">Belum ada transfer.</td></tr><?php endif; ?>
    <?php foreach ($recent as $r): ?>
      <tr><td><?= e($r['created_at']) ?></td><td><a href="print_trx.php?code=<?= urlencode($r['trx_code']) ?>" target="_blank" title="Cetak bukti"><?= e($r['trx_code']) ?></a></td>
        <td><?= e($r['sku']) ?><br><small><?= e($r['item_name']) ?></small></td>
        <td><?= e($r['batch_number']) ?></td><td><?= e($r['from_bin']) ?></td><td><?= e($r['to_bin']) ?></td>
        <td><?= e(fmtQty($r['qty'])) ?> <?= e($r['unit_code']) ?></td><td><?= e($r['username']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php renderFooter();
