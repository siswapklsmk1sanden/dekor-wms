<?php
/**
 * Barang Masuk (penerimaan PO supplier): membuat lot batch baru, menaruh stok
 * di rak, mencatat DEBIT ke buku besar, dan memperbarui harga pokok rata-rata.
 */
define('WMS_SKIP_AUTOLOG', true);
require_once __DIR__ . '/po.php';
require_once __DIR__ . '/svlk_helper.php';
require_once __DIR__ . '/photo_helper.php';
require_once __DIR__ . '/layout.php';
requirePermission('inbound', 'view');

$canCreate = canAccess('inbound', 'create');
$showCost  = canSeePrice('inbound');
$csrf      = stockCsrf();
$errors    = [];

/** Kategori barang -> tipe gudang yang boleh menerimanya (gudang QC selalu boleh). */
function inboundCheckBinType($categoryType, array $bin)
{
    $allowed = [
        'RAW_MATERIAL'  => ['RAW_MATERIAL'],
        'CHEMICAL'      => ['RAW_MATERIAL'],
        'PACKAGING'     => ['RAW_MATERIAL'],
        'HALF_FINISHED' => ['WIP_PRODUCTION'],
    ];
    if (!isset($allowed[$categoryType])) return;   // kategori lain: tidak dibatasi
    $ok = $allowed[$categoryType];
    if (in_array($bin['warehouse_type'], $ok, true) || $bin['warehouse_type'] === 'QUARANTINE_QC') return;
    throw new StockException("Rak {$bin['bin_code']} berada di gudang bertipe {$bin['warehouse_type']}; barang kategori $categoryType hanya boleh diterima ke gudang bertipe " . implode(' / ', $ok) . '.');
}
$form      = ['item_id' => '', 'bin_id' => '', 'supplier_id' => '', 'batch_number' => '',
              'received_date' => date('Y-m-d'), 'expiry_date' => '', 'qty' => '',
              'unit_cost' => '', 'moisture' => '', 'qc_status' => 'PASSED',
              'ref' => '', 'notes' => '', 'po_line_id' => '', 'photo_path' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && (int)($_GET['po_line'] ?? 0) > 0) {
    $pl = poFindLine((int)$_GET['po_line']);
    if ($pl && in_array($pl['status'], ['OPEN', 'PARTIAL'], true)) {
        $form['po_line_id']  = (string)(int)$pl['id'];
        $form['item_id']     = (string)(int)$pl['item_id'];
        $form['supplier_id'] = (string)(int)$pl['supplier_id'];
        $form['ref']         = $pl['po_number'];
        $form['qty']         = fmtQty(max(0.0, (float)$pl['qty_ordered'] - (float)$pl['qty_received']));
        if ($showCost && (float)$pl['unit_price_idr'] > 0) $form['unit_cost'] = fmtQty($pl['unit_price_idr']);
    }
}

// Jika foto melebihi post_max_size, PHP mengosongkan $_POST
$postTooBig = $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0;
if ($postTooBig) $errors[] = 'Ukuran data terlalu besar. Foto maksimal 8 MB (cek post_max_size di php.ini).';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$postTooBig) {
    stockCheckCsrf();

    // Foto kedatangan: disimpan SEBELUM draftGate agar path-nya ikut tersimpan di draft.
    // Saat draft disetujui (run_draft), path diambil dari isian draft.
    $isRun = (int)($_POST['run_draft'] ?? 0) > 0;
    if (!$isRun) {
        requirePermission('inbound', 'create');
        $photoPath = wmsValidArrivalPath($_POST['photo_path'] ?? '');
        $upl = $_FILES['photo'] ?? null;
        if ($upl && ($upl['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $newPhoto = wmsSaveArrivalPhoto($upl, $errors);
            if ($newPhoto) $photoPath = $newPhoto;
        }
        if ($photoPath === '' && INBOUND_PHOTO_REQUIRED && !$errors) {
            $errors[] = 'Foto barang yang datang wajib diunggah.';
        }
        $_POST['photo_path'] = $photoPath;
    }
    if ($isRun || !$errors) draftGate('inbound', 'Barang Masuk');

    foreach ($form as $k => $_) $form[$k] = trim((string)($_POST[$k] ?? ''));

    $itemId = (int)$form['item_id'];
    $binId  = (int)$form['bin_id'];
    $supId  = (int)$form['supplier_id'];
    $qty    = $form['qty'];

    // Penerimaan terhadap PO (opsional): supplier/ref/harga ikut PO bila tidak diisi
    $poLineId = (int)$form['po_line_id'];
    $poPre = null;
    if ($poLineId) {
        $poPre = poFindLine($poLineId);
        if (!$poPre) {
            $errors[] = 'Baris PO tidak ditemukan.';
        } else {
            if ($itemId !== (int)$poPre['item_id']) $errors[] = 'Barang yang dipilih tidak sesuai dengan baris PO ' . $poPre['po_number'] . '.';
            if ($supId === 0) { $supId = (int)$poPre['supplier_id']; $form['supplier_id'] = (string)$supId; }
            elseif ($supId !== (int)$poPre['supplier_id']) $errors[] = 'Supplier tidak sama dengan supplier pada PO ' . $poPre['po_number'] . '.';
            if ($form['ref'] === '') $form['ref'] = $poPre['po_number'];
        }
    }

    $st = mysqli_prepare($connect, "SELECT i.*, c.requires_expiry_date, c.category_type
        FROM items i JOIN categories c ON c.id = i.category_id WHERE i.id = ? AND i.is_active = 1");
    mysqli_stmt_bind_param($st, 'i', $itemId);
    mysqli_stmt_execute($st);
    $item = mysqli_fetch_assoc(mysqli_stmt_get_result($st));

    if (!$item) $errors[] = 'Barang wajib dipilih.';
    if (!$binId) $errors[] = 'Rak tujuan wajib dipilih.';
    if (!is_numeric($qty) || (float)$qty <= 0) $errors[] = 'Jumlah harus lebih dari 0.';
    if ($form['batch_number'] === '' || mb_strlen($form['batch_number']) > 48) {
        $errors[] = 'No. lot wajib diisi (maks. 48 karakter).';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['received_date'])) {
        $errors[] = 'Tanggal terima tidak valid.';
    } elseif ($form['received_date'] > date('Y-m-d')) {
        $errors[] = 'Tanggal terima tidak boleh di masa depan.';
    }

    $expiry = null;
    if ($form['expiry_date'] !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $form['expiry_date'])) {
            $errors[] = 'Tanggal kedaluwarsa tidak valid.';
        } else {
            $expiry = $form['expiry_date'];
            if ($expiry <= $form['received_date']) $errors[] = 'Tanggal kedaluwarsa harus setelah tanggal terima.';
        }
    }
    if ($item && (int)$item['requires_expiry_date'] && !$expiry) {
        $errors[] = 'Kategori barang ini wajib memiliki tanggal kedaluwarsa.';
    }

    $mc = null;
    if ($form['moisture'] !== '') {
        if (!is_numeric($form['moisture']) || $form['moisture'] < 0 || $form['moisture'] > 100) {
            $errors[] = 'Kadar air (MC) harus antara 0 - 100.';
        } else {
            $mc = (float)$form['moisture'];
        }
    }
    if ($item && $item['category_type'] === 'RAW_MATERIAL' && $mc === null) {
        $errors[] = 'Kadar air (MC) wajib diisi untuk bahan baku kayu.';
    }

    $qc = in_array($form['qc_status'], ['PASSED', 'HOLD_QUARANTINE'], true) ? $form['qc_status'] : 'PASSED';
    $autoHold = false;
    if ($mc !== null && $mc > 12 && $qc === 'PASSED') {
        $qc = 'HOLD_QUARANTINE';   // MC kayu oven harus <= 12%
        $autoHold = true;
    }

    $supplier = null;
    if ($supId) {
        $st = mysqli_prepare($connect, "SELECT * FROM suppliers WHERE id = ? AND is_active = 1");
        mysqli_stmt_bind_param($st, 'i', $supId);
        mysqli_stmt_execute($st);
        $supplier = mysqli_fetch_assoc(mysqli_stmt_get_result($st));
        if (!$supplier) $errors[] = 'Supplier tidak valid.';
    }
    if ($item && $item['category_type'] === 'RAW_MATERIAL') {
        if (!$supplier) $errors[] = 'Supplier wajib diisi untuk bahan baku kayu.';
        elseif (($svlkMsg = svlkProblem($supplier)) !== null) $errors[] = $svlkMsg . '; kayu tidak boleh diterima.';
    }

    if ($item && $showCost && $form['unit_cost'] !== '') {
        if (!is_numeric($form['unit_cost']) || (float)$form['unit_cost'] < 0) $errors[] = 'Harga satuan tidak valid.';
    }
    $cost = ($item && $showCost && $form['unit_cost'] !== '' && is_numeric($form['unit_cost']))
        ? (float)$form['unit_cost'] : (float)($item['moving_avg_cost_idr'] ?? 0);
    if ($poPre && !($showCost && $form['unit_cost'] !== '') && (float)$poPre['unit_price_idr'] > 0) {
        $cost = (float)$poPre['unit_price_idr'];   // harga PO (peran tanpa akses harga tetap memakainya)
    }

    if (!$errors) {
        try {
            stockBegin();
            $poLine = $poLineId ? poLockForReceipt($poLineId, $itemId, (float)$qty) : null;
            $bin = stockGetBin($binId);
            inboundCheckBinType($item['category_type'], $bin);
            stockCheckCapacity($bin, (float)$qty * (float)$item['unit_cbm']);

            $ins = mysqli_prepare($connect, "INSERT INTO item_batches
                (item_id, batch_number, supplier_id, received_date, expiry_date,
                 unit_cost_idr, moisture_content_pct, qc_status, arrival_photo_path, arrival_photo_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $photoRel  = wmsValidArrivalPath($form['photo_path']);
            $photoBind = $photoRel !== '' ? $photoRel : null;   // draft lama (sebelum fitur foto) tetap bisa disetujui
            $photoAt   = $photoRel !== '' ? wmsPhotoTimeFromPath($photoRel) : null;   // hari/tanggal/jam:menit:detik foto diunggah
            $supBind = $supId ?: null;
            $costS = number_format($cost, 4, '.', '');
            $mcS = $mc === null ? null : number_format($mc, 2, '.', '');
            mysqli_stmt_bind_param($ins, 'isisssssss', $itemId, $form['batch_number'], $supBind,
                $form['received_date'], $expiry, $costS, $mcS, $qc, $photoBind, $photoAt);
            if (!mysqli_stmt_execute($ins)) {
                if (mysqli_errno($connect) === 1062) throw new StockException('No. lot sudah dipakai untuk barang ini.');
                throw new StockException('Gagal menyimpan lot: ' . mysqli_error($connect));
            }
            $batchId = mysqli_insert_id($connect);

            [$trxId, $trxCode] = stockCreateTrx('INBOUND_PURCHASE', $form['ref'], $form['notes']);
            stockMove($trxId, $itemId, $bin, $batchId, (float)$qty, $cost);
            if ($qc === 'PASSED') stockUpdateMovingAvg($itemId, (float)$qty, $cost);
            if ($poLine) poRecordReceipt($poLine, $trxId, (float)$qty);
            stockCommit();

            logActivity('CREATE', 'Inbound',
                "Penerimaan $trxCode: {$item['sku']} x " . fmtQty($qty) . " lot {$form['batch_number']} ke {$bin['bin_code']} (QC $qc)" . ($poLine ? " [PO {$poLine['po_number']}]" : ''), $trxId);
            $_SESSION['inbound_done'] = [
                'trx' => $trxCode, 'sku' => $item['sku'], 'name' => $item['item_name'], 'qty' => (string)$qty,
                'lot' => $form['batch_number'], 'bin' => $bin['bin_code'], 'qc' => $qc,
                'at' => $photoAt, 'po_id' => $poLine ? (int)$poLine['po_id'] : 0,
                'po_no' => $poLine ? $poLine['po_number'] : '',
            ];
            $_SESSION['flash'] = ['ok', "Penerimaan tercatat: $trxCode" .
                ($autoHold ? ' - MC > 12%, lot otomatis masuk HOLD_QUARANTINE.' : '.') .
                ($poLine ? " Tercatat pada PO {$poLine['po_number']}." : '')];
            header('Location: manage_inbound.php');
            exit;
        } catch (StockException $ex) {
            stockRollback();
            $errors[] = $ex->getMessage();
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$done = $_SESSION['inbound_done'] ?? null;
unset($_SESSION['inbound_done']);

// Foto acuan dari Buyer untuk baris PO/SPK yang masih terbuka (pembanding saat barang datang)
$refMap = [];
$rsRef = mysqli_query($connect, "SELECT l.id AS po_line_id, i.reference_photo_path, i.item_name, i.specification, p.spk_number, p.deliver_by
    FROM purchase_order_lines l JOIN purchase_orders p ON p.id = l.po_id JOIN items i ON i.id = l.item_id
    WHERE p.status IN ('OPEN','PARTIAL') ORDER BY l.id");
while ($rsRef && ($rr = mysqli_fetch_assoc($rsRef))) {
    $lid = (int)$rr['po_line_id'];
    $ph = wmsValidRequestPath($rr['reference_photo_path']);
    if ($ph === '' || isset($refMap[$lid])) continue;
    $refMap[$lid] = ['photo' => $ph, 'name' => $rr['item_name'], 'spec' => (string)$rr['specification'],
                     'spk' => (string)($rr['spk_number'] ?: ''), 'by' => (string)$rr['deliver_by']];
}

$items = mysqli_fetch_all(mysqli_query($connect, "SELECT i.id, i.sku, i.barcode_ean13, i.item_name, u.unit_code
    FROM items i JOIN units u ON u.id = i.unit_id
    JOIN categories c ON c.id = i.category_id
    WHERE i.is_active = 1 AND c.category_type <> 'FINISHED_GOOD' ORDER BY i.sku"), MYSQLI_ASSOC);
$suppliers = mysqli_fetch_all(mysqli_query($connect, "SELECT id, supplier_code, company_name, is_svlk_certified, svlk_expiry_date
    FROM suppliers WHERE is_active = 1 ORDER BY company_name"), MYSQLI_ASSOC);
$bins = stockBinOptions();
$poLines = poOpenLines();
$recent = mysqli_fetch_all(mysqli_query($connect, "SELECT t.trx_code, t.created_at, t.reference_doc_no,
        i.sku, i.item_name, b.batch_number, b.qc_status, b.arrival_photo_path, b.arrival_photo_at, bn.bin_code, l.signed_qty, u.unit_code, uu.username
    FROM inventory_transactions t
    JOIN inventory_ledger_entries l ON l.transaction_id = t.id
    JOIN items i ON i.id = l.item_id JOIN units u ON u.id = i.unit_id
    JOIN item_batches b ON b.id = l.batch_id JOIN storage_bins bn ON bn.id = l.bin_id
    JOIN users uu ON uu.id = t.created_by
    WHERE t.trx_type = 'INBOUND_PURCHASE' ORDER BY t.id DESC LIMIT 20"), MYSQLI_ASSOC);

$curPhoto = wmsValidArrivalPath($form['photo_path']);

renderHeader('Barang Masuk');
?>
<h2>Barang Masuk (Penerimaan Supplier)</h2>
<?php if ($flash): ?><div class="<?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>
<?php foreach ($errors as $er): ?><div class="err"><?= e($er) ?></div><?php endforeach; ?>

<?php if ($done): ?>
<div class="panel" style="border-left:4px solid #1E6B34">
  <strong style="font-size:16px">Barang berhasil masuk: <?= e($done['sku']) ?> &times; <?= e(fmtQty($done['qty'])) ?></strong>
  <p style="margin:6px 0 10px;color:#555;font-size:13.5px"><?= e($done['name']) ?> &middot; Lot <b><?= e($done['lot']) ?></b> &middot; Rak <b><?= e($done['bin']) ?></b> &middot; No. transaksi <b><?= e($done['trx']) ?></b>
    <?php if ($done['at']): ?><br>Waktu kedatangan tercatat: <b><?= e(wmsFmtWaktu($done['at'])) ?></b><?php endif; ?></p>
  <?php if ($done['qc'] === 'PASSED'): ?>
    <p style="margin:0 0 6px"><span class="tag">TERSEDIA</span> Stok ini sudah bisa dipakai. Lihat di menu:</p>
  <?php else: ?>
    <p style="margin:0 0 6px"><span class="tag off">DITAHAN QC</span> Stok belum tersedia sampai QC memutuskan LULUS. Lihat di menu:</p>
  <?php endif; ?>
  <ul style="margin:0;padding-left:20px;line-height:1.7">
    <?php if ($done['qc'] === 'PASSED' && canAccess('catalog', 'view')): ?><li><a href="manage_stock_balance.php?q=<?= urlencode($done['sku']) ?>"><b>Saldo Stok</b></a> (Stok &amp; Laporan): jumlah tersedia per rak, siap untuk Barang Keluar / Produksi</li><?php endif; ?>
    <?php if ($done['qc'] !== 'PASSED'): ?><li><a href="manage_qc.php"><b>Karantina QC</b></a> (Transaksi): lot menunggu keputusan LULUS / TOLAK</li><?php endif; ?>
    <?php if (canAccess('catalog', 'view')): ?><li><a href="manage_batch.php"><b>Lot Batch</b></a> (Stok &amp; Laporan): lot, foto, dan waktu kedatangan</li>
    <li><a href="manage_ledger.php"><b>Buku Besar</b></a> (Stok &amp; Laporan): jurnal DEBIT masuk</li><?php endif; ?>
    <?php if ($done['po_id']): ?><li><a href="manage_po.php?id=<?= (int)$done['po_id'] ?>"><b>Purchase Order</b> <?= e($done['po_no']) ?></a>: jumlah diterima dan status PO ikut berubah</li><?php endif; ?>
    <li><a href="print_trx.php?code=<?= urlencode($done['trx']) ?>" target="_blank"><b>Cetak Bukti Penerimaan</b></a></li>
  </ul>
</div>
<?php endif; ?>

<?php if ($canCreate): ?>
<div class="panel" style="text-align:center">
  <div style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:.8px">Waktu saat ini (WIB): akan dicatat pada foto barang yang datang</div>
  <div id="liveClock" style="font:600 26px Outfit,Inter,Arial,sans-serif;color:#0F172A;margin:6px 0 2px">&nbsp;</div>
  <small style="color:#666">Hari, tanggal, tahun, jam, menit, dan detik dicap otomatis oleh server pada foto saat foto diunggah, bukan dari jam HP/komputer.</small>
</div>
<div class="panel" id="refBox" style="display:none;border-left:4px solid #DC2626"></div>
<?php renderScanBox(); ?>
<div class="panel">
  <strong>Terima barang baru</strong>
  <form method="post" enctype="multipart/form-data" autocomplete="off" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
    <div class="grid">
      <div style="grid-column:1/-1"><label>Terima dari PO (opsional)</label>
        <select name="po_line_id" id="poLineSel"><option value="">- tanpa PO -</option>
        <?php foreach ($poLines as $pl): ?><option value="<?= (int)$pl['id'] ?>" data-item="<?= (int)$pl['item_id'] ?>" data-sup="<?= (int)$pl['supplier_id'] ?>" data-po="<?= e($pl['po_number']) ?>" data-rem="<?= e(fmtQty((float)$pl['qty_ordered'] - (float)$pl['qty_received'])) ?>" <?= (string)$pl['id'] === $form['po_line_id'] ? 'selected' : '' ?>><?= e((!empty($pl['spk_number']) ? $pl['spk_number'] . ' / ' : '') . $pl['po_number'] . ' - ' . $pl['sku'] . ' - sisa ' . fmtQty((float)$pl['qty_ordered'] - (float)$pl['qty_received']) . ' ' . $pl['unit_code']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label>Barang *</label>
        <select name="item_id" required><option value="">- pilih -</option>
        <?php foreach ($items as $i): ?><option value="<?= (int)$i['id'] ?>" data-ean="<?= e($i['barcode_ean13'] ?? '') ?>" <?= (string)$i['id'] === $form['item_id'] ? 'selected' : '' ?>><?= e($i['sku'] . ' - ' . $i['item_name'] . ' (' . $i['unit_code'] . ')') ?></option><?php endforeach; ?>
        </select></div>
      <div><label>Rak tujuan *</label>
        <select name="bin_id" required><option value="">- pilih -</option>
        <?php foreach ($bins as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (string)$b['id'] === $form['bin_id'] ? 'selected' : '' ?>><?= e($b['bin_code'] . ' - ' . $b['zone_name']) ?></option><?php endforeach; ?>
        </select></div>
      <div><label>Supplier</label>
        <select name="supplier_id"><option value="">- pilih -</option>
        <?php foreach ($suppliers as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (string)$s['id'] === $form['supplier_id'] ? 'selected' : '' ?>><?= e($s['company_name'] . svlkLabel($s)) ?></option><?php endforeach; ?>
        </select></div>
      <div><label>No. lot batch *</label><input type="text" name="batch_number" maxlength="48" value="<?= e($form['batch_number']) ?>" placeholder="BATCH-2026-TEAK-02" required></div>
      <div><label>Jumlah *</label><input type="number" name="qty" step="0.0001" min="0.0001" value="<?= e($form['qty']) ?>" required></div>
      <div><label>Tanggal terima *</label><input type="date" name="received_date" max="<?= date('Y-m-d') ?>" value="<?= e($form['received_date']) ?>" required style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px"></div>
      <div><label>Kedaluwarsa (bahan kimia)</label><input type="date" name="expiry_date" value="<?= e($form['expiry_date']) ?>" style="width:100%;padding:8px;border:1px solid #ccc;border-radius:6px"></div>
      <div><label>Kadar air MC % (kayu)</label><input type="number" name="moisture" step="0.01" min="0" max="100" value="<?= e($form['moisture']) ?>"></div>
      <?php if ($showCost): ?><div><label>Harga satuan (IDR)</label><input type="number" name="unit_cost" step="0.01" min="0" value="<?= e($form['unit_cost']) ?>" placeholder="kosong = harga rata-rata"></div><?php endif; ?>
      <div><label>Status QC</label>
        <select name="qc_status">
          <option value="PASSED" <?= $form['qc_status'] === 'PASSED' ? 'selected' : '' ?>>PASSED (lolos)</option>
          <option value="HOLD_QUARANTINE" <?= $form['qc_status'] === 'HOLD_QUARANTINE' ? 'selected' : '' ?>>HOLD_QUARANTINE (tahan QC)</option>
        </select></div>
      <div><label>No. PO / Surat Jalan</label><input type="text" name="ref" maxlength="60" value="<?= e($form['ref']) ?>"></div>
      <div><label>Catatan</label><input type="text" name="notes" maxlength="500" value="<?= e($form['notes']) ?>"></div>
      <div style="grid-column:1/-1"><label>Foto barang yang datang <?= INBOUND_PHOTO_REQUIRED ? '*' : '' ?></label>
        <input type="hidden" name="photo_path" value="<?= e($curPhoto) ?>">
        <div style="position:relative">
          <input type="file" name="photo" id="photoMain" accept="image/*" tabindex="-1" <?= (INBOUND_PHOTO_REQUIRED && $curPhoto === '') ? 'required' : '' ?> style="position:absolute;left:0;top:0;width:1px;height:1px;opacity:0;pointer-events:none">
          <input type="file" id="photoCam" accept="image/*" capture="environment" tabindex="-1" style="display:none">
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <button type="button" id="btnCam">Ambil dengan Kamera</button>
            <button type="button" class="sec" id="btnFile">Pilih dari Galeri / File</button>
            <span id="photoName" style="font-size:13px;color:#555">Belum ada foto dipilih</span>
          </div>
          <div id="photoPrev" style="display:none;margin-top:8px"><img alt="Pratinjau foto" style="max-width:200px;max-height:150px;border-radius:6px;border:1px solid #ccc"></div>
        </div>
        <?php if ($curPhoto !== ''): ?>
          <div style="margin-top:6px"><small>Foto sudah diunggah (pilih file lagi untuk menggantinya):</small><br>
            <a href="<?= e($curPhoto) ?>" target="_blank"><img src="<?= e($curPhoto) ?>" alt="Foto barang" style="max-width:160px;max-height:120px;border-radius:6px;border:1px solid #ccc"></a></div>
        <?php endif; ?>
        <small style="color:#666;display:block;margin-top:4px">JPG/PNG/WEBP, otomatis dikecilkan. Pilih salah satu: ambil foto langsung dengan kamera, atau pilih dari galeri / file.</small></div>
    </div>
    <p style="margin:14px 0 0"><button type="submit"><?= canAccess('inbound', 'approve') ? 'Simpan Penerimaan' : 'Ajukan Draft Penerimaan' ?></button></p>
  </form>
</div>
<?php endif; ?>

<div class="panel scroll">
  <strong>20 penerimaan terakhir</strong>
  <table>
    <tr><th>Waktu</th><th>No. Transaksi</th><th>Barang</th><th>Lot</th><th>Rak</th><th>Jumlah</th><th>QC</th><th>Ref</th><th>Oleh</th><th>Foto &amp; waktu datang</th></tr>
    <?php if (!$recent): ?><tr><td colspan="10">Belum ada penerimaan.</td></tr><?php endif; ?>
    <?php foreach ($recent as $r): ?>
      <tr><td><?= e($r['created_at']) ?></td><td><a href="print_trx.php?code=<?= urlencode($r['trx_code']) ?>" target="_blank" title="Cetak bukti"><?= e($r['trx_code']) ?></a></td>
        <td><?= e($r['sku']) ?><br><small><?= e($r['item_name']) ?></small></td>
        <td><?= e($r['batch_number']) ?></td><td><?= e($r['bin_code']) ?></td>
        <td><?= e(fmtQty($r['signed_qty'])) ?> <?= e($r['unit_code']) ?></td>
        <td><span class="tag <?= $r['qc_status'] === 'PASSED' ? '' : 'off' ?>"><?= e($r['qc_status']) ?></span></td>
        <td><?= e($r['reference_doc_no']) ?></td><td><?= e($r['username']) ?></td>
        <td><?php $ph = wmsValidArrivalPath($r['arrival_photo_path'] ?? ''); if ($ph !== ''): ?><a href="<?= e($ph) ?>" target="_blank"><img src="<?= e($ph) ?>" alt="Foto" style="width:48px;height:36px;object-fit:cover;border-radius:4px;border:1px solid #ccc"></a><?php else: ?>-<?php endif; ?>
          <?php $at = $r['arrival_photo_at'] ?? wmsPhotoTimeFromPath($r['arrival_photo_path'] ?? ''); if ($at): ?><br><small><?= e(wmsFmtWaktu($at)) ?></small><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<script>
(function () {
  // Jam hidup: memakai waktu server (selisih dihitung sekali), tampil dalam WIB (UTC+7)
  var clock = document.getElementById('liveClock');
  if (clock) {
    var off = <?= (int)(time() * 1000) ?> - Date.now();
    var H = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    var B = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    var tick = function () {
      var d = new Date(Date.now() + off + 7 * 3600000);
      clock.textContent = H[d.getUTCDay()] + ', ' + d.getUTCDate() + ' ' + B[d.getUTCMonth()] + ' ' + d.getUTCFullYear() +
        ' \u00B7 ' + p(d.getUTCHours()) + ':' + p(d.getUTCMinutes()) + ':' + p(d.getUTCSeconds()) + ' WIB';
    };
    tick(); setInterval(tick, 1000);
  }

  var REF = <?= json_encode($refMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  var box = document.getElementById('refBox');
  function mk(tag, txt, css) { var n = document.createElement(tag); if (txt) n.textContent = txt; if (css) n.style.cssText = css; return n; }
  function showRef(lineId) {
    if (!box) return;
    var r = REF[lineId];
    box.innerHTML = '';
    if (!r) { box.style.display = 'none'; return; }
    box.style.display = 'flex'; box.style.gap = '16px'; box.style.alignItems = 'center'; box.style.flexWrap = 'wrap';
    var a = document.createElement('a'); a.href = r.photo; a.target = '_blank';
    var img = document.createElement('img'); img.src = r.photo; img.alt = 'Foto acuan Buyer';
    img.style.cssText = 'width:150px;height:112px;object-fit:cover;border-radius:8px;border:1px solid #ccc';
    a.appendChild(img); box.appendChild(a);
    var t = mk('div');
    t.appendChild(mk('div', 'Foto acuan dari Buyer: barang seharusnya seperti ini', 'font-weight:700;color:#B91C1C'));
    t.appendChild(mk('div', r.name, 'margin-top:4px'));
    if (r.spec) t.appendChild(mk('div', 'Spesifikasi: ' + r.spec, 'font-size:13px;color:#555'));
    if (r.spk) t.appendChild(mk('div', 'SPK ' + r.spk + (r.by ? ' \u00B7 batas kirim ' + r.by : ''), 'font-size:13px;color:#555'));
    t.appendChild(mk('div', 'Bandingkan dengan barang yang dibawa supplier sebelum memotret.', 'font-size:12.5px;color:#777;margin-top:4px'));
    box.appendChild(t);
  }

  var sel = document.getElementById('poLineSel');
  if (!sel) return;
  showRef(sel.value);
  sel.addEventListener('change', function () {
    var o = sel.options[sel.selectedIndex], f = sel.form;
    showRef(sel.value);
    if (!o || !o.value) return;
    f.item_id.value = o.getAttribute('data-item');
    f.supplier_id.value = o.getAttribute('data-sup');
    f.ref.value = o.getAttribute('data-po');
    f.qty.value = o.getAttribute('data-rem');
  });
})();
</script>
<script>
(function () {
  var main = document.getElementById('photoMain'), cam = document.getElementById('photoCam');
  var bCam = document.getElementById('btnCam'), bFile = document.getElementById('btnFile');
  var nameEl = document.getElementById('photoName'), prev = document.getElementById('photoPrev');
  if (!main || !cam || !bCam || !bFile) return;
  function show() {
    var f = main.files && main.files[0];
    if (!f) { nameEl.textContent = 'Belum ada foto dipilih'; prev.style.display = 'none'; return; }
    nameEl.textContent = f.name;
    var img = prev.querySelector('img');
    if (img.getAttribute('data-url')) URL.revokeObjectURL(img.getAttribute('data-url'));
    var u = URL.createObjectURL(f);
    img.setAttribute('data-url', u); img.src = u; prev.style.display = 'block';
  }
  bCam.addEventListener('click', function () { cam.click(); });
  bFile.addEventListener('click', function () { main.click(); });
  main.addEventListener('change', show);
  cam.addEventListener('change', function () {
    if (!cam.files || !cam.files[0]) return;
    try {
      var dt = new DataTransfer();
      dt.items.add(cam.files[0]);
      main.files = dt.files;            // foto kamera dikirim lewat kolom "photo" yang sama
    } catch (e) {
      alert('Browser ini tidak bisa memakai foto kamera di sini. Gunakan "Pilih dari Galeri / File".');
      cam.value = '';
      return;
    }
    show();
  });
})();
</script>
<?php renderFooter();