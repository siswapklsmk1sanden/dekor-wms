<?php
/**
 * Permintaan Buyer: Buyer meminta sebuah barang didaftarkan, lengkap dengan FOTO ACUAN
 * ("barang seperti apa"). Alur:
 *   REQUESTED  (Buyer mengajukan + foto acuan)
 *   -> REGISTERED  (Kepala Gudang / Admin mendaftarkannya ke master Barang, atau Buyer memilih barang yang sudah ada)
 *   -> SPK_ISSUED  (Buyer menerbitkan surat SPK ke supplier di menu Purchase Order)
 * Barang yang dikirim supplier kemudian diterima di Barang Masuk (pilih baris PO/SPK).
 * Hak akses: modul "buyer" (Lihat / Buat / Setujui = tolak). Mendaftarkan ke master butuh hak Setujui pada "master".
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/photo_helper.php';
require_once __DIR__ . '/layout.php';
requirePermission('buyer', 'view');

$canCreate   = canAccess('buyer', 'create');
$canReject   = canAccess('buyer', 'approve');
$canRegister = canAccess('master', 'approve');
$csrf        = stockCsrf();
$errors      = [];

$form = ['item_name' => '', 'specification' => '', 'qty' => '', 'unit_id' => '', 'needed_date' => '',
         'supplier_id' => '', 'existing_item_id' => '', 'photo_path' => ''];

function buyerFlash($type, $msg)
{
    $_SESSION['flash'] = [$type, $msg];
    header('Location: manage_buyer.php');
    exit;
}

// Foto melebihi post_max_size: PHP mengosongkan $_POST
$postTooBig = $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
if ($postTooBig) $errors[] = 'Ukuran data terlalu besar. Foto maksimal 8 MB (cek post_max_size di php.ini).';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$postTooBig) {
    stockCheckCsrf();
    $action = $_POST['action'] ?? '';

    // ---------------------------------------------------------------- ajukan permintaan
    if ($action === 'create') {
        requirePermission('buyer', 'create');
        foreach ($form as $k => $_) $form[$k] = trim((string)($_POST[$k] ?? ''));

        $photoPath = wmsValidRequestPath($form['photo_path']);
        $upl = $_FILES['photo'] ?? null;
        if ($upl && ($upl['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $newPhoto = wmsSaveRequestPhoto($upl, $errors);
            if ($newPhoto) { $photoPath = $newPhoto; $form['photo_path'] = $newPhoto; }
        }
        if ($photoPath === '' && !$errors) $errors[] = 'Foto acuan barang wajib diunggah (barang seperti apa yang diminta).';

        // Barang yang sudah terdaftar (opsional): nama dan satuan mengikuti master
        $existing = null;
        if ($form['existing_item_id'] !== '') {
            $st = mysqli_prepare($connect, "SELECT i.id, i.item_name, i.unit_id, i.default_supplier_id, c.category_type
                FROM items i JOIN categories c ON c.id = i.category_id WHERE i.id = ? AND i.is_active = 1");
            $eid = (int)$form['existing_item_id'];
            mysqli_stmt_bind_param($st, 'i', $eid);
            mysqli_stmt_execute($st);
            $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
            if (!$existing) $errors[] = 'Barang yang dipilih tidak valid atau nonaktif.';
            elseif ($existing['category_type'] === 'FINISHED_GOOD') $errors[] = 'Barang jadi tidak dibeli dari supplier.';
        }

        $name = $existing ? $existing['item_name'] : $form['item_name'];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 180) $errors[] = 'Nama barang wajib diisi (2-180 karakter).';
        if (mb_strlen($form['specification']) > 500) $errors[] = 'Spesifikasi terlalu panjang (maks. 500 karakter).';
        if (!is_numeric($form['qty']) || (float)$form['qty'] <= 0) $errors[] = 'Jumlah harus lebih dari 0.';

        $unitId = $existing ? (int)$existing['unit_id'] : (int)$form['unit_id'];
        $st = mysqli_prepare($connect, "SELECT id FROM units WHERE id = ?");
        mysqli_stmt_bind_param($st, 'i', $unitId);
        mysqli_stmt_execute($st);
        if (!mysqli_fetch_row(mysqli_stmt_get_result($st))) $errors[] = 'Satuan wajib dipilih.';

        $needed = null;
        if ($form['needed_date'] !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['needed_date'])) $errors[] = 'Tanggal dibutuhkan tidak valid.';
            else $needed = $form['needed_date'];
        }
        $supId = (int)$form['supplier_id'];
        if ($supId) {
            $st = mysqli_prepare($connect, "SELECT id FROM suppliers WHERE id = ? AND is_active = 1");
            mysqli_stmt_bind_param($st, 'i', $supId);
            mysqli_stmt_execute($st);
            if (!mysqli_fetch_row(mysqli_stmt_get_result($st))) $errors[] = 'Supplier usulan tidak valid.';
        }

        if (!$errors) {
            $uid     = (int)$_SESSION['user_id'];
            $spec    = $form['specification'] !== '' ? $form['specification'] : null;
            $qtyS    = number_format((float)$form['qty'], 4, '.', '');
            $supBind = $supId ?: null;
            $itemBind = $existing ? (int)$existing['id'] : null;
            $status  = $existing ? 'REGISTERED' : 'REQUESTED';
            $newId = 0; $reqNo = '';
            for ($try = 0; $try < 5 && !$newId; $try++) {
                $reqNo = 'REQ-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
                $st = mysqli_prepare($connect, "INSERT INTO buyer_requests
                    (req_number, item_id, item_name, specification, qty_requested, unit_id, supplier_id,
                     needed_date, reference_photo_path, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                mysqli_stmt_bind_param($st, 'sisssiisssi', $reqNo, $itemBind, $name, $spec, $qtyS, $unitId,
                    $supBind, $needed, $photoPath, $status, $uid);
                if (mysqli_stmt_execute($st)) { $newId = mysqli_insert_id($connect); break; }
                if (mysqli_errno($connect) !== 1062) { $errors[] = 'Gagal menyimpan permintaan: ' . mysqli_error($connect); break; }
            }
            if ($newId) {
                logActivity('CREATE', 'Permintaan Buyer', "Permintaan $reqNo: $name x " . fmtQty($qtyS) . " ($status)", $newId);
                buyerFlash('ok', $existing
                    ? "Permintaan $reqNo tersimpan. Barang sudah terdaftar, langsung bisa dibuatkan SPK."
                    : "Permintaan $reqNo tersimpan. Menunggu Kepala Gudang mendaftarkan barang ini ke master.");
            }
        }

    // ---------------------------------------------------------------- daftarkan ke master Barang
    } elseif ($action === 'register') {
        requirePermission('master', 'approve');
        $rid = (int)($_POST['req_id'] ?? 0);
        $sku = strtoupper(trim((string)($_POST['sku'] ?? '')));
        $catId = (int)($_POST['category_id'] ?? 0);
        try {
            if (!preg_match('/^[A-Z0-9._-]{2,40}$/', $sku)) throw new StockException('SKU hanya huruf, angka, titik, garis bawah, atau tanda hubung (2-40 karakter).');
            stockBegin();
            $st = mysqli_prepare($connect, "SELECT * FROM buyer_requests WHERE id = ? FOR UPDATE");
            mysqli_stmt_bind_param($st, 'i', $rid);
            mysqli_stmt_execute($st);
            $rq = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
            if (!$rq) throw new StockException('Permintaan tidak ditemukan.');
            if ($rq['status'] !== 'REQUESTED') throw new StockException('Permintaan ini sudah diproses.');
            $st = mysqli_prepare($connect, "SELECT category_type FROM categories WHERE id = ?");
            mysqli_stmt_bind_param($st, 'i', $catId);
            mysqli_stmt_execute($st);
            $cat = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
            if (!$cat) throw new StockException('Kategori wajib dipilih.');
            if ($cat['category_type'] === 'FINISHED_GOOD') throw new StockException('Barang jadi tidak didaftarkan lewat permintaan Buyer.');

            $supBind = $rq['supplier_id'] !== null ? (int)$rq['supplier_id'] : null;
            $unitId = (int)$rq['unit_id'];
            $st = mysqli_prepare($connect, "INSERT INTO items (sku, item_name, category_id, unit_id, default_supplier_id) VALUES (?, ?, ?, ?, ?)");
            mysqli_stmt_bind_param($st, 'ssiii', $sku, $rq['item_name'], $catId, $unitId, $supBind);
            if (!mysqli_stmt_execute($st)) {
                if (mysqli_errno($connect) === 1062) throw new StockException("SKU $sku sudah dipakai barang lain.");
                throw new StockException('Gagal mendaftarkan barang: ' . mysqli_error($connect));
            }
            $itemId = mysqli_insert_id($connect);
            $uid = (int)$_SESSION['user_id'];
            $st = mysqli_prepare($connect, "UPDATE buyer_requests SET item_id = ?, status = 'REGISTERED', decided_by = ?, decided_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($st, 'iii', $itemId, $uid, $rid);
            mysqli_stmt_execute($st);
            mysqli_commit($connect);
            logActivity('CREATE', 'Item', "Barang $sku didaftarkan dari permintaan {$rq['req_number']}", $itemId);
            buyerFlash('ok', "Barang $sku terdaftar di master. Buyer sekarang bisa menerbitkan SPK. Lengkapi ukuran/CBM di menu Barang bila perlu.");
        } catch (StockException $ex) {
            stockRollback();
            buyerFlash('err', $ex->getMessage());
        }

    // ---------------------------------------------------------------- tolak / batalkan
    } elseif ($action === 'reject' || $action === 'cancel') {
        $rid = (int)($_POST['req_id'] ?? 0);
        $note = trim((string)($_POST['note'] ?? ''));
        try {
            stockBegin();
            $st = mysqli_prepare($connect, "SELECT * FROM buyer_requests WHERE id = ? FOR UPDATE");
            mysqli_stmt_bind_param($st, 'i', $rid);
            mysqli_stmt_execute($st);
            $rq = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
            if (!$rq) throw new StockException('Permintaan tidak ditemukan.');
            if ($action === 'reject') {
                if (!$canReject) { http_response_code(403); die('Akses ditolak: butuh hak Setujui pada Permintaan Buyer.'); }
                if ($rq['status'] !== 'REQUESTED') throw new StockException('Hanya permintaan yang menunggu pendaftaran yang bisa ditolak.');
                if ($note === '' || mb_strlen($note) > 300) throw new StockException('Alasan penolakan wajib diisi (maks. 300 karakter).');
                $new = 'REJECTED';
            } else {
                $mine = (int)$rq['created_by'] === (int)$_SESSION['user_id'];
                if (!$mine && !$canReject) { http_response_code(403); die('Akses ditolak: hanya pembuat permintaan yang bisa membatalkan.'); }
                if (!in_array($rq['status'], ['REQUESTED', 'REGISTERED'], true)) throw new StockException('Permintaan yang sudah memiliki SPK tidak bisa dibatalkan di sini; batalkan PO-nya lebih dulu.');
                $new = 'CANCELLED';
            }
            $uid = (int)$_SESSION['user_id'];
            $noteBind = $note !== '' ? mb_substr($note, 0, 300) : null;
            $st = mysqli_prepare($connect, "UPDATE buyer_requests SET status = ?, decision_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($st, 'ssii', $new, $noteBind, $uid, $rid);
            mysqli_stmt_execute($st);
            mysqli_commit($connect);
            logActivity('UPDATE', 'Permintaan Buyer', "Permintaan {$rq['req_number']} diubah ke $new", $rid);
            buyerFlash('ok', "Permintaan {$rq['req_number']} sekarang berstatus $new.");
        } catch (StockException $ex) {
            stockRollback();
            buyerFlash('err', $ex->getMessage());
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$statusLabels = ['REQUESTED' => 'Menunggu pendaftaran', 'REGISTERED' => 'Terdaftar - siap SPK', 'SPK_ISSUED' => 'SPK terbit',
                 'REJECTED' => 'Ditolak', 'CANCELLED' => 'Dibatalkan'];
$statusFilter = isset($statusLabels[$_GET['status'] ?? '']) ? $_GET['status'] : '';

$sql = "SELECT r.*, u.unit_code, s.company_name, i.sku, cu.full_name AS creator_name,
        pl.qty_ordered, pl.qty_received, p.id AS po_id, p.po_number, p.spk_number
    FROM buyer_requests r
    JOIN units u ON u.id = r.unit_id
    JOIN users cu ON cu.id = r.created_by
    LEFT JOIN suppliers s ON s.id = r.supplier_id
    LEFT JOIN items i ON i.id = r.item_id
    LEFT JOIN purchase_order_lines pl ON pl.id = r.po_line_id
    LEFT JOIN purchase_orders p ON p.id = pl.po_id " .
    ($statusFilter ? "WHERE r.status = '" . $statusFilter . "' " : '') . "ORDER BY r.id DESC LIMIT 100";
$rs = mysqli_query($connect, $sql);
$list = $rs ? mysqli_fetch_all($rs, MYSQLI_ASSOC) : [];
$tableMissing = ($rs === false);

$units = mysqli_fetch_all(mysqli_query($connect, "SELECT id, unit_code, unit_name FROM units ORDER BY unit_code"), MYSQLI_ASSOC);
$suppliers = mysqli_fetch_all(mysqli_query($connect, "SELECT id, company_name FROM suppliers WHERE is_active = 1 ORDER BY company_name"), MYSQLI_ASSOC);
$items = mysqli_fetch_all(mysqli_query($connect, "SELECT i.id, i.sku, i.item_name, u.unit_code
    FROM items i JOIN units u ON u.id = i.unit_id JOIN categories c ON c.id = i.category_id
    WHERE i.is_active = 1 AND c.category_type <> 'FINISHED_GOOD' ORDER BY i.sku"), MYSQLI_ASSOC);
$cats = $canRegister ? mysqli_fetch_all(mysqli_query($connect, "SELECT id, category_code, category_name FROM categories
    WHERE category_type <> 'FINISHED_GOOD' ORDER BY category_name"), MYSQLI_ASSOC) : [];

function buyerStatusTag($s, $labels)
{
    $off = in_array($s, ['REJECTED', 'CANCELLED'], true) ? ' off' : '';
    return '<span class="tag' . $off . '">' . e($labels[$s] ?? $s) . '</span>';
}

renderHeader('Permintaan Buyer');
?>
<h2>Permintaan Buyer</h2>
<p style="margin:-8px 0 16px;color:#666;font-size:13.5px">Buyer meminta barang didaftarkan lengkap dengan foto acuan &rarr; Kepala Gudang mendaftarkan ke master &rarr; Buyer menerbitkan <a href="manage_po.php">SPK ke supplier</a> &rarr; supplier membawa barang &rarr; petugas menerima di <a href="manage_inbound.php">Barang Masuk</a>.</p>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>
<?php if ($tableMissing): ?><div class="err">Tabel <code>buyer_requests</code> belum ada. Jalankan <code>database\migration_buyer_spk.sql</code> terlebih dahulu.</div><?php endif; ?>

<?php if ($canCreate && !$tableMissing): ?>
<div class="panel">
  <strong>Ajukan barang untuk didaftarkan</strong>
  <form method="post" enctype="multipart/form-data" autocomplete="off" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="create">
    <div class="grid">
      <div><label>Barang sudah terdaftar? (opsional)</label>
        <select name="existing_item_id" id="existingSel"><option value="">- barang baru, belum terdaftar -</option>
        <?php foreach ($items as $i): ?><option value="<?= (int)$i['id'] ?>" <?= (string)$i['id'] === $form['existing_item_id'] ? 'selected' : '' ?>><?= e($i['sku'] . ' - ' . $i['item_name'] . ' (' . $i['unit_code'] . ')') ?></option><?php endforeach; ?>
        </select></div>
      <div><label>Nama barang *</label><input type="text" name="item_name" maxlength="180" value="<?= e($form['item_name']) ?>" placeholder="mis. Engsel pintu stainless 4 inci"></div>
      <div><label>Jumlah *</label><input type="number" name="qty" step="0.0001" min="0.0001" value="<?= e($form['qty']) ?>" required></div>
      <div><label>Satuan *</label>
        <select name="unit_id"><option value="">- pilih -</option>
        <?php foreach ($units as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (string)$u['id'] === $form['unit_id'] ? 'selected' : '' ?>><?= e($u['unit_code'] . ' - ' . $u['unit_name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label>Dibutuhkan sebelum</label><input type="date" name="needed_date" value="<?= e($form['needed_date']) ?>"></div>
      <div><label>Usulan supplier</label>
        <select name="supplier_id"><option value="">- belum ditentukan -</option>
        <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (string)$s['id'] === $form['supplier_id'] ? 'selected' : '' ?>><?= e($s['company_name']) ?></option><?php endforeach; ?>
        </select></div>
      <div style="grid-column:1/-1"><label>Spesifikasi (ukuran, bahan, warna, merek)</label><input type="text" name="specification" maxlength="500" value="<?= e($form['specification']) ?>"></div>
      <?php $curPhoto = wmsValidRequestPath($form['photo_path']); ?>
      <div style="grid-column:1/-1"><label>Foto acuan: barang seperti apa *</label>
        <input type="hidden" name="photo_path" value="<?= e($curPhoto) ?>">
        <input type="file" name="photo" accept="image/*" <?= $curPhoto === '' ? 'required' : '' ?>>
        <?php if ($curPhoto !== ''): ?>
          <div style="margin-top:6px"><small>Foto sudah diunggah (pilih file lagi untuk menggantinya):</small><br>
            <img src="<?= e($curPhoto) ?>" alt="Foto acuan" style="max-width:160px;max-height:120px;border-radius:6px;border:1px solid #ccc"></div>
        <?php endif; ?>
        <small style="color:#666;display:block;margin-top:4px">Foto ini dicetak di surat SPK supaya supplier tahu barang yang dimaksud, dan ditampilkan lagi di Barang Masuk sebagai pembanding.</small></div>
    </div>
    <p style="margin:14px 0 0"><button type="submit">Ajukan Permintaan</button></p>
  </form>
</div>
<script>
(function () {
  var sel = document.getElementById('existingSel');
  if (!sel) return;
  function sync() {
    var f = sel.form, on = sel.value !== '';
    f.item_name.disabled = on; f.unit_id.disabled = on;
    f.item_name.required = !on; f.unit_id.required = !on;
  }
  sel.addEventListener('change', sync); sync();
})();
</script>
<?php endif; ?>

<div class="panel scroll">
  <strong>Daftar permintaan</strong>
  <p style="margin:8px 0">Filter:
    <a href="manage_buyer.php">Semua</a>
    <?php foreach ($statusLabels as $k => $lbl): ?> | <a href="manage_buyer.php?status=<?= $k ?>"<?= $statusFilter === $k ? ' style="font-weight:700"' : '' ?>><?= e($lbl) ?></a><?php endforeach; ?>
  </p>
  <table>
    <tr><th>Foto acuan</th><th>Permintaan</th><th>Jumlah</th><th>Supplier usulan</th><th>Status</th><th>Tindakan</th></tr>
    <?php if (!$list): ?><tr><td colspan="6">Belum ada permintaan.</td></tr><?php endif; ?>
    <?php foreach ($list as $r):
        $ph = wmsValidRequestPath($r['reference_photo_path']); ?>
      <tr>
        <td><?php if ($ph !== ''): ?><a href="<?= e($ph) ?>" target="_blank"><img src="<?= e($ph) ?>" alt="Foto acuan" style="width:72px;height:56px;object-fit:cover;border-radius:6px;border:1px solid #ccc"></a><?php else: ?>-<?php endif; ?></td>
        <td><b><?= e($r['req_number']) ?></b><br><?= e($r['item_name']) ?>
          <?php if (!empty($r['specification'])): ?><br><small><?= e($r['specification']) ?></small><?php endif; ?>
          <br><small>Oleh <?= e($r['creator_name']) ?> &middot; <?= e($r['created_at']) ?><?= $r['needed_date'] ? ' &middot; butuh sebelum ' . e($r['needed_date']) : '' ?></small>
          <?php if (!empty($r['sku'])): ?><br><small>SKU master: <b><?= e($r['sku']) ?></b></small><?php endif; ?>
          <?php if (!empty($r['decision_note'])): ?><br><small>Catatan: <?= e($r['decision_note']) ?></small><?php endif; ?></td>
        <td><?= e(fmtQty($r['qty_requested'])) ?> <?= e($r['unit_code']) ?></td>
        <td><?= e($r['company_name'] ?? '-') ?></td>
        <td><?= buyerStatusTag($r['status'], $statusLabels) ?>
          <?php if ($r['status'] === 'SPK_ISSUED' && $r['po_id']): ?>
            <br><small><a href="print_spk.php?id=<?= (int)$r['po_id'] ?>" target="_blank"><?= e($r['spk_number'] ?: $r['po_number']) ?></a><br>
            Diterima <?= e(fmtQty($r['qty_received'])) ?> / <?= e(fmtQty($r['qty_ordered'])) ?><?= (float)$r['qty_received'] >= (float)$r['qty_ordered'] ? ' (lengkap)' : '' ?></small>
          <?php endif; ?></td>
        <td>
          <?php if ($r['status'] === 'REQUESTED' && $canRegister): ?>
            <details><summary style="cursor:pointer;color:#B91C1C">Daftarkan ke master</summary>
              <form method="post" style="margin-top:6px;display:grid;gap:6px;min-width:210px">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="register"><input type="hidden" name="req_id" value="<?= (int)$r['id'] ?>">
                <input type="text" name="sku" maxlength="40" placeholder="SKU baru, mis. HW-ENG-004" required>
                <select name="category_id" required><option value="">- kategori -</option>
                  <?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['category_code'] . ' - ' . $c['category_name']) ?></option><?php endforeach; ?>
                </select>
                <button type="submit">Daftarkan</button>
              </form></details>
          <?php endif; ?>
          <?php if ($r['status'] === 'REQUESTED' && $canReject): ?>
            <details><summary style="cursor:pointer;color:#B3261E">Tolak</summary>
              <form method="post" style="margin-top:6px;display:grid;gap:6px;min-width:210px">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="reject"><input type="hidden" name="req_id" value="<?= (int)$r['id'] ?>">
                <input type="text" name="note" maxlength="300" placeholder="Alasan penolakan" required>
                <button type="submit" class="danger">Tolak</button>
              </form></details>
          <?php endif; ?>
          <?php if ($r['status'] === 'REGISTERED' && $canCreate): ?>
            <a class="btn sec" style="padding:5px 14px" href="manage_po.php">Buat SPK</a>
          <?php endif; ?>
          <?php if (in_array($r['status'], ['REQUESTED', 'REGISTERED'], true) && ($canReject || (int)$r['created_by'] === (int)$_SESSION['user_id'])): ?>
            <form method="post" class="inline" style="margin-top:4px"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="req_id" value="<?= (int)$r['id'] ?>">
              <button type="submit" class="danger" style="padding:4px 12px" onclick="return confirm('Batalkan permintaan ini?')">Batalkan</button></form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php renderFooter();
