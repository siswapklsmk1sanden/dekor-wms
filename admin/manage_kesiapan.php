<?php
/**
 * Kesiapan Produksi (khusus Super Admin).
 *  1. Daftar periksa keamanan & konfigurasi (hanya membaca, tidak mengubah apa pun).
 *  2. Pembersih Data Uji: mengosongkan data TRANSAKSI percobaan (stok, lot, buku besar, PO, draft, opname, permintaan Buyer)
 *     dan foto kedatangan. Data master (barang, supplier, rak, BoM, pengguna, hak akses) TIDAK disentuh.
 *     Pengaman: mengetik RESET DATA UJI, dan memasukkan password sendiri.
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/layout.php';
requireLogin();
if (($_SESSION['role_code'] ?? '') !== 'super_admin') {
    http_response_code(403);
    die('Akses ditolak: hanya Super Admin.');
}
if (empty($_SESSION['ready_csrf'])) $_SESSION['ready_csrf'] = bin2hex(random_bytes(16));

// Tabel transaksi yang dikosongkan (urutan bebas: FOREIGN_KEY_CHECKS dimatikan selama proses)
const READY_TX_TABLES = ['inventory_ledger_entries', 'inventory_transactions', 'item_batches', 'stock_balances',
    'opname_counts', 'opname_sessions', 'po_receipts', 'purchase_order_lines', 'purchase_orders', 'trx_drafts', 'buyer_requests',
    'stock_reservations', 'stock_reservation_allocs', 'supplier_invoices', 'supplier_invoice_lines', 'supplier_payments',
    'supplier_credits', 'supplier_credit_apps', 'supplier_terms'];

function readyCount($connect, $table)
{
    try {
        $rs = mysqli_query($connect, 'SELECT COUNT(*) FROM `' . str_replace('`', '', $table) . '`');
        return $rs ? (int)mysqli_fetch_row($rs)[0] : 0;
    } catch (Throwable $e) { return 0; }
}

function readyFiles($dir)
{
    $out = [];
    if (!is_dir($dir)) return $out;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..' || $f === 'index.html' || $f === '.htaccess') continue;
        if (is_file("$dir/$f")) $out[] = $f;
    }
    return $out;
}

/** Foto acuan di master Barang (uploads/requests/...) tidak boleh ikut terhapus. */
function readyRemovableRequestPhotos($connect, $dir)
{
    $keep = [];
    $rs = mysqli_query($connect, "SELECT reference_photo_path FROM items WHERE reference_photo_path IS NOT NULL AND reference_photo_path <> ''");
    while ($rs && ($r = mysqli_fetch_row($rs))) $keep[basename(str_replace('\\', '/', $r[0]))] = true;
    return array_values(array_filter(readyFiles($dir), fn($f) => !isset($keep[$f])));
}

$arrDir = __DIR__ . '/uploads/arrivals';
$reqDir = __DIR__ . '/uploads/requests';
$invDir = __DIR__ . '/uploads/invoices';

// ---- Pembersih Data Uji (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_test_data') {
    $flash = null;
    if (!hash_equals($_SESSION['ready_csrf'], (string)($_POST['token'] ?? ''))) {
        $flash = ['err', 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.'];
    } elseif (trim((string)($_POST['confirm'] ?? '')) !== 'RESET DATA UJI') {
        $flash = ['err', 'Ketik RESET DATA UJI (huruf besar semua) untuk mengonfirmasi.'];
    } else {
        $st = mysqli_prepare($connect, 'SELECT password_hash FROM users WHERE id = ? AND is_active = 1');
        $uid = (int)$_SESSION['user_id'];
        mysqli_stmt_bind_param($st, 'i', $uid);
        mysqli_stmt_execute($st);
        $row = mysqli_fetch_row(mysqli_stmt_get_result($st));
        if (!$row || !password_verify((string)($_POST['password'] ?? ''), $row[0])) {
            $flash = ['err', 'Password Anda salah.'];
        } else {
            $withAudit = !empty($_POST['clear_audit']);
            $tables = READY_TX_TABLES;
            if ($withAudit) $tables[] = 'audit_logs';
            $counts = [];
            try {
                foreach ($tables as $t) $counts[$t] = readyCount($connect, $t);
                mysqli_query($connect, 'SET FOREIGN_KEY_CHECKS = 0');
                foreach ($tables as $t) mysqli_query($connect, 'TRUNCATE TABLE `' . $t . '`');
                mysqli_query($connect, 'UPDATE storage_bins SET is_frozen_for_opname = 0');
                mysqli_query($connect, 'SET FOREIGN_KEY_CHECKS = 1');

                $delFiles = 0;
                foreach (readyFiles($arrDir) as $f) if (@unlink("$arrDir/$f")) $delFiles++;
                foreach (readyFiles($invDir) as $f) if (@unlink("$invDir/$f")) $delFiles++;
                foreach (readyRemovableRequestPhotos($connect, $reqDir) as $f) if (@unlink("$reqDir/$f")) $delFiles++;

                try {
                    logActivity('RESET', 'Kesiapan Produksi', 'Data uji dibersihkan: ' . array_sum($counts) . ' baris dari ' . count($tables)
                        . ' tabel' . ($withAudit ? ' (termasuk Audit Log)' : '') . ", $delFiles foto dihapus.", 0);
                } catch (Throwable $e) { /* jejak gagal tidak membatalkan hasil */ }
                $flash = ['ok', 'Data uji dibersihkan: ' . number_format(array_sum($counts), 0, ',', '.') . " baris, $delFiles foto. Masukkan saldo awal lewat Barang Masuk."];
            } catch (Throwable $e) {
                try { mysqli_query($connect, 'SET FOREIGN_KEY_CHECKS = 1'); } catch (Throwable $e2) {}
                $flash = ['err', 'Pembersihan terhenti: ' . $e->getMessage() . ' Sebagian tabel mungkin sudah kosong; pulihkan dari salinan database bila ada.'];
            }
        }
    }
    $_SESSION['flash'] = $flash;
    $_SESSION['ready_csrf'] = bin2hex(random_bytes(16));
    header('Location: manage_kesiapan.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---- Daftar periksa ----
$checks = [];   // [level ok|warn|bad|info, judul, detail, cara perbaiki]
$add = function ($level, $title, $detail, $fix = '') use (&$checks) { $checks[] = [$level, $title, $detail, $fix]; };

$keyDefault = function_exists('wmsKeyIsDefault') ? wmsKeyIsDefault() : true;
$add($keyDefault ? 'bad' : 'ok', 'APP_KEY', $keyDefault ? 'Masih bawaan / terlalu pendek.' : 'Sudah diganti.',
    'Salin admin\\config.local.php.example menjadi config.local.php dan isi APP_KEY acak minimal 32 karakter (jangan diganti lagi setelah ada data terenkripsi).');

$hasLocal = is_file(__DIR__ . '/config.local.php');
$add($hasLocal ? 'ok' : 'warn', 'config.local.php', $hasLocal ? 'Ada.' : 'Belum ada (kredensial DB dan APP_KEY masih di config.php).',
    'Buat dari config.local.php.example.');

$demoPw = [];
$rs = mysqli_query($connect, 'SELECT username, password_hash FROM users WHERE is_active = 1');
while ($rs && ($r = mysqli_fetch_assoc($rs))) if (password_verify('123456', $r['password_hash'])) $demoPw[] = $r['username'];
$add($demoPw ? 'bad' : 'ok', 'Password bawaan 123456', $demoPw ? 'Masih dipakai oleh: ' . implode(', ', $demoPw) : 'Tidak ada akun aktif yang memakainya.',
    'Minta pemilik akun mengganti lewat Profil, atau nonaktifkan akun yang tidak dipakai di menu Pengguna.');

$demoAcc = [];
$rs = mysqli_query($connect, "SELECT username FROM users WHERE is_active = 1 AND email LIKE '%@dekorasia.local'");
while ($rs && ($r = mysqli_fetch_row($rs))) $demoAcc[] = $r[0];
$add($demoAcc ? 'warn' : 'ok', 'Akun demo aktif', $demoAcc ? 'Email @dekorasia.local pada: ' . implode(', ', $demoAcc) : 'Tidak ada.',
    'Ganti nama/email menjadi data karyawan sebenarnya, atau nonaktifkan akun yang tidak dipakai.');

$rootNoPw = (($db_user ?? '') === 'root' && ($db_pass ?? '') === '');
$add($rootNoPw ? 'warn' : 'ok', 'Akun database', $rootNoPw ? 'Memakai root tanpa password (wajar untuk komputer lokal, tidak aman bila terhubung jaringan).' : 'Bukan root tanpa password.',
    'Buat user MySQL khusus dengan password, isi di config.local.php.');

$add('ok', 'cek_sistem.php', is_file(__DIR__ . '/cek_sistem.php') ? 'Ada, tetapi hanya bisa dibuka setelah login sebagai Super Admin IT.' : 'Sudah dihapus.', '');

$host    = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
$isLocal = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true) || substr($host, -5) === '.test';
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
if ($isHttps)        $add('ok', 'HTTPS', 'Aktif.');
elseif ($isLocal)    $add('ok', 'HTTPS', 'Tidak aktif, tetapi diakses dari komputer sendiri (localhost).');
else                 $add('bad', 'HTTPS', "Diakses lewat jaringan ($host) tanpa HTTPS: password dan sesi bisa disadap.", 'Aktifkan SSL di Apache/Laragon atau pakai reverse proxy ber-HTTPS.');

$de = strtolower((string)ini_get('display_errors'));
$deOn = in_array($de, ['1', 'on', 'true', 'yes', 'stdout'], true);
$add($deOn ? 'warn' : 'ok', 'display_errors PHP', $deOn ? 'Aktif: pesan error PHP tampil ke pengguna (bisa membocorkan path/struktur).' : 'Nonaktif.',
    'Set display_errors=Off di php.ini untuk produksi, lalu restart Apache.');


$noHt = [];
foreach (['arrivals', 'avatars', 'requests', 'invoices'] as $d) if (is_dir(__DIR__ . "/uploads/$d") && !is_file(__DIR__ . "/uploads/$d/.htaccess")) $noHt[] = $d;
$add($noHt ? 'bad' : 'ok', 'Folder uploads aman', $noHt ? 'Tanpa .htaccess pemblokir PHP: ' . implode(', ', $noHt) : 'Eksekusi PHP diblokir di semua subfolder (bila server memakai Apache).',
    'Salin kembali file .htaccess dari zip ke folder tersebut. Server selain Apache perlu aturan setara.');

$extMiss = []; $extWarn = [];
foreach (['mysqli', 'openssl', 'mbstring', 'gd'] as $x) if (!extension_loaded($x)) $extMiss[] = $x;
if (!extension_loaded('zip')) $extWarn[] = 'zip';
if ($extMiss)      $add('bad', 'Ekstensi PHP', 'Tidak aktif: ' . implode(', ', $extMiss), 'Aktifkan di php.ini.');
elseif ($extWarn)  $add('warn', 'Ekstensi PHP', 'zip tidak aktif (ekspor jatuh ke CSV).', 'Aktifkan extension=zip di php.ini.');
else               $add('ok', 'Ekstensi PHP', 'mysqli, openssl, mbstring, gd, zip aktif.');

try {
    $bad = auditVerifyChain();
    $add($bad === null ? 'ok' : 'bad', 'Rantai hash Audit Log', $bad === null ? 'Utuh.' : "Rusak mulai log #$bad.",
        'Selidiki perubahan manual di tabel audit_logs; pulihkan dari salinan database bila ada.');
} catch (Throwable $e) { $add('warn', 'Rantai hash Audit Log', 'Tidak bisa diperiksa: ' . $e->getMessage()); }

$nTrx = readyCount($connect, 'inventory_transactions');
$add('info', 'Data transaksi saat ini', number_format($nTrx, 0, ',', '.') . ' transaksi, ' . number_format(readyCount($connect, 'item_batches'), 0, ',', '.')
    . ' lot, ' . number_format(readyCount($connect, 'purchase_orders'), 0, ',', '.') . ' PO.',
    'Bila ini data percobaan, kosongkan lewat Pembersih Data Uji di bawah sebelum dipakai sungguhan.');

$nBad = $nWarn = 0;
foreach ($checks as $c) { if ($c[0] === 'bad') $nBad++; elseif ($c[0] === 'warn') $nWarn++; }

// Ringkasan yang akan dihapus
$wipe = [];
foreach (READY_TX_TABLES as $t) $wipe[$t] = readyCount($connect, $t);
$nAudit  = readyCount($connect, 'audit_logs');
$nFotoA  = count(readyFiles($arrDir));
$nFotoR  = count(readyRemovableRequestPhotos($connect, $reqDir));

$badge = [
    'ok'   => ['OK',        '#1e7b34', 'rgba(30,123,52,.14)'],
    'warn' => ['Periksa',   '#a15c00', 'rgba(224,174,53,.22)'],
    'bad'  => ['Perbaiki',  '#b3261e', 'rgba(179,38,30,.14)'],
    'info' => ['Info',      '#2b5d8a', 'rgba(43,93,138,.14)'],
];

renderHeader('Kesiapan Produksi');
?>
<h2>Kesiapan Produksi</h2>
<?php if ($flash): ?><div class="<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div><?php endif; ?>

<div class="panel scroll">
  <strong>Daftar periksa</strong>
  <small style="margin-left:8px"><?= $nBad ?> perlu diperbaiki, <?= $nWarn ?> perlu diperiksa</small>
  <table>
    <tr><th>Pemeriksaan</th><th>Status</th><th>Keterangan</th></tr>
    <?php foreach ($checks as [$lv, $title, $detail, $fix]): $b = $badge[$lv]; ?>
      <tr>
        <td class="nw"><?= e($title) ?></td>
        <td class="nw"><span style="display:inline-block;padding:2px 9px;border-radius:10px;font-size:12px;font-weight:600;color:<?= $b[1] ?>;background:<?= $b[2] ?>"><?= e($b[0]) ?></span></td>
        <td><?= e($detail) ?><?php if ($fix && $lv !== 'ok'): ?><br><small style="color:#6b7075">Cara: <?= e($fix) ?></small><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <div class="panel-foot"><small>Halaman ini hanya membaca; tidak ada yang diubah dari daftar periksa. Muat ulang setelah memperbaiki.</small></div>
</div>

<div class="panel scroll">
  <strong style="color:#b3261e">Pembersih Data Uji</strong>
  <p style="margin:8px 0">Mengosongkan data <b>transaksi percobaan</b> agar siap dipakai sungguhan. <b>Tidak bisa dibatalkan</b>. Salin database lewat phpMyAdmin/HeidiSQL dulu bila perlu.
    Data master (barang, supplier, rak, BoM, pengguna, hak akses) dan foto acuan barang tetap ada. Stok menjadi nol, jadi saldo awal diinput ulang lewat Barang Masuk.</p>
  <table>
    <tr><th>Yang akan dikosongkan</th><th class="num">Baris</th></tr>
    <?php foreach ($wipe as $t => $n): ?><tr><td class="nw"><?= e($t) ?></td><td class="num"><?= number_format($n, 0, ',', '.') ?></td></tr><?php endforeach; ?>
    <tr><td class="nw">audit_logs <small>(hanya bila dicentang)</small></td><td class="num"><?= number_format($nAudit, 0, ',', '.') ?></td></tr>
    <tr><td class="nw">Foto kedatangan + foto Buyer yang tidak dipakai master Barang</td><td class="num"><?= (int)($nFotoA + $nFotoR) ?></td></tr>
  </table>


  <form method="post" style="margin-top:12px;display:grid;gap:10px;max-width:420px"
        onsubmit="return confirm('Kosongkan SEMUA data transaksi uji? Tindakan ini tidak bisa dibatalkan.');">
    <input type="hidden" name="action" value="reset_test_data">
    <input type="hidden" name="token" value="<?= e($_SESSION['ready_csrf']) ?>">
    <label><input type="checkbox" name="clear_audit" value="1" style="width:auto"> Bersihkan juga Audit Log (rantai hash dimulai dari awal)</label>
    <label>Password Anda<input type="password" name="password" autocomplete="current-password" required></label>
    <label>Ketik <b>RESET DATA UJI</b><input type="text" name="confirm" autocomplete="off" required></label>
    <div><button type="submit" style="background:#b3261e;color:#fff">Kosongkan Data Uji</button></div>
  </form>
</div>
<?php renderFooter();
