<?php
require_once __DIR__ . '/crud.php';
crudRun([
    'module' => 'master',
    'table'  => 'warehouses',
    'soft'   => true,
    'title'  => 'Master Gudang',
    'fields' => [
        ['name' => 'warehouse_code', 'label' => 'Kode Gudang', 'required' => true, 'maxlength' => 20, 'placeholder' => 'WH-RAW'],
        ['name' => 'warehouse_name', 'label' => 'Nama Gudang', 'required' => true, 'maxlength' => 100],
        ['name' => 'warehouse_type', 'label' => 'Tipe Gudang', 'type' => 'select', 'required' => true, 'options' => [
            'RAW_MATERIAL' => 'Bahan Baku', 'WIP_PRODUCTION' => 'WIP / Produksi',
            'FINISHED_GOODS' => 'Barang Jadi', 'QUARANTINE_QC' => 'Karantina QC',
            'VIRTUAL_COUNTERPART' => 'Akun Virtual']],
        ['name' => 'is_active', 'label' => 'Aktif', 'type' => 'bool', 'default' => 1],
    ],
    'columns' => ['warehouse_code' => 'Kode', 'warehouse_name' => 'Nama', 'warehouse_type' => 'Tipe'],
]);
