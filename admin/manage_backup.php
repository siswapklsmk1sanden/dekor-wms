<?php
/**
 * Backup & Pulihkan (khusus Super Admin).
 *  - Buat cadangan lengkap (database + foto + APP_KEY) lalu unduh, atau simpan di server (admin\backups\).
 *  - Pulihkan dari cadangan tersimpan / unggahan: wajib password sendiri + ketik PULIHKAN; sistem membuat
 *    cadangan pengaman "sebelum-pulih-..." lebih dulu. Setelah pulih, semua pengguna harus login ulang.
 *  - Pencadangan otomatis terjadwal: backup_otomatis.bat (Windows Task Scheduler), lihat petunjuk di halaman.
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/backup_helper.php';
require_once __DIR__ . '/layout.php';
requireLogin();
if (($_SESSION['role_code'] ?? '') !== 'super_admin') {
    http_response_code(403);
    die('Akses ditolak: hanya Super Admin.');
}
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf   = $_SESSION['csrf'];
$dir    = wmsBackupDir();
$errors = [];
$hasZip = class_exists('ZipArchive');

function bakSafeName($n)
{
    return (is_string($n) && preg_match('/^[A-Za-z0-9._-]{1,80}\.zip$/', $n)) ? $n : '';
}
function bakSize($b)
{
    return $b >= 1048576 ? number_format($b / 1048576, 1, ',', '.') . ' MB' : number_format(max(1, $b) / 1024, 0, ',', '.') . ' KB';
}

// ---- Unduh cadangan tersimpan ----
if (isset($_GET['dl'])) {
    $n = bakSafeName($_GET['dl']);
    $f = $dir . '/' . $n;
    if ($n === '' || !is_file($f)) { http_response_code(404); die('File tidak ditemukan.'); }
    logActivity('EXPORT', 'Backup', "Mengunduh cadangan $n", '');
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $n . '"');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(400); die('Token keamanan tidak valid.'); }
    $act = (string)($_POST['act'] ?? '');

    // ---- Buat cadangan ----
    if ($act === 'make') {
        $opt = ['include_key' => !empty($_POST['include_key']), 'password' => (string)($_POST['zip_password'] ?? '')];
        $toServer = ($_POST['target'] ?? '') === 'server';
        $name = 'dekorwms-' . date('Ymd-His') . '.zip';
        $dest = $toServer ? $dir . '/' . $name : tempnam(sys_get_temp_dir(), 'wmsbk');
        try {
            if (!$hasZip) throw new RuntimeException('Ekstensi PHP zip belum aktif.');
            if ($dest === false) throw new RuntimeException('Tidak bisa membuat file sementara.');
            $m = wmsBackupCreate($connect, $dest, $opt);
            logActivity('CREATE', 'Backup', 'Cadangan ' . $name . ' dibuat (' . array_sum($m['tables']) . ' baris, ' . $m['uploads'] . ' foto'
                . ($m['includes_key'] ? ', dengan APP_KEY' : ', TANPA APP_KEY') . ($m['encrypted'] ? ', ber-password' : '') . ')', '');
            if ($toServer) {
                $_SESSION['flash'] = ['ok', "Cadangan $name disimpan di server (" . bakSize(filesize($dest)) . '). Salin juga ke flashdisk / drive lain.'];
                header('Location: manage_backup.php');
                exit;
            }
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($dest));
            readfile($dest);
            @unlink($dest);
            exit;
        } catch (Throwable $ex) {
            if (!$toServer && is_string($dest)) @unlink($dest);
            if ($toServer && is_file($dest)) @unlink($dest);
            $errors[] = 'Cadangan gagal: ' . $ex->getMessage();
        }
    }

    // ---- Unggah cadangan ----
    elseif ($act === 'upload') {
        $f = $_FILES['zip'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Unggahan gagal (kode ' . (int)($f['error'] ?? -1) . '). Bila filenya besar, naikkan upload_max_filesize dan post_max_size di php.ini, atau salin file ke admin\\backups\\ secara manual.';
        } elseif (!preg_match('/\.zip$/i', $f['name'])) {
            $errors[] = 'File harus berekstensi .zip.';
        } else {
            $name = 'unggahan-' . date('Ymd-His') . '.zip';
            $z = $hasZip ? new ZipArchive() : null;
            if (!$z || $z->open($f['tmp_name']) !== true || $z->locateName('database.sql') === false) {
                $errors[] = 'Bukan file cadangan WMS (database.sql tidak ditemukan).';
                if ($z) $z->close();
            } else {
                $z->close();
                if (move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
                    header('Location: manage_backup.php?restore=' . urlencode($name));
                    exit;
                }
                $errors[] = 'Gagal menyimpan file unggahan.';
            }
        }
    }

    // ---- Hapus cadangan tersimpan ----
    elseif ($act === 'delete') {
        $n = bakSafeName($_POST['file'] ?? '');
        if ($n !== '' && is_file($dir . '/' . $n) && @unlink($dir . '/' . $n)) {
            logActivity('DELETE', 'Backup', "Menghapus cadangan $n", '');
            $_SESSION['flash'] = ['ok', "Cadangan $n dihapus."];
        } else {
            $_SESSION['flash'] = ['err', 'File tidak bisa dihapus.'];
        }
        header('Location: manage_backup.php');
        exit;
    }

    // ---- Pulihkan ----
    elseif ($act === 'restore') {
        $n = bakSafeName($_POST['file'] ?? '');
        $zipPath = $dir . '/' . $n;
        $zpw = (string)($_POST['zip_password'] ?? '');
        $stop = null;
        if ($n === '' || !is_file($zipPath)) $stop = 'File cadangan tidak ditemukan.';
        elseif (trim((string)($_POST['confirm'] ?? '')) !== 'PULIHKAN') $stop = 'Ketik PULIHKAN (huruf besar semua) untuk mengonfirmasi.';
        else {
            $st = mysqli_prepare($connect, 'SELECT password_hash FROM users WHERE id = ? AND is_active = 1');
            $uid = (int)$_SESSION['user_id'];
            mysqli_stmt_bind_param($st, 'i', $uid);
            mysqli_stmt_execute($st);
            $row = mysqli_fetch_row(mysqli_stmt_get_result($st));
            if (!$row || !password_verify((string)($_POST['password'] ?? ''), $row[0])) $stop = 'Password Anda salah.';
        }
        if ($stop === null) {
            $man = wmsBackupReadManifest($zipPath, $zpw);
            if (!$man) $stop = 'Cadangan tidak terbaca. Bila dilindungi password, isi password cadangannya dengan benar.';
        }
        if ($stop !== null) {
            $errors[] = $stop;
            $_GET['restore'] = $n;
        } else {
            $pre = 'sebelum-pulih-' . date('Ymd-His') . '.zip';
            $log = [];
            try {
                // 1) cadangan pengaman kondisi sekarang
                wmsBackupCreate($connect, $dir . '/' . $pre, ['include_key' => true, 'password' => '']);
                $log[] = "Cadangan pengaman dibuat: $pre";
                // 2) database
                $stmts = wmsRestoreSql($connect, $zipPath, $zpw);
                $log[] = number_format($stmts, 0, ',', '.') . ' pernyataan SQL dijalankan';
                // 3) foto
                $log[] = wmsRestoreUploads($zipPath, $zpw) . ' foto dipulihkan';
                // 4) APP_KEY
                $keyMsg = 'APP_KEY tidak diubah';
                if (!empty($_POST['restore_key'])) {
                    $keyMsg = wmsRestoreAppKey($zipPath, $zpw) ? 'APP_KEY dipulihkan dari cadangan (berlaku pada muatan berikutnya)' : 'APP_KEY TIDAK bisa dipulihkan (tidak ada / format tidak valid)';
                }
                $log[] = $keyMsg;
                try { logActivity('RESTORE', 'Backup', "Pemulihan dari $n. " . implode('; ', $log), ''); } catch (Throwable $e) {}
            } catch (Throwable $ex) {
                http_response_code(500);
                echo '<!DOCTYPE html><meta charset="utf-8"><title>Pemulihan gagal</title><body style="font-family:Arial;max-width:720px;margin:40px auto;line-height:1.5">'
                    . '<h2 style="color:#B3261E">Pemulihan terhenti</h2><p>' . e($ex->getMessage()) . '</p>'
                    . '<p>Database bisa berada dalam keadaan setengah pulih. Cadangan pengaman kondisi sebelumnya: <b>' . e($pre) . '</b> (di admin\\backups\\). '
                    . 'Pulihkan dari file itu lewat halaman Backup &amp; Pulihkan, atau impor database.sql di dalamnya lewat phpMyAdmin.</p>'
                    . '<p><a href="manage_backup.php">Kembali</a></p></body>';
                exit;
            }
            $_SESSION = [];
            @session_destroy();
            echo '<!DOCTYPE html><meta charset="utf-8"><title>Pemulihan selesai</title><body style="font-family:Arial;max-width:720px;margin:40px auto;line-height:1.5">'
                . '<h2 style="color:#1E6B34">Pemulihan selesai</h2><ul><li>' . implode('</li><li>', array_map('e', $log)) . '</li></ul>'
                . '<p>Semua sesi diakhiri. Masuk kembali memakai akun dari data yang dipulihkan.</p>'
                . '<p><a href="login.php"><b>Buka halaman login</b></a></p></body>';
            exit;
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Daftar cadangan tersimpan
$list = [];
foreach (glob($dir . '/*.zip') ?: [] as $f) $list[] = ['name' => basename($f), 'size' => filesize($f), 'time' => filemtime($f)];
usort($list, function ($a, $b) { return $b['time'] <=> $a['time']; });

$lastRs = mysqli_query($connect, "SELECT MAX(created_at) FROM audit_logs WHERE entity_table = 'Backup' AND event_action = 'CREATE'");
$lastBackup = $lastRs ? (mysqli_fetch_row($lastRs)[0] ?? null) : null;
$keyLocal = is_file(__DIR__ . '/config.local.php');

renderHeader('Backup & Pulihkan');
$inp = 'style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px"';
?>
<h2>Backup &amp; Pulihkan</h2>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>
<?php if (!$hasZip): ?><div class="err">Ekstensi PHP <b>zip</b> belum aktif, sehingga backup dan pemulihan tidak bisa dipakai. Laragon: klik kanan ikon Laragon &gt; PHP &gt; Extensions &gt; centang zip, lalu Reload.</div><?php endif; ?>

<?php
// ===================== HALAMAN KONFIRMASI PEMULIHAN =====================
$rf = bakSafeName($_GET['restore'] ?? '');
if ($rf !== '' && is_file($dir . '/' . $rf) && $hasZip):
    $zpwTry = (string)($_POST['zip_password'] ?? '');
    $man = wmsBackupReadManifest($dir . '/' . $rf, $zpwTry);
    $fpNow = wmsKeyFingerprint();
    $rows = $man ? array_sum($man['tables'] ?? []) : 0;
    $keyDiff = $man && ($man['key_fingerprint'] ?? '') !== $fpNow;
?>
<div class="panel">
  <strong>Pulihkan dari <?= e($rf) ?></strong>
  <?php if ($man): ?>
    <ul style="margin:10px 0">
      <li>Dibuat: <b><?= e($man['created_at'] ?? '-') ?></b></li>
      <li>Isi: <?= count($man['tables'] ?? []) ?> tabel, <?= number_format($rows, 0, ',', '.') ?> baris, <?= (int)($man['uploads'] ?? 0) ?> foto</li>
      <li>APP_KEY di dalam cadangan: <?= !empty($man['has_key_file']) ? '<b>ada</b>' : '<b style="color:#B3261E">tidak ada</b>' ?>;
        <?= $keyDiff ? '<b style="color:#B3261E">berbeda</b> dari APP_KEY server ini (telepon supplier terenkripsi baru terbaca bila kunci dipulihkan)' : 'sama dengan APP_KEY server ini' ?></li>
    </ul>
  <?php else: ?>
    <div class="err" style="margin-top:10px">Isi cadangan belum terbaca. Bila dilindungi password, isi password cadangan di bawah lalu tekan "Periksa".</div>
    <form method="post" action="manage_backup.php?restore=<?= e(urlencode($rf)) ?>" style="margin-bottom:10px">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="peek">
      <input type="password" name="zip_password" placeholder="Password cadangan" style="max-width:260px" autocomplete="off"> <button type="submit" class="sec">Periksa</button>
    </form>
  <?php endif; ?>
  <div class="err"><b>Peringatan:</b> seluruh data saat ini (stok, transaksi, pengguna, hak akses, audit log) akan DITIMPA oleh isi cadangan. Sistem membuat cadangan pengaman otomatis lebih dulu.</div>
  <form method="post" action="manage_backup.php" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="restore"><input type="hidden" name="file" value="<?= e($rf) ?>">
    <div class="grid">
      <div><label>Password cadangan (bila ada)</label><input type="password" name="zip_password" value="<?= e($zpwTry) ?>" <?= $inp ?>></div>
      <div><label>Password akun Anda *</label><input type="password" name="password" required <?= $inp ?>></div>
      <div><label>Ketik PULIHKAN *</label><input type="text" name="confirm" required <?= $inp ?>></div>
    </div>
    <p style="margin:12px 0 0"><label style="display:inline"><input type="checkbox" name="restore_key" value="1" <?= ($keyDiff && !empty($man['has_key_file'])) ? 'checked' : '' ?>> Pulihkan juga APP_KEY dari cadangan <small>(config.local.php lama disalin dulu; hanya nilai kuncinya yang diganti)</small></label></p>
    <p style="margin:12px 0 0"><button type="submit" class="danger">Pulihkan Sekarang</button> <a class="btn sec" href="manage_backup.php">Batal</a></p>
  </form>
</div>
<?php endif; ?>

<div class="panel">
  <strong>Buat cadangan</strong>
  <small>Terakhir: <?= $lastBackup ? e(substr($lastBackup, 0, 16)) : '<b style="color:#B3261E">belum pernah (lewat aplikasi)</b>' ?></small>
  <form method="post" autocomplete="off" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="make">
    <p style="margin:0 0 8px"><label style="display:inline"><input type="checkbox" name="include_key" value="1" <?= $keyLocal ? 'checked' : 'disabled' ?>> Sertakan <b>APP_KEY</b> (config.local.php)</label>
      <?php if (!$keyLocal): ?><small>- config.local.php belum ada; buat dulu dari config.local.php.example</small><?php endif; ?></p>
    <p style="margin:0 0 12px;max-width:340px"><label>Password file cadangan (disarankan bila APP_KEY disertakan)</label><input type="password" name="zip_password" <?= $inp ?> autocomplete="new-password">
      <small>Zip ber-password dibuka dengan 7-Zip / WinRAR (bukan Explorer bawaan Windows). Jangan sampai lupa password-nya.</small></p>
    <p style="margin:0"><button type="submit" name="target" value="download" <?= $hasZip ? '' : 'disabled' ?>>Buat &amp; Unduh</button>
      <button type="submit" name="target" value="server" class="sec" <?= $hasZip ? '' : 'disabled' ?>>Simpan di Server</button></p>
  </form>
  <p style="margin:12px 0 0"><small>Cadangan memuat database, foto kedatangan / acuan / avatar<?= $keyLocal ? ', dan (bila dicentang) APP_KEY' : '' ?>. Simpan salinannya di tempat lain (flashdisk / drive), bukan hanya di komputer ini.</small></p>
</div>

<div class="panel scroll">
  <strong>Cadangan di server</strong> <small>(admin\backups\)</small>
  <table>
    <tr><th>File</th><th>Ukuran</th><th>Waktu</th><th>Aksi</th></tr>
    <?php if (!$list): ?><tr><td colspan="4">Belum ada cadangan tersimpan.</td></tr><?php endif; ?>
    <?php foreach ($list as $b): ?>
      <tr><td><?= e($b['name']) ?></td><td><?= e(bakSize($b['size'])) ?></td><td><?= e(date('Y-m-d H:i', $b['time'])) ?></td>
        <td><a class="btn sec sm" href="?dl=<?= e(urlencode($b['name'])) ?>">Unduh</a>
          <a class="btn sm" href="?restore=<?= e(urlencode($b['name'])) ?>">Pulihkan</a>
          <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="delete"><input type="hidden" name="file" value="<?= e($b['name']) ?>">
            <button type="submit" class="danger sm" onclick="return confirm('Hapus <?= e($b['name']) ?>?')">Hapus</button></form></td></tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="panel">
  <strong>Pulihkan dari file di komputer</strong>
  <form method="post" enctype="multipart/form-data" style="margin-top:10px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="act" value="upload">
    <input type="file" name="zip" accept=".zip" required> <button type="submit" class="sec" <?= $hasZip ? '' : 'disabled' ?>>Unggah &amp; Periksa</button>
  </form>
  <small>Batas unggahan server: <?= e(ini_get('upload_max_filesize')) ?>. Untuk file lebih besar, salin ke folder admin\backups\ lalu pilih dari daftar di atas.</small>
</div>

<div class="panel">
  <strong>Cadangan otomatis tiap hari</strong>
  <p style="margin:8px 0">Supaya tidak terlupa, jadwalkan <code>backup_otomatis.bat</code> (di folder dekorwms). Buka CMD sebagai Administrator, lalu jalankan sekali:</p>
  <pre style="background:#f4f5f6;padding:10px;border-radius:6px;overflow:auto">schtasks /create /tn "WMS Backup Harian" /tr "C:\laragon\www\dekorwms\backup_otomatis.bat" /sc daily /st 18:00</pre>
  <small>Hasilnya admin\backups\otomatis-TANGGAL.zip (14 terbaru disimpan, termasuk APP_KEY). Lonceng notifikasi mengingatkan Super Admin bila sudah lebih dari 7 hari tanpa cadangan.</small>
</div>
<?php renderFooter();
