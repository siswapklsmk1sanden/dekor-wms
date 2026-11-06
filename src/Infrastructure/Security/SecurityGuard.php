<?php declare(strict_types=1);

namespace DekorAsia\Wms\Infrastructure\Security;

use RuntimeException;

final class SecurityGuard
{
    private const ARGON_OPTIONS = ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 2];

    public function __construct(
        #[\SensitiveParameter]
        private readonly string $appSecretKey
    ) {
        if (strlen($this->appSecretKey) < 32) {
            throw new RuntimeException('APP_SECRET_KEY di file .env harus minimal 32 karakter.');
        }
    }

    public function hashPassword(#[\SensitiveParameter] string $plainPassword): string
    {
        return password_hash($plainPassword, PASSWORD_ARGON2ID, self::ARGON_OPTIONS);
    }

    public function verifyPassword(#[\SensitiveParameter] string $plainPassword, string $storedHash): bool
    {
        return password_verify($plainPassword, $storedHash);
    }

    public function needsRehash(string $storedHash): bool
    {
        return password_needs_rehash($storedHash, PASSWORD_ARGON2ID, self::ARGON_OPTIONS);
    }

    public function burnHashingTime(#[\SensitiveParameter] string $plainPassword): void
    {
        $this->hashPassword($plainPassword);
    }

    public function generateCsrfToken(string $formActionName): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('Sesi HTTP belum aktif saat membuat CSRF token.');
        }
        if (empty($_SESSION['_csrf_seed'])) {
            $_SESSION['_csrf_seed'] = bin2hex(random_bytes(32));
        }
        $payload = session_id() . '|' . $formActionName . '|' . $_SESSION['_csrf_seed'];
        return hash_hmac('sha256', $payload, $this->appSecretKey);
    }


    public function validateCsrfToken(string $formActionName, string $submittedToken): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE || $submittedToken === '' || empty($_SESSION['_csrf_seed'])) {
            return false;
        }
        return hash_equals($this->generateCsrfToken($formActionName), $submittedToken);
    }
}
