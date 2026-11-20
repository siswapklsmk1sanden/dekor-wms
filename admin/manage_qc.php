<?php
/**
 * Karantina & Keputusan QC lot.
 *   HOLD_QUARANTINE -> PASSED   (lulus; kayu wajib MC <= 12%, bahan kimia belum kedaluwarsa)
 *   HOLD_QUARANTINE -> REJECTED (tolak, alasan wajib)
 *   PASSED          -> HOLD_QUARANTINE (tahan ulang, alasan wajib)
 *   REJECTED        -> scrap: seluruh stok lot dihapus lewat transaksi SCRAP_WRITE_OFF
 * Melihat: hak inbound/view. Memutuskan: hak inbound/approve. Semua keputusan masuk Audit Log.
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/layout.php';
requirePermission('inbound', 'view');

$canDecide = canAccess('inbound', 'approve');
$csrf      = stockCsrf();
$errors    = [];

function qcRedirect($type, $msg)
{
    $_SESSION['flash'] = [$type, $msg];
    header('Location: manage_qc.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    stockCheckCsrf();
    requirePermission('inbound', 'approve');
    $act    = $_POST['act'] ?? '';
    $bid    = (int)($_POST['batch_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $mcIn   = trim($_POST['moisture'] ?? '');

    try {
        stockBegin();
        $st = mysqli_prepare($connect, "SELECT b.*, i.sku, i.item_name, c.category_type, c.requires_expiry_date
            FROM item_batches b JOIN items i ON i.id = b.item_id JOIN categories c ON c.id = i.category_id
            WHERE b.id = ? FOR UPDATE");
        mysqli_stmt_bind_param($st, 'i', $bid);
        mysqli_stmt_execute($st);
        $b = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        if (!$b) throw new StockException('Lot tidak ditemukan.');
        $label = "{$b['sku']} lot {$b['batch_number']}";
        $from  = $b['qc_status'];

        $setStatus = function ($to, $mc = null) use ($connect, $bid) {
            if ($mc !== null) {
                $mcS = number_format($mc, 2, '.', '');
                $u = mysqli_prepare($connect, "UPDATE item_batches SET qc_status = ?, moisture_content_pct = ? WHERE id = ?");
                mysqli_stmt_bind_param($u, 'ssi', $to, $mcS, $bid);
            } else {
                $u = mysqli_prepare($connect, "UPDATE item_batches SET qc_status = ? WHERE id = ?");
                mysqli_stmt_bind_param($u, 'si', $to, $bid);
            }
            mysqli_stmt_execute($u);
        };

        if ($act === 'release') {
            if ($from !== 'HOLD_QUARANTINE') throw new StockException('Hanya lot karantina yang bisa diluluskan.');
            $mc = $b['moisture_content_pct'] !== null ? (float)$b['moisture_content_pct'] : null;
            if ($mcIn !== '') {
                if (!is_numeric($mcIn) || $mcIn < 0 || $mcIn > 100) throw new StockException('Kadar air (MC) harus 0 - 100.');
                $mc = (float)$mcIn;
            }
            if ($b['category_type'] === 'RAW_MATERIAL') {
                if ($mc === null) throw new StockException('MC wajib diukur ulang untuk meluluskan kayu.');
                if ($mc > 12) throw new StockException('MC ' . fmtQty($mc) . '% masih di atas 12%; belum boleh lulus.');
            }
            if ($b['requires_expiry_date'] && $b['expiry_date'] && $b['expiry_date'] < date('Y-m-d')) {
                throw new StockException('Lot sudah kedaluwarsa; tidak dapat diluluskan.');
            }
            $setStatus('PASSED', $mcIn !== '' ? $mc : null);
            // stok lot ini kini ikut hitungan harga rata-rata
            $q = mysqli_prepare($connect, "SELECT COALESCE(SUM(qty_on_hand),0) FROM stock_balances WHERE batch_id = ?");
            mysqli_stmt_bind_param($q, 'i', $bid);
            mysqli_stmt_execute($q);
            $qty = (float)mysqli_fetch_row(mysqli_stmt_get_result($q))[0];
            if ($qty > 0) stockUpdateMovingAvg((int)$b['item_id'], $qty, (float)$b['unit_cost_idr']);
            $msg = "Lot $label lulus QC.";
            $audit = "QC lulus: $label" . ($mcIn !== '' ? ", MC ukur ulang {$mcIn}%" : '') . ($reason !== '' ? ". Catatan: $reason" : '');
        } elseif ($act === 'reject') {
            if ($from !== 'HOLD_QUARANTINE') throw new StockException('Hanya lot karantina yang bisa ditolak.');
            if (mb_strlen($reason) < 3) throw new StockException('Alasan penolakan wajib diisi.');
            $setStatus('REJECTED');
            $msg = "Lot $label ditolak.";
            $audit = "QC tolak: $label. Alasan: $reason";
        } elseif ($act === 'hold') {
            if ($from !== 'PASSED') throw new StockException('Hanya lot lulus QC yang bisa ditahan.');
            if (mb_strlen($reason) < 3) throw new StockException('Alasan menahan lot wajib diisi.');
            $setStatus('HOLD_QUARANTINE');
            $msg = "Lot $label ditahan ke karantina.";
            $audit = "QC tahan ulang: $label. Alasan: $reason";
        } elseif ($act === 'scrap') {
            if ($from !== 'REJECTED') throw new StockException('Scrap hanya untuk lot yang ditolak QC.');
            if (mb_strlen($reason) < 3) throw new StockException('Alasan / referensi scrap wajib diisi.');
            $q = mysqli_prepare($connect, "SELECT bin_id, qty_on_hand FROM stock_balances WHERE batch_id = ? AND qty_on_hand > 0 ORDER BY bin_id");
            mysqli_stmt_bind_param($q, 'i', $bid);
            mysqli_stmt_execute($q);
            $bal = mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC);
            if (!$bal) throw new StockException('Lot ini tidak punya stok untuk di-scrap.');
            [$trxId, $trxCode] = stockCreateTrx('SCRAP_WRITE_OFF', mb_substr($reason, 0, 60), "Scrap lot QC ditolak: $label");
            $tot = 0.0;
            foreach ($bal as $r) {
                $bin = stockGetBin((int)$r['bin_id']);
                stockMove($trxId, (int)$b['item_id'], $bin, $bid, -(float)$r['qty_on_hand'], (float)$b['unit_cost_idr']);
                $tot += (float)$r['qty_on_hand'];
            }
            $msg = "Scrap $trxCode: " . fmtQty($tot) . " dari $label dihapus dari stok.";
            $audit = "QC scrap $trxCode: $label x " . fmtQty($tot) . ". Alasan: $reason";
        } else {
            throw new StockException('Aksi tidak dikenal.');
        }
        stockCommit();
        logActivity('UPDATE', 'Qc', $audit, $bid);
        qcRedirect('ok', $msg);
    } catch (StockException $ex) {
        stockRollback();
        $errors[] = $ex->getMessage();
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$lotSql = "SELECT b.id, b.batch_number, b.received_date, b.expiry_date, b.moisture_content_pct, b.qc_status,
        i.sku, i.item_name, u.unit_code, c.category_type, s.company_name,
        COALESCE(SUM(sb.qty_on_hand), 0) AS qty, GROUP_CONCAT(DISTINCT bn.bin_code ORDER BY bn.bin_code SEPARATOR ', ') AS bins,
        DATEDIFF(CURDATE(), b.received_date) AS age
    FROM item_batches b JOIN items i ON i.id = b.item_id JOIN units u ON u.id = i.unit_id
    JOIN categories c ON c.id = i.category_id LEFT JOIN suppliers s ON s.id = b.supplier_id
    JOIN stock_balances sb ON sb.batch_id = b.id AND sb.qty_on_hand > 0 JOIN storage_bins bn ON bn.id = sb.bin_id
    WHERE b.qc_status %s GROUP BY b.id ORDER BY b.received_date ASC, b.id ASC";
$pending = mysqli_fetch_all(mysqli_query($connect, sprintf($lotSql, "IN ('HOLD_QUARANTINE','REJECTED')")), MYSQLI_ASSOC);
$passed  = $canDecide ? mysqli_fetch_all(mysqli_query($connect, sprintf($lotSql, "= 'PASSED'") . " LIMIT 100"), MYSQLI_ASSOC) : [];

renderHeader('Karantina QC');
$inp = 'style="padding:6px;border:1px solid #ccc;border-radius:6px"';
?>
<h2>Karantina &amp; Keputusan QC</h2>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>

<div class="panel scroll">
  <strong>Lot ditahan / ditolak (masih ada stok)</strong>
  <table>
    <tr><th>Barang</th><th>Lot</th><th>Supplier</th><th>MC</th><th>Stok</th><th>Rak</th><th>Umur</th><th>Status</th><?php if ($canDecide): ?><th>Keputusan</th><?php endif; ?></tr>
    <?php if (!$pending): ?><tr><td colspan="<?= $canDecide ? 9 : 8 ?>">Tidak ada lot di karantina.</td></tr><?php endif; ?>
    <?php foreach ($pending as $r): $hold = $r['qc_status'] === 'HOLD_QUARANTINE'; ?>
      <tr><td><?= e($r['sku']) ?><br><small><?= e($r['item_name']) ?></small></td><td><?= e($r['batch_number']) ?></td>
        <td><?= e($r['company_name'] ?? '-') ?></td>
        <td><?php if ($r['moisture_content_pct'] !== null): $hi = (float)$r['moisture_content_pct'] > 12; ?><span style="color:<?= $hi ? '#b3261e' : 'inherit' ?>"><?= e(fmtQty($r['moisture_content_pct'])) ?>%</span><?php else: ?>-<?php endif; ?></td>
        <td><?= e(fmtQty($r['qty'])) ?> <?= e($r['unit_code']) ?></td><td><small><?= e($r['bins']) ?></small></td><td><?= (int)$r['age'] ?> hr</td>
        <td><span class="tag off" style="<?= $hold ? 'background:#fff4e0;color:#8a5a00' : 'background:#fdecea;color:#b3261e' ?>"><?= e($r['qc_status']) ?></span></td>
        <?php if ($canDecide): ?><td>
          <form method="post" style="display:flex;gap:4px;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="batch_id" value="<?= (int)$r['id'] ?>">
            <?php if ($hold && $r['category_type'] === 'RAW_MATERIAL'): ?><input type="number" name="moisture" step="0.01" min="0" max="100" placeholder="MC ukur ulang %" <?= $inp ?> style="width:120px;padding:6px;border:1px solid #ccc;border-radius:6px"><?php endif; ?>
            <input type="text" name="reason" maxlength="200" placeholder="Alasan / catatan" <?= $inp ?>>
            <?php if ($hold): ?>
              <button name="act" value="release">Lulus</button>
              <button name="act" value="reject" class="danger" onclick="return confirm('Tolak lot ini?')">Tolak</button>
            <?php else: ?>
              <button name="act" value="scrap" class="danger" onclick="return confirm('Hapus seluruh stok lot ini (scrap)? Tidak dapat dibatalkan.')">Scrap stok</button>
              <?php if (!empty($r['company_name'])): ?><a class="btn sec" href="manage_return.php?batch=<?= (int)$r['id'] ?>">Retur ke supplier</a><?php endif; ?>
            <?php endif; ?>
          </form></td><?php endif; ?></tr>
    <?php endforeach; ?>
  </table>
  <small>Kayu hanya bisa lulus jika MC &le; 12%. Lot ditahan/ditolak tidak ikut dialokasikan untuk pengeluaran.</small>
</div>

<?php if ($canDecide): ?>
<div class="panel scroll">
  <strong>Lot lulus QC (tahan ulang bila ada keluhan)</strong>
  <table>
    <tr><th>Barang</th><th>Lot</th><th>Stok</th><th>Rak</th><th>Tahan</th></tr>
    <?php if (!$passed): ?><tr><td colspan="5">Tidak ada lot.</td></tr><?php endif; ?>
    <?php foreach ($passed as $r): ?>
      <tr><td><?= e($r['sku']) ?><br><small><?= e($r['item_name']) ?></small></td><td><?= e($r['batch_number']) ?></td>
        <td><?= e(fmtQty($r['qty'])) ?> <?= e($r['unit_code']) ?></td><td><small><?= e($r['bins']) ?></small></td>
        <td><form method="post" style="display:flex;gap:4px">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="batch_id" value="<?= (int)$r['id'] ?>">
          <input type="text" name="reason" maxlength="200" placeholder="Alasan menahan *" required <?= $inp ?>>
          <button name="act" value="hold" class="danger">Tahan</button></form></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
<?php renderFooter();
