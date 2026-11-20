WMS PHP NATIVE - PT. DEKOR ASIA JAYAKARYA
Diperbarui: 20 November 2026 (database\dekorwms.sql: 31 tabel, sudah termasuk tabel yang dulu dibuat otomatis, lihat bagian 8)
Ringkasan: aplikasi gudang (Warehouse Management System) berbasis PHP + MySQL, dijalankan lokal lewat Laragon.
Bahasa antarmuka: Indonesia dan Inggris (tombol ID | EN, lihat bagian 6).

======================================================================
1. PEMASANGAN (Windows + Laragon)
======================================================================
1. Install Laragon (Full), klik "Start All" sampai Apache dan MySQL berstatus hijau.
2. Salin folder "dekorwms" ke C:\laragon\www\  (hasil: C:\laragon\www\dekorwms\admin\login.php).
3. Buat database: buka HeidiSQL (klik kanan ikon Laragon > MySQL > HeidiSQL) atau http://localhost/phpmyadmin,
   lalu impor database\dekorwms.sql. Database "dekorwms" terbuat otomatis.
   File ini LENGKAP dan menjadi satu-satunya sumber skema (31 tabel). Untuk instalasi baru tidak ada file migrasi
   yang perlu dijalankan.
   PERINGATAN: mengimpor dekorwms.sql pada database yang sudah berisi data AKAN MENIMPA data tersebut.
4. Salin admin\config.local.php.example menjadi admin\config.local.php, lalu isi kredensial database dan APP_KEY
   (lihat bagian 5 butir 1). File config.local.php tidak boleh dibagikan atau diunggah (berisi password dan APP_KEY).
5. Buka http://localhost/dekorwms/admin/login.php
   (setelah "Reload" Apache di Laragon, alamat http://dekorwms.test/admin/login.php juga bisa).
6. Akun yang ada di dekorwms.sql: admin (Super Admin IT), kepala, ppic, gudang, finance.
   Password pada berkas ini SUDAH BUKAN 123456, jadi pakai password yang ditetapkan pemilik proyek.
   Bila lupa, buat hash baru lalu perbarui kolom password_hash tabel users lewat HeidiSQL:
       php -r "echo password_hash('PasswordBaru', PASSWORD_BCRYPT);"
   Sistem memaksa ganti password yang bawaan/lemah lebih dulu (klik nama di pojok kanan atas > Profil).
7. Jika muncul "Koneksi Database Gagal": pastikan MySQL hijau di Laragon, lalu cocokkan $db_user / $db_pass
   di admin\config.local.php (bawaan Laragon: user root, password kosong).
Syarat: PHP 8.0+ dengan ekstensi mysqli, openssl, mbstring, gd. Ekstensi zip dianjurkan (ekspor .xlsx).

Jika browser menampilkan "localhost refused to connect" (web server belum jalan / port 80 bentrok):
  klik dua kali jalankan.bat di folder dekorwms. Jendela hitam terbuka (jangan ditutup) dan browser membuka
  http://localhost:8080/admin/cek_sistem.php. Halaman "Diagnosa Pemasangan" mengecek PHP, ekstensi, MySQL, database,
  dan tabel, lengkap dengan petunjuk perbaikan. Jika semua hijau, klik "Buka halaman login".
  (MySQL tetap harus hijau di Laragon.)

Membuka dari HP (satu Wi-Fi dengan komputer server):
  http://IP-KOMPUTER:8081/dekorwms/admin/login.php  (sesuaikan port Apache Anda; IP dilihat lewat CMD > ipconfig > IPv4 Address).
  Jika tidak bisa dibuka, izinkan port tersebut di Windows Firewall (Inbound Rule, TCP).
  Di Chrome HP: menu titik tiga > "Tambahkan ke layar utama" agar menjadi ikon seperti aplikasi.

======================================================================
2. PERAN DAN HAK AKSES
======================================================================
Peran bawaan: Staff Gudang, Staff PPIC, Kepala Gudang, Auditor / Finance, Super Admin IT, Buyer / Purchasing.
Hak per modul (Lihat / Buat / Setujui / Sembunyikan harga) diatur Super Admin di menu Sistem > Hak Akses.
Peran Super Admin dikunci agar tidak bisa mengunci dirinya sendiri.
Menu otomatis menyesuaikan hak akses. Menu Pengguna, Hak Akses, dan Kesiapan Produksi (grup Sistem) khusus Super Admin.

Draft -> Persetujuan: peran yang punya hak Buat tetapi tanpa hak Setujui (mis. Staff Gudang, Staff PPIC) pada Barang Masuk,
Barang Keluar, Transfer Rak, Produksi, Retur, dan perubahan Data Master tidak langsung mengubah data; isiannya menjadi
draft di menu Persetujuan. Pemegang hak Setujui menekan "Setujui & Posting" (validasi + kunci stok yang sama, pembuat =
pengaju, penyetuju = penekan) atau "Tolak" (alasan wajib). Pengaju bisa membatalkan drafnya.
Catatan: stok belum divalidasi saat draft diajukan; kesalahan baru terlihat saat disetujui (draft tetap PENDING, bisa ditolak).

Audit Log dapat dibuka semua peran yang punya hak Lihat Audit Log. Tombol "Bersihkan Log" tersedia untuk semua peran
tersebut (atas permintaan pemilik proyek); wajib mengetik HAPUS dan satu catatan "log dibersihkan oleh ..." tetap dibuat.
Untuk membatasinya ke Super Admin: ubah $canClear di admin\manage_audit_log.php.

======================================================================
3. DAFTAR MENU (admin\)
======================================================================
UTAMA
- Dashboard: kartu ringkasan, TREN TRANSAKSI 14 HARI (grafik masuk vs keluar), peringatan stok minimum (tombol Buat PO),
  lot kedaluwarsa 90 hari, transaksi terakhir, keterisian rak. Nilai persediaan hanya untuk peran yang boleh melihat harga.

DATA MASTER: Satuan, Kategori, Supplier (nomor + masa berlaku SVLK, lead time, telepon terenkripsi), Gudang, Rak / Bin,
  Barang (CBM otomatis), Foto Acuan Barang. Perubahan oleh peran tanpa hak Setujui menjadi draft.

TRANSAKSI
- Purchase Order: PO multi-barang, penerimaan sebagian (OPEN -> PARTIAL -> CLOSED, bisa ditutup/dibatalkan). Kayu hanya dari
  supplier ber-SVLK yang masih berlaku. "Batas kirim" terisi otomatis = tanggal PO + lead time supplier. Cetak SPK dari PO.
  Saran PO dari stok minimum (manage_po.php?suggest=1).
- SPK Supplier sudah digabung ke Purchase Order: setiap PO otomatis punya nomor SPK dan surat cetak berisi foto acuan barang.
- Barang Masuk: lot baru, MC kayu > 12% otomatis HOLD_QUARANTINE, wajib SVLK untuk kayu, cek kapasitas CBM, validasi jenis
  gudang/rak sesuai kategori, harga rata-rata bergerak, terima dari PO/SPK. Foto barang datang WAJIB (kamera atau galeri),
  dikecilkan dan DICAP waktu server (hari, tanggal, tahun, jam:menit:detik WIB). Ada kotak scan barcode (SKU, EAN-13,
  "SKU/NO.LOT", kode rak, nomor SPK/PO).
- Karantina QC: lulus (kayu wajib MC <= 12%), tolak, tahan ulang, scrap stok ditolak. Alasan wajib; tercatat di Audit Log.
- Retur Supplier: lot yang DITOLAK QC bisa diretur; stok turun lewat Buku Besar (RTR-xxxx); Surat Retur bisa dicetak.
  Retur otomatis membuat KREDIT (jumlah x harga lot). Di Hutang Supplier nilainya disesuaikan dengan nota kredit supplier lalu
  diselesaikan: potong faktur, barang pengganti, atau uang kembali. Retur lama bisa dibuatkan kredit manual dari daftar
  "Retur yang belum punya kredit".
- Barang Keluar: alokasi otomatis FIFO/FEFO, hanya lot QC PASSED, rak tidak beku, belum kedaluwarsa. Bila No. SPK sama dengan
  reservasi aktif untuk barang itu, reservasi otomatis dipakai dulu. Kolom scan juga mengenali nomor dokumen SPK-/PO-/WO-/INV-/EXP-/SJ-/SO-.
- Reservasi Stok: tahan stok (rak + lot, FIFO/FEFO) untuk SPK / work order / invoice, per barang atau sekaligus dari BoM. Tampil di kolom
  Dipesan Saldo Stok; tidak bisa diambil pesanan lain. Hak akses = modul Barang Keluar (peran tanpa hak Setujui mengajukan draft).
  Bisa dilepas (alasan wajib). Reservasi yang lewat tanggal dibutuhkan muncul di notifikasi dan DILEPAS OTOMATIS setelah masa tenggang
  (bawaan 3 hari; ubah dengan define('RSV_AUTO_RELEASE_GRACE_DAYS', n); di config.local.php). Pelepasan berjalan saat ada yang membuka halaman
  (tanpa cron), tercatat di Audit Log, dan stoknya kembali tersedia.
- Hutang Supplier (modul 'payable'): faktur supplier (dikaitkan ke PO: jumlah dibatasi yang sudah diterima, harga faktur dibanding harga PO
  = selisih harga), termin & jatuh tempo, pembayaran, pengingat jatuh tempo/terlambat di notifikasi, PPN per baris faktur (%), lampiran
  scan faktur (PDF/JPG/PNG maks. 5 MB; dibuka lewat invoice_file.php dengan pemeriksaan hak akses, tidak bisa dibuka langsung lewat URL),
  dan kredit/klaim retur.
- Transfer Rak: pasangan KREDIT/DEBIT.
- Produksi: komponen dipotong sesuai BoM + scrap, barang jadi masuk sebagai lot PRD-..., dalam satu transaksi database.
- Persetujuan: draft menunggu keputusan.
- Pembatalan: jurnal koreksi (buku besar tetap append-only), transaksi asal menjadi CANCELLED.

STOK & LAPORAN: Lot Batch, Saldo Stok (cari + cetak label rak/lot), Buku Besar (baca saja), Laporan (Kartu Stok, Barang Masuk
  per Supplier, Barang Keluar, Daftar PO; ekspor Excel dan cetak/PDF), Penilaian Supplier (ketepatan kirim, % lot ditolak QC,
  jumlah retur), BoM (resep, scrap %, kalkulator kebutuhan), Stock Opname (rak dibekukan, hitung buta, posting selisih dengan
  nilai Rupiah untuk Finance).

SISTEM: Audit Log (rantai hash SHA-256 + verifikasi), Pengguna, Hak Akses, Backup & Pulihkan (khusus Super Admin),
  Kesiapan Produksi (daftar periksa keamanan + Pembersih Data Uji, bagian 5).

Fitur umum: ekspor Excel .xlsx asli (kembali ke CSV bila ekstensi zip mati), cetak dokumen ber-barcode Code 128 (print_trx,
print_labels, print_spk, print_return), notifikasi (stok minimum, lot kedaluwarsa/hampir kedaluwarsa), mode gelap/terang,
sidebar yang bisa diciutkan, pencarian cepat di header (tombol "/"), tampilan nyaman di HP, dua bahasa (Indonesia / Inggris).

Setelah barang masuk dan QC PASSED, stok tersedia di Saldo Stok; bila HOLD_QUARANTINE, ada di Karantina QC sampai diputuskan.

======================================================================
4. ALUR BUYER -> SUPPLIER -> GUDANG
======================================================================
Foto acuan/spesifikasi barang ada di master Barang (menu Foto Acuan Barang). Buyer menerbitkan SPK di menu Purchase Order
(PO dibuat otomatis + surat SPK siap cetak) -> supplier membawa barang + surat -> petugas gudang menerima di Barang Masuk
(pilih baris SPK/PO; foto acuan tampil sebagai pembanding; foto kedatangan diunggah dan dicap waktu).
Catatan: menu "Permintaan Buyer" sudah dihapus dari tampilan (tabel buyer_requests masih ada di database).

======================================================================
5. KEAMANAN DAN PERSIAPAN PRODUKSI
======================================================================
Sudah ada: password bcrypt, wajib ganti password default/lemah, captcha + kunci akun 5 kali gagal selama 5 menit,
CSRF di form transaksi dan login, cookie sesi HttpOnly + SameSite, logout otomatis setelah 4 jam (SESSION_IDLE_LIMIT),
header X-Frame-Options/nosniff, folder uploads memblokir eksekusi PHP, telepon supplier terenkripsi AES-256-GCM.

Menu Sistem > Kesiapan Produksi memeriksa butir-butir di bawah secara otomatis (APP_KEY, password 123456, akun demo, root tanpa
password, cek_sistem.php, HTTPS, display_errors, .htaccess uploads, ekstensi PHP, rantai Audit Log) dan menyediakan
Pembersih Data Uji. Pembersih mengosongkan HANYA data transaksi (stok, lot, buku besar, PO, draft, opname, permintaan Buyer,
reservasi, hutang supplier beserta lampiran fakturnya) dan foto kedatangan; data master, pengguna, hak akses, dan foto acuan
barang tetap. Syaratnya: mengetik RESET DATA UJI, dan memasukkan password sendiri. Audit Log hanya dihapus bila dicentang.

Sebelum dipakai sungguhan:
 1. Salin admin\config.local.php.example menjadi config.local.php, isi APP_KEY acak (perintah:
    php -r "echo bin2hex(random_bytes(32));") dan kredensial DB. JANGAN ganti APP_KEY setelah ada data terenkripsi.
 2. Ganti semua password demo; nonaktifkan akun demo yang tidak dipakai.
 3. Buat user MySQL khusus (jangan root tanpa password) dan isi di config.local.php.
 4. Hapus admin\cek_sistem.php.
 5. Jalankan lewat Apache (Laragon), bukan jalankan.bat (itu server uji PHP). Pakai HTTPS bila diakses lewat jaringan.
 6. Bersihkan data uji (transaksi, audit log, foto di admin\uploads\) atau buat database baru dari dekorwms.sql, lalu
    masukkan saldo awal lewat Barang Masuk agar buku besar lengkap.
 7. Jangan bagikan atau unggah zip/repositori yang berisi config.local.php, folder admin\backups\ (berisi cadangan database
    dan APP_KEY), maupun foto di admin\uploads\. Bila memakai Git, masukkan semuanya ke .gitignore.
Saldo awal seeder (teak, lem) punya entri buku besar IN-SALDOAWAL dan tidak bisa dibatalkan.

Backup & Pulihkan (menu Sistem, khusus Super Admin): cadangan = database + foto + APP_KEY dalam satu zip, disimpan di
admin\backups\. Memulihkan wajib memasukkan password sendiri dan mengetik PULIHKAN. Jadwalkan backup_otomatis.bat lewat
Windows Task Scheduler (memanggil admin\backup_cli.php). Lampiran faktur ikut tercadangkan.

======================================================================
6. MULTI BAHASA (INDONESIA / INGGRIS)
======================================================================
Tombol ID | EN tersedia di halaman login dan header. Bahasa aktif ditentukan berurutan: ?lang=xx -> sesi -> cookie wmsLang -> default
(Indonesia). Cookie dipakai karena logout menghapus sesi.

Berkas:
  admin\lang.php          mesin terjemahan: __(), __e(), tombol ID|EN, penanganan ?lang=xx
  admin\lang\en.php       kamus Inggris
  admin\lang_check.php    pemeriksa kamus (command line): php admin\lang_check.php

Cara pakai di kode:
  <?= __e('Simpan') ?>                          cetak teks, sudah di-escape HTML
  $x = __('Draft #{id} dari {nama}', ['id' => 5, 'nama' => $n]);
  JavaScript: kirim lewat <?= wmsJs([...]) ?>   (lihat layout.php)
Teks sumber = bahasa Indonesia apa adanya. Teks yang belum ada di kamus tampil dalam bahasa Indonesia (tidak pernah kosong/rusak).
Bentuk jamak: nilai kamus berupa ['1 item', '{n} items'], dipilih lewat parameter 'n'.

Menambah terjemahan:
  1. Kamus berformat file PHP: return [ 'teks Indonesia' => 'English', ... ];  Kunci = teks Indonesia PERSIS seperti di kode.
  2. Tambahkan entri ke admin\lang\en.php.
  3. Jalankan  php admin\lang_check.php  untuk melihat teks di kode yang belum ada di kamus.
  4. Opsional: di config.local.php tambahkan  define('WMS_LANG_DEBUG', true);  maka teks yang belum diterjemahkan dan sempat
     ditampilkan dicatat ke admin\lang\missing.en.txt (matikan setelah selesai).

Yang sengaja TIDAK diterjemahkan: isi Audit Log (disimpan di database dan memakai rantai hash) dan data (nama supplier, SKU,
label draft, nama peran) karena itu isi database, bukan teks antarmuka.
Yang sudah diterjemahkan: halaman login, menu, grup menu, judul halaman, header (sapaan, jam, lonceng, banner, kotak scan),
notifikasi. Halaman manage_*.php, profile.php, index.php, dan print_*.php masih berbahasa Indonesia, kecuali judul halamannya.

======================================================================
7. MASALAH UMUM
======================================================================
- Foto gagal diunggah karena ukuran: naikkan upload_max_filesize dan post_max_size di php.ini, restart Apache.
- Foto kedatangan tidak bisa diproses: pastikan ekstensi GD aktif (bawaan Laragon).
- Ekspor menjadi .csv bukan .xlsx: aktifkan ekstensi PHP zip.
- Pencap waktu foto memakai font arial.ttf Windows bila ada, jika tidak font bawaan GD.
- Banner merah "APP_KEY masih bawaan": lihat bagian 5 butir 1.
- Tabel atau kolom tambahan (reservasi, hutang supplier, lampiran faktur) dibuat otomatis oleh admin\schema_ext.php saat halaman
  terkait dibuka. Pada database lama, user MySQL harus punya hak CREATE dan ALTER; bila tidak, beri hak itu sementara atau
  impor dekorwms.sql ke database baru.
- Teks tampil dalam bahasa Indonesia padahal bahasa Inggris dipilih: teks itu belum ada di admin\lang\en.php (lihat bagian 6).

======================================================================
8. RIWAYAT PERKEMBANGAN (RINGKAS)
======================================================================
Tahap awal
  - Data master, Barang Masuk/Keluar (FIFO/FEFO), transfer rak, buku besar, audit log berantai hash, manajemen pengguna
    dan matriks hak akses (bcrypt, buka kunci, minimal 1 admin aktif).
  - Stock Opname (hitung buta), BoM, Produksi (dua transaksi dalam satu transaksi database).
  - Dashboard, ekspor, cetak dokumen dan label barcode Code 128 buatan sendiri (tanpa CDN).
  - Karantina QC, pembatalan lewat jurnal koreksi.
  - Keamanan dasar (ganti password, sesi, header).
Alur pengadaan
  - Draft -> Persetujuan, CSRF login, scan barcode, enkripsi telepon supplier, saldo awal ber-ledger.
  - Purchase Order + penerimaan sebagian.
  - Foto kedatangan barang (wajib, dikecilkan, diputar tegak); kolom telepon supplier diperlebar.
  - Modul Buyer + SPK Supplier, foto acuan di master Barang, jam hidup + cap waktu foto, peran Buyer.
  - SVLK (nomor + masa berlaku), lead time otomatis di PO, jenis rak divalidasi saat Barang Masuk, nilai Rupiah selisih
    opname untuk Finance, foto kedatangan dari kamera atau galeri, Audit Log untuk semua peran.
Laporan dan tampilan
  - Menu Laporan (Kartu Stok, Masuk per Supplier, Keluar, Daftar PO).
  - Saran PO dari stok minimum + notifikasi kedaluwarsa; ekspor .xlsx asli.
  - Retur Supplier + Surat Retur; Penilaian Supplier.
  - Mode gelap/terang; perapian tampilan, sidebar tetap/ciut, header (sapaan, jam, pencarian cepat), tampilan HP.
  - Grafik tren transaksi 14 hari di Dashboard.
Penyempurnaan terakhir
  - Barcode barang otomatis (EAN-13 internal, awalan 200) bila kolom dikosongkan di Master Barang.
  - Menu SPK Supplier digabung ke Purchase Order (satu form; manage_spk.php tinggal pengalih ke manage_po.php).
  - dekorwms.sql memuat CREATE DATABASE/USE; config.local.php tidak lagi disertakan, buat dari .example.
  - Menu Kesiapan Produksi; cek_sistem.php diperbaiki (cek APP_KEY tidak lagi selalu gagal).
  - Reservasi Stok (manage_reservation.php + reservation_helper.php); Hutang Supplier + kredit retur
    (manage_payable.php, payable_helper.php); scan nomor dokumen di kolom scan.
  - Backup & Pulihkan dipasang kembali (manage_backup.php, backup_helper.php, backup_cli.php, backup_otomatis.bat).
  - Pelepasan OTOMATIS reservasi yang lewat tanggal dibutuhkan + masa tenggang; PPN per baris faktur supplier; lampiran scan
    faktur (admin\uploads\invoices, invoice_file.php). Tabel dan kolom baru dibuat otomatis oleh schema_ext.php.
  - Multi bahasa Indonesia / Inggris (lang.php, lang\en.php, lang_check.php).

======================================================================
9. BELUM ADA
======================================================================
- Reservasi dilepas otomatis hanya saat ada pengguna membuka halaman (tidak ada penjadwal/cron).
- Posting Stock Opname / pembatalan yang menurunkan stok di bawah jumlah yang dipesan ditolak sampai reservasinya dilepas.
- PPN dihitung per baris faktur yang terkait PO; faktur tanpa PO memakai satu persentase untuk seluruh nilai barang.
- Terjemahan Inggris belum mencakup halaman manage_*.php, profile.php, index.php, dan print_*.php.