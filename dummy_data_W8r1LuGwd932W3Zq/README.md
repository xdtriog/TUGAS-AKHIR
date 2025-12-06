# Data Dummy untuk Barang W8r1LuGwd932W3Zq (Setahun)

## Deskripsi
Data dummy ini mensimulasikan pergerakan stock untuk barang dengan kode `W8r1LuGwd932W3Zq` (So Nice Sosis Ayam Keju) selama 1 tahun (Januari 2024 - sekarang).

## Alur Data
1. **PESAN_BARANG**: Barang masuk dari supplier ke gudang
2. **STOCK_HISTORY (PEMESANAN)**: Mencatat setiap barang masuk ke gudang
3. **TRANSFER_BARANG**: Transfer dari gudang ke toko (dengan logika FEFO)
4. **STOCK_HISTORY (TRANSFER)**: Mencatat transfer keluar dari gudang dan masuk ke toko
5. **NOTA_JUAL & DETAIL_NOTA_JUAL**: Penjualan di toko
6. **STOCK_HISTORY (PENJUALAN)**: Mencatat setiap penjualan di toko
7. **STOCK**: Stock akhir di gudang dan toko (harus sesuai dengan jumlah akhir di stock history)

## Asumsi
- KD_BARANG: W8r1LuGwd932W3Zq
- KD_LOKASI Gudang: GDNGj825
- KD_LOKASI Toko: TOKOeLig
- KD_SUPPLIER: NGP9zHgE atau NKnVD9E9
- User IDs: GDNGj825 (gudang), TOKOeLig (toko)
- SATUAN: DUS untuk gudang, PIECES untuk toko
- SATUAN_PERDUS: 10 pieces per dus
- HARGA_PESAN_DUS: 290000-310000 per dus
- HARGA_JUAL_PIECE: 33000 per piece
- MIN_STOCK_TOKO: 10 PIECES
- MAX_STOCK_TOKO: 50 PIECES
- MIN_STOCK_GUDANG: 10 DUS
- MAX_STOCK_GUDANG: 30 DUS

## File CSV
1. `pesan_barang.csv` - Data pemesanan barang
2. `transfer_barang.csv` - Data transfer dari gudang ke toko
3. `detail_transfer_barang.csv` - Detail transfer per barang
4. `detail_transfer_barang_batch.csv` - Detail transfer per batch
5. `nota_jual.csv` - Nota penjualan
6. `detail_nota_jual.csv` - Detail penjualan
7. `stock_history_all.csv` - Semua stock history digabung (untuk import mudah)
8. `stock.csv` - Stock akhir di gudang dan toko

## Cara Generate
1. Jalankan `generate_complete_dummy.py` untuk generate CSV files
2. Jalankan `generate_sql_insert.py` untuk generate SQL INSERT statements

## Cara Import
1. Pastikan semua master data sudah ada:
   - MASTER_LOKASI (GDNGj825 untuk gudang, TOKOeLig untuk toko)
   - MASTER_SUPPLIER (NGP9zHgE, NKnVD9E9)
   - USERS (GDNGj825 untuk gudang, TOKOeLig untuk toko)
   - MASTER_BARANG (W8r1LuGwd932W3Zq)

2. Import file `Insert Dummy.sql` yang sudah di-generate

## Catatan
- Semua ID dibuat dengan format yang konsisten (PSBG, NOTA, DNJB, SKHY)
- Waktu dibuat secara berurutan (chronological)
- Stock akhir harus konsisten dengan stock history terakhir
- Transfer menggunakan sistem batch (per ID_PESAN_BARANG) dengan logika FEFO

