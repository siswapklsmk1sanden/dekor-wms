<?php declare(strict_types=1);

namespace DekorAsia\Wms\Application\Service;

use DekorAsia\Wms\Infrastructure\Audit\HashChainAuditLogger;
use DekorAsia\Wms\Infrastructure\Persistence\DatabaseTransactionManager;
use DekorAsia\Wms\Infrastructure\Security\SecurityGuard;
use PDO;

/** ssuai sm alur login di flowchrt */
final class AuthService
{
    private const MSG_INVALID = 'Email atau password salah, atau akun tidak dapat digunakan.';
    private const MSG_LOCKED  = 'Terlalu banyak percobaan gagal. Akun dikunci sementara, coba lagi beberapa menit lagi.';

    public function __construct(
        private readonly DatabaseTransactionManager $tx,
        private readonly SecurityGuard $guard,
        private readonly array $appConfig,
    ) {}

    /** @return array{ok: bool, message: string, user: ?array} */
    public function attemptLogin(string $email, #[\SensitiveParameter] string $password, string $ip, string $userAgent): array
    {
        $email = mb_strtolower(trim($email));

        $stmt = $this->tx->getConnection()->prepare(
            'SELECT u.id, u.full_name, u.email, u.password_hash, u.is_active,
                    (u.locked_until IS NOT NULL AND u.locked_until > NOW()) AS is_locked,
                    r.role_code, r.role_name, r.permissions_json
             FROM users u INNER JOIN roles r ON r.id = u.role_id
             WHERE u.email = :email LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        // 1. Email tidak ada
        if ($row === false) {
            $this->guard->burnHashingTime($password);
            $this->audit(null, 'LOGIN_FAILED', 0, ['reason' => 'unknown_email', 'email' => mb_substr($email, 0, 150)], $ip, $userAgent);
            return self::fail(self::MSG_INVALID);
        }
        $userId = (int) $row['id'];

        // 2. Akun nonaktif
        if ((int) $row['is_active'] !== 1) {
            $this->guard->burnHashingTime($password);
            $this->audit($userId, 'LOGIN_BLOCKED', $userId, ['reason' => 'inactive'], $ip, $userAgent);
            return self::fail(self::MSG_INVALID);
        }

        // 3. Akun sedang terkunci
        if ((int) $row['is_locked'] === 1) {
            $this->audit($userId, 'LOGIN_BLOCKED', $userId, ['reason' => 'locked'], $ip, $userAgent);
            return self::fail(self::MSG_LOCKED);
        }

        // 4. Password salah -> tambah hitungan gagal, kunci jika sudah mencapai batas
        if (!$this->guard->verifyPassword($password, (string) $row['password_hash'])) {
            return $this->tx->runInTransaction(function (PDO $pdo) use ($userId, $ip, $userAgent): array {
                $pdo->prepare(
                    'UPDATE users SET
                        locked_until = CASE WHEN failed_login_attempts + 1 >= :max1 THEN DATE_ADD(NOW(), INTERVAL :mins MINUTE) ELSE locked_until END,
                        failed_login_attempts = CASE WHEN failed_login_attempts + 1 >= :max2 THEN 0 ELSE failed_login_attempts + 1 END
                     WHERE id = :id'
                )->execute([
                    ':max1' => $this->appConfig['max_failed_logins'],
                    ':max2' => $this->appConfig['max_failed_logins'],
                    ':mins' => $this->appConfig['lock_minutes'],
                    ':id'   => $userId,
                ]);
                (new HashChainAuditLogger($pdo))->recordEvent($userId, 'LOGIN_FAILED', 'users', $userId, ['reason' => 'wrong_password'], $ip, $userAgent);
                return self::fail(self::MSG_INVALID);
            });
        }

        // 5. Berhasil
        return $this->tx->runInTransaction(function (PDO $pdo) use ($row, $userId, $password, $ip, $userAgent): array {
            if ($this->guard->needsRehash((string) $row['password_hash'])) {
                $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
                    ->execute([':h' => $this->guard->hashPassword($password), ':id' => $userId]);
            }
            $pdo->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = :id')
                ->execute([':id' => $userId]);
            (new HashChainAuditLogger($pdo))->recordEvent($userId, 'LOGIN_SUCCESS', 'users', $userId, ['role' => $row['role_code']], $ip, $userAgent);

            return [
                'ok' => true,
                'message' => '',
                'user' => [
                    'id'          => $userId,
                    'full_name'   => (string) $row['full_name'],
                    'email'       => (string) $row['email'],
                    'role_code'   => (string) $row['role_code'],
                    'role_name'   => (string) $row['role_name'],
                    'permissions' => json_decode((string) $row['permissions_json'], true, 512, JSON_THROW_ON_ERROR),
                ],
            ];
        });
    }

    public function recordLogout(array $user, string $ip, string $userAgent): void
    {
        $this->audit((int) $user['id'], 'LOGOUT', (int) $user['id'], [], $ip, $userAgent);
    }

    private function audit(?int $userId, string $action, int $entityId, array $payload, string $ip, string $ua): void
    {
        $hash = $this->tx->runInTransaction(
            fn (PDO $pdo): string => (new HashChainAuditLogger($pdo))->recordEvent($userId, $action, 'users', $entityId, $payload, $ip, $ua)
        );
        unset($hash);
    }

    /** @return array{ok: bool, message: string, user: ?array} */
    private static function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'user' => null];
    }
}
