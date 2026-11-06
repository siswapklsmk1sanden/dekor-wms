<?php declare(strict_types=1);

namespace DekorAsia\Wms\Presentation\Controller;

use Closure;
use DekorAsia\Wms\Application\Service\AuthService;
use DekorAsia\Wms\Infrastructure\Security\SecurityGuard;
use DekorAsia\Wms\Infrastructure\Security\SessionManager;
use DekorAsia\Wms\Presentation\View\View;

final class AuthController
{
    /** @param Closure(): AuthService $authServiceFactory koneksi DB baru dibuat saat benar-benar perlu */
    public function __construct(
        private readonly SessionManager $session,
        private readonly SecurityGuard $guard,
        private readonly Closure $authServiceFactory,
    ) {}

    public function showLogin(): void
    {
        if ($this->session->currentUser() !== null) {
            View::redirect('/dashboard');
        }
        $this->renderLogin(null, '');
    }

    public function handleLogin(): void
    {
        $email = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 150);
        $password = (string) ($_POST['password'] ?? '');

        // Flowchart: "Kode keamanan form benar?" -> Tidak: Form ditolak
        if (!$this->guard->validateCsrfToken('login', (string) ($_POST['_csrf'] ?? ''))) {
            http_response_code(403);
            $this->renderLogin('Kode keamanan form tidak valid. Muat ulang halaman lalu coba lagi.', $email);
            return;
        }
        if ($email === '' || $password === '' || strlen($password) > 1024) {
            http_response_code(422);
            $this->renderLogin('Email dan password wajib diisi.', $email);
            return;
        }

        /** @var AuthService $auth */
        $auth = ($this->authServiceFactory)();
        $result = $auth->attemptLogin($email, $password, $this->clientIp(), $this->userAgent());

        if (!$result['ok']) {
            http_response_code(401);
            $this->renderLogin($result['message'], $email);
            return;
        }

        $this->session->login($result['user']);
        View::redirect('/dashboard');
    }

    public function handleLogout(): void
    {
        $user = $this->session->currentUser();
        if ($user !== null && $this->guard->validateCsrfToken('logout', (string) ($_POST['_csrf'] ?? ''))) {
            /** @var AuthService $auth */
            $auth = ($this->authServiceFactory)();
            $auth->recordLogout($user, $this->clientIp(), $this->userAgent());
            $this->session->destroy();
        }
        View::redirect('/login');
    }

    private function renderLogin(?string $error, string $email): void
    {
        View::render('login', [
            'title' => 'Login',
            'csrf'  => $this->guard->generateCsrfToken('login'),
            'error' => $error,
            'email' => $email,
        ]);
    }

    // REMOTE_ADDR saja; header X-Forwarded-For bisa dipalsukan klien.
    private function clientIp(): string
    {
        return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    private function userAgent(): string
    {
        return (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
    }
}
