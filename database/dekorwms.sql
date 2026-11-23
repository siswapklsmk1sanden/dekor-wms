-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Waktu pembuatan: 18 Nov 2026 pada 07.57
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
  `id` bigint NOT NULL,
  `user_id` int DEFAULT NULL,
  `event_action` varchar(64) NOT NULL,
  `entity_table` varchar(64) NOT NULL,
  `entity_id` varchar(64) NOT NULL,
  `payload_json` text NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `prev_hash` char(64) NOT NULL,
  `current_hash` char(64) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `event_action`, `entity_table`, `entity_id`, `payload_json`, `ip_address`, `prev_hash`, `current_hash`, `created_at`) VALUES
(1, 5, 'RESET', 'Kesiapan Produksi', '0', '{\"description\":\"Data uji dibersihkan: 34 baris dari 20 tabel (termasuk Audit Log), 12 foto dihapus.\",\"username\":\"admin\",\"role\":\"super_admin\",\"user_agent\":\"Mozilla\\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\\/537.36 (KHTML, like Gecko) Chrome\\/157.0.0.0 Safari\\/537.36\"}', '::1', '0000000000000000000000000000000000000000000000000000000000000000', 'c0f5a6980990dd2e5e824eff814a1215255e8a0d0a2851b861b6509c7bbb9237', '2026-11-18 07:53:08');

-- --------------------------------------------------------

--
-- Struktur dari tabel `bom_components`
--

CREATE TABLE `bom_components` (
  `id` int NOT NULL,
  `bom_id` int NOT NULL,
  `component_item_id` int NOT NULL,
  `required_qty` decimal(18,4) NOT NULL,
  `scrap_tolerance_pct` decimal(5,2) NOT NULL DEFAULT '3.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `bom_components`
--

INSERT INTO `bom_components` (`id`, `bom_id`, `component_item_id`, `required_qty`, `scrap_tolerance_pct`) VALUES
(5, 3, 4, 10.0000, 3.00),
(6, 5, 4, 5.0000, 3.00);

-- --------------------------------------------------------

--
-- Struktur dari tabel `bom_headers`
--

CREATE TABLE `bom_headers` (
  `id` int NOT NULL,
  `bom_code` varchar(32) NOT NULL,
  `target_item_id` int NOT NULL,
  `output_batch_size` decimal(18,4) NOT NULL DEFAULT '1.0000',
  `is_active` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `bom_headers`
--

INSERT INTO `bom_headers` (`id`, `bom_code`, `target_item_id`, `output_batch_size`, `is_active`) VALUES
(3, 'BOM-PAGAR-01', 3, 1.0000, 0),
(5, 'BOM-PAGAR-02', 3, 1.0000, 1);

-- --------------------------------------------------------

--
-- Struktur dari tabel `buyer_requests`
--

CREATE TABLE `buyer_requests` (
  `id` int NOT NULL,
  `req_number` varchar(30) NOT NULL,
  `item_id` int DEFAULT NULL COMMENT 'Terisi setelah barang didaftarkan ke master / bila memilih barang yang sudah ada',
  `item_name` varchar(180) NOT NULL,
  `specification` varchar(500) DEFAULT NULL,
  `qty_requested` decimal(18,4) NOT NULL,
  `unit_id` int NOT NULL,
  `supplier_id` int DEFAULT NULL COMMENT 'Usulan supplier dari Buyer',
  `needed_date` date DEFAULT NULL,
  `reference_photo_path` varchar(255) NOT NULL COMMENT 'Foto acuan: barang seperti apa (uploads/requests/...)',
  `status` enum('REQUESTED','REGISTERED','SPK_ISSUED','REJECTED','CANCELLED') NOT NULL DEFAULT 'REQUESTED',
  `decision_note` varchar(300) DEFAULT NULL,
  `po_line_id` int DEFAULT NULL COMMENT 'Baris PO/SPK yang memuat permintaan ini',
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_by` int DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `categories`
--

CREATE TABLE `categories` (
  `id` int NOT NULL,
  `category_code` varchar(20) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `category_type` enum('RAW_MATERIAL','HALF_FINISHED','CHEMICAL','PACKAGING','FINISHED_GOOD') NOT NULL DEFAULT 'RAW_MATERIAL',
  `requires_expiry_date` tinyint(1) NOT NULL DEFAULT '0',
  `allocation_strategy` enum('FIFO','FEFO') NOT NULL DEFAULT 'FIFO'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `categories`
--

INSERT INTO `categories` (`id`, `category_code`, `category_name`, `category_type`, `requires_expiry_date`, `allocation_strategy`) VALUES
(1, 'CAT-WOOD', 'Kayu Jati & Mahoni Oven', 'RAW_MATERIAL', 0, 'FIFO'),
(2, 'CAT-CHEM', 'Lem & Cat Finishing', 'CHEMICAL', 1, 'FEFO'),
(3, 'CAT-FG', 'Pagar Bambu & Mebel Ekspor', 'FINISHED_GOOD', 0, 'FIFO'),
(4, 'CAT-BAMBU', 'Bambu Mentah', 'RAW_MATERIAL', 0, 'FIFO'),
(5, 'CAT-PACK', 'Pengikat & Kemasan', 'PACKAGING', 0, 'FIFO');

-- --------------------------------------------------------

--
-- Struktur dari tabel `inventory_ledger_entries`
--

CREATE TABLE `inventory_ledger_entries` (
  `id` bigint NOT NULL,
  `transaction_id` bigint NOT NULL,
  `item_id` int NOT NULL,
  `warehouse_id` int NOT NULL,
  `bin_id` int NOT NULL,
  `batch_id` bigint NOT NULL,
  `entry_direction` enum('DEBIT_IN','CREDIT_OUT') NOT NULL,
  `signed_qty` decimal(18,4) NOT NULL COMMENT 'Positif (+) untuk Debit, Negatif (-) untuk Kredit',
  `balance_after_qty` decimal(18,4) NOT NULL,
  `unit_cost_idr` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `recorded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `inventory_transactions`
--

CREATE TABLE `inventory_transactions` (
  `id` bigint NOT NULL,
  `trx_code` varchar(36) NOT NULL,
  `trx_type` enum('INBOUND_PURCHASE','OUTBOUND_PRODUCTION','PRODUCTION_OUTPUT','OUTBOUND_EXPORT_SALE','INTERNAL_BIN_TRANSFER','OPNAME_ADJUSTMENT','SCRAP_WRITE_OFF') NOT NULL,
  `trx_status` enum('DRAFT','COMMITTED','CANCELLED') NOT NULL DEFAULT 'DRAFT',
  `reference_doc_no` varchar(60) DEFAULT NULL,
  `created_by` int NOT NULL,
  `approved_by` int DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `items`
--

CREATE TABLE `items` (
  `id` int NOT NULL,
  `sku` varchar(40) NOT NULL,
  `barcode_ean13` varchar(32) DEFAULT NULL,
  `item_name` varchar(180) NOT NULL,
  `category_id` int NOT NULL,
  `unit_id` int NOT NULL,
  `default_supplier_id` int DEFAULT NULL,
  `reference_photo_path` varchar(255) DEFAULT NULL COMMENT 'Foto acuan barang (uploads/requests/...), tercetak di SPK',
  `specification` varchar(500) DEFAULT NULL COMMENT 'Spesifikasi: ukuran, bahan, warna, merek',
  `length_mm` int NOT NULL DEFAULT '0',
  `width_mm` int NOT NULL DEFAULT '0',
  `height_mm` int NOT NULL DEFAULT '0',
  `unit_cbm` decimal(12,6) NOT NULL DEFAULT '0.000000' COMMENT 'Kalkulasi: (P x L x T) / 1.000.000.000',
  `min_safety_stock` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `reorder_point` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `moving_avg_cost_idr` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `items`
--

INSERT INTO `items` (`id`, `sku`, `barcode_ean13`, `item_name`, `category_id`, `unit_id`, `default_supplier_id`, `reference_photo_path`, `specification`, `length_mm`, `width_mm`, `height_mm`, `unit_cbm`, `min_safety_stock`, `reorder_point`, `moving_avg_cost_idr`, `is_active`, `created_at`) VALUES
(1, 'DAJ-RM-TEAK-001', '8991001001', 'Papan Kayu Jati Oven 200x20x3cm', 1, 1, 1, NULL, 'per meter', 2000, 200, 30, 0.012000, 100.0000, 150.0000, 0.0000, 1, '2026-11-12 07:28:45'),
(2, 'DAJ-CH-GLUE-001', '8991001002', 'Lem Epoxy Ekspor Grade-A', 2, 1, 2, NULL, NULL, 200, 200, 300, 0.012000, 20.0000, 40.0000, 7000.0000, 1, '2026-11-12 07:28:45'),
(3, 'DAJ-FG-FENCE-001', '8991001003', 'Pagar Bambu Hitam Gulung 180x90cm', 3, 1, NULL, NULL, NULL, 1800, 900, 100, 0.162000, 50.0000, 80.0000, 0.0000, 1, '2026-11-12 07:28:45'),
(4, 'DAJ-RM-BAMBU-001', NULL, 'Bambu Hitam Mentah 3m', 4, 1, NULL, NULL, 'Contoh: panjang 3 m, diameter sekitar 8 cm. Sesuaikan.', 3000, 80, 80, 0.019200, 100.0000, 200.0000, 30000.0000, 1, '2026-11-18 03:16:11'),
(5, 'DAJ-PK-KAWAT-001', NULL, 'Kawat Pengikat Bambu', 5, 7, NULL, NULL, 'Contoh. Sesuaikan jenis dan ukuran kawat.', 0, 0, 0, 0.000000, 10.0000, 20.0000, 0.0000, 1, '2026-11-18 03:16:11');

-- --------------------------------------------------------

--
-- Struktur dari tabel `item_batches`
--

CREATE TABLE `item_batches` (
  `id` bigint NOT NULL,
  `item_id` int NOT NULL,
  `batch_number` varchar(48) NOT NULL,
  `supplier_id` int DEFAULT NULL,
  `received_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL COMMENT 'Wajib jika kategori requires_expiry_date = 1',
  `unit_cost_idr` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `moisture_content_pct` decimal(5,2) DEFAULT NULL COMMENT 'Kadar air kayu oven MC <= 12%',
  `qc_status` enum('PASSED','HOLD_QUARANTINE','REJECTED') NOT NULL DEFAULT 'PASSED',
  `arrival_photo_path` varchar(255) DEFAULT NULL COMMENT 'Foto barang saat tiba (uploads/arrivals/...)',
  `arrival_photo_at` datetime DEFAULT NULL COMMENT 'Waktu foto kedatangan diunggah (detik)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `opname_counts`
--

CREATE TABLE `opname_counts` (
  `id` bigint NOT NULL,
  `session_id` int NOT NULL,
  `item_id` int NOT NULL,
  `batch_id` bigint NOT NULL,
  `counted_qty` decimal(18,4) NOT NULL,
  `system_qty` decimal(18,4) DEFAULT NULL COMMENT 'Snapshot saldo sistem saat posting',
  `counted_by` int NOT NULL,
  `counted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `opname_sessions`
--

CREATE TABLE `opname_sessions` (
  `id` int NOT NULL,
  `session_code` varchar(32) NOT NULL,
  `bin_id` int NOT NULL,
  `status` enum('OPEN','POSTED','CANCELLED') NOT NULL DEFAULT 'OPEN',
  `notes` varchar(255) DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `closed_by` int DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `transaction_id` bigint DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `po_receipts`
--

CREATE TABLE `po_receipts` (
  `id` bigint NOT NULL,
  `po_line_id` int NOT NULL,
  `transaction_id` bigint NOT NULL,
  `qty` decimal(18,4) NOT NULL,
  `is_reversed` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchase_orders`
--

CREATE TABLE `purchase_orders` (
  `id` int NOT NULL,
  `po_number` varchar(30) NOT NULL,
  `supplier_id` int NOT NULL,
  `order_date` date NOT NULL,
  `status` enum('OPEN','PARTIAL','CLOSED','CANCELLED') NOT NULL DEFAULT 'OPEN',
  `notes` varchar(500) DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `spk_number` varchar(40) DEFAULT NULL,
  `deliver_by` date DEFAULT NULL COMMENT 'Batas kirim barang ke gudang (SPK)',
  `spk_note` varchar(500) DEFAULT NULL COMMENT 'Instruksi tambahan di surat SPK'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `purchase_order_lines`
--

CREATE TABLE `purchase_order_lines` (
  `id` int NOT NULL,
  `po_id` int NOT NULL,
  `item_id` int NOT NULL,
  `qty_ordered` decimal(18,4) NOT NULL,
  `qty_received` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `unit_price_idr` decimal(18,4) NOT NULL DEFAULT '0.0000'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `roles`
--

CREATE TABLE `roles` (
  `id` int NOT NULL,
  `role_code` varchar(30) NOT NULL,
  `role_name` varchar(80) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `roles`
--

INSERT INTO `roles` (`id`, `role_code`, `role_name`) VALUES
(1, 'staff_gudang', 'Staff Gudang'),
(2, 'staff_ppic', 'Staff PPIC'),
(3, 'kepala_gudang', 'Kepala Gudang'),
(4, 'auditor_finance', 'Auditor / Finance'),
(5, 'super_admin', 'Super Admin IT'),
(6, 'buyer', 'Buyer / Purchasing');

-- --------------------------------------------------------

--
-- Struktur dari tabel `role_permissions`
--

CREATE TABLE `role_permissions` (
  `id` int NOT NULL,
  `role_id` int NOT NULL,
  `module_name` varchar(50) NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT '0',
  `can_create` tinyint(1) NOT NULL DEFAULT '0',
  `can_approve` tinyint(1) NOT NULL DEFAULT '0',
  `hide_price` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `role_permissions`
--

INSERT INTO `role_permissions` (`id`, `role_id`, `module_name`, `can_view`, `can_create`, `can_approve`, `hide_price`) VALUES
(1, 1, 'catalog', 1, 0, 0, 1),
(2, 1, 'master', 0, 0, 0, 1),
(3, 1, 'inbound', 1, 1, 0, 1),
(4, 1, 'outbound', 1, 1, 0, 1),
(5, 1, 'bom', 1, 0, 0, 1),
(6, 1, 'opname', 1, 1, 0, 1),
(7, 1, 'audit', 0, 0, 0, 1),
(8, 2, 'catalog', 1, 0, 0, 1),
(9, 2, 'master', 1, 1, 0, 1),
(10, 2, 'inbound', 1, 1, 0, 1),
(11, 2, 'outbound', 1, 1, 0, 1),
(12, 2, 'bom', 1, 1, 0, 1),
(13, 2, 'opname', 1, 0, 0, 1),
(14, 2, 'audit', 0, 0, 0, 1),
(15, 3, 'catalog', 1, 1, 1, 0),
(16, 3, 'master', 1, 1, 1, 0),
(17, 3, 'inbound', 1, 1, 1, 0),
(18, 3, 'outbound', 1, 1, 1, 0),
(19, 3, 'bom', 1, 1, 1, 0),
(20, 3, 'opname', 1, 1, 1, 0),
(21, 3, 'audit', 1, 0, 0, 0),
(22, 4, 'catalog', 1, 0, 0, 0),
(23, 4, 'master', 1, 0, 0, 0),
(24, 4, 'inbound', 1, 0, 0, 0),
(25, 4, 'outbound', 1, 0, 0, 0),
(26, 4, 'bom', 1, 0, 0, 0),
(27, 4, 'opname', 1, 0, 0, 0),
(28, 4, 'audit', 1, 0, 0, 0),
(29, 5, 'catalog', 1, 1, 1, 0),
(30, 5, 'master', 1, 1, 1, 0),
(31, 5, 'inbound', 1, 1, 1, 0),
(32, 5, 'outbound', 1, 1, 1, 0),
(33, 5, 'bom', 1, 1, 1, 0),
(34, 5, 'opname', 1, 1, 1, 0),
(35, 5, 'audit', 1, 0, 0, 0),
(36, 4, 'buyer', 1, 0, 0, 0),
(37, 6, 'buyer', 1, 1, 0, 0),
(38, 3, 'buyer', 1, 1, 1, 0),
(39, 1, 'buyer', 1, 0, 0, 1),
(40, 2, 'buyer', 1, 1, 0, 1),
(41, 5, 'buyer', 1, 1, 1, 0),
(43, 6, 'catalog', 1, 0, 0, 0),
(44, 6, 'master', 1, 0, 0, 0),
(45, 6, 'inbound', 1, 0, 0, 0),
(46, 4, 'payable', 1, 1, 1, 0),
(47, 5, 'payable', 1, 1, 1, 0),
(48, 3, 'payable', 1, 0, 0, 0),
(49, 6, 'payable', 1, 0, 0, 0);

-- --------------------------------------------------------

--
-- Struktur dari tabel `stock_balances`
--

CREATE TABLE `stock_balances` (
  `id` bigint NOT NULL,
  `item_id` int NOT NULL,
  `warehouse_id` int NOT NULL,
  `bin_id` int NOT NULL,
  `batch_id` bigint NOT NULL,
  `qty_on_hand` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `qty_reserved` decimal(18,4) NOT NULL DEFAULT '0.0000'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `stock_reservations`
--

CREATE TABLE `stock_reservations` (
  `id` int NOT NULL,
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
  `close_note` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `stock_reservation_allocs`
--

CREATE TABLE `stock_reservation_allocs` (
  `id` int NOT NULL,
  `reservation_id` int NOT NULL,
  `bin_id` int NOT NULL,
  `batch_id` bigint NOT NULL,
  `qty` decimal(18,4) NOT NULL COMMENT 'Jumlah yang saat ini ditahan di rak+lot ini (= bagian dari stock_balances.qty_reserved)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `storage_bins`
--

CREATE TABLE `storage_bins` (
  `id` int NOT NULL,
  `warehouse_id` int NOT NULL,
  `bin_code` varchar(32) NOT NULL COMMENT 'Format Kanonikal: WH01-Z01-R01-L01',
  `zone_name` varchar(60) NOT NULL,
  `max_capacity_cbm` decimal(12,4) NOT NULL DEFAULT '50.0000',
  `is_frozen_for_opname` tinyint(1) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `storage_bins`
--

INSERT INTO `storage_bins` (`id`, `warehouse_id`, `bin_code`, `zone_name`, `max_capacity_cbm`, `is_frozen_for_opname`, `is_active`) VALUES
(1, 1, 'WH01-Z01-R01-L01', 'Zona Log Kayu Jati', 50.0000, 0, 1),
(3, 1, 'WH01-Z02-R01-L01', 'Zona Kimia Lem Epoxy', 100.0000, 0, 1),
(4, 3, 'WH03-Z01-R01-L01', 'Zona Meja Ekspor Siap Kirim', 65.0000, 0, 1),
(6, 1, 'WH01-Z01-R01-L02', 'Zona Log Kayu Jati', 50.0000, 0, 1);

-- --------------------------------------------------------

--
-- Struktur dari tabel `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int NOT NULL,
  `supplier_code` varchar(24) NOT NULL,
  `company_name` varchar(150) NOT NULL,
  `contact_person` varchar(100) NOT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `lead_time_days` smallint NOT NULL DEFAULT '7',
  `is_svlk_certified` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Sertifikasi Legalitas Kayu Ekspor',
  `svlk_number` varchar(60) DEFAULT NULL COMMENT 'Nomor sertifikat SVLK',
  `svlk_expiry_date` date DEFAULT NULL COMMENT 'Tanggal berakhir berlaku sertifikat SVLK',
  `is_active` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `suppliers`
--

INSERT INTO `suppliers` (`id`, `supplier_code`, `company_name`, `contact_person`, `phone`, `lead_time_days`, `is_svlk_certified`, `svlk_number`, `svlk_expiry_date`, `is_active`) VALUES
(1, 'SUP-001', 'Perhutani KPH Randublatung', 'Bpk. Hartono', '7qZIxR4LyxhT8zhYXcyTKPxS2xmQOeJF7BJzlr7zQnlBBcYZqKm4ng==', 7, 1, 'TEST-01', '2031-11-17', 1),
(2, 'SUP-002', 'PT. Chem Warna Indah', 'Ibu Ratna', '081398765432', 3, 0, NULL, NULL, 1),
(3, 'SUP-003', 'PT.Cipta Mangun', 'Bpk. Jamhari', 'tighnFo/s/k2b+rZRvRgbU1FAF9qf6lo8sjICCAVrprBwXCNbmYkHw==', 7, 1, 'TEST-02', '2029-10-15', 1),
(4, 'SUP-04', 'PT.Mawar cipta', 'Ibu sujianti', 'r6HMmAVKFldYvfPB2bEeoXnkqfVbdssM3GNF1w3A+tUr+y0M6/zBwQ==', 7, 0, NULL, NULL, 1);

-- --------------------------------------------------------

--
-- Struktur dari tabel `supplier_credits`
--

CREATE TABLE `supplier_credits` (
  `id` int NOT NULL,
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
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `supplier_credit_apps`
--

CREATE TABLE `supplier_credit_apps` (
  `id` int NOT NULL,
  `credit_id` int NOT NULL,
  `invoice_id` int NOT NULL,
  `amount_idr` decimal(18,2) NOT NULL,
  `is_void` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `supplier_invoices`
--

CREATE TABLE `supplier_invoices` (
  `id` int NOT NULL,
  `inv_code` varchar(30) NOT NULL COMMENT 'Nomor internal FAK-yymmdd-xxxx',
  `supplier_invoice_no` varchar(60) NOT NULL COMMENT 'Nomor faktur dari supplier',
  `supplier_id` int NOT NULL,
  `po_id` int DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `terms_days` smallint NOT NULL DEFAULT '30',
  `due_date` date NOT NULL,
  `goods_idr` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'Nilai barang menurut faktur',
  `vat_idr` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'PPN = jumlah PPN seluruh baris faktur',
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
  `attachment_file` varchar(120) DEFAULT NULL COMMENT 'Nama file scan faktur di admin/uploads/invoices',
  `attachment_name` varchar(150) DEFAULT NULL,
  `attachment_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `supplier_invoice_lines`
--

CREATE TABLE `supplier_invoice_lines` (
  `id` int NOT NULL,
  `invoice_id` int NOT NULL,
  `po_line_id` int DEFAULT NULL,
  `item_id` int NOT NULL,
  `qty` decimal(18,4) NOT NULL,
  `po_price_idr` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `invoice_price_idr` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `vat_pct` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'PPN (%) baris ini'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `supplier_payments`
--

CREATE TABLE `supplier_payments` (
  `id` int NOT NULL,
  `invoice_id` int NOT NULL,
  `paid_date` date NOT NULL,
  `amount_idr` decimal(18,2) NOT NULL,
  `method` varchar(30) NOT NULL DEFAULT 'Transfer',
  `ref_no` varchar(60) DEFAULT NULL,
  `notes` varchar(200) DEFAULT NULL,
  `is_void` tinyint(1) NOT NULL DEFAULT '0',
  `void_note` varchar(200) DEFAULT NULL,
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `supplier_terms`
--

CREATE TABLE `supplier_terms` (
  `supplier_id` int NOT NULL,
  `terms_days` smallint NOT NULL DEFAULT '30' COMMENT 'Termin pembayaran bawaan (hari)',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `trx_drafts`
--

CREATE TABLE `trx_drafts` (
  `id` int NOT NULL,
  `module_name` varchar(50) NOT NULL,
  `target_page` varchar(80) NOT NULL,
  `label` varchar(120) NOT NULL,
  `payload_json` mediumtext NOT NULL,
  `status` enum('PENDING','APPROVED','REJECTED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  `created_by` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_by` int DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `decision_note` varchar(200) DEFAULT NULL,
  `result_trx_code` varchar(80) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Struktur dari tabel `units`
--

CREATE TABLE `units` (
  `id` int NOT NULL,
  `unit_code` varchar(12) NOT NULL COMMENT 'PCS, CBM, LTR, SET, KG',
  `unit_name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `units`
--

INSERT INTO `units` (`id`, `unit_code`, `unit_name`) VALUES
(1, 'PCS', 'Pieces / Batang'),
(2, 'CBM', 'Meter Kubik'),
(3, 'LTR', 'Liter'),
(4, 'SET', 'Set Lengkap'),
(5, 'KG', 'Kilogram'),
(6, 'MTR', 'Meter'),
(7, 'ROL', 'Roll / Gulung');

-- --------------------------------------------------------

--
-- Struktur dari tabel `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `role_id` int NOT NULL,
  `employee_nik` varchar(30) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(150) NOT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `failed_login_attempts` tinyint NOT NULL DEFAULT '0',
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `users`
--

INSERT INTO `users` (`id`, `role_id`, `employee_nik`, `full_name`, `username`, `email`, `photo_path`, `password_hash`, `is_active`, `failed_login_attempts`, `locked_until`, `last_login_at`, `created_at`) VALUES
(1, 1, 'DAJ-0001', 'Staff Gudang Demo', 'gudang', 'staff.gudang@dekorasia.local', NULL, '$2y$12$XneN8fT1Cd7ihcch.u/utOYTJDYHgbD1AuW/vhY.ve98W1WYbq9bm', 1, 0, NULL, '2026-11-18 13:59:16', '2026-11-12 07:28:45'),
(2, 2, 'DAJ-0002', 'Staff PPIC Demo', 'ppic', 'staff.ppic@dekorasia.local', NULL, '$2y$12$W0d/KvC5vHBrgBM3H9W1G.A6sLuBw1CwdqGmtX0okiiJt5sroNPJ.', 1, 0, NULL, '2026-11-18 11:44:46', '2026-11-12 07:28:45'),
(3, 3, 'DAJ-0003', 'Kepala Gudang Demo', 'kepala', 'kepala.gudang@dekorasia.local', NULL, '$2y$12$5G.eVbRo7zTFkPGY5JdiheLxKnaiKyaLln0AsfxAGbBOF5Wi80Cva', 1, 0, NULL, '2026-11-18 13:45:16', '2026-11-12 07:28:45'),
(4, 4, 'DAJ-0004', 'Auditor Finance Demo', 'finance', 'auditor@dekorasia.local', NULL, '$2y$12$6/RIMyM4pS0fJ2NmVXhD5O6VUuAI5H4tC2fxhOI.C4rR3yU.m1h.m', 1, 0, NULL, '2026-11-18 11:21:05', '2026-11-12 07:28:45'),
(5, 5, 'DAJ-0005', 'Super Admin IT', 'admin', 'admin@dekorasia.local', NULL, '$2y$12$tGS5/9yQL8FvpyBlyCf39eefrhwylFfRnBGnz98jFBJZmZ7CcR.5u', 1, 0, NULL, '2026-11-18 14:00:03', '2026-11-12 07:28:45');

-- --------------------------------------------------------

--
-- Struktur dari tabel `warehouses`
--

CREATE TABLE `warehouses` (
  `id` int NOT NULL,
  `warehouse_code` varchar(20) NOT NULL,
  `warehouse_name` varchar(100) NOT NULL,
  `warehouse_type` enum('RAW_MATERIAL','WIP_PRODUCTION','FINISHED_GOODS','QUARANTINE_QC','VIRTUAL_COUNTERPART') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data untuk tabel `warehouses`
--

INSERT INTO `warehouses` (`id`, `warehouse_code`, `warehouse_name`, `warehouse_type`, `is_active`, `created_at`) VALUES
(1, 'WH-RAW', 'Gudang Bahan Baku Kayu & Rotan', 'RAW_MATERIAL', 1, '2026-11-12 07:28:45'),
(2, 'WH-WIP', 'Gudang Komponen Produksi Mebel', 'WIP_PRODUCTION', 1, '2026-11-12 07:28:45'),
(3, 'WH-FG', 'Gudang Barang Jadi Ekspor', 'FINISHED_GOODS', 1, '2026-11-12 07:28:45'),
(4, 'WH-VIRTUAL', 'Akun Penyeimbang Virtual Ledger', 'VIRTUAL_COUNTERPART', 1, '2026-11-12 07:28:45');

--
-- Indeks untuk tabel yang dibuang
--

--
-- Indeks untuk tabel `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_chain` (`prev_hash`,`current_hash`);

--
-- Indeks untuk tabel `bom_components`
--
ALTER TABLE `bom_components`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_bom_parent` (`bom_id`),
  ADD KEY `fk_bom_component` (`component_item_id`);

--
-- Indeks untuk tabel `bom_headers`
--
ALTER TABLE `bom_headers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bom_code` (`bom_code`),
  ADD KEY `fk_bom_target` (`target_item_id`);

--
-- Indeks untuk tabel `buyer_requests`
--
ALTER TABLE `buyer_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_req_number` (`req_number`),
  ADD KEY `idx_req_status` (`status`,`created_at`),
  ADD KEY `fk_req_item` (`item_id`),
  ADD KEY `fk_req_unit` (`unit_id`),
  ADD KEY `fk_req_supplier` (`supplier_id`),
  ADD KEY `fk_req_poline` (`po_line_id`),
  ADD KEY `fk_req_creator` (`created_by`);

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
  ADD KEY `fk_ledger_warehouse` (`warehouse_id`),
  ADD KEY `fk_ledger_bin` (`bin_id`),
  ADD KEY `fk_ledger_batch` (`batch_id`),
  ADD KEY `idx_ledger_trx` (`transaction_id`),
  ADD KEY `idx_ledger_item_time` (`item_id`,`recorded_at`);

--
-- Indeks untuk tabel `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `trx_code` (`trx_code`),
  ADD KEY `fk_trx_creator` (`created_by`),
  ADD KEY `idx_trx_type_status` (`trx_type`,`trx_status`),
  ADD KEY `idx_trx_ref` (`reference_doc_no`);

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
  ADD KEY `idx_batches_fifo` (`item_id`,`qc_status`,`received_date`),
  ADD KEY `idx_batches_fefo` (`item_id`,`qc_status`,`expiry_date`),
  ADD KEY `fk_batches_supplier` (`supplier_id`);

--
-- Indeks untuk tabel `opname_counts`
--
ALTER TABLE `opname_counts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_opname_count` (`session_id`,`item_id`,`batch_id`);

--
-- Indeks untuk tabel `opname_sessions`
--
ALTER TABLE `opname_sessions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `session_code` (`session_code`),
  ADD KEY `idx_opname_bin_status` (`bin_id`,`status`),
  ADD KEY `fk_opname_creator` (`created_by`);

--
-- Indeks untuk tabel `po_receipts`
--
ALTER TABLE `po_receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_porcpt_trx` (`transaction_id`),
  ADD KEY `idx_porcpt_line` (`po_line_id`);

--
-- Indeks untuk tabel `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `po_number` (`po_number`),
  ADD UNIQUE KEY `uq_po_spk` (`spk_number`),
  ADD KEY `idx_po_status` (`status`,`order_date`),
  ADD KEY `fk_po_supplier` (`supplier_id`),
  ADD KEY `fk_po_creator` (`created_by`);

--
-- Indeks untuk tabel `purchase_order_lines`
--
ALTER TABLE `purchase_order_lines`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_po_item` (`po_id`,`item_id`),
  ADD KEY `fk_pol_item` (`item_id`);

--
-- Indeks untuk tabel `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `role_code` (`role_code`);

--
-- Indeks untuk tabel `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_role_perm` (`role_id`,`module_name`);

--
-- Indeks untuk tabel `stock_balances`
--
ALTER TABLE `stock_balances`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_stock_balance_loc` (`item_id`,`bin_id`,`batch_id`),
  ADD KEY `fk_sb_warehouse` (`warehouse_id`),
  ADD KEY `fk_sb_bin` (`bin_id`),
  ADD KEY `fk_sb_batch` (`batch_id`);

--
-- Indeks untuk tabel `stock_reservations`
--
ALTER TABLE `stock_reservations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_res_code` (`res_code`),
  ADD KEY `idx_res_item_status` (`item_id`,`status`),
  ADD KEY `idx_res_ref` (`reference_doc_no`),
  ADD KEY `idx_res_group` (`group_code`);

--
-- Indeks untuk tabel `stock_reservation_allocs`
--
ALTER TABLE `stock_reservation_allocs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_alloc_res` (`reservation_id`),
  ADD KEY `idx_alloc_bal` (`bin_id`,`batch_id`);

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
-- Indeks untuk tabel `supplier_credits`
--
ALTER TABLE `supplier_credits`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_credit_code` (`credit_code`),
  ADD KEY `idx_cr_supplier_status` (`supplier_id`,`status`),
  ADD KEY `idx_cr_ref` (`return_ref`);

--
-- Indeks untuk tabel `supplier_credit_apps`
--
ALTER TABLE `supplier_credit_apps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_app_credit` (`credit_id`),
  ADD KEY `idx_app_inv` (`invoice_id`);

--
-- Indeks untuk tabel `supplier_invoices`
--
ALTER TABLE `supplier_invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_inv_code` (`inv_code`),
  ADD KEY `idx_inv_status_due` (`status`,`due_date`),
  ADD KEY `idx_inv_supplier` (`supplier_id`),
  ADD KEY `idx_inv_po` (`po_id`);

--
-- Indeks untuk tabel `supplier_invoice_lines`
--
ALTER TABLE `supplier_invoice_lines`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_invl_inv` (`invoice_id`),
  ADD KEY `idx_invl_poline` (`po_line_id`);

--
-- Indeks untuk tabel `supplier_payments`
--
ALTER TABLE `supplier_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pay_inv` (`invoice_id`);

--
-- Indeks untuk tabel `supplier_terms`
--
ALTER TABLE `supplier_terms`
  ADD PRIMARY KEY (`supplier_id`);

--
-- Indeks untuk tabel `trx_drafts`
--
ALTER TABLE `trx_drafts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_draft_status` (`status`,`module_name`),
  ADD KEY `fk_draft_creator` (`created_by`);

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
  ADD UNIQUE KEY `username` (`username`),
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
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT untuk tabel `bom_components`
--
ALTER TABLE `bom_components`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT untuk tabel `bom_headers`
--
ALTER TABLE `bom_headers`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT untuk tabel `buyer_requests`
--
ALTER TABLE `buyer_requests`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT untuk tabel `inventory_ledger_entries`
--
ALTER TABLE `inventory_ledger_entries`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `items`
--
ALTER TABLE `items`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT untuk tabel `item_batches`
--
ALTER TABLE `item_batches`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `opname_counts`
--
ALTER TABLE `opname_counts`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `opname_sessions`
--
ALTER TABLE `opname_sessions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `po_receipts`
--
ALTER TABLE `po_receipts`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchase_orders`
--
ALTER TABLE `purchase_orders`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `purchase_order_lines`
--
ALTER TABLE `purchase_order_lines`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT untuk tabel `role_permissions`
--
ALTER TABLE `role_permissions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT untuk tabel `stock_balances`
--
ALTER TABLE `stock_balances`
  MODIFY `id` bigint NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `stock_reservations`
--
ALTER TABLE `stock_reservations`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `stock_reservation_allocs`
--
ALTER TABLE `stock_reservation_allocs`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `storage_bins`
--
ALTER TABLE `storage_bins`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT untuk tabel `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT untuk tabel `supplier_credits`
--
ALTER TABLE `supplier_credits`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `supplier_credit_apps`
--
ALTER TABLE `supplier_credit_apps`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `supplier_invoices`
--
ALTER TABLE `supplier_invoices`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `supplier_invoice_lines`
--
ALTER TABLE `supplier_invoice_lines`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `supplier_payments`
--
ALTER TABLE `supplier_payments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `trx_drafts`
--
ALTER TABLE `trx_drafts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT untuk tabel `units`
--
ALTER TABLE `units`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT untuk tabel `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT untuk tabel `warehouses`
--
ALTER TABLE `warehouses`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- Ketidakleluasaan untuk tabel pelimpahan (Dumped Tables)
--

--
-- Ketidakleluasaan untuk tabel `bom_components`
--
ALTER TABLE `bom_components`
  ADD CONSTRAINT `fk_bom_component` FOREIGN KEY (`component_item_id`) REFERENCES `items` (`id`),
  ADD CONSTRAINT `fk_bom_parent` FOREIGN KEY (`bom_id`) REFERENCES `bom_headers` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `bom_headers`
--
ALTER TABLE `bom_headers`
  ADD CONSTRAINT `fk_bom_target` FOREIGN KEY (`target_item_id`) REFERENCES `items` (`id`);

--
-- Ketidakleluasaan untuk tabel `buyer_requests`
--
ALTER TABLE `buyer_requests`
  ADD CONSTRAINT `fk_req_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_req_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  ADD CONSTRAINT `fk_req_poline` FOREIGN KEY (`po_line_id`) REFERENCES `purchase_order_lines` (`id`),
  ADD CONSTRAINT `fk_req_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  ADD CONSTRAINT `fk_req_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`);

--
-- Ketidakleluasaan untuk tabel `inventory_ledger_entries`
--
ALTER TABLE `inventory_ledger_entries`
  ADD CONSTRAINT `fk_ledger_batch` FOREIGN KEY (`batch_id`) REFERENCES `item_batches` (`id`),
  ADD CONSTRAINT `fk_ledger_bin` FOREIGN KEY (`bin_id`) REFERENCES `storage_bins` (`id`),
  ADD CONSTRAINT `fk_ledger_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  ADD CONSTRAINT `fk_ledger_trx` FOREIGN KEY (`transaction_id`) REFERENCES `inventory_transactions` (`id`),
  ADD CONSTRAINT `fk_ledger_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`);

--
-- Ketidakleluasaan untuk tabel `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  ADD CONSTRAINT `fk_trx_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

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
-- Ketidakleluasaan untuk tabel `opname_counts`
--
ALTER TABLE `opname_counts`
  ADD CONSTRAINT `fk_opcount_session` FOREIGN KEY (`session_id`) REFERENCES `opname_sessions` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `opname_sessions`
--
ALTER TABLE `opname_sessions`
  ADD CONSTRAINT `fk_opname_bin` FOREIGN KEY (`bin_id`) REFERENCES `storage_bins` (`id`),
  ADD CONSTRAINT `fk_opname_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Ketidakleluasaan untuk tabel `po_receipts`
--
ALTER TABLE `po_receipts`
  ADD CONSTRAINT `fk_porcpt_line` FOREIGN KEY (`po_line_id`) REFERENCES `purchase_order_lines` (`id`),
  ADD CONSTRAINT `fk_porcpt_trx` FOREIGN KEY (`transaction_id`) REFERENCES `inventory_transactions` (`id`);

--
-- Ketidakleluasaan untuk tabel `purchase_orders`
--
ALTER TABLE `purchase_orders`
  ADD CONSTRAINT `fk_po_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_po_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`);

--
-- Ketidakleluasaan untuk tabel `purchase_order_lines`
--
ALTER TABLE `purchase_order_lines`
  ADD CONSTRAINT `fk_pol_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  ADD CONSTRAINT `fk_pol_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `role_permissions`
--
ALTER TABLE `role_permissions`
  ADD CONSTRAINT `fk_perm_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;

--
-- Ketidakleluasaan untuk tabel `stock_balances`
--
ALTER TABLE `stock_balances`
  ADD CONSTRAINT `fk_sb_batch` FOREIGN KEY (`batch_id`) REFERENCES `item_batches` (`id`),
  ADD CONSTRAINT `fk_sb_bin` FOREIGN KEY (`bin_id`) REFERENCES `storage_bins` (`id`),
  ADD CONSTRAINT `fk_sb_item` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`),
  ADD CONSTRAINT `fk_sb_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`);

--
-- Ketidakleluasaan untuk tabel `storage_bins`
--
ALTER TABLE `storage_bins`
  ADD CONSTRAINT `fk_bins_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON UPDATE CASCADE;

--
-- Ketidakleluasaan untuk tabel `trx_drafts`
--
ALTER TABLE `trx_drafts`
  ADD CONSTRAINT `fk_draft_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`);

--
-- Ketidakleluasaan untuk tabel `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
