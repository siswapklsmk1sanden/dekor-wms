<?php declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Hanya bisa dijalankan dari terminal.');
}
require __DIR__ . '/../bootstrap.php';

use DekorAsia\Wms\Infrastructure\Persistence\DatabaseTransactionManager;
use DekorAsia\Wms\Infrastructure\Security\SecurityGuard;

$email = strtolower(trim($argv[1] ?? ''));
if ($email === '') {
    exit("Cara pakai: php bin/reset_password.php email@dekorasia.local\n");
}

$db  = config('database');
$tx  = new DatabaseTransactionManager($db['host'], $db['port'], $db['name'], $db['user'], $db['pass']);
$pdo = $tx->getConnection();
$guard = new SecurityGuard(config('app')['secret_key']);

$cek = $pdo->prepare('SELECT id FROM users WHERE email = :e');
$cek->execute([':e' => $email]);
if (!$cek->fetchColumn()) {
    exit("Email {$email} tidak ada di tabel users.\n");
}

$password = rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=');
$pdo->prepare(
    'UPDATE users SET password_hash = :h, is_active = 1, failed_login_attempts = 0, locked_until = NULL WHERE email = :e'
)->execute([':h' => $guard->hashPassword($password), ':e' => $email]);

echo "Password baru untuk {$email}: {$password}\n";