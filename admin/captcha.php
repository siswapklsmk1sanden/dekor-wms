<?php
require_once __DIR__ . '/config.php';

// Tanpa 0/O/1/l/I supaya tidak membingungkan
$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
$code = '';
for ($i = 0; $i < 6; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
$_SESSION['login_captcha'] = $code;

$w = 166; $h = 48;
$palette = ['#38bdf8', '#fb923c', '#34d399', '#a78bfa', '#f472b6', '#facc15'];

$svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">';
$svg .= '<rect width="100%" height="100%" fill="#0d1424"/>';

// garis pengganggu
for ($i = 0; $i < 5; $i++) {
    $svg .= '<line x1="' . random_int(0, $w) . '" y1="' . random_int(0, $h) . '" x2="' . random_int(0, $w) . '" y2="' . random_int(0, $h) . '" stroke="' . $palette[array_rand($palette)] . '" stroke-width="1.2" opacity="0.7"/>';
}

// huruf, masing-masing sedikit miring dan bergeser
foreach (str_split($code) as $i => $ch) {
    $x = 14 + $i * 24;
    $y = 32 + random_int(-4, 4);
    $r = random_int(-18, 18);
    $svg .= '<text x="' . $x . '" y="' . $y . '" font-family="Arial, Helvetica, sans-serif" font-size="28" font-weight="bold" fill="' . $palette[array_rand($palette)] . '" transform="rotate(' . $r . ' ' . $x . ' ' . $y . ')">' . $ch . '</text>';
}
$svg .= '</svg>';

if (ob_get_length()) ob_end_clean();
header('Content-Type: image/svg+xml');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
echo $svg;