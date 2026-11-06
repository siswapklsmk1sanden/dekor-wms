<?php declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Hanya bisa dijalankan dari terminal.');
}
require __DIR__ . '/../bootstrap.php';

use DekorAsia\Wms\Infrastructure\Persistence\DatabaseTransactionManager;
use DekorAsia\Wms\Infrastructure\Security\SecurityGuard;

$db = config('database');
$tx = new DatabaseTransactionManager($db['host'], $db['port'], $db['name'], $db['user'], $db['pass']);
$pdo = $tx->getConnection();
$guard = new SecurityGuard(config('app')['secret_key']);

$upsertRole = $pdo->prepare(
    'INSERT INTO roles (role_code, role_name, permissions_json) VALUES (:c, :n, :p)
     ON DUPLICATE KEY UPDATE role_name = VALUES(role_name), permissions_json = VALUES(permissions_json)'
);
foreach (config('permissions')['roles'] as $code => $role) {
    $upsertRole->execute([':c' => $code, ':n' => $role['name'], ':p' => json_encode($role['permissions'], JSON_THROW_ON_ERROR)]);
}
echo "Roles diperbarui.\n\n";

$demoUsers = [
 ['STAFF_GUDANG',    'DAJ-0001', 'Staff Gudang Demo',    'staff.gudang@dekorasia.local'],
 ['STAFF_PPIC',      'DAJ-0002', 'Staff PPIC Demo',      'staff.ppic@dekorasia.local'],
 ['KEPALA_GUDANG',   'DAJ-0003', 'Kepala Gudang Demo',   'kepala.gudang@dekorasia.local'],
 ['AUDITOR_FINANCE', 'DAJ-0004', 'Auditor Finance Demo', 'auditor@dekorasia.local'],
 ['SUPER_ADMIN',     'DAJ-0005', 'Super Admin IT',       'admin@dekorasia.local'],
];

$findRole = $pdo->prepare('SELECT id FROM roles WHERE role_code = :c');
$exists = $pdo->prepare('SELECT 1 FROM users WHERE email = :e');
$insert = $pdo->prepare(
   'INSERT INTO users (role_id, employee_nik, full_name, email, password_hash) VALUES (:r, :nik, :n, :e, :h)'
);

foreach ($demoUsers as [$roleCode, $nik, $name, $email]) {
    $exists->execute([':e' => $email]);
    if ($exists->fetchColumn()) {
        echo "Lewati (sudah ada): {$email}\n";
        continue;
    }
    $findRole->execute([':c' => $roleCode]);
    $roleId = (int) $findRole->fetchColumn();
    $password = rtrim(strtr(base64_encode(random_bytes(9)), '+/', '-_'), '=');
    $insert->execute([':r' => $roleId, ':nik' => $nik, ':n' => $name, ':e' => $email, ':h' => $guard->hashPassword($password)]);
    echo sprintf("%-16s %-32s password: %s\n", $roleCode, $email, $password);
}
echo "\nCatat password di atas sekarang. Password tidak disimpan dalam bentuk asli.\n";
