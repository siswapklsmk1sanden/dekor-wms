<?php
/**
 * Diagnosa pemasangan: cek PHP, ekstensi, koneksi MySQL, database & tabel.
 * Tidak mengubah data apa pun.
 * Akses:
 *  - Sudah login sebagai Super Admin IT -> boleh dari komputer mana pun.
 *  - Belum login -> hanya dari localhost DAN hanya selama sistem belum siap
 *    (database/tabel/pengguna belum ada), supaya pemasangan awal tetap bisa didiagnosa.
 *  - Selain itu -> diarahkan ke halaman login.
 */
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('WMSSESSID');
    $wmsSessDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wms_sessions';
    if (!is_dir($wmsSessDir)) { @mkdir($wmsSessDir, 0700, true); }
    if (is_dir($wmsSessDir) && is_writable($wmsSessDir)) { session_save_path($wmsSessDir); }
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $isHttps, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
$isSuperAdmin = !empty($_SESSION['user_id']) && ($_SESSION['role_code'] ?? '') === 'super_admin'
    && (time() - (int)($_SESSION['last_activity'] ?? time())) <= 4 * 3600;
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
if (!$isSuperAdmin && !$isLocal) {
    header('Location: login.php');
    exit;
}
$usersReady = false;   // diisi true bila tabel users ada dan berisi
mysqli_report(MYSQLI_REPORT_OFF);

// Ambil kredensial dari config.php tanpa menjalankannya (config.php berhenti bila DB gagal)
$cfg = (is_file(__DIR__ . '/config.local.php') ? (string)@file_get_contents(__DIR__ . '/config.local.php') . "\n" : '')
     . ((string)@file_get_contents(__DIR__ . '/config.php'));   // config.local.php dibaca lebih dulu (menimpa bawaan)
$get = function ($name, $default) use ($cfg) {
    // Kecocokan pertama menang: config.local.php (jika ada) ditaruh di depan config.php.
    if (!preg_match_all('/\$' . $name . '\s*=\s*(?:\'([^\']*)\'|"([^"]*)"|(\d+))\s*;/', $cfg, $all, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL)) return $default;
    $m = $all[0];
    foreach ([1, 2, 3] as $i) if ($m[$i] !== null) return $m[$i];
    return $default;
};
$host = $get('db_host', 'localhost'); $user = $get('db_user', 'root');
$pass = $get('db_pass', '');          $name = $get('db_name', 'dekorwms'); $port = (int)$get('db_port', 3306);

$checks = [];
$add = function ($ok, $title, $detail = '', $fix = '') use (&$checks) { $checks[] = [$ok, $title, $detail, $fix]; };

$add(version_compare(PHP_VERSION, '8.0.0', '>='), 'Versi PHP ' . PHP_VERSION, 'Dibutuhkan 8.0 atau lebih baru.', 'Di Laragon: Menu > PHP > pilih versi 8.x.');
foreach (['mysqli', 'openssl', 'mbstring', 'json', 'session'] as $ext) {
    $add(extension_loaded($ext), "Ekstensi $ext", '', "Aktifkan extension=$ext di php.ini (Laragon: Menu > PHP > Extensions).");
}

$link = @mysqli_connect($host, $user, $pass, '', $port);
$add((bool)$link, "Koneksi MySQL ($user@$host:$port)", $link ? 'Server MySQL menjawab.' : (mysqli_connect_error() ?: 'Tidak dapat terhubung.'),
    'Pastikan MySQL berstatus hijau di Laragon (Start All) dan cocokkan $db_user/$db_pass di admin/config.php.');

$expected = ['roles','role_permissions','users','warehouses','storage_bins','categories','units','suppliers','items',
             'item_batches','stock_balances','inventory_transactions','inventory_ledger_entries','bom_headers','bom_components',
             'audit_logs','opname_sessions', 'trx_drafts','opname_counts',
             'purchase_orders','purchase_order_lines','po_receipts','buyer_requests'];
if ($link) {
    $dbEsc = mysqli_real_escape_string($link, $name);
    $rs = mysqli_query($link, "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$dbEsc'");
    $hasDb = $rs && mysqli_num_rows($rs) > 0;
    $add($hasDb, "Database '$name'", $hasDb ? 'Ada.' : 'Belum dibuat.', 'Import file database/dekorwms.sql lewat HeidiSQL atau phpMyAdmin.');
    if ($hasDb) {
        $have = [];
        $rs = mysqli_query($link, "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = '$dbEsc'");
        while ($rs && ($r = mysqli_fetch_row($rs))) $have[] = $r[0];
        $missing = array_diff($expected, $have);
        $add(!$missing, 'Tabel lengkap (' . count($expected) . ' tabel)', $missing ? 'Kurang: ' . implode(', ', $missing) : 'Semua tabel ada.',
            'Impor ulang database/dekorwms.sql (file migrasi lama sudah dilebur ke sini). PERHATIAN: ini menimpa data; bila database sudah berisi data, unduh backup dulu.');
        if (!$missing) {
            mysqli_select_db($link, $name);
            $colRs = mysqli_query($link, "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$dbEsc'
                AND ((TABLE_NAME = 'item_batches' AND COLUMN_NAME = 'arrival_photo_at')
                  OR (TABLE_NAME = 'purchase_orders' AND COLUMN_NAME IN ('spk_number','deliver_by','spk_note')))");
            $nCol = $colRs ? (int)mysqli_fetch_row($colRs)[0] : 0;
            $add($nCol === 4, 'Kolom Buyer/SPK & waktu foto kedatangan', $nCol === 4 ? 'Lengkap.' : "Baru $nCol dari 4 kolom.",
                'Impor ulang database/dekorwms.sql (versi terbaru sudah memuat kolom ini; menimpa data, unduh backup dulu bila sudah berisi data).');
            $n = mysqli_fetch_row(mysqli_query($link, 'SELECT COUNT(*) FROM users'))[0];
            $usersReady = $n > 0;
            $add($n > 0, "Pengguna terdaftar: $n", $n > 0 ? '' : 'Tabel users kosong.', 'Import ulang database/dekorwms.sql.');
            $weak = mysqli_fetch_row(mysqli_query($link, "SELECT COUNT(*) FROM users WHERE is_active = 1"))[0];
        }
    }
}
$localCfg = is_file(__DIR__ . '/config.local.php') ? (string)@file_get_contents(__DIR__ . '/config.local.php') : '';
$keyOk = (preg_match('/define\(\s*\'APP_KEY\'\s*,\s*\'([^\']{32,})\'\s*\)/', $localCfg, $km) && strpos($km[1], 'GANTI-DENGAN') !== 0)
      || strpos((string)@file_get_contents(__DIR__ . '/config.php'), 'GANTI-DENGAN-KUNCI-ACAK') === false;
$add($keyOk, 'APP_KEY sudah diganti', 'Masih memakai nilai bawaan (aman untuk uji coba, ganti sebelum dipakai sungguhan).', 'Salin admin/config.local.php.example menjadi config.local.php lalu isi APP_KEY acak minimal 32 karakter.');

if (!$isSuperAdmin && $usersReady) {   // sistem sudah terpasang: wajib login Super Admin
    header('Location: login.php');
    exit;
}
$allOk = !array_filter($checks, fn($c) => !$c[0] && strpos($c[1], 'APP_KEY') === false);
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Diagnosa Pemasangan - WMS Dekor Asia Jayakarya</title>
<style>
  body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f1f3f5;margin:0;color:#222}
  main{max-width:760px;margin:30px auto;padding:0 16px}
  h1{font-size:22px} .row{background:#fff;border-radius:8px;padding:12px 16px;margin-bottom:8px;box-shadow:0 1px 4px rgba(0,0,0,.07);display:flex;gap:12px}
  .dot{flex:none;width:22px;height:22px;border-radius:50%;color:#fff;text-align:center;font-weight:700;line-height:22px;font-size:13px}
  .ok{background:#1e8a45} .bad{background:#b3261e} .warn{background:#d98a00}
  .fix{color:#8a1c14;font-size:13px;margin-top:4px} small{color:#555}
  .res{padding:14px 16px;border-radius:8px;margin:16px 0;font-weight:600}
  a.btn{display:inline-block;background:#B91C1C;color:#fff;padding:10px 16px;border-radius:6px;text-decoration:none}
</style><?php require_once __DIR__ . '/theme.php'; wmsThemeEmit(); ?></head><body><main>
<h1>Diagnosa Pemasangan WMS</h1>
<div class="res" style="background:<?= $allOk ? '#e6f4ea;color:#1e6b34' : '#fdecea;color:#b3261e' ?>">
  <?= $allOk ? 'Semua pemeriksaan penting lolos. Sistem siap dipakai.' : 'Ada yang perlu diperbaiki. Ikuti petunjuk merah di bawah, lalu muat ulang halaman ini.' ?>
</div>
<?php foreach ($checks as [$ok, $title, $detail, $fix]):
    $isKey = strpos($title, 'APP_KEY') !== false; $cls = $ok ? 'ok' : ($isKey ? 'warn' : 'bad'); ?>
  <div class="row"><div class="dot <?= $cls ?>"><?= $ok ? '✓' : ($isKey ? '!' : '✕') ?></div>
    <div><b><?= htmlspecialchars($title) ?></b>
      <?php if ($detail && ($ok === false || $isKey === false)): ?><br><small><?= htmlspecialchars($detail) ?></small><?php endif; ?>
      <?php if (!$ok && $fix): ?><div class="fix">Perbaikan: <?= htmlspecialchars($fix) ?></div><?php endif; ?></div></div>
<?php endforeach; ?>
<?php if ($allOk): ?><p><a class="btn" href="login.php">Buka halaman login</a></p><?php endif; ?>
</main></body></html>