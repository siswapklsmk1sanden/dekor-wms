<?php
/**
 * Tabel tambahan v38: Reservasi Stok, Hutang Supplier (faktur, pembayaran, kredit retur).
 * Dibuat OTOMATIS (CREATE TABLE IF NOT EXISTS) saat halaman terkait dibuka, jadi database yang sudah
 * berjalan TIDAK perlu diimpor ulang. Untuk instalasi dengan user MySQL tanpa hak CREATE, jalankan
 * database\migration_v38.sql sekali lewat phpMyAdmin/HeidiSQL.
 * Tidak boleh dipanggil di tengah transaksi database (DDL memutus transaksi).
 */
require_once __DIR__ . '/config.php';

function wmsSchemaExtDdl()
{
    $ddl = [];

    $ddl['stock_reservations'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `stock_reservations` (
  `id` int NOT NULL AUTO_INCREMENT,
  `res_code` varchar(30) NOT NULL,
  `group_code` varchar(30) DEFAULT NULL COMMENT 'Reservasi dari satu BoM memakai kode kelompok yang sama',
  `item_id` int NOT NULL,
  `qty_total` decimal(18,4) NOT NULL,
  `qty_open` decimal(18,4) NOT NULL COMMENT 'Sisa yang masih ditahan',
  `reference_doc_no` varchar(60) NOT NULL COMMENT 'No. SPK / work order / invoice yang memesan',
  `needed_date` date DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL,
  `status` enum('ACTIVE','FULFILLED','RELEASED') NOT NULL DEFAULT 'ACTIVE',
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_by` int DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `close_note` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_res_code` (`res_code`),
  KEY `idx_res_item_status` (`item_id`,`status`),
  KEY `idx_res_ref` (`reference_doc_no`),
  KEY `idx_res_group` (`group_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

    $ddl['stock_reservation_allocs'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `stock_reservation_allocs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `reservation_id` int NOT NULL,
  `bin_id` int NOT NULL,
  `batch_id` bigint NOT NULL,
  `qty` decimal(18,4) NOT NULL COMMENT 'Jumlah yang saat ini ditahan di rak+lot ini (= bagian dari stock_balances.qty_reserved)',
  PRIMARY KEY (`id`),
  KEY `idx_alloc_res` (`reservation_id`),
  KEY `idx_alloc_bal` (`bin_id`,`batch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

    $ddl['supplier_terms'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `supplier_terms` (
  `supplier_id` int NOT NULL,
  `terms_days` smallint NOT NULL DEFAULT '30' COMMENT 'Termin pembayaran bawaan (hari)',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`supplier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

    $ddl['supplier_invoices'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `supplier_invoices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `inv_code` varchar(30) NOT NULL COMMENT 'Nomor internal FAK-yymmdd-xxxx',
  `supplier_invoice_no` varchar(60) NOT NULL COMMENT 'Nomor faktur dari supplier',
  `supplier_id` int NOT NULL,
  `po_id` int DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `terms_days` smallint NOT NULL DEFAULT '30',
  `due_date` date NOT NULL,
  `goods_idr` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'Nilai barang menurut faktur',
  `extra_idr` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'PPN / ongkir / potongan / pembulatan (boleh negatif)',
  `amount_idr` decimal(18,2) NOT NULL COMMENT 'Total tagihan = goods + extra',
  `po_value_idr` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'Nilai barang yang sama menurut harga PO',
  `paid_idr` decimal(18,2) NOT NULL DEFAULT '0.00',
  `credit_applied_idr` decimal(18,2) NOT NULL DEFAULT '0.00',
  `status` enum('UNPAID','PARTIAL','PAID','CANCELLED') NOT NULL DEFAULT 'UNPAID',
  `notes` varchar(300) DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cancelled_note` varchar(200) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inv_code` (`inv_code`),
  KEY `idx_inv_status_due` (`status`,`due_date`),
  KEY `idx_inv_supplier` (`supplier_id`),
  KEY `idx_inv_po` (`po_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

    $ddl['supplier_invoice_lines'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `supplier_invoice_lines` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `po_line_id` int DEFAULT NULL,
  `item_id` int NOT NULL,
  `qty` decimal(18,4) NOT NULL,
  `po_price_idr` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `invoice_price_idr` decimal(18,4) NOT NULL DEFAULT '0.0000',
  PRIMARY KEY (`id`),
  KEY `idx_invl_inv` (`invoice_id`),
  KEY `idx_invl_poline` (`po_line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

    $ddl['supplier_payments'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `supplier_payments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `paid_date` date NOT NULL,
  `amount_idr` decimal(18,2) NOT NULL,
  `method` varchar(30) NOT NULL DEFAULT 'Transfer',
  `ref_no` varchar(60) DEFAULT NULL,
  `notes` varchar(200) DEFAULT NULL,
  `is_void` tinyint(1) NOT NULL DEFAULT '0',
  `void_note` varchar(200) DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_pay_inv` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

    $ddl['supplier_credits'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `supplier_credits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `credit_code` varchar(30) NOT NULL COMMENT 'KRD-yymmdd-xxxx',
  `supplier_id` int NOT NULL,
  `trx_id` bigint DEFAULT NULL COMMENT 'Transaksi retur (inventory_transactions.id) bila berasal dari retur',
  `return_ref` varchar(40) DEFAULT NULL COMMENT 'RTR-xxxx',
  `item_id` int DEFAULT NULL,
  `batch_id` bigint DEFAULT NULL,
  `qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `unit_cost_idr` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `calc_idr` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'Nilai hitungan sistem = qty x harga lot',
  `amount_idr` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'Nilai yang diakui supplier (boleh beda dari hitungan)',
  `resolution` enum('CREDIT','REPLACEMENT','REFUND') NOT NULL DEFAULT 'CREDIT',
  `status` enum('OPEN','SETTLED','CANCELLED') NOT NULL DEFAULT 'OPEN',
  `supplier_doc_no` varchar(60) DEFAULT NULL COMMENT 'No. nota kredit / surat penggantian dari supplier',
  `settled_at` datetime DEFAULT NULL,
  `notes` varchar(300) DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_credit_code` (`credit_code`),
  KEY `idx_cr_supplier_status` (`supplier_id`,`status`),
  KEY `idx_cr_ref` (`return_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

    $ddl['supplier_credit_apps'] = <<<'SQL'
CREATE TABLE IF NOT EXISTS `supplier_credit_apps` (
  `id` int NOT NULL AUTO_INCREMENT,
  `credit_id` int NOT NULL,
  `invoice_id` int NOT NULL,
  `amount_idr` decimal(18,2) NOT NULL,
  `is_void` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_app_credit` (`credit_id`),
  KEY `idx_app_inv` (`invoice_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL;

    return $ddl;
}

/**
 * Kolom tambahan v39: PPN per baris faktur + lampiran scan faktur.
 * Ditambahkan sekali bila belum ada: [tabel, kolom, perintah ALTER].
 */
function wmsSchemaExtColumns()
{
    return [
        ['supplier_invoices', 'vat_idr', "ALTER TABLE `supplier_invoices` ADD COLUMN `vat_idr` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'PPN = jumlah PPN seluruh baris faktur' AFTER `goods_idr`"],
        ['supplier_invoices', 'attachment_file', "ALTER TABLE `supplier_invoices` ADD COLUMN `attachment_file` varchar(120) NULL DEFAULT NULL COMMENT 'Nama file scan faktur di admin/uploads/invoices'"],
        ['supplier_invoices', 'attachment_name', "ALTER TABLE `supplier_invoices` ADD COLUMN `attachment_name` varchar(150) NULL DEFAULT NULL"],
        ['supplier_invoices', 'attachment_at', "ALTER TABLE `supplier_invoices` ADD COLUMN `attachment_at` datetime NULL DEFAULT NULL"],
        ['supplier_invoice_lines', 'vat_pct', "ALTER TABLE `supplier_invoice_lines` ADD COLUMN `vat_pct` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'PPN (%) baris ini'"],
    ];
}

/** Tabel-tabel transaksi baru (dipakai Pembersih Data Uji). */
function wmsSchemaExtTables()
{
    return array_keys(wmsSchemaExtDdl());
}

/**
 * Pastikan tabel v38 ada dan hak akses modul 'payable' (Hutang Supplier) terisi.
 * Mengembalikan true bila siap; false bila gagal (alasannya ada di $GLOBALS['WMS_SCHEMA_ERR']).
 */
function wmsEnsureSchemaExt()
{
    global $connect;
    static $done = null;
    if ($done !== null) return $done;
    if (!empty($_SESSION['schema_ext_v39'])) return $done = true;

    $errs = [];
    foreach (wmsSchemaExtDdl() as $name => $sql) {
        try {
            if (!mysqli_query($connect, $sql)) $errs[] = "$name: " . mysqli_error($connect);
        } catch (Throwable $ex) {
            $errs[] = "$name: " . $ex->getMessage();
        }
    }
    foreach (wmsSchemaExtColumns() as [$tbl, $col, $alter]) {
        try {
            $rs = mysqli_query($connect, "SHOW COLUMNS FROM `$tbl` LIKE '$col'");
            if ($rs && mysqli_num_rows($rs) === 0 && !mysqli_query($connect, $alter)) $errs[] = "$tbl.$col: " . mysqli_error($connect);
        } catch (Throwable $ex) {
            $errs[] = "$tbl.$col: " . $ex->getMessage();
        }
    }
    if ($errs) {
        $GLOBALS['WMS_SCHEMA_ERR'] = implode('; ', $errs);
        return $done = false;
    }

    // Hak akses bawaan modul 'payable' (hanya sekali, selama belum ada barisnya sama sekali)
    try {
        $rs = mysqli_query($connect, "SELECT COUNT(*) FROM role_permissions WHERE module_name = 'payable'");
        $n  = $rs ? (int)mysqli_fetch_row($rs)[0] : 1;
        if ($n === 0) {
            $seed = [
                'auditor_finance' => [1, 1, 1, 0],
                'super_admin'     => [1, 1, 1, 0],
                'kepala_gudang'   => [1, 0, 0, 0],
                'buyer'           => [1, 0, 0, 0],
            ];
            foreach ($seed as $code => $p) {
                $st = mysqli_prepare($connect, "INSERT IGNORE INTO role_permissions
                    (role_id, module_name, can_view, can_create, can_approve, hide_price)
                    SELECT r.id, 'payable', ?, ?, ?, ? FROM roles r WHERE r.role_code = ?");
                mysqli_stmt_bind_param($st, 'iiiis', $p[0], $p[1], $p[2], $p[3], $code);
                mysqli_stmt_execute($st);
            }
        }
    } catch (Throwable $ex) {
        // hak akses bisa diatur manual di Sistem > Hak Akses
    }

    $_SESSION['schema_ext_v39'] = 1;
    return $done = true;
}

/** Hentikan halaman dengan pesan jelas bila tabel baru tidak bisa dibuat. */
function wmsRequireSchemaExt()
{
    if (!wmsEnsureSchemaExt()) {
        http_response_code(500);
        die('Tabel baru (v38) belum bisa dibuat otomatis: ' . e($GLOBALS['WMS_SCHEMA_ERR'] ?? '')
            . '. Jalankan database\\migration_v38.sql lalu database\\migration_v39.sql sekali lewat phpMyAdmin / HeidiSQL, lalu muat ulang halaman.');
    }
}
