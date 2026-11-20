<?php
require_once __DIR__ . '/config.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/admin/index.php');
    exit;
}

$error = '';
$keepUser = ($_SERVER['REQUEST_METHOD'] === 'POST') ? trim((string)($_POST['username'] ?? '')) : '';
if (empty($_SESSION['login_csrf'])) $_SESSION['login_csrf'] = bin2hex(random_bytes(16));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !hash_equals($_SESSION['login_csrf'], (string)($_POST['csrf'] ?? ''))) {
    $_SERVER['REQUEST_METHOD'] = 'CSRF_FAIL';
    $error = 'Sesi formulir kedaluwarsa. Silakan coba masuk lagi.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Captcha dicek dulu (huruf besar/kecil dibedakan), sekali pakai
    $captchaOk = !empty($_SESSION['login_captcha'])
        && hash_equals($_SESSION['login_captcha'], (string)($_POST['captcha'] ?? ''));
    unset($_SESSION['login_captcha']);

    if (!$captchaOk) {
        $error = 'Kode captcha salah. Silakan coba lagi.';
    } else {
        $username = escapeString($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $rs = mysqli_query($connect, "SELECT u.*, r.role_code FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE u.username = '$username' LIMIT 1");
        $u = $rs ? mysqli_fetch_assoc($rs) : null;

        if ($u && $u['locked_until'] && strtotime($u['locked_until']) > time()) {
            $error = 'Akun terkunci sementara. Coba lagi beberapa menit lagi.';
        } elseif ($u && (int)$u['is_active'] === 1 && password_verify($password, $u['password_hash'])) {
            session_regenerate_id(true);
            unset($_SESSION['login_csrf']);
            $_SESSION['user_id']   = (int)$u['id'];
            $_SESSION['username']  = $u['username'];
            $_SESSION['full_name'] = $u['full_name'];
            $_SESSION['role_id']   = (int)$u['role_id'];
            $_SESSION['role_code'] = $u['role_code'];
            $_SESSION['weak_pw']   = ($password === '123456' || strlen($password) < 8);
            $_SESSION['last_activity'] = time();
            mysqli_query($connect, "UPDATE users SET failed_login_attempts = 0,
                    locked_until = NULL, last_login_at = NOW() WHERE id = " . (int)$u['id']);
            logActivity('LOGIN', 'Users', 'Login berhasil: ' . $u['username'], $u['id']);
            header('Location: ' . BASE_URL . '/admin/index.php');
            exit;
        } else {
            if ($u) {
                $fails = (int)$u['failed_login_attempts'] + 1;
                $lock  = $fails >= 5 ? ", locked_until = DATE_ADD(NOW(), INTERVAL 5 MINUTE), failed_login_attempts = 0" : ", failed_login_attempts = $fails";
                mysqli_query($connect, "UPDATE users SET id = id $lock WHERE id = " . (int)$u['id']);
            }
            $error = 'Username atau password salah.';
        }
    }
}

// Cari otomatis assets/header-bg.* (jpg/jpeg/png/webp), ?v= supaya foto baru langsung tampil
$bgFile = glob(__DIR__ . '/assets/header-bg.{jpg,jpeg,png,webp}', GLOB_BRACE);
$bgUrl  = $bgFile ? 'assets/' . basename($bgFile[0]) . '?v=' . filemtime($bgFile[0]) : '';
$bgCss  = $bgUrl ? "url('" . $bgUrl . "')" : 'none';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login - WMS Dekor Asia Jayakarya</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script>(function(){try{var m=document.cookie.match(/(?:^|; )wmsTheme=(\w+)/),c=m?m[1]:null,l=null;try{l=localStorage.getItem('wmsTheme')}catch(e){}var t=l||c;if(!t&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)t='dark';if(t==='dark')document.documentElement.setAttribute('data-theme','dark');else document.documentElement.removeAttribute('data-theme')}catch(e){}})();</script>
<style>
  *{box-sizing:border-box}
  body{font-family:Inter,"Segoe UI",system-ui,Arial,sans-serif;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
       background:linear-gradient(135deg,rgba(15,23,42,.92) 0%,rgba(30,41,59,.88) 60%,rgba(153,27,27,.80) 100%),<?= $bgCss ?> center/cover #0F172A}
  .box{background:#fff;padding:34px;border-radius:16px;width:360px;max-width:92vw;box-shadow:0 20px 60px rgba(0,0,0,.45);border-top:4px solid #DC2626}
  h1{font-family:Outfit,Inter,Arial,sans-serif;font-weight:700;font-size:20px;margin:0 0 4px;color:#0F172A;letter-spacing:.2px}
  h1 span{font-weight:400;color:#DC2626}
  p{margin:0 0 18px;color:#666;font-size:13px}
  label{display:block;font-size:13px;font-weight:500;margin:12px 0 4px;color:#444}
  input{width:100%;padding:11px 12px;border:1px solid #cbd5e1;border-radius:8px;font-family:inherit;font-size:14px}
  input:focus{outline:2px solid #DC2626;border-color:#B91C1C}
  @media(max-width:600px){input{font-size:16px;min-height:46px}button.go{min-height:48px}}
  .cap{display:flex;align-items:center;gap:10px}
  .cap img{height:48px;width:166px;border-radius:6px;display:block}
  .cap a{font-size:12px;color:#DC2626;cursor:pointer;text-decoration:underline}
  button.go{width:100%;margin-top:20px;padding:13px;border:0;border-radius:8px;background:linear-gradient(135deg,#DC2626,#991B1B);color:#fff;font-family:Outfit,Inter,Arial,sans-serif;font-size:15px;font-weight:600;letter-spacing:.2px;cursor:pointer;box-shadow:0 4px 20px rgba(220,38,38,.35);transition:transform .15s,box-shadow .15s}
  button.go:hover{transform:translateY(-1px);box-shadow:0 8px 24px rgba(220,38,38,.45)}
  .err{background:#FDECEA;color:#B3261E;padding:9px 12px;border-radius:6px;font-size:13px;margin-bottom:8px;border-left:4px solid #B3261E}
  .thm{position:fixed;top:14px;right:14px;width:40px;height:40px;padding:0;border-radius:12px;border:1px solid rgba(255,255,255,.5);background:rgba(15,23,42,.6);color:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center}
  .thm:hover{border-color:#DC2626;color:#fca5a5}
  .thm svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  .thm .i-sun{display:none}
  html[data-theme=dark] .thm .i-moon{display:none}
  html[data-theme=dark] .thm .i-sun{display:block}
  html[data-theme=dark] body{background:linear-gradient(rgba(10,12,14,.88),rgba(10,12,14,.88)),<?= $bgCss ?> center/cover #0d0f11}
  html[data-theme=dark] .box{background:#111827;box-shadow:0 10px 40px rgba(0,0,0,.7)}
  html[data-theme=dark] h1{color:#f3f4f5}
  html[data-theme=dark] p{color:#aab0b6}
  html[data-theme=dark] label{color:#c3c8cd}
  html[data-theme=dark] input{background:#0b1220;border-color:#334155;color:#e2e8f0}
  html[data-theme=dark] .err{background:#3a1b19;color:#ff9b93;border-left-color:#ff6b61}
</style>
</head>
<body>
<button type="button" class="thm" id="thm" title="Ganti mode gelap / terang" aria-label="Ganti mode gelap atau terang">
  <svg class="i-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/></svg>
  <svg class="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
</button>
<script>document.getElementById('thm').addEventListener('click',function(){var r=document.documentElement,d=r.getAttribute('data-theme')==='dark',v=d?'light':'dark';if(d)r.removeAttribute('data-theme');else r.setAttribute('data-theme','dark');document.cookie='wmsTheme='+v+';path=/;max-age=31536000;SameSite=Lax';try{localStorage.setItem('wmsTheme',v)}catch(e){}});</script>
<form class="box" method="post" autocomplete="off">
  <h1>WMS Dekor Asia <span>Jayakarya</span></h1>
  <p>Masuk untuk melanjutkan</p>
  <input type="hidden" name="csrf" value="<?= e($_SESSION['login_csrf']) ?>">
  <?php if ($error): ?><div class="err"><?= e($error) ?></div><?php endif; ?>

  <label>Username</label>
  <input name="username" required value="<?= e($keepUser) ?>" <?= $keepUser === '' ? 'autofocus' : '' ?>>

  <label>Password</label>
  <input name="password" type="password" required <?= $keepUser !== '' ? 'autofocus' : '' ?>>

  <label>Kode captcha <span style="font-weight:400;color:#999">(huruf besar/kecil berbeda)</span></label>
  <div class="cap">
    <img id="capimg" src="captcha.php?r=<?= time() ?>" alt="captcha">
    <a id="caprefresh">Ganti</a>
  </div>
  <input name="captcha" maxlength="6" required placeholder="Ketik 6 karakter di atas" style="margin-top:8px">

  <button class="go" type="submit">Masuk</button>
</form>
<script>
document.getElementById('caprefresh').onclick = function () {
  document.getElementById('capimg').src = 'captcha.php?r=' + Date.now();
  document.querySelector('input[name=captcha]').value = '';
};
</script>
</body>
</html>