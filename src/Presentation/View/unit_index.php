<h1>Data Master: Satuan</h1>

<?php if ($units === []): ?>
    <p>Belum ada data satuan.</p>
<?php else: ?>
    <table border="1" cellpadding="6" cellspacing="0">
        <thead>
            <tr><th>No</th><th>Kode</th><th>Nama</th><th>Angka desimal</th></tr>
        </thead>
        <tbody>
            <?php foreach ($units as $i => $u): ?>
                <tr>
                    <td><?= $i + 1 ?></td>
                    <td><?= e($u['unit_code']) ?></td>
                    <td><?= e($u['unit_name']) ?></td>
                    <td><?= (int) $u['decimal_places'] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<p><a href="/dashboard">Kembali ke beranda</a></p>