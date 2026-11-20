<?php
/**
 * Profil, foto profil, ubah username & ganti password sendiri (semua peran yang sudah login).
 * Butuh kolom users.photo_path (lihat langkah SQL).
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/layout.php';
requireLogin();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$uid  = (int)$_SESSION['user_id'];
$errors = [];
$ok = null;

const AVATAR_DIR_REL = 'uploads/avatars';
const AVATAR_MAX_BYTES = 2 * 1024 * 1024; // 2 MB

/** Pastikan folder avatar ada & tidak bisa mengeksekusi PHP. */
function ensureAvatarDir(): string {
    $dir = __DIR__ . '/' . AVATAR_DIR_REL;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $ht = $dir . '/.htaccess';
    $htBody = "<FilesMatch \"\\.(php|phtml|phar)$\">\nRequire all denied\n</FilesMatch>\n";
    // Tulis ulang jika belum ada, atau masih versi lama yang memakai php_flag (memicu error 500)
    if (!file_exists($ht) || strpos((string)@file_get_contents($ht), 'php_flag') !== false) {
        @file_put_contents($ht, $htBody);
    }
    return $dir;
}

/** Hapus file foto lama (hanya jika berada di folder avatar). */
function deleteOldAvatar(?string $rel): void {
    if (!$rel || strpos($rel, AVATAR_DIR_REL . '/') !== 0 || strpos($rel, '..') !== false) return;
    $full = __DIR__ . '/' . $rel;
    if (is_file($full)) @unlink($full);
}

/** Validasi, potong persegi, kecilkan 256x256, simpan sebagai JPG. Return path relatif atau null. */
function processAvatar(array $f, int $uid, array &$errors): ?string {
    if (!function_exists('imagecreatetruecolor')) { $errors[] = 'Ekstensi PHP GD belum aktif di server.'; return null; }
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { $errors[] = 'Pilih file foto terlebih dahulu.'; return null; }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > AVATAR_MAX_BYTES) {
        $errors[] = 'Ukuran foto maksimal 2 MB.'; return null;
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { $errors[] = 'Unggah foto gagal, coba lagi.'; return null; }

    $info = @getimagesize($f['tmp_name']);
    $mime = $info['mime'] ?? '';
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($f['tmp_name']); break;
        case 'image/png':  $src = @imagecreatefrompng($f['tmp_name']); break;
        case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($f['tmp_name']) : false; break;
        default: $src = false;
    }
    if (!$src) { $errors[] = 'Format foto harus JPG, PNG, atau WEBP yang valid.'; return null; }

    $w = imagesx($src); $h = imagesy($src);
    $side = min($w, $h);
    $sx = (int)(($w - $side) / 2); $sy = (int)(($h - $side) / 2);
    $dst = imagecreatetruecolor(256, 256);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, 256, 256, $side, $side);

    $dir = ensureAvatarDir();
    $name = 'u' . $uid . '_' . bin2hex(random_bytes(6)) . '.jpg';
    $ok = @imagejpeg($dst, $dir . '/' . $name, 85);
    // PHP >= 8.0: objek GdImage dibersihkan otomatis, imagedestroy() tidak perlu (deprecated di 8.5)
    unset($src, $dst);
    if (!$ok) { $errors[] = 'Gagal menyimpan foto. Periksa izin folder ' . AVATAR_DIR_REL . '.'; return null; }
    return AVATAR_DIR_REL . '/' . $name;
}

$st = mysqli_prepare($connect, "SELECT u.*, r.role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?");
mysqli_stmt_bind_param($st, 'i', $uid);
mysqli_stmt_execute($st);
$me = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
if (!$me) { session_destroy(); header('Location: login.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Jika file melebihi post_max_size, PHP mengosongkan $_POST
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'Ukuran data terlalu besar. Foto maksimal 2 MB.';
    } else {
        if (!hash_equals($csrf, $_POST['csrf'] ?? '')) { http_response_code(400); die('Token keamanan tidak valid.'); }
        $action = $_POST['action'] ?? 'password';

        if ($action === 'photo_upload') {
            $path = processAvatar($_FILES['photo'] ?? [], $uid, $errors);
            if ($path) {
                $old = $me['photo_path'] ?? null;
                $st = mysqli_prepare($connect, "UPDATE users SET photo_path = ? WHERE id = ?");
                mysqli_stmt_bind_param($st, 'si', $path, $uid);
                mysqli_stmt_execute($st);
                deleteOldAvatar($old);
                $me['photo_path'] = $path;
                logActivity('UPDATE', 'Users', "Pengguna {$me['username']} mengganti foto profil", $uid);
                $ok = 'Foto profil berhasil diperbarui.';
            }
        } elseif ($action === 'photo_delete') {
            $old = $me['photo_path'] ?? null;
            $st = mysqli_prepare($connect, "UPDATE users SET photo_path = NULL WHERE id = ?");
            mysqli_stmt_bind_param($st, 'i', $uid);
            mysqli_stmt_execute($st);
            deleteOldAvatar($old);
            $me['photo_path'] = null;
            logActivity('UPDATE', 'Users', "Pengguna {$me['username']} menghapus foto profil", $uid);
            $ok = 'Foto profil dihapus.';
        } elseif ($action === 'username') {
            // Ubah username sendiri: wajib password saat ini, format ketat, tidak boleh dipakai pengguna lain.
            $newU = trim((string)($_POST['username'] ?? ''));
            $cur  = (string)($_POST['current'] ?? '');
            $oldU = (string)$me['username'];

            if (!password_verify($cur, $me['password_hash'])) $errors[] = 'Password saat ini salah.';
            if (!preg_match('/^[A-Za-z0-9._-]{3,30}$/', $newU)) {
                $errors[] = 'Username 3-30 karakter: huruf, angka, titik, garis bawah, atau strip (tanpa spasi).';
            } elseif ($newU === $oldU) {
                $errors[] = 'Username baru sama dengan yang lama.';
            }
            if (!$errors) {
                $st = mysqli_prepare($connect, "SELECT id FROM users WHERE username = ? AND id <> ? LIMIT 1");
                mysqli_stmt_bind_param($st, 'si', $newU, $uid);
                mysqli_stmt_execute($st);
                if (mysqli_fetch_row(mysqli_stmt_get_result($st))) $errors[] = 'Username sudah dipakai pengguna lain.';
            }
            if (!$errors) {
                try {
                    $st = mysqli_prepare($connect, "UPDATE users SET username = ? WHERE id = ?");
                    mysqli_stmt_bind_param($st, 'si', $newU, $uid);
                    if (!mysqli_stmt_execute($st)) {
                        $errors[] = mysqli_errno($connect) === 1062 ? 'Username sudah dipakai pengguna lain.' : 'Gagal menyimpan username.';
                    }
                } catch (Throwable $ex) {
                    $errors[] = ((int)$ex->getCode() === 1062) ? 'Username sudah dipakai pengguna lain.' : 'Gagal menyimpan username.';
                }
                if (!$errors) {
                    $me['username'] = $newU;
                    $_SESSION['username'] = $newU;
                    logActivity('UPDATE', 'Users', "Pengguna mengganti username dari $oldU menjadi $newU", $uid);
                    $ok = 'Username berhasil diganti. Gunakan username baru saat login berikutnya.';
                }
            }
        } else {
            $cur = (string)($_POST['current'] ?? '');
            $new = (string)($_POST['new'] ?? '');
            $rep = (string)($_POST['repeat'] ?? '');

            if (!password_verify($cur, $me['password_hash'])) $errors[] = 'Password saat ini salah.';
            if (strlen($new) < 8) $errors[] = 'Password baru minimal 8 karakter.';
            if (!preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) $errors[] = 'Password baru harus mengandung huruf dan angka.';
            if (in_array(strtolower($new), ['password1', 'password123', 'qwerty123', 'admin123', '12345678a', 'abcd1234'], true)
                || strtolower($new) === strtolower($me['username']) || preg_match('/^(.)\1+$/', $new)) {
                $errors[] = 'Password baru terlalu mudah ditebak.';
            }
            if ($new !== $rep) $errors[] = 'Konfirmasi password tidak sama.';
            if ($cur !== '' && $new === $cur) $errors[] = 'Password baru harus berbeda dari yang lama.';

            if (!$errors) {
                $hash = password_hash($new, PASSWORD_BCRYPT);
                $st = mysqli_prepare($connect, "UPDATE users SET password_hash = ?, failed_login_attempts = 0, locked_until = NULL WHERE id = ?");
                mysqli_stmt_bind_param($st, 'si', $hash, $uid);
                mysqli_stmt_execute($st);
                session_regenerate_id(true);
                $_SESSION['weak_pw'] = false;
                logActivity('UPDATE', 'Users', "Pengguna {$me['username']} mengganti password sendiri", $uid);
                $ok = 'Password berhasil diganti.';
            }
        }
    }
}

$photo = $me['photo_path'] ?? null;
if ($photo) ensureAvatarDir(); // sekaligus memperbaiki .htaccess lama bila perlu
$initial = strtoupper(mb_substr($me['full_name'], 0, 1));

renderHeader('Profil');
?>
<h2>Profil Saya</h2>
<div class="panel">
  <div style="display:flex;gap:28px;flex-wrap:wrap;align-items:flex-start">
    <div style="text-align:center;width:160px">
      <?php if ($photo): ?>
        <img src="<?= e($photo) ?>?v=<?= (int)@filemtime(__DIR__ . '/' . $photo) ?>" alt="Foto profil"
             style="width:128px;height:128px;border-radius:50%;object-fit:cover;border:3px solid #e8b83a">
      <?php else: ?>
        <div style="width:128px;height:128px;border-radius:50%;background:#e8b83a;color:#1a1a1a;font-size:52px;font-weight:700;display:flex;align-items:center;justify-content:center;margin:0 auto"><?= e($initial) ?></div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" style="margin-top:12px">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="photo_upload">
        <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" required style="width:100%;font-size:12px">
        <button type="submit" style="margin-top:8px">Unggah Foto</button>
        <div style="font-size:11px;color:#777;margin-top:6px">JPG/PNG/WEBP, maks. 2 MB</div>
      </form>
      <?php if ($photo): ?>
      <form method="post" style="margin-top:6px" onsubmit="return confirm('Hapus foto profil?')">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="photo_delete">
        <button type="submit" style="background:none;border:none;color:#c0392b;cursor:pointer;font-size:12px;text-decoration:underline">Hapus foto</button>
      </form>
      <?php endif; ?>
    </div>

    <table style="max-width:520px;flex:1;min-width:280px">
      <tr><th style="width:140px">Nama</th><td><?= e($me['full_name']) ?></td></tr>
      <tr><th>NIK</th><td><?= e($me['employee_nik']) ?></td></tr>
      <tr><th>Username</th><td><?= e($me['username']) ?></td></tr>
      <tr><th>Email</th><td><?= e($me['email']) ?></td></tr>
      <tr><th>Peran</th><td><?= e($me['role_name']) ?></td></tr>
      <tr><th>Login terakhir</th><td><?= e($me['last_login_at'] ?? '-') ?></td></tr>
    </table>
  </div>
</div>
<?php if ($ok): ?><div class="ok"><?= e($ok) ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>
<div class="panel">
  <strong>Ubah username</strong>
  <form method="post" autocomplete="off" style="margin-top:12px;max-width:380px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="username">
    <p><label>Username baru</label><input type="text" name="username" value="<?= e($_POST['username'] ?? $me['username']) ?>" required minlength="3" maxlength="30" pattern="[A-Za-z0-9._\-]{3,30}" autocomplete="off" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px"></p>
    <p><label>Password saat ini (untuk konfirmasi)</label><input type="password" name="current" required autocomplete="current-password" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px"></p>
    <div style="font-size:12px;color:#777;margin:-4px 0 10px">3-30 karakter: huruf, angka, titik, garis bawah, atau strip.</div>
    <button type="submit">Simpan Username</button>
  </form>
</div>
<div class="panel">
  <strong>Ganti password</strong>
  <form method="post" autocomplete="off" style="margin-top:12px;max-width:380px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <input type="hidden" name="action" value="password">
    <p><label>Password saat ini</label><input type="password" name="current" required autocomplete="current-password" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px"></p>
    <p><label>Password baru (min. 8 karakter)</label><input type="password" name="new" required minlength="8" autocomplete="new-password" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px"></p>
    <p><label>Ulangi password baru</label><input type="password" name="repeat" required minlength="8" autocomplete="new-password" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px"></p>
    <button type="submit">Simpan Password</button>
  </form>
</div>
<?php renderFooter();