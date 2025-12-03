#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script lengkap untuk generate data dummy: PESAN_BARANG > TRANSFER (FEFO) > PENJUALAN
- Generate pesan barang secara berkala sampai hari ini
- Transfer dengan logika FEFO (First Expired First Out)
- Penjualan setiap hari
- Auto pemesanan baru jika stock gudang habis
- Auto transfer jika stock toko habis
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
KD_SUPPLIER_1 = 'NGP9zHgE'
KD_SUPPLIER_2 = 'NKnVD9E9'
SATUAN_PERDUS = 25  # 25 pieces per dus (sesuai dengan MASTER_BARANG untuk 4aCSBjQPd3TzFd90)
HARGA_PESAN_DUS = 125000  # Harga beli per dus
BIAYA_PENGIRIMAN = 50000  # Biaya pengiriman
HARGA_JUAL_PIECE = 15000  # Harga jual per piece
MIN_STOCK_TOKO = 100  # Minimal stock toko dalam PIECES (trigger untuk resupply)
MAX_STOCK_TOKO = 1000  # Maksimal stock toko dalam PIECES (target setelah resupply)
MIN_STOCK_GUDANG = 50  # Minimal stock gudang dalam DUS (trigger untuk pemesanan)
MAX_STOCK_GUDANG = 200  # Maksimal stock gudang dalam DUS (target setelah pemesanan)

# Periode simulasi
START_DATE = datetime(2024, 1, 1)
END_DATE = datetime.now()  # Sampai hari ini

# Data yang akan di-generate
pesan_barang_data = []
transfer_data = []
sales_data = []
stock_history_data = []

# Counter
pesan_counter = 1
transfer_counter = 1
detail_counter = 1
batch_counter = 1
nota_counter = 1
detail_nota_counter = 1
history_counter = 1

# Stock tracking
stock_gudang = 0  # DUS
stock_toko = 0    # PIECES

# Batch tracking (untuk FEFO)
batch_stock = {}  # {id_pesan: sisa_stock_dus}
batch_expired = {}  # {id_pesan: tgl_expired}

# Tracking untuk AVG_HARGA_BELI_PIECES
purchase_history = []  # List of (harga_dus, total_masuk_dus) untuk menghitung weighted average

def generate_id_pesan(tanggal):
    """Generate ID pesan barang"""
    global pesan_counter
    id_pesan = f'PB{tanggal.strftime("%Y%m%d")}{pesan_counter:03d}'
    pesan_counter += 1
    return id_pesan

def generate_id_transfer(tanggal):
    """Generate ID transfer"""
    global transfer_counter
    id_transfer = f'TRF{tanggal.strftime("%Y%m%d")}{transfer_counter:03d}'
    transfer_counter += 1
    return id_transfer

def generate_id_detail(tanggal):
    """Generate ID detail transfer"""
    global detail_counter
    id_detail = f'DTL{tanggal.strftime("%Y%m%d")}{detail_counter:03d}'
    detail_counter += 1
    return id_detail

def generate_id_batch(tanggal):
    """Generate ID batch"""
    global batch_counter
    id_batch = f'BTCH{tanggal.strftime("%Y%m%d")}{batch_counter:03d}'
    batch_counter += 1
    return id_batch

def generate_id_nota():
    """Generate ID nota"""
    global nota_counter
    id_nota = f'NOTA{nota_counter:06d}'
    nota_counter += 1
    return id_nota

def generate_id_detail_nota():
    """Generate ID detail nota"""
    global detail_nota_counter
    id_detail = f'DNJB{detail_nota_counter:06d}'
    detail_nota_counter += 1
    return id_detail

def generate_id_history(tanggal):
    """Generate ID stock history"""
    global history_counter
    id_history = f'SH{tanggal.strftime("%Y%m%d%H%M")}{history_counter % 100:02d}'
    history_counter += 1
    return id_history

def calculate_avg_harga_beli():
    """Hitung AVG_HARGA_BELI_PIECES berdasarkan weighted average dari semua pemesanan"""
    if not purchase_history:
        return 0.0
    
    total_harga_quantity = sum(harga_dus * total_masuk_dus for harga_dus, total_masuk_dus in purchase_history)
    total_quantity = sum(total_masuk_dus * SATUAN_PERDUS for _, total_masuk_dus in purchase_history)
    
    if total_quantity > 0:
        return total_harga_quantity / total_quantity
    return 0.0

def pesan_barang_baru(tanggal, jumlah_pesan=None):
    """
    Generate pemesanan barang baru
    
    Args:
        tanggal: Tanggal barang masuk
        jumlah_pesan: Jumlah DUS yang dipesan (optional, jika None akan random 50-100)
    
    Returns:
        id_pesan jika berhasil
    """
    global stock_gudang
    
    # Jumlah pesan: jika tidak ditentukan, random 50-100 dus
    if jumlah_pesan is None:
        jumlah_pesan = random.randint(50, 100)
    
    # Harga pesan: bisa bervariasi sedikit (120000-130000 per dus)
    harga_pesan_dus = random.randint(120000, 130000)
    
    # Tanggal expired: minimal minggu depan sampai maksimal 2027
    min_expired = tanggal + timedelta(days=7)
    max_expired = datetime(2027, 12, 31)
    days_range = (max_expired - min_expired).days
    random_days = random.randint(0, days_range)
    tgl_expired = (min_expired + timedelta(days=random_days)).strftime('%Y-%m-%d')
    
    # Supplier random
    supplier = random.choice([KD_SUPPLIER_1, KD_SUPPLIER_2])
    
    # ID
    id_pesan = generate_id_pesan(tanggal)
    
    # Waktu
    waktu_pesan = (tanggal - timedelta(days=2)).strftime('%Y-%m-%d %H:%M:%S')
    waktu_estimasi = (tanggal - timedelta(hours=2)).strftime('%Y-%m-%d %H:%M:%S')
    waktu_selesai = tanggal.strftime('%Y-%m-%d %H:%M:%S')
    
    # Update stock
    stock_awal = stock_gudang
    stock_gudang += jumlah_pesan
    
    # Simpan batch
    batch_stock[id_pesan] = jumlah_pesan
    batch_expired[id_pesan] = tgl_expired
    
    # Simpan untuk perhitungan AVG_HARGA_BELI_PIECES
    purchase_history.append((harga_pesan_dus, jumlah_pesan))
    
    # PESAN_BARANG
    pesan_barang_data.append({
        'ID_PESAN_BARANG': id_pesan,
        'KD_LOKASI': KD_LOKASI_GUDANG,
        'KD_BARANG': KD_BARANG,
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
        'KD_BARANG': KD_BARANG,
        'KD_LOKASI': KD_LOKASI_GUDANG,
        'UPDATED_BY': USER_GUDANG,
        'JUMLAH_AWAL': stock_awal,
        'JUMLAH_PERUBAHAN': jumlah_pesan,
        'JUMLAH_AKHIR': stock_gudang,
        'TIPE_PERUBAHAN': 'PEMESANAN',
        'REF': id_pesan,
        'SATUAN': 'DUS',
        'WAKTU_CHANGE': waktu_selesai
    })
    
    return id_pesan

def transfer_barang_fifo(tanggal, jumlah_transfer_dus):
    """
    Transfer barang dengan logika FEFO (First Expired First Out)
    
    Args:
        tanggal: Tanggal transfer
        jumlah_transfer_dus: Jumlah DUS yang akan ditransfer dari gudang
    
    Returns:
        transfer_id jika berhasil, None jika gagal
    
    Note:
        - Transfer dalam bentuk DUS dari gudang
        - DUS akan dikonversi menjadi PIECES saat masuk ke toko (DUS * SATUAN_PERDUS)
        - Menggunakan logika FEFO untuk memilih batch yang expired terdekat
    """
    global stock_gudang, stock_toko
    
    if stock_gudang <= 0 or jumlah_transfer_dus <= 0:
        return None
    
    # Sort batch berdasarkan expired date (FEFO)
    sorted_batches = sorted(
        [(id_pesan, batch_stock[id_pesan], batch_expired[id_pesan]) 
         for id_pesan in batch_stock.keys() if batch_stock[id_pesan] > 0],
        key=lambda x: x[2] if x[2] else '9999-12-31'
    )
    
    if not sorted_batches:
        return None
    
    # Alokasi batch dengan FEFO
    sisa_kebutuhan = min(jumlah_transfer_dus, stock_gudang)
    batch_allocation = []  # List of (id_pesan, jumlah_dus)
    
    for id_pesan, sisa_batch, tgl_expired in sorted_batches:
        if sisa_kebutuhan <= 0:
            break
        
        if sisa_batch > 0:
            ambil_dari_batch = min(sisa_batch, sisa_kebutuhan)
            batch_allocation.append((id_pesan, ambil_dari_batch))
            batch_stock[id_pesan] -= ambil_dari_batch
            sisa_kebutuhan -= ambil_dari_batch
    
    if not batch_allocation:
        return None
    
    # Update stock
    stock_gudang_awal = stock_gudang
    jumlah_transfer_actual = sum(j[1] for j in batch_allocation)
    stock_gudang -= jumlah_transfer_actual
    
    jumlah_transfer_pieces = jumlah_transfer_actual * SATUAN_PERDUS
    stock_toko_awal = stock_toko
    stock_toko += jumlah_transfer_pieces
    
    # ID Transfer
    transfer_id = generate_id_transfer(tanggal)
    detail_id = generate_id_detail(tanggal)
    
    # TRANSFER_BARANG
    transfer_data.append({
        'type': 'transfer',
        'ID_TRANSFER_BARANG': transfer_id,
        'ID_USERS_PENGIRIM': USER_GUDANG,
        'ID_USERS_PENERIMA': USER_TOKO,
        'KD_LOKASI_ASAL': KD_LOKASI_GUDANG,
        'KD_LOKASI_TUJUAN': KD_LOKASI_TOKO,
        'WAKTU_PESAN_TRANSFER': (tanggal - timedelta(hours=2)).strftime('%Y-%m-%d %H:%M:%S'),
        'WAKTU_KIRIM_TRANSFER': (tanggal - timedelta(hours=1)).strftime('%Y-%m-%d %H:%M:%S'),
        'WAKTU_SELESAI_TRANSFER': tanggal.strftime('%Y-%m-%d %H:%M:%S'),
        'STATUS': 'SELESAI'
    })
    
    # DETAIL_TRANSFER_BARANG
    transfer_data.append({
        'type': 'detail_transfer',
        'ID_DETAIL_TRANSFER_BARANG': detail_id,
        'ID_TRANSFER_BARANG': transfer_id,
        'KD_BARANG': KD_BARANG,
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
    
    # STOCK_HISTORY - Transfer Keluar (Gudang)
    id_history_g = generate_id_history(tanggal)
    stock_history_data.append({
        'ID_HISTORY_STOCK': id_history_g,
        'KD_BARANG': KD_BARANG,
        'KD_LOKASI': KD_LOKASI_GUDANG,
        'UPDATED_BY': USER_GUDANG,
        'JUMLAH_AWAL': stock_gudang_awal,
        'JUMLAH_PERUBAHAN': -jumlah_transfer_actual,
        'JUMLAH_AKHIR': stock_gudang,
        'TIPE_PERUBAHAN': 'TRANSFER',
        'REF': transfer_id,
        'SATUAN': 'DUS',
        'WAKTU_CHANGE': tanggal.strftime('%Y-%m-%d %H:%M:%S')
    })
    
    # STOCK_HISTORY - Transfer Masuk (Toko)
    id_history_t = generate_id_history(tanggal)
    stock_history_data.append({
        'ID_HISTORY_STOCK': id_history_t,
        'KD_BARANG': KD_BARANG,
        'KD_LOKASI': KD_LOKASI_TOKO,
        'UPDATED_BY': USER_TOKO,
        'JUMLAH_AWAL': stock_toko_awal,
        'JUMLAH_PERUBAHAN': jumlah_transfer_pieces,
        'JUMLAH_AKHIR': stock_toko,
        'TIPE_PERUBAHAN': 'TRANSFER',
        'REF': transfer_id,
        'SATUAN': 'PIECES',
        'WAKTU_CHANGE': tanggal.strftime('%Y-%m-%d %H:%M:%S')
    })
    
    return transfer_id

def penjualan(tanggal, jumlah_jual):
    """Generate penjualan"""
    global stock_toko
    
    if stock_toko < jumlah_jual:
        jumlah_jual = stock_toko
    
    if jumlah_jual <= 0:
        return None
    
    # Update stock
    stock_toko_awal = stock_toko
    stock_toko -= jumlah_jual
    
    # Hitung harga - HARGA_BELI_BARANG dinamis berdasarkan AVG_HARGA_BELI_PIECES saat ini
    harga_beli = calculate_avg_harga_beli()  # AVG_HARGA_BELI_PIECES pada saat penjualan
    harga_jual = HARGA_JUAL_PIECE  # Harga jual tetap (misal 15k atau 30k)
    
    # Hitung total
    total_beli_uang = jumlah_jual * harga_beli
    total_jual_uang = jumlah_jual * harga_jual
    
    # ID
    nota_id = generate_id_nota()
    detail_id = generate_id_detail_nota()
    
    # Waktu transaksi: 08:00 - 20:00
    hour = random.randint(8, 20)
    minute = random.randint(0, 59)
    waktu_nota = tanggal.replace(hour=hour, minute=minute, second=random.randint(0, 59))
    
    # NOTA_JUAL (akan di-update nanti dengan total dari semua detail)
    sales_data.append({
        'type': 'nota',
        'ID_NOTA_JUAL': nota_id,
        'ID_USERS': USER_TOKO,
        'KD_LOKASI': KD_LOKASI_TOKO,
        'WAKTU_NOTA': waktu_nota.strftime('%Y-%m-%d %H:%M:%S'),
        'TOTAL_JUAL_BARANG': jumlah_jual,  # Sementara, akan di-aggregate nanti
        'SUB_TOTAL_JUAL': total_jual_uang,  # Sementara
        'SUB_TOTAL_BELI': total_beli_uang,  # Sementara
        'GROSS_PROFIT': total_jual_uang - total_beli_uang,  # Sementara
        'PAJAK': 0,  # Akan dihitung setelah aggregate
        'GRAND_TOTAL': 0  # Akan dihitung setelah aggregate
    })
    
    # DETAIL_NOTA_JUAL
    sales_data.append({
        'type': 'detail_nota',
        'ID_DNJB': detail_id,
        'KD_BARANG': KD_BARANG,
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
        'KD_BARANG': KD_BARANG,
        'KD_LOKASI': KD_LOKASI_TOKO,
        'UPDATED_BY': USER_TOKO,
        'JUMLAH_AWAL': stock_toko_awal,
        'JUMLAH_PERUBAHAN': -jumlah_jual,
        'JUMLAH_AKHIR': stock_toko,
        'TIPE_PERUBAHAN': 'PENJUALAN',
        'REF': detail_id,
        'SATUAN': 'PIECES',
        'WAKTU_CHANGE': waktu_nota.strftime('%Y-%m-%d %H:%M:%S')
    })
    
    return nota_id

# ===== SIMULASI =====
print("=" * 80)
print("GENERATE DATA DUMMY LENGKAP")
print("=" * 80)
print(f"Periode: {START_DATE.strftime('%Y-%m-%d')} sampai {END_DATE.strftime('%Y-%m-%d')}")
print()

current_date = START_DATE
last_pesan_date = None
last_transfer_date = None

print("Memulai simulasi...")

while current_date <= END_DATE:
    # 1. PESAN BARANG (pemesanan ketika stock gudang mendekati minimal stock)
    # Pemesanan terjadi ketika stock gudang <= MIN_STOCK_GUDANG
    if stock_gudang <= MIN_STOCK_GUDANG:
        # Hitung kebutuhan: target mencapai MAX_STOCK_GUDANG atau minimal 100 DUS lebih dari MIN_STOCK
        kebutuhan_dus = max(MAX_STOCK_GUDANG - stock_gudang, MIN_STOCK_GUDANG + 100 - stock_gudang)
        
        # Minimal pemesanan 50 DUS untuk efisiensi, maksimal 200 DUS
        if kebutuhan_dus >= 50:
            # Batasi maksimal 200 DUS per pemesanan
            jumlah_pesan_dus = min(kebutuhan_dus, 200)
            
            # Simpan stock sebelum pemesanan
            stock_gudang_sebelum = stock_gudang
            
            # Pemesanan dilakukan 2 hari sebelum barang masuk
            pesan_date = current_date - timedelta(days=2)
            pesan_barang_baru(pesan_date, jumlah_pesan_dus)
            last_pesan_date = pesan_date
            
            # Stock sudah di-update di dalam fungsi pesan_barang_baru()
            print(f"  [{current_date.strftime('%Y-%m-%d')}] Pemesanan baru: {jumlah_pesan_dus} DUS, Stock gudang: {stock_gudang_sebelum} -> {stock_gudang} DUS")
    
    # 2. TRANSFER BARANG (resupply ketika stock toko mendekati minimal stock)
    # Transfer terjadi ketika stock toko <= MIN_STOCK_TOKO
    # Transfer dalam bentuk DUS, yang akan dikonversi menjadi PIECES (lebih banyak)
    if stock_gudang > 0:
        # Cek apakah perlu resupply (stock toko <= minimal stock)
        if stock_toko <= MIN_STOCK_TOKO:
            # Hitung kebutuhan: target mencapai MAX_STOCK_TOKO atau minimal 200 pieces lebih dari MIN_STOCK
            kebutuhan_pieces = max(MAX_STOCK_TOKO - stock_toko, MIN_STOCK_TOKO + 200 - stock_toko)
            # Konversi kebutuhan pieces ke DUS (bulatkan ke atas)
            kebutuhan_dus = (kebutuhan_pieces + SATUAN_PERDUS - 1) // SATUAN_PERDUS  # Ceiling division
            
            # Batasi dengan stock gudang yang tersedia
            jumlah_transfer_dus = min(kebutuhan_dus, stock_gudang)
            
            # Minimal transfer 5 DUS untuk efisiensi
            if jumlah_transfer_dus >= 5:
                transfer_id = transfer_barang_fifo(current_date, jumlah_transfer_dus)
                if transfer_id:
                    last_transfer_date = current_date
                    jumlah_transfer_pieces = jumlah_transfer_dus * SATUAN_PERDUS
                    print(f"  [{current_date.strftime('%Y-%m-%d')}] Resupply: {jumlah_transfer_dus} DUS ({jumlah_transfer_pieces} PIECES), Stock toko: {stock_toko} -> {stock_toko + jumlah_transfer_pieces} PIECES")
    
    # 3. PENJUALAN (setiap hari, 2-5 transaksi)
    if stock_toko > 0:
        num_transactions = random.randint(2, 5)
        for _ in range(num_transactions):
            if stock_toko <= 0:
                break
            
            jumlah_jual = random.randint(3, min(30, stock_toko))
            nota_id = penjualan(current_date, jumlah_jual)
    
    # Next day
    current_date += timedelta(days=1)

# Update SISA_STOCK_DUS di pesan_barang_data
for pesan in pesan_barang_data:
    id_pesan = pesan['ID_PESAN_BARANG']
    pesan['SISA_STOCK_DUS'] = batch_stock.get(id_pesan, 0)

# ===== WRITE CSV FILES =====
print()
print("Menulis file CSV...")

# PESAN_BARANG
with open('pesan_barang.csv', 'w', newline='', encoding='utf-8') as f:
    fieldnames = ['ID_PESAN_BARANG', 'KD_LOKASI', 'KD_BARANG', 'ID_PERHITUNGAN_KUANTITAS_POQ', 'KD_SUPPLIER',
                  'HARGA_PESAN_BARANG_DUS', 'BIAYA_PENGIRIMAAN', 'JUMLAH_PESAN_BARANG_DUS', 'JUMLAH_TIBA_DUS',
                  'JUMLAH_DITOLAK_DUS', 'TOTAL_MASUK_DUS', 'SISA_STOCK_DUS', 'TGL_EXPIRED',
                  'WAKTU_PESAN', 'WAKTU_ESTIMASI_SELESAI', 'WAKTU_SELESAI', 'STATUS']
    writer = csv.DictWriter(f, fieldnames=fieldnames)
    writer.writeheader()
    writer.writerows(pesan_barang_data)

# TRANSFER_BARANG
with open('transfer_barang.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['ID_TRANSFER_BARANG', 'ID_USERS_PENGIRIM', 'ID_USERS_PENERIMA', 'KD_LOKASI_ASAL', 'KD_LOKASI_TUJUAN',
                     'WAKTU_PESAN_TRANSFER', 'WAKTU_KIRIM_TRANSFER', 'WAKTU_SELESAI_TRANSFER', 'STATUS'])
    for t in transfer_data:
        if t['type'] == 'transfer':
            writer.writerow([t['ID_TRANSFER_BARANG'], t['ID_USERS_PENGIRIM'], t['ID_USERS_PENERIMA'],
                            t['KD_LOKASI_ASAL'], t['KD_LOKASI_TUJUAN'], t['WAKTU_PESAN_TRANSFER'],
                            t['WAKTU_KIRIM_TRANSFER'], t['WAKTU_SELESAI_TRANSFER'], t['STATUS']])

# DETAIL_TRANSFER_BARANG
with open('detail_transfer_barang.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['ID_DETAIL_TRANSFER_BARANG', 'ID_TRANSFER_BARANG', 'KD_BARANG', 'TOTAL_PESAN_TRANSFER_DUS',
                     'TOTAL_KIRIM_DUS', 'TOTAL_TIBA_DUS', 'TOTAL_DITOLAK_DUS', 'TOTAL_MASUK_DUS', 'STATUS'])
    for t in transfer_data:
        if t['type'] == 'detail_transfer':
            writer.writerow([t['ID_DETAIL_TRANSFER_BARANG'], t['ID_TRANSFER_BARANG'], t['KD_BARANG'],
                            t['TOTAL_PESAN_TRANSFER_DUS'], t['TOTAL_KIRIM_DUS'], t['TOTAL_TIBA_DUS'],
                            t['TOTAL_DITOLAK_DUS'], t['TOTAL_MASUK_DUS'], t['STATUS']])

# DETAIL_TRANSFER_BARANG_BATCH
with open('detail_transfer_barang_batch.csv', 'w', newline='', encoding='utf-8') as f:
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
    nota['PAJAK'] = round(nota['SUB_TOTAL_JUAL'] * 0.11, 2)  # 11% PPN
    nota['GRAND_TOTAL'] = nota['SUB_TOTAL_JUAL'] + nota['PAJAK']

# Write NOTA_JUAL
with open('nota_jual.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['ID_NOTA_JUAL', 'ID_USERS', 'KD_LOKASI', 'WAKTU_NOTA', 'TOTAL_JUAL_BARANG',
                     'SUB_TOTAL_JUAL', 'PAJAK', 'GRAND_TOTAL', 'SUB_TOTAL_BELI', 'GROSS_PROFIT'])
    for nota in sorted(nota_dict.values(), key=lambda x: x['WAKTU_NOTA']):
        writer.writerow([nota['ID_NOTA_JUAL'], nota['ID_USERS'], nota['KD_LOKASI'], nota['WAKTU_NOTA'],
                         nota['TOTAL_JUAL_BARANG'], nota['SUB_TOTAL_JUAL'], nota['PAJAK'],
                         nota['GRAND_TOTAL'], nota['SUB_TOTAL_BELI'], nota['GROSS_PROFIT']])

# DETAIL_NOTA_JUAL
with open('detail_nota_jual.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['ID_DNJB', 'KD_BARANG', 'ID_NOTA_JUAL', 'JUMLAH_JUAL_BARANG', 'HARGA_JUAL_BARANG',
                     'TOTAL_JUAL_UANG', 'HARGA_BELI_BARANG', 'TOTAL_BELI_UANG'])
    for s in sales_data:
        if s['type'] == 'detail_nota':
            writer.writerow([s['ID_DNJB'], s['KD_BARANG'], s['ID_NOTA_JUAL'], s['JUMLAH_JUAL_BARANG'],
                            s['HARGA_JUAL_BARANG'], s['TOTAL_JUAL_UANG'], s['HARGA_BELI_BARANG'],
                            s['TOTAL_BELI_UANG']])

# STOCK_HISTORY
with open('stock_history_all.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['ID_HISTORY_STOCK', 'KD_BARANG', 'KD_LOKASI', 'UPDATED_BY', 'JUMLAH_AWAL', 'JUMLAH_PERUBAHAN',
                     'JUMLAH_AKHIR', 'TIPE_PERUBAHAN', 'REF', 'SATUAN', 'WAKTU_CHANGE'])
    writer.writerows([
        [h['ID_HISTORY_STOCK'], h['KD_BARANG'], h['KD_LOKASI'], h['UPDATED_BY'], h['JUMLAH_AWAL'],
         h['JUMLAH_PERUBAHAN'], h['JUMLAH_AKHIR'], h['TIPE_PERUBAHAN'], h['REF'], h['SATUAN'], h['WAKTU_CHANGE']]
        for h in stock_history_data
    ])

# STOCK
today = datetime.now()
with open('stock.csv', 'w', newline='', encoding='utf-8') as f:
    writer = csv.writer(f)
    writer.writerow(['KD_BARANG', 'KD_LOKASI', 'UPDATED_BY', 'JUMLAH_BARANG', 'JUMLAH_MIN_STOCK', 'JUMLAH_MAX_STOCK',
                     'SATUAN', 'LAST_UPDATED'])
    writer.writerow([KD_BARANG, KD_LOKASI_GUDANG, USER_GUDANG, stock_gudang, MIN_STOCK_GUDANG, MAX_STOCK_GUDANG, 'DUS',
                     today.strftime('%Y-%m-%d %H:%M:%S')])
    writer.writerow([KD_BARANG, KD_LOKASI_TOKO, USER_TOKO, stock_toko, MIN_STOCK_TOKO, MAX_STOCK_TOKO, 'PIECES',
                     today.strftime('%Y-%m-%d %H:%M:%S')])

# ===== UPDATE MASTER_BARANG =====
# Hitung AVG_HARGA_BELI_PIECES
avg_harga_beli = calculate_avg_harga_beli()

# Buat file SQL untuk update MASTER_BARANG
update_master_barang_sql = f"""-- Update AVG_HARGA_BELI_PIECES berdasarkan weighted average dari semua pemesanan
-- Formula: AVG_HARGA_BELI_PIECES = SUM(HARGA_PESAN_BARANG_DUS * TOTAL_MASUK_DUS) / SUM(TOTAL_MASUK_DUS * SATUAN_PERDUS)
UPDATE MASTER_BARANG 
SET AVG_HARGA_BELI_PIECES = {avg_harga_beli:.2f}
WHERE KD_BARANG = '{KD_BARANG}';

-- Verifikasi
SELECT KD_BARANG, NAMA_BARANG, AVG_HARGA_BELI_PIECES, SATUAN_PERDUS
FROM MASTER_BARANG
WHERE KD_BARANG = '{KD_BARANG}';
"""

with open('update_avg_harga_beli.sql', 'w', encoding='utf-8') as f:
    f.write(update_master_barang_sql)

# ===== SUMMARY =====
print()
print("=" * 80)
print("SUMMARY")
print("=" * 80)
print(f"Total Pemesanan: {len(pesan_barang_data)}")
print(f"Total Transfer: {len([t for t in transfer_data if t['type'] == 'transfer'])}")
print(f"Total Penjualan: {len([s for s in sales_data if s['type'] == 'nota'])}")
print(f"Stock Gudang Akhir: {stock_gudang} DUS")
print(f"Stock Toko Akhir: {stock_toko} PIECES")
print(f"Total Stock History: {len(stock_history_data)}")
print()
print(f"AVG_HARGA_BELI_PIECES: Rp {avg_harga_beli:,.2f}")
print(f"  (Dihitung dari {len(purchase_history)} pemesanan)")
print()
print("[OK] Semua file CSV berhasil di-generate!")
print("[OK] File update_avg_harga_beli.sql berhasil dibuat!")
print("=" * 80)

