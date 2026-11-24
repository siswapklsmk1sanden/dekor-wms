<?php
/**
 * Manajemen Pengguna (khusus Super Admin): tambah/ubah akun, NIK, peran,
 * reset password, buka kunci akun, aktif/nonaktif.
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
$me   = (int)$_SESSION['user_id'];
$errors = [];
$form = ['id' => 0, 'employee_nik' => '', 'full_name' => '', 'username' => '', 'email' => '', 'role_id' => ''];

$roles = [];
$rs = mysqli_query($connect, "SELECT id, role_code, role_name FROM roles ORDER BY id");
while ($rs && ($r = mysqli_fetch_assoc($rs))) $roles[$r['id']] = $r;

function activeAdmins($excludeId = 0)
{
    global $connect;
    $st = mysqli_prepare($connect, "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
        WHERE r.role_code = 'super_admin' AND u.is_active = 1 AND u.id <> ?");
    mysqli_stmt_bind_param($st, 'i', $excludeId);
    mysqli_stmt_execute($st);
    return (int)mysqli_fetch_row(mysqli_stmt_get_result($st))[0];
}

function flashRedirect($type, $msg)
{
    $_SESSION['flash'] = [$type, $msg];
    header('Location: manage_user.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, $_POST['csrf'] ?? '')) { http_response_code(400); die('Token keamanan tidak valid.'); }
    $act = $_POST['act'] ?? 'save';
    $id  = (int)($_POST['id'] ?? 0);

    $st = mysqli_prepare($connect, "SELECT u.*, r.role_code FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?");
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $target = mysqli_fetch_assoc(mysqli_stmt_get_result($st));

    if ($act === 'toggle' && $target) {
        if ($id === $me) flashRedirect('err', 'Anda tidak dapat menonaktifkan akun sendiri.');
        if ($target['is_active'] && $target['role_code'] === 'super_admin' && activeAdmins($id) < 1) {
            flashRedirect('err', 'Minimal harus ada satu Super Admin aktif.');
        }
        $st = mysqli_prepare($connect, "UPDATE users SET is_active = 1 - is_active WHERE id = ?");
        mysqli_stmt_bind_param($st, 'i', $id);
        mysqli_stmt_execute($st);
        logActivity('UPDATE', 'Users', ($target['is_active'] ? 'Menonaktifkan' : 'Mengaktifkan') . " pengguna {$target['username']}", $id);
        flashRedirect('ok', 'Status pengguna diperbarui.');
    }
    if ($act === 'unlock' && $target) {
        $st = mysqli_prepare($connect, "UPDATE users SET locked_until = NULL, failed_login_attempts = 0 WHERE id = ?");
        mysqli_stmt_bind_param($st, 'i', $id);
        mysqli_stmt_execute($st);
        logActivity('UPDATE', 'Users', "Membuka kunci akun {$target['username']}", $id);
        flashRedirect('ok', 'Akun dibuka kuncinya.');
    }

    if ($act === 'save') {
        foreach (['employee_nik', 'full_name', 'username', 'email', 'role_id'] as $k) $form[$k] = trim((string)($_POST[$k] ?? ''));
        $form['id'] = $id;
        $pass = (string)($_POST['password'] ?? '');
        $roleId = (int)$form['role_id'];

        if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $form['employee_nik'])) $errors[] = 'NIK 3-30 karakter (huruf, angka, . _ -).';
        if ($form['full_name'] === '' || mb_strlen($form['full_name']) > 120) $errors[] = 'Nama lengkap wajib (maks. 120 karakter).';
        if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $form['username'])) $errors[] = 'Username 3-50 karakter (huruf, angka, . _ -).';
        if (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($form['email']) > 150) $errors[] = 'Email tidak valid.';
        if (!isset($roles[$roleId])) $errors[] = 'Peran tidak valid.';
        if ($id === 0 && strlen($pass) < 8) $errors[] = 'Password minimal 8 karakter.';
        if ($id > 0 && $pass !== '' && strlen($pass) < 8) $errors[] = 'Password baru minimal 8 karakter.';
        if ($id > 0 && !$target) $errors[] = 'Pengguna tidak ditemukan.';

        if ($id > 0 && $target && !$errors) {
            if ($id === $me && $roleId !== (int)$target['role_id']) $errors[] = 'Anda tidak dapat mengubah peran akun sendiri.';
            if ($target['role_code'] === 'super_admin' && isset($roles[$roleId]) && $roles[$roleId]['role_code'] !== 'super_admin' && activeAdmins($id) < 1) {
                $errors[] = 'Minimal harus ada satu Super Admin aktif.';
            }
        }

        if (!$errors) {
            if ($id > 0) {
                if ($pass !== '') {
                    $hash = password_hash($pass, PASSWORD_BCRYPT);
                    $st = mysqli_prepare($connect, "UPDATE users SET employee_nik=?, full_name=?, username=?, email=?, role_id=?, password_hash=? WHERE id=?");
                    mysqli_stmt_bind_param($st, 'ssssisi', $form['employee_nik'], $form['full_name'], $form['username'], $form['email'], $roleId, $hash, $id);
                } else {
                    $st = mysqli_prepare($connect, "UPDATE users SET employee_nik=?, full_name=?, username=?, email=?, role_id=? WHERE id=?");
                    mysqli_stmt_bind_param($st, 'ssssii', $form['employee_nik'], $form['full_name'], $form['username'], $form['email'], $roleId, $id);
                }
            } else {
                $hash = password_hash($pass, PASSWORD_BCRYPT);
                $st = mysqli_prepare($connect, "INSERT INTO users (employee_nik, full_name, username, email, role_id, password_hash) VALUES (?,?,?,?,?,?)");
                mysqli_stmt_bind_param($st, 'ssssis', $form['employee_nik'], $form['full_name'], $form['username'], $form['email'], $roleId, $hash);
            }
            if (mysqli_stmt_execute($st)) {
                $newId = $id ?: mysqli_insert_id($connect);
                logActivity($id ? 'UPDATE' : 'CREATE', 'Users',
                    ($id ? 'Memperbarui' : 'Menambahkan') . " pengguna {$form['username']} (peran {$roles[$roleId]['role_code']})" . ($pass !== '' && $id ? ', password direset' : ''), $newId);
                flashRedirect('ok', $id ? 'Pengguna diperbarui.' : 'Pengguna ditambahkan.');
            }
            $errors[] = mysqli_errno($connect) === 1062 ? 'NIK, username, atau email sudah dipakai pengguna lain.' : 'Gagal menyimpan: ' . mysqli_error($connect);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['edit'])) {
    $st = mysqli_prepare($connect, "SELECT id, employee_nik, full_name, username, email, role_id FROM users WHERE id = ?");
    $eid = (int)$_GET['edit'];
    mysqli_stmt_bind_param($st, 'i', $eid);
    mysqli_stmt_execute($st);
    if ($row = mysqli_fetch_assoc(mysqli_stmt_get_result($st))) $form = $row;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$users = mysqli_fetch_all(mysqli_query($connect, "SELECT u.*, r.role_name FROM users u JOIN roles r ON r.id = u.role_id ORDER BY u.id"), MYSQLI_ASSOC);

renderHeader('Pengguna');
?>
<h2>Manajemen Pengguna</h2>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>
<style>
  .uform .fgrid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px 16px}
  .uform label{display:block;margin-bottom:6px;font-size:13px}
  .uform input[type=text],.uform input[type=password],.uform select{
    width:100%;box-sizing:border-box;height:40px;padding:0 12px;font:inherit;font-size:14px}
  .uform .actions{margin-top:18px;display:flex;gap:10px;align-items:center}
  @media (max-width:800px){.uform .fgrid{grid-template-columns:1fr}}
</style>
<div class="panel uform">
  <strong><?= $form['id'] ? 'Ubah pengguna' : 'Tambah pengguna' ?></strong>
  <form method="post" autocomplete="off" style="margin-top:14px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
    <div class="fgrid">
      <div><label>NIK karyawan *</label><input type="text" name="employee_nik" value="<?= e($form['employee_nik']) ?>" placeholder="DAJ-0006" required></div>
      <div><label>Nama lengkap *</label><input type="text" name="full_name" value="<?= e($form['full_name']) ?>" required></div>
      <div><label>Username *</label><input type="text" name="username" value="<?= e($form['username']) ?>" required></div>
      <div><label>Email *</label><input type="text" name="email" value="<?= e($form['email']) ?>" required></div>
      <div><label>Peran *</label><select name="role_id" required><option value="">- pilih -</option>
        <?php foreach ($roles as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (string)$r['id'] === (string)$form['role_id'] ? 'selected' : '' ?>><?= e($r['role_name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Password <?= $form['id'] ? '(kosongkan jika tidak diganti)' : '* (min. 8 karakter)' ?></label><input type="password" name="password" autocomplete="new-password"></div>
    </div>
    <div class="actions">
      <button type="submit">Simpan</button>
      <?php if ($form['id']): ?><a class="btn sec" href="manage_user.php">Batal</a><?php endif; ?>
    </div>
  </form>
</div>
<div class="panel scroll">
  <table>
    <tr><th>NIK</th><th>Nama</th><th>Username</th><th>Email</th><th>Peran</th><th>Status</th><th>Login terakhir</th><th></th></tr>
    <?php foreach ($users as $u): $locked = $u['locked_until'] && strtotime($u['locked_until']) > time(); ?>
      <tr><td><?= e($u['employee_nik']) ?></td><td><?= e($u['full_name']) ?></td><td><?= e($u['username']) ?></td><td><?= e($u['email']) ?></td>
        <td><?= e($u['role_name']) ?></td>
        <td><span class="tag <?= $u['is_active'] ? '' : 'off' ?>"><?= $u['is_active'] ? 'Aktif' : 'Nonaktif' ?></span><?= $locked ? ' <span class="tag off">Terkunci</span>' : '' ?></td>
        <td><?= e($u['last_login_at'] ?? '-') ?></td>
        <td style="white-space:nowrap">
          <a class="btn sec" href="?edit=<?= (int)$u['id'] ?>">Ubah</a>
          <?php if ($locked): ?><form class="inline" method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="unlock"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button>Buka kunci</button></form><?php endif; ?>
          <?php if ((int)$u['id'] !== $me): ?><form class="inline" method="post" onsubmit="return confirm('Ubah status pengguna ini?')"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="danger"><?= $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form><?php endif; ?>
        </td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php renderFooter();