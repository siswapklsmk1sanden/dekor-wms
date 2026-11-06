<?php
use DekorAsia\Wms\Infrastructure\Security\AuthorizationMiddleware;
use DekorAsia\Wms\Infrastructure\Security\SecurityGuard;

// Menu hanya tampil jika sudah login, dan disaring sesuai izin role.
$navUser  = $_SESSION['auth']['user'] ?? null;
$navMenus = [];
$navCsrf  = '';
if ($navUser !== null) {
    $navMenus = array_filter(
        config('permissions')['menus'],
        static fn (array $m): bool => AuthorizationMiddleware::can($navUser, $m['permission'])
    );
    $navCsrf = (new SecurityGuard(config('app')['secret_key']))->generateCsrfToken('logout');
}
$navPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? 'WMS') ?> - WMS PT. Dekor Asia Jayakarya</title>
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="<?= $navUser !== null ? 'has-sidebar' : '' ?>">
<?php if ($navUser !== null): ?>
<div class="app">
    <aside class="sidebar">
        <a class="brand" href="/dashboard">WMS Dekor Asia</a>

        <nav class="menu">
            <a href="/dashboard" class="<?= $navPath === '/dashboard' ? 'active' : '' ?>">Beranda</a>
            <?php foreach ($navMenus as $key => $menu):
                $active = str_starts_with($navPath, '/modul/' . $key)
                       || ($key === 'master' && str_starts_with($navPath, '/master')); ?>
                <a href="/modul/<?= e($key) ?>" class="<?= $active ? 'active' : '' ?>"><?= e($menu['label']) ?></a>
            <?php endforeach; ?>
        </nav>

        <div class="user-box">
            <span class="who"><?= e($navUser['full_name']) ?> (<?= e($navUser['role_name']) ?>)</span>
            <form method="post" action="/logout" class="inline">
                <input type="hidden" name="_csrf" value="<?= e($navCsrf) ?>">
                <button type="submit">Keluar</button>
            </form>
        </div>
    </aside>

    <main class="content">
        <?= $content ?>
    </main>
</div>
<?php else: ?>
<main class="content">
    <?= $content ?>
</main>
<?php endif; ?>
</body>
</html>
