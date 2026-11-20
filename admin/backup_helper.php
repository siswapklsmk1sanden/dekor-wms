<?php
/**
 * Backup & Pulihkan (v38). Cadangan = satu file .zip berisi:
 *   manifest.json     info cadangan (waktu, tabel, jumlah baris, sidik jari APP_KEY)
 *   database.sql      seluruh tabel (struktur + data), dibuat PHP murni (tanpa mysqldump)
 *   uploads/...       foto kedatangan, foto acuan, avatar
 *   config.local.php  (opsional) berisi APP_KEY; tanpa kunci ini telepon supplier tidak bisa dibuka lagi
 * Zip bisa diberi password (AES-256) bila ekstensi zip PHP mendukung.
 */
require_once __DIR__ . '/config.php';

const WMS_BACKUP_FORMAT = 'dekorwms-backup';

function wmsBackupDir()
{
    $dir = __DIR__ . '/backups';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    if (is_dir($dir)) {
        if (!is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

function wmsKeyFingerprint()
{
    return substr(hash('sha256', APP_KEY), 0, 16);
}

/** Tulis dump SQL seluruh tabel ke file. Mengembalikan ['tables' => [nama => jumlah baris]]. */
function wmsDumpDatabase($connect, $path)
{
    $fh = fopen($path, 'wb');
    if (!$fh) throw new RuntimeException('Tidak bisa menulis file sementara.');
    $tzRow = mysqli_fetch_row(mysqli_query($connect, 'SELECT @@session.time_zone'));
    $oldTz = $tzRow ? $tzRow[0] : 'SYSTEM';
    mysqli_query($connect, "SET time_zone = '+00:00'");
    fwrite($fh, "-- DEKORWMS-BACKUP v1\n-- " . date('c') . "\n");
    fwrite($fh, "SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");

    $tables = [];
    $rs = mysqli_query($connect, "SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    while ($rs && ($r = mysqli_fetch_row($rs))) $tables[] = $r[0];
    $counts = [];
    foreach ($tables as $t) {
        $qt = '`' . str_replace('`', '``', $t) . '`';
        $cr = mysqli_fetch_row(mysqli_query($connect, "SHOW CREATE TABLE $qt"));
        fwrite($fh, "DROP TABLE IF EXISTS $qt;\n" . str_replace(["\r", "\n"], ' ', $cr[1]) . ";\n");
        $res = mysqli_query($connect, "SELECT * FROM $qt", MYSQLI_USE_RESULT);
        if (!$res) throw new RuntimeException("Gagal membaca tabel $t: " . mysqli_error($connect));
        $fields = mysqli_fetch_fields($res);
        $cols = implode(',', array_map(function ($f) { return '`' . $f->name . '`'; }, $fields));
        $n = 0;
        $buf = [];
        while ($row = mysqli_fetch_row($res)) {
            $vals = [];
            foreach ($row as $i => $v) {
                if ($v === null) { $vals[] = 'NULL'; continue; }
                if ($fields[$i]->charsetnr == 63 && $v !== '') { $vals[] = '0x' . bin2hex($v); continue; }   // kolom biner
                $vals[] = "'" . mysqli_real_escape_string($connect, $v) . "'";
            }
            $buf[] = '(' . implode(',', $vals) . ')';
            $n++;
            if (count($buf) >= 100) {
                fwrite($fh, "INSERT INTO $qt ($cols) VALUES\n" . implode(",\n", $buf) . ";\n");
                $buf = [];
            }
        }
        mysqli_free_result($res);
        if ($buf) fwrite($fh, "INSERT INTO $qt ($cols) VALUES\n" . implode(",\n", $buf) . ";\n");
        $counts[$t] = $n;
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS = 1;\n-- SELESAI\n");
    fclose($fh);
    mysqli_query($connect, "SET time_zone = '" . mysqli_real_escape_string($connect, $oldTz) . "'");
    return ['tables' => $counts];
}

/** Daftar file upload yang ikut dicadangkan: [path absolut => nama di zip]. */
function wmsBackupUploadFiles()
{
    $base = __DIR__ . '/uploads';
    $out = [];
    if (!is_dir($base)) return $out;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if (!$f->isFile()) continue;
        $name = $f->getFilename();
        if ($name === '.htaccess' || $name === 'index.html') continue;
        $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base) + 1));
        $out[$f->getPathname()] = 'uploads/' . $rel;
    }
    return $out;
}

/**
 * Buat file cadangan .zip di $destPath. Opsi: include_key (bool), password (string|'').
 * Mengembalikan manifest (array).
 */
function wmsBackupCreate($connect, $destPath, array $opt = [])
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('Ekstensi PHP zip belum aktif (Laragon: Menu > PHP > Extensions > zip).');
    @set_time_limit(0);
    @ini_set('memory_limit', '512M');
    $includeKey = !empty($opt['include_key']);
    $password   = (string)($opt['password'] ?? '');

    $tmpSql = tempnam(sys_get_temp_dir(), 'wmsdb');
    try {
        $dump  = wmsDumpDatabase($connect, $tmpSql);
        $files = wmsBackupUploadFiles();
        $cfg   = __DIR__ . '/config.local.php';
        $hasKey = $includeKey && is_file($cfg);
        $manifest = [
            'format' => WMS_BACKUP_FORMAT, 'version' => 1, 'created_at' => date('Y-m-d H:i:s'),
            'app' => 'WMS Dekor Asia Jayakarya', 'key_fingerprint' => wmsKeyFingerprint(),
            'includes_key' => $hasKey, 'encrypted' => $password !== '',
            'uploads' => count($files), 'tables' => $dump['tables'],
        ];
        $zip = new ZipArchive();
        if ($zip->open($destPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Gagal membuat file zip.');
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFile($tmpSql, 'database.sql');
        foreach ($files as $abs => $name) $zip->addFile($abs, $name);
        if ($hasKey) $zip->addFile($cfg, 'config.local.php');
        if ($password !== '') {
            if (!method_exists($zip, 'setEncryptionName') || !$zip->setPassword($password)) {
                $zip->close();
                @unlink($destPath);
                throw new RuntimeException('Enkripsi password tidak didukung di PHP ini. Buat cadangan tanpa password.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) $zip->setEncryptionIndex($i, ZipArchive::EM_AES_256);
        }
        if (!$zip->close()) throw new RuntimeException('Gagal menyelesaikan file zip.');
    } finally {
        @unlink($tmpSql);
    }
    return $manifest;
}

/** Baca manifest dari zip cadangan (null bila bukan cadangan WMS / password salah). */
function wmsBackupReadManifest($zipPath, $password = '')
{
    if (!class_exists('ZipArchive')) return null;
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return null;
    if ($password !== '') $zip->setPassword($password);
    $raw = $zip->getFromName('manifest.json');
    $hasSql = $zip->locateName('database.sql') !== false;
    $hasKey = $zip->locateName('config.local.php') !== false;
    $zip->close();
    if ($raw === false || !$hasSql) return null;
    $m = json_decode($raw, true);
    if (!is_array($m) || ($m['format'] ?? '') !== WMS_BACKUP_FORMAT) return null;
    $m['has_key_file'] = $hasKey;
    return $m;
}

/** Hapus cadangan otomatis lama, sisakan $keep terbaru (nama otomatis-*.zip). */
function wmsBackupPrune($keep)
{
    $files = glob(wmsBackupDir() . '/otomatis-*.zip') ?: [];
    rsort($files);
    foreach (array_slice($files, max(1, (int)$keep)) as $f) @unlink($f);
}

/** Jalankan database.sql dari zip. Mengembalikan jumlah pernyataan. Berhenti di kesalahan pertama. */
function wmsRestoreSql($connect, $zipPath, $password = '')
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) throw new RuntimeException('File zip tidak bisa dibuka.');
    if ($password !== '') $zip->setPassword($password);
    $fp = $zip->getStream('database.sql');
    if (!$fp) { $zip->close(); throw new RuntimeException('database.sql tidak bisa dibaca (password salah?).'); }
    $first = fgets($fp);
    if ($first === false || strpos($first, '-- DEKORWMS-BACKUP') !== 0) { fclose($fp); $zip->close(); throw new RuntimeException('Format database.sql tidak dikenali.'); }
    @set_time_limit(0);
    $tzRow = mysqli_fetch_row(mysqli_query($connect, 'SELECT @@session.time_zone'));
    $oldTz = $tzRow ? $tzRow[0] : 'SYSTEM';
    $resetTz = function () use ($connect, $oldTz) {
        mysqli_query($connect, 'SET FOREIGN_KEY_CHECKS = 1');
        mysqli_query($connect, "SET time_zone = '" . mysqli_real_escape_string($connect, $oldTz) . "'");
    };
    $buf = '';
    $n = 0;
    $line = 1;
    $done = false;
    while (($l = fgets($fp)) !== false) {
        $line++;
        $t = rtrim($l, "\r\n");
        if ($buf === '' && ($t === '' || strncmp($t, '--', 2) === 0)) {
            if ($t === '-- SELESAI') $done = true;
            continue;
        }
        $buf .= $t . "\n";
        if (substr($t, -1) === ';') {
            if (!mysqli_query($connect, $buf)) {
                $err = mysqli_error($connect);
                fclose($fp); $zip->close();
                $resetTz();
                throw new RuntimeException("Gagal di baris $line database.sql: $err");
            }
            $n++;
            $buf = '';
        }
    }
    fclose($fp);
    $zip->close();
    $resetTz();
    if (!$done || $buf !== '') throw new RuntimeException('database.sql terpotong (tidak lengkap); pemulihan tidak selesai.');
    return $n;
}

/** Salin folder uploads dari zip (daftar putih subfolder & ekstensi). Mengembalikan jumlah file. */
function wmsRestoreUploads($zipPath, $password = '')
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return 0;
    if ($password !== '') $zip->setPassword($password);
    $n = 0;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (!preg_match('#^uploads/(arrivals|requests|avatars|invoices)/([A-Za-z0-9._-]+\.(?:jpe?g|png|gif|webp|pdf))$#i', $name, $m)) continue;
        $dir = __DIR__ . '/uploads/' . strtolower($m[1]);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (strtolower($m[1]) === 'invoices' && !is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        $in = $zip->getStream($name);
        if (!$in) continue;
        $out = @fopen($dir . '/' . $m[2], 'wb');
        if ($out) { stream_copy_to_stream($in, $out); fclose($out); $n++; }
        fclose($in);
    }
    $zip->close();
    return $n;
}

/** Pulihkan APP_KEY dari config.local.php di zip (hanya nilai kuncinya, divalidasi ketat). Mengembalikan true bila diubah. */
function wmsRestoreAppKey($zipPath, $password = '')
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) return false;
    if ($password !== '') $zip->setPassword($password);
    $raw = $zip->getFromName('config.local.php');
    $zip->close();
    if ($raw === false) return false;
    if (!preg_match('/define\(\s*[\'"]APP_KEY[\'"]\s*,\s*([\'"])([A-Za-z0-9+\/=_.:-]{16,200})\1\s*\)/', $raw, $m)) return false;
    $key = $m[2];
    $cfg = __DIR__ . '/config.local.php';
    $newLine = "define('APP_KEY', '" . $key . "');";
    if (is_file($cfg)) {
        $cur = file_get_contents($cfg);
        @copy($cfg, $cfg . '.sebelum-pulih-' . date('Ymd-His'));
        $re = '/define\(\s*[\'"]APP_KEY[\'"]\s*,\s*[\'"][^\'"]*[\'"]\s*\)\s*;/';
        if (preg_match($re, $cur)) {
            $cur = preg_replace_callback($re, function () use ($newLine) { return $newLine; }, $cur, 1);
        } else {
            $cur = preg_replace('/<\?php\s*/', "<?php\n" . $newLine . "\n", $cur, 1);
        }
        return file_put_contents($cfg, $cur) !== false;
    }
    return file_put_contents($cfg, "<?php\n" . $newLine . "\n") !== false;
}
