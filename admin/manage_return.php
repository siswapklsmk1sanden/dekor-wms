<?php
/**
 * Retur ke Supplier: lot yang DITOLAK di Karantina QC dikembalikan ke supplier.
 *  - Stok lot turun lewat Buku Besar (sisi KREDIT, jenis SCRAP_WRITE_OFF dengan referensi RTR-xxxx),
 *    memakai pengunci stok dan validasi yang sama seperti transaksi lain.
 *  - Jalur Persetujuan: pemegang hak Buat pada modul Barang Masuk hanya mengajukan draft;
 *    pemegang hak Setujui langsung memposting, atau menyetujui draft di menu Persetujuan.
 *  - Setelah posting tersedia Surat Retur yang bisa dicetak (print_return.php).
 * Tidak ada perubahan database: retur dikenali dari referensi "RTR-" pada transaksi.
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/payable_helper.php';
require_once __DIR__ . '/layout.php';
requirePermission('inbound', 'view');
$hasPay = wmsEnsureSchemaExt();   // tabel kredit supplier (v38)

$canCreate = canAccess('inbound', 'create');
$csrf      = stockCsrf();
$errors    = [];
$form      = ['batch_id' => (string)(int)($_GET['batch'] ?? 0), 'qty' => '', 'doc_no' => '', 'reason' => ''];
if ($form['batch_id'] === '0') $form['batch_id'] = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    stockCheckCsrf();
    draftGate('inbound', 'Retur Supplier');
    foreach ($form as $k => $_) $form[$k] = trim((string)($_POST[$k] ?? ''));

    $bid = (int)$form['batch_id'];
    if (!$bid) $errors[] = 'Pilih lot yang akan diretur.';
    if ($form['qty'] !== '' && (!is_numeric($form['qty']) || (float)$form['qty'] <= 0)) $errors[] = 'Jumlah retur harus lebih dari 0 (kosongkan untuk seluruh stok lot).';
    if (mb_strlen($form['reason']) < 3) $errors[] = 'Alasan retur wajib diisi.';
    if (mb_strlen($form['reason']) > 200) $errors[] = 'Alasan retur terlalu panjang (maks. 200 karakter).';
    if (mb_strlen($form['doc_no']) > 40) $errors[] = 'No. dokumen terlalu panjang (maks. 40 karakter).';

    if (!$errors) {
        try {
            stockBegin();
            $st = mysqli_prepare($connect, "SELECT b.*, i.sku, i.item_name, s.company_name
                FROM item_batches b JOIN items i ON i.id = b.item_id LEFT JOIN suppliers s ON s.id = b.supplier_id
                WHERE b.id = ? FOR UPDATE");
            mysqli_stmt_bind_param($st, 'i', $bid);
            mysqli_stmt_execute($st);
            $b = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
            if (!$b) throw new StockException('Lot tidak ditemukan.');
            if ($b['qc_status'] !== 'REJECTED') throw new StockException('Retur hanya untuk lot yang ditolak QC.');
            if (empty($b['supplier_id'])) throw new StockException('Lot ini tidak punya supplier; tidak bisa diretur. Gunakan Scrap di Karantina QC.');

            $q = mysqli_prepare($connect, "SELECT bin_id, qty_on_hand, qty_reserved FROM stock_balances
                WHERE batch_id = ? AND qty_on_hand > 0 ORDER BY bin_id");
            mysqli_stmt_bind_param($q, 'i', $bid);
            mysqli_stmt_execute($q);
            $bal = mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC);
            $total = 0.0;
            foreach ($bal as $r) $total += (float)$r['qty_on_hand'];
            if ($total <= 0) throw new StockException('Lot ini tidak punya stok untuk diretur.');

            $want = $form['qty'] === '' ? $total : round((float)$form['qty'], 4);
            if ($want > $total + 0.00005) throw new StockException('Jumlah retur (' . fmtQty($want) . ') melebihi stok lot (' . fmtQty($total) . ').');

            $label = "{$b['sku']} lot {$b['batch_number']}";
            $ref   = 'RTR-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
            $notes = "Retur ke {$b['company_name']}: $label. Alasan: {$form['reason']}" . ($form['doc_no'] !== '' ? ". No. dok: {$form['doc_no']}" : '');
            [$trxId, $trxCode] = stockCreateTrx('SCRAP_WRITE_OFF', $ref, mb_substr($notes, 0, 500));

            $left = $want; $done = 0.0;
            foreach ($bal as $r) {
                if ($left <= 0.00005) break;
                $take = min($left, (float)$r['qty_on_hand']);
                $bin = stockGetBin((int)$r['bin_id']);
                stockMove($trxId, (int)$b['item_id'], $bin, $bid, -$take, (float)$b['unit_cost_idr']);
                $left -= $take; $done += $take;
            }
            // Kredit / klaim ke supplier (nilai = jumlah x harga lot); Finance menyesuaikannya di Hutang Supplier
            $krd = '';
            if ($hasPay) {
                try {
                    $krd = payCreateReturnCredit((int)$b['supplier_id'], $trxId, $ref, (int)$b['item_id'], $bid, $done, (float)$b['unit_cost_idr'], mb_substr('Retur: ' . $form['reason'], 0, 280));
                } catch (Throwable $exCr) {
                    $krd = '';
                }
            }
            stockCommit();
            logActivity('CREATE', 'Retur Supplier', "Retur $trxCode ($ref): $label x " . fmtQty($done) . " ke {$b['company_name']}. Alasan: {$form['reason']}", $trxId);
            $_SESSION['flash'] = ['ok', "Retur $ref tercatat: " . fmtQty($done) . " dari $label keluar dari stok." . ($krd !== '' ? " Kredit supplier $krd tercatat (lihat Hutang Supplier)." : ''), $trxCode];
            header('Location: manage_return.php');
            exit;
        } catch (StockException $ex) {
            stockRollback();
            $errors[] = $ex->getMessage();
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Lot ditolak yang masih ada stoknya
$lots = mysqli_fetch_all(mysqli_query($connect, "SELECT b.id, b.batch_number, b.received_date, i.sku, i.item_name, u.unit_code,
        s.company_name, COALESCE(SUM(sb.qty_on_hand), 0) AS qty
    FROM item_batches b JOIN items i ON i.id = b.item_id JOIN units u ON u.id = i.unit_id
    LEFT JOIN suppliers s ON s.id = b.supplier_id
    JOIN stock_balances sb ON sb.batch_id = b.id AND sb.qty_on_hand > 0
    WHERE b.qc_status = 'REJECTED'
    GROUP BY b.id, b.batch_number, b.received_date, i.sku, i.item_name, u.unit_code, s.company_name
    ORDER BY b.received_date, b.id"), MYSQLI_ASSOC);

// Riwayat retur
$hist = mysqli_fetch_all(mysqli_query($connect, "SELECT t.trx_code, t.reference_doc_no, t.created_at, i.sku, i.item_name, u.unit_code,
        b.batch_number, s.company_name, SUM(-l.signed_qty) AS qty
    FROM inventory_transactions t
    JOIN inventory_ledger_entries l ON l.transaction_id = t.id
    JOIN items i ON i.id = l.item_id JOIN units u ON u.id = i.unit_id
    JOIN item_batches b ON b.id = l.batch_id LEFT JOIN suppliers s ON s.id = b.supplier_id
    WHERE t.trx_type = 'SCRAP_WRITE_OFF' AND t.reference_doc_no LIKE 'RTR-%'
    GROUP BY t.id, t.trx_code, t.reference_doc_no, t.created_at, i.sku, i.item_name, u.unit_code, b.batch_number, s.company_name
    ORDER BY t.id DESC LIMIT 30"), MYSQLI_ASSOC);

renderHeader('Retur Supplier');
$inp = 'style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px"';
?>
<h2>Retur ke Supplier</h2>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?>
  <?php if (!empty($flash[2])): ?> <a href="print_return.php?code=<?= urlencode($flash[2]) ?>" target="_blank"><b>Cetak surat retur</b></a><?php endif; ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($canCreate): ?>
<div class="panel">
  <strong>Retur lot yang ditolak QC</strong>
  <?php if (!$lots): ?>
    <p style="margin:10px 0 0">Tidak ada lot ditolak yang masih punya stok. Lot ditolak muncul setelah diputuskan di <a href="manage_qc.php">Karantina QC</a>.</p>
  <?php else: ?>
  <form method="post" autocomplete="off" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="grid">
      <div><label>Lot ditolak *</label>
        <select name="batch_id" required>
          <option value="">- pilih lot -</option>
          <?php foreach ($lots as $l): ?>
            <option value="<?= (int)$l['id'] ?>" <?= (string)$l['id'] === $form['batch_id'] ? 'selected' : '' ?>><?= e($l['sku'] . ' / ' . $l['batch_number'] . ' / ' . ($l['company_name'] ?? 'tanpa supplier') . ' / stok ' . fmtQty($l['qty']) . ' ' . $l['unit_code']) ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label>Jumlah retur</label><input type="number" name="qty" step="0.0001" min="0.0001" value="<?= e($form['qty']) ?>" placeholder="kosong = seluruh stok lot" <?= $inp ?>></div>
      <div><label>No. dokumen supplier (opsional)</label><input type="text" name="doc_no" maxlength="40" value="<?= e($form['doc_no']) ?>" placeholder="mis. No. surat jalan / nota retur" <?= $inp ?>></div>
      <div><label>Alasan retur *</label><input type="text" name="reason" maxlength="200" value="<?= e($form['reason']) ?>" required placeholder="mis. MC kayu 18%, di atas batas" <?= $inp ?>></div>
    </div>
    <p style="margin:12px 0 0"><button type="submit"><?= canAccess('inbound', 'approve') ? 'Proses Retur' : 'Ajukan Draft Retur' ?></button></p>
    <small>Stok lot baru berkurang setelah retur diposting (langsung bila Anda pemegang hak Setujui, atau setelah draft disetujui di menu Persetujuan).</small>
  </form>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="panel scroll">
  <strong>Riwayat retur</strong> <small>(30 terakhir)</small>
  <table>
    <tr><th>Waktu</th><th>No. Retur</th><th>Supplier</th><th>Barang</th><th>Lot</th><th>Jumlah</th><th>Surat</th></tr>
    <?php if (!$hist): ?><tr><td colspan="7">Belum ada retur.</td></tr><?php endif; ?>
    <?php foreach ($hist as $h): ?>
      <tr><td><?= e($h['created_at']) ?></td>
        <td><?= e($h['reference_doc_no']) ?><br><small><a href="print_trx.php?code=<?= urlencode($h['trx_code']) ?>" target="_blank"><?= e($h['trx_code']) ?></a></small></td>
        <td><?= e($h['company_name'] ?? '-') ?></td>
        <td><?= e($h['sku']) ?><br><small><?= e($h['item_name']) ?></small></td>
        <td><?= e($h['batch_number']) ?></td>
        <td><?= e(fmtQty($h['qty'])) ?> <?= e($h['unit_code']) ?></td>
        <td><a class="btn sec" style="padding:3px 10px" href="print_return.php?code=<?= urlencode($h['trx_code']) ?>" target="_blank">Cetak</a></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php renderFooter();
