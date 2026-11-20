<?php
/**
 * Foto Acuan Barang: tiap barang di master diberi foto "barang seperti apa" dan spesifikasinya.
 * Foto & spesifikasi ini otomatis tercetak di surat PO dan jadi pembanding di Barang Masuk.
 * Hak akses: modul "master" (Lihat = daftar, Setujui = unggah/ubah; hanya Kepala Gudang & Super Admin,
 * sesuai matriks RBAC: perubahan master oleh peran lain harus lewat persetujuan).
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/photo_helper.php';
require_once __DIR__ . '/layout.php';
requirePermission('master', 'view');

$canEdit = canAccess('master', 'approve');
$csrf    = stockCsrf();
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    stockCheckCsrf();
    requirePermission('master', 'approve');
    $iid  = (int)($_POST['item_id'] ?? 0);
    $spec = trim((string)($_POST['specification'] ?? ''));
    if (mb_strlen($spec) > 500) $errors[] = 'Spesifikasi terlalu panjang (maks. 500 karakter).';

    $st = mysqli_prepare($connect, "SELECT id, sku, reference_photo_path FROM items WHERE id = ?");
    mysqli_stmt_bind_param($st, 'i', $iid);
    mysqli_stmt_execute($st);
    $item = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if (!$item) $errors[] = 'Barang tidak ditemukan.';

    $newPhoto = null;
    if (!$errors && isset($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $newPhoto = wmsSaveRequestPhoto($_FILES['photo'], $errors);
    }
    if (!$errors) {
        $specVal = $spec !== '' ? $spec : null;
        if ($newPhoto !== null) {
            $u = mysqli_prepare($connect, "UPDATE items SET specification = ?, reference_photo_path = ? WHERE id = ?");
            mysqli_stmt_bind_param($u, 'ssi', $specVal, $newPhoto, $iid);
        } else {
            $u = mysqli_prepare($connect, "UPDATE items SET specification = ? WHERE id = ?");
            mysqli_stmt_bind_param($u, 'si', $specVal, $iid);
        }
        if (mysqli_stmt_execute($u)) {
            logActivity('UPDATE', 'Foto Acuan Barang', "Foto/spesifikasi acuan barang {$item['sku']} diperbarui", $iid);
            $_SESSION['flash'] = ['ok', "Foto acuan & spesifikasi {$item['sku']} tersimpan."];
            header('Location: manage_item_photo.php' . (!empty($_GET['q']) ? '?q=' . urlencode((string)$_GET['q']) : ''));
            exit;
        }
        $errors[] = 'Gagal menyimpan. Pastikan migrasi database sudah dijalankan (migration_spk_dari_master.sql).';
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$colOk = false;
$cr = mysqli_query($connect, "SHOW COLUMNS FROM items LIKE 'reference_photo_path'");
if ($cr && mysqli_num_rows($cr) > 0) $colOk = true;

$q = trim((string)($_GET['q'] ?? ''));
$rows = [];
if ($colOk) {
    $sql = "SELECT i.id, i.sku, i.item_name, i.specification, i.reference_photo_path, i.is_active, u.unit_code, c.category_name
            FROM items i JOIN units u ON u.id = i.unit_id JOIN categories c ON c.id = i.category_id";
    if ($q !== '') {
        $like = '%' . $q . '%';
        $st = mysqli_prepare($connect, $sql . " WHERE i.sku LIKE ? OR i.item_name LIKE ? ORDER BY i.sku LIMIT 100");
        mysqli_stmt_bind_param($st, 'ss', $like, $like);
        mysqli_stmt_execute($st);
        $rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);
    } else {
        $rows = mysqli_fetch_all(mysqli_query($connect, $sql . " ORDER BY i.sku LIMIT 100"), MYSQLI_ASSOC);
    }
}

renderHeader('Foto Acuan Barang');
?>
<h2>Foto Acuan Barang</h2>
<p style="margin:-8px 0 16px;color:#666;font-size:13.5px">Barang baru ditambah di <a href="manage_item.php">Barang</a>, lalu beri foto acuan dan spesifikasinya di sini. Foto ini tercetak di surat <a href="manage_po.php">Purchase Order</a> untuk supplier dan tampil lagi di Barang Masuk sebagai pembanding.</p>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>
<?php if (!$colOk): ?><div class="err">Kolom foto belum ada. Jalankan <code>database\migration_spk_dari_master.sql</code> terlebih dahulu.</div><?php endif; ?>

<div class="panel scroll">
  <form method="get" style="margin-bottom:10px">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari SKU / nama barang..." style="max-width:260px;display:inline-block">
    <button type="submit" class="sec">Cari</button>
    <?php if ($q !== ''): ?><a class="btn sec" href="manage_item_photo.php">Reset</a><?php endif; ?>
  </form>
  <table>
    <tr><th style="width:100px">Foto</th><th>Barang</th><?php if ($canEdit): ?><th>Ubah foto &amp; spesifikasi</th><?php else: ?><th>Spesifikasi</th><?php endif; ?></tr>
    <?php if (!$rows): ?><tr><td colspan="3">Tidak ada barang.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $ph = wmsValidRequestPath($r['reference_photo_path'] ?? ''); ?>
    <tr>
      <td><?php if ($ph !== ''): ?><a href="<?= e($ph) ?>" target="_blank"><img src="<?= e($ph) ?>" alt="" style="width:90px;height:70px;object-fit:cover;border-radius:6px;border:1px solid #ccc"></a><?php else: ?><small>belum ada foto</small><?php endif; ?></td>
      <td><b><?= e($r['sku']) ?></b><br><?= e($r['item_name']) ?><br><small><?= e($r['category_name']) ?> &middot; <?= e($r['unit_code']) ?><?= (int)$r['is_active'] ? '' : ' &middot; nonaktif' ?></small></td>
      <td>
        <?php if ($canEdit): ?>
        <form method="post" enctype="multipart/form-data" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
          <input type="hidden" name="item_id" value="<?= (int)$r['id'] ?>">
          <input type="text" name="specification" maxlength="500" value="<?= e($r['specification'] ?? '') ?>" placeholder="Spesifikasi (ukuran, bahan, warna, merek)" style="margin-bottom:6px">
          <input type="file" name="photo" accept="image/jpeg,image/png,image/webp">
          <button type="submit" style="margin-left:6px">Simpan</button>
        </form>
        <?php else: ?><?= e($r['specification'] ?? '-') ?><?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php if (count($rows) >= 100): ?><p><small>Menampilkan 100 barang pertama; gunakan pencarian untuk menyaring.</small></p><?php endif; ?>
</div>
<?php renderFooter();
