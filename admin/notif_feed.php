<?php
/** Data notifikasi (JSON) untuk lonceng; dipanggil berkala oleh halaman. */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['now' => time(), 'items' => []]);
    exit;
}
require_once __DIR__ . '/notif_helper.php';
try {
    echo json_encode(wmsNotifications(), JSON_UNESCAPED_UNICODE);
} catch (Throwable $ex) {
    echo json_encode(['now' => time(), 'items' => []]);
}
