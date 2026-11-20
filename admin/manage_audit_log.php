<?php
/**
 * Audit Log: riwayat aktivitas + verifikasi rantai hash SHA-256.
 * Pembersihan seluruh log: semua peran yang dapat membuka halaman ini (permintaan pemilik proyek),
 * wajib mengetik HAPUS, dan satu catatan "log dibersihkan oleh ..." tetap dibuat.
 * Untuk membatasi lagi ke Super Admin: ganti $canClear di bawah dengan
 * (($_SESSION['role_code'] ?? '') === 'super_admin').
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/layout.php';
requirePermission('audit', 'view');

$canClear = !empty($_SESSION['user_id']);   // requirePermission di atas sudah memastikan login dan hak lihat
if (empty($_SESSION['audit_clear_token'])) {
    $_SESSION['audit_clear_token'] = bin2hex(random_bytes(16));
}

// ---- Bersihkan semua log (POST) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_all') {
    if (!$canClear) {
        $_SESSION['flash'] = ['err', 'Anda tidak diizinkan membersihkan Audit Log.'];
    } elseif (!hash_equals($_SESSION['audit_clear_token'], (string)($_POST['token'] ?? ''))) {
        $_SESSION['flash'] = ['err', 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.'];
    } elseif (trim((string)($_POST['confirm'] ?? '')) !== 'HAPUS') {
        $_SESSION['flash'] = ['err', 'Ketik HAPUS (huruf besar) untuk mengonfirmasi.'];
    } else {
        try {
            $n = (int)mysqli_fetch_row(mysqli_query($connect, 'SELECT COUNT(*) FROM audit_logs'))[0];
            try {
                mysqli_query($connect, 'TRUNCATE TABLE audit_logs');      // id mulai dari 1 lagi
            } catch (Throwable $e) {
                mysqli_query($connect, 'DELETE FROM audit_logs');          // cadangan bila TRUNCATE ditolak
            }
            try {
                logActivity('UPDATE', 'Audit Log', "Seluruh Audit Log dibersihkan oleh " . ($_SESSION['username'] ?? 'pengguna') . " ($n entri).", 0);
            } catch (Throwable $e) { /* catatan jejak gagal tidak membatalkan pembersihan */ }
            $_SESSION['flash'] = ['ok', "Audit Log dibersihkan ($n entri dihapus)."];
        } catch (Throwable $e) {
            $_SESSION['flash'] = ['err', 'Gagal membersihkan Audit Log: ' . $e->getMessage()];
        }
    }
    $_SESSION['audit_clear_token'] = bin2hex(random_bytes(16));
    header('Location: manage_audit_log.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$verify = null;
if (isset($_GET['verify'])) {
    $bad = auditVerifyChain();
    $verify = $bad === null
        ? ['ok', 'Rantai hash utuh: tidak ada log yang diubah atau dihapus.']
        : ['err', "Rantai hash RUSAK mulai dari log #$bad. Data setelah titik ini tidak dapat dipercaya."];
}

$q    = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = 50;
$like = '%' . $q . '%';

$st = mysqli_prepare($connect, "SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
    WHERE (? = '' OR a.event_action LIKE ? OR a.entity_table LIKE ? OR a.entity_id LIKE ? OR a.payload_json LIKE ? OR u.username LIKE ?)");
mysqli_stmt_bind_param($st, 'ssssss', $q, $like, $like, $like, $like, $like);
mysqli_stmt_execute($st);
$total = (int)mysqli_fetch_row(mysqli_stmt_get_result($st))[0];
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);
$off   = ($page - 1) * $per;

$st = mysqli_prepare($connect, "SELECT a.id, a.created_at, a.event_action, a.entity_table, a.entity_id,
        a.payload_json, a.ip_address, a.current_hash, u.username
    FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
    WHERE (? = '' OR a.event_action LIKE ? OR a.entity_table LIKE ? OR a.entity_id LIKE ? OR a.payload_json LIKE ? OR u.username LIKE ?)
    ORDER BY a.id DESC LIMIT $per OFFSET $off");
mysqli_stmt_bind_param($st, 'ssssss', $q, $like, $like, $like, $like, $like);
mysqli_stmt_execute($st);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

renderHeader('Audit Log');
?>
<h2>Audit Log</h2>
<?php if ($flash): ?><div class="<?= e($flash[0]) ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php if ($verify): ?><div class="<?= $verify[0] ?>"><?= e($verify[1]) ?></div><?php endif; ?>
<div class="panel" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between">
  <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;margin:0">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari aksi, modul, ID, pengguna, deskripsi" style="max-width:360px">
    <button type="submit">Cari</button>
    <?php if ($q !== ''): ?><a class="btn sec" href="manage_audit_log.php">Reset</a><?php endif; ?>
    <a class="btn danger" style="background:#2b5d8a" href="?verify=1">Verifikasi Rantai Hash</a>
  </form>
  <?php if ($canClear): ?>
  <form method="post" style="margin:0"
        onsubmit="var v = prompt('Hapus SELURUH Audit Log? Tidak bisa dibatalkan.\nKetik HAPUS untuk konfirmasi:'); if (v === null) return false; this.confirm.value = v; return true;">
    <input type="hidden" name="action" value="clear_all">
    <input type="hidden" name="token" value="<?= e($_SESSION['audit_clear_token']) ?>">
    <input type="hidden" name="confirm" value="">
    <button type="submit" title="Hapus seluruh Audit Log" style="background:transparent;color:#B3261E;border:1px solid #B3261E;box-shadow:none">Bersihkan Log</button>
  </form>
  <?php endif; ?>
</div>
<div class="panel scroll">
  <small><?= (int)$total ?> log</small>
  <table>
    <tr><th>#</th><th>Waktu</th><th>Pengguna</th><th>Aksi</th><th>Modul</th><th>ID</th><th>Deskripsi</th><th>IP</th><th>Hash</th></tr>
    <?php if (!$rows): ?><tr><td colspan="9">Tidak ada log.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $p = json_decode($r['payload_json'], true) ?: []; ?>
      <tr><td><?= (int)$r['id'] ?></td><td><?= e($r['created_at']) ?></td>
        <td><?= e($r['username'] ?? ($p['username'] ?? 'system')) ?></td>
        <td><span class="tag"><?= e($r['event_action']) ?></span></td>
        <td><?= e($r['entity_table']) ?></td><td><?= e($r['entity_id']) ?></td>
        <td><?= e($p['description'] ?? '') ?></td><td><?= e($r['ip_address']) ?></td>
        <td><small title="<?= e($r['current_hash']) ?>"><?= e(substr($r['current_hash'], 0, 10)) ?>…</small></td></tr>
    <?php endforeach; ?>
  </table>
  <p style="display:flex;gap:8px;align-items:center">
    <?php $qs = fn($p) => http_build_query(['q' => $q, 'page' => $p]); ?>
    <?php if ($page > 1): ?><a class="btn sec" href="?<?= e($qs($page - 1)) ?>">&laquo; Sebelumnya</a><?php endif; ?>
    <span>Hal. <?= $page ?> / <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn sec" href="?<?= e($qs($page + 1)) ?>">Berikutnya &raquo;</a><?php endif; ?>
  </p>
</div>
<?php renderFooter();