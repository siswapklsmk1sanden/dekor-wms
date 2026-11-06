<h1>Login WMS PT. Dekor Asia Jayakarya</h1>

<?php if (!empty($error)): ?>
    <p role="alert"><?= e($error) ?></p>
<?php endif; ?>

<form method="post" action="/login" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">
    <p>
        <label for="email">Email</label><br>
        <input type="email" id="email" name="email" value="<?= e($email ?? '') ?>" maxlength="150" required autofocus>
    </p>
    <p>
        <label for="password">Password</label><br>
        <input type="password" id="password" name="password" maxlength="1024" autocomplete="current-password" required>
    </p>
    <button type="submit">Masuk</button>
</form>
