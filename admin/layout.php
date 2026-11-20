<?php
/**
 * Layout bersama: header, navigasi (otomatis sesuai hak akses), footer.
 * Menu hanya muncul jika file modul ada dan peran punya can_view.
 */
if (!function_exists('renderHeader')) {
    function renderHeader($title)
    {
        require_once __DIR__ . '/reservation_helper.php';
        wmsAutoMaintenance();
        $nav = [
            ['index.php',            'Dashboard',        null,        'Utama'],
            ['manage_unit.php',      'Satuan',           'master',    'Data Master'],
            ['manage_category.php',  'Kategori',         'master',    'Data Master'],
            ['manage_supplier.php',  'Supplier',         'master',    'Data Master'],
            ['manage_warehouse.php', 'Gudang',           'master',    'Data Master'],
            ['manage_storage_bin.php','Rak / Bin',       'master',    'Data Master'],
            ['manage_item.php',      'Barang',           'master',    'Data Master'],
            ['manage_item_photo.php','Foto Acuan Barang','master',    'Data Master'],
            ['manage_po.php',        'Purchase Order',   'po',        'Transaksi'],
            ['manage_inbound.php',   'Barang Masuk',     'inbound',   'Transaksi'],
            ['manage_qc.php',        'Karantina QC',     'inbound',   'Transaksi'],
            ['manage_return.php',    'Retur Supplier',   'inbound',   'Transaksi'],
            ['manage_payable.php',   'Hutang Supplier',  'payable',   'Transaksi'],
            ['manage_outbound.php',  'Barang Keluar',    'outbound',  'Transaksi'],
            ['manage_reservation.php','Reservasi Stok',  'outbound',  'Transaksi'],
            ['manage_bin_transfer.php','Transfer Rak',   'outbound',  'Transaksi'],
            ['manage_production.php','Produksi',         'bom',       'Transaksi'],
            ['manage_approval.php',  'Persetujuan',      'approval',  'Transaksi'],
            ['manage_reversal.php',  'Pembatalan',       'approver',  'Transaksi'],
            ['manage_batch.php',     'Lot Batch',        'catalog',   'Stok & Laporan'],
            ['manage_stock_balance.php', 'Saldo Stok',   'catalog',   'Stok & Laporan'],
            ['manage_ledger.php',    'Buku Besar',       'catalog',   'Stok & Laporan'],
            ['manage_report.php',    'Laporan',          'catalog',   'Stok & Laporan'],
            ['manage_supplier_score.php', 'Penilaian Supplier', 'inbound', 'Stok & Laporan'],
            ['manage_bom.php',       'BoM',              'bom',       'Stok & Laporan'],
            ['manage_stock_opname.php','Stock Opname',   'opname',    'Stok & Laporan'],
            ['manage_audit_log.php', 'Audit Log',        'audit',     'Sistem'],
            ['manage_user.php',      'Pengguna',         'admin',     'Sistem'],
            ['manage_roles.php',     'Hak Akses',        'admin',     'Sistem'],
            ['manage_kesiapan.php',  'Kesiapan Produksi','admin',     'Sistem'],
            ['manage_backup.php',    'Backup & Pulihkan','admin',     'Sistem'],
        ];

        // Ikon tiap menu (garis SVG sederhana)
        $icons = [
            'index.php'                => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
            'manage_unit.php'          => '<path d="M21.3 15.3a2.4 2.4 0 010 3.4l-2.6 2.6a2.4 2.4 0 01-3.4 0L2.7 8.7a2.4 2.4 0 010-3.4l2.6-2.6a2.4 2.4 0 013.4 0z"/><path d="M14.5 12.5l2-2M11.5 9.5l2-2M8.5 6.5l2-2M17.5 15.5l2-2"/>',
            'manage_category.php'      => '<path d="M20.6 13.4l-7.2 7.2a2 2 0 01-2.8 0L2 12V2h10l8.6 8.6a2 2 0 010 2.8z"/><circle cx="7" cy="7" r="1.5"/>',
            'manage_supplier.php'      => '<path d="M1 3h15v13H1zM16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
            'manage_warehouse.php'     => '<path d="M3 21V9l9-6 9 6v12"/><path d="M9 21v-7h6v7"/>',
            'manage_storage_bin.php'   => '<rect x="3" y="3" width="18" height="5" rx="1"/><path d="M5 8v11a2 2 0 002 2h10a2 2 0 002-2V8M10 12h4"/>',
            'manage_item.php'          => '<path d="M21 8l-9-5-9 5v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5M12 13v8"/>',
            'manage_item_photo.php'    => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
            'manage_po.php'            => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/>',
            'manage_inbound.php'       => '<path d="M12 3v12M7 10l5 5 5-5M4 21h16"/>',
            'manage_qc.php'            => '<path d="M12 2l8 3v6c0 5-3.5 9.5-8 11-4.5-1.5-8-6-8-11V5z"/><path d="M9 12l2 2 4-4"/>',
            'manage_return.php'        => '<path d="M9 14l-5-5 5-5"/><path d="M4 9h11a5 5 0 010 10h-3"/>',
            'manage_outbound.php'      => '<path d="M12 21V9M7 14l5-5 5 5M4 3h16"/>',
            'manage_bin_transfer.php'  => '<path d="M17 4l4 4-4 4M21 8H7M7 20l-4-4 4-4M3 16h14"/>',
            'manage_production.php'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 00.3 1.8l.1.1a2 2 0 11-2.8 2.8l-.1-.1a1.7 1.7 0 00-1.8-.3 1.7 1.7 0 00-1 1.5V21a2 2 0 11-4 0v-.1a1.7 1.7 0 00-1.1-1.5 1.7 1.7 0 00-1.8.3l-.1.1a2 2 0 11-2.8-2.8l.1-.1a1.7 1.7 0 00.3-1.8 1.7 1.7 0 00-1.5-1H3a2 2 0 110-4h.1a1.7 1.7 0 001.5-1.1 1.7 1.7 0 00-.3-1.8l-.1-.1a2 2 0 112.8-2.8l.1.1a1.7 1.7 0 001.8.3H9a1.7 1.7 0 001-1.5V3a2 2 0 114 0v.1a1.7 1.7 0 001 1.5 1.7 1.7 0 001.8-.3l.1-.1a2 2 0 112.8 2.8l-.1.1a1.7 1.7 0 00-.3 1.8V9a1.7 1.7 0 001.5 1H21a2 2 0 110 4h-.1a1.7 1.7 0 00-1.5 1z"/>',
            'manage_approval.php'      => '<circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>',
            'manage_reversal.php'      => '<path d="M3 7v6h6"/><path d="M21 17a9 9 0 00-15-6.7L3 13"/>',
            'manage_batch.php'         => '<path d="M12 2l10 5-10 5L2 7z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/>',
            'manage_stock_balance.php' => '<path d="M3 3v18h18"/><path d="M8 17v-6M13 17V7M18 17v-4"/>',
            'manage_ledger.php'        => '<path d="M4 19.5A2.5 2.5 0 016.5 17H20V2H6.5A2.5 2.5 0 004 4.5z"/><path d="M4 19.5A2.5 2.5 0 006.5 22H20v-5"/>',
            'manage_report.php'        => '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6M8 18v-3M12 18v-6M16 18v-4"/>',
            'manage_supplier_score.php'=> '<path d="M12 2l3 7 7 .6-5.3 4.7L18.2 21 12 17.3 5.8 21l1.5-6.7L2 9.6 9 9z"/>',
            'manage_bom.php'           => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2M9 12h6M9 16h6"/>',
            'manage_stock_opname.php'  => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><path d="M9 14l2 2 4-4"/>',
            'manage_audit_log.php'     => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'manage_user.php'          => '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.9M16 3.1a4 4 0 010 7.8"/>',
            'manage_roles.php'         => '<circle cx="8" cy="15" r="4"/><path d="M10.8 12.2L21 2M16 7l3 3"/>',
            'manage_kesiapan.php'      => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/>',
            'manage_backup.php'        => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v6c0 1.7 4 3 9 3s9-1.3 9-3V5M3 11v6c0 1.7 4 3 9 3s9-1.3 9-3v-6"/>',
            'manage_payable.php'       => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
            'manage_reservation.php'   => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 018 0v4"/>',
            '_'                        => '<circle cx="12" cy="12" r="8"/>',
        ];
        $current = basename($_SERVER['SCRIPT_NAME']);

        // Data untuk kotak pengguna (header dan sidebar)
        $uName = (string)($_SESSION['full_name'] ?? ($_SESSION['username'] ?? ''));
        $uRoleRaw = (string)($_SESSION['role_code'] ?? '');
        $uRole = strtoupper(str_replace('_', ' ', $uRoleRaw));
        $uRoleNice = ucwords(strtolower(str_replace('_', ' ', $uRoleRaw)));
        $uInit = strtoupper(function_exists('mb_substr') ? mb_substr($uName, 0, 1) : substr($uName, 0, 1));

        // Foto profil (kolom users.photo_path). Bila belum ada / gagal dibaca, pakai huruf awal.
        $uPhoto = null;
        global $connect;
        if (!empty($_SESSION['user_id']) && isset($connect)) {
            try {
                $psPhoto = mysqli_prepare($connect, "SELECT photo_path FROM users WHERE id = ?");
                $pidPhoto = (int)$_SESSION['user_id'];
                mysqli_stmt_bind_param($psPhoto, 'i', $pidPhoto);
                mysqli_stmt_execute($psPhoto);
                $rowPhoto = mysqli_fetch_assoc(mysqli_stmt_get_result($psPhoto));
                $candPhoto = $rowPhoto['photo_path'] ?? null;
                if ($candPhoto && strpos($candPhoto, '..') === false && is_file(__DIR__ . '/' . $candPhoto)) {
                    $uPhoto = $candPhoto . '?v=' . (int)@filemtime(__DIR__ . '/' . $candPhoto);
                }
            } catch (Throwable $exPhoto) {
                $uPhoto = null;
            }
        }
        $notifData = ['now' => time(), 'items' => []];
        try {
            require_once __DIR__ . '/notif_helper.php';
            $notifData = wmsNotifications();
        } catch (Throwable $exNotif) {
            $notifData = ['now' => time(), 'items' => []];
        }
        $avHtml = $uPhoto
            ? '<img src="' . e($uPhoto) . '" alt="' . e($uName) . '">'
            : e($uInit);
        ?>
<!DOCTYPE html>
<html lang="id"<?= (($_COOKIE['wmsTheme'] ?? '') === 'dark') ? ' data-theme="dark"' : '' ?>>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0F172A">
<title><?= e($title) ?> - WMS Dekor Asia Jayakarya</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<script>(function(){try{var m=document.cookie.match(/(?:^|; )wmsTheme=(\w+)/),c=m?m[1]:null,l=null;try{l=localStorage.getItem('wmsTheme')}catch(e){}var t=l||c;if(!t&&window.matchMedia&&matchMedia('(prefers-color-scheme: dark)').matches)t='dark';if(t==='dark')document.documentElement.setAttribute('data-theme','dark');else document.documentElement.removeAttribute('data-theme')}catch(e){}})();</script>
<style>
  /* Gaya terinspirasi template CardioCare (ThemeWagon): Outfit + Inter, aksen merah, biru-abu gelap, tombol bergradien */
  :root{--pri:#DC2626;--pri-d:#B91C1C;--pri-l:#FCA5A5;--acc:#0EA5E9;--ink:#0F172A;--ink2:#334155;--teal:#1E293B;--bg:#F8FAFC;--line:#E2E8F0;--link:#B91C1C}
  *{box-sizing:border-box}
  body{font-family:Inter,"Segoe UI",system-ui,Arial,sans-serif;margin:0;background:var(--bg);color:var(--ink2);font-size:15px;line-height:1.45}
  h1,h2,h3{font-family:Outfit,Inter,"Segoe UI",Arial,sans-serif;font-weight:600;color:var(--ink)}
  :root{--sbw:248px}
  aside.side{position:fixed;top:0;left:0;bottom:0;width:var(--sbw);min-width:var(--sbw);background:linear-gradient(180deg,#0F172A 0%,#1E293B 100%);color:#fff;overflow:hidden;z-index:40;display:flex;flex-direction:column;border-right:1px solid rgba(255,255,255,.06);transition:transform .2s,width .2s}

  /* Judul dua baris (logo bulat hanya tampil saat sidebar ciut) */
  aside.side .brand{display:flex;align-items:center;justify-content:center;padding:20px 20px 16px;text-decoration:none;border-bottom:1px solid rgba(255,255,255,.1)}
  aside.side .brand .blogo{display:none;width:48px;height:48px;border-radius:50%;border:0;background:linear-gradient(135deg,#DC2626,#991B1B);color:#fff;box-shadow:0 4px 14px rgba(220,38,38,.35);font:700 14px Outfit,sans-serif;letter-spacing:.5px;align-items:center;justify-content:center}
  aside.side .brand .btxt{flex:1;min-width:0}
  aside.side .brand b{display:block;font-family:Outfit,Inter,Arial,sans-serif;font-weight:600;font-size:16px;line-height:1.25;letter-spacing:.5px;text-transform:uppercase;color:#fff}
  aside.side .brand small{display:block;margin-top:3px;font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--pri-l)}

  aside.side nav{position:relative;flex:1 1 auto;min-height:0;overflow-y:auto;overflow-x:hidden;overscroll-behavior:contain;padding:6px 0 12px;scrollbar-width:thin;scrollbar-color:rgba(255,255,255,.28) transparent}
  aside.side nav::-webkit-scrollbar{width:6px}
  aside.side nav::-webkit-scrollbar-thumb{background:rgba(255,255,255,.28);border-radius:6px}
  aside.side .brand{flex:none}
  aside.side .grp{padding:12px 20px 4px;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:rgba(255,255,255,.45)}
  aside.side nav a{display:flex;align-items:center;gap:12px;padding:8px 20px;font-size:14px;font-weight:500;color:rgba(255,255,255,.82);text-decoration:none;border-left:4px solid transparent}
  aside.side nav a .ic{width:20px;height:20px;flex:none;display:flex}
  aside.side nav a .ic svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round}
  aside.side nav a:hover{background:rgba(255,255,255,.07);color:#fff}
  aside.side nav a.on{background:rgba(220,38,38,.18);border-left-color:var(--pri);color:#fff;font-weight:600}

  /* Kartu pengguna di sidebar */
  aside.side .user{flex:none;margin-top:0;padding:12px 14px;border-top:1px solid rgba(255,255,255,.1)}
  aside.side .user a.ucard{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:14px;background:rgba(255,255,255,.06);text-decoration:none;transition:background .15s}
  aside.side .user a.ucard:hover{background:rgba(255,255,255,.12)}
  .ucard .av{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#DC2626,#991B1B);color:#fff;font:700 16px Outfit,sans-serif;display:flex;align-items:center;justify-content:center;flex:none;overflow:hidden}
  .ucard .uinfo{display:flex;flex-direction:column;line-height:1.25;min-width:0}
  .ucard .uinfo b{font-family:Outfit,Inter,Arial,sans-serif;font-size:14px;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .ucard .uinfo em{font-style:normal;font-size:12.5px;font-weight:600;color:var(--pri-l)}
  aside.side .user button.lo{margin-top:10px;width:100%;display:flex;align-items:center;justify-content:center;gap:8px;background:transparent;color:#fff;border:1px solid rgba(255,255,255,.4);padding:7px 16px;border-radius:10px;font-size:12px;font-weight:500;text-transform:none;letter-spacing:0;cursor:pointer}
  aside.side .user button.lo svg{width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
  aside.side .user button.lo:hover{background:var(--pri);color:#fff;border-color:var(--pri)}

  header.top{display:flex;align-items:center;justify-content:flex-end;margin-left:var(--sbw);background:#fff;color:var(--ink);padding:10px 24px;border-bottom:1px solid var(--line);box-shadow:0 2px 6px rgba(0,0,0,.04);position:sticky;top:0;z-index:30;transition:margin-left .2s}
  header.top .who{font-size:14px;display:flex;align-items:center;gap:12px}
  header.top .mbrand,header.top button.burger{display:none}

  /* Kotak pengguna di header */
  header.top .who a.usr{display:flex;align-items:center;gap:10px;padding:4px 16px 4px 4px;border:1px solid var(--line);border-radius:10px;background:#FEF2F2;text-decoration:none;color:var(--ink);transition:.15s}
  header.top .who a.usr:hover{border-color:var(--pri);background:#FEE2E2}
  .usr .av{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#DC2626,#991B1B);color:#fff;font:700 15px Outfit,sans-serif;display:flex;align-items:center;justify-content:center;flex:none;overflow:hidden}
  .usr .uinfo{display:flex;flex-direction:column;line-height:1.2}
  .usr .uinfo b{font-size:14px;font-weight:700;color:var(--ink)}
  .usr .uinfo em{font-style:normal;font-size:10.5px;font-weight:700;letter-spacing:1px;color:var(--link)}
  /* Foto profil mengisi lingkaran avatar (header & sidebar) */
  .av img{width:100%;height:100%;object-fit:cover;display:block;border-radius:50%}

  /* Tombol keluar berikon di header */
  .btn-out{width:40px;height:40px;padding:0;border-radius:12px;background:var(--ink);border:1px solid rgba(255,255,255,.7);display:inline-flex;align-items:center;justify-content:center;cursor:pointer}
  .btn-out svg{width:18px;height:18px;color:#fb7185}
  .btn-out:hover{background:#B3261E}
  .btn-out:hover svg{color:#fff}
  /* Lonceng notifikasi */
  .ntf{position:relative}
  .ntf-btn{width:40px;height:40px;padding:0;border-radius:12px;background:#fff;border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;position:relative;color:var(--ink)}
  .ntf-btn:hover{border-color:var(--pri);background:#FEE2E2}
  .ntf-btn svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  .ntf-badge{position:absolute;top:-6px;right:-6px;min-width:20px;height:20px;padding:0 5px;border-radius:10px;background:#B3261E;color:#fff;font:700 11px Inter,Arial,sans-serif;display:none;align-items:center;justify-content:center;border:2px solid #fff}
  .ntf-panel{display:none;position:absolute;right:0;top:50px;width:360px;max-width:calc(100vw - 24px);max-height:70vh;overflow-y:auto;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 10px 30px rgba(0,0,0,.18);z-index:60}
  .ntf.open .ntf-panel{display:block}
  .ntf-head{padding:12px 16px;font:600 14px Outfit,Inter,Arial,sans-serif;color:var(--ink);border-bottom:1px solid var(--line);position:sticky;top:0;background:#fff}
  .ntf-item{display:block;padding:11px 16px 11px 14px;border-left:4px solid var(--line);border-bottom:1px solid var(--line);text-decoration:none;color:var(--ink2)}
  .ntf-item:hover{background:#FEF2F2}
  .ntf-item b{display:block;font-size:13.5px;color:var(--ink)}
  .ntf-item span{display:block;font-size:12.5px;color:#555;margin-top:2px;word-break:break-word}
  .ntf-item.new{background:#FEF2F2}
  .ntf-row{position:relative}
  .ntf-row .ntf-item{padding-right:40px}
  .ntf-x{position:absolute;top:8px;right:8px;width:24px;height:24px;border:0;border-radius:50%;background:transparent;color:#888;font:400 18px/1 Inter,Arial,sans-serif;cursor:pointer;padding:0}
  .ntf-x:hover{background:#B3261E;color:#fff}
  .ntf-head{display:flex;align-items:center;justify-content:space-between}
  .ntf-clear{border:0;background:transparent;color:var(--link);font:500 12.5px Inter,Arial,sans-serif;cursor:pointer;text-decoration:underline;padding:0}
  .ntf-item.t-approval{border-left-color:#B91C1C}.ntf-item.t-po{border-left-color:#1E293B}.ntf-item.t-qc{border-left-color:#8a5a00}
  .ntf-item.t-in{border-left-color:#2e7d32}.ntf-item.t-out{border-left-color:#1565c0}.ntf-item.t-stock,.ntf-item.t-expiry,.ntf-item.t-no{border-left-color:#B3261E}.ntf-item.t-ok{border-left-color:#2e7d32}.ntf-item.t-pay,.ntf-item.t-bak{border-left-color:#B3261E}.ntf-item.t-rsv{border-left-color:#8a5a00}
  .ntf-empty{padding:22px 16px;text-align:center;color:#777;font-size:13.5px}
  .backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:35}

  /* ====== Mode ciut (rel ikon), hanya di layar lebar ====== */
  @media(min-width:901px){
    body.sb-collapsed{--sbw:80px}
    body.sb-collapsed aside.side{border-radius:0}
    body.sb-collapsed aside.side nav{scrollbar-width:none}
    body.sb-collapsed aside.side nav::-webkit-scrollbar{display:none}
    body.sb-collapsed aside.side .brand{padding:18px 0 14px}
    body.sb-collapsed aside.side .brand .blogo{display:flex;flex:none;margin:0 auto}
    body.sb-collapsed aside.side .brand .btxt{display:none}
    body.sb-collapsed aside.side .grp{font-size:0;padding:0;height:0;margin:8px 22px;border-top:1px solid rgba(255,255,255,.12)}
    body.sb-collapsed aside.side nav{padding:6px 0 16px}
    body.sb-collapsed aside.side nav a{justify-content:center;width:50px;height:50px;margin:6px auto;padding:0;border-left:0;border-radius:16px;gap:0}
    body.sb-collapsed aside.side nav a .lbl{display:none}
    body.sb-collapsed aside.side nav a.on{background:linear-gradient(135deg,#DC2626,#991B1B);color:#fff;box-shadow:0 6px 18px rgba(220,38,38,.35)}
    body.sb-collapsed aside.side .user{padding:12px 0;display:flex;flex-direction:column;align-items:center;gap:10px}
    body.sb-collapsed aside.side .user a.ucard{padding:4px;border-radius:50%;background:none}
    body.sb-collapsed aside.side .user .uinfo{display:none}
    body.sb-collapsed aside.side .user form{margin:0}
    body.sb-collapsed aside.side .user button.lo{width:46px;height:46px;margin:0;padding:0;border-radius:14px}
    body.sb-collapsed aside.side .user button.lo .lbl{display:none}
  }

  @media(max-width:900px){
    aside.side{width:248px;transform:translateX(-100%)}
    body.sb-open aside.side{transform:none}
    body.sb-open .backdrop{display:block}
    button.sb-toggle{display:none!important}
    header.top{margin-left:0;background:var(--ink);color:#fff;padding:10px 14px;border-bottom:3px solid var(--pri)}
    header.top .who{margin-left:auto;font-size:12px}
    header.top .who a.usr{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.25);padding-right:12px}
    .usr .uinfo b{color:#fff;font-size:13px}
    .usr .uinfo em{color:var(--pri-l);font-size:9.5px}
    .usr .av{width:32px;height:32px;font-size:13px}
    header.top .mbrand{display:inline;font-family:Outfit,Inter,Arial,sans-serif;font-weight:600;letter-spacing:.6px;text-transform:uppercase;font-size:13px;color:#fff}
    header.top .mbrand span{font-weight:400;color:var(--pri-l)}
    header.top button.burger{display:inline-block;background:none;border:1px solid rgba(255,255,255,.5);color:#fff;font-size:20px;line-height:1;padding:4px 10px;border-radius:6px;text-transform:none;margin-right:12px;letter-spacing:0}
    main{margin-left:0!important;padding-left:14px;padding-right:14px}
  }
  @media(max-width:480px){.usr .uinfo{display:none}header.top .who a.usr{padding:4px}}
  /* Konten diletakkan di tengah area di kanan sidebar (maks. 1180px), jadi tidak ada ruang kosong sepihak saat sidebar ciut/lebar */
  main{max-width:none;margin:0 0 0 var(--sbw);padding:26px max(24px,calc((100vw - var(--sbw) - 1180px)/2));transition:margin-left .2s,padding .2s}
  @media(max-width:900px){main{margin-left:0;padding:20px 14px}}
  main a{color:var(--link)}
  h2{margin:0 0 20px;font-size:22px;position:relative;padding-bottom:12px}
  h2:after{content:"";position:absolute;left:0;bottom:0;width:48px;height:3px;background:linear-gradient(90deg,#DC2626,#0EA5E9);border-radius:2px}
  .panel{background:#fff;border-radius:14px;padding:18px 20px;box-shadow:0 2px 14px rgba(15,23,42,.07);border:1px solid #E2E8F0;margin-bottom:20px}
  .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px}
  label{display:block;font-size:13px;margin-bottom:4px;color:#555;font-weight:500}
  input[type=text],input[type=number],input[type=password],input[type=date],select,textarea{width:100%;padding:9px 10px;border:1px solid #cfd3d6;border-radius:6px;font-size:14px;font-family:inherit;background:#fff}
  input:focus,select:focus,textarea:focus{outline:2px solid var(--pri);outline-offset:0;border-color:var(--pri-d)}
  .chk{display:flex;align-items:center;gap:8px;padding-top:0;align-self:end;min-height:38px}
  button,.btn{padding:9px 20px;border:0;border-radius:8px;background:linear-gradient(135deg,#DC2626,#991B1B);color:#fff;font-family:Outfit,Inter,"Segoe UI",Arial,sans-serif;font-size:13.5px;font-weight:600;letter-spacing:.2px;text-transform:none;cursor:pointer;text-decoration:none;display:inline-block;box-shadow:0 4px 14px rgba(220,38,38,.28);transition:transform .15s,box-shadow .15s,filter .15s}
  button:hover,.btn:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(220,38,38,.4);filter:brightness(1.05)}
  .btn.sec,button.sec{background:var(--teal);color:#fff;box-shadow:none} .btn.sec:hover,button.sec:hover{background:var(--ink);box-shadow:none}
  .btn.danger,button.danger{background:#fff;color:#B91C1C;border:1px solid #B91C1C;box-shadow:none} .btn.danger:hover,button.danger:hover{background:#B91C1C;color:#fff;box-shadow:none;transform:none}
  /* tombol keluar di header harus menimpa gaya button umum di atas */
  button.btn-out{width:40px;height:40px;padding:0;border-radius:12px;background:var(--ink);display:inline-flex}
  button.btn-out:hover{background:#B3261E}

  /* Tombol bulat untuk menciutkan / melebarkan sidebar */
  button.sb-toggle{position:fixed;top:78px;left:calc(var(--sbw) - 14px);width:30px;height:30px;padding:0;border-radius:50%;background:var(--ink);border:1px solid rgba(255,255,255,.75);color:var(--pri-l);display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:50;box-shadow:0 2px 8px rgba(0,0,0,.35);transition:left .2s,background .15s}
  button.sb-toggle:hover{background:var(--pri);color:#fff}
  button.sb-toggle svg{width:16px;height:16px;transition:transform .2s}
  body.sb-collapsed button.sb-toggle svg{transform:rotate(180deg)}

  table{width:100%;border-collapse:collapse}
  th,td{padding:10px 10px;text-align:left;border-bottom:1px solid #EEF0F1;font-size:14px;vertical-align:middle}
  th{background:#F8FAFC;color:var(--ink);font-weight:700;font-size:12.5px;text-transform:uppercase;letter-spacing:.4px;border-bottom:2px solid var(--pri)}
  tr:hover td{background:#FEF2F2}
  .ok{background:#E6F4EA;color:#1E6B34;padding:10px 14px;border-radius:6px;margin-bottom:14px;font-size:14px;border-left:4px solid #1E6B34}
  .err{background:#FDECEA;color:#B3261E;padding:10px 14px;border-radius:6px;margin-bottom:14px;font-size:14px;border-left:4px solid #B3261E}
  .tag{display:inline-block;padding:2px 10px;border-radius:12px;font-size:12px;font-weight:500;background:#E6F4EA;color:#1E6B34}
  .tag.off{background:#EEE;color:#777}
  form.inline{display:inline;margin:0}
  .scroll{overflow-x:auto}

  /* ====== Perapian tampilan (v28) ====== */
  button,.btn{white-space:nowrap;line-height:1.25}
  .btn.sm,button.sm{padding:5px 14px;font-size:11.5px}
  th{white-space:nowrap}
  .nw{white-space:nowrap}
  th.num,td.num{text-align:right;white-space:nowrap}
  th.act,td.act{text-align:right;white-space:nowrap;width:1%}
  td .btn,td button,td form.inline{vertical-align:middle}
  td .btn,td button:not(.lo){padding:5px 14px;font-size:11.5px}
  td form.inline{display:inline-block;margin:0}
  td .btn+form.inline,td form.inline+form.inline,td form.inline+.btn,td .btn+.btn{margin-left:6px}
  td:last-child:has(>.btn),td:last-child:has(>button),td:last-child:has(>form.inline){white-space:nowrap}
  p>.btn+.btn,p>button+.btn,p>.btn+button,p>button+button{margin-left:8px}
  .panel>strong:first-child,.panel>h3:first-child{display:block;margin:0 0 12px;font:600 15px/1.3 Outfit,Inter,"Segoe UI",Arial,sans-serif;color:var(--ink)}
  .panel>small{display:block;margin-top:10px;font-size:12.5px;color:#6b7075}
  .panel-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:14px;padding-top:14px;border-top:1px solid var(--line)}
  .panel-foot small{font-size:12.5px;color:#6b7075;flex:1 1 220px}
  .panel table{margin:0}
  .panel>table+p,.panel>table+form{margin-top:14px}
  .grid>div{min-width:0}
  select,input[type=file]{max-width:100%}
  img{max-width:100%}
  table.fit th,table.fit td{padding:9px 6px;font-size:13px}
  table.fit th{font-size:11.5px;white-space:normal;line-height:1.25}
  .scroll{scrollbar-width:thin}
  @media(max-width:600px){.panel.scroll>table{min-width:500px}.panel-foot .btn{width:100%;text-align:center}}

  /* ====== Nyaman di HP (v33): isian 16px agar iPhone tidak zoom, sasaran sentuh min. 44px ====== */
  button,.btn,a,input,select,label{touch-action:manipulation}
  @media(max-width:900px){
    input[type=text],input[type=number],input[type=password],input[type=date],input[type=search],select,textarea{font-size:16px;min-height:44px}
    input[type=checkbox]{width:22px;height:22px;flex:none}
    input[type=file]{font-size:14px;width:100%;padding:8px 0}
    button,.btn{min-height:44px;padding:11px 22px;font-size:13px}
    td .btn,td button:not(.lo),.btn.sm,button.sm{min-height:38px;padding:8px 14px}
    .panel{padding:14px}
    h2{font-size:19px}
  }
  @media(max-width:600px){
    .grid{grid-template-columns:1fr}
    .hl-search input[type=search]{min-height:0}
  }

  /* ====== Isi header kiri (v31): sapaan + tanggal/jam, pencarian cepat ====== */
  header.top .hl{display:flex;align-items:center;gap:20px;margin-right:auto;min-width:0;flex:1 1 auto}
  .hl-ttl{display:flex;flex-direction:column;line-height:1.25;min-width:0;flex:none;max-width:340px;user-select:none;-webkit-user-select:none}
  .hl-ttl small{font-variant-numeric:tabular-nums}
  .hl-ttl b{font:600 14.5px Outfit,Inter,Arial,sans-serif;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .hl-ttl small{font-size:12px;color:#6b7075;white-space:nowrap}
  .hl-search{position:relative;flex:1 1 260px;max-width:380px;margin:0}
  .hl-search svg{position:absolute;left:13px;top:50%;width:17px;height:17px;margin-top:-8.5px;fill:none;stroke:#7d858c;stroke-width:2;stroke-linecap:round;pointer-events:none}
  .hl-search input[type=search]{width:100%;padding:9px 34px 9px 38px;border:1px solid var(--line);border-radius:10px;background:#F4F5F6;font-size:13.5px;-webkit-appearance:none;appearance:none}
  .hl-search input[type=search]::-webkit-search-cancel-button{display:none}
  .hl-search input[type=search]:focus{background:#fff;outline:2px solid var(--pri);border-color:var(--pri-d)}
  .hl-search kbd{position:absolute;right:12px;top:50%;transform:translateY(-50%);font:600 11px Inter,Arial,sans-serif;color:#7d858c;border:1px solid var(--line);border-radius:5px;padding:0 6px;line-height:18px;background:#fff;pointer-events:none}
  .hl-search input:focus+kbd{display:none}
  @media(max-width:900px){header.top .hl{display:none}}
  @media(max-width:1180px){.hl-ttl small{display:none}}
  /* ====== Mode gelap (v27): aktif bila <html data-theme="dark">; pilihan disimpan di localStorage 'wmsTheme' ====== */
  .thm-btn{width:40px;height:40px;padding:0;border-radius:12px;background:#fff;border:1px solid var(--line);display:inline-flex;align-items:center;justify-content:center;cursor:pointer;color:var(--ink)}
  .thm-btn:hover{border-color:var(--pri);background:#FEE2E2}
  .thm-btn svg{width:20px;height:20px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
  .thm-btn .i-sun{display:none}
  html[data-theme=dark]{color-scheme:dark;--bg:#0b1220;--line:#1f2a3d;--link:#f87171}
  html[data-theme=dark] body{background:var(--bg);color:#cbd5e1}
  html[data-theme=dark] h1,html[data-theme=dark] h2,html[data-theme=dark] h3,html[data-theme=dark] strong{color:#f1f5f9}
  html[data-theme=dark] header.top{background:#111827;color:#e2e8f0;border-bottom-color:var(--line);box-shadow:0 2px 6px rgba(0,0,0,.4)}
  html[data-theme=dark] .panel,html[data-theme=dark] .card{background:#111827;box-shadow:0 1px 8px rgba(0,0,0,.45)}
  html[data-theme=dark] .card b{color:#f1f5f9}
  html[data-theme=dark] .card.warn b{color:#ff8a80}
  html[data-theme=dark] label{color:#94a3b8}
  html[data-theme=dark] input[type=text],html[data-theme=dark] input[type=number],html[data-theme=dark] input[type=password],html[data-theme=dark] input[type=date],html[data-theme=dark] input[type=search],html[data-theme=dark] input[type=email],html[data-theme=dark] input[type=file],html[data-theme=dark] select,html[data-theme=dark] textarea{background:#0b1220;border-color:#334155;color:#e2e8f0}
  html[data-theme=dark] input::placeholder,html[data-theme=dark] textarea::placeholder{color:#7d858c}
  html[data-theme=dark] select option{background:#111827;color:#e2e8f0}
  html[data-theme=dark] th{background:#1b2536;color:#f1f5f9}
  html[data-theme=dark] th,html[data-theme=dark] td{border-bottom-color:#1f2a3d}
  html[data-theme=dark] tr:hover td{background:#1a2438}
  html[data-theme=dark] .ok{background:#16301f;color:#8fd9a3;border-left-color:#4caf6a}
  html[data-theme=dark] .err{background:#3a1b19;color:#ff9b93;border-left-color:#ff6b61}
  html[data-theme=dark] .tag{background:#16301f;color:#8fd9a3}
  html[data-theme=dark] .tag.off{background:#1e293b;color:#94a3b8}
  html[data-theme=dark] .bar{background:#1e293b}
  html[data-theme=dark] header.top .who a.usr{background:#1e293b;border-color:#334155}
  html[data-theme=dark] .usr .uinfo b{color:#f1f5f9}
  html[data-theme=dark] .ntf-btn,html[data-theme=dark] .thm-btn{background:#1e293b;border-color:#334155;color:#e2e8f0}
  html[data-theme=dark] .ntf-btn:hover,html[data-theme=dark] .thm-btn:hover{background:#243047;border-color:var(--pri)}
  html[data-theme=dark] .ntf-badge{border-color:#111827}
  html[data-theme=dark] .ntf-panel{background:#111827;border-color:#334155;box-shadow:0 10px 30px rgba(0,0,0,.6)}
  html[data-theme=dark] .ntf-head{background:#111827;color:#f1f5f9;border-bottom-color:#1f2a3d}
  html[data-theme=dark] .ntf-item{color:#cbd5e1;border-bottom-color:#1f2a3d}
  html[data-theme=dark] .ntf-item:hover{background:#1a2438}
  html[data-theme=dark] .ntf-item b{color:#f1f5f9}
  html[data-theme=dark] .ntf-item span{color:#94a3b8}
  html[data-theme=dark] .ntf-item.new{background:#2a1616}
  html[data-theme=dark] .ntf-empty{color:#8d959c}
  html[data-theme=dark] .ntf-x{color:#8d959c}
  html[data-theme=dark] .thm-btn .i-moon{display:none}
  html[data-theme=dark] .thm-btn .i-sun{display:block}
  /* warna inline pada halaman modul (chip berlatar sendiri tidak diubah) */
  html[data-theme=dark] [style*="color:#b3261e"]:not([style*="background"]),html[data-theme=dark] [style*="color:#B3261E"]:not([style*="background"]),html[data-theme=dark] [style*="color:#c0392b"]:not([style*="background"]){color:#ff8a80!important}
  html[data-theme=dark] [style*="color:#555"]:not([style*="background"]),html[data-theme=dark] [style*="color:#666"]:not([style*="background"]),html[data-theme=dark] [style*="color:#777"]:not([style*="background"]),html[data-theme=dark] [style*="color:#999"]:not([style*="background"]),html[data-theme=dark] [style*="color:#444"]:not([style*="background"]),html[data-theme=dark] [style*="color:#333"]:not([style*="background"]){color:#94a3b8!important}
  html[data-theme=dark] [style*="color:#B91C1C"]:not([style*="background"]),html[data-theme=dark] [style*="color:#8a5a00"]:not([style*="background"]){color:#f87171!important}
  html[data-theme=dark] [style*="color:#1e6b34"]:not([style*="background"]){color:#8fd9a3!important}
  html[data-theme=dark] [style*="color:#1a1a1a"]:not([style*="background"]),html[data-theme=dark] [style*="color:#0F172A"]:not([style*="background"]),html[data-theme=dark] [style*="color:#000"]:not([style*="background"]){color:#f1f5f9!important}
  html[data-theme=dark] hr{border-color:#1f2a3d}
  html[data-theme=dark] .hl-ttl b{color:#f1f5f9}
  html[data-theme=dark] .hl-ttl small{color:#94a3b8}
  html[data-theme=dark] .hl-search input[type=search]{background:#0b1220;border-color:#334155;color:#e2e8f0}
  html[data-theme=dark] .hl-search input[type=search]:focus{background:#111827}
  html[data-theme=dark] .hl-search kbd{background:#1e293b;border-color:#334155;color:#94a3b8}
  html[data-theme=dark] .panel>strong:first-child,html[data-theme=dark] .panel>h3:first-child{color:#f1f5f9}
  html[data-theme=dark] .panel>small,html[data-theme=dark] .panel-foot small{color:#94a3b8}
  html[data-theme=dark] .panel-foot{border-top-color:#1f2a3d}
  html[data-theme=dark] .card{color:#94a3b8}
  html[data-theme=dark] code,html[data-theme=dark] pre{background:#1b2536;color:#e2e8f0}
  html[data-theme=dark] .panel,html[data-theme=dark] .card{border-color:#1f2a3d}
  html[data-theme=dark] .btn.danger,html[data-theme=dark] button.danger{background:transparent;color:#f87171;border-color:#f87171}
  html[data-theme=dark] .btn.danger:hover,html[data-theme=dark] button.danger:hover{background:#B91C1C;color:#fff;border-color:#B91C1C}
  html[data-theme=dark] img{opacity:.92}
</style>
</head>
<body>
<script>try{if(localStorage.getItem('wmsSb')==='1')document.body.classList.add('sb-collapsed')}catch(e){}</script>
<aside class="side" id="sidebar">
  <a class="brand" href="index.php" title="WMS Dekor Asia Jayakarya">
    <span class="blogo">DAJ</span>
    <span class="btxt"><b>Dekor Asia Jayakarya</b><small>Warehouse System</small></span>
  </a>
  <nav>
<?php $lastGrp = null;
foreach ($nav as [$file, $label, $mod, $grp]):
    if (!file_exists(__DIR__ . '/' . $file)) continue;
    if ($mod === 'approver') { if (!(canAccess('inbound', 'approve') && canAccess('outbound', 'approve'))) continue; }
    elseif ($mod === 'approval') { if (!(canAccess('inbound', 'create') || canAccess('outbound', 'create') || canAccess('bom', 'create') || canAccess('master', 'create'))) continue; }
    elseif ($mod === 'po') { if (!(canAccess('inbound', 'view') || canAccess('buyer', 'view'))) continue; }
    elseif ($mod === 'admin') { if (($_SESSION['role_code'] ?? '') !== 'super_admin') continue; }
    elseif ($mod !== null && !canAccess($mod, 'view')) continue;
    if ($grp !== $lastGrp) { $lastGrp = $grp; echo '    <div class="grp">' . e($grp) . "</div>\n"; } ?>
    <a href="<?= e($file) ?>" class="<?= $file === $current ? 'on' : '' ?>" title="<?= e($label) ?>"><span class="ic"><svg viewBox="0 0 24 24"><?= $icons[$file] ?? $icons['_'] ?></svg></span><span class="lbl"><?= e($label) ?></span></a>
<?php endforeach; ?>
  </nav>
  <script>(function(){var n=document.querySelector('aside.side nav');if(!n)return;try{var y=sessionStorage.getItem('wmsNavY');if(y!==null)n.scrollTop=+y}catch(e){}})();</script>
  <div class="user">
    <a class="ucard" href="profile.php" title="Buka profil">
      <span class="av"><?= $avHtml ?></span>
      <span class="uinfo"><b><?= e($uName) ?></b><em><?= e($uRoleNice) ?></em></span>
    </a>
    <form method="post" action="logout.php" class="inline">
      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? ($_SESSION['csrf'] = bin2hex(random_bytes(16)))) ?>">
      <button type="submit" class="lo" title="Keluar">
        <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg>
        <span class="lbl">Keluar</span>
      </button>
    </form>
  </div>
</aside>
<button type="button" class="sb-toggle" id="sbCollapse" title="Ciutkan / lebarkan menu" aria-label="Ciutkan atau lebarkan menu">
  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
</button>
<div class="backdrop" id="sbBackdrop"></div>
<header class="top">
  <button type="button" class="burger" id="sbToggle" aria-label="Menu">&#9776;</button>
  <span class="mbrand">WMS Dekor Asia <span>Jayakarya</span></span>
  <div class="hl">
    <div class="hl-ttl"><b id="hlGreet">Halo, <?= e($uName) ?></b><small id="hlClock">&nbsp;</small></div>
<?php if (file_exists(__DIR__ . '/manage_stock_balance.php') && canAccess('catalog', 'view')): ?>
    <form class="hl-search" method="get" action="manage_stock_balance.php" role="search">
      <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
      <input type="search" name="q" id="hlQ" placeholder="Cari barang, lot, atau rak…" aria-label="Cari stok (SKU, nama barang, lot, rak)" autocomplete="off">
      <kbd>/</kbd>
    </form>
<?php endif; ?>
  </div>
  <span class="who">
    <button type="button" class="thm-btn" id="thmBtn" title="Ganti mode gelap / terang" aria-label="Ganti mode gelap atau terang">
      <svg class="i-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z"/></svg>
      <svg class="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
    </button>
    <div class="ntf" id="ntf">
      <button type="button" class="ntf-btn" id="ntfBtn" title="Notifikasi" aria-label="Notifikasi">
        <svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 01-3.4 0"/></svg>
        <span class="ntf-badge" id="ntfBadge">0</span>
      </button>
      <div class="ntf-panel" id="ntfPanel"><div class="ntf-head"><span>Notifikasi</span><button type="button" class="ntf-clear" id="ntfClear">Hapus semua</button></div><div id="ntfList"></div></div>
    </div>
    <a class="usr" href="profile.php" title="Buka profil">
      <span class="av"><?= $avHtml ?></span>
      <span class="uinfo"><b><?= e($uName) ?></b><em><?= e($uRole) ?></em></span>
    </a>
    <form method="post" action="logout.php" class="inline">
      <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf'] ?? ($_SESSION['csrf'] = bin2hex(random_bytes(16)))) ?>">
      <button type="submit" class="btn-out" title="Keluar" aria-label="Keluar">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5M21 12H9"/></svg>
      </button>
    </form>
  </span>
</header>
<script>
(function(){var g=document.getElementById('hlGreet'),c=document.getElementById('hlClock');if(!g||!c)return;
var nm=g.textContent.replace(/^Halo,\s*/,''),Z='Asia/Jakarta',last='',lastG='';
var fD,fT,fH;
try{fD=new Intl.DateTimeFormat('id-ID',{weekday:'long',day:'numeric',month:'long',year:'numeric',timeZone:Z});
fT=new Intl.DateTimeFormat('id-ID',{hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:false,timeZone:Z});
fH=new Intl.DateTimeFormat('en-GB',{hour:'2-digit',hour12:false,timeZone:Z});}catch(e){return;}
function tick(){try{var d=new Date(),h=parseInt(fH.format(d),10)%24,
sp=h<11?'pagi':(h<15?'siang':(h<18?'sore':'malam')),
gt='Selamat '+sp+', '+nm,
tx=fD.format(d)+' \u00b7 '+fT.format(d).replace(/\./g,':')+' WIB';
if(gt!==lastG){g.textContent=gt;lastG=gt;}
if(tx!==last){c.textContent=tx;last=tx;}}catch(e){}}
tick();setInterval(tick,1000);
document.addEventListener('visibilitychange',tick);window.addEventListener('focus',tick);window.addEventListener('pageshow',tick);
var q=document.getElementById('hlQ');
if(q)document.addEventListener('keydown',function(e){if(e.key!=='/'||e.ctrlKey||e.metaKey||e.altKey)return;var t=(e.target.tagName||'').toLowerCase();if(t==='input'||t==='textarea'||t==='select'||e.target.isContentEditable)return;e.preventDefault();q.focus();});})();
</script>
<script>
(function(){var b=document.getElementById('thmBtn');if(!b)return;
b.addEventListener('click',function(){var r=document.documentElement,d=r.getAttribute('data-theme')==='dark',v=d?'light':'dark';
if(d)r.removeAttribute('data-theme');else r.setAttribute('data-theme','dark');
document.cookie='wmsTheme='+v+';path=/;max-age=31536000;SameSite=Lax';try{localStorage.setItem('wmsTheme',v)}catch(e){}});})();
</script>
<script>
(function(){var b=document.body,t=document.getElementById('sbToggle'),k=document.getElementById('sbBackdrop'),c=document.getElementById('sbCollapse');
if(t)t.addEventListener('click',function(){b.classList.toggle('sb-open')});
if(k)k.addEventListener('click',function(){b.classList.remove('sb-open')});
if(c)c.addEventListener('click',function(){var on=b.classList.toggle('sb-collapsed');try{localStorage.setItem('wmsSb',on?'1':'0')}catch(e){}});
var n=document.querySelector('aside.side nav'),a=n&&n.querySelector('a.on');
if(n&&a){var top=a.offsetTop,bot=top+a.offsetHeight;if(top<n.scrollTop||bot>n.scrollTop+n.clientHeight)n.scrollTop=top-(n.clientHeight-a.offsetHeight)/2;}
if(n)n.addEventListener('scroll',function(){try{sessionStorage.setItem('wmsNavY',n.scrollTop)}catch(e){}});})();
</script>
<script>
(function(){
var box=document.getElementById('ntf');if(!box)return;
var btn=document.getElementById('ntfBtn'),badge=document.getElementById('ntfBadge'),list=document.getElementById('ntfList'),clr=document.getElementById('ntfClear');
var data=<?= json_encode($notifData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
var U='<?= (int)($_SESSION['user_id'] ?? 0) ?>',K_SEEN='wmsNtfSeen_'+U,K_DEL='wmsNtfDel_'+U;
function load(k){try{var v=JSON.parse(localStorage.getItem(k)||'[]');return Array.isArray(v)?v:[]}catch(e){return[]}}
function save(k,v){try{localStorage.setItem(k,JSON.stringify(v))}catch(e){}}
var seen=load(K_SEEN),del=load(K_DEL);
function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return{'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]})}
function visible(){return (data.items||[]).filter(function(it){return del.indexOf(it.key)<0})}
function unread(){return visible().filter(function(it){return seen.indexOf(it.key)<0})}
function prune(){
  var keys=(data.items||[]).map(function(it){return it.key});
  seen=seen.filter(function(k){return keys.indexOf(k)>=0});
  del=del.filter(function(k){return keys.indexOf(k)>=0});
  save(K_SEEN,seen);save(K_DEL,del);
}
function badgeUpdate(){var n=unread().length;badge.textContent=n>99?'99+':n;badge.style.display=n>0?'flex':'none'}
function render(){
  var h='';
  visible().forEach(function(it){
    var nw=seen.indexOf(it.key)<0;
    h+='<div class="ntf-row"><a class="ntf-item t-'+esc(it.type)+(nw?' new':'')+'" href="'+esc(it.url)+'"><b>'+esc(it.title)+'</b><span>'+esc(it.text)+'</span></a>'
      +'<button type="button" class="ntf-x" data-k="'+esc(it.key)+'" title="Hapus notifikasi ini" aria-label="Hapus notifikasi">&times;</button></div>';
  });
  list.innerHTML=h||'<div class="ntf-empty">Tidak ada notifikasi.</div>';
  clr.style.display=visible().length?'':'none';
}
function markAllSeen(){
  (data.items||[]).forEach(function(it){if(seen.indexOf(it.key)<0)seen.push(it.key)});
  save(K_SEEN,seen);badgeUpdate();
}
list.addEventListener('click',function(e){
  var x=e.target.closest?e.target.closest('.ntf-x'):null;if(!x)return;
  e.preventDefault();e.stopPropagation();
  var k=x.getAttribute('data-k');if(del.indexOf(k)<0)del.push(k);
  save(K_DEL,del);render();badgeUpdate();
});
clr.addEventListener('click',function(e){
  e.stopPropagation();
  (data.items||[]).forEach(function(it){if(del.indexOf(it.key)<0)del.push(it.key)});
  save(K_DEL,del);render();badgeUpdate();
});
btn.addEventListener('click',function(e){
  e.stopPropagation();
  var open=box.classList.toggle('open');
  if(open){render();markAllSeen();}   // angka hilang setelah dilihat; yang baru tetap disorot sampai ditutup
});
document.addEventListener('click',function(e){if(!box.contains(e.target))box.classList.remove('open')});
document.addEventListener('keydown',function(e){if(e.key==='Escape')box.classList.remove('open')});
function poll(){
  fetch('notif_feed.php',{credentials:'same-origin',cache:'no-store'}).then(function(r){return r.ok?r.json():null}).then(function(d){
    if(!d||!d.items)return;
    data=d;prune();
    if(box.classList.contains('open')){render();markAllSeen();}else badgeUpdate();
  }).catch(function(){});
}
prune();render();badgeUpdate();
setInterval(poll,60000);
})();
</script>
<main>
<?php if (($_SESSION['role_code'] ?? '') === 'super_admin' && function_exists('wmsKeyIsDefault') && wmsKeyIsDefault()): ?>
<div class="err">APP_KEY masih bawaan/terlalu pendek. Buat <code>admin/config.local.php</code> (lihat <code>config.local.php.example</code>) dengan kunci acak minimal 32 karakter.</div>
<?php endif; ?>
<?php if (!empty($_SESSION['weak_pw'])): ?>
<div class="err"><strong>Menu lain terkunci.</strong> Password Anda masih default/lemah (mis. 123456). Ganti dulu di form "Ganti password" pada halaman Profil; setelah tersimpan, semua menu dan dashboard terbuka.<?php if (basename($_SERVER['SCRIPT_NAME']) !== 'profile.php'): ?> <a href="profile.php">Ganti sekarang</a>.<?php endif; ?></div>
<?php endif; ?>
<?php
    }

    /** Kotak scan barcode (scanner USB meniru keyboard + Enter): mengisi pilihan barang / rak / stok asal / nomor dokumen. */
    function renderScanBox()
    {
        ?>
<div class="panel" style="padding:10px 16px">
  <strong style="font-size:13px">Scan barcode</strong>
  <small>(barang: SKU atau EAN, lot: SKU/NO.LOT, rak: kode rak, dokumen: SPK-/PO-/WO-/INV-/EXP-/SJ-/SO-)</small><br>
  <input type="text" id="scanInput" autocomplete="off" placeholder="Arahkan scanner ke sini, lalu Enter" style="max-width:380px;margin-top:6px">
  <span id="scanMsg" style="font-size:13px;margin-left:8px"></span>
</div>
<script>
(function () {
  var inp = document.getElementById('scanInput'), msg = document.getElementById('scanMsg');
  function pick(sel, raw, code, lot) {
    if (!sel) return false;
    for (var i = 0; i < sel.options.length; i++) {
      var o = sel.options[i], t = (o.text || '').toUpperCase();
      var ean = (o.getAttribute('data-ean') || '').toUpperCase();
      var hit = (ean !== '' && (ean === raw || ean === code)) ||
                t.indexOf(code + ' - ') === 0 ||
                (lot !== '' ? t.indexOf(code + ' | ' + lot + ' |') === 0 : t.indexOf(code + ' | ') === 0);
      if (hit) { sel.selectedIndex = i; sel.dispatchEvent(new Event('change')); return true; }
    }
    return false;
  }
  function q(name) { return document.querySelector('[name=' + name + ']'); }
  inp.addEventListener('keydown', function (ev) {
    if (ev.key !== 'Enter') return;
    ev.preventDefault();
    var raw = inp.value.trim().toUpperCase();
    inp.value = '';
    if (!raw) return;
    var p = raw.split('/'), code = p[0], lot = p.length > 1 ? p.slice(1).join('/') : '';
    var ok = false;
    var isDoc = /^(SPK|PO|WO|INV|EXP|SJ|SO)-/.test(raw);
    // Nomor SPK / PO (dari barcode surat SPK) di Barang Masuk: pilih baris PO terbuka yang cocok
    var poSel = q('po_line_id');
    if (poSel && /^(SPK|PO)-/.test(raw)) {
      var hit = -1, n = 0;
      for (var i = 0; i < poSel.options.length; i++) {
        if ((poSel.options[i].text || '').toUpperCase().indexOf(raw) !== -1) { if (hit < 0) hit = i; n++; }
      }
      if (hit >= 0) {
        poSel.selectedIndex = hit; poSel.dispatchEvent(new Event('change'));
        msg.textContent = 'OK: ' + raw + (n > 1 ? ' (' + n + ' barang di SPK ini; pilih baris lain di "Terima dari PO" bila perlu)' : '');
        msg.style.color = '#1e6b34';
        if (q('bin_id')) q('bin_id').focus();
      } else {
        msg.textContent = 'SPK/PO tidak ditemukan atau sudah selesai diterima: ' + raw;
        msg.style.color = '#b3261e';
      }
      return;
    }
    // Nomor dokumen lain: isi kolom referensi (No. SPK / kontainer / invoice)
    var refIn = q('ref');
    if (isDoc && refIn) {
      refIn.value = raw;
      msg.textContent = 'OK: nomor dokumen ' + raw + ' diisi ke kolom referensi';
      msg.style.color = '#1e6b34';
      var nxt = null, cands = [q('item_id'), q('src'), q('bom_id')];
      for (var c = 0; c < cands.length; c++) { if (cands[c] && cands[c].value === '') { nxt = cands[c]; break; } }
      if (nxt) nxt.focus(); else if (q('qty')) q('qty').focus();
      return;
    }
    if (pick(q('src'), raw, code, lot)) ok = true;
    else if (pick(q('item_id'), raw, code, '')) {
      ok = true;
      var b = q('batch_number');
      if (b && lot) b.value = lot;
    }
    else if (pick(q('bin_id'), raw, raw, '')) ok = true;
    else if (pick(q('dest_bin_id'), raw, raw, '')) ok = true;
    msg.textContent = ok ? 'OK: ' + raw : 'Kode tidak dikenali: ' + raw;
    msg.style.color = ok ? '#1e6b34' : '#b3261e';
    if (ok && q('qty')) q('qty').focus();
  });
  inp.focus();
})();
</script>
<?php
    }

    function renderFooter()
    {
        echo "</main>\n</body>\n</html>";
    }
}