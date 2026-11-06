<?php declare(strict_types=1);

namespace DekorAsia\Wms\Infrastructure\Security;

final class SessionManager
{
    public function __construct(private readonly array $appConfig) {}

    public function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', '28800');
        session_name('DAJWMSSESSID');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => (bool) $this->appConfig['cookie_secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    /** Dipanggil setelah login berhasil. ID sesi diganti agar kebal session fixation. */
    public function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['_csrf_seed'] = bin2hex(random_bytes(32));
        $_SESSION['auth'] = ['user' => $user, 'last_activity' => time()];
    }

    /** Mengembalikan data user yang sedang login, atau null (termasuk jika sesi idle terlalu lama). */
    public function currentUser(): ?array
    {
        $auth = $_SESSION['auth'] ?? null;
        if (!is_array($auth)) {
            return null;
        }
        if (time() - (int) $auth['last_activity'] > (int) $this->appConfig['session_idle_seconds']) {
            $this->destroy();
            $this->start();
            return null;
        }
        $_SESSION['auth']['last_activity'] = time();
        return $auth['user'];
    }

    public function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $p['path'],
                'domain'   => $p['domain'],
                'secure'   => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => 'Strict',
            ]);
        }
        session_destroy();
    }
}
