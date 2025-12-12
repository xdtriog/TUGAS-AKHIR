#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script lengkap untuk generate data dummy GABUNGAN untuk 2 produk:
- 4aCSBjQPd3TzFd90
- W8r1LuGwd932W3Zq

PESAN_BARANG > TRANSFER (FEFO) > PENJUALAN
- Generate pesan barang secara berkala sampai hari ini
- Transfer dengan logika FEFO (First Expired First Out)
- Penjualan setiap hari
- Auto pemesanan baru jika stock gudang habis
- Auto transfer jika stock toko habis
"""

import csv
from datetime import datetime, timedelta
import random
import string
import uuid
import time
import os

# ===== KONFIGURASI PRODUK =====
PRODUCTS = {
    '4aCSBjQPd3TzFd90': {
        'KD_BARANG': '4aCSBjQPd3TzFd90',
        'SATUAN_PERDUS': 25,  # 25 pieces per dus
        'HARGA_PESAN_DUS': 400000,  # Harga beli per dus (lebih mahal)
        'HARGA_JUAL_PIECE': 40000,  # Harga jual per piece (lebih mahal)
        'MIN_STOCK_TOKO': 100,  # Minimal stock toko dalam PIECES
        'MAX_STOCK_TOKO': 1000,  # Maksimal stock toko dalam PIECES
        'MIN_STOCK_GUDANG': 50,  # Minimal stock gudang dalam DUS
        'MAX_STOCK_GUDANG': 200,  # Maksimal stock gudang dalam DUS
        'MIN_PESAN_DUS': 50,  # Minimal pemesanan DUS
        'MAX_PESAN_DUS': 200,  # Maksimal pemesanan DUS
        'MIN_TRANSFER_DUS': 5,  # Minimal transfer DUS
    },
    'W8r1LuGwd932W3Zq': {
        'KD_BARANG': 'W8r1LuGwd932W3Zq',
        'SATUAN_PERDUS': 10,  # 10 pieces per dus
        'HARGA_PESAN_DUS': 300000,  # Harga beli per dus
        'HARGA_JUAL_PIECE': 33000,  # Harga jual per piece
        'MIN_STOCK_TOKO': 50,  # Minimal stock toko dalam PIECES (ditingkatkan dari 10)
        'MAX_STOCK_TOKO': 200,  # Maksimal stock toko dalam PIECES (ditingkatkan dari 50)
        'MIN_STOCK_GUDANG': 30,  # Minimal stock gudang dalam DUS (ditingkatkan dari 10)
        'MAX_STOCK_GUDANG': 100,  # Maksimal stock gudang dalam DUS (ditingkatkan dari 30)
        'MIN_PESAN_DUS': 20,  # Minimal pemesanan DUS (ditingkatkan dari 10)
        'MAX_PESAN_DUS': 100,  # Maksimal pemesanan DUS (ditingkatkan dari 30)
        'MIN_TRANSFER_DUS': 2,  # Minimal transfer DUS (ditingkatkan dari 1)
    }
}

# Konstanta Lokasi dan User (sama untuk semua produk)
KD_LOKASI_GUDANG = 'GDNGj825'
KD_LOKASI_TOKO_LIST = ['TOKOeLig', 'TOKOjtqG', 'TOKOk3CZ']  # 3 toko
USER_GUDANG = 'GDNGj825'
USER_TOKO_LIST = ['TOKOeLig', 'TOKOrvYu', 'TOKOu8EU']  # 3 user toko
KD_SUPPLIER_1 = 'NGP9zHgE'
KD_SUPPLIER_2 = 'NKnVD9E9'
BIAYA_PENGIRIMAN = 50000

# Periode simulasi
START_DATE = datetime(2024, 1, 1)
END_DATE = datetime.now()  # Sampai hari ini

# Data yang akan di-generate (gabungan untuk semua produk)
pesan_barang_data = []
transfer_data = []
sales_data = []
stock_history_data = []

# Counter (global untuk semua produk)
pesan_counter = 1
transfer_counter = 1
detail_counter = 1
batch_counter = 1
nota_counter = 1
detail_nota_counter = 1
history_counter = 1

# Stock tracking per produk: {kd_barang: stock_gudang, stock_toko}
stock_gudang = {}  # {kd_barang: jumlah_dus}
stock_toko = {}    # {kd_barang: {kd_lokasi: jumlah_pieces}} - per toko

# Batch tracking per produk: {kd_barang: {id_pesan: sisa_stock_dus}}
batch_stock = {}   # {kd_barang: {id_pesan: sisa_stock_dus}}
batch_expired = {} # {kd_barang: {id_pesan: tgl_expired}}

# Tracking untuk AVG_HARGA_BELI_PIECES per produk
purchase_history = {}  # {kd_barang: [(harga_dus, total_masuk_dus), ...]}

# Inisialisasi stock dan tracking untuk setiap produk
for kd_barang in PRODUCTS.keys():
    stock_gudang[kd_barang] = 0
    stock_toko[kd_barang] = {kd_lokasi: 0 for kd_lokasi in KD_LOKASI_TOKO_LIST}  # Per toko
    batch_stock[kd_barang] = {}
    batch_expired[kd_barang] = {}
    purchase_history[kd_barang] = []

# ===== FUNGSI GENERATE ID =====
def generate_id_pesan(tanggal):
    """Generate ID pesan barang dengan format PSBG+UUID"""
    global pesan_counter
    uuid_hex = uuid.uuid4().hex.upper()[:12]
    timestamp_part = str(int(time.time() * 1000000) % 1000000).zfill(6)
    uuid_part = (uuid_hex + timestamp_part)[:12]
    id_pesan = f'PSBG{uuid_part}'
    pesan_counter += 1
    return id_pesan

def generate_id_transfer(tanggal):
    """Generate ID transfer dengan format TRFR+UUID"""
    global transfer_counter
    uuid_hex = uuid.uuid4().hex.upper()[:12]
    timestamp_part = str(int(time.time() * 1000000) % 1000000).zfill(6)
    uuid_part = (uuid_hex + timestamp_part)[:12]
    id_transfer = f'TRFR{uuid_part}'
    transfer_counter += 1
    return id_transfer

def generate_id_detail(tanggal):
    """Generate ID detail transfer dengan format DTFR+UUID"""
    global detail_counter
    uuid_hex = uuid.uuid4().hex.upper()[:12]
    timestamp_part = str(int(time.time() * 1000000) % 1000000).zfill(6)
    uuid_part = (uuid_hex + timestamp_part)[:12]
    id_detail = f'DTFR{uuid_part}'
    detail_counter += 1
    return id_detail

def generate_id_batch(tanggal):
    """Generate ID batch dengan format DTFB+UUID"""
    global batch_counter
    uuid_hex = uuid.uuid4().hex.upper()[:12]
    timestamp_part = str(int(time.time() * 1000000) % 1000000).zfill(6)
    uuid_part = (uuid_hex + timestamp_part)[:12]
    id_batch = f'DTFB{uuid_part}'
    batch_counter += 1
    return id_batch

def generate_id_nota():
    """Generate ID nota jual dengan format NTJL+UUID"""
    global nota_counter
    uuid_hex = uuid.uuid4().hex.upper()[:12]
    timestamp_part = str(int(time.time() * 1000000) % 1000000).zfill(6)
    uuid_part = (uuid_hex + timestamp_part)[:12]
    id_nota = f'NTJL{uuid_part}'
    nota_counter += 1
    return id_nota

def generate_id_detail_nota():
    """Generate ID detail nota jual dengan format DNJB+UUID"""
    global detail_nota_counter
    uuid_hex = uuid.uuid4().hex.upper()[:12]
    timestamp_part = str(int(time.time() * 1000000) % 1000000).zfill(6)
    uuid_part = (uuid_hex + timestamp_part)[:12]
    id_detail = f'DNJB{uuid_part}'
    detail_nota_counter += 1
    return id_detail

def generate_id_history(tanggal):
    """Generate ID history stock dengan format SKHY+UUID"""
    global history_counter
    uuid_hex = uuid.uuid4().hex.upper()[:12]
    timestamp_part = str(int(time.time() * 1000000) % 1000000).zfill(6)
    uuid_part = (uuid_hex + timestamp_part)[:12]
    id_history = f'SKHY{uuid_part}'
    history_counter += 1
    return id_history

# ===== FUNGSI PERHITUNGAN =====
def calculate_avg_harga_beli(kd_barang):
    """Hitung AVG_HARGA_BELI_PIECES berdasarkan weighted average dari purchase_history"""
    if kd_barang not in purchase_history or not purchase_history[kd_barang]:
        return PRODUCTS[kd_barang]['HARGA_PESAN_DUS'] / PRODUCTS[kd_barang]['SATUAN_PERDUS']
    
    total_harga = 0
    total_dus = 0
    
    for harga_dus, jumlah_dus in purchase_history[kd_barang]:
        total_harga += harga_dus * jumlah_dus
        total_dus += jumlah_dus
    
    if total_dus == 0:
        return PRODUCTS[kd_barang]['HARGA_PESAN_DUS'] / PRODUCTS[kd_barang]['SATUAN_PERDUS']
    
    avg_harga_dus = total_harga / total_dus
    return avg_harga_dus / PRODUCTS[kd_barang]['SATUAN_PERDUS']

# ===== FUNGSI SIMULASI PER PRODUK =====
def pesan_barang_baru(kd_barang, tanggal, jumlah_pesan):
    """Generate pemesanan barang baru untuk produk tertentu"""
    product = PRODUCTS[kd_barang]
    
    # Supplier random
    supplier = random.choice([KD_SUPPLIER_1, KD_SUPPLIER_2])
    
    # Harga pesan dengan variasi ±5%
    harga_pesan_dus = random.randint(
        int(product['HARGA_PESAN_DUS'] * 0.95),
        int(product['HARGA_PESAN_DUS'] * 1.05)
    )
    
    # Tgl expired: 6-12 bulan dari sekarang
    months_ahead = random.randint(6, 12)
    tgl_expired = (tanggal + timedelta(days=months_ahead * 30)).strftime('%Y-%m-%d')
    
    id_pesan = generate_id_pesan(tanggal)
    
    # Waktu
    waktu_pesan = (tanggal - timedelta(days=2)).strftime('%Y-%m-%d %H:%M:%S')
    waktu_estimasi = (tanggal - timedelta(hours=2)).strftime('%Y-%m-%d %H:%M:%S')
    # Waktu selesai menggunakan jam random (08:00 - 18:00)
    hour = random.randint(8, 18)
    minute = random.randint(0, 59)
    waktu_selesai = tanggal.replace(hour=hour, minute=minute, second=random.randint(0, 59)).strftime('%Y-%m-%d %H:%M:%S')
    
    # Update stock
    stock_awal = stock_gudang[kd_barang]
    stock_gudang[kd_barang] += jumlah_pesan
    
    # Simpan batch
    batch_stock[kd_barang][id_pesan] = jumlah_pesan
    batch_expired[kd_barang][id_pesan] = tgl_expired
    
    # Simpan untuk perhitungan AVG_HARGA_BELI_PIECES
    purchase_history[kd_barang].append((harga_pesan_dus, jumlah_pesan))
    
    # PESAN_BARANG
    pesan_barang_data.append({
        'ID_PESAN_BARANG': id_pesan,
        'KD_LOKASI': KD_LOKASI_GUDANG,
        'KD_BARANG': kd_barang,
        'ID_PERHITUNGAN_KUANTITAS_POQ': '',
        'KD_SUPPLIER': supplier,
        'HARGA_PESAN_BARANG_DUS': f'{harga_pesan_dus:.2f}',
        'BIAYA_PENGIRIMAAN': f'{BIAYA_PENGIRIMAN:.2f}',
        'JUMLAH_PESAN_BARANG_DUS': jumlah_pesan,
        'JUMLAH_TIBA_DUS': jumlah_pesan,
        'JUMLAH_DITOLAK_DUS': 0,
        'TOTAL_MASUK_DUS': jumlah_pesan,
        'SISA_STOCK_DUS': jumlah_pesan,
        'TGL_EXPIRED': tgl_expired,
        'WAKTU_PESAN': waktu_pesan,
        'WAKTU_ESTIMASI_SELESAI': waktu_estimasi,
        'WAKTU_SELESAI': waktu_selesai,
        'STATUS': 'SELESAI'
    })
    
    # STOCK_HISTORY - Pemesanan
    id_history = generate_id_history(tanggal)
    stock_history_data.append({
        'ID_HISTORY_STOCK': id_history,
        'KD_BARANG': kd_barang,
        'KD_LOKASI': KD_LOKASI_GUDANG,
        'UPDATED_BY': USER_GUDANG,
        'JUMLAH_AWAL': stock_awal,
        'JUMLAH_PERUBAHAN': jumlah_pesan,
        'JUMLAH_AKHIR': stock_gudang[kd_barang],
        'TIPE_PERUBAHAN': 'PEMESANAN',
        'REF': id_pesan,
        'SATUAN': 'DUS',
        'WAKTU_CHANGE': waktu_selesai
    })
    
    return id_pesan

def transfer_barang_fifo(kd_barang, tanggal, jumlah_transfer_dus, kd_lokasi_tujuan, user_toko):
    """
    Transfer barang dengan logika FEFO (First Expired First Out) untuk produk tertentu ke toko tertentu
    """
    product = PRODUCTS[kd_barang]
    
    if stock_gudang[kd_barang] <= 0 or jumlah_transfer_dus <= 0:
        return None
    
    # Sort batch berdasarkan expired date (FEFO)
    sorted_batches = sorted(
        [(id_pesan, batch_stock[kd_barang][id_pesan], batch_expired[kd_barang][id_pesan]) 
         for id_pesan in batch_stock[kd_barang].keys() if batch_stock[kd_barang][id_pesan] > 0],
        key=lambda x: x[2] if x[2] else '9999-12-31'
    )
    
    if not sorted_batches:
        return None
    
    # Alokasi batch dengan FEFO
    sisa_kebutuhan = min(jumlah_transfer_dus, stock_gudang[kd_barang])
    batch_allocation = []  # List of (id_pesan, jumlah_dus)
    
    for id_pesan, sisa_batch, tgl_expired in sorted_batches:
        if sisa_kebutuhan <= 0:
            break
        
        if sisa_batch > 0:
            ambil_dari_batch = min(sisa_batch, sisa_kebutuhan)
            batch_allocation.append((id_pesan, ambil_dari_batch))
            batch_stock[kd_barang][id_pesan] -= ambil_dari_batch
            sisa_kebutuhan -= ambil_dari_batch
    
    if not batch_allocation:
        return None
    
    # Update stock
    stock_gudang_awal = stock_gudang[kd_barang]
    jumlah_transfer_actual = sum(j[1] for j in batch_allocation)
    stock_gudang[kd_barang] -= jumlah_transfer_actual
    
    jumlah_transfer_pieces = jumlah_transfer_actual * product['SATUAN_PERDUS']
    stock_toko_awal = stock_toko[kd_barang][kd_lokasi_tujuan]
    stock_toko[kd_barang][kd_lokasi_tujuan] += jumlah_transfer_pieces
    
    # ID Transfer
    transfer_id = generate_id_transfer(tanggal)
    detail_id = generate_id_detail(tanggal)
    
    # Waktu transfer: gunakan jam random (08:00 - 10:00) untuk memastikan terjadi sebelum penjualan
    hour_transfer = random.randint(8, 10)
    minute_transfer = random.randint(0, 59)
    waktu_pesan_transfer = tanggal.replace(hour=hour_transfer-2, minute=minute_transfer, second=0)
    waktu_kirim_transfer = tanggal.replace(hour=hour_transfer-1, minute=minute_transfer, second=0)
    waktu_selesai_transfer = tanggal.replace(hour=hour_transfer, minute=minute_transfer, second=0)
    
    # TRANSFER_BARANG
    transfer_data.append({
        'type': 'transfer',
        'ID_TRANSFER_BARANG': transfer_id,
        'ID_USERS_PENGIRIM': USER_GUDANG,
        'ID_USERS_PENERIMA': user_toko,
        'KD_LOKASI_ASAL': KD_LOKASI_GUDANG,
        'KD_LOKASI_TUJUAN': kd_lokasi_tujuan,
        'WAKTU_PESAN_TRANSFER': waktu_pesan_transfer.strftime('%Y-%m-%d %H:%M:%S'),
        'WAKTU_KIRIM_TRANSFER': waktu_kirim_transfer.strftime('%Y-%m-%d %H:%M:%S'),
        'WAKTU_SELESAI_TRANSFER': waktu_selesai_transfer.strftime('%Y-%m-%d %H:%M:%S'),
        'STATUS': 'SELESAI'
    })
    
    # DETAIL_TRANSFER_BARANG
    transfer_data.append({
        'type': 'detail_transfer',
        'ID_DETAIL_TRANSFER_BARANG': detail_id,
        'ID_TRANSFER_BARANG': transfer_id,
        'KD_BARANG': kd_barang,
        'TOTAL_PESAN_TRANSFER_DUS': jumlah_transfer_actual,
        'TOTAL_KIRIM_DUS': jumlah_transfer_actual,
        'TOTAL_TIBA_DUS': jumlah_transfer_actual,
        'TOTAL_DITOLAK_DUS': 0,
        'TOTAL_MASUK_DUS': jumlah_transfer_actual,
        'STATUS': 'SELESAI'
    })
    
    # DETAIL_TRANSFER_BARANG_BATCH
    for id_pesan, jumlah_dus_batch in batch_allocation:
        batch_id = generate_id_batch(tanggal)
        transfer_data.append({
            'type': 'detail_batch',
            'ID_DETAIL_TRANSFER_BARANG_BATCH': batch_id,
            'ID_DETAIL_TRANSFER_BARANG': detail_id,
            'ID_PESAN_BARANG': id_pesan,
            'JUMLAH_PESAN_TRANSFER_BATCH_DUS': jumlah_dus_batch,
            'JUMLAH_KIRIM_DUS': jumlah_dus_batch,
            'JUMLAH_TIBA_DUS': jumlah_dus_batch,
            'JUMLAH_DITOLAK_DUS': 0,
            'JUMLAH_MASUK_DUS': jumlah_dus_batch
        })
    
    # Waktu transfer keluar dari gudang: sama dengan waktu selesai transfer (08:00-10:00)
    waktu_transfer_g = waktu_selesai_transfer
    
    # STOCK_HISTORY - Transfer Keluar (Gudang)
    id_history_g = generate_id_history(tanggal)
    stock_history_data.append({
        'ID_HISTORY_STOCK': id_history_g,
        'KD_BARANG': kd_barang,
        'KD_LOKASI': KD_LOKASI_GUDANG,
        'UPDATED_BY': USER_GUDANG,
        'JUMLAH_AWAL': stock_gudang_awal,
        'JUMLAH_PERUBAHAN': -jumlah_transfer_actual,
        'JUMLAH_AKHIR': stock_gudang[kd_barang],
        'TIPE_PERUBAHAN': 'TRANSFER',
        'REF': transfer_id,
        'SATUAN': 'DUS',
        'WAKTU_CHANGE': waktu_transfer_g.strftime('%Y-%m-%d %H:%M:%S')
    })
    
    # STOCK_HISTORY - Transfer Masuk (Toko) - terjadi setelah transfer keluar (tambah 1-5 menit)
    # Pastikan waktu transfer masuk ke toko maksimal jam 10:30 agar sebelum penjualan (yang mulai jam 11:00)
    waktu_transfer_t = waktu_transfer_g + timedelta(minutes=random.randint(1, 5))
    # Jika waktu transfer masuk melewati jam 10:30, set ke maksimal 10:30
    if waktu_transfer_t.hour > 10 or (waktu_transfer_t.hour == 10 and waktu_transfer_t.minute > 30):
        waktu_transfer_t = tanggal.replace(hour=10, minute=30, second=0)
    id_history_t = generate_id_history(tanggal)
    stock_history_data.append({
        'ID_HISTORY_STOCK': id_history_t,
        'KD_BARANG': kd_barang,
        'KD_LOKASI': kd_lokasi_tujuan,
        'UPDATED_BY': user_toko,
        'JUMLAH_AWAL': stock_toko_awal,
        'JUMLAH_PERUBAHAN': jumlah_transfer_pieces,
        'JUMLAH_AKHIR': stock_toko[kd_barang][kd_lokasi_tujuan],
        'TIPE_PERUBAHAN': 'TRANSFER',
        'REF': transfer_id,
        'SATUAN': 'PIECES',
        'WAKTU_CHANGE': waktu_transfer_t.strftime('%Y-%m-%d %H:%M:%S')
    })
    
    return transfer_id

def penjualan(kd_barang, tanggal, jumlah_jual, kd_lokasi_toko, user_toko):
    """Generate penjualan untuk produk tertentu di toko tertentu"""
    product = PRODUCTS[kd_barang]
    
    if stock_toko[kd_barang][kd_lokasi_toko] < jumlah_jual:
        jumlah_jual = stock_toko[kd_barang][kd_lokasi_toko]
    
    if jumlah_jual <= 0:
        return None
    
    # Update stock
    stock_toko_awal = stock_toko[kd_barang][kd_lokasi_toko]
    stock_toko[kd_barang][kd_lokasi_toko] -= jumlah_jual
    
    # Hitung harga
    harga_beli = calculate_avg_harga_beli(kd_barang)
    harga_jual = product['HARGA_JUAL_PIECE']
    
    # Hitung total
    total_beli_uang = jumlah_jual * harga_beli
    total_jual_uang = jumlah_jual * harga_jual
    
    # ID
    nota_id = generate_id_nota()
    detail_id = generate_id_detail_nota()
    
    # Waktu transaksi: 11:00 - 20:00 (setelah transfer yang terjadi 08:00-10:30)
    # Memastikan penjualan terjadi setelah transfer masuk ke toko
    hour = random.randint(11, 20)
    minute = random.randint(0, 59)
    waktu_nota = tanggal.replace(hour=hour, minute=minute, second=random.randint(0, 59))
    
    # NOTA_JUAL
    sales_data.append({
        'type': 'nota',
        'ID_NOTA_JUAL': nota_id,
        'ID_USERS': user_toko,
        'KD_LOKASI': kd_lokasi_toko,
        'WAKTU_NOTA': waktu_nota.strftime('%Y-%m-%d %H:%M:%S'),
        'TOTAL_JUAL_BARANG': jumlah_jual,
        'SUB_TOTAL_JUAL': total_jual_uang,
        'SUB_TOTAL_BELI': total_beli_uang,
        'GROSS_PROFIT': total_jual_uang - total_beli_uang,
        'PAJAK': 0,
        'GRAND_TOTAL': 0
    })
    
    # DETAIL_NOTA_JUAL
    sales_data.append({
        'type': 'detail_nota',
        'ID_DNJB': detail_id,
        'KD_BARANG': kd_barang,
        'ID_NOTA_JUAL': nota_id,
        'JUMLAH_JUAL_BARANG': jumlah_jual,
        'HARGA_JUAL_BARANG': harga_jual,
        'TOTAL_JUAL_UANG': total_jual_uang,
        'HARGA_BELI_BARANG': harga_beli,
        'TOTAL_BELI_UANG': total_beli_uang
    })
    
    # STOCK_HISTORY - Penjualan
    id_history = generate_id_history(waktu_nota)
    stock_history_data.append({
        'ID_HISTORY_STOCK': id_history,
        'KD_BARANG': kd_barang,
        'KD_LOKASI': kd_lokasi_toko,
        'UPDATED_BY': user_toko,
        'JUMLAH_AWAL': stock_toko_awal,
        'JUMLAH_PERUBAHAN': -jumlah_jual,
        'JUMLAH_AKHIR': stock_toko[kd_barang][kd_lokasi_toko],
        'TIPE_PERUBAHAN': 'PENJUALAN',
        'REF': detail_id,
        'SATUAN': 'PIECES',
        'WAKTU_CHANGE': waktu_nota.strftime('%Y-%m-%d %H:%M:%S')
    })
    
    return nota_id

# ===== SIMULASI =====
print("=" * 80)
print("GENERATE DATA DUMMY LENGKAP (GABUNGAN 2 PRODUK)")
print("=" * 80)
print(f"Periode: {START_DATE.strftime('%Y-%m-%d')} sampai {END_DATE.strftime('%Y-%m-%d')}")
print(f"Produk: {', '.join(PRODUCTS.keys())}")
print()

current_date = START_DATE

print("Memulai simulasi...")

while current_date <= END_DATE:
    # Loop untuk setiap produk
    for kd_barang, product in PRODUCTS.items():
        # 1. PESAN BARANG (pemesanan ketika stock gudang mendekati minimal stock)
        # Logika realistis: pesan ketika stock gudang <= MIN_STOCK_GUDANG
        if stock_gudang[kd_barang] <= product['MIN_STOCK_GUDANG']:
            # Hitung kebutuhan untuk mengisi gudang sampai MAX_STOCK_GUDANG
            kebutuhan_dus = max(
                product['MAX_STOCK_GUDANG'] - stock_gudang[kd_barang],
                product['MIN_STOCK_GUDANG'] + (product['MAX_STOCK_GUDANG'] - product['MIN_STOCK_GUDANG']) // 2 - stock_gudang[kd_barang]
            )
            
            if kebutuhan_dus >= product['MIN_PESAN_DUS']:
                jumlah_pesan_dus = min(kebutuhan_dus, product['MAX_PESAN_DUS'])
                stock_gudang_sebelum = stock_gudang[kd_barang]
                pesan_date = current_date - timedelta(days=2)
                pesan_barang_baru(kd_barang, pesan_date, jumlah_pesan_dus)
                print(f"  [{current_date.strftime('%Y-%m-%d')}] [{kd_barang}] Pemesanan: {jumlah_pesan_dus} DUS, Stock gudang: {stock_gudang_sebelum} -> {stock_gudang[kd_barang]} DUS")
        
        # 2. TRANSFER BARANG (resupply ketika stock toko mendekati minimal stock)
        # Logika realistis: cek setiap toko, jika stock <= MIN_STOCK_TOKO baru transfer
        if stock_gudang[kd_barang] > 0:
            for idx, kd_lokasi_toko in enumerate(KD_LOKASI_TOKO_LIST):
                user_toko = USER_TOKO_LIST[idx]
                stock_toko_sekarang = stock_toko[kd_barang][kd_lokasi_toko]
                
                # Transfer hanya jika stock toko <= MIN_STOCK_TOKO (mau habis)
                if stock_toko_sekarang <= product['MIN_STOCK_TOKO']:
                    # Hitung kebutuhan untuk mengisi toko sampai MAX_STOCK_TOKO
                    kebutuhan_pieces = max(
                        product['MAX_STOCK_TOKO'] - stock_toko_sekarang,
                        product['MIN_STOCK_TOKO'] + (product['MAX_STOCK_TOKO'] - product['MIN_STOCK_TOKO']) // 2 - stock_toko_sekarang
                    )
                    # Konversi kebutuhan pieces ke DUS
                    kebutuhan_dus = (kebutuhan_pieces + product['SATUAN_PERDUS'] - 1) // product['SATUAN_PERDUS']
                    
                    # Batasi dengan stock gudang yang tersedia
                    jumlah_transfer_dus = min(kebutuhan_dus, stock_gudang[kd_barang])
                    
                    if jumlah_transfer_dus >= product['MIN_TRANSFER_DUS']:
                        transfer_id = transfer_barang_fifo(kd_barang, current_date, jumlah_transfer_dus, kd_lokasi_toko, user_toko)
                        if transfer_id:
                            jumlah_transfer_pieces = jumlah_transfer_dus * product['SATUAN_PERDUS']
                            print(f"  [{current_date.strftime('%Y-%m-%d')}] [{kd_barang}] Resupply ke {kd_lokasi_toko}: {jumlah_transfer_dus} DUS ({jumlah_transfer_pieces} PIECES), Stock toko: {stock_toko_sekarang} -> {stock_toko[kd_barang][kd_lokasi_toko]} PIECES")
        
        # 3. PENJUALAN (setiap hari, 2-5 transaksi per toko)
        for idx, kd_lokasi_toko in enumerate(KD_LOKASI_TOKO_LIST):
            user_toko = USER_TOKO_LIST[idx]
            stock_toko_sekarang = stock_toko[kd_barang][kd_lokasi_toko]
            
            if stock_toko_sekarang > 0:
                num_transactions = random.randint(2, 5)
                for _ in range(num_transactions):
                    if stock_toko_sekarang <= 0:
                        break
                    
                    max_jual = min(30, stock_toko_sekarang)
                    if max_jual < 1:
                        break
                    jumlah_jual = random.randint(1, max_jual)
                    penjualan(kd_barang, current_date, jumlah_jual, kd_lokasi_toko, user_toko)
                    stock_toko_sekarang = stock_toko[kd_barang][kd_lokasi_toko]  # Update setelah penjualan
    
    # Next day
    current_date += timedelta(days=1)

# Update SISA_STOCK_DUS di pesan_barang_data
for pesan in pesan_barang_data:
    id_pesan = pesan['ID_PESAN_BARANG']
    kd_barang = pesan['KD_BARANG']
    pesan['SISA_STOCK_DUS'] = batch_stock[kd_barang].get(id_pesan, 0)

# ===== VERIFIKASI DAN PERBAIKAN JUMLAH_AKHIR =====
print()
print("Memverifikasi dan memperbaiki JUMLAH_AKHIR...")

# Sort berdasarkan WAKTU_CHANGE
stock_history_data_sorted = sorted(stock_history_data, key=lambda x: (x['WAKTU_CHANGE'], x['ID_HISTORY_STOCK']))

# Verifikasi dan perbaiki JUMLAH_AWAL dan JUMLAH_AKHIR berdasarkan urutan waktu
stock_track = {}

for h in stock_history_data_sorted:
    kd_lokasi = h['KD_LOKASI']
    kd_barang = h['KD_BARANG']
    key = f"{kd_barang}_{kd_lokasi}"
    
    # Ambil JUMLAH_AWAL dari stock akhir transaksi sebelumnya
    if key in stock_track:
        # Perbaiki JUMLAH_AWAL jika tidak sesuai dengan stock akhir transaksi sebelumnya
        if h['JUMLAH_AWAL'] != stock_track[key]:
            print(f"WARNING: JUMLAH_AWAL tidak konsisten untuk {h['ID_HISTORY_STOCK']} ({h['TIPE_PERUBAHAN']}): expected {stock_track[key]}, got {h['JUMLAH_AWAL']}. Memperbaiki...")
            h['JUMLAH_AWAL'] = stock_track[key]
    else:
        # Transaksi pertama untuk lokasi ini, simpan JUMLAH_AWAL sebagai baseline
        stock_track[key] = h['JUMLAH_AWAL']
    
    # Hitung JUMLAH_AKHIR yang benar
    expected_akhir = h['JUMLAH_AWAL'] + h['JUMLAH_PERUBAHAN']
    if h['JUMLAH_AKHIR'] != expected_akhir:
        print(f"WARNING: JUMLAH_AKHIR tidak konsisten untuk {h['ID_HISTORY_STOCK']} ({h['TIPE_PERUBAHAN']}): expected {expected_akhir}, got {h['JUMLAH_AKHIR']}. Memperbaiki...")
        h['JUMLAH_AKHIR'] = expected_akhir
    
    # Update stock tracker dengan stock akhir transaksi ini
    stock_track[key] = h['JUMLAH_AKHIR']

# ===== WRITE CSV FILES =====
print()
print("Menulis file CSV...")

# Buat folder output jika belum ada
os.makedirs('output', exist_ok=True)

# PESAN_BARANG
with open('output/pesan_barang.csv', 'w', newline='', encoding='utf-8') as f:
    fieldnames = ['ID_PESAN_BARANG', 'KD_LOKASI', 'KD_BARANG', 'ID_PERHITUNGAN_KUANTITAS_POQ', 'KD_SUPPLIER',
                  'HARGA_PESAN_BARANG_DUS', 'BIAYA_PENGIRIMAAN', 'JUMLAH_PESAN_BARANG_DUS', 'JUMLAH_TIBA_DUS',
                  'JUMLAH_DITOLAK_DUS', 'TOTAL_MASUK_DUS', 'SISA_STOCK_DUS', 'TGL_EXPIRED',
                  'WAKTU_PESAN', 'WAKTU_ESTIMASI_SELESAI', 'WAKTU_SELESAI', 'STATUS']
    writer = csv.DictWriter(f, fieldnames=fieldnames)
    writer.writeheader()
    writer.writerows(pesan_barang_data)

# TRANSFER_BARANG
with open('output/transfer_barang.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['ID_TRANSFER_BARANG', 'ID_USERS_PENGIRIM', 'ID_USERS_PENERIMA', 'KD_LOKASI_ASAL', 'KD_LOKASI_TUJUAN',
                     'WAKTU_PESAN_TRANSFER', 'WAKTU_KIRIM_TRANSFER', 'WAKTU_SELESAI_TRANSFER', 'STATUS'])
    for t in transfer_data:
        if t['type'] == 'transfer':
            writer.writerow([t['ID_TRANSFER_BARANG'], t['ID_USERS_PENGIRIM'], t['ID_USERS_PENERIMA'],
                            t['KD_LOKASI_ASAL'], t['KD_LOKASI_TUJUAN'], t['WAKTU_PESAN_TRANSFER'],
                            t['WAKTU_KIRIM_TRANSFER'], t['WAKTU_SELESAI_TRANSFER'], t['STATUS']])

# DETAIL_TRANSFER_BARANG
with open('output/detail_transfer_barang.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['ID_DETAIL_TRANSFER_BARANG', 'ID_TRANSFER_BARANG', 'KD_BARANG', 'TOTAL_PESAN_TRANSFER_DUS',
                     'TOTAL_KIRIM_DUS', 'TOTAL_TIBA_DUS', 'TOTAL_DITOLAK_DUS', 'TOTAL_MASUK_DUS', 'STATUS'])
    for t in transfer_data:
        if t['type'] == 'detail_transfer':
            writer.writerow([t['ID_DETAIL_TRANSFER_BARANG'], t['ID_TRANSFER_BARANG'], t['KD_BARANG'],
                            t['TOTAL_PESAN_TRANSFER_DUS'], t['TOTAL_KIRIM_DUS'], t['TOTAL_TIBA_DUS'],
                            t['TOTAL_DITOLAK_DUS'], t['TOTAL_MASUK_DUS'], t['STATUS']])

# DETAIL_TRANSFER_BARANG_BATCH
with open('output/detail_transfer_barang_batch.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['ID_DETAIL_TRANSFER_BARANG_BATCH', 'ID_DETAIL_TRANSFER_BARANG', 'ID_PESAN_BARANG',
                     'JUMLAH_PESAN_TRANSFER_BATCH_DUS', 'JUMLAH_KIRIM_DUS', 'JUMLAH_TIBA_DUS',
                     'JUMLAH_DITOLAK_DUS', 'JUMLAH_MASUK_DUS'])
    for t in transfer_data:
        if t['type'] == 'detail_batch':
            writer.writerow([t['ID_DETAIL_TRANSFER_BARANG_BATCH'], t['ID_DETAIL_TRANSFER_BARANG'], t['ID_PESAN_BARANG'],
                            t['JUMLAH_PESAN_TRANSFER_BATCH_DUS'], t['JUMLAH_KIRIM_DUS'], t['JUMLAH_TIBA_DUS'],
                            t['JUMLAH_DITOLAK_DUS'], t['JUMLAH_MASUK_DUS']])

# NOTA_JUAL - Aggregate data dari detail
nota_dict = {}
for s in sales_data:
    if s['type'] == 'nota':
        nota_id = s['ID_NOTA_JUAL']
        nota_dict[nota_id] = {
            'ID_NOTA_JUAL': nota_id,
            'ID_USERS': s['ID_USERS'],
            'KD_LOKASI': s['KD_LOKASI'],
            'WAKTU_NOTA': s['WAKTU_NOTA'],
            'TOTAL_JUAL_BARANG': 0,
            'SUB_TOTAL_JUAL': 0,
            'SUB_TOTAL_BELI': 0,
            'GROSS_PROFIT': 0
        }

# Aggregate dari detail_nota
for s in sales_data:
    if s['type'] == 'detail_nota':
        nota_id = s['ID_NOTA_JUAL']
        if nota_id in nota_dict:
            nota_dict[nota_id]['TOTAL_JUAL_BARANG'] += s['JUMLAH_JUAL_BARANG']
            nota_dict[nota_id]['SUB_TOTAL_JUAL'] += s['TOTAL_JUAL_UANG']
            nota_dict[nota_id]['SUB_TOTAL_BELI'] += s['TOTAL_BELI_UANG']

# Hitung GROSS_PROFIT, PAJAK, dan GRAND_TOTAL
for nota_id, nota in nota_dict.items():
    nota['GROSS_PROFIT'] = nota['SUB_TOTAL_JUAL'] - nota['SUB_TOTAL_BELI']
    nota['PAJAK'] = nota['SUB_TOTAL_JUAL'] * 0.11  # PPN 11%
    nota['GRAND_TOTAL'] = nota['SUB_TOTAL_JUAL'] + nota['PAJAK']

# NOTA_JUAL
with open('output/nota_jual.csv', 'w', newline='', encoding='utf-8') as f:
    fieldnames = ['ID_NOTA_JUAL', 'ID_USERS', 'KD_LOKASI', 'WAKTU_NOTA', 'TOTAL_JUAL_BARANG',
                  'SUB_TOTAL_JUAL', 'SUB_TOTAL_BELI', 'GROSS_PROFIT', 'PAJAK', 'GRAND_TOTAL']
    writer = csv.DictWriter(f, fieldnames=fieldnames)
    writer.writeheader()
    writer.writerows(nota_dict.values())

# DETAIL_NOTA_JUAL
with open('output/detail_nota_jual.csv', 'w', newline='', encoding='utf-8') as f:
    fieldnames = ['ID_DNJB', 'KD_BARANG', 'ID_NOTA_JUAL', 'JUMLAH_JUAL_BARANG', 'HARGA_JUAL_BARANG',
                  'TOTAL_JUAL_UANG', 'HARGA_BELI_BARANG', 'TOTAL_BELI_UANG']
    writer = csv.DictWriter(f, fieldnames=fieldnames)
    writer.writeheader()
    for s in sales_data:
        if s['type'] == 'detail_nota':
            writer.writerow({
                'ID_DNJB': s['ID_DNJB'],
                'KD_BARANG': s['KD_BARANG'],
                'ID_NOTA_JUAL': s['ID_NOTA_JUAL'],
                'JUMLAH_JUAL_BARANG': s['JUMLAH_JUAL_BARANG'],
                'HARGA_JUAL_BARANG': s['HARGA_JUAL_BARANG'],
                'TOTAL_JUAL_UANG': s['TOTAL_JUAL_UANG'],
                'HARGA_BELI_BARANG': s['HARGA_BELI_BARANG'],
                'TOTAL_BELI_UANG': s['TOTAL_BELI_UANG']
            })

# STOCK_HISTORY
with open('output/stock_history_all.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['ID_HISTORY_STOCK', 'KD_BARANG', 'KD_LOKASI', 'UPDATED_BY', 'JUMLAH_AWAL', 'JUMLAH_PERUBAHAN',
                     'JUMLAH_AKHIR', 'TIPE_PERUBAHAN', 'REF', 'SATUAN', 'WAKTU_CHANGE'])
    writer.writerows([
        [h['ID_HISTORY_STOCK'], h['KD_BARANG'], h['KD_LOKASI'], h['UPDATED_BY'], h['JUMLAH_AWAL'],
         h['JUMLAH_PERUBAHAN'], h['JUMLAH_AKHIR'], h['TIPE_PERUBAHAN'], h['REF'], h['SATUAN'], h['WAKTU_CHANGE']]
        for h in stock_history_data_sorted
    ])

# STOCK
today = datetime.now()
with open('output/stock.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['KD_BARANG', 'KD_LOKASI', 'UPDATED_BY', 'JUMLAH_BARANG', 'JUMLAH_MIN_STOCK', 'JUMLAH_MAX_STOCK',
                     'SATUAN', 'LAST_UPDATED'])
    for kd_barang, product in PRODUCTS.items():
        # Stock gudang
        writer.writerow([kd_barang, KD_LOKASI_GUDANG, USER_GUDANG, stock_gudang[kd_barang], 
                        product['MIN_STOCK_GUDANG'], product['MAX_STOCK_GUDANG'], 'DUS',
                        today.strftime('%Y-%m-%d %H:%M:%S')])
        # Stock per toko
        for idx, kd_lokasi_toko in enumerate(KD_LOKASI_TOKO_LIST):
            user_toko = USER_TOKO_LIST[idx]
            writer.writerow([kd_barang, kd_lokasi_toko, user_toko, stock_toko[kd_barang][kd_lokasi_toko], 
                            product['MIN_STOCK_TOKO'], product['MAX_STOCK_TOKO'], 'PIECES',
                            today.strftime('%Y-%m-%d %H:%M:%S')])

# ===== SUMMARY =====
print()
print("=" * 80)
print("SUMMARY")
print("=" * 80)
total_pemesanan = len(pesan_barang_data)
total_transfer = len([t for t in transfer_data if t['type'] == 'transfer'])
total_penjualan = len([s for s in sales_data if s['type'] == 'nota'])
total_stock_history = len(stock_history_data_sorted)

print(f"Total Pemesanan: {total_pemesanan}")
print(f"Total Transfer: {total_transfer}")
print(f"Total Penjualan: {total_penjualan}")
print(f"Total Stock History: {total_stock_history}")
print()

for kd_barang, product in PRODUCTS.items():
    print(f"Produk: {kd_barang}")
    print(f"  Stock Gudang Akhir: {stock_gudang[kd_barang]} DUS")
    for idx, kd_lokasi_toko in enumerate(KD_LOKASI_TOKO_LIST):
        print(f"  Stock Toko {kd_lokasi_toko} Akhir: {stock_toko[kd_barang][kd_lokasi_toko]} PIECES")
    avg_harga = calculate_avg_harga_beli(kd_barang)
    print(f"  AVG_HARGA_BELI_PIECES: Rp {avg_harga:,.2f}")
    print()

print("[OK] Semua file CSV berhasil di-generate di folder 'output'!")
print("=" * 80)

