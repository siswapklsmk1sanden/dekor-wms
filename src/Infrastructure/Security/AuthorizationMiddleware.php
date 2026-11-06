<?php declare(strict_types=1);

namespace DekorAsia\Wms\Infrastructure\Security;

final class AuthorizationMiddleware
{
    public function __construct(private readonly SessionManager $session) {}

    /** Harus sudah login, kalau belum -> UnauthenticatedException (router mengalihkan ke /login). */
    public function requireLogin(): array
    {
        $user = $this->session->currentUser();
        if ($user === null) {
            throw new UnauthenticatedException('Belum login.');
        }
        return $user;
    }

    /** Harus login DAN punya izin tertentu, kalau tidak -> ForbiddenException (HTTP 403). */
    public function requirePermission(string $permission): array
    {
        $user = $this->requireLogin();
        if (!self::can($user, $permission)) {
            throw new ForbiddenException("Role {$user['role_code']} tidak punya izin {$permission}.");
        }
        return $user;
    }

    public static function can(array $user, string $permission): bool
    {
        $granted = $user['permissions'] ?? [];
        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }
}
