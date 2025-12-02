#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script untuk generate data dummy transfer dan penjualan untuk barang cx7xRUzL
"""

import csv
from datetime import datetime, timedelta
import random

# Konstanta
KD_BARANG = '4aCSBjQPd3TzFd90'
KD_LOKASI_GUDANG = 'NRb2W8VG'
KD_LOKASI_TOKO = 'NsHmYgfe'
USER_GUDANG = 'GDNGj825'
USER_TOKO = 'TOKOeLig'
SATUAN_PERDUS = 12  # 12 pieces per dus

# Baca data pemesanan dari CSV untuk mendapatkan TGL_EXPIRED
pesanan_data = []
with open('pesan_barang.csv', 'r', encoding='utf-8') as f:
    reader = csv.DictReader(f)
    for row in reader:
        pesanan_data.append({
            'id': row['ID_PESAN_BARANG'],
            'tgl': row['WAKTU_SELESAI'],
            'jumlah': int(row['TOTAL_MASUK_DUS']),
            'tgl_expired': row['TGL_EXPIRED'],
            'stock_awal': 0,
            'stock_akhir': int(row['TOTAL_MASUK_DUS'])
        })

def generate_transfer_data():
    """Generate data transfer dari gudang ke toko dengan logika FEFO (First Expired First Out)"""
    transfers = []
    transfer_counter = 1
    detail_counter = 1
    batch_counter = 1
    history_id_counter = 1  # Counter untuk history ID
    
    # Baca data pesan barang dan sort berdasarkan TGL_EXPIRED (FEFO)
    pesanan_sorted = sorted(pesanan_data, key=lambda x: x.get('tgl_expired', '9999-12-31') if 'tgl_expired' in x else '9999-12-31')
    
    # Simpan sisa stock per batch
    batch_stock = {p['id']: p['jumlah'] for p in pesanan_sorted}
    
    # Stock tracking
    stock_gudang = sum(p['jumlah'] for p in pesanan_sorted)  # Total semua batch
    stock_toko = 0  # dalam PIECES
    
    # Generate transfer setiap 1-3 hari
    start_date = datetime(2024, 1, 4)
    end_date = datetime.now()
    current_date = start_date
    
    while current_date <= end_date:
        # Skip jika tidak ada stock gudang
        if stock_gudang <= 0:
            current_date += timedelta(days=1)
            continue
        
        # Jumlah transfer per hari: 1-3 kali
        num_transfers_today = random.randint(1, 3)
        
        for _ in range(num_transfers_today):
            if stock_gudang <= 0:
                break
            
            # Tentukan jumlah transfer (10-30 dus per transfer)
            max_transfer_dus = min(stock_gudang, random.randint(10, 30))
            jumlah_transfer_dus = max(1, max_transfer_dus)
            jumlah_transfer_pieces = jumlah_transfer_dus * SATUAN_PERDUS
            
            # Alokasi batch dengan FEFO (First Expired First Out)
            # Ambil dari batch dengan expired terdekat dulu
            sisa_kebutuhan = jumlah_transfer_dus
            batch_allocation = []  # List of (id_pesan, jumlah_dus)
            
            for pesanan in pesanan_sorted:
                if sisa_kebutuhan <= 0:
                    break
                
                id_pesan = pesanan['id']
                sisa_batch = batch_stock.get(id_pesan, 0)
                
                if sisa_batch > 0:
                    # Ambil dari batch ini (maksimal sesuai sisa batch atau sisa kebutuhan)
                    ambil_dari_batch = min(sisa_batch, sisa_kebutuhan)
                    batch_allocation.append((id_pesan, ambil_dari_batch))
                    batch_stock[id_pesan] -= ambil_dari_batch
                    sisa_kebutuhan -= ambil_dari_batch
            
            # Update stock
            stock_gudang_awal = stock_gudang
            stock_gudang -= jumlah_transfer_dus
            stock_toko_awal = stock_toko
            stock_toko += jumlah_transfer_pieces
            
            # ID Transfer
            transfer_id = f'TRF{current_date.strftime("%Y%m%d")}{transfer_counter:03d}'
            detail_id = f'DTL{current_date.strftime("%Y%m%d")}{detail_counter:03d}'
            
            # Transfer Barang
            transfers.append({
                'type': 'transfer',
                'id': transfer_id,
                'pengirim': USER_GUDANG,
                'penerima': USER_TOKO,
                'asal': KD_LOKASI_GUDANG,
                'tujuan': KD_LOKASI_TOKO,
                'waktu_pesan': (current_date - timedelta(hours=2)).strftime('%Y-%m-%d %H:%M:%S'),
                'waktu_kirim': (current_date - timedelta(hours=1)).strftime('%Y-%m-%d %H:%M:%S'),
                'waktu_selesai': current_date.strftime('%Y-%m-%d %H:%M:%S'),
                'status': 'SELESAI'
            })
            
            # Detail Transfer Barang
            transfers.append({
                'type': 'detail_transfer',
                'id': detail_id,
                'transfer_id': transfer_id,
                'barang': KD_BARANG,
                'total_pesan_dus': jumlah_transfer_dus,
                'total_kirim_dus': jumlah_transfer_dus,
                'total_tiba_dus': jumlah_transfer_dus,
                'total_ditolak_dus': 0,
                'total_masuk_dus': jumlah_transfer_dus,
                'status': 'SELESAI'
            })
            
            # Detail Transfer Barang Batch (untuk setiap batch yang dialokasikan)
            for id_pesan, jumlah_dus_batch in batch_allocation:
                batch_id = f'BTCH{current_date.strftime("%Y%m%d")}{batch_counter:03d}'
                transfers.append({
                    'type': 'detail_batch',
                    'id': batch_id,
                    'detail_id': detail_id,
                    'pesan_id': id_pesan,
                    'jumlah_pesan_dus': jumlah_dus_batch,
                    'jumlah_kirim_dus': jumlah_dus_batch,
                    'jumlah_tiba_dus': jumlah_dus_batch,
                    'jumlah_ditolak_dus': 0,
                    'jumlah_masuk_dus': jumlah_dus_batch
                })
                batch_counter += 1
            
            # Stock History - Transfer Keluar (Gudang)
            # Format: SH + YYYYMMDDHHMM + 2 digit counter = 16 karakter
            history_id_g = f'SH{current_date.strftime("%Y%m%d%H%M")}{history_id_counter % 100:02d}'
            history_id_counter += 1
            transfers.append({
                'type': 'stock_history',
                'history_id': history_id_g,
                'barang': KD_BARANG,
                'lokasi': KD_LOKASI_GUDANG,
                'user': USER_GUDANG,
                'jumlah_awal': stock_gudang_awal,
                'jumlah_perubahan': -jumlah_transfer_dus,
                'jumlah_akhir': stock_gudang,
                'tipe': 'TRANSFER',
                'ref': transfer_id,
                'satuan': 'DUS',
                'waktu': current_date.strftime('%Y-%m-%d %H:%M:%S')
            })
            
            # Stock History - Transfer Masuk (Toko)
            # Format: SH + YYYYMMDDHHMM + 2 digit counter = 16 karakter
            history_id_t = f'SH{current_date.strftime("%Y%m%d%H%M")}{history_id_counter % 100:02d}'
            history_id_counter += 1
            transfers.append({
                'type': 'stock_history',
                'history_id': history_id_t,
                'barang': KD_BARANG,
                'lokasi': KD_LOKASI_TOKO,
                'user': USER_TOKO,
                'jumlah_awal': stock_toko_awal,
                'jumlah_perubahan': jumlah_transfer_pieces,
                'jumlah_akhir': stock_toko,
                'tipe': 'TRANSFER',
                'ref': transfer_id,
                'satuan': 'PIECES',
                'waktu': current_date.strftime('%Y-%m-%d %H:%M:%S')
            })
            
            transfer_counter += 1
            detail_counter += 1
        
        # Next day
        current_date += timedelta(days=random.randint(1, 3))
    
    # Update SISA_STOCK_DUS di pesan_barang (akan di-update di bagian write CSV)
    return transfers, stock_gudang, stock_toko, batch_stock

def generate_sales_data(stock_toko_awal, stock_gudang_awal):
    """Generate data penjualan di toko"""
    sales = []
    nota_counter = 1
    detail_counter = 1
    history_id_counter = 1  # Counter untuk history ID
    stock_toko = stock_toko_awal
    stock_gudang = stock_gudang_awal  # Track stock gudang untuk transfer otomatis
    
    # Generate penjualan setiap hari (kecuali hari libur)
    start_date = datetime(2024, 1, 4)  # Mulai setelah transfer pertama
    end_date = datetime.now()  # Sampai hari ini
    
    current_date = start_date
    last_transfer_date = None
    transfer_counter_auto = 1
    
    while current_date <= end_date:
        # Skip weekend (optional, bisa diaktifkan)
        # if current_date.weekday() >= 5:  # Saturday = 5, Sunday = 6
        #     current_date += timedelta(days=1)
        #     continue
        
        # Jika stock toko habis atau kurang dari 100 pieces, generate transfer otomatis
        if stock_toko < 100 and stock_gudang > 0 and (last_transfer_date is None or (current_date - last_transfer_date).days >= 3):
            # Transfer otomatis dari gudang ke toko (10-20 dus, maksimal sesuai stock gudang)
            max_transfer_dus = min(20, stock_gudang)
            jumlah_transfer_dus = random.randint(10, max_transfer_dus) if max_transfer_dus >= 10 else max_transfer_dus
            jumlah_transfer_pieces = jumlah_transfer_dus * SATUAN_PERDUS
            
            # Update stock
            stock_gudang_awal_trans = stock_gudang
            stock_gudang -= jumlah_transfer_dus
            stock_toko_awal_trans = stock_toko
            stock_toko += jumlah_transfer_pieces
            last_transfer_date = current_date
            
            # Generate transfer data (untuk tracking)
            transfer_id_auto = f'TRFAUTO{current_date.strftime("%Y%m%d")}{transfer_counter_auto:03d}'
            transfer_counter_auto += 1
            
            # Stock History - Transfer Keluar (Gudang) - Auto
            history_id_auto_g = f'SH{current_date.strftime("%Y%m%d%H%M")}{history_id_counter % 100:02d}'
            history_id_counter += 1
            sales.append({
                'type': 'stock_history',
                'history_id': history_id_auto_g,
                'barang': KD_BARANG,
                'lokasi': KD_LOKASI_GUDANG,
                'user': USER_GUDANG,
                'jumlah_awal': stock_gudang_awal_trans,
                'jumlah_perubahan': -jumlah_transfer_dus,
                'jumlah_akhir': stock_gudang,
                'tipe': 'TRANSFER',
                'ref': transfer_id_auto,
                'satuan': 'DUS',
                'waktu': current_date.replace(hour=8, minute=0, second=0).strftime('%Y-%m-%d %H:%M:%S')
            })
            
            # Stock History - Transfer Masuk (Toko) - Auto
            history_id_auto_t = f'SH{current_date.strftime("%Y%m%d%H%M")}{history_id_counter % 100:02d}'
            history_id_counter += 1
            sales.append({
                'type': 'stock_history',
                'history_id': history_id_auto_t,
                'barang': KD_BARANG,
                'lokasi': KD_LOKASI_TOKO,
                'user': USER_TOKO,
                'jumlah_awal': stock_toko_awal_trans,
                'jumlah_perubahan': jumlah_transfer_pieces,
                'jumlah_akhir': stock_toko,
                'tipe': 'TRANSFER',
                'ref': transfer_id_auto,
                'satuan': 'PIECES',
                'waktu': current_date.replace(hour=8, minute=0, second=0).strftime('%Y-%m-%d %H:%M:%S')
            })
        
        # Jumlah transaksi per hari: 2-5 transaksi (lebih realistis)
        num_transactions = random.randint(2, 5)
        
        for i in range(num_transactions):
            if stock_toko <= 0:
                # Skip penjualan jika stock habis, tapi tetap lanjutkan loop
                continue
            
            # Waktu transaksi: 08:00 - 20:00
            hour = random.randint(8, 20)
            minute = random.randint(0, 59)
            waktu_nota = current_date.replace(hour=hour, minute=minute, second=random.randint(0, 59))
            
            # Jumlah penjualan: 3-30 pieces per transaksi (lebih realistis)
            max_jual = min(stock_toko, random.randint(5, 30))
            jumlah_jual = random.randint(3, max_jual) if max_jual >= 3 else max_jual
            harga_jual = 15000  # Harga jual per piece
            pajak = int(jumlah_jual * harga_jual * 0.11)  # 11% PPN
            grand_total = (jumlah_jual * harga_jual) + pajak
            
            # Update stock
            stock_toko_awal_trans = stock_toko
            stock_toko -= jumlah_jual
            
            # ID
            nota_id = f'NOTA{nota_counter:06d}'
            detail_id = f'DNJB{detail_counter:06d}'
            
            # Nota Jual
            sales.append({
                'type': 'nota',
                'id': nota_id,
                'user': USER_TOKO,
                'lokasi': KD_LOKASI_TOKO,
                'waktu': waktu_nota.strftime('%Y-%m-%d %H:%M:%S'),
                'grand_total': grand_total,
                'pajak': pajak
            })
            
            # Detail Nota Jual
            sales.append({
                'type': 'detail_nota',
                'id': detail_id,
                'nota_id': nota_id,
                'barang': KD_BARANG,
                'jumlah': jumlah_jual,
                'harga': harga_jual
            })
            
            # Stock History - Penjualan
            # Format: SH + YYYYMMDDHHMM + 2 digit counter = 16 karakter
            history_id_s = f'SH{waktu_nota.strftime("%Y%m%d%H%M")}{history_id_counter % 100:02d}'
            history_id_counter += 1
            sales.append({
                'type': 'stock_history',
                'history_id': history_id_s,
                'barang': KD_BARANG,
                'lokasi': KD_LOKASI_TOKO,
                'user': USER_TOKO,
                'jumlah_awal': stock_toko_awal_trans,
                'jumlah_perubahan': -jumlah_jual,
                'jumlah_akhir': stock_toko,
                'tipe': 'PENJUALAN',
                'ref': detail_id,
                'satuan': 'PIECES',
                'waktu': waktu_nota.strftime('%Y-%m-%d %H:%M:%S')
            })
            
            nota_counter += 1
            detail_counter += 1
        
        current_date += timedelta(days=1)
    
    return sales, stock_toko, stock_gudang

# Generate data
print("Generating transfer data...")
transfers, stock_gudang_final, stock_toko_after_transfer, batch_stock = generate_transfer_data()
print(f"Generated {len([t for t in transfers if t['type'] == 'transfer'])} transfers")
print(f"Stock gudang setelah transfer: {stock_gudang_final} DUS")
print(f"Stock toko setelah transfer: {stock_toko_after_transfer} PIECES")

# Update SISA_STOCK_DUS di pesan_barang.csv
print("\nUpdating SISA_STOCK_DUS in pesan_barang.csv...")
with open('pesan_barang.csv', 'r', encoding='utf-8') as f:
    reader = csv.DictReader(f)
    rows = list(reader)

for row in rows:
    id_pesan = row['ID_PESAN_BARANG']
    row['SISA_STOCK_DUS'] = str(batch_stock.get(id_pesan, 0))

with open('pesan_barang.csv', 'w', newline='', encoding='utf-8') as f:
    if rows:
        fieldnames = list(rows[0].keys())
        writer = csv.DictWriter(f, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)
print("✓ SISA_STOCK_DUS updated")

print("\nGenerating sales data...")
sales, stock_toko_final, stock_gudang_final_updated = generate_sales_data(stock_toko_after_transfer, stock_gudang_final)
print(f"Generated {len([s for s in sales if s['type'] == 'nota'])} sales transactions")
print(f"Stock toko akhir: {stock_toko_final} PIECES")
print(f"Stock gudang akhir: {stock_gudang_final_updated} DUS")

# Write to CSV files
print("\nWriting CSV files...")

# Transfer Barang
with open('transfer_barang.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['ID_TRANSFER_BARANG', 'ID_USERS_PENGIRIM', 'ID_USERS_PENERIMA', 'KD_LOKASI_ASAL', 'KD_LOKASI_TUJUAN', 
                     'WAKTU_PESAN_TRANSFER', 'WAKTU_KIRIM_TRANSFER', 'WAKTU_SELESAI_TRANSFER', 'STATUS'])
    for t in transfers:
        if t['type'] == 'transfer':
            writer.writerow([t['id'], t['pengirim'], t['penerima'], t['asal'], t['tujuan'],
                            t['waktu_pesan'], t['waktu_kirim'], t['waktu_selesai'], t['status']])

# Detail Transfer Barang
with open('detail_transfer_barang.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['ID_DETAIL_TRANSFER_BARANG', 'ID_TRANSFER_BARANG', 'KD_BARANG', 'TOTAL_PESAN_TRANSFER_DUS',
                     'TOTAL_KIRIM_DUS', 'TOTAL_TIBA_DUS', 'TOTAL_DITOLAK_DUS', 'TOTAL_MASUK_DUS', 'STATUS'])
    for t in transfers:
        if t['type'] == 'detail_transfer':
            writer.writerow([t['id'], t['transfer_id'], t['barang'], t['total_pesan_dus'],
                            t['total_kirim_dus'], t['total_tiba_dus'], t['total_ditolak_dus'], t['total_masuk_dus'], t['status']])

# Detail Transfer Barang Batch
with open('detail_transfer_barang_batch.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['ID_DETAIL_TRANSFER_BARANG_BATCH', 'ID_DETAIL_TRANSFER_BARANG', 'ID_PESAN_BARANG',
                     'JUMLAH_PESAN_TRANSFER_BATCH_DUS', 'JUMLAH_KIRIM_DUS', 'JUMLAH_TIBA_DUS', 
                     'JUMLAH_DITOLAK_DUS', 'JUMLAH_MASUK_DUS'])
    for t in transfers:
        if t['type'] == 'detail_batch':
            writer.writerow([t['id'], t['detail_id'], t['pesan_id'], t['jumlah_pesan_dus'],
                            t['jumlah_kirim_dus'], t['jumlah_tiba_dus'], t['jumlah_ditolak_dus'], t['jumlah_masuk_dus']])

# Stock History Transfer
with open('stock_history_transfer.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['ID_HISTORY_STOCK', 'KD_BARANG', 'KD_LOKASI', 'UPDATED_BY', 'JUMLAH_AWAL', 'JUMLAH_PERUBAHAN',
                     'JUMLAH_AKHIR', 'TIPE_PERUBAHAN', 'REF', 'SATUAN', 'WAKTU_CHANGE'])
    for t in transfers:
        if t['type'] == 'stock_history':
            writer.writerow([t['history_id'], t['barang'], t['lokasi'], t['user'], t['jumlah_awal'],
                            t['jumlah_perubahan'], t['jumlah_akhir'], t['tipe'], t['ref'], t['satuan'], t['waktu']])

# Nota Jual
with open('nota_jual.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['ID_NOTA_JUAL', 'ID_USERS', 'KD_LOKASI', 'WAKTU_NOTA', 'GRAND_TOTAL', 'PAJAK'])
    for s in sales:
        if s['type'] == 'nota':
            writer.writerow([s['id'], s['user'], s['lokasi'], s['waktu'], s['grand_total'], s['pajak']])

# Detail Nota Jual
with open('detail_nota_jual.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['ID_DNJB', 'KD_BARANG', 'ID_NOTA_JUAL', 'JUMLAH_JUAL_BARANG', 'HARGA_JUAL_BARANG'])
    for s in sales:
        if s['type'] == 'detail_nota':
            writer.writerow([s['id'], s['barang'], s['nota_id'], s['jumlah'], s['harga']])

# Stock History Penjualan
with open('stock_history_penjualan.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['ID_HISTORY_STOCK', 'KD_BARANG', 'KD_LOKASI', 'UPDATED_BY', 'JUMLAH_AWAL', 'JUMLAH_PERUBAHAN',
                     'JUMLAH_AKHIR', 'TIPE_PERUBAHAN', 'REF', 'SATUAN', 'WAKTU_CHANGE'])
    for s in sales:
        if s['type'] == 'stock_history':
            writer.writerow([s['history_id'], s['barang'], s['lokasi'], s['user'], s['jumlah_awal'],
                            s['jumlah_perubahan'], s['jumlah_akhir'], s['tipe'], s['ref'], s['satuan'], s['waktu']])

# Stock Final
today = datetime.now()
with open('stock.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['KD_BARANG', 'KD_LOKASI', 'UPDATED_BY', 'JUMLAH_BARANG', 'JUMLAH_MIN_STOCK', 'JUMLAH_MAX_STOCK', 'SATUAN', 'LAST_UPDATED'])
    # Stock Gudang
    writer.writerow([KD_BARANG, KD_LOKASI_GUDANG, USER_GUDANG, stock_gudang_final_updated, 50, 200, 'DUS', today.strftime('%Y-%m-%d %H:%M:%S')])
    # Stock Toko
    writer.writerow([KD_BARANG, KD_LOKASI_TOKO, USER_TOKO, stock_toko_final, 100, 1000, 'PIECES', today.strftime('%Y-%m-%d %H:%M:%S')])

# Combine all stock history files
print("\nCombining stock history files...")
all_stock_history = []

# Read pemesanan
with open('stock_history_pemesanan.csv', 'r', encoding='utf-8') as f:
    reader = csv.reader(f)
    header = next(reader)
    all_stock_history.append(header)
    for row in reader:
        all_stock_history.append(row)

# Read transfer (skip header)
with open('stock_history_transfer.csv', 'r', encoding='utf-8') as f:
    reader = csv.reader(f)
    next(reader)  # Skip header
    for row in reader:
        all_stock_history.append(row)

# Read penjualan (skip header)
with open('stock_history_penjualan.csv', 'r', encoding='utf-8') as f:
    reader = csv.reader(f)
    next(reader)  # Skip header
    for row in reader:
        all_stock_history.append(row)

# Write combined file
with open('stock_history_all.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerows(all_stock_history)

print("\nDone! All CSV files generated.")
print(f"\nSummary:")
print(f"- Stock Gudang Akhir: {stock_gudang_final_updated} DUS")
print(f"- Stock Toko Akhir: {stock_toko_final} PIECES")
print(f"- Total Stock History Records: {len(all_stock_history) - 1}")  # -1 for header

