-- ========================================================
-- MIGRASI v39: lampiran scan faktur & PPN per baris faktur
-- Jalankan SETELAH migration_v38.sql, sekali saja.
-- Bila muncul error "Duplicate column name", kolom itu sudah ada: lewati barisnya.
-- Instalasi baru dari dekorwms.sql TIDAK memerlukan berkas ini.
-- ========================================================

SET NAMES utf8mb4;

-- supplier_invoices.vat_idr
ALTER TABLE `supplier_invoices` ADD COLUMN `vat_idr` decimal(18,2) NOT NULL DEFAULT '0.00' COMMENT 'PPN = jumlah PPN seluruh baris faktur' AFTER `goods_idr`;

-- supplier_invoices.attachment_file
ALTER TABLE `supplier_invoices` ADD COLUMN `attachment_file` varchar(120) NULL DEFAULT NULL COMMENT 'Nama file scan faktur di admin/uploads/invoices';

-- supplier_invoices.attachment_name
ALTER TABLE `supplier_invoices` ADD COLUMN `attachment_name` varchar(150) NULL DEFAULT NULL;

-- supplier_invoices.attachment_at
ALTER TABLE `supplier_invoices` ADD COLUMN `attachment_at` datetime NULL DEFAULT NULL;

-- supplier_invoice_lines.vat_pct
ALTER TABLE `supplier_invoice_lines` ADD COLUMN `vat_pct` decimal(5,2) NOT NULL DEFAULT '0.00' COMMENT 'PPN (%) baris ini';

