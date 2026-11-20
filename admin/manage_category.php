<?php
require_once __DIR__ . '/crud.php';
crudRun([
    'module' => 'master',
    'table'  => 'categories',
    'title'  => 'Master Kategori Material',
    'fields' => [
        ['name' => 'category_code', 'label' => 'Kode Kategori', 'required' => true, 'maxlength' => 20, 'placeholder' => 'CAT-WOOD'],
        ['name' => 'category_name', 'label' => 'Nama Kategori', 'required' => true, 'maxlength' => 100],
        ['name' => 'category_type', 'label' => 'Tipe', 'type' => 'select', 'required' => true, 'options' => [
            'RAW_MATERIAL' => 'Bahan Baku', 'HALF_FINISHED' => 'Setengah Jadi',
            'CHEMICAL' => 'Kimia', 'PACKAGING' => 'Kemasan', 'FINISHED_GOOD' => 'Barang Jadi']],
        ['name' => 'allocation_strategy', 'label' => 'Strategi Alokasi', 'type' => 'select', 'required' => true, 'options' => [
            'FIFO' => 'FIFO (masuk pertama keluar pertama)', 'FEFO' => 'FEFO (kedaluwarsa pertama keluar pertama)']],
        ['name' => 'requires_expiry_date', 'label' => 'Wajib tanggal kedaluwarsa', 'type' => 'bool'],
    ],
    'columns' => ['category_code' => 'Kode', 'category_name' => 'Nama', 'category_type' => 'Tipe',
                  'allocation_strategy' => 'Strategi', 'requires_expiry_date' => 'Wajib Expired'],
]);
