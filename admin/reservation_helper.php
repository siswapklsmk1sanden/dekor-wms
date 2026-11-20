<?php
/**
 * Helper Reservasi Stok.
 * Reservasi menahan sejumlah stok (rak + lot, FIFO/FEFO, hanya QC PASSED, belum kedaluwarsa) untuk
 * satu SPK / work order / invoice. Jumlah yang ditahan tercatat di stock_balances.qty_reserved
 * (kolom "Dipesan" di Saldo Stok), sehingga Barang Keluar, Produksi, Transfer Rak, dan Retur lain
 * otomatis tidak bisa mengambilnya. Rincian per rak+lot ada di stock_reservation_allocs.
 *
 * Semua fungsi pencatat HARUS dipanggil di dalam transaksi database (stockBegin ... stockCommit).
 * Jangan memanggil wmsEnsureSchemaExt() dari sini (DDL memutus transaksi); halaman pemanggil
 * sudah memanggilnya di awal.
 */
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/schema_ext.php';

/** Buat satu reservasi untuk satu barang. Mengembalikan ['id','code','sku','detail'=>[...]]. */
function rsvCreate($itemId, $qty, $ref, $neededDate, $notes, $groupCode = null)
{
    global $connect;
    $itemId = (int)$itemId;
    $qty    = round((float)$qty, 4);
    $ref    = trim((string)$ref);
    if ($qty <= 0) throw new StockException('Jumlah reservasi harus lebih dari 0.');
    if ($ref === '') throw new StockException('No. SPK / work order wajib diisi.');
    if (mb_strlen($ref) > 60) throw new StockException('No. SPK / work order terlalu panjang (maks. 60 karakter).');

    $st = mysqli_prepare($connect, "SELECT i.id, i.sku, c.allocation_strategy
        FROM items i JOIN categories c ON c.id = i.category_id WHERE i.id = ? AND i.is_active = 1");
    mysqli_stmt_bind_param($st, 'i', $itemId);
    mysqli_stmt_execute($st);
    $item = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if (!$item) throw new StockException('Barang tidak ditemukan atau tidak aktif.');

    try {
        $plan = planAllocation($itemId, $qty, $item['allocation_strategy']);
    } catch (StockException $ex) {
        throw new StockException($item['sku'] . ' (butuh ' . fmtQty($qty) . '): ' . $ex->getMessage());
    }

    $me     = (int)($GLOBALS['WMS_TRX_CREATOR'] ?? ($_SESSION['user_id'] ?? 0));
    $need   = ($neededDate !== null && $neededDate !== '') ? (string)$neededDate : null;
    $notesV = ($notes !== null && trim((string)$notes) !== '') ? mb_substr(trim((string)$notes), 0, 300) : null;
    $qS     = number_format($qty, 4, '.', '');

    $resId = 0;
    $code  = '';
    for ($try = 0; $try < 5; $try++) {
        $code = 'RSV-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
        $ins = mysqli_prepare($connect, "INSERT INTO stock_reservations
            (res_code, group_code, item_id, qty_total, qty_open, reference_doc_no, needed_date, notes, status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVE', ?)");
        mysqli_stmt_bind_param($ins, 'ssisssssi', $code, $groupCode, $itemId, $qS, $qS, $ref, $need, $notesV, $me);
        if (mysqli_stmt_execute($ins)) {
            $resId = (int)mysqli_insert_id($connect);
            break;
        }
        if (mysqli_errno($connect) !== 1062) {
            throw new StockException('Gagal mencatat reservasi: ' . mysqli_error($connect));
        }
    }
    if (!$resId) throw new StockException('Gagal membuat nomor reservasi unik.');

    $detail = [];
    foreach ($plan as $p) {
        $take  = number_format((float)$p['take'], 4, '.', '');
        $binId = (int)$p['bin_id'];
        $batId = (int)$p['batch_id'];
        $up = mysqli_prepare($connect, "UPDATE stock_balances SET qty_reserved = qty_reserved + ?
            WHERE item_id = ? AND bin_id = ? AND batch_id = ?");
        mysqli_stmt_bind_param($up, 'siii', $take, $itemId, $binId, $batId);
        mysqli_stmt_execute($up);
        if (mysqli_stmt_affected_rows($up) !== 1) {
            throw new StockException('Gagal menahan stok di rak ' . $p['bin_code'] . '.');
        }
        $al = mysqli_prepare($connect, "INSERT INTO stock_reservation_allocs (reservation_id, bin_id, batch_id, qty)
            VALUES (?, ?, ?, ?)");
        mysqli_stmt_bind_param($al, 'iiis', $resId, $binId, $batId, $take);
        if (!mysqli_stmt_execute($al)) {
            throw new StockException('Gagal mencatat rincian reservasi: ' . mysqli_error($connect));
        }
        $detail[] = $p['bin_code'] . '/' . $p['batch_number'] . ' x' . fmtQty($p['take']);
    }
    return ['id' => $resId, 'code' => $code, 'sku' => $item['sku'], 'detail' => $detail];
}

/**
 * Kurangi tahanan sebuah reservasi sebesar $amount (melepas qty_reserved di rak+lot terkait).
 * Baris reservasi harus sudah dikunci pemanggil. Mengembalikan jumlah yang benar-benar dilepas.
 */
function rsvShrink(array $res, $amount)
{
    global $connect;
    $left   = round((float)$amount, 4);
    $rid    = (int)$res['id'];
    $itemId = (int)$res['item_id'];

    $q = mysqli_prepare($connect, "SELECT id, bin_id, batch_id, qty FROM stock_reservation_allocs
        WHERE reservation_id = ? ORDER BY id FOR UPDATE");
    mysqli_stmt_bind_param($q, 'i', $rid);
    mysqli_stmt_execute($q);
    $allocs = mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC);

    $released = 0.0;
    foreach ($allocs as $a) {
        if ($left <= 0.00005) break;
        $dec  = min($left, (float)$a['qty']);
        $decS = number_format($dec, 4, '.', '');
        $binId = (int)$a['bin_id'];
        $batId = (int)$a['batch_id'];
        $u = mysqli_prepare($connect, "UPDATE stock_balances SET qty_reserved = GREATEST(0, qty_reserved - ?)
            WHERE item_id = ? AND bin_id = ? AND batch_id = ?");
        mysqli_stmt_bind_param($u, 'siii', $decS, $itemId, $binId, $batId);
        mysqli_stmt_execute($u);

        $remain = round((float)$a['qty'] - $dec, 4);
        $aid = (int)$a['id'];
        if ($remain <= 0.00005) {
            $d = mysqli_prepare($connect, "DELETE FROM stock_reservation_allocs WHERE id = ?");
            mysqli_stmt_bind_param($d, 'i', $aid);
            mysqli_stmt_execute($d);
        } else {
            $remS = number_format($remain, 4, '.', '');
            $d = mysqli_prepare($connect, "UPDATE stock_reservation_allocs SET qty = ? WHERE id = ?");
            mysqli_stmt_bind_param($d, 'si', $remS, $aid);
            mysqli_stmt_execute($d);
        }
        $left     = round($left - $dec, 4);
        $released = round($released + $dec, 4);
    }
    return $released;
}

/**
 * Pakai reservasi aktif (barang sama + No. SPK/referensi sama) untuk sebuah pengeluaran sebesar $qty.
 * Dipanggil SEBELUM planAllocation: bagian yang dipesan dilepas dulu supaya terhitung tersedia.
 * Mengembalikan ['taken' => jumlah dari reservasi, 'codes' => [kode reservasi]].
 */
function rsvConsume($itemId, $ref, $qty, $note = '')
{
    global $connect;
    $out = ['taken' => 0.0, 'codes' => []];
    $ref = trim((string)$ref);
    if ($ref === '' || (float)$qty <= 0) return $out;

    $st = @mysqli_prepare($connect, "SELECT * FROM stock_reservations
        WHERE item_id = ? AND status = 'ACTIVE' AND reference_doc_no = ? ORDER BY id FOR UPDATE");
    if (!$st) return $out;                       // tabel belum ada: tidak ada reservasi
    $iid = (int)$itemId;
    mysqli_stmt_bind_param($st, 'is', $iid, $ref);
    mysqli_stmt_execute($st);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

    $me   = (int)($_SESSION['user_id'] ?? 0);
    $need = round((float)$qty, 4);
    foreach ($rows as $r) {
        if ($need <= 0.00005) break;
        $take  = min($need, (float)$r['qty_open']);
        $taken = rsvShrink($r, $take);
        $need  = round($need - $taken, 4);
        $out['taken'] = round($out['taken'] + $taken, 4);
        $out['codes'][] = $r['res_code'];

        $rid     = (int)$r['id'];
        $newOpen = round((float)$r['qty_open'] - $taken, 4);
        if ($newOpen <= 0.00005) {
            $msg = mb_substr('Terpenuhi' . ($note !== '' ? ' lewat ' . $note : ''), 0, 200);
            $u = mysqli_prepare($connect, "UPDATE stock_reservations SET qty_open = 0, status = 'FULFILLED',
                closed_by = ?, closed_at = NOW(), close_note = ? WHERE id = ?");
            mysqli_stmt_bind_param($u, 'isi', $me, $msg, $rid);
        } else {
            $oS = number_format($newOpen, 4, '.', '');
            $u = mysqli_prepare($connect, "UPDATE stock_reservations SET qty_open = ? WHERE id = ?");
            mysqli_stmt_bind_param($u, 'si', $oS, $rid);
        }
        mysqli_stmt_execute($u);
    }
    return $out;
}

/** Teks ringkas untuk pesan/log: " (memakai 5 dari reservasi RSV-..., RSV-...)" atau kosong. */
function rsvConsumeNote(array $c)
{
    if (empty($c['taken']) || (float)$c['taken'] <= 0) return '';
    return ' (memakai ' . fmtQty($c['taken']) . ' dari reservasi ' . implode(', ', $c['codes']) . ')';
}

/**
 * Lepas seluruh sisa reservasi (batal). Hanya pemegang hak Setujui atau pembuatnya.
 * Mengembalikan [baris reservasi, jumlah yang dilepas].
 */
function rsvRelease($resId, $reason, $actorId, $isApprover)
{
    global $connect;
    $rid = (int)$resId;
    $st = mysqli_prepare($connect, "SELECT * FROM stock_reservations WHERE id = ? FOR UPDATE");
    mysqli_stmt_bind_param($st, 'i', $rid);
    mysqli_stmt_execute($st);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
    if (!$r) throw new StockException('Reservasi tidak ditemukan.');
    if ($r['status'] !== 'ACTIVE') throw new StockException("Reservasi {$r['res_code']} sudah ditutup.");
    if (!$isApprover && (int)$r['created_by'] !== (int)$actorId) {
        throw new StockException('Hanya pembuat reservasi atau pemegang hak Setujui yang dapat melepasnya.');
    }
    $taken = rsvShrink($r, (float)$r['qty_open']);
    $d = mysqli_prepare($connect, "DELETE FROM stock_reservation_allocs WHERE reservation_id = ?");
    mysqli_stmt_bind_param($d, 'i', $rid);
    mysqli_stmt_execute($d);

    $msg = mb_substr('Dilepas: ' . $reason, 0, 200);
    $actor = (int)$actorId;
    $u = mysqli_prepare($connect, "UPDATE stock_reservations SET qty_open = 0, status = 'RELEASED',
        closed_by = ?, closed_at = NOW(), close_note = ? WHERE id = ?");
    mysqli_stmt_bind_param($u, 'isi', $actor, $msg, $rid);
    mysqli_stmt_execute($u);
    return [$r, $taken];
}

// =====================================================================
// Pelepasan otomatis reservasi yang lewat tanggal dibutuhkan
// =====================================================================
/** Masa tenggang (hari) setelah tanggal dibutuhkan sebelum reservasi dilepas otomatis. Ubah lewat config.local.php. */
if (!defined('RSV_AUTO_RELEASE_GRACE_DAYS')) define('RSV_AUTO_RELEASE_GRACE_DAYS', 3);

/**
 * Lepas otomatis reservasi ACTIVE yang tanggal dibutuhkannya sudah lewat lebih dari masa tenggang.
 * Stok yang ditahan kembali tersedia; tercatat di Audit Log. Tidak boleh dipanggil di tengah transaksi.
 * Mengembalikan daftar kode reservasi yang dilepas.
 */
function rsvAutoReleaseExpired($graceDays = null)
{
    global $connect;
    $grace = max(0, (int)($graceDays ?? RSV_AUTO_RELEASE_GRACE_DAYS));
    $ids = [];
    try {
        $rs = mysqli_query($connect, "SELECT id FROM stock_reservations
            WHERE status = 'ACTIVE' AND needed_date IS NOT NULL AND needed_date < DATE_SUB(CURDATE(), INTERVAL $grace DAY)
            ORDER BY id LIMIT 200");
        if (!$rs) return [];
        while ($r = mysqli_fetch_row($rs)) $ids[] = (int)$r[0];
    } catch (Throwable $e) {
        return [];                                // tabel reservasi belum ada
    }

    $done = [];
    foreach ($ids as $rid) {
        try {
            mysqli_begin_transaction($connect);
            $st = mysqli_prepare($connect, "SELECT * FROM stock_reservations WHERE id = ? FOR UPDATE");
            mysqli_stmt_bind_param($st, 'i', $rid);
            mysqli_stmt_execute($st);
            $r = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
            if (!$r || $r['status'] !== 'ACTIVE' || empty($r['needed_date'])
                || strtotime($r['needed_date']) >= strtotime(date('Y-m-d') . " -$grace days")) {
                mysqli_rollback($connect);        // sudah diproses orang lain / belum waktunya
                continue;
            }
            $taken = rsvShrink($r, (float)$r['qty_open']);
            $d = mysqli_prepare($connect, "DELETE FROM stock_reservation_allocs WHERE reservation_id = ?");
            mysqli_stmt_bind_param($d, 'i', $rid);
            mysqli_stmt_execute($d);
            $msg = mb_substr('Dilepas otomatis: lewat tanggal dibutuhkan ' . $r['needed_date'] . " + $grace hari", 0, 200);
            $u = mysqli_prepare($connect, "UPDATE stock_reservations SET qty_open = 0, status = 'RELEASED',
                closed_by = NULL, closed_at = NOW(), close_note = ? WHERE id = ?");
            mysqli_stmt_bind_param($u, 'si', $msg, $rid);
            mysqli_stmt_execute($u);
            mysqli_commit($connect);
            $done[] = $r['res_code'];
            logActivity('UPDATE', 'Reservasi Stok', "Reservasi {$r['res_code']} (" . $r['reference_doc_no'] . ', ' . fmtQty($taken)
                . ") dilepas otomatis: lewat tanggal dibutuhkan {$r['needed_date']} + $grace hari.", $rid);
        } catch (Throwable $e) {
            try { mysqli_rollback($connect); } catch (Throwable $e2) {}
        }
    }
    return $done;
}

/**
 * Pemeliharaan ringan yang dipanggil dari halaman (tanpa cron): paling sering sekali per 5 menit per sesi,
 * atau langsung bila $force = true.
 */
function wmsAutoMaintenance($force = false)
{
    if (empty($_SESSION['user_id'])) return;
    if (!$force && !empty($_SESSION['maint_ts']) && (time() - (int)$_SESSION['maint_ts']) < 300) return;
    $_SESSION['maint_ts'] = time();
    rsvAutoReleaseExpired();
}
