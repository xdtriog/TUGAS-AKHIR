# Generator Data Dummy Gabungan

Generator data dummy untuk **2 produk sekaligus**:
- `4aCSBjQPd3TzFd90`
- `W8r1LuGwd932W3Zq`

## Fitur

- Generate data untuk kedua produk dalam satu eksekusi
- Stock tracking terpisah untuk masing-masing produk
- Batch tracking terpisah untuk masing-masing produk (FEFO)
- Purchase history terpisah untuk menghitung AVG_HARGA_BELI_PIECES
- Verifikasi dan perbaikan otomatis JUMLAH_AKHIR
- Sorting history berdasarkan WAKTU_CHANGE
- Waktu random untuk menghindari konflik waktu

## Konfigurasi Produk

### 4aCSBjQPd3TzFd90
- SATUAN_PERDUS: 25 pieces
- HARGA_PESAN_DUS: 400,000 (lebih mahal)
- HARGA_JUAL_PIECE: 40,000 (lebih mahal)
- MIN_STOCK_TOKO: 100 pieces
- MAX_STOCK_TOKO: 1,000 pieces
- MIN_STOCK_GUDANG: 50 DUS
- MAX_STOCK_GUDANG: 200 DUS

### W8r1LuGwd932W3Zq
- SATUAN_PERDUS: 10 pieces
- HARGA_PESAN_DUS: 300,000
- HARGA_JUAL_PIECE: 33,000
- MIN_STOCK_TOKO: 10 pieces
- MAX_STOCK_TOKO: 50 pieces
- MIN_STOCK_GUDANG: 10 DUS
- MAX_STOCK_GUDANG: 30 DUS

## Cara Menggunakan

### 1. Generate Data Dummy

```bash
cd dummy_data_combined
python generate_complete_dummy.py
```

Output akan disimpan di folder `output/`:
- `pesan_barang.csv`
- `transfer_barang.csv`
- `detail_transfer_barang.csv`
- `detail_transfer_barang_batch.csv`
- `nota_jual.csv`
- `detail_nota_jual.csv`
- `stock_history_all.csv`
- `stock.csv`

### 2. Generate SQL INSERT

```bash
python generate_sql_insert.py
```

Output: `output/Insert Dummy.sql`

## Urutan Import SQL

1. PESAN_BARANG
2. STOCK_HISTORY (PEMESANAN)
3. TRANSFER_BARANG
4. DETAIL_TRANSFER_BARANG
5. DETAIL_TRANSFER_BARANG_BATCH
6. STOCK_HISTORY (TRANSFER)
7. NOTA_JUAL
8. DETAIL_NOTA_JUAL
9. STOCK_HISTORY (PENJUALAN)
10. STOCK

## Perbedaan dengan Generator Terpisah

### Generator Terpisah
- Satu generator per produk
- Data di folder terpisah
- Perlu menjalankan 2 kali untuk 2 produk

### Generator Gabungan
- Satu generator untuk 2 produk
- Data di folder `output/` yang sama
- Semua data digabung dalam satu file CSV
- Lebih efisien untuk generate data banyak produk sekaligus

## Catatan

- Semua ID menggunakan UUID4 + timestamp untuk memastikan keunikan
- JUMLAH_AKHIR otomatis diverifikasi dan diperbaiki jika tidak konsisten
- History diurutkan berdasarkan WAKTU_CHANGE untuk konsistensi
- Waktu menggunakan random untuk menghindari konflik jika ada beberapa transaksi pada hari yang sama

