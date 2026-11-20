<?php
/**
 * Notifikasi lonceng (kanan atas), diringkas per jenis supaya daftar tetap pendek:
 * paling banyak satu baris per jenis (maks. 7 baris), disesuaikan dengan hak akses pengguna.
 * Bila hanya ada 1 data, tautan langsung ke data itu; bila lebih, ke halaman daftarnya.
 */
/** Isi placeholder {nama} pada teks notifikasi. */
if (!function_exists('wmsFmt')) {
    function wmsFmt($text, array $params = [])
    {
        $map = [];
        foreach ($params as $k => $v) $map['{' . $k . '}'] = (string)$v;
        return $map ? strtr($text, $map) : $text;
    }
}

function wmsNotifRows($sql)
{
    global $connect;
    $rs = mysqli_query($connect, $sql);
    return $rs ? mysqli_fetch_all($rs, MYSQLI_ASSOC) : [];
}

/** "a, b dan 3 lainnya" dari daftar teks. */
function wmsNotifJoin(array $names, int $show = 2): string
{
    $names = array_values(array_unique(array_filter($names, 'strlen')));
    $head = array_slice($names, 0, $show);
    $rest = count($names) - count($head);
    return implode(', ', $head) . ($rest > 0 ? ' ' . wmsFmt('dan {n} lainnya', ['n' => $rest]) : '');
}

function wmsNotifications(): array
{
    global $connect;
    $me = (int)($_SESSION['user_id'] ?? 0);
    $out = [];
    if (!$me || !isset($connect)) return ['now' => time(), 'items' => []];
    try {
        require_once __DIR__ . '/schema_ext.php';
        wmsEnsureSchemaExt();
    } catch (Throwable $exSchema) {
        // tabel v38 gagal dibuat: notifikasi terkait dilewati
    }
    // $sig = penanda perubahan: bila berubah, notifikasi dianggap baru lagi
    $add = function (string $type, string $title, string $text, string $url, string $sig, string $kind = 'task') use (&$out) {
        $out[] = ['key' => $type . ':' . md5($sig), 'kind' => $kind, 'type' => $type, 'title' => $title, 'text' => $text, 'url' => $url, 'ts' => 0];
    };

    // 1. Persetujuan: draft yang menunggu keputusan Anda
    $approvable = [];
    foreach (['inbound', 'outbound', 'bom', 'master'] as $m) if (canAccess($m, 'approve')) $approvable[] = $m;
    if ($approvable) {
        $in = "'" . implode("','", $approvable) . "'";   // dari daftar tetap, bukan input pengguna
        $rows = wmsNotifRows("SELECT d.id, d.label, cu.full_name AS creator
            FROM trx_drafts d JOIN users cu ON cu.id = d.created_by
            WHERE d.status = 'PENDING' AND d.module_name IN ($in) ORDER BY d.id ASC LIMIT 100");
        $n = count($rows);
        if ($n === 1) {
            $r = $rows[0];
            $add('approval', wmsFmt('Menunggu persetujuan Anda'), wmsFmt('Draft #{id}: {label} (dari {creator})', ['id' => $r['id'], 'label' => $r['label'], 'creator' => $r['creator']]), 'manage_approval.php#d' . (int)$r['id'], 'ap' . $r['id']);
        } elseif ($n > 1) {
            $add('approval', wmsFmt('{n} draft menunggu persetujuan Anda', ['n' => $n]), wmsNotifJoin(array_column($rows, 'label')), 'manage_approval.php',
                'ap' . implode(',', array_column($rows, 'id')));
        }
    }

    // 2. Hasil pengajuan saya (disetujui / ditolak) 3 hari terakhir
    $rows = wmsNotifRows("SELECT d.id, d.label, d.status, d.decision_note
        FROM trx_drafts d
        WHERE d.created_by = $me AND d.status IN ('APPROVED','REJECTED') AND d.decided_at >= (NOW() - INTERVAL 3 DAY)
        ORDER BY d.decided_at DESC LIMIT 50");
    $n = count($rows);
    if ($n === 1) {
        $r = $rows[0];
        $ok = $r['status'] === 'APPROVED';
        $txt = wmsFmt('Draft #{id}: {label}', ['id' => $r['id'], 'label' => $r['label']]) . (!$ok && !empty($r['decision_note']) ? ' - ' . wmsFmt('alasan:') . ' ' . $r['decision_note'] : '');
        $add($ok ? 'ok' : 'no', $ok ? wmsFmt('Pengajuan Anda disetujui') : wmsFmt('Pengajuan Anda ditolak'), $txt, 'manage_approval.php#d' . (int)$r['id'], 'rs' . $r['id'] . $r['status'], 'event');
    } elseif ($n > 1) {
        $okN = count(array_filter($rows, function ($r) { return $r['status'] === 'APPROVED'; }));
        $add($okN === $n ? 'ok' : 'no', wmsFmt('{n} pengajuan Anda sudah diputuskan', ['n' => $n]), wmsFmt('{ok} disetujui, {no} ditolak', ['ok' => $okN, 'no' => $n - $okN]), 'manage_approval.php#d' . (int)$rows[0]['id'],
            'rs' . implode(',', array_map(function ($r) { return $r['id'] . $r['status']; }, $rows)), 'event');
    }

    if (canAccess('inbound', 'view')) {
        // 3. PO yang masih menunggu kedatangan barang
        $rows = wmsNotifRows("SELECT p.id, p.po_number, p.deliver_by, s.company_name
            FROM purchase_orders p JOIN purchase_order_lines l ON l.po_id = p.id JOIN suppliers s ON s.id = p.supplier_id
            WHERE p.status IN ('OPEN','PARTIAL') AND l.qty_received < l.qty_ordered
            GROUP BY p.id ORDER BY (p.deliver_by IS NULL), p.deliver_by ASC, p.id ASC LIMIT 100");
        $n = count($rows);
        if ($n > 0) {
            $late = array_values(array_filter($rows, function ($r) { return !empty($r['deliver_by']) && $r['deliver_by'] < date('Y-m-d'); }));
            $sig = 'po' . implode(',', array_column($rows, 'id')) . 'L' . count($late);
            if ($n === 1) {
                $r = $rows[0];
                $due = !empty($r['deliver_by']) ? ' - ' . ($late ? wmsFmt('TERLAMBAT sejak') : wmsFmt('batas kirim')) . ' ' . $r['deliver_by'] : '';
                $add('po', $late ? wmsFmt('Barang terlambat datang') : wmsFmt('Menunggu barang masuk'), wmsFmt('{po} dari {supplier}', ['po' => $r['po_number'], 'supplier' => $r['company_name']]) . $due, 'manage_po.php?id=' . (int)$r['id'], $sig);
            } else {
                $title = wmsFmt('{n} PO menunggu barang', ['n' => $n]) . ($late ? ' (' . wmsFmt('{n} terlambat', ['n' => count($late)]) . ')' : '');
                $add('po', $title, wmsNotifJoin(array_column($late ?: $rows, 'po_number')), 'manage_po.php', $sig);
            }
        }

        // 4. Lot karantina menunggu QC
        $r = wmsNotifRows("SELECT COUNT(DISTINCT b.id) AS n, COALESCE(MAX(b.id),0) AS mx FROM item_batches b
            JOIN stock_balances sb ON sb.batch_id = b.id AND sb.qty_on_hand > 0 WHERE b.qc_status = 'HOLD_QUARANTINE'");
        if ($r && (int)$r[0]['n'] > 0) $add('qc', wmsFmt('Menunggu pemeriksaan QC'), wmsFmt('{n} lot di karantina belum diputuskan', ['n' => $r[0]['n']]), 'manage_qc.php', 'qc' . $r[0]['n'] . '-' . $r[0]['mx']);

        // 5. Barang masuk baru dari orang lain (24 jam)
        $rows = wmsNotifRows("SELECT t.id, i.sku, uu.full_name
            FROM inventory_transactions t JOIN inventory_ledger_entries l ON l.transaction_id = t.id
            JOIN items i ON i.id = l.item_id JOIN users uu ON uu.id = t.created_by
            WHERE t.trx_type = 'INBOUND_PURCHASE' AND t.trx_status = 'COMMITTED' AND t.created_by <> $me
              AND t.created_at >= (NOW() - INTERVAL 1 DAY)
            GROUP BY t.id, i.id ORDER BY t.id DESC LIMIT 50");
        if ($rows) {
            $add('in', wmsFmt('{n} barang masuk baru', ['n' => count($rows)]), wmsNotifJoin(array_column($rows, 'sku')) . ' - ' . wmsFmt('oleh') . ' ' . wmsNotifJoin(array_column($rows, 'full_name'), 1),
                'manage_inbound.php', 'in' . implode(',', array_column($rows, 'id')), 'event');
        }
    }

    // 6. Barang keluar baru dari orang lain (24 jam)
    if (canAccess('outbound', 'view')) {
        $rows = wmsNotifRows("SELECT t.id, i.sku, uu.full_name
            FROM inventory_transactions t JOIN inventory_ledger_entries l ON l.transaction_id = t.id
            JOIN items i ON i.id = l.item_id JOIN users uu ON uu.id = t.created_by
            WHERE t.trx_type IN ('OUTBOUND_PRODUCTION','OUTBOUND_EXPORT_SALE') AND t.trx_status = 'COMMITTED' AND t.created_by <> $me
              AND t.created_at >= (NOW() - INTERVAL 1 DAY)
            GROUP BY t.id, i.id ORDER BY t.id DESC LIMIT 50");
        if ($rows) {
            $add('out', wmsFmt('{n} barang keluar baru', ['n' => count($rows)]), wmsNotifJoin(array_column($rows, 'sku')) . ' - ' . wmsFmt('oleh') . ' ' . wmsNotifJoin(array_column($rows, 'full_name'), 1),
                'manage_outbound.php', 'out' . implode(',', array_column($rows, 'id')), 'event');
        }
    }

    // 7. Stok di bawah reorder point (sama dengan peringatan di Dashboard)
    if (canAccess('catalog', 'view')) {
        $rows = wmsNotifRows("SELECT i.id, i.sku, i.min_safety_stock,
                COALESCE(SUM(CASE WHEN b.qc_status = 'PASSED' AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
                                  THEN sb.qty_on_hand - sb.qty_reserved END), 0) AS qty
            FROM items i LEFT JOIN stock_balances sb ON sb.item_id = i.id LEFT JOIN item_batches b ON b.id = sb.batch_id
            WHERE i.is_active = 1 GROUP BY i.id HAVING qty <= i.reorder_point ORDER BY qty ASC");
        if ($rows) {
            $crit = 0;
            foreach ($rows as $r) if ((float)$r['qty'] <= (float)$r['min_safety_stock']) $crit++;
            $add('stock', wmsFmt('{n} barang di bawah batas minimum', ['n' => count($rows)]), wmsNotifJoin(array_column($rows, 'sku')) . ($crit ? ' (' . wmsFmt('{n} di bawah stok pengaman', ['n' => $crit]) . ')' : ''),
                canAccess('inbound', 'create') ? 'manage_po.php?suggest=1' : 'index.php', 'st' . implode(',', array_column($rows, 'id')) . 'C' . $crit);
        }

        // 8. Lot bahan (mis. kimia) yang sudah / hampir kedaluwarsa (30 hari) dan masih ada stoknya
        $rows = wmsNotifRows("SELECT b.id, b.batch_number, b.expiry_date, DATEDIFF(b.expiry_date, CURDATE()) AS days_left, SUM(sb.qty_on_hand) AS qty
            FROM item_batches b JOIN stock_balances sb ON sb.batch_id = b.id
            WHERE b.expiry_date IS NOT NULL AND b.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            GROUP BY b.id HAVING qty > 0 ORDER BY b.expiry_date ASC LIMIT 100");
        if ($rows) {
            $gone = count(array_filter($rows, function ($r) { return (int)$r['days_left'] < 0; }));
            $first = $rows[0];
            $d = (int)$first['days_left'];
            $when = $d < 0 ? wmsFmt('sudah lewat {n} hari', ['n' => abs($d)]) : wmsFmt('sisa {n} hari', ['n' => $d]);
            $add('expiry', wmsFmt('{n} lot kedaluwarsa / hampir kedaluwarsa', ['n' => count($rows)]),
                wmsNotifJoin(array_column($rows, 'batch_number')) . ' (' . wmsFmt('terdekat {when}', ['when' => $when]) . ($gone ? ', ' . wmsFmt('{n} sudah lewat', ['n' => $gone]) : '') . ')',
                'index.php', 'ex' . implode(',', array_column($rows, 'id')) . 'G' . $gone);
        }
    }

    // 9. Sertifikat SVLK supplier: sudah / hampir kedaluwarsa (30 hari) atau datanya belum lengkap
    if (canAccess('master', 'view')) {
        $rows = wmsNotifRows("SELECT id, company_name, svlk_expiry_date, DATEDIFF(svlk_expiry_date, CURDATE()) AS days_left
            FROM suppliers WHERE is_active = 1 AND is_svlk_certified = 1
              AND (svlk_expiry_date IS NULL OR svlk_expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))
            ORDER BY (svlk_expiry_date IS NULL), svlk_expiry_date ASC LIMIT 100");
        if ($rows) {
            $gone = $missing = 0;
            foreach ($rows as $r) {
                if ($r['svlk_expiry_date'] === null) $missing++;
                elseif ((int)$r['days_left'] < 0) $gone++;
            }
            $n = count($rows);
            $detail = ($gone ? wmsFmt('{n} sudah kedaluwarsa', ['n' => $gone]) : '') . ($gone && $missing ? ', ' : '') . ($missing ? wmsFmt('{n} belum diisi tanggalnya', ['n' => $missing]) : '');
            $add('svlk', wmsFmt('{n} sertifikat SVLK supplier perlu perhatian', ['n' => $n]),
                wmsNotifJoin(array_column($rows, 'company_name')) . ($detail !== '' ? " ($detail)" : ''),
                $n === 1 ? 'manage_supplier.php?edit=' . (int)$rows[0]['id'] : 'manage_supplier.php',
                'sv' . implode(',', array_column($rows, 'id')) . 'G' . $gone . 'M' . $missing);
        }
    }

    // 10. Hutang supplier: faktur terlambat / jatuh tempo dalam 3 hari
    if (canAccess('payable', 'view')) {
        $rows = wmsNotifRows("SELECT si.id, si.inv_code, s.company_name, si.due_date, DATEDIFF(CURDATE(), si.due_date) AS late,
                (si.amount_idr - si.paid_idr - si.credit_applied_idr) AS outstanding
            FROM supplier_invoices si JOIN suppliers s ON s.id = si.supplier_id
            WHERE si.status IN ('UNPAID','PARTIAL') AND si.due_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)
            ORDER BY si.due_date ASC, si.id ASC LIMIT 100");
        if ($rows) {
            $n = count($rows);
            $late = count(array_filter($rows, function ($r) { return (int)$r['late'] > 0; }));
            $sum = 0.0;
            foreach ($rows as $r) $sum += (float)$r['outstanding'];
            $sig = 'pay' . implode(',', array_column($rows, 'id')) . 'L' . $late;
            if ($n === 1) {
                $r = $rows[0];
                $d = (int)$r['late'];
                $when = $d > 0 ? wmsFmt('terlambat {n} hari', ['n' => $d]) : ($d === 0 ? wmsFmt('jatuh tempo hari ini') : wmsFmt('jatuh tempo {n} hari lagi', ['n' => -$d]));
                $add('pay', $d > 0 ? wmsFmt('Faktur supplier terlambat dibayar') : wmsFmt('Faktur supplier segera jatuh tempo'),
                    "{$r['inv_code']} - {$r['company_name']}, Rp " . number_format($sum, 0, ',', '.') . " ($when)", 'manage_payable.php?inv=' . (int)$r['id'], $sig);
            } else {
                $add('pay', wmsFmt('{n} faktur supplier jatuh tempo', ['n' => $n]) . ($late ? ' (' . wmsFmt('{n} terlambat', ['n' => $late]) . ')' : ''),
                    wmsNotifJoin(array_column($rows, 'company_name')) . ' - ' . wmsFmt('total') . ' Rp ' . number_format($sum, 0, ',', '.'), 'manage_payable.php?st=overdue', $sig);
            }
        }
        // Kredit / klaim retur yang terbuka lebih dari 14 hari
        $rows = wmsNotifRows("SELECT id, credit_code FROM supplier_credits WHERE status = 'OPEN' AND created_at < (NOW() - INTERVAL 14 DAY) ORDER BY id ASC LIMIT 100");
        if ($rows) {
            $add('pay', wmsFmt('{n} kredit / klaim retur belum diselesaikan', ['n' => count($rows)]), wmsNotifJoin(array_column($rows, 'credit_code')) . ' (' . wmsFmt('lebih dari 14 hari') . ')',
                'manage_payable.php#kredit', 'cr' . implode(',', array_column($rows, 'id')));
        }
    }

    // 11. Reservasi stok yang melewati tanggal dibutuhkan
    if (canAccess('outbound', 'view')) {
        $rows = wmsNotifRows("SELECT id, res_code, reference_doc_no FROM stock_reservations
            WHERE status = 'ACTIVE' AND needed_date IS NOT NULL AND needed_date < CURDATE() ORDER BY needed_date ASC LIMIT 100");
        if ($rows) {
            $add('rsv', wmsFmt('{n} reservasi stok melewati tanggal dibutuhkan', ['n' => count($rows)]), wmsNotifJoin(array_column($rows, 'reference_doc_no')) . ' - ' . wmsFmt('pakai atau lepas; dilepas otomatis {n} hari setelah tanggal dibutuhkan', ['n' => (defined('RSV_AUTO_RELEASE_GRACE_DAYS') ? RSV_AUTO_RELEASE_GRACE_DAYS : 3)]),
                'manage_reservation.php', 'rv' . implode(',', array_column($rows, 'id')));
        }
    }

    return ['now' => time(), 'items' => $out];
}
