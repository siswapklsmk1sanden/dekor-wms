<?php declare(strict_types=1);

namespace DekorAsia\Wms\Presentation\Controller;

use DekorAsia\Wms\Infrastructure\Security\AuthorizationMiddleware;
use DekorAsia\Wms\Infrastructure\Security\SecurityGuard;
use DekorAsia\Wms\Presentation\View\View;

final class DashboardController
{
    public function __construct(
        private readonly AuthorizationMiddleware $middleware,
        private readonly SecurityGuard $guard,
    ) {}

    /** Halaman utama: hanya menampilkan menu yang boleh dibuka oleh role user. */
    public function index(): void
    {
        $user = $this->middleware->requireLogin();
        $menus = array_filter(
            config('permissions')['menus'],
            static fn (array $menu): bool => AuthorizationMiddleware::can($user, $menu['permission'])
        );

        View::render('dashboard', [
            'title'      => 'Beranda',
            'user'       => $user,
            'menus'      => $menus,
            'csrfLogout' => $this->guard->generateCsrfToken('logout'),
        ]);
    }

    /** Halaman sementara tiap modul. Akses tetap diperiksa lewat izin menu (bukan sekadar disembunyikan). */
    public function module(string $key): void
    {
        $menus = config('permissions')['menus'];
        if (!isset($menus[$key])) {
            $this->middleware->requireLogin();
            http_response_code(404);
            View::render('error', ['title' => '404', 'heading' => 'Halaman tidak ditemukan', 'message' => 'Modul yang diminta tidak ada.']);
            return;
        }
        $this->middleware->requirePermission($menus[$key]['permission']);
        View::render('module_placeholder', ['title' => $menus[$key]['label'], 'menu' => $menus[$key]]);
    }
}
