<?php
require_once __DIR__ . '/crud.php';
crudRun([
    'module' => 'master',
    'table'  => 'storage_bins',
    'soft'   => true,
    'title'  => 'Master Rak / Storage Bin',
    'fields' => [
        ['name' => 'warehouse_id', 'label' => 'Gudang', 'type' => 'select', 'required' => true,
         'sql' => "SELECT id, CONCAT(warehouse_code, ' - ', warehouse_name) FROM warehouses WHERE is_active = 1 ORDER BY warehouse_code"],
        ['name' => 'bin_code', 'label' => 'Kode Rak', 'required' => true, 'maxlength' => 32,
         'placeholder' => 'WH01-Z01-R01-L01', 'pattern' => '/^WH\d{2}-Z\d{2}-R\d{2}-L\d{2}$/', 'hint' => 'contoh WH01-Z01-R01-L01'],
        ['name' => 'zone_name', 'label' => 'Nama Zona', 'required' => true, 'maxlength' => 60],
        ['name' => 'max_capacity_cbm', 'label' => 'Kapasitas Maks (CBM)', 'type' => 'number', 'step' => '0.0001', 'required' => true, 'min' => 0, 'default' => '50'],
        ['name' => 'is_frozen_for_opname', 'label' => 'Dibekukan untuk opname', 'type' => 'bool'],
        ['name' => 'is_active', 'label' => 'Aktif', 'type' => 'bool', 'default' => 1],
    ],
    'list_sql' => "SELECT b.*, w.warehouse_code FROM storage_bins b
                   JOIN warehouses w ON w.id = b.warehouse_id ORDER BY b.bin_code",
    'columns' => ['bin_code' => 'Kode Rak', 'warehouse_code' => 'Gudang', 'zone_name' => 'Zona',
                  'max_capacity_cbm' => 'Kapasitas CBM', 'is_frozen_for_opname' => 'Beku Opname'],
]);
