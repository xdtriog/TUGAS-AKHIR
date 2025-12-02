#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script untuk memperbaiki SISA_STOCK_DUS di pesan_barang.csv
berdasarkan transfer yang sudah dilakukan
"""

import csv

# Baca data transfer batch
transfer_data = {}
with open('detail_transfer_barang_batch.csv', 'r', encoding='utf-8') as f:
    reader = csv.DictReader(f)
    for row in reader:
        id_pesan = row['ID_PESAN_BARANG']
        jumlah_masuk = int(row['JUMLAH_MASUK_DUS'])
        if id_pesan not in transfer_data:
            transfer_data[id_pesan] = 0
        transfer_data[id_pesan] += jumlah_masuk

# Baca dan update pesan_barang.csv
rows = []
with open('pesan_barang.csv', 'r', encoding='utf-8') as f:
    reader = csv.DictReader(f)
    for row in reader:
        id_pesan = row['ID_PESAN_BARANG']
        total_masuk = int(row['TOTAL_MASUK_DUS'])
        total_transfer = transfer_data.get(id_pesan, 0)
        
        # Hitung SISA_STOCK_DUS yang benar
        sisa_stock_dus = max(0, total_masuk - total_transfer)
        
        # Update SISA_STOCK_DUS
        row['SISA_STOCK_DUS'] = str(sisa_stock_dus)
        rows.append(row)
        
        print(f"{id_pesan}: TOTAL={total_masuk}, TRANSFER={total_transfer}, SISA={sisa_stock_dus} (sebelumnya: {row.get('SISA_STOCK_DUS', 'N/A')})")

# Tulis kembali ke file
with open('pesan_barang.csv', 'w', newline='', encoding='utf-8') as f:
    if rows:
        fieldnames = list(rows[0].keys())
        writer = csv.DictWriter(f, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)

print(f"\n✓ Updated {len(rows)} records in pesan_barang.csv")

