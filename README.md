# Sistem Informasi Persediaan - Distributor Frozen Food

Sistem informasi persediaan (Inventory Management System) berbasis web untuk mengelola stok barang frozen food dengan multi-lokasi (Gudang dan Toko), multi-role (Pemilik, Staff Gudang, Staff Toko), dan fitur lengkap untuk manajemen persediaan, transfer barang, penjualan, dan laporan.

## 📋 Daftar Isi

- [Fitur Utama](#fitur-utama)
- [Teknologi yang Digunakan](#teknologi-yang-digunakan)
- [Struktur Project](#struktur-project)
- [Persyaratan Sistem](#persyaratan-sistem)
- [Instalasi](#instalasi)
- [Konfigurasi](#konfigurasi)
- [Penggunaan](#penggunaan)
- [Database Schema](#database-schema)
- [Role dan Akses](#role-dan-akses)
- [Fitur Detail](#fitur-detail)

## 🚀 Fitur Utama

### 1. **Manajemen Stock**
- Tracking stock real-time untuk Gudang dan Toko
- Stock history dengan detail perubahan (PEMESANAN, TRANSFER, OPNAME, RUSAK, PENJUALAN, KOREKSI)
- Kartu stock untuk tracking per barang per lokasi
- Stock opname dengan perhitungan selisih otomatis
- Minimum dan maximum stock alert
- Perhitungan POQ (Periodic Order Quantity)

### 2. **Transfer Barang**
- Transfer barang dari Gudang ke Toko dengan sistem batch (FEFO - First Expired First Out)
- Tracking status transfer (PESAN, DIKIRIM, SELESAI)
- Koreksi transfer untuk status DIKIRIM dan SELESAI
- Surat jalan otomatis
- Detail transfer dengan batch tracking

### 3. **Point of Sale (POS)**
- Sistem kasir untuk penjualan di toko
- Cetak nota otomatis
- Perhitungan gross profit otomatis
- Tracking penjualan per item

### 4. **Master Data**
- Master Barang (dengan gambar, berat, satuan per dus)
- Master Merek
- Master Kategori Barang
- Master Supplier
- Master Lokasi (Gudang/Toko)
- Master User dengan role-based access

### 5. **Laporan**
- Laporan Penjualan (Volume Transaksi, Kuantitas Penjualan, Pendapatan, Beban Pokok, Laba Kotor)
- Laporan Mutasi Barang Rusak (Gudang & Toko)
- Laporan Stock Opname (Gudang & Toko)
- Laporan POQ (Periodic Order Quantity)
- Kartu Stock (Gudang & Toko)
- Riwayat Pembelian
- Riwayat Resupply (Gudang & Toko)
- Download laporan dalam format Excel

### 6. **Fitur Tambahan**
- Mutasi barang rusak dengan kompensasi
- Tracking expired date per batch
- Biaya operasional
- Dashboard dengan statistik real-time
- Export data ke Excel

## 💻 Teknologi yang Digunakan

- **Backend:** PHP 7.4+
- **Database:** MySQL 5.7+ / MariaDB
- **Frontend:** 
  - HTML5, CSS3, JavaScript
  - Bootstrap 5.3.0
  - jQuery
  - DataTables
  - SweetAlert2
  - Chart.js (untuk dashboard)
- **Server:** XAMPP / Apache
- **Other:** 
  - UUID Generator untuk ID unik
  - FPDF / PHPExcel untuk export

## 📁 Struktur Project

```
skripsi/
├── assets/
│   ├── css/
│   │   └── style.css          # Custom CSS
│   └── images/
│       └── barang/            # Gambar produk
├── gudang/                    # Modul Staff Gudang
│   ├── dashboard.php
│   ├── barang_masuk.php       # Penerimaan barang dari supplier
│   ├── perpindahan_barang.php # Transfer ke toko
│   ├── stock_opname.php
│   ├── mutasi_barang_rusak.php
│   ├── cetak_label_batch.php
│   └── includes/
│       ├── sidebar.php
│       └── sidebar.js
├── toko/                      # Modul Staff Toko
│   ├── dashboard.php
│   ├── point_of_sale.php      # Sistem kasir
│   ├── barang_masuk.php       # Penerimaan dari gudang
│   ├── stock_opname.php
│   ├── mutasi_barang_rusak.php
│   ├── cetak_nota.php
│   └── includes/
│       ├── sidebar.php
│       └── sidebar.js
├── pemilik/                   # Modul Pemilik/Owner
│   ├── dashboard.php
│   ├── stock.php              # Daftar stock
│   ├── stock_detail_gudang.php
│   ├── stock_detail_toko.php
│   ├── kartu_stock_gudang.php
│   ├── kartu_stock_toko.php
│   ├── master_barang.php
│   ├── master_merek.php
│   ├── master_kategori.php
│   ├── master_supplier.php
│   ├── master_lokasi.php
│   ├── master_user.php
│   ├── laporan_penjualan.php
│   ├── laporan_mutasi_barang_rusak_gudang.php
│   ├── laporan_mutasi_barang_rusak_toko.php
│   ├── laporan_stock_opname_gudang.php
│   ├── laporan_stock_opname_toko.php
│   ├── laporan_poq.php
│   ├── riwayat_pembelian.php
│   ├── riwayat_pembelian_gudang.php
│   ├── riwayat_resupply.php
│   ├── riwayat_resupply_gudang.php
│   ├── riwayat_resupply_toko.php
│   ├── lihat_expired.php
│   ├── lihat_biaya_operasional.php
│   ├── download_*.php          # Download laporan Excel
│   └── includes/
│       ├── sidebar.php
│       ├── sidebar.js
│       └── datatables-init.js
├── includes/
│   └── uuid_generator.php     # Generator ID unik
├── dummy_data_combined/       # Generator data dummy
│   ├── generate_complete_dummy.py
│   ├── generate_sql_insert.py
│   └── output/
├── dbconnect.php              # Koneksi database
├── dbFrozenFood.sql           # Database schema
├── index.php                  # Halaman login
└── logout.php
```

## 📋 Persyaratan Sistem

- **Server:** XAMPP / WAMP / LAMP
- **PHP:** 7.4 atau lebih tinggi
- **MySQL:** 5.7 atau lebih tinggi (atau MariaDB 10.2+)
- **Web Browser:** Chrome, Firefox, Edge (versi terbaru)
- **Extension PHP:**
  - mysqli
  - mbstring
  - gd (untuk gambar)
  - zip (untuk export Excel)

## 🔧 Instalasi

### 1. Clone Repository

```bash
git clone https://github.com/username/skripsi.git
cd skripsi
```

### 2. Setup Database

1. Buka phpMyAdmin atau MySQL client
2. Import file `dbFrozenFood.sql`:
   ```sql
   mysql -u root -p < dbFrozenFood.sql
   ```
   Atau melalui phpMyAdmin: Import → Pilih file `dbFrozenFood.sql`

### 3. Konfigurasi Database

Edit file `dbconnect.php`:

```php
<?php
$servername = "localhost";
$username = "root";        // Sesuaikan dengan username MySQL Anda
$password = "";            // Sesuaikan dengan password MySQL Anda
$dbname = "skripsi";       // Nama database

// Global domain variable
$GLOBALS['domain'] = "http://localhost/skripsi";  // Sesuaikan dengan URL project Anda

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>
```

### 4. Setup Web Server

**XAMPP:**
1. Copy folder `skripsi` ke `C:\xampp\htdocs\` (Windows) atau `/opt/lampp/htdocs/` (Linux)
2. Pastikan Apache dan MySQL berjalan
3. Akses melalui browser: `http://localhost/skripsi`

**WAMP:**
1. Copy folder `skripsi` ke `C:\wamp64\www\`
2. Akses: `http://localhost/skripsi`

**LAMP (Linux):**
1. Copy folder ke `/var/www/html/`
2. Set permissions: `sudo chown -R www-data:www-data /var/www/html/skripsi`
3. Akses: `http://localhost/skripsi`

### 5. Setup Data Dummy (Opsional)

Jika ingin menggunakan data dummy untuk testing:

```bash
cd dummy_data_combined
python generate_complete_dummy.py
python generate_sql_insert.py
```

Kemudian import file `output/Insert Dummy.sql` ke database.

## ⚙️ Konfigurasi

### Konfigurasi Domain

Edit `dbconnect.php` untuk mengatur domain:

```php
$GLOBALS['domain'] = "http://localhost/skripsi";
```

### Konfigurasi Upload Gambar

Pastikan folder `assets/images/barang/` memiliki permission write:

```bash
chmod 755 assets/images/barang/
```

### Konfigurasi Session

Session disimpan di server default. Untuk production, disarankan menggunakan session yang lebih aman.

## 👥 Role dan Akses

### 1. **Pemilik (OWNR)**
- Akses penuh ke semua modul
- Master data (Barang, Merek, Kategori, Supplier, Lokasi, User)
- Laporan lengkap
- Dashboard dengan statistik
- Koreksi transfer
- Stock management

### 2. **Staff Gudang (GDNG)**
- Dashboard gudang
- Barang masuk dari supplier
- Transfer barang ke toko
- Stock opname gudang
- Mutasi barang rusak
- Cetak label batch

### 3. **Staff Toko (TOKO)**
- Dashboard toko
- Point of Sale (POS)
- Barang masuk dari gudang
- Stock opname toko
- Mutasi barang rusak
- Cetak nota

## 📊 Database Schema

### Tabel Utama

- **MASTER_BARANG** - Data master barang
- **MASTER_MEREK** - Data merek
- **MASTER_KATEGORI_BARANG** - Kategori barang
- **MASTER_SUPPLIER** - Data supplier
- **MASTER_LOKASI** - Lokasi (Gudang/Toko)
- **USERS** - Data user dengan role
- **STOCK** - Stock per barang per lokasi
- **STOCK_HISTORY** - History perubahan stock
- **PESAN_BARANG** - Pemesanan barang ke supplier
- **TRANSFER_BARANG** - Transfer antar lokasi
- **DETAIL_TRANSFER_BARANG** - Detail transfer per barang
- **DETAIL_TRANSFER_BARANG_BATCH** - Detail transfer per batch
- **NOTA_JUAL** - Nota penjualan
- **DETAIL_NOTA_JUAL** - Detail penjualan per item
- **MUTASI_BARANG_RUSAK** - Mutasi barang rusak
- **STOCK_OPNAME** - Data stock opname
- **BIAYA_OPERASIONAL** - Biaya operasional
- **PERHITUNGAN_INTERVAL_POQ** - Perhitungan interval POQ
- **PERHITUNGAN_KUANTITAS_POQ** - Perhitungan kuantitas POQ

Lihat file `dbFrozenFood.sql` untuk detail lengkap schema database.

## 🎯 Fitur Detail

### Stock Management

- **Stock Tracking:** Real-time tracking stock per lokasi dengan satuan PIECES dan DUS
- **Stock History:** Log semua perubahan stock dengan tipe:
  - `PEMESANAN` - Barang masuk dari supplier
  - `TRANSFER` - Transfer antar lokasi
  - `OPNAME` - Stock opname
  - `RUSAK` - Mutasi barang rusak
  - `PENJUALAN` - Penjualan di toko
  - `KOREKSI` - Koreksi data
- **Kartu Stock:** Tracking detail per barang per lokasi dengan referensi batch

### Transfer Barang

- **Sistem Batch:** Transfer menggunakan sistem batch dengan FEFO (First Expired First Out)
- **Status Tracking:**
  - `PESAN` - Barang dipesan
  - `DIKIRIM` - Barang dikirim dari gudang
  - `SELESAI` - Barang diterima di toko
- **Koreksi Transfer:** Dapat mengoreksi jumlah kirim, tiba, dan ditolak
- **Surat Jalan:** Generate surat jalan otomatis

### Point of Sale

- **Kasir:** Interface kasir untuk penjualan
- **Nota:** Cetak nota otomatis dengan format receipt
- **Profit Calculation:** Perhitungan gross profit otomatis
- **Stock Update:** Update stock otomatis setelah penjualan

### Laporan

- **Laporan Penjualan:**
  - Volume Transaksi
  - Kuantitas Penjualan
  - Pendapatan Penjualan
  - Beban Pokok Penjualan
  - Laba Kotor
  - Penerimaan Kas Bruto
- **Laporan Mutasi Barang Rusak:** Tracking barang rusak dengan kompensasi
- **Laporan Stock Opname:** Perbandingan stock fisik vs sistem
- **Laporan POQ:** Perhitungan Periodic Order Quantity
- **Export Excel:** Semua laporan dapat di-export ke Excel

## 🔐 Keamanan

- **Session Management:** Menggunakan PHP session untuk autentikasi
- **Role-Based Access:** Setiap role memiliki akses terbatas sesuai kebutuhan
- **SQL Injection Prevention:** Menggunakan prepared statements
- **XSS Prevention:** Menggunakan `htmlspecialchars()` untuk output

## 📝 Catatan Penting

1. **Password:** Saat ini password disimpan dalam plain text. Untuk production, disarankan menggunakan password hashing (bcrypt/argon2).

2. **File Upload:** Pastikan folder upload memiliki permission yang tepat dan validasi file upload.

3. **Database Backup:** Lakukan backup database secara berkala.

4. **Environment:** File `dbconnect.php` berisi kredensial database. Jangan commit file ini ke public repository tanpa enkripsi.

## 🤝 Kontribusi

Project ini adalah skripsi untuk keperluan akademik. Jika ingin berkontribusi atau melaporkan bug, silakan buat issue di repository ini.

## 📄 Lisensi

Project ini tersedia sebagai open source dan dapat digunakan secara bebas untuk keperluan akademik, komersial, atau pengembangan lebih lanjut. Pengguna bebas untuk memodifikasi, mendistribusikan, dan menggunakan project ini sesuai kebutuhan mereka.

## 👤 Author

**Kevin Dinata**

- LinkedIn: [kevin-dinata-machung](https://www.linkedin.com/in/kevin-dinata-machung/)

## 📞 Support

Untuk pertanyaan atau bantuan, silakan hubungi administrator sistem.

---

**Catatan:** Project ini masih dalam tahap pengembangan. Beberapa fitur mungkin masih perlu perbaikan atau optimasi.

