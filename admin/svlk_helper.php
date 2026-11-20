<?php
/**
 * Aturan SVLK supplier (dipakai Purchase Order dan Barang Masuk).
 * Lokasi: admin/svlk_helper.php
 */

/**
 * Kembalikan pesan alasan penolakan, atau null jika supplier boleh memasok kayu.
 * $supplier = baris tabel suppliers (butuh kolom is_svlk_certified dan svlk_expiry_date).
 * Supplier lama yang bercentang SVLK tetapi belum punya tanggal berlaku tetap dianggap sah
 * sampai datanya dilengkapi (statusnya tampil "data belum lengkap" di Master Supplier).
 */
if (!function_exists('svlkProblem')) {
    function svlkProblem(array $supplier): ?string
    {
        if (!(int)($supplier['is_svlk_certified'] ?? 0)) {
            return 'Supplier belum bersertifikat SVLK';
        }
        $exp = (string)($supplier['svlk_expiry_date'] ?? '');
        if ($exp !== '' && $exp < date('Y-m-d')) {
            return 'Sertifikat SVLK supplier sudah kedaluwarsa (berakhir ' . $exp . ')';
        }
        return null;
    }
}

/** Keterangan singkat untuk daftar pilihan supplier. */
if (!function_exists('svlkLabel')) {
    function svlkLabel(array $supplier): string
    {
        if (!(int)($supplier['is_svlk_certified'] ?? 0)) return '';
        return svlkProblem($supplier) === null ? ' (SVLK)' : ' (SVLK KEDALUWARSA)';
    }
}
