<?php
require_once __DIR__ . '/crud.php';
crudRun([
    'module' => 'master',
    'table'  => 'suppliers',
    'soft'   => true,
    'title'  => 'Master Supplier',
    'fields' => [
        ['name' => 'supplier_code', 'label' => 'Kode Supplier', 'required' => true, 'maxlength' => 24, 'placeholder' => 'SUP-003'],
        ['name' => 'company_name', 'label' => 'Nama Perusahaan', 'required' => true, 'maxlength' => 150],
        ['name' => 'contact_person', 'label' => 'Kontak Person', 'required' => true, 'maxlength' => 100],
        ['name' => 'phone', 'label' => 'Telepon (terenkripsi)', 'maxlength' => 30, 'encrypted' => true],
        ['name' => 'lead_time_days', 'label' => 'Lead Time (hari)', 'type' => 'number', 'required' => true, 'min' => 0, 'default' => 7],
        ['name' => 'is_svlk_certified', 'label' => 'Bersertifikat SVLK', 'type' => 'bool', 'default' => 1],
        ['name' => 'svlk_number', 'label' => 'No. Sertifikat SVLK', 'maxlength' => 60, 'placeholder' => 'wajib bila bersertifikat SVLK'],
        ['name' => 'svlk_expiry_date', 'label' => 'SVLK Berlaku Sampai', 'type' => 'date'],
        ['name' => 'is_active', 'label' => 'Aktif', 'type' => 'bool', 'default' => 1],
    ],
    // Status SVLK dihitung dari tanggal berlaku; kayu dari supplier KEDALUWARSA ditolak di PO dan Barang Masuk.
    'list_sql' => "SELECT s.*,
        CASE
          WHEN s.is_svlk_certified = 0 THEN 'Tidak bersertifikat'
          WHEN s.svlk_expiry_date IS NULL THEN 'Ya - data belum lengkap'
          WHEN s.svlk_expiry_date < CURDATE() THEN 'KEDALUWARSA'
          WHEN s.svlk_expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            THEN CONCAT('Segera habis (', DATEDIFF(s.svlk_expiry_date, CURDATE()), ' hari)')
          ELSE 'Berlaku'
        END AS svlk_status
        FROM suppliers s ORDER BY s.id ASC",
    'validate' => function (array $v): array {
        $err = [];
        if (($v['is_svlk_certified'] ?? '0') === '1') {
            if (($v['svlk_number'] ?? null) === null) {
                $err[] = 'No. Sertifikat SVLK wajib diisi untuk supplier bersertifikat SVLK.';
            }
            if (($v['svlk_expiry_date'] ?? null) === null) {
                $err[] = 'Tanggal berlaku SVLK wajib diisi untuk supplier bersertifikat SVLK.';
            }
        }
        return $err;
    },
    // Tanpa centang SVLK, nomor dan tanggal dikosongkan agar tidak menyisakan data basi.
    'before_save' => function (array $v): array {
        if (($v['is_svlk_certified'] ?? '0') !== '1') {
            $v['svlk_number'] = null;
            $v['svlk_expiry_date'] = null;
        }
        return $v;
    },
    'columns' => ['supplier_code' => 'Kode', 'company_name' => 'Perusahaan', 'contact_person' => 'Kontak',
                  'phone' => 'Telepon', 'lead_time_days' => 'Lead Time',
                  'svlk_status' => 'Status SVLK', 'svlk_number' => 'No. SVLK', 'svlk_expiry_date' => 'Berlaku s/d'],
]);
