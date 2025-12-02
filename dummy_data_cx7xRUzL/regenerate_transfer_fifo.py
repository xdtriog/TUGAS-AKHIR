#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script untuk regenerate transfer data dengan logika FEFO (First Expired First Out)
Batch dengan tanggal expired terdekat dipakai dulu
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

# Baca data pesan barang
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

# Sort pesanan berdasarkan TGL_EXPIRED (FEFO - First Expired First Out)
pesanan_data.sort(key=lambda x: x['tgl_expired'] if x['tgl_expired'] else '9999-12-31')

# Simpan sisa stock per batch
batch_stock = {p['id']: p['jumlah'] for p in pesanan_data}

# Generate transfer dengan logika FEFO
transfers = []
transfer_counter = 1
detail_counter = 1
batch_counter = 1
history_id_counter = 1

# Stock tracking
stock_gudang = sum(p['jumlah'] for p in pesanan_data)  # Total semua batch
stock_toko = 0  # dalam PIECES

# Generate transfer setiap 3-5 hari
start_date = datetime(2024, 1, 4)
end_date = datetime.now()
current_date = start_date

while current_date <= end_date:
    # Skip jika tidak ada stock gudang
    if stock_gudang <= 0:
        current_date += timedelta(days=1)
        continue
    
    # Jumlah transfer per hari: 1-3 kali (lebih realistis)
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
        
        for pesanan in pesanan_data:
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
        
        # Jika masih kurang, ambil dari batch lain (tidak seharusnya terjadi jika logika benar)
        if sisa_kebutuhan > 0:
            print(f"Warning: Kebutuhan {sisa_kebutuhan} dus tidak terpenuhi pada {current_date.strftime('%Y-%m-%d')}")
        
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

# Write to CSV files
print(f"Generated {len([t for t in transfers if t['type'] == 'transfer'])} transfers")
print(f"Stock gudang setelah transfer: {stock_gudang} DUS")
print(f"Stock toko setelah transfer: {stock_toko} PIECES")

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

print("✓ Transfer data regenerated with FEFO logic")
print("✓ SISA_STOCK_DUS updated in pesan_barang.csv")

