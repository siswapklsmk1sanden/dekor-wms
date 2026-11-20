<?php
/**
 * Cadangan otomatis lewat command line (dijadwalkan dengan backup_otomatis.bat / Windows Task Scheduler).
 * Pemakaian: php backup_cli.php [--simpan=14] [--tanpa-kunci]
 * Hasil: admin\backups\otomatis-YYYYmmdd-HHMMSS.zip (berisi database, foto, dan APP_KEY).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['SCRIPT_NAME'] = '/admin/backup_cli.php';
require_once __DIR__ . '/backup_helper.php';

$keep = 14;
$withKey = true;
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--simpan=(\d{1,3})$/', $a, $m)) $keep = max(1, (int)$m[1]);
    if ($a === '--tanpa-kunci') $withKey = false;
}
$name = 'otomatis-' . date('Ymd-His') . '.zip';
$dest = wmsBackupDir() . '/' . $name;
try {
    $m = wmsBackupCreate($connect, $dest, ['include_key' => $withKey, 'password' => '']);
    wmsBackupPrune($keep);
    logActivity('CREATE', 'Backup', "Cadangan otomatis $name (" . array_sum($m['tables']) . ' baris, ' . $m['uploads'] . ' foto'
        . ($m['includes_key'] ? ', dengan APP_KEY' : ', TANPA APP_KEY') . ')', '');
    echo "OK: $dest\n";
    exit(0);
} catch (Throwable $ex) {
    @unlink($dest);
    fwrite(STDERR, 'GAGAL: ' . $ex->getMessage() . "\n");
    exit(1);
}
