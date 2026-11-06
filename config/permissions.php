<?php declare(strict_types=1);

return [
    'roles' => [
        'STAFF_GUDANG' => ['name' => 'Staff Gudang', 'permissions' => [
            'catalog.view', 'report.access',
            'inbound.access', 'inbound.draft', 'inbound.scan',
            'outbound.access', 'outbound.scan',
            'bom.access', 'bom.prepare',
            'opname.access', 'opname.count',
        ]],
        'STAFF_PPIC' => ['name' => 'Staff PPIC', 'permissions' => [
            'catalog.view', 'report.access',
            'master.access', 'master.draft',
            'inbound.access', 'inbound.verify',
            'outbound.access', 'outbound.request',
            'bom.access', 'bom.execute',
            'opname.access', 'opname.progress',
        ]],
        'KEPALA_GUDANG' => ['name' => 'Kepala Gudang', 'permissions' => [
            'catalog.view', 'catalog.view_price', 'report.access',
            'master.access', 'master.approve',
            'inbound.access', 'inbound.approve',
            'outbound.access', 'outbound.approve',
            'bom.access', 'bom.approve',
            'opname.access', 'opname.review',
            'audit.access', 'audit.view',
        ]],
        'AUDITOR_FINANCE' => ['name' => 'Auditor / Finance', 'permissions' => [
            'catalog.view', 'catalog.view_price', 'report.access',
            'master.access', 'inbound.access', 'outbound.access', 'bom.access',
            'opname.access', 'opname.valuation',
            'audit.access', 'audit.view', 'audit.verify',
        ]],
        'SUPER_ADMIN' => ['name' => 'Super Admin IT', 'permissions' => ['*']],
    ],

    'menus' => [
        'master'   => ['label' => '1. Data Master',            'permission' => 'master.access'],
        'inbound'  => ['label' => '2. Barang Masuk',           'permission' => 'inbound.access'],
        'outbound' => ['label' => '3. Barang Keluar',          'permission' => 'outbound.access'],
        'bom'      => ['label' => '4. Produksi (Resep / BoM)', 'permission' => 'bom.access'],
        'opname'   => ['label' => '5. Stock Opname',           'permission' => 'opname.access'],
        'report'   => ['label' => '6. Laporan dan Scan',       'permission' => 'report.access'],
        'audit'    => ['label' => 'Audit Log',                 'permission' => 'audit.access'],
    ],
];
