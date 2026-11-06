<style>
    :root {
        --primary: #1f4e79;
        --border: #c8ced6;
        --error-bg: #fdecea;
        --error-text: #b42318;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f3f5f8;
        font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
    }
    .login-card {
        width: 100%;
        max-width: 380px;
        background: #fff;
        padding: 32px;
        border-radius: 10px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, .08);
    }
    .login-card h1 { margin: 0 0 24px; font-size: 1.3rem; text-align: center; }
    .field { margin-bottom: 16px; }
    .field label { display: block; margin-bottom: 6px; font-size: .9rem; }
    .field input {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--border);
        border-radius: 6px;
        font-size: 1rem;
    }
    .field input:focus { outline: 2px solid var(--primary); border-color: transparent; }
    .alert {
        margin: 0 0 16px;
        padding: 10px 12px;
        background: var(--error-bg);
        color: var(--error-text);
        border-radius: 6px;
        font-size: .9rem;
    }
    button[type="submit"] {
        width: 100%;
        padding: 11px;
        border: 0;
        border-radius: 6px;
        background: var(--primary);
        color: #fff;
        font-size: 1rem;
        cursor: pointer;
    }
    button[type="submit"]:hover { filter: brightness(1.1); }
</style>

<main class="login-card">
    <h1>Login WMS PT. Dekor Asia Jayakarya</h1>

    <?php if (!empty($error)): ?>
        <p class="alert" role="alert"><?= e($error) ?></p>
    <?php endif; ?>

    <form method="post" action="/login">
        <input type="hidden" name="_csrf" value="<?= e($csrf) ?>">

        <div class="field">
            <label for="email">Email</label>
            <input type="email" id="email" name="email"
                   value="<?= e($email ?? '') ?>"
                   maxlength="150" autocomplete="username" required autofocus>
        </div>

        <div class="field">
            <label for="password">Password</label>
            <input type="password" id="password" name="password"
                   maxlength="1024" autocomplete="current-password" required>
        </div>

        <button type="submit">Masuk</button>
    </form>
</main>
