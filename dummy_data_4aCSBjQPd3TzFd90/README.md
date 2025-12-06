# Data Dummy untuk Barang 4aCSBjQPd3TzFd90 (Setahun)

## Deskripsi
Data dummy ini mensimulasikan pergerakan stock untuk barang dengan kode `4aCSBjQPd3TzFd90` selama 1 tahun (Januari - Desember 2024).

## Alur Data
1. **PESAN_BARANG**: Barang masuk dari supplier ke gudang (23 kali pemesanan)
2. **STOCK_HISTORY (PEMESANAN)**: Mencatat setiap barang masuk ke gudang
3. **TRANSFER_BARANG**: Transfer dari gudang ke toko (sekitar 50-60 kali transfer)
4. **STOCK_HISTORY (TRANSFER)**: Mencatat transfer keluar dari gudang dan masuk ke toko
5. **NOTA_JUAL & DETAIL_NOTA_JUAL**: Penjualan di toko (sekitar 300-350 transaksi)
6. **STOCK_HISTORY (PENJUALAN)**: Mencatat setiap penjualan di toko
7. **STOCK**: Stock akhir di gudang dan toko (harus sesuai dengan jumlah akhir di stock history)

## Asumsi
- KD_BARANG: 4aCSBjQPd3TzFd90
- KD_LOKASI Gudang: NRb2W8VG
- KD_LOKASI Toko: NsHmYgfe
- KD_SUPPLIER: NGP9zHgE atau NKnVD9E9
- User IDs: GDNGj825 (gudang), TOKOeLig (toko)
- SATUAN: DUS untuk gudang, PIECES untuk toko
- SATUAN_PERDUS: 12 pieces per dus

## File CSV
1. `pesan_barang.csv` - Data pemesanan barang (23 pemesanan)
2. `stock_history_pemesanan.csv` - Stock history untuk pemesanan (23 records)
3. `transfer_barang.csv` - Data transfer dari gudang ke toko (~64 transfer)
4. `detail_transfer_barang.csv` - Detail transfer per barang (~64 records)
5. `detail_transfer_barang_batch.csv` - Detail transfer per batch (~64 records)
6. `stock_history_transfer.csv` - Stock history untuk transfer (~128 records: 64 keluar gudang + 64 masuk toko)
7. `nota_jual.csv` - Nota penjualan (~1257 transaksi)
8. `detail_nota_jual.csv` - Detail penjualan (~1257 records)
9. `stock_history_penjualan.csv` - Stock history untuk penjualan (~1257 records)
10. `stock_history_all.csv` - Semua stock history digabung (untuk import mudah)
11. `stock.csv` - Stock akhir di gudang dan toko

## Statistik Data
- **Total Pemesanan**: 23 kali (1735 DUS)
- **Total Transfer**: ~64 kali dari gudang ke toko
- **Total Penjualan**: ~1257 transaksi
- **Stock Gudang Akhir**: 1640 DUS
- **Stock Toko Akhir**: 7350 PIECES

## Cara Import
1. Pastikan semua master data sudah ada:
   - MASTER_LOKASI (NRb2W8VG untuk gudang, NsHmYgfe untuk toko)
   - MASTER_SUPPLIER (NGP9zHgE, NKnVD9E9)
   - USERS (GDNGj825 untuk gudang, TOKOeLig untuk toko)
   - MASTER_BARANG (4aCSBjQPd3TzFd90)

2. Import sesuai urutan (penting untuk konsistensi):
   ```
   1. pesan_barang.csv
   2. stock_history_pemesanan.csv (atau gunakan stock_history_all.csv)
   3. transfer_barang.csv
   4. detail_transfer_barang.csv
   5. detail_transfer_barang_batch.csv
   6. stock_history_transfer.csv (atau gunakan stock_history_all.csv)
   7. nota_jual.csv
   8. detail_nota_jual.csv
   9. stock_history_penjualan.csv (atau gunakan stock_history_all.csv)
   10. stock.csv
   ```

   **Atau gunakan file gabungan:**
   ```
   1. pesan_barang.csv
   2. stock_history_all.csv (sudah termasuk semua: pemesanan, transfer, penjualan)
   3. transfer_barang.csv
   4. detail_transfer_barang.csv
   5. detail_transfer_barang_batch.csv
   6. nota_jual.csv
   7. detail_nota_jual.csv
   8. stock.csv
   ```

## Catatan
- Semua ID dibuat dengan format yang konsisten
- Waktu dibuat secara berurutan (chronological)
- Stock akhir harus konsisten dengan stock history terakhir
- Transfer menggunakan sistem batch (per ID_PESAN_BARANG)

