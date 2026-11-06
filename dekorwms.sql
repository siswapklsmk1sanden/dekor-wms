-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Waktu pembuatan: 06 Nov 2026 pada 01.55
-- Versi server: 8.0.30
-- Versi PHP: 8.5.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Basis data: `dekorwms`
--

-- --------------------------------------------------------

--
-- Struktur dari tabel `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint UNSIGNED NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `event_action` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_table` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_id` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload_json` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '127.0.0.1',
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CLI/System',
  `prev_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `current_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime(6) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `event_action`, `entity_table`, `entity_id`, `payload_json`, `ip_address`, `user_agent`, `prev_hash`, `current_hash`, `created_at`) VALUES
(1, 5, 'LOGIN_FAILED', 'users', '5', '{\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '0000000000000000000000000000000000000000000000000000000000000000', '732cad04e7cbd099a52a05be8d3b38bdf1180f34bf61e21b88982bc27fd7bb7e', '2026-11-05 08:56:10.380726'),
(2, 5, 'LOGIN_FAILED', 'users', '5', '{\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '732cad04e7cbd099a52a05be8d3b38bdf1180f34bf61e21b88982bc27fd7bb7e', '54194e5bed397a817bd32b977ac99dc9a962f95772c2e3c8c46abbe72c24ff53', '2026-11-05 09:00:02.103201'),
(3, 5, 'LOGIN_FAILED', 'users', '5', '{\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '54194e5bed397a817bd32b977ac99dc9a962f95772c2e3c8c46abbe72c24ff53', '045adc52bebc08687e10029275222cfa659027501f913a32a5cb42ed4b7ad0c2', '2026-11-05 09:00:55.914901'),
(4, 5, 'LOGIN_FAILED', 'users', '5', '{\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '045adc52bebc08687e10029275222cfa659027501f913a32a5cb42ed4b7ad0c2', 'c87c3ddcdf5a7f829639753a63b0bf115ccc04d6f37823bb4417d1ce58f7ce4c', '2026-11-05 09:01:30.728228'),
(5, 5, 'LOGIN_FAILED', 'users', '5', '{\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', 'c87c3ddcdf5a7f829639753a63b0bf115ccc04d6f37823bb4417d1ce58f7ce4c', '0a16bfa6fb4f52109e11882b7bfa625a87953afefc90df6bd17b82438885ff3a', '2026-11-05 09:01:35.803482'),
(6, 5, 'LOGIN_BLOCKED', 'users', '5', '{\"reason\":\"locked\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '0a16bfa6fb4f52109e11882b7bfa625a87953afefc90df6bd17b82438885ff3a', '4557a5ca3e485b9d8881ae4f4242c596ebc4de492cdd0415fb18b42fba471d11', '2026-11-05 09:01:43.533610'),
(7, 2, 'LOGIN_SUCCESS', 'users', '2', '{\"role\":\"STAFF_PPIC\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '4557a5ca3e485b9d8881ae4f4242c596ebc4de492cdd0415fb18b42fba471d11', '6178be7de705e8d256a811f9f8a053f7d9f0c0d05a78a5158bcae67b68b0684b', '2026-11-05 09:25:35.743247'),
(8, 2, 'LOGOUT', 'users', '2', '[]', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '6178be7de705e8d256a811f9f8a053f7d9f0c0d05a78a5158bcae67b68b0684b', '8b05c8cd2c0f06ab158ece7e6cd5f988cd07840052b1630bfc348a9e47f4947a', '2026-11-05 09:27:16.887596'),
(9, 5, 'LOGIN_FAILED', 'users', '5', '{\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '8b05c8cd2c0f06ab158ece7e6cd5f988cd07840052b1630bfc348a9e47f4947a', '0ee9e2260d2f61762b93d064cf5e901ca9f882bb83351ae7332718f0356e3d05', '2026-11-05 09:27:52.733375'),
(10, 5, 'LOGIN_FAILED', 'users', '5', '{\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '0ee9e2260d2f61762b93d064cf5e901ca9f882bb83351ae7332718f0356e3d05', 'edb685dc9dd4d069753fa8723411fb02ee693e0ecb2814e47654279d024f1917', '2026-11-05 09:43:31.143050'),
(11, 5, 'LOGIN_FAILED', 'users', '5', '{\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', 'edb685dc9dd4d069753fa8723411fb02ee693e0ecb2814e47654279d024f1917', 'dcf0e3b5778ce8a3d21c5610cf562d53e674340536b68a3336dfd34e3db22b95', '2026-11-06 00:59:46.555661'),
(12, 5, 'LOGIN_FAILED', 'users', '5', '{\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', 'dcf0e3b5778ce8a3d21c5610cf562d53e674340536b68a3336dfd34e3db22b95', '7626217e341cc27a035a1bf7c245a14e89b286faaa3176f4a94bbdc07c3082cb', '2026-11-06 01:01:15.503064'),
(13, 5, 'LOGIN_FAILED', 'users', '5', '{\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '7626217e341cc27a035a1bf7c245a14e89b286faaa3176f4a94bbdc07c3082cb', '5d0b687a9786175d07096d74b7cb6eac9ee7fc4e40768a9db1e0304850555a2d', '2026-11-06 01:20:28.986054'),
(14, 5, 'LOGIN_FAILED', 'users', '5', '{\"reason\":\"wrong_password\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '5d0b687a9786175d07096d74b7cb6eac9ee7fc4e40768a9db1e0304850555a2d', '16c5f4cc82582e3d3ce1243c8fb48b9f61ee909fdfca2fb935d4961f54312360', '2026-11-06 01:20:40.041154'),
(15, 5, 'LOGIN_SUCCESS', 'users', '5', '{\"role\":\"SUPER_ADMIN\"}', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Safari/537.36', '16c5f4cc82582e3d3ce1243c8fb48b9f61ee909fdfca2fb935d4961f54312360', '5e23ebf7628b1c8cdde4de38f84996eddb058abffce9989b1aebc251f5a5c821', '2026-11-06 01:23:39.632419');

-- --------------------------------------------------------

--
-- Struktur dari tabel `bom_components`
--

CREATE TABLE `bom_components` (
  `id` int UNSIGNED NOT NULL,
  `bom_id` int UNSIGNED NOT NULL,
  `component_item_id` int UNSIGNED NOT NULL COMMENT 'Bahan Baku / Hardware penyusun',
  `required_qty` decimal(18,4) NOT NULL,
  `scrap_tolerance_pct` decimal(5,2) NOT NULL DEFAULT '3.00' COMMENT 'Persentase toleransi potongan terbuang'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `bom_headers`
--

CREATE TABLE `bom_headers` (
  `id` int UNSIGNED NOT NULL,
  `bom_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_item_id` int UNSIGNED NOT NULL COMMENT 'Barang Jadi / Setengah Jadi yang dihasilkan',
  `output_batch_size` decimal(18,4) NOT NULL DEFAULT '1.0000',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `categories`
--

CREATE TABLE `categories` (
  `id` smallint UNSIGNED NOT NULL,
  `category_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_type` enum('RAW_MATERIAL','HALF_FINISHED','HARDWARE_CHEMICAL','PACKAGING','FINISHED_GOOD') COLLATE utf8mb4_unicode_ci NOT NULL,
  `requires_expiry_date` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 untuk Lem, Cat, Tiner, Silica Gel',
  `allocation_strategy` enum('FIFO','FEFO') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'FIFO'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `inventory_ledger_entries`
--

CREATE TABLE `inventory_ledger_entries` (
  `id` bigint UNSIGNED NOT NULL,
  `transaction_id` bigint UNSIGNED NOT NULL,
  `item_id` int UNSIGNED NOT NULL,
  `warehouse_id` smallint UNSIGNED NOT NULL,
  `bin_id` int UNSIGNED NOT NULL,
  `batch_id` bigint UNSIGNED NOT NULL,
  `entry_direction` enum('DEBIT_IN','CREDIT_OUT') COLLATE utf8mb4_unicode_ci NOT NULL,
  `signed_qty` decimal(18,4) NOT NULL COMMENT 'Positif = masuk, negatif = keluar',
  `balance_after_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `unit_cost_idr` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `total_value_idr` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `recorded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Trigger `inventory_ledger_entries`
--
DELIMITER $$
CREATE TRIGGER `trg_prevent_ledger_delete` BEFORE DELETE ON `inventory_ledger_entries` FOR EACH ROW SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'VIOLATION: Penghapusan baris buku besar stok dilarang keras demi integritas audit PT. Dekor Asia Jayakarya!'
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_prevent_ledger_update` BEFORE UPDATE ON `inventory_ledger_entries` FOR EACH ROW SIGNAL SQLSTATE '45000'
SET MESSAGE_TEXT = 'VIOLATION: Tabel inventory_ledger_entries bersifat Append-Only. Perubahan historis dilarang keras! Buat jurnal koreksi baru.'
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Struktur dari tabel `inventory_transactions`
--

CREATE TABLE `inventory_transactions` (
  `id` bigint UNSIGNED NOT NULL,
  `trx_code` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Contoh: TRX-IN-202611-00089',
  `trx_type` enum('INBOUND_PURCHASE','OUTBOUND_PRODUCTION','PRODUCTION_OUTPUT','OUTBOUND_EXPORT_SALE','INTERNAL_BIN_TRANSFER','OPNAME_ADJUSTMENT','SCRAP_WRITE_OFF') COLLATE utf8mb4_unicode_ci NOT NULL,
  `trx_status` enum('DRAFT','COMMITTED','CANCELLED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'DRAFT',
  `reference_doc_no` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'No PO, No Work Order, atau No Container',
  `notes` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `items`
--

CREATE TABLE `items` (
  `id` int UNSIGNED NOT NULL,
  `sku` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `barcode_ean13` varchar(32) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_name` varchar(180) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` smallint UNSIGNED NOT NULL,
  `unit_id` smallint UNSIGNED NOT NULL,
  `default_supplier_id` int UNSIGNED DEFAULT NULL,
  `length_mm` int UNSIGNED NOT NULL DEFAULT '0',
  `width_mm` int UNSIGNED NOT NULL DEFAULT '0',
  `height_mm` int UNSIGNED NOT NULL DEFAULT '0',
  `unit_cbm` decimal(12,6) GENERATED ALWAYS AS ((((`length_mm` * `width_mm`) * `height_mm`) / 1000000000.0)) STORED,
  `gross_weight_kg` decimal(10,3) NOT NULL DEFAULT '0.000',
  `valuation_method` enum('FIFO','WEIGHTED_AVERAGE') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WEIGHTED_AVERAGE',
  `min_safety_stock` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `reorder_point` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `standard_cost_idr` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `moving_avg_cost_idr` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `item_batches`
--

CREATE TABLE `item_batches` (
  `id` bigint UNSIGNED NOT NULL,
  `item_id` int UNSIGNED NOT NULL,
  `batch_number` varchar(48) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Contoh: LOT-20261104-001',
  `supplier_id` int UNSIGNED DEFAULT NULL,
  `received_date` date NOT NULL,
  `manufacture_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL COMMENT 'Wajib diisi jika kategori requires_expiry_date = 1',
  `unit_cost_idr` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `moisture_content_pct` decimal(5,2) DEFAULT NULL COMMENT 'Kadar air kayu (MC%) standar ekspor <= 12%',
  `qc_status` enum('PASSED','HOLD_QUARANTINE','REJECTED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PASSED',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `roles`
--

CREATE TABLE `roles` (
  `id` smallint UNSIGNED NOT NULL,
  `role_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `permissions_json` json NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `roles`
--

INSERT INTO `roles` (`id`, `role_code`, `role_name`, `permissions_json`, `created_at`) VALUES
(1, 'WAREHOUSE_STAFF', 'Staff Gudang', '{\"catalog\": \"view_no_price\", \"inbound\": \"draft_and_scan\", \"outbound\": \"scan_picking\", \"audit_log\": \"none\", \"master_data\": \"none\", \"stock_opname\": \"input_physical_count\", \"bom_production\": \"prepare_materials\"}', '2026-11-05 03:41:47'),
(2, 'PPIC_STAFF', 'Staff PPIC', '{\"catalog\": \"view_no_price\", \"inbound\": \"verify_po\", \"outbound\": \"create_request\", \"audit_log\": \"none\", \"master_data\": \"create_edit_draft\", \"stock_opname\": \"view_progress\", \"bom_production\": \"execute_work_order\"}', '2026-11-05 03:41:47'),
(3, 'WAREHOUSE_HEAD', 'Kepala Gudang', '{\"catalog\": \"view_full\", \"inbound\": \"approve_posting\", \"outbound\": \"approve_posting\", \"audit_log\": \"read_only\", \"master_data\": \"approve_activate\", \"stock_opname\": \"view_variance_and_post\", \"bom_production\": \"approve_mutation\"}', '2026-11-05 03:41:47'),
(4, 'AUDITOR_FINANCE', 'Auditor / Finance', '[\"catalog.view\", \"catalog.view_price\", \"report.access\", \"master.access\", \"inbound.access\", \"outbound.access\", \"bom.access\", \"opname.access\", \"opname.valuation\", \"audit.access\", \"audit.view\", \"audit.verify\"]', '2026-11-05 03:41:47'),
(5, 'SUPER_ADMIN', 'Super Admin IT', '[\"*\"]', '2026-11-05 03:41:47'),
(6, 'STAFF_GUDANG', 'Staff Gudang', '[\"catalog.view\", \"report.access\", \"inbound.access\", \"inbound.draft\", \"inbound.scan\", \"outbound.access\", \"outbound.scan\", \"bom.access\", \"bom.prepare\", \"opname.access\", \"opname.count\"]', '2026-11-05 08:44:42'),
(7, 'STAFF_PPIC', 'Staff PPIC', '[\"catalog.view\", \"report.access\", \"master.access\", \"master.draft\", \"inbound.access\", \"inbound.verify\", \"outbound.access\", \"outbound.request\", \"bom.access\", \"bom.execute\", \"opname.access\", \"opname.progress\"]', '2026-11-05 08:44:42'),
(8, 'KEPALA_GUDANG', 'Kepala Gudang', '[\"catalog.view\", \"catalog.view_price\", \"report.access\", \"master.access\", \"master.approve\", \"inbound.access\", \"inbound.approve\", \"outbound.access\", \"outbound.approve\", \"bom.access\", \"bom.approve\", \"opname.access\", \"opname.review\", \"audit.access\", \"audit.view\"]', '2026-11-05 08:44:42');

-- --------------------------------------------------------

--
-- Struktur dari tabel `stock_balances`
--

CREATE TABLE `stock_balances` (
  `id` bigint UNSIGNED NOT NULL,
  `item_id` int UNSIGNED NOT NULL,
  `warehouse_id` smallint UNSIGNED NOT NULL,
  `bin_id` int UNSIGNED NOT NULL,
  `batch_id` bigint UNSIGNED NOT NULL,
  `qty_on_hand` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `qty_reserved` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `qty_available` decimal(18,4) GENERATED ALWAYS AS ((`qty_on_hand` - `qty_reserved`)) STORED,
  `lock_version` int UNSIGNED NOT NULL DEFAULT '1' COMMENT 'Optimistic Concurrency Control Version',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Struktur dari tabel `stock_opname_items`
--

CREATE TABLE `stock_opname_items` (
  `id` bigint UNSIGNED NOT NULL,
  `opname_session_id` int UNSIGNED NOT NULL,
  `bin_id` int UNSIGNED NOT NULL,
  `item_id` int UNSIGNED NOT NULL,
  `batch_id` bigint UNSIGNED NOT NULL,
  `system_qty_snapshot` decimal(18,4) NOT NULL COMMENT 'Disembunyikan dari layar Staff Gudang (Blind Count)',
  `physical_qty_counted` decimal(18,4) DEFAULT NULL,
  `variance_qty` decimal(18,4) GENERATED ALWAYS AS ((`physical_qty_counted` - `system_qty_snapshot`)) STORED,
  `counted_by` int UNSIGNED DEFAULT NULL,
  `counted_at` datetime DEFAULT NULL,
  `variance_reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `stock_opname_sessions`
--

CREATE TABLE `stock_opname_sessions` (
  `id` int UNSIGNED NOT NULL,
  `opname_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `warehouse_id` smallint UNSIGNED NOT NULL,
  `opname_status` enum('OPEN_COUNTING','REVIEW_VARIANCE','RECONCILED_CLOSED') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'OPEN_COUNTING',
  `scheduled_date` date NOT NULL,
  `created_by` int UNSIGNED NOT NULL,
  `approved_by` int UNSIGNED DEFAULT NULL,
  `reconciled_transaction_id` bigint UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `storage_bins`
--

CREATE TABLE `storage_bins` (
  `id` int UNSIGNED NOT NULL,
  `warehouse_id` smallint UNSIGNED NOT NULL,
  `bin_code` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Contoh: WH01-Z01-R03-L02',
  `zone_name` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `aisle_number` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `rack_number` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `shelf_level` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `max_capacity_cbm` decimal(12,4) NOT NULL DEFAULT '50.0000',
  `current_occupancy_cbm` decimal(12,4) NOT NULL DEFAULT '0.0000',
  `bin_status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'AVAILABLE',
  `is_frozen_for_opname` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = Dibekukan saat Stock Opname',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int UNSIGNED NOT NULL,
  `supplier_code` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `company_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_person` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lead_time_days` smallint UNSIGNED NOT NULL DEFAULT '7' COMMENT 'Rata-rata waktu tunggu pengiriman (hari)',
  `is_svlk_certified` tinyint(1) NOT NULL DEFAULT '0' COMMENT 'Sertifikasi Legalitas Kayu (Wajib untuk Ekspor)',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `units`
--

CREATE TABLE `units` (
  `id` smallint UNSIGNED NOT NULL,
  `unit_code` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'PCS, SET, CBM, KG, LTR, ROLL, BOX',
  `unit_name` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `decimal_places` tinyint UNSIGNED NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `users`
--

CREATE TABLE `users` (
  `id` int UNSIGNED NOT NULL,
  `role_id` smallint UNSIGNED NOT NULL,
  `employee_nik` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `failed_login_attempts` tinyint UNSIGNED NOT NULL DEFAULT '0',
  `locked_until` timestamp NULL DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data untuk tabel `users`
--

INSERT INTO `users` (`id`, `role_id`, `employee_nik`, `full_name`, `email`, `password_hash`, `is_active`, `failed_login_attempts`, `locked_until`, `last_login_at`, `created_at`, `updated_at`) VALUES
(1, 6, 'DAJ-0001', 'Staff Gudang Demo', 'staff.gudang@dekorasia.local', '$argon2id$v=19$m=65536,t=4,p=2$aUVQSTh1V0xqNTFlQlR4RQ$Gg83a5lNVjiDj5hXHRoBykJyxquMFgw+uNhdPBA0EjU', 1, 0, NULL, NULL, '2026-11-05 08:44:42', '2026-11-05 08:44:42'),
(2, 7, 'DAJ-0002', 'Staff PPIC Demo', 'staff.ppic@dekorasia.local', '$argon2id$v=19$m=65536,t=4,p=2$YmNkODFBeFRHUW90OG0xOA$LvR8kkPhKR9r1VsG++4FqwPxdIjGO7ojrfUVxBurrdk', 1, 0, NULL, '2026-11-05 09:25:35', '2026-11-05 08:44:42', '2026-11-05 09:25:35'),
(3, 8, 'DAJ-0003', 'Kepala Gudang Demo', 'kepala.gudang@dekorasia.local', '$argon2id$v=19$m=65536,t=4,p=2$QmMwZjE2YWp5bThEOUs1bA$Dt4Q0h/QqLV5HYRibXHmtqgxQ0M2MXhkr1e0hT3CeqA', 1, 0, NULL, NULL, '2026-11-05 08:44:42', '2026-11-05 08:44:42'),
(4, 4, 'DAJ-0004', 'Auditor Finance Demo', 'auditor@dekorasia.local', '$argon2id$v=19$m=65536,t=4,p=2$SW1YazFEcjVha0l1LlpBeQ$5DuuzKldlP8yuPCAyrKM7RAP9X9LouAacX4Eue0WbiY', 1, 0, NULL, NULL, '2026-11-05 08:44:42', '2026-11-05 08:44:42'),
(5, 5, 'DAJ-0005', 'Super Admin IT', 'admin@dekorasia.local', '$argon2id$v=19$m=65536,t=4,p=2$cmxrLzA5ejg1MkhCSjB0Sw$CBB+4pQG+JVyqrYUToRJ1SiQNlKQatZpaphh34ls7bA', 1, 0, NULL, '2026-11-06 01:23:39', '2026-11-05 08:44:43', '2026-11-06 01:23:39');

-- --------------------------------------------------------

--
-- Struktur dari tabel `warehouses`
--

CREATE TABLE `warehouses` (
  `id` smallint UNSIGNED NOT NULL,
  `warehouse_code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `warehouse_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `warehouse_type` enum('RAW_MATERIAL','WIP_PRODUCTION','FINISHED_GOODS','QUARANTINE_QC','VIRTUAL_COUNTERPART') COLLATE utf8mb4_unicode_ci NOT NULL,
  `address_detail` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indeks untuk tabel yang dibuang
--

--
-- Indeks untuk tabel `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `current_hash` (`current_hash`),
  ADD KEY `idx_audit_entity` (`entity_table`,`entity_id`);

--
-- Indeks untuk tabel `bom_components`
--
ALTER TABLE `bom_components`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_bom_comp_hdr` (`bom_id`),
  ADD KEY `fk_bom_comp_item` (`component_item_id`);

--
-- Indeks untuk tabel `bom_headers`
--
ALTER TABLE `bom_headers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bom_code` (`bom_code`),
  ADD KEY `fk_bom_target` (`target_item_id`);

--
-- Indeks untuk tabel `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `category_code` (`category_code`);

--
-- Indeks untuk tabel `inventory_ledger_entries`
--
ALTER TABLE `inventory_ledger_entries`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_led_trx` (`transaction_id`),
  ADD KEY `fk_led_wh` (`warehouse_id`),
  ADD KEY `fk_led_bin` (`bin_id`),
  ADD KEY `fk_led_batch` (`batch_id`),
  ADD KEY `idx_led_item_time` (`item_id`,`recorded_at`);

--
-- Indeks untuk tabel `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `trx_code` (`trx_code`),
  ADD KEY `fk_trx_user` (`created_by`),
  ADD KEY `idx_trx_type_status` (`trx_type`,`trx_status`);

--
-- Indeks untuk tabel `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD UNIQUE KEY `barcode_ean13` (`barcode_ean13`),
  ADD KEY `fk_items_category` (`category_id`),
  ADD KEY `fk_items_unit` (`unit_id`),
  ADD KEY `fk_items_supplier` (`default_supplier_id`);

--
-- Indeks untuk tabel `item_batches`
--
ALTER TABLE `item_batches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_item_batch` (`item_id`,`batch_number`),
  ADD KEY `fk_batches_supplier` (`supplier_id`),
  ADD KEY `idx_batches_fifo` (`item_id`,`qc_status`,`received_date`,`id`),
  ADD KEY `idx_batches_fefo` (`item_id`,`qc_status`,`expiry_date`,`received_date`);

--
-- Indeks untuk tabel `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_code` (`role_code`);

--
-- Indeks untuk tabel `stock_balances`
--
ALTER TABLE `stock_balances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_balance_locator` (`item_id`,`bin_id`,`batch_id`),
  ADD KEY `fk_bal_bin` (`bin_id`),
  ADD KEY `fk_bal_batch` (`batch_id`),
  ADD KEY `idx_bal_wh_item` (`warehouse_id`,`item_id`);

--
-- Indeks untuk tabel `stock_opname_items`
--
ALTER TABLE `stock_opname_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_opitem_session` (`opname_session_id`);

--
-- Indeks untuk tabel `stock_opname_sessions`
--
ALTER TABLE `stock_opname_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `opname_code` (`opname_code`),
  ADD KEY `fk_opname_wh` (`warehouse_id`);

--
-- Indeks untuk tabel `storage_bins`
--
ALTER TABLE `storage_bins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bin_code` (`bin_code`),
  ADD KEY `fk_bins_warehouse` (`warehouse_id`);

--
-- Indeks untuk tabel `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `supplier_code` (`supplier_code`);

--
-- Indeks untuk tabel `units`
--
ALTER TABLE `units`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unit_code` (`unit_code`);

--
-- Indeks untuk tabel `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_nik` (`employee_nik`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_users_role` (`role_id`);

--
-- Indeks untuk tabel `warehouses`
--
ALTER TABLE `warehouses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `warehouse_code` (`warehouse_code`);

--
-- AUTO_INCREMENT untuk tabel yang dibuang
--

--
-- AUTO_INCREMENT untuk tabel `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT untuk tabel `bom_components`
--
ALTER TABLE `bom_components`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `bom_headers`
--
ALTER TABLE `bom_headers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `categories`
--
ALTER TABLE `categories`
  MODIFY `id` smallint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `inventory_ledger_entries`
--
ALTER TABLE `inventory_ledger_entries`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `items`
--
ALTER TABLE `items`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `item_batches`
--
ALTER TABLE `item_batches`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `roles`
--
ALTER TABLE `roles`
  MODIFY `id` smallint UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT untuk tabel `stock_balances`
--
ALTER TABLE `stock_balances`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `stock_opname_items`
--
ALTER TABLE `stock_opname_items`
  MODIFY `id` bigint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `stock_opname_sessions`
--
ALTER TABLE `stock_opname_sessions`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `storage_bins`
--
ALTER TABLE `storage_bins`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `units`
--
ALTER TABLE `units`
  MODIFY `id` smallint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `users`
--
ALTER TABLE `users`
  MODIFY `id` int UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT untuk tabel `warehouses`
--
ALTER TABLE `warehouses`
  MODIFY `id` smallint UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `bom_components`
--
ALTER TABLE `bom_components`
  ADD CONSTRAINT `fk_bom_comp_hdr` FOREIGN KEY (`bom_id`) REFERENCES `bom_headers` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_bom_comp_item` FOREIGN KEY (`component_item_id`) REFERENCES `items` (`id`);

--
-- Ketidakleluasaan untuk tabel `bom_headers`
--
ALTER TABLE `bom_headers`
  ADD CONSTRAINT `fk_bom_target` FOREIGN KEY (`target_item_id`) REFERENCES `items` (`id`);

--
-- Ketidakleluasaan untuk tabel `inventory_ledger_entries`
--
ALTER TABLE `inventory_ledger_entries`
  ADD CONSTRAINT `fk_led_batch` FOREIGN KEY (`batch_id`) REFERENCES `item_batches` (`id`),
  ADD CONSTRAINT `fk_led_bin` FOREIGN KEY (`bin_id`) REFERENCES `storage_bins` (`id`),
  ADD CONSTRAINT `fk_led_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  ADD CONSTRAINT `fk_led_trx` FOREIGN KEY (`transaction_id`) REFERENCES `inventory_transactions` (`id`),
  ADD CONSTRAINT `fk_led_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`);

--
-- Ketidakleluasaan untuk tabel `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  ADD CONSTRAINT `fk_trx_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Ketidakleluasaan untuk tabel `items`
--
ALTER TABLE `items`
  ADD CONSTRAINT `fk_items_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  ADD CONSTRAINT `fk_items_supplier` FOREIGN KEY (`default_supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `fk_items_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`);

--
-- Ketidakleluasaan untuk tabel `item_batches`
--
ALTER TABLE `item_batches`
  ADD CONSTRAINT `fk_batches_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  ADD CONSTRAINT `fk_batches_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Ketidakleluasaan untuk tabel `stock_balances`
--
ALTER TABLE `stock_balances`
  ADD CONSTRAINT `fk_bal_batch` FOREIGN KEY (`batch_id`) REFERENCES `item_batches` (`id`),
  ADD CONSTRAINT `fk_bal_bin` FOREIGN KEY (`bin_id`) REFERENCES `storage_bins` (`id`),
  ADD CONSTRAINT `fk_bal_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  ADD CONSTRAINT `fk_bal_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`);

--
-- Ketidakleluasaan untuk tabel `stock_opname_items`
--
ALTER TABLE `stock_opname_items`
  ADD CONSTRAINT `fk_opitem_session` FOREIGN KEY (`opname_session_id`) REFERENCES `stock_opname_sessions` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `stock_opname_sessions`
--
ALTER TABLE `stock_opname_sessions`
  ADD CONSTRAINT `fk_opname_wh` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`);

--
-- Ketidakleluasaan untuk tabel `storage_bins`
--
ALTER TABLE `storage_bins`
  ADD CONSTRAINT `fk_bins_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
