#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script untuk update tanggal expired di pesan_barang.csv
Tanggal expired: minimal minggu depan sampai maksimal 2027
"""

import csv
from datetime import datetime, timedelta
import random

# Baca file CSV
input_file = 'pesan_barang.csv'
output_file = 'pesan_barang.csv'

# Hitung tanggal minimal (minggu depan) dan maksimal (2027-12-31)
min_date = datetime.now() + timedelta(days=7)  # Minggu depan
max_date = datetime(2027, 12, 31)  # Maksimal 2027

# Baca data
rows = []
with open(input_file, 'r', encoding='utf-8') as f:
    reader = csv.DictReader(f)
    for row in reader:
        # Generate tanggal expired random antara min_date dan max_date
        days_range = (max_date - min_date).days
        random_days = random.randint(0, days_range)
        expired_date = min_date + timedelta(days=random_days)
        
        # Update TGL_EXPIRED
        row['TGL_EXPIRED'] = expired_date.strftime('%Y-%m-%d')
        rows.append(row)

# Tulis kembali ke file
with open(output_file, 'w', newline='', encoding='utf-8') as f:
    if rows:
        fieldnames = list(rows[0].keys())
        writer = csv.DictWriter(f, fieldnames=fieldnames)
        writer.writeheader()
        writer.writerows(rows)

print(f"✓ Updated {len(rows)} records")
print(f"✓ Tanggal expired range: {min_date.strftime('%Y-%m-%d')} sampai {max_date.strftime('%Y-%m-%d')}")

