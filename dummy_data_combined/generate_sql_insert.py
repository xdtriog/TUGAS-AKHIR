#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script untuk generate SQL INSERT statements dari file CSV (GABUNGAN banyak produk)
"""

import csv
import os

# Konfigurasi produk (harus konsisten dengan generate_complete_dummy.py)
import random

# Set seed untuk konsistensi harga jual (harus sama dengan generate_complete_dummy.py)
random.seed(42)  # Seed yang sama untuk konsistensi

BASE_PRODUCT_CONFIG = {
    'MIN_STOCK_TOKO': 50,
    'MAX_STOCK_TOKO': 200,
    'MIN_STOCK_GUDANG': 30,
    'MAX_STOCK_GUDANG': 100,
    'HARGA_JUAL_PIECE': 33000,  # Harga jual per piece (default, akan di-override)
}

# Generate harga jual variatif untuk setiap produk (33k - 50k)
def generate_harga_jual():
    """Generate harga jual random antara 33,000 - 50,000"""
    return random.randint(33000, 50000)

PRODUCTS = {
    # Produk dari MASTER_BARANG (401-441) - dengan harga jual variatif (33k-50k)
    '0qiaT6r5h6tx1vG5': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': '0qiaT6r5h6tx1vG5', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    '3SMMLSAekpQ7YHBm': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': '3SMMLSAekpQ7YHBm', 'SATUAN_PERDUS': 24, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    '4eJNEAcagseDtEgc': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': '4eJNEAcagseDtEgc', 'SATUAN_PERDUS': 10, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    '5FkHT4Mzbx4W5kQE': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': '5FkHT4Mzbx4W5kQE', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    '5trjzMBdbhei4ZJY': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': '5trjzMBdbhei4ZJY', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    '6HYQiMz32fakk4k7': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': '6HYQiMz32fakk4k7', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    '7hvrUALJk9fW53Uw': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': '7hvrUALJk9fW53Uw', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    '7KHAaDZe1ncb5PxZ': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': '7KHAaDZe1ncb5PxZ', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    '8e0exsihhJ5vyw4R': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': '8e0exsihhJ5vyw4R', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'abPg4AgMfBYJQFbY': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'abPg4AgMfBYJQFbY', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'BmQgQAmDbUifCNkp': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'BmQgQAmDbUifCNkp', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'bSqgiWf3hbrwBtn8': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'bSqgiWf3hbrwBtn8', 'SATUAN_PERDUS': 10, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'cJR1HX6NcEBkvQa9': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'cJR1HX6NcEBkvQa9', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'd7d7HhD0d8c4B1aT': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'd7d7HhD0d8c4B1aT', 'SATUAN_PERDUS': 24, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'dQVmWBpb4rAtaFyX': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'dQVmWBpb4rAtaFyX', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'EipfpEa23tx29rxA': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'EipfpEa23tx29rxA', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'fEuFwvHHggEZGAuM': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'fEuFwvHHggEZGAuM', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'hrzWDaube4kT8Hra': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'hrzWDaube4kT8Hra', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'i4nFqC8PgEZEHQYc': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'i4nFqC8PgEZEHQYc', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'iMq19Qq7ccrK2mMe': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'iMq19Qq7ccrK2mMe', 'SATUAN_PERDUS': 24, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'jgdV5NWf41WKmiHb': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'jgdV5NWf41WKmiHb', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'MAcpqAYAjxhGyScJ': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'MAcpqAYAjxhGyScJ', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'mh560H4Z4RpSFTnz': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'mh560H4Z4RpSFTnz', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'MpHjcrYfdsSZCZk3': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'MpHjcrYfdsSZCZk3', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'n1F0Bxwc3arcFkcV': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'n1F0Bxwc3arcFkcV', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'NmffHrvs1NmdsNtk': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'NmffHrvs1NmdsNtk', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'PiBLjDVZctBYPYhp': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'PiBLjDVZctBYPYhp', 'SATUAN_PERDUS': 24, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'qASpqzyP3FQ7TKJg': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'qASpqzyP3FQ7TKJg', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'sxDvWY0uiU1zu8uz': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'sxDvWY0uiU1zu8uz', 'SATUAN_PERDUS': 24, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    't3DiGNqjiBjUns42': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 't3DiGNqjiBjUns42', 'SATUAN_PERDUS': 10, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'U3Avc19Uiip05fih': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'U3Avc19Uiip05fih', 'SATUAN_PERDUS': 10, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'uafjPLDC2SFFw7Rk': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'uafjPLDC2SFFw7Rk', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'uTb95XLLdJdfEJ8u': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'uTb95XLLdJdfEJ8u', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'VHUkNBy8eqEXPVS1': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'VHUkNBy8eqEXPVS1', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'vLwTPWXVitRFFFLJ': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'vLwTPWXVitRFFFLJ', 'SATUAN_PERDUS': 24, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'x2RqyPEi640Bx5c0': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'x2RqyPEi640Bx5c0', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'Xd7uGwF2hrKTbD4P': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'Xd7uGwF2hrKTbD4P', 'SATUAN_PERDUS': 24, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'XMA9bdbvm8CXVsYU': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'XMA9bdbvm8CXVsYU', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'XzN8unV1kQRnjha4': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'XzN8unV1kQRnjha4', 'SATUAN_PERDUS': 24, 'HARGA_JUAL_PIECE': generate_harga_jual()},
    'ZwcXmVPafgipxtmm': {**BASE_PRODUCT_CONFIG, 'KD_BARANG': 'ZwcXmVPafgipxtmm', 'SATUAN_PERDUS': 12, 'HARGA_JUAL_PIECE': generate_harga_jual()},
}

def escape_sql_string(value):
    """Escape string untuk SQL"""
    if value is None or value == '':
        return 'NULL'
    if isinstance(value, str):
        # Escape single quotes
        return "'" + value.replace("'", "''") + "'"
    return str(value)

def generate_insert_from_csv(csv_file, table_name, sql_file):
    """Generate INSERT statements dari CSV file"""
    if not os.path.exists(csv_file):
        print(f"Warning: {csv_file} tidak ditemukan, skip...")
        return False
    
    with open(csv_file, 'r', encoding='utf-8') as f:
        reader = csv.DictReader(f)
        rows = list(reader)
    
    if not rows:
        print(f"Warning: {csv_file} kosong, skip...")
        return False
    
    sql_file.write(f"\n-- ============================================\n")
    sql_file.write(f"-- INSERT untuk {table_name}\n")
    sql_file.write(f"-- Source: {csv_file}\n")
    sql_file.write(f"-- Total records: {len(rows)}\n")
    sql_file.write(f"-- ============================================\n\n")
    
    # Get column names from first row
    columns = list(rows[0].keys())
    
    # Write INSERT statement
    sql_file.write(f"INSERT INTO {table_name} ({', '.join(columns)}) VALUES\n")
    
    values_list = []
    for row in rows:
        values = []
        for col in columns:
            value = row.get(col, '')
            if value == '' or value is None:
                values.append('NULL')
            elif value.replace('.', '', 1).replace('-', '', 1).isdigit():
                # Number
                values.append(value)
            else:
                # String - escape single quotes
                values.append(escape_sql_string(value))
        
        values_list.append(f"({', '.join(values)})")
    
    # Write values (batch insert)
    sql_file.write(',\n'.join(values_list))
    sql_file.write(';\n\n')
    
    print(f"[OK] Generated INSERT for {table_name} ({len(rows)} records)")
    return True

def generate_stock_history_from_all(csv_file, table_name, tipe_perubahan, sql_file):
    """Generate INSERT statements untuk STOCK_HISTORY dari stock_history_all.csv berdasarkan TIPE_PERUBAHAN"""
    if not os.path.exists(csv_file):
        print(f"Warning: {csv_file} tidak ditemukan, skip...")
        return False
    
    with open(csv_file, 'r', encoding='utf-8') as f:
        reader = csv.DictReader(f)
        rows = [r for r in reader if r.get('TIPE_PERUBAHAN', '') == tipe_perubahan]
    
    if not rows:
        print(f"Warning: Tidak ada data {tipe_perubahan} di {csv_file}, skip...")
        return False
    
    sql_file.write(f"\n-- ============================================\n")
    sql_file.write(f"-- INSERT untuk {table_name} ({tipe_perubahan})\n")
    sql_file.write(f"-- Source: {csv_file} (filtered by TIPE_PERUBAHAN = {tipe_perubahan})\n")
    sql_file.write(f"-- Total records: {len(rows)}\n")
    sql_file.write(f"-- ============================================\n\n")
    
    # Get column names from first row
    columns = list(rows[0].keys())
    
    # Write INSERT statement
    sql_file.write(f"INSERT INTO {table_name} ({', '.join(columns)}) VALUES\n")
    
    values_list = []
    for row in rows:
        values = []
        for col in columns:
            value = row.get(col, '')
            if value == '' or value is None:
                values.append('NULL')
            elif value.replace('.', '', 1).replace('-', '', 1).isdigit():
                # Number
                values.append(value)
            else:
                # String - escape single quotes
                values.append(escape_sql_string(value))
        
        values_list.append(f"({', '.join(values)})")
    
    # Write values (batch insert)
    sql_file.write(',\n'.join(values_list))
    sql_file.write(';\n\n')
    
    print(f"[OK] Generated INSERT for {table_name} ({tipe_perubahan}) ({len(rows)} records)")
    return len(rows)

def main():
    """Main function"""
    output_file = 'output/Insert Dummy.sql'
    output_dir = 'output'
    
    # Buat folder output jika belum ada
    os.makedirs(output_dir, exist_ok=True)
    
    # Urutan import sesuai README
    files_to_import = [
        ('pesan_barang.csv', 'PESAN_BARANG'),
        ('transfer_barang.csv', 'TRANSFER_BARANG'),
        ('detail_transfer_barang.csv', 'DETAIL_TRANSFER_BARANG'),
        ('detail_transfer_barang_batch.csv', 'DETAIL_TRANSFER_BARANG_BATCH'),
        ('nota_jual.csv', 'NOTA_JUAL'),
        ('detail_nota_jual.csv', 'DETAIL_NOTA_JUAL'),
        ('stock.csv', 'STOCK'),
    ]
    
    # STOCK_HISTORY dipisah berdasarkan TIPE_PERUBAHAN dari stock_history_all.csv
    stock_history_types = [
        ('stock_history_all.csv', 'STOCK_HISTORY', 'PEMESANAN'),
        ('stock_history_all.csv', 'STOCK_HISTORY', 'TRANSFER'),
        ('stock_history_all.csv', 'STOCK_HISTORY', 'PENJUALAN'),
    ]
    
    with open(output_file, 'w', encoding='utf-8') as sql_file:
        # Write header
        sql_file.write("-- ============================================\n")
        sql_file.write("-- INSERT DUMMY DATA untuk Semua Produk\n")
        sql_file.write("-- Generated from CSV files\n")
        sql_file.write("-- ============================================\n\n")
        sql_file.write(f"-- Total Produk: {len(PRODUCTS)}\n")
        sql_file.write("\n")
        sql_file.write("-- Urutan import:\n")
        sql_file.write("-- 1. PESAN_BARANG\n")
        sql_file.write("-- 2. STOCK_HISTORY (PEMESANAN)\n")
        sql_file.write("-- 3. TRANSFER_BARANG\n")
        sql_file.write("-- 4. DETAIL_TRANSFER_BARANG\n")
        sql_file.write("-- 5. DETAIL_TRANSFER_BARANG_BATCH\n")
        sql_file.write("-- 6. STOCK_HISTORY (TRANSFER)\n")
        sql_file.write("-- 7. NOTA_JUAL\n")
        sql_file.write("-- 8. DETAIL_NOTA_JUAL\n")
        sql_file.write("-- 9. STOCK_HISTORY (PENJUALAN)\n")
        sql_file.write("-- 10. STOCK\n\n")
        sql_file.write("SET FOREIGN_KEY_CHECKS = 0;\n\n")
        
        total_records = 0
        
        # Import PESAN_BARANG
        csv_path = os.path.join(output_dir, 'pesan_barang.csv')
        if generate_insert_from_csv(csv_path, 'PESAN_BARANG', sql_file):
            with open(csv_path, 'r', encoding='utf-8') as f:
                reader = csv.DictReader(f)
                total_records += len(list(reader))
        
        # Import STOCK_HISTORY (PEMESANAN)
        csv_path = os.path.join(output_dir, 'stock_history_all.csv')
        count = generate_stock_history_from_all(csv_path, 'STOCK_HISTORY', 'PEMESANAN', sql_file)
        if count:
            total_records += count
        
        # Import files lainnya
        for csv_file, table_name in files_to_import[1:]:  # Skip pesan_barang karena sudah diimport
            csv_path = os.path.join(output_dir, csv_file)
            if table_name == 'STOCK':
                # Handle STOCK khusus - sesuaikan MIN_STOCK dan MAX_STOCK
                if os.path.exists(csv_path):
                    with open(csv_path, 'r', encoding='utf-8') as f:
                        reader = csv.DictReader(f)
                        rows = list(reader)
                    
                    if rows:
                        sql_file.write(f"\n-- ============================================\n")
                        sql_file.write(f"-- INSERT untuk {table_name}\n")
                        sql_file.write(f"-- Source: {csv_file}\n")
                        sql_file.write(f"-- Total records: {len(rows)}\n")
                        sql_file.write(f"-- ============================================\n\n")
                        
                        columns = list(rows[0].keys())
                        sql_file.write(f"INSERT INTO {table_name} ({', '.join(columns)}) VALUES\n")
                        
                        values_list = []
                        for row in rows:
                            kd_barang = row.get('KD_BARANG', '')
                            kd_lokasi = row.get('KD_LOKASI', '')
                            
                            # Sesuaikan MIN_STOCK dan MAX_STOCK berdasarkan produk dan lokasi
                            if kd_barang in PRODUCTS:
                                product = PRODUCTS[kd_barang]
                                if kd_lokasi == 'GDNGj825':
                                    # Gudang
                                    row['JUMLAH_MIN_STOCK'] = product['MIN_STOCK_GUDANG']
                                    row['JUMLAH_MAX_STOCK'] = product['MAX_STOCK_GUDANG']
                                else:
                                    # Toko
                                    row['JUMLAH_MIN_STOCK'] = product['MIN_STOCK_TOKO']
                                    row['JUMLAH_MAX_STOCK'] = product['MAX_STOCK_TOKO']
                            
                            values = []
                            for col in columns:
                                value = row.get(col, '')
                                if value == '' or value is None:
                                    values.append('NULL')
                                elif isinstance(value, (int, float)):
                                    values.append(str(value))
                                elif isinstance(value, str) and value.replace('.', '', 1).replace('-', '', 1).isdigit():
                                    values.append(value)
                                else:
                                    values.append(escape_sql_string(str(value)))
                            
                            values_list.append(f"({', '.join(values)})")
                        
                        sql_file.write(',\n'.join(values_list))
                        sql_file.write(';\n\n')
                        total_records += len(rows)
                        print(f"[OK] Generated INSERT for {table_name} ({len(rows)} records)")
            else:
                csv_path = os.path.join(output_dir, csv_file)
                if generate_insert_from_csv(csv_path, table_name, sql_file):
                    with open(csv_path, 'r', encoding='utf-8') as f:
                        reader = csv.DictReader(f)
                        total_records += len(list(reader))
        
        # Import STOCK_HISTORY (TRANSFER) - setelah TRANSFER_BARANG
        csv_path = os.path.join(output_dir, 'stock_history_all.csv')
        count = generate_stock_history_from_all(csv_path, 'STOCK_HISTORY', 'TRANSFER', sql_file)
        if count:
            total_records += count
        
        # Import STOCK_HISTORY (PENJUALAN) - setelah NOTA_JUAL
        csv_path = os.path.join(output_dir, 'stock_history_all.csv')
        count = generate_stock_history_from_all(csv_path, 'STOCK_HISTORY', 'PENJUALAN', sql_file)
        if count:
            total_records += count
        
        # Write footer
        sql_file.write("\nSET FOREIGN_KEY_CHECKS = 1;\n\n")
        sql_file.write(f"-- Total records inserted: {total_records}\n")
        sql_file.write("-- End of INSERT statements\n\n")
        
        # ===== UPDATE AVG_HARGA_BELI_PIECES =====
        # Hitung AVG_HARGA_BELI_PIECES dari pesan_barang.csv untuk setiap produk
        csv_path = os.path.join(output_dir, 'pesan_barang.csv')
        if os.path.exists(csv_path):
            with open(csv_path, 'r', encoding='utf-8') as f:
                reader = csv.DictReader(f)
                pesan_rows = list(reader)
            
            if pesan_rows:
                sql_file.write("-- ============================================\n")
                sql_file.write("-- UPDATE AVG_HARGA_BELI_PIECES\n")
                sql_file.write("-- ============================================\n")
                sql_file.write("-- Update AVG_HARGA_BELI_PIECES berdasarkan weighted average dari semua pemesanan\n")
                sql_file.write("-- Formula: AVG_HARGA_BELI_PIECES = SUM(HARGA_PESAN_BARANG_DUS * TOTAL_MASUK_DUS) / SUM(TOTAL_MASUK_DUS * SATUAN_PERDUS)\n\n")
                
                for kd_barang, product in PRODUCTS.items():
                    # Filter pesan untuk produk ini
                    pesan_produk = [r for r in pesan_rows if r.get('KD_BARANG', '') == kd_barang]
                    
                    if pesan_produk:
                        SATUAN_PERDUS = product['SATUAN_PERDUS']
                        total_harga_quantity = 0
                        total_quantity = 0
                        
                        for row in pesan_produk:
                            harga_dus = float(row.get('HARGA_PESAN_BARANG_DUS', 0))
                            total_masuk = int(row.get('TOTAL_MASUK_DUS', 0))
                            total_harga_quantity += harga_dus * total_masuk
                            total_quantity += total_masuk * SATUAN_PERDUS
                        
                        if total_quantity > 0:
                            avg_harga_beli = total_harga_quantity / total_quantity
                            
                            # Get HARGA_JUAL_PIECE dari PRODUCTS config
                            harga_jual = PRODUCTS.get(kd_barang, {}).get('HARGA_JUAL_PIECE', BASE_PRODUCT_CONFIG['HARGA_JUAL_PIECE'])
                            
                            sql_file.write(f"-- Update untuk {kd_barang}\n")
                            sql_file.write(f"UPDATE MASTER_BARANG \n")
                            sql_file.write(f"SET AVG_HARGA_BELI_PIECES = {avg_harga_beli:.2f},\n")
                            sql_file.write(f"    HARGA_JUAL_BARANG_PIECES = {harga_jual:.2f}\n")
                            sql_file.write(f"WHERE KD_BARANG = '{kd_barang}';\n\n")
        
        # ===== SUMMARY DATA DUMMY =====
        sql_file.write("-- ============================================\n")
        sql_file.write("-- SUMMARY DATA DUMMY\n")
        sql_file.write("-- ============================================\n")
        sql_file.write("-- Verifikasi STOCK Akhir (Semua Lokasi)\n")
        sql_file.write("SELECT \n")
        sql_file.write("    CASE \n")
        sql_file.write("        WHEN KD_LOKASI = 'GDNGj825' THEN 'STOCK GUDANG'\n")
        sql_file.write("        ELSE 'STOCK TOKO'\n")
        sql_file.write("    END as LOKASI,\n")
        sql_file.write("    KD_BARANG,\n")
        sql_file.write("    KD_LOKASI,\n")
        sql_file.write("    JUMLAH_BARANG as STOCK_AKHIR,\n")
        sql_file.write("    JUMLAH_MIN_STOCK,\n")
        sql_file.write("    JUMLAH_MAX_STOCK,\n")
        sql_file.write("    SATUAN\n")
        sql_file.write("FROM STOCK\n")
        sql_file.write("ORDER BY KD_BARANG, KD_LOKASI;\n\n")
        sql_file.write("-- Verifikasi AVG_HARGA_BELI_PIECES Akhir\n")
        sql_file.write("SELECT \n")
        sql_file.write("    KD_BARANG, \n")
        sql_file.write("    NAMA_BARANG, \n")
        sql_file.write("    AVG_HARGA_BELI_PIECES as AVG_HARGA_BELI_PIECES_AKHIR,\n")
        sql_file.write("    SATUAN_PERDUS\n")
        sql_file.write("FROM MASTER_BARANG\n")
        sql_file.write("ORDER BY KD_BARANG;\n")
    
    print(f"\n[OK] SQL file generated: {output_file}")
    print(f"[OK] Total records: {total_records}")

if __name__ == '__main__':
    main()

