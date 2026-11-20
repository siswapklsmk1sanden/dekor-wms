<?php
/**
 * Persetujuan Draft: transaksi yang diajukan peran tanpa hak "Setujui"
 * (Barang Masuk, Barang Keluar, Transfer Rak, Produksi, dan perubahan Data Master)
 * menunggu di sini.
 *  - Pemegang hak Setujui pada modul terkait: Proses & Setujui, atau Tolak (alasan wajib).
 *  - Pengaju: melihat draft sendiri dan boleh membatalkannya selama masih PENDING.
 * Menyetujui = membuka halaman modul asal dengan isian draft, lalu posting memakai
 * validasi + kunci stok yang sama seperti input langsung.
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/stock.php';
require_once __DIR__ . '/photo_helper.php';
require_once __DIR__ . '/layout.php';
requireLogin();

$modules = ['inbound', 'outbound', 'bom', 'master'];
$mayCreate = false;
foreach ($modules as $m) {
    if (canAccess($m, 'create') || canAccess($m, 'approve')) $mayCreate = true;
}
if (!$mayCreate) {
    http_response_code(403);
    die('Akses ditolak untuk peran Anda.');
}

$me   = (int)$_SESSION['user_id'];
$csrf = stockCsrf();
$approvable = array_values(array_filter($modules, function ($m) { return canAccess($m, 'approve'); }));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    stockCheckCsrf();
    $act = $_POST['act'] ?? '';
    $id  = (int)($_POST['id'] ?? 0);
    $st = mysqli_prepare($connect, "SELECT * FROM trx_drafts WHERE id = ?");
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $d = mysqli_fetch_assoc(mysqli_stmt_get_result($st));

    if (!$d || $d['status'] !== 'PENDING') {
        $_SESSION['flash'] = ['err', 'Draft tidak ditemukan atau sudah diproses.'];
    } elseif ($act === 'reject') {
        requirePermission($d['module_name'], 'approve');
        $note = trim((string)($_POST['note'] ?? ''));
        if ($note === '') {
            $_SESSION['flash'] = ['err', 'Alasan penolakan wajib diisi.'];
        } else {
            $note = mb_substr($note, 0, 200);
            $up = mysqli_prepare($connect, "UPDATE trx_drafts SET status = 'REJECTED', decided_by = ?,
                decided_at = NOW(), decision_note = ? WHERE id = ? AND status = 'PENDING'");
            mysqli_stmt_bind_param($up, 'isi', $me, $note, $id);
            mysqli_stmt_execute($up);
            logActivity('REJECT', $d['label'], "Menolak draft {$d['label']} #$id: $note", $id);
            $_SESSION['flash'] = ['ok', "Draft #$id ditolak."];
        }
    } elseif ($act === 'cancel') {
        if ((int)$d['created_by'] !== $me) {
            $_SESSION['flash'] = ['err', 'Hanya pengaju yang dapat membatalkan draft ini.'];
        } else {
            $up = mysqli_prepare($connect, "UPDATE trx_drafts SET status = 'CANCELLED', decided_by = ?,
                decided_at = NOW(), decision_note = 'Dibatalkan pengaju' WHERE id = ? AND status = 'PENDING'");
            mysqli_stmt_bind_param($up, 'ii', $me, $id);
            mysqli_stmt_execute($up);
            logActivity('CANCEL', $d['label'], "Membatalkan draft {$d['label']} #$id", $id);
            $_SESSION['flash'] = ['ok', "Draft #$id dibatalkan."];
        }
    }
    header('Location: manage_approval.php');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/** Nilai tunggal dari satu query berparameter integer (atau null). */
function apprLookup($sql, $id)
{
    global $connect;
    $st = mysqli_prepare($connect, $sql);
    mysqli_stmt_bind_param($st, 'i', $id);
    mysqli_stmt_execute($st);
    $row = mysqli_fetch_row(mysqli_stmt_get_result($st));
    return $row ? $row[0] : null;
}

/** Ubah isian draft menjadi pasangan label => teks yang mudah dibaca. */
function apprDescribe(array $p)
{
    // Draft data master: tampilan sudah disiapkan saat pengajuan (label => teks)
    if (isset($p['_display']) && is_array($p['_display'])) {
        $out = [];
        foreach ($p['_display'] as $lab => $txt) {
            if ($txt === '' || $txt === null) continue;
            $out[(string)$lab] = (string)$txt;
        }
        return $out;
    }
    $labels = [
        'trx_type' => 'Jenis', 'item_id' => 'Barang', 'bin_id' => 'Rak', 'dest_bin_id' => 'Rak tujuan',
        'src' => 'Stok asal', 'supplier_id' => 'Supplier', 'bom_id' => 'BoM', 'batch_number' => 'No. Lot',
        'received_date' => 'Tgl terima', 'expiry_date' => 'Kedaluwarsa', 'qty' => 'Jumlah',
        'unit_cost' => 'Harga satuan', 'moisture' => 'MC (%)', 'qc_status' => 'Status QC',
        'ref' => 'Referensi', 'notes' => 'Catatan',
        'batch_id' => 'Lot', 'doc_no' => 'No. dok. supplier', 'reason' => 'Alasan',
        'mode' => 'Cara memesan', 'needed_date' => 'Dibutuhkan tgl',
    ];
    $out = [];
    foreach ($p as $k => $v) {
        if ($v === '' || $v === null || !isset($labels[$k])) continue;
        $v = (string)$v;
        $txt = $v;
        if ($k === 'item_id') {
            $r = apprLookup("SELECT CONCAT(sku, ' - ', item_name) FROM items WHERE id = ?", (int)$v);
            if ($r) $txt = $r;
        } elseif ($k === 'bin_id' || $k === 'dest_bin_id') {
            $r = apprLookup("SELECT bin_code FROM storage_bins WHERE id = ?", (int)$v);
            if ($r) $txt = $r;
        } elseif ($k === 'supplier_id') {
            $r = apprLookup("SELECT company_name FROM suppliers WHERE id = ?", (int)$v);
            if ($r) $txt = $r;
        } elseif ($k === 'batch_id') {
            $r = apprLookup("SELECT CONCAT(i.sku, ' / lot ', b.batch_number) FROM item_batches b JOIN items i ON i.id = b.item_id WHERE b.id = ?", (int)$v);
            if ($r) $txt = $r;
        } elseif ($k === 'bom_id') {
            $r = apprLookup("SELECT bom_code FROM bom_headers WHERE id = ?", (int)$v);
            if ($r) $txt = $r;
        } elseif ($k === 'src') {
            $parts = explode(':', $v);
            if (count($parts) === 3) {
                $bin = apprLookup("SELECT bin_code FROM storage_bins WHERE id = ?", (int)$parts[0]);
                $lot = apprLookup("SELECT batch_number FROM item_batches WHERE id = ?", (int)$parts[1]);
                $sku = apprLookup("SELECT sku FROM items WHERE id = ?", (int)$parts[2]);
                $txt = trim(($sku ?: '?') . ' / lot ' . ($lot ?: '?') . ' / rak ' . ($bin ?: '?'));
            }
        } elseif ($k === 'trx_type') {
            $txt = $v === 'OUTBOUND_EXPORT_SALE' ? 'Ekspor' : ($v === 'OUTBOUND_PRODUCTION' ? 'Ke produksi' : $v);
        }
        $out[$labels[$k]] = $txt;
    }
    return $out;
}

$where = "d.created_by = " . $me;
if ($approvable) {
    $in = "'" . implode("','", $approvable) . "'";   // nilai dari daftar tetap di atas, bukan input pengguna
    $where .= " OR d.module_name IN ($in)";
}
$pending = mysqli_fetch_all(mysqli_query($connect, "SELECT d.*, cu.full_name AS creator
    FROM trx_drafts d JOIN users cu ON cu.id = d.created_by
    WHERE d.status = 'PENDING' AND ($where) ORDER BY d.id ASC"), MYSQLI_ASSOC);
$history = mysqli_fetch_all(mysqli_query($connect, "SELECT d.*, cu.full_name AS creator, du.full_name AS decider
    FROM trx_drafts d JOIN users cu ON cu.id = d.created_by LEFT JOIN users du ON du.id = d.decided_by
    WHERE d.status <> 'PENDING' AND ($where) ORDER BY d.decided_at DESC, d.id DESC LIMIT 30"), MYSQLI_ASSOC);

$statusLabel = ['APPROVED' => 'Disetujui', 'REJECTED' => 'Ditolak', 'CANCELLED' => 'Dibatalkan'];

renderHeader('Persetujuan');
?>
<h2>Persetujuan Draft</h2>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>

<div class="panel scroll">
  <strong>Menunggu persetujuan</strong> <small>(stok baru berubah setelah disetujui)</small>
  <table>
    <tr><th>#</th><th>Diajukan</th><th>Modul</th><th>Isi draft</th><th>Aksi</th></tr>
    <?php if (!$pending): ?><tr><td colspan="5">Tidak ada draft yang menunggu.</td></tr><?php endif; ?>
    <?php foreach ($pending as $d):
        $pay  = json_decode($d['payload_json'], true) ?: [];
        $desc = apprDescribe($pay);
        $ph   = wmsValidArrivalPath($pay['photo_path'] ?? '');
        $canDecide = canAccess($d['module_name'], 'approve');
        $mine = (int)$d['created_by'] === $me; ?>
      <tr id="d<?= (int)$d['id'] ?>">
        <td><?= (int)$d['id'] ?></td>
        <td><?= e($d['created_at']) ?><br><small><?= e($d['creator']) ?></small></td>
        <td><?= e($d['label']) ?></td>
        <td><?php foreach ($desc as $lab => $txt): ?><div><small><?= e($lab) ?>:</small> <?= e($txt) ?></div><?php endforeach; ?>
          <?php if ($ph !== ''): ?><div style="margin-top:6px"><small>Foto kedatangan:</small><br><a href="<?= e($ph) ?>" target="_blank"><img src="<?= e($ph) ?>" alt="Foto barang" style="max-width:160px;max-height:120px;border-radius:6px;border:1px solid #ccc"></a></div><?php endif; ?></td>
        <td style="min-width:220px">
          <?php if ($canDecide): ?>
            <form method="post" action="<?= e($d['target_page']) ?>" class="inline">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="run_draft" value="<?= (int)$d['id'] ?>">
              <button type="submit" onclick="return confirm('Setujui dan posting draft #<?= (int)$d['id'] ?> sekarang?')">Setujui &amp; Posting</button>
            </form>
            <form method="post" class="inline" style="display:block;margin-top:6px">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="act" value="reject">
              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <input type="text" name="note" maxlength="200" placeholder="Alasan tolak *" required style="width:140px;padding:6px">
              <button type="submit" class="danger">Tolak</button>
            </form>
          <?php endif; ?>
          <?php if ($mine): ?>
            <form method="post" class="inline" style="display:block;margin-top:6px">
              <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
              <input type="hidden" name="act" value="cancel">
              <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button type="submit" class="sec" onclick="return confirm('Batalkan draft ini?')">Batalkan</button>
            </form>
          <?php endif; ?>
          <?php if (!$canDecide && !$mine): ?>-<?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>

<div class="panel scroll">
  <strong>Riwayat keputusan</strong> <small>(30 terakhir)</small>
  <table>
    <tr><th>#</th><th>Modul</th><th>Pengaju</th><th>Status</th><th>Diputuskan</th><th>Hasil / alasan</th></tr>
    <?php if (!$history): ?><tr><td colspan="6">Belum ada riwayat.</td></tr><?php endif; ?>
    <?php foreach ($history as $d): ?>
      <tr id="d<?= (int)$d['id'] ?>">
        <td><?= (int)$d['id'] ?></td><td><?= e($d['label']) ?></td><td><?= e($d['creator']) ?></td>
        <td><?= e($statusLabel[$d['status']] ?? $d['status']) ?></td>
        <td><?= e($d['decided_at']) ?><br><small><?= e($d['decider'] ?? '') ?></small></td>
        <td><?php if ($d['result_trx_code']): ?><?= e($d['result_trx_code']) ?><?php else: ?><?= e($d['decision_note']) ?><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
</div>
<?php renderFooter();
