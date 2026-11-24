<?php
/**
 * Purchase Order (PO) supplier: buat PO berisi beberapa barang, pantau qty dipesan vs diterima,
 * tutup/batalkan PO. Penerimaan barangnya dilakukan di Barang Masuk (pilih baris PO), boleh
 * bertahap (partial receipt); status PO berubah OPEN -> PARTIAL -> CLOSED otomatis.
 * SATU fitur untuk PO dan SPK: setiap PO otomatis punya nomor SPK + surat cetak ke supplier (berisi foto acuan & spesifikasi
 * barang dari master). Hak akses: modul "inbound" ATAU "buyer" untuk melihat/membuat; Setujui (tutup/batalkan) = "inbound".
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/po.php';
require_once __DIR__ . '/svlk_helper.php';
require_once __DIR__ . '/photo_helper.php';
require_once __DIR__ . '/layout.php';
requireLogin();
if (!(canAccess('inbound', 'view') || canAccess('buyer', 'view'))) {
    http_response_code(403);
    die('Akses ditolak untuk peran Anda.');
}

$canCreate  = canAccess('inbound', 'create') || canAccess('buyer', 'create');
$canApprove = canAccess('inbound', 'approve');
$showCost   = canSeePrice('inbound') || canSeePrice('buyer');
$csrf       = stockCsrf();
$errors     = [];
$maxLines   = 20;

$form = ['supplier_id' => '', 'order_date' => date('Y-m-d'), 'po_number' => '', 'notes' => '', 'deliver_by' => ''];
$formLines = [['item_id' => '', 'qty' => '', 'price' => '']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    stockCheckCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        if (!$canCreate) { http_response_code(403); die('Akses ditolak untuk peran Anda.'); }
        foreach ($form as $k => $_) $form[$k] = trim((string)($_POST[$k] ?? ''));
        $supId = (int)$form['supplier_id'];

        $supplier = null;
        if ($supId) {
            $st = mysqli_prepare($connect, "SELECT * FROM suppliers WHERE id = ? AND is_active = 1");
            mysqli_stmt_bind_param($st, 'i', $supId);
            mysqli_stmt_execute($st);
            $supplier = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        }
        if (!$supplier) $errors[] = 'Supplier wajib dipilih.';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['order_date'])) $errors[] = 'Tanggal PO tidak valid.';
        if ($form['po_number'] !== '' && !preg_match('#^[A-Za-z0-9./-]{3,30}$#', $form['po_number'])) {
            $errors[] = 'No. PO hanya boleh huruf, angka, titik, garis miring, atau tanda hubung (3-30 karakter).';
        }
        if (mb_strlen($form['notes']) > 500) $errors[] = 'Catatan terlalu panjang (maks. 500 karakter).';
        if ($form['deliver_by'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['deliver_by'])) $errors[] = 'Batas kirim tidak valid.';
        elseif ($form['deliver_by'] !== '' && $form['deliver_by'] < date('Y-m-d')) $errors[] = 'Batas kirim tidak boleh di masa lalu.';

        // Baris PO (array item_id[], qty[], price[]); baris tanpa barang diabaikan
        $formLines = [];
        $lines = [];
        $seen = [];
        $ids = (array)($_POST['item_id'] ?? []);
        $qtys = (array)($_POST['qty'] ?? []);
        $prices = (array)($_POST['price'] ?? []);
        foreach ($ids as $idx => $rawId) {
            $itemId = (int)$rawId;
            $q = trim((string)($qtys[$idx] ?? ''));
            $pr = trim((string)($prices[$idx] ?? ''));
            $formLines[] = ['item_id' => (string)$rawId, 'qty' => $q, 'price' => $pr];
            if ($itemId === 0 && $q === '') continue;
            if ($itemId === 0) { $errors[] = 'Ada baris PO tanpa barang.'; continue; }
            if (isset($seen[$itemId])) { $errors[] = 'Satu barang hanya boleh muncul sekali dalam satu PO.'; continue; }
            $seen[$itemId] = true;
            if (!is_numeric($q) || (float)$q <= 0) { $errors[] = 'Jumlah pesanan harus lebih dari 0.'; continue; }
            $price = 0.0;
            if ($showCost && $pr !== '') {
                if (!is_numeric($pr) || (float)$pr < 0) { $errors[] = 'Harga satuan tidak valid.'; continue; }
                $price = (float)$pr;
            }
            $lines[] = ['item_id' => $itemId, 'qty' => (float)$q, 'price' => $price];
        }
        if (!$formLines) $formLines = [['item_id' => '', 'qty' => '', 'price' => '']];
        if (!$lines && !$errors) $errors[] = 'PO harus memiliki minimal satu barang.';
        if (count($lines) > $maxLines) $errors[] = "Maksimal $maxLines barang per PO.";

        // Aturan yang sama dengan Barang Masuk: kayu hanya dari supplier bersertifikat SVLK
        if ($supplier && $lines && !$errors) {
            foreach ($lines as $ln) {
                $st = mysqli_prepare($connect, "SELECT i.sku, c.category_type FROM items i
                    JOIN categories c ON c.id = i.category_id WHERE i.id = ? AND i.is_active = 1");
                mysqli_stmt_bind_param($st, 'i', $ln['item_id']);
                mysqli_stmt_execute($st);
                $it = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
                if (!$it) { $errors[] = 'Ada barang yang tidak valid atau nonaktif.'; break; }
                if ($it['category_type'] === 'FINISHED_GOOD') { $errors[] = "Barang jadi ({$it['sku']}) tidak dipesan lewat PO supplier."; break; }
                if ($it['category_type'] === 'RAW_MATERIAL' && ($svlkMsg = svlkProblem($supplier)) !== null) {
                    $errors[] = "$svlkMsg; bahan baku kayu ({$it['sku']}) tidak boleh dipesan dari supplier ini.";
                    break;
                }
            }
        }

        if (!$errors) {
            try {
                stockBegin();
                $poNo = $form['po_number'];
                $generated = ($poNo === '');
                $poId = 0;
                for ($try = 0; $try < 5; $try++) {
                    $sfx = strtoupper(bin2hex(random_bytes(2)));
                    if ($generated) $poNo = 'PO-' . date('ymd') . '-' . $sfx;
                    $spkNo = 'SPK-' . date('ymd') . '-' . $sfx;
                    $uid = (int)$_SESSION['user_id'];
                    $notes = $form['notes'] !== '' ? $form['notes'] : null;
                    $deliverBy = $form['deliver_by'] !== '' ? $form['deliver_by'] : null;
                    $st = mysqli_prepare($connect, "INSERT INTO purchase_orders
                        (po_number, supplier_id, order_date, status, notes, created_by, deliver_by, spk_number, spk_note)
                        VALUES (?, ?, ?, 'OPEN', ?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($st, 'sississs', $poNo, $supId, $form['order_date'], $notes, $uid, $deliverBy, $spkNo, $notes);
                    if (mysqli_stmt_execute($st)) { $poId = mysqli_insert_id($connect); break; }
                    if (mysqli_errno($connect) !== 1062) throw new StockException('Gagal menyimpan PO: ' . mysqli_error($connect));
                    if (!$generated && $try === 0 && stripos(mysqli_error($connect), 'po_number') !== false) throw new StockException("No. PO $poNo sudah dipakai.");
                }
                if (!$poId) throw new StockException('Gagal membuat nomor PO unik.');
                foreach ($lines as $ln) {
                    $q = number_format($ln['qty'], 4, '.', '');
                    $p = number_format($ln['price'], 4, '.', '');
                    $st = mysqli_prepare($connect, "INSERT INTO purchase_order_lines (po_id, item_id, qty_ordered, unit_price_idr)
                        VALUES (?, ?, ?, ?)");
                    mysqli_stmt_bind_param($st, 'iiss', $poId, $ln['item_id'], $q, $p);
                    if (!mysqli_stmt_execute($st)) throw new StockException('Gagal menyimpan baris PO: ' . mysqli_error($connect));
                }
                mysqli_commit($connect);
                logActivity('CREATE', 'Purchase Order', "PO $poNo / $spkNo dibuat (" . count($lines) . ' barang)', $poId);
                $_SESSION['flash'] = ['ok', "PO $poNo tersimpan (surat SPK $spkNo). Cetak suratnya untuk diberikan ke supplier."];
                header('Location: manage_po.php?id=' . $poId);
                exit;
            } catch (StockException $ex) {
                stockRollback();
                $errors[] = $ex->getMessage();
            }
        }
    } elseif ($action === 'close' || $action === 'cancel') {
        if (!$canApprove) { http_response_code(403); die('Akses ditolak: butuh hak Setujui pada Barang Masuk.'); }
        $poId = (int)($_POST['po_id'] ?? 0);
        try {
            stockBegin();
            $st = mysqli_prepare($connect, "SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE");
            mysqli_stmt_bind_param($st, 'i', $poId);
            mysqli_stmt_execute($st);
            $po = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
            if (!$po) throw new StockException('PO tidak ditemukan.');
            if ($action === 'close') {
                if (!in_array($po['status'], ['OPEN', 'PARTIAL'], true)) throw new StockException('Hanya PO OPEN/PARTIAL yang bisa ditutup.');
                $new = 'CLOSED';
            } else {
                if ($po['status'] !== 'OPEN') throw new StockException('Hanya PO OPEN (belum ada penerimaan) yang bisa dibatalkan. PO yang sudah menerima barang bisa ditutup.');
                $st = mysqli_prepare($connect, "SELECT COUNT(*) FROM po_receipts r
                    JOIN purchase_order_lines l ON l.id = r.po_line_id WHERE l.po_id = ? AND r.is_reversed = 0");
                mysqli_stmt_bind_param($st, 'i', $poId);
                mysqli_stmt_execute($st);
                if ((int)mysqli_fetch_row(mysqli_stmt_get_result($st))[0] > 0) throw new StockException('PO ini sudah punya penerimaan aktif; tidak bisa dibatalkan.');
                $new = 'CANCELLED';
            }
            $u = mysqli_prepare($connect, "UPDATE purchase_orders SET status = ? WHERE id = ?");
            mysqli_stmt_bind_param($u, 'si', $new, $poId);
            mysqli_stmt_execute($u);
            mysqli_commit($connect);
            logActivity('UPDATE', 'Purchase Order', "PO {$po['po_number']} diubah ke status $new", $poId);
            $_SESSION['flash'] = ['ok', "PO {$po['po_number']} sekarang berstatus $new."];
            header('Location: manage_po.php?id=' . $poId);
            exit;
        } catch (StockException $ex) {
            stockRollback();
            $_SESSION['flash'] = ['err', $ex->getMessage()];
            header('Location: manage_po.php?id=' . $poId);
            exit;
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$viewId = (int)($_GET['id'] ?? 0);
$po = null; $poLines = []; $poReceipts = [];
if ($viewId) {
    $st = mysqli_prepare($connect, "SELECT p.*, s.company_name, s.supplier_code, u.username
        FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id JOIN users u ON u.id = p.created_by
        WHERE p.id = ?");
    mysqli_stmt_bind_param($st, 'i', $viewId);
    mysqli_stmt_execute($st);
    $po = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if ($po) {
        $st = mysqli_prepare($connect, "SELECT l.*, i.sku, i.item_name, un.unit_code
            FROM purchase_order_lines l JOIN items i ON i.id = l.item_id JOIN units un ON un.id = i.unit_id
            WHERE l.po_id = ? ORDER BY l.id");
        mysqli_stmt_bind_param($st, 'i', $viewId);
        mysqli_stmt_execute($st);
        $poLines = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
        $st = mysqli_prepare($connect, "SELECT r.qty, r.is_reversed, r.created_at, t.trx_code, i.sku
            FROM po_receipts r JOIN purchase_order_lines l ON l.id = r.po_line_id
            JOIN items i ON i.id = l.item_id JOIN inventory_transactions t ON t.id = r.transaction_id
            WHERE l.po_id = ? ORDER BY r.id DESC");
        mysqli_stmt_bind_param($st, 'i', $viewId);
        mysqli_stmt_execute($st);
        $poReceipts = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
    }
}

$statusFilter = in_array($_GET['status'] ?? '', ['OPEN', 'PARTIAL', 'CLOSED', 'CANCELLED'], true) ? $_GET['status'] : '';
$listSql = "SELECT p.id, p.po_number, p.order_date, p.status, s.company_name,
        COUNT(l.id) AS n_lines, COALESCE(SUM(l.qty_ordered),0) AS q_ord, COALESCE(SUM(l.qty_received),0) AS q_rcv,
        COALESCE(SUM(l.qty_ordered * l.unit_price_idr),0) AS total_value
    FROM purchase_orders p JOIN suppliers s ON s.id = p.supplier_id
    LEFT JOIN purchase_order_lines l ON l.po_id = p.id " .
    ($statusFilter ? "WHERE p.status = '" . $statusFilter . "' " : '') .
    "GROUP BY p.id ORDER BY p.id DESC LIMIT 50";
$list = mysqli_fetch_all(mysqli_query($connect, $listSql), MYSQLI_ASSOC);

$suppliers = mysqli_fetch_all(mysqli_query($connect, "SELECT id, company_name, is_svlk_certified, svlk_expiry_date, lead_time_days
    FROM suppliers WHERE is_active = 1 ORDER BY company_name"), MYSQLI_ASSOC);
$items = mysqli_fetch_all(mysqli_query($connect, "SELECT i.id, i.sku, i.item_name, u.unit_code, i.specification, i.reference_photo_path, i.default_supplier_id
    FROM items i JOIN units u ON u.id = i.unit_id JOIN categories c ON c.id = i.category_id
    WHERE i.is_active = 1 AND c.category_type <> 'FINISHED_GOOD' ORDER BY i.sku"), MYSQLI_ASSOC);

$itemMeta = [];
foreach ($items as $it) {
    $itemMeta[(int)$it['id']] = ['unit' => $it['unit_code'], 'spec' => (string)$it['specification'],
        'photo' => wmsValidRequestPath($it['reference_photo_path'] ?? ''), 'sup' => (int)$it['default_supplier_id']];
}
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;

function poStatusTag($s)
{
    $off = in_array($s, ['CLOSED', 'CANCELLED'], true) ? ' off' : '';
    return '<span class="tag' . $off . '">' . e($s) . '</span>';
}
function poItemOptions(array $items, $selected)
{
    $h = '<option value="">- pilih barang -</option>';
    foreach ($items as $i) {
        $sel = ((string)$i['id'] === (string)$selected) ? ' selected' : '';
        $h .= '<option value="' . (int)$i['id'] . '"' . $sel . '>' . e($i['sku'] . ' - ' . $i['item_name'] . ' (' . $i['unit_code'] . ')') . '</option>';
    }
    return $h;
}

// Saran PO dari stok minimum: dibuka lewat tombol "Buat PO" di Dashboard / lonceng (?suggest=1, opsional &item=<id barang>).
// Hanya mengisi form (belum menyimpan apa pun). Jumlah saran = 2 x reorder point - stok pakai - yang sudah dipesan di PO terbuka.
$suggestNote = '';
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $canCreate && isset($_GET['suggest']) && !$viewId) {
    $only = (int)($_GET['item'] ?? 0);   // 0 = semua barang di bawah batas; >0 = hanya barang itu
    $sugRows = mysqli_fetch_all(mysqli_query($connect, "SELECT i.id, i.sku, i.reorder_point, i.moving_avg_cost_idr, i.default_supplier_id,
            COALESCE(SUM(CASE WHEN b.qc_status = 'PASSED' AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
                              THEN sb.qty_on_hand - sb.qty_reserved END), 0) AS qty,
            (SELECT COALESCE(SUM(l.qty_ordered - l.qty_received), 0) FROM purchase_order_lines l
                JOIN purchase_orders p ON p.id = l.po_id AND p.status IN ('OPEN','PARTIAL')
                WHERE l.item_id = i.id AND l.qty_received < l.qty_ordered) AS on_order
        FROM items i JOIN categories c ON c.id = i.category_id AND c.category_type <> 'FINISHED_GOOD'
        LEFT JOIN stock_balances sb ON sb.item_id = i.id LEFT JOIN item_batches b ON b.id = sb.batch_id
        WHERE i.is_active = 1 GROUP BY i.id HAVING qty <= i.reorder_point ORDER BY qty ASC"), MYSQLI_ASSOC);
    $picked = []; $covered = 0; $supSet = [];
    foreach ($sugRows as $sr) {
        if ($only && (int)$sr['id'] !== $only) continue;
        $need = 2 * (float)$sr['reorder_point'] - (float)$sr['qty'] - (float)$sr['on_order'];
        if ($need <= 0) { $covered++; continue; }
        $picked[] = ['item_id' => (string)(int)$sr['id'], 'qty' => (string)ceil($need),
                     'price' => $showCost && (float)$sr['moving_avg_cost_idr'] > 0 ? number_format((float)$sr['moving_avg_cost_idr'], 2, '.', '') : ''];
        if (!empty($sr['default_supplier_id'])) $supSet[(int)$sr['default_supplier_id']] = true;
        if (count($picked) >= $maxLines) break;
    }
    if ($picked) {
        $formLines = $picked;
        if (count($supSet) === 1) $form['supplier_id'] = (string)array_key_first($supSet);
        $suggestNote = 'Form terisi dari saran stok minimum (' . count($picked) . ' barang). Jumlah = 2 x reorder point dikurangi stok pakai dan barang yang sudah dipesan; silakan ubah sesuai kebutuhan.'
            . (count($supSet) !== 1 ? ' Barang ini punya supplier bawaan berbeda atau belum diatur, jadi supplier dipilih manual.' : '')
            . ($covered ? " $covered barang lain tidak diisi karena pesanan terbukanya sudah mencukupi." : '');
    } else {
        $suggestNote = $covered ? 'Semua barang di bawah batas minimum sudah tertutup oleh PO yang masih terbuka, jadi tidak ada yang perlu dipesan lagi.'
                                : 'Tidak ada barang di bawah batas minimum yang bisa dipesan lewat PO.';
    }
}

renderHeader('Purchase Order');
?>
<h2>Purchase Order Supplier</h2>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($viewId && !$po): ?>
  <div class="err">PO tidak ditemukan.</div>
<?php elseif ($po): ?>
<div class="panel">
  <p style="margin:0 0 10px"><a href="manage_po.php">&laquo; Semua PO</a></p>
  <strong style="font-size:17px"><?= e($po['po_number']) ?></strong> <?= poStatusTag($po['status']) ?>
  <p style="margin:8px 0 0;color:#555;font-size:13.5px">
    Supplier: <b><?= e($po['company_name']) ?></b> &middot; Tanggal PO: <?= e($po['order_date']) ?> &middot; Dibuat oleh: <?= e($po['username']) ?>
    <?php if (!empty($po['notes'])): ?><br>Catatan: <?= e($po['notes']) ?><?php endif; ?>
    <?php if (!empty($po['deliver_by'])): ?><br>Batas kirim: <?= e($po['deliver_by']) ?><?php endif; ?>
    <br><a class="btn" style="padding:5px 16px;margin-top:6px;display:inline-block" href="print_spk.php?id=<?= (int)$po['id'] ?>" target="_blank">Cetak surat ke supplier</a>
  </p>
  <div class="scroll" style="margin-top:14px">
    <table>
      <tr><th>Barang</th><th>Dipesan</th><th>Diterima</th><th>Sisa</th><?php if ($showCost): ?><th>Harga satuan (IDR)</th><?php endif; ?><th></th></tr>
      <?php foreach ($poLines as $l):
          $rem = max(0.0, (float)$l['qty_ordered'] - (float)$l['qty_received']); ?>
        <tr><td><?= e($l['sku']) ?><br><small><?= e($l['item_name']) ?></small></td>
          <td><?= e(fmtQty($l['qty_ordered'])) ?> <?= e($l['unit_code']) ?></td>
          <td><?= e(fmtQty($l['qty_received'])) ?></td>
          <td><b><?= e(fmtQty($rem)) ?></b></td>
          <?php if ($showCost): ?><td><?= e(number_format((float)$l['unit_price_idr'], 2, ',', '.')) ?></td><?php endif; ?>
          <td><?php if ($rem > 0 && in_array($po['status'], ['OPEN', 'PARTIAL'], true) && canAccess('inbound', 'create')): ?>
            <a class="btn sec" style="padding:5px 14px" href="manage_inbound.php?po_line=<?= (int)$l['id'] ?>">Terima</a><?php endif; ?></td></tr>
      <?php endforeach; ?>
    </table>
  </div>
  <?php if ($canApprove && in_array($po['status'], ['OPEN', 'PARTIAL'], true)): ?>
  <form method="post" style="margin-top:14px;display:flex;gap:8px;flex-wrap:wrap">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="po_id" value="<?= (int)$po['id'] ?>">
    <button type="submit" name="action" value="close" class="sec" onclick="return confirm('Tutup PO ini? Sisa pesanan yang belum datang dianggap selesai.')">Tutup PO</button>
    <?php if ($po['status'] === 'OPEN'): ?>
    <button type="submit" name="action" value="cancel" class="danger" onclick="return confirm('Batalkan PO ini?')">Batalkan PO</button>
    <?php endif; ?>
  </form>
  <?php endif; ?>
</div>
<div class="panel scroll">
  <strong>Riwayat penerimaan</strong>
  <table>
    <tr><th>Waktu</th><th>No. Transaksi</th><th>Barang</th><th>Jumlah</th><th>Status</th></tr>
    <?php if (!$poReceipts): ?><tr><td colspan="5">Belum ada penerimaan.</td></tr><?php endif; ?>
    <?php foreach ($poReceipts as $r): ?>
      <tr><td><?= e($r['created_at']) ?></td>
        <td><a href="print_trx.php?code=<?= urlencode($r['trx_code']) ?>" target="_blank"><?= e($r['trx_code']) ?></a></td>
        <td><?= e($r['sku']) ?></td><td><?= e(fmtQty($r['qty'])) ?></td>
        <td><?= (int)$r['is_reversed'] ? '<span class="tag off">DIBATALKAN</span>' : '<span class="tag">AKTIF</span>' ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>

<?php else: ?>

<?php if ($canCreate): ?>
<div class="panel">
  <strong>Buat PO baru</strong>
  <?php if ($suggestNote !== ''): ?><div class="ok" style="margin:8px 0"><?= e($suggestNote) ?></div><?php endif; ?>
  <form method="post" autocomplete="off" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="create">
    <style>
      .grid.po-head{grid-template-columns:repeat(3,minmax(0,1fr));align-items:start}
      .grid.po-head .span2{grid-column:span 2}
      @media(max-width:900px){.grid.po-head{grid-template-columns:repeat(2,minmax(0,1fr))}.grid.po-head .span2{grid-column:1/-1}}
      @media(max-width:600px){.grid.po-head{grid-template-columns:1fr}}
    </style>
    <div class="grid po-head">
      <div><label>Supplier *</label>
        <select name="supplier_id" id="supplier_id" required><option value="">- pilih -</option>
        <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>" data-lead="<?= (int)$s['lead_time_days'] ?>" <?= (string)$s['id'] === $form['supplier_id'] ? 'selected' : '' ?>><?= e($s['company_name'] . svlkLabel($s)) ?></option><?php endforeach; ?>
        </select></div>
      <div><label>Tanggal PO *</label><input type="date" name="order_date" value="<?= e($form['order_date']) ?>" required></div>
      <div><label>No. PO</label><input type="text" name="po_number" maxlength="30" value="<?= e($form['po_number']) ?>" placeholder="kosong = dibuat otomatis"></div>
      <div><label>Batas kirim (opsional)</label><input type="date" name="deliver_by" id="deliverBy" min="<?= date('Y-m-d') ?>" value="<?= e($form['deliver_by']) ?>">
        <small id="leadHint" style="color:#666;display:block;margin-top:4px">Terisi otomatis dari lead time supplier; boleh diubah.</small></div>
      <div class="span2"><label>Catatan untuk supplier (tercetak di surat)</label><input type="text" name="notes" maxlength="500" value="<?= e($form['notes']) ?>" placeholder="mis. kirim pagi hari, hubungi petugas gudang sebelum tiba"></div>
    </div>
    <div class="scroll" style="margin-top:14px">
      <table id="poLines">
        <tr><th style="width:84px">Foto acuan</th><th>Barang *</th><th style="width:140px">Jumlah *</th><?php if ($showCost): ?><th style="width:170px">Harga satuan (IDR)</th><?php endif; ?><th style="width:60px"></th></tr>
        <?php foreach ($formLines as $fl): ?>
        <tr>
          <td class="ph"></td>
          <td><select name="item_id[]"><?= poItemOptions($items, $fl['item_id']) ?></select><small class="sp" style="display:block;margin-top:4px"></small></td>
          <td><input type="number" name="qty[]" step="0.0001" min="0.0001" value="<?= e($fl['qty']) ?>"></td>
          <?php if ($showCost): ?><td><input type="number" name="price[]" step="0.01" min="0" value="<?= e($fl['price']) ?>"></td><?php endif; ?>
          <td><button type="button" class="danger" style="padding:5px 12px" onclick="poDelRow(this)">&times;</button></td>
        </tr>
        <?php endforeach; ?>
      </table>
    </div>
    <p style="margin:12px 0 0;display:flex;gap:10px;flex-wrap:wrap">
      <button type="button" class="sec" onclick="poAddRow()">+ Tambah barang</button>
      <button type="submit">Simpan PO</button>
    </p>
  </form>
</div>
<template id="poRowTpl">
  <tr>
    <td class="ph"></td>
    <td><select name="item_id[]"><?= poItemOptions($items, '') ?></select><small class="sp" style="display:block;margin-top:4px"></small></td>
    <td><input type="number" name="qty[]" step="0.0001" min="0.0001"></td>
    <?php if ($showCost): ?><td><input type="number" name="price[]" step="0.01" min="0"></td><?php endif; ?>
    <td><button type="button" class="danger" style="padding:5px 12px" onclick="poDelRow(this)">&times;</button></td>
  </tr>
</template>
<script>
var PO_MAX = <?= (int)$maxLines ?>;
function poAddRow() {
  var t = document.getElementById('poLines');
  if (t.rows.length - 1 >= PO_MAX) { alert('Maksimal ' + PO_MAX + ' barang per PO.'); return; }
  t.appendChild(document.getElementById('poRowTpl').content.cloneNode(true));
}
// Foto acuan + spesifikasi barang dari master; usul supplier utama bila supplier belum dipilih
var PO_ITEMS = <?= json_encode($itemMeta, $jsonFlags) ?>;
function poEsc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
function poRefresh(tr) {
  var sel = tr.querySelector('select'), ph = tr.querySelector('.ph'), sp = tr.querySelector('.sp');
  if (!sel || !ph || !sp) return;
  var it = PO_ITEMS[sel.value];
  if (!it) { ph.innerHTML = ''; sp.textContent = ''; return; }
  ph.innerHTML = it.photo ? '<img src="' + poEsc(it.photo) + '" alt="" style="width:64px;height:50px;object-fit:cover;border-radius:6px;border:1px solid #ccc">' : '<small>tanpa foto</small>';
  sp.textContent = it.spec ? 'Spesifikasi: ' + it.spec : (it.photo ? '' : 'Belum ada foto/spesifikasi acuan');
}
(function () {
  var t = document.getElementById('poLines');
  if (!t) return;
  t.addEventListener('change', function (ev) {
    if (ev.target.tagName !== 'SELECT') return;
    poRefresh(ev.target.closest('tr'));
    var it = PO_ITEMS[ev.target.value], sup = document.getElementById('supplier_id');
    if (it && it.sup && sup && !sup.value) { sup.value = String(it.sup); sup.dispatchEvent(new Event('change')); }
  });
  Array.prototype.forEach.call(t.rows, function (tr, i) { if (i > 0) poRefresh(tr); });
})();
function poDelRow(btn) {
  var t = document.getElementById('poLines');
  if (t.rows.length <= 2) return;
  btn.closest('tr').remove();
}
</script>
<?php endif; ?>

<div class="panel scroll">
  <strong>Daftar PO</strong>
  <p style="margin:8px 0">
    Filter:
    <a href="manage_po.php">Semua</a>
    <?php foreach (['OPEN', 'PARTIAL', 'CLOSED', 'CANCELLED'] as $s): ?>
      | <a href="manage_po.php?status=<?= $s ?>"<?= $statusFilter === $s ? ' style="font-weight:700"' : '' ?>><?= $s ?></a>
    <?php endforeach; ?>
  </p>
  <table>
    <tr><th>No. PO</th><th>Tanggal</th><th>Supplier</th><th>Barang</th><th>Diterima / Dipesan</th><?php if ($showCost): ?><th>Nilai (IDR)</th><?php endif; ?><th>Status</th></tr>
    <?php if (!$list): ?><tr><td colspan="<?= $showCost ? 7 : 6 ?>">Belum ada PO.</td></tr><?php endif; ?>
    <?php foreach ($list as $r): ?>
      <tr><td><a href="manage_po.php?id=<?= (int)$r['id'] ?>"><?= e($r['po_number']) ?></a></td>
        <td><?= e($r['order_date']) ?></td><td><?= e($r['company_name']) ?></td>
        <td><?= (int)$r['n_lines'] ?></td>
        <td><?= e(fmtQty($r['q_rcv'])) ?> / <?= e(fmtQty($r['q_ord'])) ?></td>
        <?php if ($showCost): ?><td><?= e(number_format((float)$r['total_value'], 0, ',', '.')) ?></td><?php endif; ?>
        <td><?= poStatusTag($r['status']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php endif; ?>
<script>
(function () {
  // Batas kirim otomatis = Tanggal PO + lead time supplier (boleh diubah manual).
  var supSel = document.querySelector('select[name="supplier_id"]');
  var dateIn = document.querySelector('input[name="order_date"]');
  var by = document.getElementById('deliverBy'), hint = document.getElementById('leadHint');
  if (!supSel || !dateIn || !by) return;
  var auto = (by.value === '');
  function calc() {
    var o = supSel.options[supSel.selectedIndex];
    var lead = o ? parseInt(o.getAttribute('data-lead'), 10) : NaN;
    if (isNaN(lead) || !/^\d{4}-\d{2}-\d{2}$/.test(dateIn.value)) return;
    if (hint) hint.textContent = 'Lead time supplier: ' + lead + ' hari. Boleh diubah.';
    if (!auto) return;
    var p = dateIn.value.split('-');
    var d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + lead));
    var v = d.toISOString().slice(0, 10);
    by.value = (v >= by.min) ? v : '';
  }
  by.addEventListener('input', function () { auto = (by.value === ''); });
  supSel.addEventListener('change', calc);
  dateIn.addEventListener('change', calc);
  calc();
})();
</script>
<?php renderFooter();