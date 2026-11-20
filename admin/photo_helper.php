<?php
/**
 * Helper foto: foto kedatangan barang (Barang Masuk, Persetujuan, Lot Batch) dan
 * foto acuan permintaan Buyer (Permintaan Buyer, SPK).
 *
 * - Foto kedatangan disimpan di admin/uploads/arrivals/ dan DISTEMPEL waktu server
 *   (hari, tanggal, tahun, jam:menit:detik WIB) tepat saat foto diunggah.
 * - Foto acuan Buyer disimpan di admin/uploads/requests/ (tanpa stempel).
 * Folder dibuat otomatis, eksekusi PHP diblokir. Ubah INBOUND_PHOTO_REQUIRED menjadi false
 * jika foto kedatangan tidak ingin diwajibkan.
 */
if (!defined('ARRIVAL_DIR_REL')) {
    define('ARRIVAL_DIR_REL', 'uploads/arrivals');
    define('REQUEST_DIR_REL', 'uploads/requests');
    define('ARRIVAL_MAX_BYTES', 8 * 1024 * 1024);   // foto kamera HP bisa 3-6 MB
    define('ARRIVAL_MAX_SIDE', 1280);               // sisi terpanjang setelah dikecilkan (px)
    define('INBOUND_PHOTO_REQUIRED', true);
}

/** Waktu lengkap berbahasa Indonesia: "Senin, 16 November 2026 14:23:05 WIB". */
function wmsFmtWaktu($dt): string
{
    $ts = is_numeric($dt) ? (int)$dt : strtotime((string)$dt);
    if (!$ts) return '-';
    $hari  = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus',
              'September', 'Oktober', 'November', 'Desember'];
    return $hari[(int)date('w', $ts)] . ', ' . (int)date('j', $ts) . ' ' . $bulan[(int)date('n', $ts)]
         . ' ' . date('Y H:i:s', $ts) . ' WIB';
}

/** Pastikan folder ada, tidak bisa mengeksekusi PHP, dan tidak bisa dilist. */
function wmsEnsurePhotoDir(string $rel): string
{
    $dir = __DIR__ . '/' . $rel;
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "<FilesMatch \"\\.(php|phtml|phar)$\">\nRequire all denied\n</FilesMatch>\n");
    }
    if (!is_file($dir . '/index.html')) @file_put_contents($dir . '/index.html', '');
    return $dir;
}

function wmsEnsureArrivalDir(): string { return wmsEnsurePhotoDir(ARRIVAL_DIR_REL); }

/** Kembalikan path relatif jika valid & filenya ada, selain itu string kosong. */
function wmsValidPhotoPath($rel, string $dirRel): string
{
    $rel = trim((string)$rel);
    if (!preg_match('#^' . preg_quote($dirRel, '#') . '/[A-Za-z0-9_]{8,64}\.jpg$#', $rel)) return '';
    return is_file(__DIR__ . '/' . $rel) ? $rel : '';
}

function wmsValidArrivalPath($rel): string { return wmsValidPhotoPath($rel, ARRIVAL_DIR_REL); }
function wmsValidRequestPath($rel): string { return wmsValidPhotoPath($rel, REQUEST_DIR_REL); }

/**
 * Waktu foto kedatangan (Y-m-d H:i:s) dibaca dari nama file (a20261116_142305_xxxx.jpg).
 * Nama file dibuat server saat foto diunggah, jadi ini waktu kedatangan yang tercatat.
 */
function wmsPhotoTimeFromPath($rel): ?string
{
    if (!preg_match('#/a(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})_[A-Za-z0-9]+\.jpg$#', (string)$rel, $m)) return null;
    if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1]) || (int)$m[4] > 23 || (int)$m[5] > 59 || (int)$m[6] > 59) return null;
    return "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
}

/** Bubuhkan pita waktu di bagian bawah gambar (TTF bila tersedia, jika tidak font bawaan GD diperbesar). */
function wmsStampPhoto($img, string $text): void
{
    $w = imagesx($img);
    $h = imagesy($img);
    $barH = max(28, (int)round($h * 0.06));
    imagealphablending($img, true);
    imagefilledrectangle($img, 0, $h - $barH, $w, $h, imagecolorallocatealpha($img, 0, 0, 0, 45));
    $white = imagecolorallocate($img, 255, 255, 255);

    $font = null;
    foreach (['C:/Windows/Fonts/arial.ttf', 'C:/Windows/Fonts/segoeui.ttf',
              '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/dejavu/DejaVuSans.ttf',
              '/Library/Fonts/Arial.ttf'] as $cand) {
        if (is_file($cand)) { $font = $cand; break; }
    }
    if ($font && function_exists('imagettftext') && function_exists('imagettfbbox')) {
        $size = max(8.0, $barH * 0.34);
        for ($i = 0; $i < 20; $i++) {
            $box = @imagettfbbox($size, 0, $font, $text);
            if (!$box || abs($box[2] - $box[0]) <= $w - 20 || $size <= 6) break;
            $size *= 0.9;
        }
        if ($box && @imagettftext($img, $size, 0, 10, $h - (int)round($barH * 0.3), $white, $font, $text) !== false) return;
    }

    // Cadangan: font bitmap GD (9x15 px per huruf) digambar kecil lalu diperbesar.
    $len = max(1, strlen($text));
    $tmp = imagecreatetruecolor($len * 9 + 4, 19);
    imagefill($tmp, 0, 0, imagecolorallocate($tmp, 0, 0, 0));
    imagestring($tmp, 5, 2, 2, $text, imagecolorallocate($tmp, 255, 255, 255));
    $scale = max(1, min((int)floor(($barH * 0.7) / 15), (int)floor(($w - 20) / ($len * 9 + 4))));
    imagecopyresized($img, $tmp, 10, $h - $barH + (int)(($barH - 19 * $scale) / 2),
                     0, 0, ($len * 9 + 4) * $scale, 19 * $scale, $len * 9 + 4, 19);
    unset($tmp);
}

/**
 * Validasi, putar sesuai EXIF, kecilkan, (opsional) stempel waktu, simpan sebagai JPG.
 * Return path relatif atau null.
 */
function wmsSavePhoto(array $f, array &$errors, string $dirRel, string $prefix, bool $stamp): ?string
{
    if (!function_exists('imagecreatetruecolor')) { $errors[] = 'Ekstensi PHP GD belum aktif di server.'; return null; }
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || (int)($f['size'] ?? 0) > ARRIVAL_MAX_BYTES) {
        $errors[] = 'Ukuran foto terlalu besar (maksimal 8 MB; cek juga upload_max_filesize di php.ini).';
        return null;
    }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { $errors[] = 'Unggah foto gagal, coba lagi.'; return null; }

    $info = @getimagesize($f['tmp_name']);
    $mime = $info['mime'] ?? '';
    switch ($mime) {
        case 'image/jpeg': $src = @imagecreatefromjpeg($f['tmp_name']); break;
        case 'image/png':  $src = @imagecreatefrompng($f['tmp_name']); break;
        case 'image/webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($f['tmp_name']) : false; break;
        default: $src = false;
    }
    if (!$src) { $errors[] = 'Format foto harus JPG, PNG, atau WEBP yang valid.'; return null; }

    // Foto dari kamera HP sering tersimpan "miring" lewat tag EXIF: putar agar tegak.
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif  = @exif_read_data($f['tmp_name']);
        $o     = (int)($exif['Orientation'] ?? 1);
        $angle = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($angle) {
            $rot = @imagerotate($src, $angle, 0);
            if ($rot) $src = $rot;
        }
    }

    $w = imagesx($src); $h = imagesy($src);
    $scale = min(1, ARRIVAL_MAX_SIDE / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $now = time();   // satu-satunya sumber waktu: nama file dan stempel selalu sama
    if ($stamp) wmsStampPhoto($dst, wmsFmtWaktu($now));

    $dir  = wmsEnsurePhotoDir($dirRel);
    $name = $prefix . date('Ymd_His', $now) . '_' . bin2hex(random_bytes(6)) . '.jpg';
    $ok   = @imagejpeg($dst, $dir . '/' . $name, 82);
    unset($src, $dst);
    if (!$ok) { $errors[] = 'Gagal menyimpan foto. Periksa izin folder ' . $dirRel . '.'; return null; }
    return $dirRel . '/' . $name;
}

/** Foto barang yang datang: distempel waktu server. */
function wmsSaveArrivalPhoto(array $f, array &$errors): ?string
{
    return wmsSavePhoto($f, $errors, ARRIVAL_DIR_REL, 'a', true);
}

/** Foto acuan "barang seperti apa" dari Buyer: tanpa stempel. */
function wmsSaveRequestPhoto(array $f, array &$errors): ?string
{
    return wmsSavePhoto($f, $errors, REQUEST_DIR_REL, 'r', false);
}
