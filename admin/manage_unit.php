<?php
require_once __DIR__ . '/crud.php';
crudRun([
    'module' => 'master',
    'table'  => 'units',
    'title'  => 'Master Satuan Ukur',
    'fields' => [
        ['name' => 'unit_code', 'label' => 'Kode Satuan', 'required' => true, 'maxlength' => 12, 'placeholder' => 'PCS'],
        ['name' => 'unit_name', 'label' => 'Nama Satuan', 'required' => true, 'maxlength' => 50],
    ],
    'columns' => ['unit_code' => 'Kode', 'unit_name' => 'Nama'],
]);
