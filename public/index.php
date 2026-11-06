<?php declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use DekorAsia\Wms\Application\Service\AuthService;
use DekorAsia\Wms\Infrastructure\Persistence\DatabaseTransactionManager;
use DekorAsia\Wms\Infrastructure\Security\AuthorizationMiddleware;
use DekorAsia\Wms\Infrastructure\Security\ForbiddenException;
use DekorAsia\Wms\Infrastructure\Security\SecurityGuard;
use DekorAsia\Wms\Infrastructure\Security\SessionManager;
use DekorAsia\Wms\Infrastructure\Security\UnauthenticatedException;
use DekorAsia\Wms\Presentation\Controller\AuthController;
use DekorAsia\Wms\Presentation\Controller\DashboardController;
use DekorAsia\Wms\Presentation\Controller\UnitController;
use DekorAsia\Wms\Presentation\View\View;

$app = config('app');
date_default_timezone_set($app['timezone']);
error_reporting(E_ALL);
ini_set('display_errors', $app['debug'] ? '1' : '0');

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store');

try {
    $session = new SessionManager($app);
    $session->start();

    $guard = new SecurityGuard($app['secret_key']);
    $middleware = new AuthorizationMiddleware($session);

    $authFactory = static function () use ($guard, $app): AuthService {
        $db = config('database');
        $tx = new DatabaseTransactionManager($db['host'], $db['port'], $db['name'], $db['user'], $db['pass']);
        return new AuthService($tx, $guard, $app);
    };

    $auth = new AuthController($session, $guard, $authFactory);
    $dashboard = new DashboardController($middleware, $guard);
        $pdoFactory = static function (): \PDO {
        $db = config('database');
        return (new DatabaseTransactionManager($db['host'], $db['port'], $db['name'], $db['user'], $db['pass']))->getConnection();
    };
       $units = new UnitController($middleware, $pdoFactory, $guard);

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';

    if ($method === 'GET' && $path === '/') {
        View::redirect($session->currentUser() !== null ? '/dashboard' : '/login');
    } elseif ($method === 'GET' && $path === '/login') {
        $auth->showLogin();
    } elseif ($method === 'POST' && $path === '/login') {
        $auth->handleLogin();
    } elseif ($method === 'POST' && $path === '/logout') {
        $auth->handleLogout();
    } elseif ($method === 'GET' && $path === '/dashboard') {
         $dashboard->index();
    } elseif ($method === 'GET' && $path === '/master/units') {   
         $units->index();
    } elseif ($method === 'GET' && preg_match('#^/modul/([a-z]+)$#', $path, $m) === 1) {
        $dashboard->module($m[1]);
    } else {
        http_response_code(404);
        View::render('error', ['title' => '404', 'heading' => 'Halaman tidak ditemukan', 'message' => 'Alamat yang Anda buka tidak tersedia.']);
    }
} catch (UnauthenticatedException) {
    View::redirect('/login');
} catch (ForbiddenException) {
    http_response_code(403);
    View::render('error', ['title' => '403', 'heading' => 'Akses ditolak', 'message' => 'Role Anda tidak memiliki izin untuk halaman ini.']);
} catch (Throwable $e) {
    error_log((string) $e);
    http_response_code(500);
    View::render('error', [
        'title'   => '500',
        'heading' => 'Terjadi kesalahan sistem',
        'message' => $app['debug'] ? $e->getMessage() : 'Silakan hubungi tim IT.',
    ]);
}
