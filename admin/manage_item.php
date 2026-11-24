<?php
require_once __DIR__ . '/crud.php';

// Harga hanya tampil/bisa diubah untuk peran dengan hide_price = 0
$showPrice = canSeePrice('master');

$fields = [
    ['name' => 'sku', 'label' => 'SKU', 'required' => true, 'maxlength' => 40, 'placeholder' => 'DAJ-RM-TEAK-002'],
    ['name' => 'barcode_ean13', 'label' => 'Barcode', 'maxlength' => 32, 'placeholder' => 'kosong = dibuat otomatis'],
    ['name' => 'item_name', 'label' => 'Nama Barang', 'required' => true, 'maxlength' => 180],
    ['name' => 'category_id', 'label' => 'Kategori', 'type' => 'select', 'required' => true,
     'sql' => "SELECT id, CONCAT(category_code, ' - ', category_name) FROM categories ORDER BY category_code"],
    ['name' => 'unit_id', 'label' => 'Satuan', 'type' => 'select', 'required' => true,
     'sql' => "SELECT id, CONCAT(unit_code, ' - ', unit_name) FROM units ORDER BY unit_code"],
    ['name' => 'default_supplier_id', 'label' => 'Supplier Utama', 'type' => 'select',
     'sql' => "SELECT id, CONCAT(supplier_code, ' - ', company_name) FROM suppliers WHERE is_active = 1 ORDER BY supplier_code"],
    ['name' => 'length_mm', 'label' => 'Panjang (mm)', 'type' => 'number', 'required' => true, 'min' => 0, 'default' => 0],
    ['name' => 'width_mm',  'label' => 'Lebar (mm)',   'type' => 'number', 'required' => true, 'min' => 0, 'default' => 0],
    ['name' => 'height_mm', 'label' => 'Tinggi (mm)',  'type' => 'number', 'required' => true, 'min' => 0, 'default' => 0],
    ['name' => 'min_safety_stock', 'label' => 'Stok Pengaman', 'type' => 'number', 'step' => '0.0001', 'required' => true, 'min' => 0, 'default' => 0],
    ['name' => 'reorder_point', 'label' => 'Reorder Point', 'type' => 'number', 'step' => '0.0001', 'required' => true, 'min' => 0, 'default' => 0],
];
if ($showPrice) {
    $fields[] = ['name' => 'moving_avg_cost_idr', 'label' => 'Harga Rata-rata (Rp)', 'type' => 'number', 'step' => '0.0001', 'required' => true, 'min' => 0, 'default' => 0];
}
$fields[] = ['name' => 'is_active', 'label' => 'Aktif', 'type' => 'bool', 'default' => 1];

$columns = ['sku' => 'SKU', 'item_name' => 'Nama Barang', 'category_name' => 'Kategori',
            'unit_code' => 'Satuan', 'unit_cbm' => 'CBM / unit', 'reorder_point' => 'Reorder Point'];
if ($showPrice) {
    $columns['moving_avg_cost_idr'] = 'Harga Rata-rata';
}

/**
 * Barcode internal EAN-13 otomatis: awalan 200 (rentang pemakaian internal), 9 digit urut, 1 digit cek.
 * Dipakai bila kolom Barcode dikosongkan; scan di Barang Masuk mengenalinya sama seperti barcode pabrik.
 */
function itemNextInternalEan()
{
    global $connect;
    $rs = mysqli_query($connect, "SELECT MAX(CAST(SUBSTRING(barcode_ean13, 4, 9) AS UNSIGNED)) FROM items WHERE barcode_ean13 REGEXP '^200[0-9]{10}$'");
    $n = $rs ? (int)(mysqli_fetch_row($rs)[0] ?? 0) : 0;
    $body = '200' . str_pad((string)($n + 1), 9, '0', STR_PAD_LEFT);
    $sum = 0;
    for ($i = 0; $i < 12; $i++) $sum += (int)$body[$i] * ($i % 2 === 0 ? 1 : 3);
    return $body . ((10 - $sum % 10) % 10);
}

crudRun([
    'module' => 'master',
    'table'  => 'items',
    'soft'   => true,
    'title'  => 'Master Barang (Katalog)',
    'fields' => $fields,
    'before_save' => function (array $v) {
        // Kubikasi ekspor otomatis: (P x L x T) mm / 1.000.000.000 = meter kubik
        if (empty($v['barcode_ean13'])) $v['barcode_ean13'] = itemNextInternalEan();
        $v['unit_cbm'] = round(((float)$v['length_mm'] * (float)$v['width_mm'] * (float)$v['height_mm']) / 1000000000, 6);
        return $v;
    },
    'list_sql' => "SELECT i.*, c.category_name, u.unit_code
                   FROM items i
                   JOIN categories c ON c.id = i.category_id
                   JOIN units u ON u.id = i.unit_id
                   ORDER BY i.sku",
    'columns' => $columns,
    'money'   => ['moving_avg_cost_idr'],
]);