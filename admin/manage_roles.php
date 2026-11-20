<?php
/**
 * Matriks Hak Akses per peran dan modul (khusus Super Admin).
 * Peran super_admin dikunci agar tidak bisa mengunci diri sendiri.
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/layout.php';
requireLogin();
if (($_SESSION['role_code'] ?? '') !== 'super_admin') {
    http_response_code(403);
    die('Akses ditolak: hanya Super Admin.');
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];

$modules = ['catalog' => 'Katalog & Stok', 'master' => 'Master Data', 'inbound' => 'Barang Masuk',
            'outbound' => 'Barang Keluar & Transfer', 'bom' => 'BoM', 'opname' => 'Stock Opname', 'audit' => 'Audit Log',
            'buyer' => 'Cetak Surat PO ke Supplier', 'payable' => 'Hutang Supplier (faktur, bayar, kredit retur)'];
$cols = ['can_view' => 'Lihat', 'can_create' => 'Buat', 'can_approve' => 'Setujui', 'hide_price' => 'Sembunyikan harga'];

$roles = mysqli_fetch_all(mysqli_query($connect, "SELECT id, role_code, role_name FROM roles ORDER BY id"), MYSQLI_ASSOC);
$roleId = (int)($_GET['role'] ?? $_POST['role_id'] ?? ($roles[0]['id'] ?? 0));
$role = null;
foreach ($roles as $r) if ((int)$r['id'] === $roleId) $role = $r;
if (!$role) { $role = $roles[0]; $roleId = (int)$role['id']; }
$locked = $role['role_code'] === 'super_admin';

$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) { http_response_code(400); die('Token keamanan tidak valid.'); }
    if ($locked) {
        $msg = ['err', 'Hak akses Super Admin dikunci dan tidak dapat diubah.'];
    } else {
        mysqli_begin_transaction($connect);
        $st = mysqli_prepare($connect, "INSERT INTO role_permissions (role_id, module_name, can_view, can_create, can_approve, hide_price)
            VALUES (?,?,?,?,?,?) ON DUPLICATE KEY UPDATE can_view=VALUES(can_view), can_create=VALUES(can_create),
            can_approve=VALUES(can_approve), hide_price=VALUES(hide_price)");
        $ok = true;
        foreach ($modules as $m => $_) {
            $v = !empty($_POST['p'][$m]['can_view']) ? 1 : 0;
            // Buat/Setujui hanya bermakna jika boleh melihat
            $c = $v && !empty($_POST['p'][$m]['can_create']) ? 1 : 0;
            $a = $v && !empty($_POST['p'][$m]['can_approve']) ? 1 : 0;
            $h = !empty($_POST['p'][$m]['hide_price']) ? 1 : 0;
            mysqli_stmt_bind_param($st, 'isiiii', $roleId, $m, $v, $c, $a, $h);
            $ok = $ok && mysqli_stmt_execute($st);
        }
        if ($ok) {
            mysqli_commit($connect);
            logActivity('UPDATE', 'Role Permissions', "Memperbarui matriks hak akses peran {$role['role_code']}", $roleId);
            $msg = ['ok', 'Hak akses disimpan. Berlaku pada pemuatan halaman berikutnya bagi pengguna peran ini.'];
        } else {
            mysqli_rollback($connect);
            $msg = ['err', 'Gagal menyimpan: ' . mysqli_error($connect)];
        }
    }
}

$perm = [];
$st = mysqli_prepare($connect, "SELECT * FROM role_permissions WHERE role_id = ?");
mysqli_stmt_bind_param($st, 'i', $roleId);
mysqli_stmt_execute($st);
foreach (mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC) as $r) $perm[$r['module_name']] = $r;

renderHeader('Hak Akses');
?>
<h2>Matriks Hak Akses</h2>
<?php if ($msg): ?><div class="<?= $msg[0] ?>"><?= e($msg[1]) ?></div><?php endif; ?>
<div class="panel">
  <form method="get" style="display:flex;gap:8px;align-items:center">
    <label style="margin:0">Peran:</label>
    <select name="role" onchange="this.form.submit()" style="max-width:260px">
      <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (int)$r['id'] === $roleId ? 'selected' : '' ?>><?= e($r['role_name']) ?></option><?php endforeach; ?>
    </select>
    <?php if ($locked): ?><span class="tag off">Dikunci</span><?php endif; ?>
  </form>
</div>
<div class="panel scroll">
  <form method="post">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="role_id" value="<?= $roleId ?>">
    <table>
      <tr><th>Modul</th><?php foreach ($cols as $c => $l): ?><th style="text-align:center"><?= e($l) ?></th><?php endforeach; ?></tr>
      <?php foreach ($modules as $m => $label): $p = $perm[$m] ?? []; ?>
        <tr><td><?= e($label) ?> <small>(<?= e($m) ?>)</small></td>
          <?php foreach ($cols as $c => $l): ?>
            <td style="text-align:center"><input type="checkbox" name="p[<?= e($m) ?>][<?= e($c) ?>]" value="1" <?= !empty($p[$c]) ? 'checked' : '' ?> <?= $locked ? 'disabled' : '' ?>></td>
          <?php endforeach; ?></tr>
      <?php endforeach; ?>
    </table>
    <?php if (!$locked): ?><p><button type="submit">Simpan Hak Akses</button></p><?php endif; ?>
  </form>
</div>
<?php renderFooter();
