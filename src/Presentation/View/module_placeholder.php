<h1><?= e($menu['label']) ?></h1>
<p>Halaman ini sudah dilindungi RBAC. Isi modulnya dikerjakan pada tahap berikutnya.</p>

<?php if (($menu['permission'] ?? '') === 'master.access'): ?>
    <ul>
        <li><a href="/master/units">Satuan</a></li>
    </ul>
<?php endif; ?>

<p><a href="/dashboard">Kembali ke beranda</a></p>