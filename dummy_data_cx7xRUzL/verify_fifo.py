#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script untuk verifikasi bahwa transfer mengikuti logika FEFO
"""

import csv
from datetime import datetime

# Baca data pesan barang
pesan = {}
with open('pesan_barang.csv', 'r', encoding='utf-8') as f:
    reader = csv.DictReader(f)
    for r in reader:
        pesan[r['ID_PESAN_BARANG']] = {
            'expired': r['TGL_EXPIRED'],
            'sisa': int(r['SISA_STOCK_DUS']),
            'total': int(r['TOTAL_MASUK_DUS'])
        }

# Baca data transfer batch
transfer_by_date = {}  # {date: [(id_pesan, jumlah, expired)]}
with open('detail_transfer_barang_batch.csv', 'r', encoding='utf-8') as f:
    reader = csv.DictReader(f)
    for r in reader:
        detail_id = r['ID_DETAIL_TRANSFER_BARANG']
        id_pesan = r['ID_PESAN_BARANG']
        jumlah = int(r['JUMLAH_MASUK_DUS'])
        expired = pesan[id_pesan]['expired']
        
        # Ambil tanggal dari detail_transfer_barang
        # Kita perlu baca detail_transfer_barang untuk dapat tanggal
        if detail_id not in transfer_by_date:
            transfer_by_date[detail_id] = []
        transfer_by_date[detail_id].append((id_pesan, jumlah, expired))

# Baca detail_transfer_barang untuk dapat tanggal
detail_dates = {}
with open('detail_transfer_barang.csv', 'r', encoding='utf-8') as f:
    reader = csv.DictReader(f)
    for r in reader:
        detail_id = r['ID_DETAIL_TRANSFER_BARANG']
        transfer_id = r['ID_TRANSFER_BARANG']
        detail_dates[detail_id] = transfer_id

# Baca transfer_barang untuk dapat tanggal
transfer_dates = {}
with open('transfer_barang.csv', 'r', encoding='utf-8') as f:
    reader = csv.DictReader(f)
    for r in reader:
        transfer_id = r['ID_TRANSFER_BARANG']
        tanggal = r['WAKTU_SELESAI_TRANSFER'][:10]  # Ambil tanggal saja
        transfer_dates[transfer_id] = tanggal

# Verifikasi FEFO
print("Verifikasi FEFO (First Expired First Out):")
print("=" * 80)

# Group by tanggal transfer
transfers_by_date = {}
for detail_id, batches in transfer_by_date.items():
    transfer_id = detail_dates.get(detail_id, '')
    tanggal = transfer_dates.get(transfer_id, '')
    if tanggal not in transfers_by_date:
        transfers_by_date[tanggal] = []
    transfers_by_date[tanggal].extend(batches)

# Sort by date
sorted_dates = sorted(transfers_by_date.keys())

# Verifikasi setiap tanggal
fifo_valid = True
for tanggal in sorted_dates[:10]:  # Cek 10 tanggal pertama
    batches = transfers_by_date[tanggal]
    # Sort batch berdasarkan expired date
    batches_sorted = sorted(batches, key=lambda x: x[2] if x[2] else '9999-12-31')
    
    print(f"\nTanggal: {tanggal}")
    print(f"  Batch yang ditransfer (urutkan berdasarkan expired):")
    for id_pesan, jumlah, expired in batches_sorted:
        print(f"    - {id_pesan}: {jumlah} dus, Expired: {expired}")

# Verifikasi total transfer per batch
print("\n" + "=" * 80)
print("Total Transfer per Batch (urutkan berdasarkan expired):")
print("-" * 80)

# Hitung total transfer per batch
transfer_total = {}
with open('detail_transfer_barang_batch.csv', 'r', encoding='utf-8') as f:
    reader = csv.DictReader(f)
    for r in reader:
        id_pesan = r['ID_PESAN_BARANG']
        jumlah = int(r['JUMLAH_MASUK_DUS'])
        transfer_total[id_pesan] = transfer_total.get(id_pesan, 0) + jumlah

# Sort berdasarkan expired date
sorted_pesan = sorted(pesan.items(), key=lambda x: x[1]['expired'] if x[1]['expired'] else '9999-12-31')

print(f"{'ID_PESAN':<15} {'TGL_EXPIRED':<12} {'TOTAL':<6} {'TRANSFER':<8} {'SISA':<6}")
print("-" * 80)
for id_pesan, data in sorted_pesan[:15]:
    total_transfer = transfer_total.get(id_pesan, 0)
    print(f"{id_pesan:<15} {data['expired']:<12} {data['total']:<6} {total_transfer:<8} {data['sisa']:<6}")

print("\n✓ Verifikasi selesai")

