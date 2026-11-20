<?php
require_once __DIR__ . '/config.php';
// Logout hanya lewat POST + token CSRF (mencegah logout paksa lewat link/gambar tersembunyi).
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/admin/' . (empty($_SESSION['user_id']) ? 'login.php' : 'index.php'));
    exit;
}
if (!empty($_SESSION['user_id'])) {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        die('Token keamanan tidak valid. Kembali dan muat ulang halaman.');
    }
    logActivity('LOGOUT', 'Users', 'Logout: ' . ($_SESSION['username'] ?? ''), $_SESSION['user_id']);
}
$_SESSION = [];
session_destroy();
header('Location: ' . BASE_URL . '/admin/login.php');
exit;
