#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script untuk generate SQL INSERT statements dari file CSV
"""

import csv
import os

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
    
    print(f"✓ Generated INSERT for {table_name} ({len(rows)} records)")
    return True

def main():
    """Main function"""
    output_file = 'Insert Dummy.sql'
    
    # Urutan import sesuai README
    files_to_import = [
        ('pesan_barang.csv', 'PESAN_BARANG'),
        ('stock_history_pemesanan.csv', 'STOCK_HISTORY'),
        ('transfer_barang.csv', 'TRANSFER_BARANG'),
        ('detail_transfer_barang.csv', 'DETAIL_TRANSFER_BARANG'),
        ('detail_transfer_barang_batch.csv', 'DETAIL_TRANSFER_BARANG_BATCH'),
        ('stock_history_transfer.csv', 'STOCK_HISTORY'),
        ('nota_jual.csv', 'NOTA_JUAL'),
        ('detail_nota_jual.csv', 'DETAIL_NOTA_JUAL'),
        ('stock_history_penjualan.csv', 'STOCK_HISTORY'),
        ('stock.csv', 'STOCK'),
    ]
    
    with open(output_file, 'w', encoding='utf-8') as sql_file:
        # Write header
        sql_file.write("-- ============================================\n")
        sql_file.write("-- INSERT DUMMY DATA untuk Barang 4aCSBjQPd3TzFd90\n")
        sql_file.write("-- Generated from CSV files\n")
        sql_file.write("-- ============================================\n\n")
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
        
        # Process each file
        total_records = 0
        for csv_file, table_name in files_to_import:
            if generate_insert_from_csv(csv_file, table_name, sql_file):
                # Count records
                with open(csv_file, 'r', encoding='utf-8') as f:
                    reader = csv.DictReader(f)
                    total_records += len(list(reader))
        
        # Write footer
        sql_file.write("\nSET FOREIGN_KEY_CHECKS = 1;\n\n")
        sql_file.write(f"-- Total records inserted: {total_records}\n")
        sql_file.write("-- End of INSERT statements\n")
    
    print(f"\n✓ SQL file generated: {output_file}")
    print(f"✓ Total records: {total_records}")

if __name__ == '__main__':
    main()

