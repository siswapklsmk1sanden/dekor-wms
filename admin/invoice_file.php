<?php
/**
 * Membuka lampiran scan faktur supplier (PDF / JPG / PNG) setelah memeriksa hak akses modul 'payable'.
 * File disimpan di admin/uploads/invoices (folder diblokir dari akses URL langsung).
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/payable_helper.php';
requirePermission('payable', 'view');
wmsRequireSchemaExt();

$id = (int)($_GET['id'] ?? 0);
$st = mysqli_prepare($connect, "SELECT attachment_file, attachment_name FROM supplier_invoices WHERE id = ?");
mysqli_stmt_bind_param($st, 'i', $id);
mysqli_stmt_execute($st);
$r = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
$file = $r ? (string)$r['attachment_file'] : '';
if ($file === '' || !preg_match('/^inv_\d{8}_\d{6}_[a-f0-9]{12}\.(pdf|jpg|png)$/', $file, $m)) {
    http_response_code(404);
    die('Lampiran tidak ditemukan.');
}
$path = payInvoiceDir() . '/' . $file;
if (!is_file($path)) {
    http_response_code(404);
    die('File lampiran tidak ada di server.');
}
$types = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
$dl = preg_replace('/[^A-Za-z0-9._ -]+/', '_', (string)($r['attachment_name'] ?: $file));
header('Content-Type: ' . $types[$m[1]]);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $dl . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
