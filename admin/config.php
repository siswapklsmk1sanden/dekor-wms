<?php
/**
 * Global Configuration, Database Connection, RBAC Guard & Audit Auto-Logger
 * Project: WMS PHP Native (PT. Dekor Asia Jayakarya)
 * Lokasi : admin/config.php
 * Target : PHP 8.0+ (diuji untuk Laragon PHP 8.5), ekstensi mysqli & openssl
 */

// 1. Inisialisasi Session Global (cookie aman + batas idle)
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
if (!defined('SESSION_IDLE_LIMIT')) {
    define('SESSION_IDLE_LIMIT', 4 * 3600);   // logout otomatis setelah 4 jam tanpa aktivitas
}
function wmsSessionExpired($last, $now, $limit = SESSION_IDLE_LIMIT)
{
    return $last !== null && ($now - (int)$last) > $limit;
}
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'secure' => $isHttps,
        'httponly' => true, 'samesite' => 'Lax',
    ]);
    session_start();
    if (!empty($_SESSION['user_id'])) {
        if (wmsSessionExpired($_SESSION['last_activity'] ?? null, time())) {
            $_SESSION = [];
            session_regenerate_id(true);
        } else {
            $_SESSION['last_activity'] = time();
        }
    }
}
date_default_timezone_set('Asia/Jakarta');


// 2. Parameter Database MySQL
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';          // Kosongkan jika default Laragon/XAMPP
$db_name = 'dekorwms';
$db_port = 3306;

// Override lokal (opsional): buat admin/config.local.php untuk mengganti $db_* dan APP_KEY
// tanpa mengubah file ini. Lihat config.local.php.example.
if (is_file(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

// Kunci enkripsi data sensitif (ganti dengan string acak 32+ karakter)
if (!defined('APP_KEY')) {
    define('APP_KEY', 'GANTI-DENGAN-KUNCI-ACAK-MINIMAL-32-KARAKTER');
}

// 3. Inisialisasi Koneksi MySQLi (PHP 8.1+ melempar exception secara default)
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
try {
    $connect = mysqli_connect($db_host, $db_user, $db_pass, $db_name, $db_port);
    mysqli_set_charset($connect, "utf8mb4");
} catch (mysqli_sql_exception $ex) {
    http_response_code(500);
    die("Koneksi Database Gagal. Periksa MySQL/MariaDB Laragon dan database '" . $db_name . "'.");
}
// Setelah koneksi, kembalikan ke mode lama agar query gagal bisa ditangani ($rs ? ... : null)
mysqli_report(MYSQLI_REPORT_OFF);

// 4. Konfigurasi Rute Dinamis & BASE_URL
// BASE_URL = alamat folder induk dari /admin, apa pun nama foldernya
// (dekorwms, dekor-wms, atau root). Contoh: http://localhost/dekor-wms
if (!defined('BASE_URL')) {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $port  = isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443;
    $protocol = ($https || $port) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $pos = strrpos($script_dir, '/admin');
    $base_dir = ($pos !== false) ? substr($script_dir, 0, $pos) : rtrim($script_dir, '/');

    define('BASE_URL', $protocol . $host . $base_dir);
}

// 5. Fungsi Sanitasi Input Universal
if (!function_exists('escapeString')) {
    function escapeString($data)
    {
        global $connect;
        if (is_array($data)) {
            return array_map('escapeString', $data);
        }
        return mysqli_real_escape_string($connect, trim($data ?? ''));
    }
}

if (!function_exists('e')) {
    function e($data)
    {
        return htmlspecialchars((string)$data, ENT_QUOTES, 'UTF-8');
    }
}

// 6. Helper Enkripsi Data Sensitif (AES-256-GCM)
if (!function_exists('encryptData')) {
    function encryptData($plain)
    {
        $key = hash('sha256', APP_KEY, true);
        $iv  = random_bytes(12);
        $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key,
                               OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $ct);
    }

    function decryptData($blob)
    {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 29) return null;
        $key = hash('sha256', APP_KEY, true);
        $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key,
                               OPENSSL_RAW_DATA, substr($raw, 0, 12),
                               substr($raw, 12, 16));
        return $out === false ? null : $out;
    }
}

// =====================================================================
// 7. RBAC GUARD (HAK AKSES PER MODUL BERDASARKAN TABEL role_permissions)
// =====================================================================
// Saat login, halaman login wajib mengisi $_SESSION['user_id'],
// ['username'], ['role_id'] dan ['role_code'].
if (!function_exists('requireLogin')) {
    function requireLogin()
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: ' . BASE_URL . '/admin/login.php');
            exit;
        }
        // Password default/lemah: wajib diganti dulu, semua halaman lain diarahkan ke profil.
        $self = basename($_SERVER['SCRIPT_NAME'] ?? '');
        if (!empty($_SESSION['weak_pw']) && !in_array($self, ['profile.php', 'logout.php'], true)) {
            header('Location: ' . BASE_URL . '/admin/profile.php');
            exit;
        }
    }
}

if (!function_exists('wmsKeyIsDefault')) {
    function wmsKeyIsDefault()
    {
        return strpos(APP_KEY, 'GANTI-DENGAN') === 0 || strlen(APP_KEY) < 32;
    }
}

if (!function_exists('getPermission')) {
    function getPermission($module)
    {
        global $connect;
        static $cache = [];

        if (!isset($cache[$module])) {
            $role_id = (int)($_SESSION['role_id'] ?? 0);
            $mod     = escapeString($module);
            $rs = mysqli_query($connect, "SELECT can_view, can_create,
                    can_approve, hide_price FROM role_permissions
                    WHERE role_id = $role_id AND module_name = '$mod' LIMIT 1");
            $row = $rs ? mysqli_fetch_assoc($rs) : null;
            $cache[$module] = $row ?: ['can_view' => 0, 'can_create' => 0,
                                       'can_approve' => 0, 'hide_price' => 1];
        }
        return $cache[$module];
    }
}

if (!function_exists('canAccess')) {
    // $action: view | create | approve
    function canAccess($module, $action = 'view')
    {
        $perm = getPermission($module);
        return (int)($perm['can_' . $action] ?? 0) === 1;
    }

    function canSeePrice($module = 'catalog')
    {
        return (int)getPermission($module)['hide_price'] === 0;
    }

    function requirePermission($module, $action = 'view')
    {
        requireLogin();
        if (!canAccess($module, $action)) {
            http_response_code(403);
            die('Akses ditolak untuk peran Anda.');
        }
    }
}

// =====================================================================
// 8. SISTEM CORE LOG ACTIVITY (AUDIT TRAIL RANTAI HASH SHA-256)
// =====================================================================
if (!function_exists('auditHash')) {
    function auditHash($prev, $r)
    {
        return hash('sha256', implode('|', [
            $prev, (string)$r['user_id'], $r['event_action'],
            $r['entity_table'], (string)$r['entity_id'],
            $r['payload_json'], $r['ip_address'], $r['created_at']
        ]));
    }
}

if (!function_exists('logActivity')) {
    function logActivity($action, $module, $description = '', $entity_id = '')
    {
        global $connect;
        if (!$connect) return false;

        // Ambil data user dari session login
        $user_id  = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $username = !empty($_SESSION['username'])
                  ? trim($_SESSION['username']) : 'system';
        $role     = !empty($_SESSION['role_code'])
                  ? trim($_SESSION['role_code']) : 'system';

        // IP memakai REMOTE_ADDR saja agar tidak bisa dipalsukan via header
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $user_agent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);

        $row = [
            'user_id'      => $user_id > 0 ? $user_id : null,
            'event_action' => strtoupper(trim($action)),
            'entity_table' => ucwords(trim($module)),
            'entity_id'    => (string)$entity_id,
            'payload_json' => json_encode([
                'description' => trim($description),
                'username'    => $username,
                'role'        => $role,
                'user_agent'  => $user_agent,
            ], JSON_UNESCAPED_UNICODE),
            'ip_address'   => $ip_address,
            'created_at'   => date('Y-m-d H:i:s'),
        ];

        // Kunci agar dua request bersamaan tidak memutus rantai hash
        mysqli_query($connect, "SELECT GET_LOCK('wms_audit_chain', 5)");
        $rs   = mysqli_query($connect, "SELECT current_hash FROM audit_logs
                                        ORDER BY id DESC LIMIT 1");
        $last = $rs ? mysqli_fetch_assoc($rs) : null;
        $prev = $last['current_hash'] ?? str_repeat('0', 64);
        $hash = auditHash($prev, $row);

        $uid = ($row['user_id'] !== null) ? (int)$row['user_id'] : 'NULL';
        $sqlLog = "INSERT INTO audit_logs (user_id, event_action, entity_table,
                   entity_id, payload_json, ip_address, prev_hash,
                   current_hash, created_at) VALUES ($uid,
                   '" . escapeString($row['event_action']) . "',
                   '" . escapeString($row['entity_table']) . "',
                   '" . escapeString($row['entity_id']) . "',
                   '" . escapeString($row['payload_json']) . "',
                   '" . escapeString($row['ip_address']) . "',
                   '$prev', '$hash', '" . $row['created_at'] . "')";

        $ok = mysqli_query($connect, $sqlLog);
        mysqli_query($connect, "SELECT RELEASE_LOCK('wms_audit_chain')");
        return $ok;
    }
}

// Forensik: kembalikan null jika rantai utuh, atau ID baris pertama yang rusak
if (!function_exists('auditVerifyChain')) {
    function auditVerifyChain()
    {
        global $connect;
        $prev = str_repeat('0', 64);
        $rs = mysqli_query($connect, "SELECT * FROM audit_logs ORDER BY id ASC");
        while ($r = mysqli_fetch_assoc($rs)) {
            $valid = $r['prev_hash'] === $prev
                  && $r['current_hash'] === auditHash($prev, $r);
            if (!$valid) {
                return (int)$r['id'];
            }
            $prev = $r['current_hash'];
        }
        return null;
    }
}

// =====================================================================
// 9. INTERCEPTOR REAL QUERY (PARSING QUERY SQL MUTASI)
// =====================================================================
/**
 * Dipanggil manual oleh modul setelah query mutasi, contoh:
 * auditInspectQuery($sql, mysqli_insert_id($connect));
 */
if (!function_exists('auditInspectQuery')) {
    function auditInspectQuery($sql, $affected_id = 0)
    {
        if (empty($sql)) return;

        // Abaikan query internal log dan jurnal buku besar otomatis
        if (stripos($sql, 'audit_logs') !== false ||
            stripos($sql, 'inventory_ledger_entries') !== false) {
            return;
        }

        $firstWord = strtoupper(strtok(ltrim($sql), " \t\n\r"));
        if (!in_array($firstWord, ['INSERT', 'UPDATE', 'DELETE'])) {
            return;
        }

        // Ekstraksi nama tabel modul via regex (item_batches -> Item Batches)
        $module = 'System';
        $re = '/(?:FROM|INTO|UPDATE)\s+`?([a-zA-Z0-9_]+)`?/i';
        if (preg_match($re, $sql, $m)) {
            $module = ucwords(str_replace('_', ' ', strtolower($m[1])));
        }

        // Ekstraksi ID dari query jika ada
        $id = (int)$affected_id;
        $re = '/WHERE.*?\bid\s*=\s*[\'"]?([0-9]+)/i';
        if ($id === 0 && preg_match($re, $sql, $m)) {
            $id = (int)$m[1];
        }
        $idStr = $id > 0 ? " ID: #$id" : '';

        $map = [
            'INSERT' => ['CREATE', "Menambahkan data baru pada modul $module$idStr"],
            'UPDATE' => ['UPDATE', "Memperbarui data pada modul $module$idStr"],
            'DELETE' => ['DELETE', "Menghapus data pada modul $module$idStr"],
        ];
        [$act, $desc] = $map[$firstWord];
        logActivity($act, $module, $desc, $id ?: '');
    }
}

// =====================================================================
// 11. EKSPOR EXCEL (.xlsx asli; cadangan CSV bila ekstensi PHP zip tidak aktif)
// =====================================================================
// Nama fungsi tetap csvOut agar semua halaman yang memanggilnya otomatis ikut. File .xlsx memuat
// lebar kolom otomatis, judul tebal, baris judul beku, angka sebagai angka, dan teks (tanggal, lot) sebagai teks,
// sehingga tidak ada "#####" dan peringatan PHP tidak mungkin tercampur ke isi file.
if (!function_exists('csvOut')) {
    function wmsXlsxCol($n)
    {
        $s = '';
        $n++;
        while ($n > 0) { $m = ($n - 1) % 26; $s = chr(65 + $m) . $s; $n = intdiv($n - 1, 26); }
        return $s;
    }

    function wmsXlsxBuild(array $header, array $rows)
    {
        $clean = function ($v) {
            $v = (string)$v;
            return preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $v) ?? '';
        };
        $all = array_merge([array_values($header)], array_map('array_values', $rows));
        $ncol = 0;
        foreach ($all as $r) $ncol = max($ncol, count($r));
        $w = array_fill(0, max(1, $ncol), 8);
        foreach ($all as $r) foreach ($r as $i => $v) $w[$i] = max($w[$i], min(60, mb_strlen($clean($v)) + 2));

        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
        for ($i = 0; $i < $ncol; $i++) $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w[$i] . '" customWidth="1"/>';
        $x .= '</cols><sheetData>';
        foreach ($all as $ri => $r) {
            $x .= '<row r="' . ($ri + 1) . '">';
            foreach ($r as $ci => $v) {
                $v = $clean($v);
                $ref = wmsXlsxCol($ci) . ($ri + 1);
                $st = $ri === 0 ? ' s="1"' : '';
                if ($ri > 0 && strlen($v) <= 15 && preg_match('/^-?(0|[1-9][0-9]{0,14})(\.[0-9]+)?$/', $v)) {
                    $x .= '<c r="' . $ref . '"' . $st . '><v>' . $v . '</v></c>';
                } elseif ($v === '') {
                    $x .= '<c r="' . $ref . '"' . $st . '/>';
                } else {
                    $x .= '<c r="' . $ref . '"' . $st . ' t="inlineStr"><is><t xml:space="preserve">' . htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData></worksheet>';
        return [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Laporan" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFEFE6D8"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            'xl/worksheets/sheet1.xml' => $x,
        ];
    }

    function csvOut($filename, array $header, array $rows)
    {
        ini_set('display_errors', '0');   // peringatan PHP tidak boleh masuk ke isi file unduhan
        while (ob_get_level() > 0) ob_end_clean();

        if (class_exists('ZipArchive')) {
            $tmp = tempnam(sys_get_temp_dir(), 'wms');
            $zip = new ZipArchive();
            if ($tmp !== false && $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                foreach (wmsXlsxBuild($header, $rows) as $name => $data) $zip->addFromString($name, $data);
                if ($zip->close()) {
                    $name = preg_replace('/\.csv$/i', '.xlsx', $filename);
                    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                    header('Content-Disposition: attachment; filename="' . $name . '"');
                    header('Content-Length: ' . filesize($tmp));
                    readfile($tmp);
                    @unlink($tmp);
                    exit;
                }
            }
            if ($tmp !== false) @unlink($tmp);
        }

        // Cadangan: CSV (pemisah titik koma, UTF-8 BOM), ditulis manual tanpa fputcsv
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $cell = function ($v) {
            $v = (string)$v;
            // cegah formula injection di Excel
            if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false && !is_numeric($v)) $v = "'" . $v;
            return (strpbrk($v, ";\"\r\n") !== false) ? '"' . str_replace('"', '""', $v) . '"' : $v;
        };
        echo "\xEF\xBB\xBF" . implode(';', array_map($cell, $header)) . "\r\n";
        foreach ($rows as $r) echo implode(';', array_map($cell, $r)) . "\r\n";
        exit;
    }
}

// =====================================================================
// 10. GLOBAL SHUTDOWN & REQUEST MONITOR (MEREKAM SETIAP FORM ACTION)
// =====================================================================
// Menangkap request ke manage_*.php dan mendeteksi aksi CRUD di akhir proses
register_shutdown_function(function () {
    global $connect;

    // Hanya jalankan jika user sedang login
    if (empty($_SESSION['user_id'])) {
        return;
    }

    // Modul transaksi (inbound/outbound/transfer) mencatat audit sendiri
    if (defined('WMS_SKIP_AUTOLOG')) {
        return;
    }

    // Nama file aktif (contoh: manage_item.php -> Item)
    $script = basename($_SERVER['SCRIPT_NAME'], '.php');
    if (strpos($script, 'manage_') !== 0) {
        return;
    }
    $module = ucwords(str_replace('_', ' ', substr($script, 7)));

    // Modul audit, ledger (read-only) tidak perlu dicatat
    $skip = ['audit log', 'ledger', 'stock balance'];
    if (in_array(strtolower($module), $skip)) {
        return;
    }

    // A. Deteksi DELETE via GET URL (misal: ?act=delete&id=5)
    $act = strtolower($_GET['act'] ?? '');
    if (in_array($act, ['delete', 'del', 'hapus'])) {
        $delId = (int)($_GET['id'] ?? 0);
        $info  = $delId > 0 ? " ID: #$delId" : '';
        logActivity('DELETE', $module,
            "Menghapus data pada modul $module$info", $delId ?: '');
        return;
    }

    // B. Deteksi POST (CREATE atau UPDATE)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $idPost = !empty($_POST['id']) ? (int)$_POST['id'] : 0;
        $label  = $_POST['name'] ?? $_POST['item_name'] ?? $_POST['sku'] ?? '';
        $label  = $label !== '' ? ' "' . e($label) . '"' : '';

        $isDelete = isset($_POST['delete']) || (($_POST['act'] ?? '') === 'delete');
        if ($isDelete) {
            logActivity('DELETE', $module,
                "Menghapus data pada modul $module ID: #$idPost$label", $idPost ?: '');
        } elseif ($idPost > 0) {
            logActivity('UPDATE', $module,
                "Memperbarui data pada modul $module ID: #$idPost$label", $idPost);
        } else {
            $newId = mysqli_insert_id($connect);
            $info  = $newId > 0 ? " ID: #$newId" : '';
            logActivity('CREATE', $module,
                "Menambahkan data baru pada modul $module$info$label", $newId ?: '');
        }
    }
});
