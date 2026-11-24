<?php
/**
 * Mesin CRUD generik untuk modul master data.
 * Dipakai oleh manage_*.php lewat crudRun([...config...]).
 * - Semua query tulis memakai prepared statement.
 * - CSRF token pada setiap form.
 * - Hak akses: view = lihat, create = tambah/ubah, approve = nonaktifkan/hapus.
 * - Alur draft: peran yang punya hak Buat tetapi TIDAK punya hak Setujui (mis. Staff PPIC)
 *   hanya mengajukan draft ke tabel trx_drafts; data master baru tersimpan setelah
 *   pemegang hak Setujui (Kepala Gudang) menyetujuinya di manage_approval.php.
 * - Audit log dicatat otomatis oleh monitor di config.php (bagian 10).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/layout.php';

function crudLoadOptions(array $f)
{
    global $connect;
    if (isset($f['options'])) return $f['options'];
    $out = [];
    if (!empty($f['sql'])) {
        $rs = mysqli_query($connect, $f['sql']);
        while ($rs && ($r = mysqli_fetch_row($rs))) {
            $out[$r[0]] = $r[1];
        }
    }
    return $out;
}

/** Tampilkan nilai kolom terenkripsi; nilai lama (belum terenkripsi) dikembalikan apa adanya. */
function crudReveal($v)
{
    if ($v === null || $v === '') return $v;
    $d = decryptData((string)$v);
    return $d === null ? $v : $d;
}

function crudRun(array $c)
{
    global $connect;

    $module = $c['module'];
    $table  = $c['table'];
    $fields = $c['fields'];
    $soft   = !empty($c['soft']);     // tabel punya kolom is_active
    $title  = $c['title'];

    requirePermission($module, 'view');
    $canCreate  = canAccess($module, 'create');
    $canApprove = canAccess($module, 'approve');

    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    $self = basename($_SERVER['SCRIPT_NAME']);

    $byName = [];
    $options = [];
    foreach ($fields as $f) {
        $byName[$f['name']] = $f;
        if (($f['type'] ?? 'text') === 'select') {
            $options[$f['name']] = crudLoadOptions($f);
        }
    }

    $errors = [];
    $formRow = null;   // nilai form yang dikembalikan saat validasi gagal
    $draftRun = 0;     // ID draft yang sedang dieksekusi penyetuju (0 = input biasa)

    // ------------------------------------------------------------ POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
            http_response_code(400);
            die('Token keamanan tidak valid. Muat ulang halaman.');
        }
        // Eksekusi draft: hanya pemegang hak Setujui. $_POST diganti isi draft, lalu diproses
        // oleh jalur 'save' di bawah seperti input langsung.
        if ((int)($_POST['run_draft'] ?? 0) > 0) {
            requirePermission($module, 'approve');
            $did = (int)$_POST['run_draft'];
            $dst = mysqli_prepare($connect, "SELECT * FROM trx_drafts
                WHERE id = ? AND status = 'PENDING' AND module_name = ? AND target_page = ?");
            mysqli_stmt_bind_param($dst, 'iss', $did, $module, $self);
            mysqli_stmt_execute($dst);
            $drow = mysqli_fetch_assoc(mysqli_stmt_get_result($dst));
            if (!$drow) {
                $_SESSION['flash'] = ['err', 'Draft tidak ditemukan atau sudah diproses.'];
                header('Location: manage_approval.php');
                exit;
            }
            $payload = json_decode($drow['payload_json'], true);
            if (!is_array($payload)) $payload = [];
            $csrfTok = $_POST['csrf'];
            $present = is_array($payload['_present'] ?? null) ? $payload['_present'] : [];
            $pid = (int)($payload['id'] ?? 0);
            $existing = [];
            if ($pid > 0) {
                $ex = mysqli_prepare($connect, "SELECT * FROM `$table` WHERE id = ?");
                mysqli_stmt_bind_param($ex, 'i', $pid);
                mysqli_stmt_execute($ex);
                $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($ex)) ?: [];
            }
            $_POST = [];
            foreach ($fields as $f) {
                $n = $f['name'];
                if (in_array($n, $present, true)) {
                    if (isset($payload[$n])) {
                        $v = (string)$payload[$n];
                        if (!empty($f['encrypted'])) $v = (string)(decryptData($v) ?? '');
                        $_POST[$n] = $v;
                    }
                } elseif ($pid > 0) {
                    // kolom yang tidak terlihat oleh pengaju (mis. harga): pertahankan nilai tersimpan
                    if (isset($existing[$n])) {
                        $_POST[$n] = !empty($f['encrypted']) ? (string)crudReveal($existing[$n]) : (string)$existing[$n];
                    }
                } elseif (isset($f['default'])) {
                    $_POST[$n] = (string)$f['default'];
                }
            }
            $_POST['id']    = (string)$pid;
            $_POST['act']   = 'save';
            $_POST['csrf']  = $csrfTok;
            $draftRun = $did;
            if (!defined('WMS_SKIP_AUTOLOG')) define('WMS_SKIP_AUTOLOG', true);   // dicatat manual di bawah
        }

        $act = $_POST['act'] ?? 'save';
        $id  = (int)($_POST['id'] ?? 0);

        if ($act === 'toggle' && $soft && $id > 0) {
            requirePermission($module, 'approve');
            $st = mysqli_prepare($connect, "UPDATE `$table` SET is_active = 1 - is_active WHERE id = ?");
            mysqli_stmt_bind_param($st, 'i', $id);
            mysqli_stmt_execute($st);
            $_SESSION['flash'] = ['ok', 'Status data diperbarui.'];
            header('Location: ' . $self);
            exit;
        }

        if ($act === 'delete' && !$soft && $id > 0) {
            requirePermission($module, 'approve');
            $st = mysqli_prepare($connect, "DELETE FROM `$table` WHERE id = ?");
            mysqli_stmt_bind_param($st, 'i', $id);
            if (mysqli_stmt_execute($st)) {
                $_SESSION['flash'] = ['ok', 'Data dihapus.'];
            } else {
                $_SESSION['flash'] = ['err', 'Data tidak bisa dihapus karena masih dipakai data lain.'];
            }
            header('Location: ' . $self);
            exit;
        }

        if ($act === 'save') {
            requirePermission($module, 'create');
            $vals = [];
            $formRow = ['id' => $id];
            foreach ($fields as $f) {
                $n    = $f['name'];
                $type = $f['type'] ?? 'text';
                if ($type === 'bool') {
                    $vals[$n] = isset($_POST[$n]) ? '1' : '0';
                    $formRow[$n] = $vals[$n];
                    continue;
                }
                $raw = trim((string)($_POST[$n] ?? ''));
                $formRow[$n] = $raw;
                if ($raw === '') {
                    if (!empty($f['required'])) {
                        $errors[] = $f['label'] . ' wajib diisi.';
                    }
                    $vals[$n] = null;
                    continue;
                }
                if ($type === 'number' && !is_numeric($raw)) {
                    $errors[] = $f['label'] . ' harus berupa angka.';
                }
                if ($type === 'number' && is_numeric($raw) && isset($f['min']) && (float)$raw < $f['min']) {
                    $errors[] = $f['label'] . ' minimal ' . $f['min'] . '.';
                }
                if ($type === 'select' && !array_key_exists($raw, $options[$n])) {
                    $errors[] = $f['label'] . ' tidak valid.';
                }
                if ($type === 'date') {
                    $dt = DateTime::createFromFormat('Y-m-d', $raw);
                    if (!$dt || $dt->format('Y-m-d') !== $raw) $errors[] = $f['label'] . ' bukan tanggal yang valid.';
                }
                if (!empty($f['pattern']) && !preg_match($f['pattern'], $raw)) {
                    $errors[] = $f['label'] . ' format salah' . (!empty($f['hint']) ? ' (' . $f['hint'] . ')' : '') . '.';
                }
                if (!empty($f['maxlength']) && mb_strlen($raw) > $f['maxlength']) {
                    $errors[] = $f['label'] . ' maksimal ' . $f['maxlength'] . ' karakter.';
                }
                // Field bertanda 'encrypted' disimpan AES-256-GCM (kunci APP_KEY).
                $vals[$n] = !empty($f['encrypted']) ? encryptData($raw) : $raw;
            }

            // Validasi lintas kolom (opsional): callback menerima nilai terolah, mengembalikan daftar pesan galat.
            if (!empty($c['validate']) && is_callable($c['validate'])) {
                foreach ((array)$c['validate']($vals, $id) as $m) $errors[] = $m;
            }

            if (!$errors && !$canApprove && !$draftRun) {
                // Peran tanpa hak Setujui: simpan sebagai draft, data master belum berubah.
                if (!defined('WMS_SKIP_AUTOLOG')) define('WMS_SKIP_AUTOLOG', true);
                $payload = ['id' => $id, '_present' => array_keys($vals), '_title' => $title, '_display' => []];
                foreach ($fields as $f) {
                    $n = $f['name'];
                    $ftype = $f['type'] ?? 'text';
                    $v = $vals[$n] ?? null;
                    if ($ftype === 'bool') {
                        if ($v === '1') $payload[$n] = '1';
                        $payload['_display'][$f['label']] = $v === '1' ? 'Ya' : 'Tidak';
                        continue;
                    }
                    if ($v === null) continue;
                    $payload[$n] = $v;   // kolom 'encrypted' sudah berupa ciphertext
                    if (!empty($f['encrypted'])) $payload['_display'][$f['label']] = '(terenkripsi)';
                    elseif ($ftype === 'select') $payload['_display'][$f['label']] = $options[$n][$v] ?? $v;
                    else $payload['_display'][$f['label']] = $v;
                }
                $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
                if ($json === false || strlen($json) > 20000) {
                    $errors[] = 'Isian draft tidak valid atau terlalu besar.';
                } else {
                    $label = mb_substr($title . ($id > 0 ? " (ubah #$id)" : ' (baru)'), 0, 120);
                    $uid = (int)$_SESSION['user_id'];
                    $ds = mysqli_prepare($connect, "INSERT INTO trx_drafts (module_name, target_page, label, payload_json, created_by)
                        VALUES (?, ?, ?, ?, ?)");
                    mysqli_stmt_bind_param($ds, 'ssssi', $module, $self, $label, $json, $uid);
                    mysqli_stmt_execute($ds);
                    $newDraft = mysqli_insert_id($connect);
                    logActivity('DRAFT', $title, "Mengajukan draft $label #$newDraft (menunggu persetujuan)", $newDraft);
                    $_SESSION['flash'] = ['ok', "Draft #$newDraft diajukan dan menunggu persetujuan Kepala Gudang. Data master belum berubah."];
                    header('Location: ' . $self);
                    exit;
                }
            } elseif (!$errors) {
                if (!empty($c['before_save'])) {
                    $vals = $c['before_save']($vals);   // hook: hitung kolom turunan
                }
                $cols  = array_keys($vals);
                $types = str_repeat('s', count($cols));
                if ($draftRun) mysqli_begin_transaction($connect);
                if ($id > 0) {
                    $set = implode(', ', array_map(fn($k) => "`$k` = ?", $cols));
                    $sql = "UPDATE `$table` SET $set WHERE id = ?";
                    $st  = mysqli_prepare($connect, $sql);
                    $params = array_values($vals);
                    $params[] = $id;
                    mysqli_stmt_bind_param($st, $types . 'i', ...$params);
                } else {
                    $colList = implode(', ', array_map(fn($k) => "`$k`", $cols));
                    $ph = implode(', ', array_fill(0, count($cols), '?'));
                    $st = mysqli_prepare($connect, "INSERT INTO `$table` ($colList) VALUES ($ph)");
                    $params = array_values($vals);
                    mysqli_stmt_bind_param($st, $types, ...$params);
                }
                if ($st && mysqli_stmt_execute($st)) {
                    $savedId = $id > 0 ? $id : (int)mysqli_insert_id($connect);
                    if ($draftRun) {
                        $by = (int)$_SESSION['user_id'];
                        $res = mb_substr($title . ' #' . $savedId, 0, 80);
                        $cl = mysqli_prepare($connect, "UPDATE trx_drafts SET status = 'APPROVED', decided_by = ?,
                            decided_at = NOW(), result_trx_code = ? WHERE id = ? AND status = 'PENDING'");
                        mysqli_stmt_bind_param($cl, 'isi', $by, $res, $draftRun);
                        mysqli_stmt_execute($cl);
                        if (mysqli_stmt_affected_rows($cl) !== 1) {
                            mysqli_rollback($connect);
                            $errors[] = 'Draft ini sudah diproses oleh pengguna lain.';
                        } else {
                            mysqli_commit($connect);
                            logActivity($id > 0 ? 'UPDATE' : 'CREATE', $title,
                                "Menyetujui draft #$draftRun: " . ($id > 0 ? 'memperbarui' : 'menambahkan') . " $title ID: #$savedId", $savedId);
                            $_SESSION['flash'] = ['ok', "Draft #$draftRun disetujui: data master tersimpan."];
                            header('Location: ' . $self);
                            exit;
                        }
                    } else {
                        $_SESSION['flash'] = ['ok', $id > 0 ? 'Data diperbarui.' : 'Data ditambahkan.'];
                        header('Location: ' . $self);
                        exit;   // monitor audit di config.php mencatat CREATE/UPDATE
                    }
                } else {
                    if ($draftRun) mysqli_rollback($connect);
                    $dup = $connect->errno === 1062;
                    $errors[] = $dup ? 'Data dengan kode/nilai yang sama sudah ada.'
                                     : 'Data tidak dapat disimpan. Periksa isian Anda.';
                }
                if ($draftRun && $errors) {
                    $errors[] = "Draft #$draftRun tetap menunggu. Perbaiki isian lalu simpan manual, atau tolak draft di halaman Persetujuan.";
                }
            }
        }
    }

    // ------------------------------------------------------------ data form
    $editId = (int)($_GET['edit'] ?? 0);
    if ($formRow === null) {
        if ($editId > 0) {
            $st = mysqli_prepare($connect, "SELECT * FROM `$table` WHERE id = ?");
            mysqli_stmt_bind_param($st, 'i', $editId);
            mysqli_stmt_execute($st);
            $formRow = mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: null;
            if ($formRow) {
                foreach ($fields as $f) {
                    if (!empty($f['encrypted']) && isset($formRow[$f['name']])) {
                        $formRow[$f['name']] = crudReveal($formRow[$f['name']]);
                    }
                }
            }
        }
        if ($formRow === null) {
            $formRow = ['id' => 0];
            foreach ($fields as $f) {
                $formRow[$f['name']] = $f['default'] ?? (($f['type'] ?? '') === 'bool' ? 0 : '');
            }
        }
    }

    // ------------------------------------------------------------ data list
    $listSql = $c['list_sql'] ?? "SELECT * FROM `$table` ORDER BY id ASC";
    $rows = [];
    $rs = mysqli_query($connect, $listSql);
    while ($rs && ($r = mysqli_fetch_assoc($rs))) {
        $rows[] = $r;
    }

    // Pencarian teks pada kolom yang tampil + paginasi (25 baris per halaman)
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $rows = array_values(array_filter($rows, function ($r) use ($q, $c, $byName) {
            foreach ($c['columns'] as $key => $_) {
                $v = (string)($r[$key] ?? '');
                if (!empty($byName[$key]['encrypted'])) $v = (string)crudReveal($v);
                if (mb_stripos($v, $q) !== false) return true;
            }
            return false;
        }));
    }
    $perPage = 25;
    $total   = count($rows);
    $pages   = max(1, (int)ceil($total / $perPage));
    $page    = min(max(1, (int)($_GET['page'] ?? 1)), $pages);
    $rows    = array_slice($rows, ($page - 1) * $perPage, $perPage);

    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    renderHeader($title);
    ?>
<h2><?= e($title) ?></h2>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php foreach ($errors as $m): ?><div class="err"><?= e($m) ?></div><?php endforeach; ?>

<?php
// Draft yang menunggu persetujuan untuk halaman ini (pengaju: draft sendiri; penyetuju: semua)
$meId = (int)$_SESSION['user_id'];
$pq = mysqli_prepare($connect, "SELECT COUNT(*) FROM trx_drafts WHERE status = 'PENDING'
    AND module_name = ? AND target_page = ?" . ($canApprove ? '' : ' AND created_by = ?'));
if ($canApprove) mysqli_stmt_bind_param($pq, 'ss', $module, $self);
else mysqli_stmt_bind_param($pq, 'ssi', $module, $self, $meId);
mysqli_stmt_execute($pq);
$pendingDrafts = (int)mysqli_fetch_row(mysqli_stmt_get_result($pq))[0];
if ($pendingDrafts > 0 && (canAccess('inbound', 'create') || canAccess('outbound', 'create') || canAccess('bom', 'create') || $canApprove)): ?>
<div class="ok"><?= $pendingDrafts ?> draft data menunggu persetujuan. <a href="manage_approval.php">Buka halaman Persetujuan</a></div>
<?php endif; ?>
<?php if (!$canApprove && $canCreate): ?>
<div class="ok" style="background:#fff8e1;color:#7a5b00">Perubahan Anda disimpan sebagai draft dan baru berlaku setelah disetujui Kepala Gudang.</div>
<?php endif; ?>

<?php if ($canCreate): ?>
<div class="panel">
  <strong><?= (int)$formRow['id'] > 0 ? 'Ubah data #' . (int)$formRow['id'] : 'Tambah data baru' ?></strong>
  <form method="post" autocomplete="off" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
    <input type="hidden" name="act" value="save">
    <input type="hidden" name="id" value="<?= (int)$formRow['id'] ?>">
    <div class="grid">
    <?php foreach ($fields as $f):
        $n = $f['name']; $type = $f['type'] ?? 'text'; $val = $formRow[$n] ?? '';
        // Isian angka: buang nol desimal di belakang (7000.0000 -> 7000)
        if ($type === 'number' && is_string($val) && strpos($val, '.') !== false && is_numeric($val)) $val = rtrim(rtrim($val, '0'), '.'); ?>
      <?php if ($type === 'bool'): ?>
        <div class="chk"><input type="checkbox" id="f_<?= e($n) ?>" name="<?= e($n) ?>" value="1" <?= (int)$val === 1 ? 'checked' : '' ?>>
          <label for="f_<?= e($n) ?>" style="margin:0"><?= e($f['label']) ?></label></div>
      <?php elseif ($type === 'select'): ?>
        <div><label><?= e($f['label']) ?><?= !empty($f['required']) ? ' *' : '' ?></label>
          <select name="<?= e($n) ?>">
            <option value="">-- pilih --</option>
            <?php foreach ($options[$n] as $ov => $ol): ?>
              <option value="<?= e($ov) ?>" <?= (string)$val === (string)$ov ? 'selected' : '' ?>><?= e($ol) ?></option>
            <?php endforeach; ?>
          </select></div>
      <?php else: ?>
        <div><label><?= e($f['label']) ?><?= !empty($f['required']) ? ' *' : '' ?></label>
          <input type="<?= $type === 'number' ? 'number' : ($type === 'date' ? 'date' : 'text') ?>" name="<?= e($n) ?>" value="<?= e($val) ?>"
            <?= $type === 'number' ? 'step="' . e($f['step'] ?? '1') . '"' : '' ?>
            <?= isset($f['min']) ? 'min="' . e($f['min']) . '"' : '' ?>
            <?= !empty($f['maxlength']) ? 'maxlength="' . (int)$f['maxlength'] . '"' : '' ?>
            <?= !empty($f['placeholder']) ? 'placeholder="' . e($f['placeholder']) . '"' : '' ?>></div>
      <?php endif; ?>
    <?php endforeach; ?>
    </div>
    <div style="margin-top:14px">
      <button type="submit"><?= $canApprove ? 'Simpan' : 'Ajukan Draft' ?></button>
      <?php if ((int)$formRow['id'] > 0): ?><a class="btn sec" href="<?= e($self) ?>">Batal</a><?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="panel scroll">
  <form method="get" style="margin-bottom:10px">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Cari..." style="max-width:260px;display:inline-block">
    <button type="submit" class="sec">Cari</button>
    <?php if ($q !== ''): ?><a class="btn sec" href="<?= e($self) ?>">Reset</a><?php endif; ?>
    <small style="margin-left:8px"><?= (int)$total ?> data</small>
  </form>
  <table>
    <tr>
      <th>#</th>
      <?php foreach ($c['columns'] as $label): ?><th><?= e($label) ?></th><?php endforeach; ?>
      <?php if ($soft): ?><th>Status</th><?php endif; ?>
      <th>Aksi</th>
    </tr>
    <?php if (!$rows): ?>
      <tr><td colspan="<?= count($c['columns']) + 3 ?>">Belum ada data.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['id'] ?></td>
        <?php foreach ($c['columns'] as $key => $label):
            $v = $r[$key] ?? '';
            if (isset($byName[$key]) && ($byName[$key]['type'] ?? '') === 'bool') $v = (int)$v === 1 ? 'Ya' : 'Tidak';
            if (isset($byName[$key]) && !empty($byName[$key]['encrypted'])) $v = crudReveal($v);
            // Kolom rupiah: tampil 7.000 (titik ribuan), bukan 7000.0000
            if (!empty($c['money']) && in_array($key, $c['money'], true) && $v !== '' && is_numeric($v)) $v = number_format((float)$v, 0, ',', '.'); ?>
          <td><?= e($v) ?></td>
        <?php endforeach; ?>
        <?php if ($soft): ?>
          <td><span class="tag <?= (int)$r['is_active'] === 1 ? '' : 'off' ?>"><?= (int)$r['is_active'] === 1 ? 'Aktif' : 'Nonaktif' ?></span></td>
        <?php endif; ?>
        <td>
          <?php if ($canCreate): ?><a class="btn sec" href="<?= e($self) ?>?edit=<?= (int)$r['id'] ?>">Ubah</a><?php endif; ?>
          <?php if ($canApprove): ?>
            <form class="inline" method="post" onsubmit="return confirm('Lanjutkan?')">
              <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <?php if ($soft): ?>
                <input type="hidden" name="act" value="toggle">
                <button class="btn danger" type="submit"><?= (int)$r['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?></button>
              <?php else: ?>
                <input type="hidden" name="act" value="delete">
                <button class="btn danger" type="submit">Hapus</button>
              <?php endif; ?>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <?php if ($pages > 1): ?>
  <p style="margin:12px 0 0">
    <?php if ($page > 1): ?><a class="btn sec" href="<?= e($self) ?>?page=<?= $page - 1 ?>&amp;q=<?= urlencode($q) ?>">&laquo; Sebelumnya</a><?php endif; ?>
    <small style="margin:0 8px">Halaman <?= $page ?> / <?= $pages ?></small>
    <?php if ($page < $pages): ?><a class="btn sec" href="<?= e($self) ?>?page=<?= $page + 1 ?>&amp;q=<?= urlencode($q) ?>">Berikutnya &raquo;</a><?php endif; ?>
  </p>
  <?php endif; ?>
</div>
<?php
    renderFooter();
}