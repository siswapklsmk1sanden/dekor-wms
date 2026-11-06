<h1>Beranda WMS</h1>
<p>Selamat datang, <strong><?= e($user['full_name']) ?></strong> (<?= e($user['role_name']) ?>)</p>

<h2>Menu</h2>
<?php if ($menus === []): ?>
    <p>Role Anda belum memiliki menu.</p>
<?php else: ?>
    <ul>
        <?php foreach ($menus as $key => $menu): ?>
            <li><a href="/modul/<?= e($key) ?>"><?= e($menu['label']) ?></a></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="post" action="/logout">
    <input type="hidden" name="_csrf" value="<?= e($csrfLogout) ?>">
    <button type="submit">Keluar</button>
</form>
