<?php
session_start();
require_once '../dbconnect.php';
require_once '../includes/uuid_generator.php';

// Cek apakah user sudah login
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
    exit();
}

// Get parameters
$kd_lokasi = isset($_GET['kd_lokasi']) ? trim($_GET['kd_lokasi']) : '';
$type = isset($_GET['type']) ? trim($_GET['type']) : '';

if (empty($kd_lokasi)) {
    header("Location: stock.php");
    exit();
}

// Get lokasi info
$query_lokasi = "SELECT KD_LOKASI, NAMA_LOKASI, ALAMAT_LOKASI, TYPE_LOKASI 
                 FROM MASTER_LOKASI 
                 WHERE KD_LOKASI = ? AND STATUS = 'AKTIF'";
$stmt_lokasi = $conn->prepare($query_lokasi);
$stmt_lokasi->bind_param("s", $kd_lokasi);
$stmt_lokasi->execute();
$result_lokasi = $stmt_lokasi->get_result();

if ($result_lokasi->num_rows == 0) {
    header("Location: stock.php");
    exit();
}

$lokasi = $result_lokasi->fetch_assoc();

// Handle AJAX request untuk get stock data
if (isset($_GET['get_stock_data']) && $_GET['get_stock_data'] == '1') {
    $kd_barang_ajax = isset($_GET['kd_barang']) ? trim($_GET['kd_barang']) : '';
    $kd_lokasi_ajax = isset($_GET['kd_lokasi']) ? trim($_GET['kd_lokasi']) : '';
    
    if (!empty($kd_barang_ajax) && !empty($kd_lokasi_ajax)) {
        // Query untuk mendapatkan data barang lengkap
        $query_barang_ajax = "SELECT 
            mb.KD_BARANG,
            mb.NAMA_BARANG,
            mb.BERAT,
            mb.STATUS as STATUS_BARANG,
            COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
            COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI
        FROM MASTER_BARANG mb
        LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
        LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
        WHERE mb.KD_BARANG = ?";
        $stmt_barang_ajax = $conn->prepare($query_barang_ajax);
        $stmt_barang_ajax->bind_param("s", $kd_barang_ajax);
        $stmt_barang_ajax->execute();
        $result_barang_ajax = $stmt_barang_ajax->get_result();
        
        // Query untuk mendapatkan stock data
        $query_stock_ajax = "SELECT JUMLAH_MAX_STOCK, JUMLAH_BARANG 
                            FROM STOCK 
                            WHERE KD_BARANG = ? AND KD_LOKASI = ?";
        $stmt_stock_ajax = $conn->prepare($query_stock_ajax);
        $stmt_stock_ajax->bind_param("ss", $kd_barang_ajax, $kd_lokasi_ajax);
        $stmt_stock_ajax->execute();
        $result_stock_ajax = $stmt_stock_ajax->get_result();
        
        // Query untuk mendapatkan supplier terakhir dengan data lengkap
        $query_supplier_last = "SELECT pb.KD_SUPPLIER, 
                                       COALESCE(ms.KD_SUPPLIER, '-') as SUPPLIER_KD,
                                       COALESCE(ms.NAMA_SUPPLIER, '-') as NAMA_SUPPLIER,
                                       COALESCE(ms.ALAMAT_SUPPLIER, '-') as ALAMAT_SUPPLIER
                               FROM PESAN_BARANG pb
                               LEFT JOIN MASTER_SUPPLIER ms ON pb.KD_SUPPLIER = ms.KD_SUPPLIER
                               WHERE pb.KD_BARANG = ? AND pb.KD_LOKASI = ? AND pb.KD_SUPPLIER IS NOT NULL 
                               ORDER BY pb.WAKTU_PESAN DESC 
                               LIMIT 1";
        $stmt_supplier_last = $conn->prepare($query_supplier_last);
        $stmt_supplier_last->bind_param("ss", $kd_barang_ajax, $kd_lokasi_ajax);
        $stmt_supplier_last->execute();
        $result_supplier_last = $stmt_supplier_last->get_result();
        $last_supplier_data = $result_supplier_last->num_rows > 0 ? $result_supplier_last->fetch_assoc() : null;
        $last_supplier = $last_supplier_data ? $last_supplier_data['KD_SUPPLIER'] : null;
        
        if ($result_stock_ajax->num_rows > 0 && $result_barang_ajax->num_rows > 0) {
            $stock_data = $result_stock_ajax->fetch_assoc();
            $barang_data = $result_barang_ajax->fetch_assoc();
            header('Content-Type: application/json');
            
            echo json_encode([
                'success' => true,
                'kd_barang' => $barang_data['KD_BARANG'] ?? '',
                'nama_barang' => $barang_data['NAMA_BARANG'] ?? '',
                'merek_barang' => $barang_data['NAMA_MEREK'] ?? '-',
                'kategori_barang' => $barang_data['NAMA_KATEGORI'] ?? '-',
                'berat_barang' => $barang_data['BERAT'] ?? 0,
                'status_barang' => ($barang_data['STATUS_BARANG'] ?? '') == 'AKTIF' ? 'Aktif' : 'Tidak Aktif',
                'stock_max' => $stock_data['JUMLAH_MAX_STOCK'] ?? 0,
                'stock_sekarang' => $stock_data['JUMLAH_BARANG'] ?? 0,
                'last_supplier' => $last_supplier ?? null
            ]);
            exit();
        } else {
            header('Content-Type: application/json');
            $error_msg = 'Data tidak ditemukan. ';
            if ($result_stock_ajax->num_rows == 0) {
                $error_msg .= 'Stock tidak ditemukan. ';
            }
            if ($result_barang_ajax->num_rows == 0) {
                $error_msg .= 'Barang tidak ditemukan.';
            }
            echo json_encode(['success' => false, 'message' => $error_msg]);
            exit();
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
    exit();
}

// Handle AJAX request untuk get data POQ dan hitung otomatis
if (isset($_GET['get_poq_data']) && $_GET['get_poq_data'] == '1') {
    $kd_barang_ajax = isset($_GET['kd_barang']) ? trim($_GET['kd_barang']) : '';
    $kd_lokasi_ajax = isset($_GET['kd_lokasi']) ? trim($_GET['kd_lokasi']) : '';
    
    if (!empty($kd_barang_ajax) && !empty($kd_lokasi_ajax)) {
        try {
            // Query untuk mendapatkan data barang lengkap
            $query_barang_ajax = "SELECT 
                mb.KD_BARANG,
                mb.NAMA_BARANG,
                mb.BERAT,
                mb.STATUS as STATUS_BARANG,
                mb.SATUAN_PERDUS,
                mb.AVG_HARGA_BELI_PIECES,
                COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
                COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI
            FROM MASTER_BARANG mb
            LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
            LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
            WHERE mb.KD_BARANG = ?";
            $stmt_barang_ajax = $conn->prepare($query_barang_ajax);
            $stmt_barang_ajax->bind_param("s", $kd_barang_ajax);
            $stmt_barang_ajax->execute();
            $result_barang_ajax = $stmt_barang_ajax->get_result();
            
            // Query untuk mendapatkan stock data
            $query_stock_ajax = "SELECT JUMLAH_BARANG 
                                FROM STOCK 
                                WHERE KD_BARANG = ? AND KD_LOKASI = ?";
            $stmt_stock_ajax = $conn->prepare($query_stock_ajax);
            $stmt_stock_ajax->bind_param("ss", $kd_barang_ajax, $kd_lokasi_ajax);
            $stmt_stock_ajax->execute();
            $result_stock_ajax = $stmt_stock_ajax->get_result();
            
            if ($result_stock_ajax->num_rows == 0 || $result_barang_ajax->num_rows == 0) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
                exit();
            }
            
            $stock_data = $result_stock_ajax->fetch_assoc();
            $barang_data = $result_barang_ajax->fetch_assoc();
            $stock_sekarang = intval($stock_data['JUMLAH_BARANG']);
            $satuan_perdus = intval($barang_data['SATUAN_PERDUS'] ?? 1);
            
            // 1. Hitung DEMAND RATE (D) dalam DUS per hari dari penjualan SEMUA TOKO setahun terakhir
            // Konversi dari penjualan toko: JUMLAH_JUAL_BARANG (pieces) ÷ SATUAN_PERDUS = dus
            $query_demand = "SELECT 
                COALESCE(SUM(dnj.JUMLAH_JUAL_BARANG), 0) as TOTAL_PIECES
            FROM DETAIL_NOTA_JUAL dnj
            INNER JOIN NOTA_JUAL nj ON dnj.ID_NOTA_JUAL = nj.ID_NOTA_JUAL
            INNER JOIN MASTER_LOKASI ml ON nj.KD_LOKASI = ml.KD_LOKASI
            WHERE dnj.KD_BARANG = ? 
            AND ml.TYPE_LOKASI = 'toko'  -- Penjualan dari SEMUA TOKO
            AND nj.WAKTU_NOTA >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
            $stmt_demand = $conn->prepare($query_demand);
            $stmt_demand->bind_param("s", $kd_barang_ajax);
            $stmt_demand->execute();
            $result_demand = $stmt_demand->get_result();
            $demand_data = $result_demand->fetch_assoc();
            $total_pieces = intval($demand_data['TOTAL_PIECES'] ?? 0);
            
            // Konversi pieces ke dus: TOTAL_PIECES ÷ SATUAN_PERDUS
            $total_dus_terjual = $satuan_perdus > 0 ? ($total_pieces / $satuan_perdus) : 0;
            
            // Demand rate dalam DUS per hari (untuk 1 tahun = 365 hari)
            // TIDAK ADA DEFAULT - harus dari data hitungan
            if ($total_dus_terjual <= 0) {
                throw new Exception('Tidak ada data penjualan untuk menghitung demand rate');
            }
            $demand_rate = $total_dus_terjual / 365;
            
            // 2. Hitung SETUP COST (S) - biaya tetap setiap kali pemesanan
            // Ambil rata-rata BIAYA_PENGIRIMAAN dari PESAN_BARANG (biaya admin + bongkar muat)
            // Filter 1 tahun terakhir untuk konsistensi dengan logika Rolling 1 Year
            $query_setup = "SELECT 
                COALESCE(AVG(pb.BIAYA_PENGIRIMAAN), 0) as AVG_BIAYA_PENGIRIMAAN
            FROM PESAN_BARANG pb
            WHERE pb.KD_BARANG = ? 
            AND pb.KD_LOKASI = ? 
            AND pb.BIAYA_PENGIRIMAAN > 0
            AND pb.WAKTU_PESAN >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
            $stmt_setup = $conn->prepare($query_setup);
            $stmt_setup->bind_param("ss", $kd_barang_ajax, $kd_lokasi_ajax);
            $stmt_setup->execute();
            $result_setup = $stmt_setup->get_result();
            $setup_data = $result_setup->fetch_assoc();
            $setup_cost = floatval($setup_data['AVG_BIAYA_PENGIRIMAAN'] ?? 0);
            
            // TIDAK ADA DEFAULT - harus dari data hitungan
            if ($setup_cost <= 0) {
                throw new Exception('Tidak ada data BIAYA_PENGIRIMAAN untuk menghitung setup cost');
            }
            
            // 3. Hitung HOLDING COST (H) - Rp per DUS per hari (CARA PALING AKURAT)
            // a. Hitung total biaya operasional gudang 1 tahun terakhir
            $query_biaya_gudang = "SELECT 
                SUM(CASE 
                    WHEN bo.PERIODE = 'HARIAN' THEN bo.JUMLAH_BIAYA_UANG * 365
                    WHEN bo.PERIODE = 'BULANAN' THEN bo.JUMLAH_BIAYA_UANG * 12
                    WHEN bo.PERIODE = 'TAHUNAN' THEN bo.JUMLAH_BIAYA_UANG
                    ELSE 0
                END) as TOTAL_BIAYA_GUDANG_TAHUN
            FROM BIAYA_OPERASIONAL bo
            WHERE bo.KD_LOKASI = ?
            AND bo.LAST_UPDATED >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
            $stmt_biaya_gudang = $conn->prepare($query_biaya_gudang);
            $stmt_biaya_gudang->bind_param("s", $kd_lokasi_ajax);
            $stmt_biaya_gudang->execute();
            $result_biaya_gudang = $stmt_biaya_gudang->get_result();
            $biaya_gudang_data = $result_biaya_gudang->fetch_assoc();
            $total_biaya_gudang_tahun = floatval($biaya_gudang_data['TOTAL_BIAYA_GUDANG_TAHUN'] ?? 0);
            
            // b. Hitung TOTAL rata-rata jumlah dus yang tersimpan di gudang selama 1 tahun
            // UNTUK SEMUA BARANG (bukan hanya barang ini)
            // Ambil stok akhir setiap hari dari STOCK_HISTORY (untuk gudang, SATUAN = 'DUS', semua barang)
            $query_total_avg_stok_dus = "SELECT 
                AVG(sh.JUMLAH_AKHIR) as TOTAL_AVG_STOK_DUS
            FROM STOCK_HISTORY sh
            WHERE sh.KD_LOKASI = ?
            AND sh.SATUAN = 'DUS'
            AND sh.WAKTU_CHANGE >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
            AND sh.JUMLAH_AKHIR >= 0";
            $stmt_total_avg_stok = $conn->prepare($query_total_avg_stok_dus);
            $stmt_total_avg_stok->bind_param("s", $kd_lokasi_ajax);
            $stmt_total_avg_stok->execute();
            $result_total_avg_stok = $stmt_total_avg_stok->get_result();
            $total_avg_stok_data = $result_total_avg_stok->fetch_assoc();
            $total_avg_stok_dus = floatval($total_avg_stok_data['TOTAL_AVG_STOK_DUS'] ?? 0);
            
            // c. H per dus per tahun = Total biaya gudang ÷ Total rata-rata stok dus (SEMUA BARANG)
            // d. H per dus per hari = H per tahun ÷ 365
            // TIDAK ADA DEFAULT - harus dari data hitungan
            if ($total_avg_stok_dus <= 0 || $total_biaya_gudang_tahun <= 0) {
                throw new Exception('Tidak ada data biaya operasional gudang atau stok history untuk menghitung holding cost');
            }
            $holding_cost_per_dus_per_tahun = $total_biaya_gudang_tahun / $total_avg_stok_dus;
            $holding_cost = $holding_cost_per_dus_per_tahun / 365;
            
            // 4. Hitung LEAD TIME (rata-rata waktu pengiriman dari supplier)
            // Filter 1 tahun terakhir untuk konsistensi dengan logika Rolling 1 Year
            $query_lead_time = "SELECT 
                AVG(DATEDIFF(pb.WAKTU_SELESAI, pb.WAKTU_PESAN)) as AVG_LEAD_TIME
            FROM PESAN_BARANG pb
            WHERE pb.KD_BARANG = ? 
            AND pb.KD_LOKASI = ?
            AND pb.STATUS = 'SELESAI'
            AND pb.WAKTU_PESAN IS NOT NULL
            AND pb.WAKTU_SELESAI IS NOT NULL
            AND DATEDIFF(pb.WAKTU_SELESAI, pb.WAKTU_PESAN) > 0
            AND pb.WAKTU_PESAN >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
            $stmt_lead_time = $conn->prepare($query_lead_time);
            $stmt_lead_time->bind_param("ss", $kd_barang_ajax, $kd_lokasi_ajax);
            $stmt_lead_time->execute();
            $result_lead_time = $stmt_lead_time->get_result();
            $lead_time_data = $result_lead_time->fetch_assoc();
            $avg_lead_time = floatval($lead_time_data['AVG_LEAD_TIME'] ?? 0);
            
            // TIDAK ADA DEFAULT - harus dari data hitungan
            if ($avg_lead_time <= 0) {
                throw new Exception('Tidak ada data lead time dari PESAN_BARANG yang sudah selesai');
            }
            $lead_time = round($avg_lead_time);
            
            // 5. Cek apakah interval POQ sudah ada
            $query_interval_poq = "SELECT INTERVAL_HARI
                                   FROM PERHITUNGAN_INTERVAL_POQ
                                   WHERE KD_BARANG = ? AND KD_LOKASI = ?
                                   ORDER BY WAKTU_PERHITUNGAN_INTERVAL_POQ DESC
                                   LIMIT 1";
            $stmt_interval_poq = $conn->prepare($query_interval_poq);
            $stmt_interval_poq->bind_param("ss", $kd_barang_ajax, $kd_lokasi_ajax);
            $stmt_interval_poq->execute();
            $result_interval_poq = $stmt_interval_poq->get_result();
            $interval_data = $result_interval_poq->num_rows > 0 ? $result_interval_poq->fetch_assoc() : null;
            $has_interval = $interval_data !== null;
            $existing_interval = $interval_data ? intval($interval_data['INTERVAL_HARI']) : null;
            
            // 6. Hitung INTERVAL POQ Optimal (T*) dalam hari
            // Jika interval sudah ada, gunakan yang ada, jika belum hitung baru
            $interval_hari_raw = null;
            if ($has_interval && $existing_interval > 0) {
                $interval_hari = $existing_interval;
                $interval_hari_raw = $existing_interval; // Jika sudah ada, raw = rounded
            } else {
                // Rumus: T* = √(2 × S / (D × H))
                if ($demand_rate > 0 && $holding_cost > 0) {
                    $interval_hari_raw = sqrt((2 * $setup_cost) / ($demand_rate * $holding_cost));
                    $interval_hari = ceil($interval_hari_raw); // Round up ke hari terdekat
                    // Minimum 1 hari
                    if ($interval_hari < 1) {
                        $interval_hari = 1;
                    }
                } else {
                    $interval_hari_raw = 1;
                    $interval_hari = 1;
                }
            }
            
            // 7. Hitung KUANTITAS POQ (Q*) yang harus dipesan saat ini
            // Q* = (D × T*) + (D × LeadTime) - Stok_Sekarang_dus
            $kuantitas_poq_dus_raw = ($demand_rate * $interval_hari) + ($demand_rate * $lead_time) - $stock_sekarang;
            
            // Hitung nilai yang sudah di-round up (untuk disimpan)
            $kuantitas_poq_dus_rounded = ceil($kuantitas_poq_dus_raw);
            
            // 8. Query untuk mendapatkan supplier terakhir dengan data lengkap
            $query_supplier_last = "SELECT pb.KD_SUPPLIER, 
                                       COALESCE(ms.KD_SUPPLIER, '-') as SUPPLIER_KD,
                                       COALESCE(ms.NAMA_SUPPLIER, '-') as NAMA_SUPPLIER,
                                       COALESCE(ms.ALAMAT_SUPPLIER, '-') as ALAMAT_SUPPLIER
                               FROM PESAN_BARANG pb
                               LEFT JOIN MASTER_SUPPLIER ms ON pb.KD_SUPPLIER = ms.KD_SUPPLIER
                               WHERE pb.KD_BARANG = ? AND pb.KD_LOKASI = ? AND pb.KD_SUPPLIER IS NOT NULL 
                               ORDER BY pb.WAKTU_PESAN DESC 
                               LIMIT 1";
            $stmt_supplier_last = $conn->prepare($query_supplier_last);
            $stmt_supplier_last->bind_param("ss", $kd_barang_ajax, $kd_lokasi_ajax);
            $stmt_supplier_last->execute();
            $result_supplier_last = $stmt_supplier_last->get_result();
            $last_supplier_data = $result_supplier_last->num_rows > 0 ? $result_supplier_last->fetch_assoc() : null;
            $last_supplier = $last_supplier_data ? $last_supplier_data['KD_SUPPLIER'] : null;
            
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'kd_barang' => $barang_data['KD_BARANG'] ?? '',
                'nama_barang' => $barang_data['NAMA_BARANG'] ?? '',
                'merek_barang' => $barang_data['NAMA_MEREK'] ?? '-',
                'kategori_barang' => $barang_data['NAMA_KATEGORI'] ?? '-',
                'berat_barang' => $barang_data['BERAT'] ?? 0,
                'status_barang' => ($barang_data['STATUS_BARANG'] ?? '') == 'AKTIF' ? 'Aktif' : 'Tidak Aktif',
                'stock_sekarang' => $stock_sekarang,
                'demand_rate' => $demand_rate,
                'setup_cost' => $setup_cost,
                'holding_cost' => $holding_cost,
                'lead_time' => $lead_time,
                'interval_hari_raw' => $interval_hari_raw, // Nilai real (bisa desimal)
                'interval_hari' => $interval_hari, // Nilai yang sudah di-round up (untuk disimpan)
                'kuantitas_poq_raw' => $kuantitas_poq_dus_raw, // Nilai real (bisa negatif)
                'kuantitas_poq' => $kuantitas_poq_dus_rounded, // Nilai yang sudah di-round up (untuk disimpan)
                'has_interval' => $has_interval,
                'total_dus_year' => $total_dus_terjual,
                'total_biaya_gudang_tahun' => $total_biaya_gudang_tahun,
                'total_avg_stok_dus' => $total_avg_stok_dus,
                'last_supplier' => $last_supplier ?? null
            ]);
            exit();
        } catch (Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            exit();
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Parameter tidak lengkap']);
    exit();
}


// Handle AJAX request untuk simpan dan pesan POQ
if (isset($_POST['action']) && $_POST['action'] == 'simpan_dan_pesan_poq') {
    header('Content-Type: application/json');
    
    $kd_barang = isset($_POST['kd_barang']) ? trim($_POST['kd_barang']) : '';
    $kd_lokasi = isset($_POST['kd_lokasi']) ? trim($_POST['kd_lokasi']) : '';
    $kd_supplier = isset($_POST['kd_supplier']) ? trim($_POST['kd_supplier']) : '';
    
    if (empty($kd_barang) || empty($kd_lokasi) || empty($kd_supplier)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
        exit();
    }
    
    try {
        // Ambil semua data yang sudah dihitung dari get_poq_data
        // Query untuk mendapatkan data barang
        $query_barang = "SELECT SATUAN_PERDUS FROM MASTER_BARANG WHERE KD_BARANG = ?";
        $stmt_barang = $conn->prepare($query_barang);
        $stmt_barang->bind_param("s", $kd_barang);
        $stmt_barang->execute();
        $result_barang = $stmt_barang->get_result();
        if ($result_barang->num_rows == 0) {
            throw new Exception('Barang tidak ditemukan');
        }
        $barang_data = $result_barang->fetch_assoc();
        $satuan_perdus = intval($barang_data['SATUAN_PERDUS'] ?? 1);
        
        // Query untuk mendapatkan stock
        $query_stock = "SELECT JUMLAH_BARANG FROM STOCK WHERE KD_BARANG = ? AND KD_LOKASI = ?";
        $stmt_stock = $conn->prepare($query_stock);
        $stmt_stock->bind_param("ss", $kd_barang, $kd_lokasi);
        $stmt_stock->execute();
        $result_stock = $stmt_stock->get_result();
        if ($result_stock->num_rows == 0) {
            throw new Exception('Stock tidak ditemukan');
        }
        $stock_data = $result_stock->fetch_assoc();
        $stock_sekarang = intval($stock_data['JUMLAH_BARANG']);
        
        // Hitung ulang semua variabel POQ (sama seperti di get_poq_data)
        // 1. Demand Rate (D) dalam DUS per hari dari SEMUA TOKO
        $query_demand = "SELECT COALESCE(SUM(dnj.JUMLAH_JUAL_BARANG), 0) as TOTAL_PIECES
                        FROM DETAIL_NOTA_JUAL dnj
                        INNER JOIN NOTA_JUAL nj ON dnj.ID_NOTA_JUAL = nj.ID_NOTA_JUAL
                        INNER JOIN MASTER_LOKASI ml ON nj.KD_LOKASI = ml.KD_LOKASI
                        WHERE dnj.KD_BARANG = ? 
                        AND ml.TYPE_LOKASI = 'toko'
                        AND nj.WAKTU_NOTA >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        $stmt_demand = $conn->prepare($query_demand);
        $stmt_demand->bind_param("s", $kd_barang);
        $stmt_demand->execute();
        $result_demand = $stmt_demand->get_result();
        $demand_data = $result_demand->fetch_assoc();
        $total_pieces = intval($demand_data['TOTAL_PIECES'] ?? 0);
        $total_dus_terjual = $satuan_perdus > 0 ? ($total_pieces / $satuan_perdus) : 0;
        
        // TIDAK ADA DEFAULT - harus dari data hitungan
        if ($total_dus_terjual <= 0) {
            throw new Exception('Tidak ada data penjualan untuk menghitung demand rate');
        }
        $demand_rate = $total_dus_terjual / 365;
        
        // 2. Setup Cost (S) - biaya tetap pemesanan
        // Filter 1 tahun terakhir untuk konsistensi dengan logika Rolling 1 Year
        $query_setup = "SELECT COALESCE(AVG(pb.BIAYA_PENGIRIMAAN), 0) as AVG_BIAYA
                       FROM PESAN_BARANG pb
                       WHERE pb.KD_BARANG = ? 
                       AND pb.KD_LOKASI = ? 
                       AND pb.BIAYA_PENGIRIMAAN > 0
                       AND pb.WAKTU_PESAN >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        $stmt_setup = $conn->prepare($query_setup);
        $stmt_setup->bind_param("ss", $kd_barang, $kd_lokasi);
        $stmt_setup->execute();
        $result_setup = $stmt_setup->get_result();
        $setup_data = $result_setup->fetch_assoc();
        $setup_cost = floatval($setup_data['AVG_BIAYA'] ?? 0);
        
        // TIDAK ADA DEFAULT - harus dari data hitungan
        if ($setup_cost <= 0) {
            throw new Exception('Tidak ada data BIAYA_PENGIRIMAAN untuk menghitung setup cost');
        }
        
        // 3. Holding Cost (H) - Rp per DUS per hari
        // a. Total biaya operasional gudang 1 tahun
        $query_biaya_gudang = "SELECT 
            SUM(CASE 
                WHEN bo.PERIODE = 'HARIAN' THEN bo.JUMLAH_BIAYA_UANG * 365
                WHEN bo.PERIODE = 'BULANAN' THEN bo.JUMLAH_BIAYA_UANG * 12
                WHEN bo.PERIODE = 'TAHUNAN' THEN bo.JUMLAH_BIAYA_UANG
                ELSE 0
            END) as TOTAL_BIAYA_GUDANG_TAHUN
        FROM BIAYA_OPERASIONAL bo
        WHERE bo.KD_LOKASI = ?
        AND bo.LAST_UPDATED >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        $stmt_biaya_gudang = $conn->prepare($query_biaya_gudang);
        $stmt_biaya_gudang->bind_param("s", $kd_lokasi);
        $stmt_biaya_gudang->execute();
        $result_biaya_gudang = $stmt_biaya_gudang->get_result();
        $biaya_gudang_data = $result_biaya_gudang->fetch_assoc();
        $total_biaya_gudang_tahun = floatval($biaya_gudang_data['TOTAL_BIAYA_GUDANG_TAHUN'] ?? 0);
        
        // b. Hitung TOTAL rata-rata jumlah dus yang tersimpan di gudang selama 1 tahun
        // UNTUK SEMUA BARANG (bukan hanya barang ini)
        // Ambil stok akhir setiap hari dari STOCK_HISTORY (untuk gudang, SATUAN = 'DUS', semua barang)
        $query_total_avg_stok_dus = "SELECT 
            AVG(sh.JUMLAH_AKHIR) as TOTAL_AVG_STOK_DUS
        FROM STOCK_HISTORY sh
        WHERE sh.KD_LOKASI = ?
        AND sh.SATUAN = 'DUS'
        AND sh.WAKTU_CHANGE >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
        AND sh.JUMLAH_AKHIR >= 0";
        $stmt_total_avg_stok = $conn->prepare($query_total_avg_stok_dus);
        $stmt_total_avg_stok->bind_param("s", $kd_lokasi);
        $stmt_total_avg_stok->execute();
        $result_total_avg_stok = $stmt_total_avg_stok->get_result();
        $total_avg_stok_data = $result_total_avg_stok->fetch_assoc();
        $total_avg_stok_dus = floatval($total_avg_stok_data['TOTAL_AVG_STOK_DUS'] ?? 0);
        
        // c. H per dus per tahun = Total biaya gudang ÷ Total rata-rata stok dus (SEMUA BARANG)
        // d. H per dus per hari = H per tahun ÷ 365
        // TIDAK ADA DEFAULT - harus dari data hitungan
        if ($total_avg_stok_dus <= 0 || $total_biaya_gudang_tahun <= 0) {
            throw new Exception('Tidak ada data biaya operasional gudang atau stok history untuk menghitung holding cost');
        }
        $holding_cost_per_dus_per_tahun = $total_biaya_gudang_tahun / $total_avg_stok_dus;
        $holding_cost = $holding_cost_per_dus_per_tahun / 365;
        
        // 4. Lead Time
        // Filter 1 tahun terakhir untuk konsistensi dengan logika Rolling 1 Year
        $query_lead_time = "SELECT AVG(DATEDIFF(pb.WAKTU_SELESAI, pb.WAKTU_PESAN)) as AVG_LEAD_TIME
                           FROM PESAN_BARANG pb
                           WHERE pb.KD_BARANG = ? 
                           AND pb.KD_LOKASI = ?
                           AND pb.STATUS = 'SELESAI'
                           AND pb.WAKTU_PESAN IS NOT NULL 
                           AND pb.WAKTU_SELESAI IS NOT NULL
                           AND DATEDIFF(pb.WAKTU_SELESAI, pb.WAKTU_PESAN) > 0
                           AND pb.WAKTU_PESAN >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)";
        $stmt_lead_time = $conn->prepare($query_lead_time);
        $stmt_lead_time->bind_param("ss", $kd_barang, $kd_lokasi);
        $stmt_lead_time->execute();
        $result_lead_time = $stmt_lead_time->get_result();
        $lead_time_data = $result_lead_time->fetch_assoc();
        $avg_lead_time = floatval($lead_time_data['AVG_LEAD_TIME'] ?? 0);
        
        // TIDAK ADA DEFAULT - harus dari data hitungan
        if ($avg_lead_time <= 0) {
            throw new Exception('Tidak ada data lead time dari PESAN_BARANG yang sudah selesai');
        }
        $lead_time = round($avg_lead_time);
        
        // 5. Cek interval POQ
        $query_interval_check = "SELECT INTERVAL_HARI FROM PERHITUNGAN_INTERVAL_POQ
                                WHERE KD_BARANG = ? AND KD_LOKASI = ?
                                ORDER BY WAKTU_PERHITUNGAN_INTERVAL_POQ DESC LIMIT 1";
        $stmt_interval_check = $conn->prepare($query_interval_check);
        $stmt_interval_check->bind_param("ss", $kd_barang, $kd_lokasi);
        $stmt_interval_check->execute();
        $result_interval_check = $stmt_interval_check->get_result();
        $interval_check_data = $result_interval_check->num_rows > 0 ? $result_interval_check->fetch_assoc() : null;
        $has_interval = $interval_check_data !== null;
        $existing_interval = $interval_check_data ? intval($interval_check_data['INTERVAL_HARI']) : null;
        
        // 6. Hitung Interval
        if ($has_interval && $existing_interval > 0) {
            $interval_hari = $existing_interval;
        } else {
            if ($demand_rate > 0 && $holding_cost > 0) {
                $interval_hari = sqrt((2 * $setup_cost) / ($demand_rate * $holding_cost));
                $interval_hari = ceil($interval_hari); // Round up ke hari terdekat
                if ($interval_hari < 1) {
                    $interval_hari = 1;
                }
            } else {
                $interval_hari = 1;
            }
        }
        
        // 7. Hitung Kuantitas POQ (Q*) dalam dus
        // Q* = (D × T*) + (D × LeadTime) - Stok_Sekarang_dus
        $kuantitas_poq_raw = ($demand_rate * $interval_hari) + ($demand_rate * $lead_time) - $stock_sekarang;
        
        // Simpan nilai raw untuk tracking (bisa negatif di database)
        // Tapi untuk pemesanan, jika negatif berarti stock sudah lebih dari cukup
        if ($kuantitas_poq_raw < 0) {
            throw new Exception('Stock saat ini sudah lebih dari cukup. Tidak perlu melakukan pemesanan. Kuantitas POQ: ' . number_format($kuantitas_poq_raw, 2) . ' dus');
        }
        
        // Dibulatkan ke atas (CEIL) ke dus utuh
        $kuantitas_poq = ceil($kuantitas_poq_raw);
        
        if ($interval_hari <= 0) {
            throw new Exception('Gagal menghitung interval POQ');
        }
        
        // Cek apakah interval sudah ada
        $id_interval_poq = null;
        $use_existing_interval = $has_interval;
        
        if ($use_existing_interval && $existing_interval > 0) {
            $query_check_interval = "SELECT ID_PERHITUNGAN_INTERVAL_POQ FROM PERHITUNGAN_INTERVAL_POQ 
                                    WHERE KD_BARANG = ? AND KD_LOKASI = ?
                                    ORDER BY WAKTU_PERHITUNGAN_INTERVAL_POQ DESC LIMIT 1";
            $stmt_check_interval = $conn->prepare($query_check_interval);
            $stmt_check_interval->bind_param("ss", $kd_barang, $kd_lokasi);
            $stmt_check_interval->execute();
            $result_check_interval = $stmt_check_interval->get_result();
            
            if ($result_check_interval->num_rows > 0) {
                $interval_row = $result_check_interval->fetch_assoc();
                $id_interval_poq = $interval_row['ID_PERHITUNGAN_INTERVAL_POQ'];
            }
        }
        
        // Mulai transaction
        $conn->begin_transaction();
        
        // Jika interval belum ada, buat baru
        if ($id_interval_poq === null) {
            $maxAttempts = 100;
            $attempt = 0;
            do {
                $uuid = ShortIdGenerator::generate(12, '');
                $id_interval_poq = 'IPOQ' . $uuid;
                $attempt++;
                if (!checkUUIDExists($conn, 'PERHITUNGAN_INTERVAL_POQ', 'ID_PERHITUNGAN_INTERVAL_POQ', $id_interval_poq)) {
                    break;
                }
            } while ($attempt < $maxAttempts);
            
            if ($attempt >= $maxAttempts) {
                throw new Exception('Gagal generate ID interval POQ');
            }
            
            $insert_interval = "INSERT INTO PERHITUNGAN_INTERVAL_POQ 
                              (ID_PERHITUNGAN_INTERVAL_POQ, KD_LOKASI, KD_BARANG, DEMAND_RATE, SETUP_COST, HOLDING_COST, INTERVAL_HARI)
                              VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt_interval = $conn->prepare($insert_interval);
            $stmt_interval->bind_param("sssiddi", $id_interval_poq, $kd_lokasi, $kd_barang, $demand_rate, $setup_cost, $holding_cost, $interval_hari);
            
            if (!$stmt_interval->execute()) {
                throw new Exception('Gagal insert interval POQ: ' . $stmt_interval->error);
            }
        }
        
        // Insert kuantitas POQ
        $maxAttempts = 100;
        $attempt = 0;
        do {
            $uuid = ShortIdGenerator::generate(12, '');
            $id_kuantitas_poq = 'KPOQ' . $uuid;
            $attempt++;
            if (!checkUUIDExists($conn, 'PERHITUNGAN_KUANTITAS_POQ', 'ID_PERHITUNGAN_KUANTITAS_POQ', $id_kuantitas_poq)) {
                break;
            }
        } while ($attempt < $maxAttempts);
        
        if ($attempt >= $maxAttempts) {
            throw new Exception('Gagal generate ID kuantitas POQ');
        }
        
        $insert_kuantitas = "INSERT INTO PERHITUNGAN_KUANTITAS_POQ 
                            (ID_PERHITUNGAN_KUANTITAS_POQ, ID_PERHITUNGAN_INTERVAL_POQ, KD_LOKASI, KD_BARANG, 
                             INTERVAL_HARI, DEMAND_RATE, LEAD_TIME, STOCK_SEKARANG, KUANTITAS_POQ)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_kuantitas = $conn->prepare($insert_kuantitas);
        $stmt_kuantitas->bind_param("ssssiiiii", $id_kuantitas_poq, $id_interval_poq, $kd_lokasi, $kd_barang, 
                                    $interval_hari, $demand_rate, $lead_time, $stock_sekarang, $kuantitas_poq);
        
        if (!$stmt_kuantitas->execute()) {
            throw new Exception('Gagal insert kuantitas POQ: ' . $stmt_kuantitas->error);
        }
        
        // Insert pesanan barang
        $maxAttempts = 100;
        $attempt = 0;
        do {
            $uuid = ShortIdGenerator::generate(12, '');
            $id_pesan_barang = 'PSBG' . $uuid;
            $attempt++;
            if (!checkUUIDExists($conn, 'PESAN_BARANG', 'ID_PESAN_BARANG', $id_pesan_barang)) {
                break;
            }
        } while ($attempt < $maxAttempts);
        
        if ($attempt >= $maxAttempts) {
            throw new Exception('Gagal generate ID pesan barang');
        }
        
        $status = 'DIPESAN';
        $insert_pesan = "INSERT INTO PESAN_BARANG 
                        (ID_PESAN_BARANG, KD_LOKASI, KD_BARANG, ID_PERHITUNGAN_KUANTITAS_POQ, KD_SUPPLIER, 
                         JUMLAH_PESAN_BARANG_DUS, STATUS)
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt_pesan = $conn->prepare($insert_pesan);
        $stmt_pesan->bind_param("sssssis", $id_pesan_barang, $kd_lokasi, $kd_barang, $id_kuantitas_poq, 
                               $kd_supplier, $kuantitas_poq, $status);
        
        if (!$stmt_pesan->execute()) {
            throw new Exception('Gagal insert pesan barang: ' . $stmt_pesan->error);
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'POQ berhasil disimpan dan pesanan dibuat',
            'id_pesan_barang' => $id_pesan_barang
        ]);
        exit();
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit();
    }
}

// Handle form submission untuk update min/max stock
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'update_stock_setting') {
        $kd_barang = trim($_POST['kd_barang']);
        $jumlah_max_stock = intval($_POST['jumlah_max_stock']);
        $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
        
        if (!empty($kd_barang) && $jumlah_max_stock >= 0) {
            // Update max stock
            $update_query = "UPDATE STOCK SET JUMLAH_MAX_STOCK = ?, UPDATED_BY = ? WHERE KD_BARANG = ? AND KD_LOKASI = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("isss", $jumlah_max_stock, $user_id, $kd_barang, $kd_lokasi);
            
            if ($update_stmt->execute()) {
                $message = 'Setting stock berhasil diperbarui';
                $message_type = 'success';
                // Redirect untuk mencegah resubmission
                header("Location: stock_detail_gudang.php?kd_lokasi=" . urlencode($kd_lokasi) . "&success=1");
                exit();
            } else {
                $message = 'Gagal memperbarui setting stock!';
                $message_type = 'danger';
            }
            $update_stmt->close();
        } else {
            $message = 'Data tidak valid! Stock Max harus >= 0.';
            $message_type = 'danger';
        }
    } elseif ($_POST['action'] == 'pesan_manual') {
        $kd_barang = trim($_POST['kd_barang']);
        $kd_lokasi = trim($_POST['kd_lokasi']);
        $kd_supplier = trim($_POST['kd_supplier']);
        $jumlah_dipesan = intval($_POST['jumlah_dipesan']);
        
        if (!empty($kd_barang) && !empty($kd_lokasi) && !empty($kd_supplier) && $jumlah_dipesan > 0) {
            // Generate ID_PESAN_BARANG UUID (16 karakter, tanpa prefix)
            // Generate ID_PESAN_BARANG dengan format PSBG+UUID (total 16 karakter: PSBG=4, UUID=12)
            $maxAttempts = 100;
            $attempt = 0;
            do {
                $uuid = ShortIdGenerator::generate(12, '');
                $id_pesan_barang = 'PSBG' . $uuid;
                $attempt++;
                if (!checkUUIDExists($conn, 'PESAN_BARANG', 'ID_PESAN_BARANG', $id_pesan_barang)) {
                    break; // UUID unique, keluar dari loop
                }
            } while ($attempt < $maxAttempts);
            
            if ($attempt >= $maxAttempts) {
                $message = 'Gagal generate ID pesan barang! Silakan coba lagi.';
                $message_type = 'danger';
            } else {
                // Insert data ke tabel PESAN_BARANG
                $status = 'DIPESAN';
                $insert_query = "INSERT INTO PESAN_BARANG (ID_PESAN_BARANG, KD_LOKASI, KD_BARANG, KD_SUPPLIER, JUMLAH_PESAN_BARANG_DUS, STATUS) VALUES (?, ?, ?, ?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bind_param("ssssis", $id_pesan_barang, $kd_lokasi, $kd_barang, $kd_supplier, $jumlah_dipesan, $status);
                
                if ($insert_stmt->execute()) {
                    $message = 'Pesanan manual berhasil dibuat dengan ID: ' . $id_pesan_barang;
                    $message_type = 'success';
                    // Redirect untuk mencegah resubmission
                    header("Location: stock_detail_gudang.php?kd_lokasi=" . urlencode($kd_lokasi) . "&success=2");
                    exit();
                } else {
                    $message = 'Gagal membuat pesanan manual!';
                    $message_type = 'danger';
                }
                $insert_stmt->close();
            }
        } else {
            $message = 'Semua field wajib harus diisi dan jumlah dipesan harus > 0!';
            $message_type = 'danger';
        }
    }
}

// Handle success message dari redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $message = 'Setting stock berhasil diperbarui';
    $message_type = 'success';
} elseif (isset($_GET['success']) && $_GET['success'] == '2') {
    $message = 'Pesanan manual berhasil dibuat';
    $message_type = 'success';
}

// Query untuk mendapatkan data supplier (untuk dropdown pesan manual)
$query_supplier = "SELECT KD_SUPPLIER, NAMA_SUPPLIER, ALAMAT_SUPPLIER 
                   FROM MASTER_SUPPLIER 
                   WHERE STATUS = 'AKTIF'
                   ORDER BY NAMA_SUPPLIER ASC";
$result_supplier = $conn->query($query_supplier);

// Query untuk mendapatkan data stock dengan informasi lengkap
$query_stock = "SELECT 
    s.KD_BARANG,
    mb.NAMA_BARANG,
    mb.BERAT,
    mb.STATUS as STATUS_BARANG,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
    s.JUMLAH_BARANG as STOCK_SEKARANG,
    s.JUMLAH_MIN_STOCK,
    s.JUMLAH_MAX_STOCK,
    s.SATUAN,
    s.LAST_UPDATED,
    COALESCE(
        DATE_FORMAT(DATE_ADD(poq.WAKTU_PERHITUNGAN_KUANTITAS_POQ, INTERVAL poq.INTERVAL_HARI DAY), '%Y-%m-%d'),
        NULL
    ) as JATUH_TEMPO_POQ,
    COALESCE(
        DATE_FORMAT(poq.WAKTU_PERHITUNGAN_KUANTITAS_POQ, '%Y-%m-%d %H:%i:%s'),
        NULL
    ) as WAKTU_TERAKHIR_POQ,
    CASE 
        WHEN COALESCE(DATE_ADD(poq.WAKTU_PERHITUNGAN_KUANTITAS_POQ, INTERVAL poq.INTERVAL_HARI DAY), '9999-12-31') <= CURDATE() THEN 1
        WHEN COALESCE(DATE_ADD(poq.WAKTU_PERHITUNGAN_KUANTITAS_POQ, INTERVAL poq.INTERVAL_HARI DAY), '9999-12-31') <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 2
        ELSE 3
    END as PRIORITAS_JATUH_TEMPO,
    COALESCE(pb_terakhir.ID_PERHITUNGAN_KUANTITAS_POQ, NULL) as ID_POQ_TERAKHIR,
    COALESCE(pb_terakhir.ID_PESAN_BARANG, NULL) as ID_PESAN_BARANG_TERAKHIR
FROM STOCK s
INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
LEFT JOIN (
    SELECT 
        poq1.KD_BARANG,
        poq1.KD_LOKASI,
        poq1.WAKTU_PERHITUNGAN_KUANTITAS_POQ,
        poq1.INTERVAL_HARI,
        poq1.ID_PERHITUNGAN_KUANTITAS_POQ
    FROM PERHITUNGAN_KUANTITAS_POQ poq1
    INNER JOIN (
        SELECT KD_BARANG, KD_LOKASI, MAX(WAKTU_PERHITUNGAN_KUANTITAS_POQ) as MAX_WAKTU
        FROM PERHITUNGAN_KUANTITAS_POQ
        WHERE KD_LOKASI = ?
        GROUP BY KD_BARANG, KD_LOKASI
    ) poq2 ON poq1.KD_BARANG = poq2.KD_BARANG 
        AND poq1.KD_LOKASI = poq2.KD_LOKASI 
        AND poq1.WAKTU_PERHITUNGAN_KUANTITAS_POQ = poq2.MAX_WAKTU
) poq ON s.KD_BARANG = poq.KD_BARANG AND s.KD_LOKASI = poq.KD_LOKASI
LEFT JOIN (
    SELECT 
        pb1.KD_BARANG,
        pb1.KD_LOKASI,
        pb1.ID_PERHITUNGAN_KUANTITAS_POQ,
        pb1.ID_PESAN_BARANG
    FROM PESAN_BARANG pb1
    INNER JOIN (
        SELECT KD_BARANG, KD_LOKASI, MAX(WAKTU_PESAN) as MAX_WAKTU_PESAN
        FROM PESAN_BARANG
        WHERE KD_LOKASI = ?
        GROUP BY KD_BARANG, KD_LOKASI
    ) pb2 ON pb1.KD_BARANG = pb2.KD_BARANG 
        AND pb1.KD_LOKASI = pb2.KD_LOKASI 
        AND pb1.WAKTU_PESAN = pb2.MAX_WAKTU_PESAN
) pb_terakhir ON s.KD_BARANG = pb_terakhir.KD_BARANG AND s.KD_LOKASI = pb_terakhir.KD_LOKASI
WHERE s.KD_LOKASI = ?
ORDER BY 
    PRIORITAS_JATUH_TEMPO ASC,
    COALESCE(DATE_ADD(poq.WAKTU_PERHITUNGAN_KUANTITAS_POQ, INTERVAL poq.INTERVAL_HARI DAY), '9999-12-31') ASC,
    s.JUMLAH_BARANG ASC";

$stmt_stock = $conn->prepare($query_stock);
if ($stmt_stock === false) {
    // Log error untuk debugging
    error_log("SQL Error: " . $conn->error);
    error_log("Query: " . $query_stock);
    $message = 'Error mempersiapkan query: ' . htmlspecialchars($conn->error);
    $message_type = 'danger';
    $result_stock = null;
} else {
$stmt_stock->bind_param("sss", $kd_lokasi, $kd_lokasi, $kd_lokasi);
    if (!$stmt_stock->execute()) {
        error_log("Execute Error: " . $stmt_stock->error);
        $message = 'Error menjalankan query: ' . htmlspecialchars($stmt_stock->error);
        $message_type = 'danger';
        $result_stock = null;
    } else {
$result_stock = $stmt_stock->get_result();
    }
}

// Format tanggal (dd/mm/yyyy)
function formatTanggal($tanggal) {
    if (empty($tanggal) || $tanggal == null) {
        return '-';
    }
    $date = new DateTime($tanggal);
    return $date->format('d/m/Y');
}

// Format tanggal dan waktu (dd/mm/yyyy HH:ii WIB)
function formatTanggalWaktu($tanggal) {
    if (empty($tanggal) || $tanggal == null) {
        return '-';
    }
    $date = new DateTime($tanggal);
    return $date->format('d/m/Y H:i') . ' WIB';
}

// Set active page untuk sidebar
$active_page = 'stock';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Stock <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="dashboard-body">
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Pemilik - Stock <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></h1>
            <p class="text-muted mb-0"><?php echo htmlspecialchars($lokasi['ALAMAT_LOKASI']); ?></p>
        </div>

        <!-- Action Button -->
        <div class="mb-3">
            <button type="button" class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalSettingStock">
                Setting Stock <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?>
            </button>
        </div>

        <!-- Table Stock -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tableStock" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Kode Barang</th>
                            <th>Merek Barang</th>
                            <th>Kategori Barang</th>
                            <th>Nama Barang</th>
                            <th>Berat (gr)</th>
                            <th>Stock Max</th>
                            <th>Stock Sekarang</th>
                            <th>Satuan</th>
                            <th>Jatuh Tempo POQ</th>
                            <th>Waktu Terakhir POQ</th>
                            <th>Terakhir Update</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_stock && $result_stock->num_rows > 0): ?>
                            <?php while ($row = $result_stock->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['KD_BARANG']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_MEREK']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_BARANG']); ?></td>
                                    <td><?php echo number_format($row['BERAT'], 0, ',', '.'); ?></td>
                                    <td><?php echo number_format($row['JUMLAH_MAX_STOCK'], 0, ',', '.'); ?></td>
                                    <td><?php echo number_format($row['STOCK_SEKARANG'], 0, ',', '.'); ?></td>
                                    <td><?php echo htmlspecialchars($row['SATUAN']); ?></td>
                                    <td><?php echo formatTanggal($row['JATUH_TEMPO_POQ']); ?></td>
                                    <td><?php echo formatTanggal($row['WAKTU_TERAKHIR_POQ']); ?></td>
                                    <td><?php echo formatTanggalWaktu($row['LAST_UPDATED']); ?></td>
                                    <td>
                                        <?php if ($row['STATUS_BARANG'] == 'AKTIF'): ?>
                                            <span class="badge bg-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Tidak Aktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column gap-1">
                                            <button class="btn-view btn-sm" onclick="lihatRiwayatPembelian('<?php echo htmlspecialchars($row['KD_BARANG']); ?>')">Lihat Riwayat Pembelian</button>
                                            <button class="btn-view btn-sm" onclick="lihatExpired('<?php echo htmlspecialchars($row['KD_BARANG']); ?>', '<?php echo htmlspecialchars($kd_lokasi); ?>')">Lihat Expired</button>
                                            <?php if ($row['STATUS_BARANG'] == 'AKTIF'): ?>
                                            <button class="btn-view btn-sm" onclick="hitungPOQ('<?php echo htmlspecialchars($row['KD_BARANG']); ?>', '<?php echo htmlspecialchars($kd_lokasi); ?>')">Hitung POQ</button>
                                            <button class="btn-view btn-sm" onclick="pesanManual('<?php echo htmlspecialchars($row['KD_BARANG']); ?>', '<?php echo htmlspecialchars($kd_lokasi); ?>')">Pesan Manual</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Setting Stock -->
    <div class="modal fade" id="modalSettingStock" tabindex="-1" aria-labelledby="modalSettingStockLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalSettingStockLabel">Setting Stock <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formSettingStock" method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="update_stock_setting">
                        <input type="hidden" name="kd_barang" id="setting_kd_barang">
                        
                        <div class="mb-3">
                            <label for="setting_pilih_barang" class="form-label">Pilih Barang <span class="text-danger">*</span></label>
                            <select class="form-select" id="setting_pilih_barang" name="pilih_barang" required>
                                <option value="">-- Pilih Barang --</option>
                                <?php 
                                // Reset result pointer
                                $result_stock->data_seek(0);
                                if ($result_stock->num_rows > 0): ?>
                                    <?php while ($row = $result_stock->fetch_assoc()): ?>
                                        <?php 
                                        // Hanya tampilkan barang aktif di dropdown setting stock
                                        if ($row['STATUS_BARANG'] == 'AKTIF'): 
                                        ?>
                                            <option value="<?php echo htmlspecialchars($row['KD_BARANG']); ?>" 
                                                    data-max-stock="<?php echo $row['JUMLAH_MAX_STOCK']; ?>">
                                                <?php 
                                                $display_text = $row['KD_BARANG'] . '-' . $row['NAMA_MEREK'] . '-' . $row['NAMA_KATEGORI'] . '-' . $row['NAMA_BARANG'] . '-' . number_format($row['BERAT'], 0, ',', '.');
                                                echo htmlspecialchars($display_text);
                                                ?>
                                            </option>
                                        <?php endif; ?>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="setting_stock_max" class="form-label">Stock Max <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="setting_stock_max" name="jumlah_max_stock" placeholder="0" min="0" required>
                            <small class="text-muted">Maximum stock yang dapat disimpan.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn-primary-custom" id="btnSimpanSetting">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Hitung POQ -->
    <div class="modal fade" id="modalHitungPOQ" tabindex="-1" aria-labelledby="modalHitungPOQLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalHitungPOQLabel">HITUNG POQ</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Informasi Barang -->
                    <div class="row mb-3">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Kode Barang</label>
                            <input type="text" class="form-control form-control-sm" id="poq_kd_barang_display" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Merek Barang</label>
                            <input type="text" class="form-control form-control-sm" id="poq_merek_barang" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Kategori Barang</label>
                            <input type="text" class="form-control form-control-sm" id="poq_kategori_barang" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Berat Barang (gr)</label>
                            <input type="text" class="form-control form-control-sm" id="poq_berat_barang" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Nama Barang</label>
                            <input type="text" class="form-control form-control-sm" id="poq_nama_barang" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Status Barang</label>
                            <input type="text" class="form-control form-control-sm" id="poq_status_barang" readonly style="background-color: #e9ecef;">
                        </div>
                    </div>
                    
                    <hr>
                    
                    <!-- Informasi Perhitungan POQ (Read-only) -->
                    <h6 class="mb-3">Data Perhitungan POQ (Rolling 1 Tahun)</h6>
                    <div class="row mb-3">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Demand Rate (dus/hari)</label>
                            <input type="text" class="form-control form-control-sm" id="poq_permintaan" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Setup Cost (Biaya Administrasi Pemesanan)</label>
                            <input type="text" class="form-control form-control-sm" id="poq_biaya_administrasi" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Holding Cost (Rp/dus/hari)</label>
                            <input type="text" class="form-control form-control-sm" id="poq_biaya_holding" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Lead Time</label>
                            <input type="text" class="form-control form-control-sm" id="poq_lead_time" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Stock Sekarang (dus)</label>
                            <input type="text" class="form-control form-control-sm" id="poq_stock_sekarang" readonly style="background-color: #e9ecef;">
                        </div>
                    </div>
                    
                    <hr>
                    
                    <!-- Hasil Perhitungan POQ -->
                    <h6 class="mb-3">Hasil Perhitungan POQ</h6>
                    <div class="row mb-3">
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Periode POQ (hari)</label>
                            <input type="text" class="form-control form-control-sm" id="poq_periode" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="form-label fw-bold">Jumlah Pemesanan POQ (dus)</label>
                            <input type="text" class="form-control form-control-sm" id="poq_kuantitas" readonly style="background-color: #e9ecef;">
                        </div>
                    </div>
                    
                    <!-- Supplier untuk pesanan -->
                    <div class="mb-3">
                        <label for="poq_supplier" class="form-label">Supplier <span class="text-danger">*</span></label>
                        <select class="form-select form-select-sm" id="poq_supplier" required>
                            <option value="">-- Pilih Supplier --</option>
                            <?php 
                            if ($result_supplier && $result_supplier->num_rows > 0) {
                                $result_supplier->data_seek(0);
                                while ($supplier = $result_supplier->fetch_assoc()): 
                                    $alamat_display = !empty($supplier['ALAMAT_SUPPLIER']) ? ' - ' . htmlspecialchars($supplier['ALAMAT_SUPPLIER']) : '';
                                ?>
                                    <option value="<?php echo htmlspecialchars($supplier['KD_SUPPLIER']); ?>">
                                        <?php echo htmlspecialchars($supplier['KD_SUPPLIER'] . ' - ' . $supplier['NAMA_SUPPLIER'] . $alamat_display); ?>
                                    </option>
                                <?php endwhile;
                            } ?>
                        </select>
                    </div>
                    
                    <!-- Hidden fields -->
                    <input type="hidden" id="poq_kd_barang">
                    <input type="hidden" id="poq_kd_lokasi">
                    <input type="hidden" id="poq_use_existing_interval" value="0">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn-primary-custom" id="btnSimpanDanPesanPOQ">Simpan dan Pesan</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Pesan Manual -->
    <div class="modal fade" id="modalPesanManual" tabindex="-1" aria-labelledby="modalPesanManualLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalPesanManualLabel">Pesan Manual</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formPesanManual" method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="pesan_manual">
                        <input type="hidden" name="kd_barang" id="pesan_kd_barang">
                        <input type="hidden" name="kd_lokasi" id="pesan_kd_lokasi">
                        
                        <!-- Item Details Section -->
                        <div class="row mb-2">
                            <div class="col-md-6 mb-2">
                                <label class="form-label fw-bold">Kode Barang</label>
                                <input type="text" class="form-control form-control-sm" id="pesan_kd_barang_display" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label fw-bold">Merek Barang</label>
                                <input type="text" class="form-control form-control-sm" id="pesan_merek_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label fw-bold">Kategori Barang</label>
                                <input type="text" class="form-control form-control-sm" id="pesan_kategori_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label fw-bold">Berat Barang (gr)</label>
                                <input type="text" class="form-control form-control-sm" id="pesan_berat_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label fw-bold">Nama Barang</label>
                                <input type="text" class="form-control form-control-sm" id="pesan_nama_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="form-label fw-bold">Status Barang</label>
                                <input type="text" class="form-control form-control-sm" id="pesan_status_barang" readonly style="background-color: #e9ecef;">
                            </div>
                        </div>
                        
                        <div class="mb-2">
                            <label for="pesan_supplier" class="form-label">Supplier <span class="text-danger">*</span></label>
                            <select class="form-select form-select-sm" id="pesan_supplier" name="kd_supplier" required>
                                <option value="">-- Pilih Supplier --</option>
                                <?php 
                                // Reset result pointer untuk supplier
                                if ($result_supplier && $result_supplier->num_rows > 0) {
                                    $result_supplier->data_seek(0);
                                    while ($supplier = $result_supplier->fetch_assoc()): 
                                        $alamat_display = !empty($supplier['ALAMAT_SUPPLIER']) ? ' - ' . htmlspecialchars($supplier['ALAMAT_SUPPLIER']) : '';
                                    ?>
                                        <option value="<?php echo htmlspecialchars($supplier['KD_SUPPLIER']); ?>" 
                                                data-alamat="<?php echo htmlspecialchars($supplier['ALAMAT_SUPPLIER'] ?? ''); ?>">
                                            <?php echo htmlspecialchars($supplier['KD_SUPPLIER'] . ' - ' . $supplier['NAMA_SUPPLIER'] . $alamat_display); ?>
                                        </option>
                                    <?php endwhile;
                                } ?>
                            </select>
                        </div>
                        
                        <div class="row mb-2">
                            <div class="col-md-6 mb-2">
                                <label for="pesan_stock_max" class="form-label">Stock maksimal (dus)</label>
                                <input type="number" class="form-control form-control-sm" id="pesan_stock_max" readonly style="background-color: #e9ecef;" disabled>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label for="pesan_stock_sekarang" class="form-label">Stock Saat Ini (dus)</label>
                                <input type="number" class="form-control form-control-sm" id="pesan_stock_sekarang" readonly style="background-color: #e9ecef;" disabled>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label for="pesan_stock_dipesan" class="form-label">Stock yg dipesan (dus) <span class="text-danger">*</span></label>
                                <div class="input-group input-group-sm">
                                    <input type="number" class="form-control" id="pesan_stock_dipesan" name="jumlah_dipesan" placeholder="0" min="0" required>
                                    <button type="button" class="btn btn-outline-secondary" onclick="setMaxStock()">Max</button>
                                </div>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label for="pesan_stock_setelah_dipesan" class="form-label">Stock Setelah Dipesan (dus)</label>
                                <input type="number" class="form-control form-control-sm" id="pesan_stock_setelah_dipesan" readonly style="background-color: #e9ecef;" disabled>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn-primary-custom" id="btnSimpanPesan">Simpan dan Pesan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- jQuery (required for DataTables) -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <!-- Sidebar Script -->
    <script src="includes/sidebar.js"></script>
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- DataTables Initialization -->
    <script>
        $(document).ready(function() {
            // Show success/error message dengan SweetAlert
            <?php if (!empty($message)): ?>
                <?php if ($message_type == 'success'): ?>
                    Swal.fire({
                        icon: 'success',
                        title: 'Berhasil!',
                        text: '<?php echo htmlspecialchars($message, ENT_QUOTES); ?>',
                        confirmButtonColor: '#667eea',
                        timer: 2000,
                        timerProgressBar: true
                    });
                <?php else: ?>
                    Swal.fire({
                        icon: 'error',
                        title: 'Gagal!',
                        text: '<?php echo htmlspecialchars($message, ENT_QUOTES); ?>',
                        confirmButtonColor: '#e74c3c'
                    });
                <?php endif; ?>
            <?php endif; ?>
            
            // Disable DataTables error reporting
            $.fn.dataTable.ext.errMode = 'none';
            
            $('#tableStock').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data stock'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[3, 'asc']], // Sort by Nama Barang
                columnDefs: [
                    { orderable: false, targets: 12 } // Disable sorting on Action column
                ],
                scrollX: true, // Enable horizontal scrolling
                responsive: true,
                drawCallback: function(settings) {
                    // Suppress any errors
                    if (settings.aoData.length === 0) {
                        return;
                    }
                }
            }).on('error.dt', function(e, settings, techNote, message) {
                // Suppress error messages
                console.log('DataTables error suppressed:', message);
                return false;
            });
            
            // Handle perubahan pilihan barang di modal setting stock
            $('#setting_pilih_barang').on('change', function() {
                var selectedOption = $(this).find('option:selected');
                var kdBarang = selectedOption.val();
                var maxStock = selectedOption.data('max-stock') || 0;
                
                $('#setting_kd_barang').val(kdBarang);
                $('#setting_stock_max').val(maxStock);
            });
            
            // Reset form saat modal ditutup
            $('#modalSettingStock').on('hidden.bs.modal', function() {
                $('#formSettingStock')[0].reset();
                $('#setting_kd_barang').val('');
                $('#setting_stock_max').val('');
                $('#setting_pilih_barang').val('').trigger('change');
            });
            
            // Handle perubahan stock dipesan untuk menghitung stock setelah dipesan
            $('#pesan_stock_dipesan').on('input change', function() {
                calculateStockAfterOrder();
            });
            
            // Reset form saat modal ditutup
            $('#modalPesanManual').on('hidden.bs.modal', function() {
                // Reset supplier dropdown format
                $('#pesan_supplier option').each(function() {
                    var optionText = $(this).text();
                    if (optionText.includes('(Pesan Terakhir) - ')) {
                        $(this).text(optionText.replace('(Pesan Terakhir) - ', ''));
                    }
                });
                $('#pesan_supplier').val('');
                $('#pesan_kd_barang_display').val('');
                $('#pesan_merek_barang').val('');
                $('#pesan_kategori_barang').val('');
                $('#pesan_berat_barang').val('');
                $('#pesan_nama_barang').val('');
                $('#pesan_status_barang').val('');
            });
        });
        
        // Flag untuk mencegah multiple submission
        var isSubmittingSetting = false;
        
        // Form validation dan prevent multiple submission - Setting Stock
        $('#formSettingStock').on('submit', function(e) {
            if (isSubmittingSetting) {
                e.preventDefault();
                return false;
            }
            
            var kdBarang = $('#setting_kd_barang').val();
            var maxStock = parseInt($('#setting_stock_max').val()) || 0;
            
            if (!kdBarang) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Pilih barang terlebih dahulu!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#setting_pilih_barang').focus();
                });
                return false;
            }
            
            if (maxStock < 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Stock Max harus >= 0!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#setting_stock_max').focus();
                });
                return false;
            }
            
            // Konfirmasi dengan SweetAlert
            e.preventDefault();
            Swal.fire({
                title: 'Konfirmasi',
                text: 'Apakah Anda yakin ingin menyimpan setting stock?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Ya, Simpan',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    isSubmittingSetting = true;
                    $('#btnSimpanSetting').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...');
                    $('#formSettingStock')[0].submit();
                }
            });
        });
        
        // Reset flag saat modal ditutup
        $('#modalSettingStock').on('hidden.bs.modal', function() {
            isSubmittingSetting = false;
            $('#btnSimpanSetting').prop('disabled', false).html('Simpan');
        });

        function lihatRiwayatPembelian(kdBarang) {
            // Redirect ke halaman riwayat pembelian
            if (!kdBarang || kdBarang.trim() === '') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Kode barang tidak valid!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }
            // Gunakan path relatif dari folder pemilik
            var url = 'riwayat_pembelian.php?kd_barang=' + encodeURIComponent(kdBarang);
            window.location.href = url;
        }

        function lihatExpired(kdBarang, kdLokasi) {
            // Redirect ke halaman lihat expired
            if (!kdBarang || kdBarang.trim() === '') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Kode barang tidak valid!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }
            var url = 'lihat_expired.php?kd_barang=' + encodeURIComponent(kdBarang) + '&kd_lokasi=' + encodeURIComponent(kdLokasi);
            window.location.href = url;
        }

        function hitungPOQ(kdBarang, kdLokasi) {
            // Ambil data barang dan hitung POQ otomatis
            $.ajax({
                url: '',
                method: 'GET',
                data: {
                    get_poq_data: '1',
                    kd_barang: kdBarang,
                    kd_lokasi: kdLokasi
                },
                dataType: 'json',
                beforeSend: function() {
                    // Show loading
                    Swal.fire({
                        title: 'Menghitung POQ...',
                        text: 'Mohon tunggu, sedang menghitung data dari 1 tahun terakhir',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                },
                success: function(response) {
                    Swal.close();
                    
                    if (response.success) {
                        // Set hidden fields
                        $('#poq_kd_barang').val(kdBarang);
                        $('#poq_kd_lokasi').val(kdLokasi);
                        $('#poq_use_existing_interval').val(response.has_interval ? '1' : '0');
                        
                        // Set item details (read-only)
                        $('#poq_kd_barang_display').val(response.kd_barang || '');
                        $('#poq_merek_barang').val(response.merek_barang || '-');
                        $('#poq_kategori_barang').val(response.kategori_barang || '-');
                        var beratFormatted = response.berat_barang ? parseInt(response.berat_barang).toLocaleString('id-ID') : '';
                        $('#poq_berat_barang').val(beratFormatted);
                        $('#poq_nama_barang').val(response.nama_barang || '');
                        $('#poq_status_barang').val(response.status_barang || '');
                        
                        // Set data perhitungan (read-only)
                        // Demand rate sudah dalam DUS per hari
                        var demandRateDus = response.demand_rate || 0;
                        $('#poq_permintaan').val(demandRateDus.toLocaleString('id-ID', {minimumFractionDigits: 4, maximumFractionDigits: 4}) + ' dus/hari');
                        $('#poq_biaya_administrasi').val('Rp. ' + (response.setup_cost ? parseFloat(response.setup_cost).toLocaleString('id-ID') : '0'));
                        $('#poq_biaya_holding').val('Rp. ' + (response.holding_cost ? parseFloat(response.holding_cost).toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '/dus/hari' : '0'));
                        $('#poq_lead_time').val((response.lead_time || 0) + ' hari');
                        $('#poq_stock_sekarang').val((response.stock_sekarang || 0).toLocaleString('id-ID') + ' dus');
                        
                        // Tampilkan periode POQ: nilai real dan nilai yang sudah di-round up
                        var intervalRaw = parseFloat(response.interval_hari_raw || response.interval_hari || 0);
                        var intervalRounded = parseFloat(response.interval_hari || 0);
                        var intervalRawFormatted = intervalRaw.toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        var intervalRoundedFormatted = intervalRounded.toLocaleString('id-ID', {minimumFractionDigits: 0, maximumFractionDigits: 0});
                        
                        if (intervalRaw != intervalRounded) {
                            // Jika berbeda, tampilkan kedua nilai
                            $('#poq_periode').val(intervalRawFormatted + ' hari (real) → ' + intervalRoundedFormatted + ' hari (rounded up)');
                        } else {
                            // Jika sama (sudah bulat), tampilkan satu nilai saja
                            $('#poq_periode').val(intervalRoundedFormatted + ' hari');
                        }
                        
                        // Tampilkan kuantitas POQ: nilai real dan nilai yang sudah di-round up
                        var kuantitasPoqRaw = parseFloat(response.kuantitas_poq_raw || 0);
                        var kuantitasPoqRounded = parseFloat(response.kuantitas_poq || 0);
                        var kuantitasRawFormatted = kuantitasPoqRaw.toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                        var kuantitasRoundedFormatted = kuantitasPoqRounded.toLocaleString('id-ID', {minimumFractionDigits: 0, maximumFractionDigits: 0});
                        
                        if (kuantitasPoqRaw < 0) {
                            $('#poq_kuantitas').val(kuantitasRawFormatted + ' dus (real) → ' + kuantitasRoundedFormatted + ' dus (rounded) - Stock lebih dari cukup');
                            $('#poq_kuantitas').css('color', '#dc3545'); // Warna merah untuk negatif
                        } else if (kuantitasPoqRaw != kuantitasPoqRounded) {
                            // Jika berbeda, tampilkan kedua nilai
                            $('#poq_kuantitas').val(kuantitasRawFormatted + ' dus (real) → ' + kuantitasRoundedFormatted + ' dus (rounded up)');
                            $('#poq_kuantitas').css('color', '#000'); // Warna hitam
                        } else {
                            // Jika sama (sudah bulat), tampilkan satu nilai saja
                            $('#poq_kuantitas').val(kuantitasRoundedFormatted + ' dus');
                            $('#poq_kuantitas').css('color', '#000'); // Warna hitam
                        }
                        
                        // Set supplier terakhir jika ada dan update format display
                        var lastSupplierKd = response.last_supplier || null;
                        
                        // Update format semua option supplier
                        $('#poq_supplier option').each(function() {
                            var optionValue = $(this).val();
                            var originalText = $(this).text();
                            
                            // Hapus prefix "(Pesan Terakhir) - " jika ada
                            if (originalText.includes('(Pesan Terakhir) - ')) {
                                originalText = originalText.replace('(Pesan Terakhir) - ', '');
                            }
                            
                            // Jika ini supplier terakhir, tambahkan prefix
                            if (optionValue && optionValue === lastSupplierKd) {
                                $(this).text('(Pesan Terakhir) - ' + originalText);
                            } else {
                                $(this).text(originalText);
                            }
                        });
                        
                        // Auto-select supplier terakhir jika ada
                        if (lastSupplierKd) {
                            $('#poq_supplier').val(lastSupplierKd);
                        } else {
                            $('#poq_supplier').val('');
                        }
                        
                        $('#modalHitungPOQ').modal('show');
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: response.message || 'Gagal mengambil data POQ!',
                            confirmButtonColor: '#e74c3c'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    Swal.close();
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Terjadi kesalahan saat mengambil data!',
                        confirmButtonColor: '#e74c3c'
                    });
                }
            });
        }
        
        // Handle tombol Simpan dan Pesan
        $('#btnSimpanDanPesanPOQ').on('click', function() {
            var kdBarang = $('#poq_kd_barang').val();
            var kdLokasi = $('#poq_kd_lokasi').val();
            var kdSupplier = $('#poq_supplier').val();
            
            if (!kdBarang || !kdLokasi) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Data barang tidak lengkap!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }
            
            if (!kdSupplier) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Pilih supplier terlebih dahulu!',
                    confirmButtonColor: '#667eea'
                });
                $('#poq_supplier').focus();
                return;
            }
            
            // Validasi kuantitas POQ tidak boleh negatif
            // Ambil nilai dari response (yang sudah di-round up)
            var kuantitasPoqText = $('#poq_kuantitas').val();
            // Extract nilai rounded (setelah tanda panah jika ada)
            var kuantitasPoqMatch = kuantitasPoqText.match(/→\s*(\d+[\.,]?\d*)\s*dus/);
            if (!kuantitasPoqMatch) {
                // Jika tidak ada tanda panah, ambil nilai pertama
                kuantitasPoqMatch = kuantitasPoqText.match(/(-?\d+[\.,]?\d*)/);
            }
            if (kuantitasPoqMatch) {
                var kuantitasPoq = parseFloat((kuantitasPoqMatch[1] || kuantitasPoqMatch[0]).replace(',', '.'));
                if (kuantitasPoq < 0 || kuantitasPoqText.includes('Stock lebih dari cukup')) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Tidak Dapat Memesan!',
                        html: 'Stock saat ini sudah lebih dari cukup.<br>Kuantitas POQ: <strong>' + kuantitasPoqText + '</strong><br><br>Tidak perlu melakukan pemesanan.',
                        confirmButtonColor: '#667eea'
                    });
                    return;
                }
            }
            
            // Konfirmasi
            Swal.fire({
                title: 'Konfirmasi',
                text: 'Apakah Anda yakin ingin menyimpan POQ dan membuat pesanan?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Ya, Simpan dan Pesan',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    $('#btnSimpanDanPesanPOQ').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...');
                    
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: {
                            action: 'simpan_dan_pesan_poq',
                            kd_barang: kdBarang,
                            kd_lokasi: kdLokasi,
                            kd_supplier: kdSupplier
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Berhasil!',
                                    text: response.message || 'POQ berhasil disimpan dan pesanan dibuat',
                                    confirmButtonColor: '#667eea'
                                }).then(() => {
                                    location.reload();
                                });
                            } else {
                                $('#btnSimpanDanPesanPOQ').prop('disabled', false).html('Simpan dan Pesan');
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error!',
                                    text: response.message || 'Gagal menyimpan POQ!',
                                    confirmButtonColor: '#e74c3c'
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            $('#btnSimpanDanPesanPOQ').prop('disabled', false).html('Simpan dan Pesan');
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: 'Terjadi kesalahan saat menyimpan POQ!',
                                confirmButtonColor: '#e74c3c'
                            });
                        }
                    });
                }
            });
        });
        
        // Reset form saat modal ditutup
        $('#modalHitungPOQ').on('hidden.bs.modal', function() {
            // Reset supplier dropdown format
            $('#poq_supplier option').each(function() {
                var optionText = $(this).text();
                if (optionText.includes('(Pesan Terakhir) - ')) {
                    $(this).text(optionText.replace('(Pesan Terakhir) - ', ''));
                }
            });
            
            $('#poq_kd_barang').val('');
            $('#poq_kd_lokasi').val('');
            $('#poq_kd_barang_display').val('');
            $('#poq_merek_barang').val('');
            $('#poq_kategori_barang').val('');
            $('#poq_berat_barang').val('');
            $('#poq_nama_barang').val('');
            $('#poq_status_barang').val('');
            $('#poq_permintaan').val('');
            $('#poq_biaya_administrasi').val('');
            $('#poq_biaya_holding').val('');
            $('#poq_lead_time').val('');
            $('#poq_stock_sekarang').val('');
            $('#poq_periode').val('');
            $('#poq_kuantitas').val('');
            $('#poq_supplier').val('');
            $('#poq_use_existing_interval').val('0');
            $('#btnSimpanDanPesanPOQ').prop('disabled', false);
        });

        function pesanManual(kdBarang, kdLokasi) {
            // Ambil data stock untuk barang dan lokasi ini
            $.ajax({
                url: '',
                method: 'GET',
                data: {
                    get_stock_data: '1',
                    kd_barang: kdBarang,
                    kd_lokasi: kdLokasi
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Set hidden fields
                        $('#pesan_kd_barang').val(kdBarang);
                        $('#pesan_kd_lokasi').val(kdLokasi);
                        
                        // Set item details (read-only)
                        $('#pesan_kd_barang_display').val(response.kd_barang || '');
                        $('#pesan_merek_barang').val(response.merek_barang || '-');
                        $('#pesan_kategori_barang').val(response.kategori_barang || '-');
                        var beratFormatted = response.berat_barang ? parseInt(response.berat_barang).toLocaleString('id-ID') : '';
                        $('#pesan_berat_barang').val(beratFormatted);
                        $('#pesan_nama_barang').val(response.nama_barang || '');
                        $('#pesan_status_barang').val(response.status_barang || '');
                        
                        // Set stock fields
                        $('#pesan_stock_max').val(response.stock_max);
                        $('#pesan_stock_sekarang').val(response.stock_sekarang);
                        $('#pesan_stock_dipesan').val(0);
                        
                        // Set supplier terakhir jika ada dan update format display
                        var lastSupplierKd = response.last_supplier || null;
                        
                        // Update format semua option supplier
                        $('#pesan_supplier option').each(function() {
                            var optionValue = $(this).val();
                            var originalText = $(this).text();
                            
                            // Hapus prefix "(Pesan Terakhir) - " jika ada
                            if (originalText.includes('(Pesan Terakhir) - ')) {
                                originalText = originalText.replace('(Pesan Terakhir) - ', '');
                            }
                            
                            // Jika ini supplier terakhir, tambahkan prefix
                            if (optionValue && optionValue === lastSupplierKd) {
                                $(this).text('(Pesan Terakhir) - ' + originalText);
                            } else {
                                $(this).text(originalText);
                            }
                        });
                        
                        // Auto-select supplier terakhir jika ada
                        if (lastSupplierKd) {
                            $('#pesan_supplier').val(lastSupplierKd);
                        } else {
                            $('#pesan_supplier').val('');
                        }
                        
                        // Hitung stock setelah dipesan
                        calculateStockAfterOrder();
                        
                        $('#modalPesanManual').modal('show');
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'Gagal mengambil data stock!',
                            confirmButtonColor: '#e74c3c'
                        });
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    console.error('Response:', xhr.responseText);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Terjadi kesalahan saat mengambil data! ' + error,
                        confirmButtonColor: '#e74c3c'
                    });
                }
            });
        }
        
        function setMaxStock() {
            var stockMax = parseInt($('#pesan_stock_max').val()) || 0;
            var stockSekarang = parseInt($('#pesan_stock_sekarang').val()) || 0;
            var stockDipesan = stockMax - stockSekarang;
            
            if (stockDipesan < 0) {
                stockDipesan = 0;
            }
            
            $('#pesan_stock_dipesan').val(stockDipesan);
            calculateStockAfterOrder();
        }
        
        function calculateStockAfterOrder() {
            var stockSekarang = parseInt($('#pesan_stock_sekarang').val()) || 0;
            var stockDipesan = parseInt($('#pesan_stock_dipesan').val()) || 0;
            var stockSetelahDipesan = stockSekarang + stockDipesan;
            
            $('#pesan_stock_setelah_dipesan').val(stockSetelahDipesan);
        }
        
        // Flag untuk mencegah multiple submission - Pesan Manual
        var isSubmittingPesan = false;
        
        // Form validation dan prevent multiple submission - Pesan Manual
        $('#formPesanManual').on('submit', function(e) {
            if (isSubmittingPesan) {
                e.preventDefault();
                return false;
            }
            
            var kdSupplier = $('#pesan_supplier').val();
            var jumlahDipesan = parseInt($('#pesan_stock_dipesan').val()) || 0;
            
            if (!kdSupplier) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Pilih supplier terlebih dahulu!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#pesan_supplier').focus();
                });
                return false;
            }
            
            if (jumlahDipesan <= 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Jumlah stock yang dipesan harus > 0!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#pesan_stock_dipesan').focus();
                });
                return false;
            }
            
            // Konfirmasi dengan SweetAlert
            e.preventDefault();
            Swal.fire({
                title: 'Konfirmasi',
                text: 'Apakah Anda yakin ingin menyimpan dan memesan stock?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Ya, Simpan dan Pesan',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    isSubmittingPesan = true;
                    $('#btnSimpanPesan').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...');
                    $('#formPesanManual')[0].submit();
        }
            });
        });
        
        // Reset flag saat modal ditutup
        $('#modalPesanManual').on('hidden.bs.modal', function() {
            isSubmittingPesan = false;
            $('#btnSimpanPesan').prop('disabled', false).html('Simpan dan Pesan');
            $('#formPesanManual')[0].reset();
        });
    </script>
</body>
</html>

