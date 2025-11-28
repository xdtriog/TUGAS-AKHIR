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
                 WHERE KD_LOKASI = ? AND STATUS = 'AKTIF' AND TYPE_LOKASI = 'toko'";
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
        // Query untuk mendapatkan stock data
        $query_stock_ajax = "SELECT JUMLAH_MIN_STOCK, JUMLAH_MAX_STOCK, JUMLAH_BARANG 
                            FROM STOCK 
                            WHERE KD_BARANG = ? AND KD_LOKASI = ?";
        $stmt_stock_ajax = $conn->prepare($query_stock_ajax);
        $stmt_stock_ajax->bind_param("ss", $kd_barang_ajax, $kd_lokasi_ajax);
        $stmt_stock_ajax->execute();
        $result_stock_ajax = $stmt_stock_ajax->get_result();
        
        if ($result_stock_ajax->num_rows > 0) {
            $stock_data = $result_stock_ajax->fetch_assoc();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'stock_min' => $stock_data['JUMLAH_MIN_STOCK'],
                'stock_max' => $stock_data['JUMLAH_MAX_STOCK'],
                'stock_sekarang' => $stock_data['JUMLAH_BARANG']
            ]);
            exit();
        }
    }
    
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan']);
    exit();
}

// Handle AJAX request untuk update min/max stock
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_stock_setting') {
    header('Content-Type: application/json');
    
    $kd_barang = isset($_POST['kd_barang']) ? trim($_POST['kd_barang']) : '';
    $jumlah_min_stock = isset($_POST['jumlah_min_stock']) ? intval($_POST['jumlah_min_stock']) : 0;
    $jumlah_max_stock = isset($_POST['jumlah_max_stock']) ? intval($_POST['jumlah_max_stock']) : 0;
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    
    if (empty($kd_barang) || $jumlah_min_stock < 0 || $jumlah_max_stock < 0) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    if ($jumlah_min_stock > $jumlah_max_stock) {
        echo json_encode(['success' => false, 'message' => 'Stock Min tidak boleh lebih besar dari Stock Max!']);
        exit();
    }
    
    // Update min dan max stock
    $update_query = "UPDATE STOCK SET JUMLAH_MIN_STOCK = ?, JUMLAH_MAX_STOCK = ?, UPDATED_BY = ? WHERE KD_BARANG = ? AND KD_LOKASI = ?";
    $update_stmt = $conn->prepare($update_query);
    
    if (!$update_stmt) {
        echo json_encode(['success' => false, 'message' => 'Gagal prepare query: ' . $conn->error]);
        exit();
    }
    
    $update_stmt->bind_param("iisss", $jumlah_min_stock, $jumlah_max_stock, $user_id, $kd_barang, $kd_lokasi);
    
    if ($update_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Setting stock berhasil diperbarui!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal memperbarui setting stock: ' . $update_stmt->error]);
    }
    
    $update_stmt->close();
    exit();
}

// Handle AJAX request untuk get batch expired untuk barang tertentu
if (isset($_GET['get_batch_expired']) && $_GET['get_batch_expired'] == '1') {
    header('Content-Type: application/json');
    
    $kd_barang = isset($_GET['kd_barang']) ? trim($_GET['kd_barang']) : '';
    
    if (empty($kd_barang)) {
        echo json_encode(['success' => false, 'message' => 'Kode barang tidak valid!']);
        exit();
    }
    
    // Cari lokasi gudang (asal)
    $query_gudang = "SELECT KD_LOKASI FROM MASTER_LOKASI WHERE TYPE_LOKASI = 'gudang' AND STATUS = 'AKTIF' LIMIT 1";
    $result_gudang = $conn->query($query_gudang);
    if ($result_gudang->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada gudang aktif!']);
        exit();
    }
    $gudang = $result_gudang->fetch_assoc();
    $kd_lokasi_gudang = $gudang['KD_LOKASI'];
    
    // Query untuk mendapatkan batch expired (per ID_PESAN_BARANG dan TGL_EXPIRED)
    // Hanya ambil yang STATUS = 'SELESAI' dan SISA_STOCK_DUS > 0
    // Sort dari expired date terdekat
    $query_batch = "SELECT 
        pb.ID_PESAN_BARANG,
        pb.TGL_EXPIRED,
        pb.SISA_STOCK_DUS,
        COALESCE(ms.KD_SUPPLIER, '-') as SUPPLIER_KD,
        COALESCE(ms.NAMA_SUPPLIER, '-') as NAMA_SUPPLIER
    FROM PESAN_BARANG pb
    LEFT JOIN MASTER_SUPPLIER ms ON pb.KD_SUPPLIER = ms.KD_SUPPLIER
    WHERE pb.KD_BARANG = ? AND pb.KD_LOKASI = ? AND pb.STATUS = 'SELESAI' AND pb.SISA_STOCK_DUS > 0
    ORDER BY 
        CASE 
            WHEN pb.TGL_EXPIRED IS NULL THEN 999
            WHEN pb.TGL_EXPIRED < CURDATE() THEN 1
            WHEN pb.TGL_EXPIRED = CURDATE() THEN 2
            WHEN pb.TGL_EXPIRED <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 3
            ELSE 4
        END ASC,
        COALESCE(pb.TGL_EXPIRED, '9999-12-31') ASC";
    $stmt_batch = $conn->prepare($query_batch);
    $stmt_batch->bind_param("ss", $kd_barang, $kd_lokasi_gudang);
    $stmt_batch->execute();
    $result_batch = $stmt_batch->get_result();
    
    $batches = [];
    while ($row = $result_batch->fetch_assoc()) {
        $supplier_display = '';
        if ($row['SUPPLIER_KD'] != '-' && $row['NAMA_SUPPLIER'] != '-') {
            $supplier_display = $row['SUPPLIER_KD'] . ' - ' . $row['NAMA_SUPPLIER'];
        } else {
            $supplier_display = '-';
        }
        
        // Format tanggal expired
        $tgl_expired_display = '-';
        if (!empty($row['TGL_EXPIRED'])) {
            $date_expired = new DateTime($row['TGL_EXPIRED']);
            $bulan = [
                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
            ];
            $tgl_expired_display = $date_expired->format('d') . ' ' . $bulan[(int)$date_expired->format('m')] . ' ' . $date_expired->format('Y');
        }
        
        $batches[] = [
            'id_pesan_barang' => $row['ID_PESAN_BARANG'],
            'tgl_expired' => $row['TGL_EXPIRED'],
            'tgl_expired_display' => $tgl_expired_display,
            'sisa_stock_dus' => intval($row['SISA_STOCK_DUS']),
            'supplier' => $supplier_display
        ];
    }
    
    echo json_encode(['success' => true, 'batches' => $batches]);
    exit();
}

// Handle AJAX request untuk get data barang yang dipilih untuk resupply
if (isset($_GET['get_resupply_data']) && $_GET['get_resupply_data'] == '1') {
    header('Content-Type: application/json');
    
    $kd_barang_list = isset($_GET['kd_barang']) ? $_GET['kd_barang'] : [];
    if (!is_array($kd_barang_list)) {
        $kd_barang_list = [$kd_barang_list];
    }
    
    if (empty($kd_barang_list)) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada barang yang dipilih!']);
        exit();
    }
    
    // Cari lokasi gudang (asal)
    $query_gudang = "SELECT KD_LOKASI FROM MASTER_LOKASI WHERE TYPE_LOKASI = 'gudang' AND STATUS = 'AKTIF' LIMIT 1";
    $result_gudang = $conn->query($query_gudang);
    if ($result_gudang->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada gudang aktif!']);
        exit();
    }
    $gudang = $result_gudang->fetch_assoc();
    $kd_lokasi_gudang = $gudang['KD_LOKASI'];
    
    // Buat placeholders untuk IN clause
    $placeholders = str_repeat('?,', count($kd_barang_list) - 1) . '?';
    
    $query_resupply = "SELECT 
        s.KD_BARANG,
        mb.NAMA_BARANG,
        mb.BERAT,
        mb.SATUAN_PERDUS,
        COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
        COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
        s.JUMLAH_BARANG as STOCK_SEKARANG,
        s.JUMLAH_MIN_STOCK,
        s.JUMLAH_MAX_STOCK,
        s.SATUAN,
        COALESCE(sg.JUMLAH_BARANG, 0) as STOCK_GUDANG,
        COALESCE(sg.SATUAN, 'PIECES') as SATUAN_GUDANG
    FROM STOCK s
    INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
    LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
    LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
    LEFT JOIN STOCK sg ON mb.KD_BARANG = sg.KD_BARANG AND sg.KD_LOKASI = ?
    WHERE s.KD_BARANG IN ($placeholders) AND s.KD_LOKASI = ? AND mb.STATUS = 'AKTIF'
    ORDER BY mb.NAMA_BARANG ASC";
    // Note: Filter STATUS = 'AKTIF' tetap diperlukan untuk resupply karena hanya barang aktif yang bisa di-resupply
    
    $stmt_resupply = $conn->prepare($query_resupply);
    $types = str_repeat('s', count($kd_barang_list)) . 'ss';
    $params = array_merge([$kd_lokasi_gudang], $kd_barang_list, [$kd_lokasi]);
    $stmt_resupply->bind_param($types, ...$params);
    $stmt_resupply->execute();
    $result_resupply = $stmt_resupply->get_result();
    
    $data = [];
    while ($row = $result_resupply->fetch_assoc()) {
        $data[] = [
            'kd_barang' => $row['KD_BARANG'],
            'nama_merek' => $row['NAMA_MEREK'],
            'nama_kategori' => $row['NAMA_KATEGORI'],
            'nama_barang' => $row['NAMA_BARANG'],
            'berat' => $row['BERAT'],
            'stock_min' => $row['JUMLAH_MIN_STOCK'],
            'stock_max' => $row['JUMLAH_MAX_STOCK'],
            'stock_sekarang' => $row['STOCK_SEKARANG'],
            'satuan_perdus' => $row['SATUAN_PERDUS'],
            'satuan' => $row['SATUAN'],
            'stock_gudang' => $row['STOCK_GUDANG'],
            'satuan_gudang' => $row['SATUAN_GUDANG']
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $data]);
    exit();
}

// Handle AJAX request untuk cek stock gudang
if (isset($_GET['cek_stock_gudang']) && $_GET['cek_stock_gudang'] == '1') {
    header('Content-Type: application/json');
    
    $resupply_data = isset($_GET['resupply_data']) ? json_decode($_GET['resupply_data'], true) : [];
    
    if (empty($resupply_data) || !is_array($resupply_data)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Cari lokasi gudang (asal)
    $query_gudang = "SELECT KD_LOKASI FROM MASTER_LOKASI WHERE TYPE_LOKASI = 'gudang' AND STATUS = 'AKTIF' LIMIT 1";
    $result_gudang = $conn->query($query_gudang);
    if ($result_gudang->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada gudang aktif!']);
        exit();
    }
    $gudang = $result_gudang->fetch_assoc();
    $kd_lokasi_gudang = $gudang['KD_LOKASI'];
    
    $stock_tidak_cukup = [];
    
    foreach ($resupply_data as $item) {
        $kd_barang = $item['kd_barang'] ?? '';
        $jumlah_resupply_dus = intval($item['jumlah_resupply_dus'] ?? 0);
        
        if (empty($kd_barang) || $jumlah_resupply_dus <= 0) {
            continue;
        }
        
        // Get stock gudang dan satuan perdus
        $query_stock_gudang = "SELECT 
            COALESCE(s.JUMLAH_BARANG, 0) as STOCK_GUDANG,
            COALESCE(s.SATUAN, 'DUS') as SATUAN,
            mb.SATUAN_PERDUS,
            mb.NAMA_BARANG
        FROM MASTER_BARANG mb
        LEFT JOIN STOCK s ON mb.KD_BARANG = s.KD_BARANG AND s.KD_LOKASI = ?
        WHERE mb.KD_BARANG = ?";
        $stmt_stock_gudang = $conn->prepare($query_stock_gudang);
        $stmt_stock_gudang->bind_param("ss", $kd_lokasi_gudang, $kd_barang);
        $stmt_stock_gudang->execute();
        $result_stock_gudang = $stmt_stock_gudang->get_result();
        
        if ($result_stock_gudang->num_rows > 0) {
            $stock_data = $result_stock_gudang->fetch_assoc();
            $stock_gudang = intval($stock_data['STOCK_GUDANG']);
            $satuan = $stock_data['SATUAN'] ?? 'DUS';
            $satuan_perdus = intval($stock_data['SATUAN_PERDUS'] ?? 1);
            $nama_barang = $stock_data['NAMA_BARANG'];
            
            // Konversi stock gudang ke DUS untuk perbandingan
            // Jika SATUAN = 'PIECES', konversi ke DUS: stock_gudang / satuan_perdus
            // Jika SATUAN = 'DUS', langsung gunakan stock_gudang
            $stock_gudang_dus = $stock_gudang;
            if ($satuan == 'PIECES') {
                $stock_gudang_dus = floor($stock_gudang / $satuan_perdus);
            }
            
            // Cek apakah stock gudang (dalam DUS) cukup untuk jumlah resupply (dalam DUS)
            if ($stock_gudang_dus < $jumlah_resupply_dus) {
                $stock_tidak_cukup[] = [
                    'kd_barang' => $kd_barang,
                    'nama_barang' => $nama_barang,
                    'jumlah_resupply_dus' => $jumlah_resupply_dus,
                    'stock_gudang_dus' => $stock_gudang_dus,
                    'stock_gudang' => $stock_gudang,
                    'satuan' => $satuan
                ];
            }
        } else {
            // Barang tidak ditemukan di gudang, berarti stock = 0
            $query_nama_barang = "SELECT NAMA_BARANG FROM MASTER_BARANG WHERE KD_BARANG = ?";
            $stmt_nama_barang = $conn->prepare($query_nama_barang);
            $stmt_nama_barang->bind_param("s", $kd_barang);
            $stmt_nama_barang->execute();
            $result_nama_barang = $stmt_nama_barang->get_result();
            $nama_barang = $result_nama_barang->num_rows > 0 ? $result_nama_barang->fetch_assoc()['NAMA_BARANG'] : $kd_barang;
            
            $stock_tidak_cukup[] = [
                'kd_barang' => $kd_barang,
                'nama_barang' => $nama_barang,
                'jumlah_resupply_dus' => $jumlah_resupply_dus,
                'stock_gudang_dus' => 0,
                'stock_gudang' => 0,
                'satuan' => 'DUS'
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'stock_tidak_cukup' => $stock_tidak_cukup,
        'ada_stock_tidak_cukup' => count($stock_tidak_cukup) > 0
    ]);
    exit();
}

// Handle AJAX request untuk simpan resupply
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'simpan_resupply') {
    header('Content-Type: application/json');
    
    $resupply_data = isset($_POST['resupply_data']) ? $_POST['resupply_data'] : [];
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    
    if (empty($resupply_data) || !is_array($resupply_data)) {
        echo json_encode(['success' => false, 'message' => 'Data resupply tidak valid!']);
        exit();
    }
    
    // Cari lokasi gudang (asal)
    $query_gudang = "SELECT KD_LOKASI FROM MASTER_LOKASI WHERE TYPE_LOKASI = 'gudang' AND STATUS = 'AKTIF' LIMIT 1";
    $result_gudang = $conn->query($query_gudang);
    if ($result_gudang->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada gudang aktif!']);
        exit();
    }
    $gudang = $result_gudang->fetch_assoc();
    $kd_lokasi_asal = $gudang['KD_LOKASI'];
    
    // Cek stock gudang sebelum menyimpan
    $stock_habis = [];
    $query_gudang_check = "SELECT KD_LOKASI FROM MASTER_LOKASI WHERE TYPE_LOKASI = 'gudang' AND STATUS = 'AKTIF' LIMIT 1";
    $result_gudang_check = $conn->query($query_gudang_check);
    if ($result_gudang_check->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Tidak ada gudang aktif!']);
        exit();
    }
    $gudang_check = $result_gudang_check->fetch_assoc();
    $kd_lokasi_gudang_check = $gudang_check['KD_LOKASI'];
    
    foreach ($resupply_data as $item) {
        $kd_barang = $item['kd_barang'] ?? '';
        $jumlah_resupply_dus = intval($item['jumlah_resupply_dus'] ?? 0);
        
        if (empty($kd_barang) || $jumlah_resupply_dus <= 0) {
            continue;
        }
        
        // Get stock gudang dan satuan perdus
        $query_stock_gudang_check = "SELECT 
            COALESCE(s.JUMLAH_BARANG, 0) as STOCK_GUDANG,
            COALESCE(s.SATUAN, 'DUS') as SATUAN,
            mb.SATUAN_PERDUS,
            mb.NAMA_BARANG
        FROM MASTER_BARANG mb
        LEFT JOIN STOCK s ON mb.KD_BARANG = s.KD_BARANG AND s.KD_LOKASI = ?
        WHERE mb.KD_BARANG = ?";
        $stmt_stock_gudang_check = $conn->prepare($query_stock_gudang_check);
        $stmt_stock_gudang_check->bind_param("ss", $kd_lokasi_gudang_check, $kd_barang);
        $stmt_stock_gudang_check->execute();
        $result_stock_gudang_check = $stmt_stock_gudang_check->get_result();
        
        if ($result_stock_gudang_check->num_rows > 0) {
            $stock_data_check = $result_stock_gudang_check->fetch_assoc();
            $stock_gudang_check = intval($stock_data_check['STOCK_GUDANG']);
            $satuan_check = $stock_data_check['SATUAN'] ?? 'DUS';
            $satuan_perdus_check = intval($stock_data_check['SATUAN_PERDUS'] ?? 1);
            $nama_barang_check = $stock_data_check['NAMA_BARANG'];
            
            // Konversi stock gudang ke DUS untuk perbandingan
            // Jika SATUAN = 'PIECES', konversi ke DUS: stock_gudang / satuan_perdus
            // Jika SATUAN = 'DUS', langsung gunakan stock_gudang
            $stock_gudang_dus_check = $stock_gudang_check;
            if ($satuan_check == 'PIECES') {
                $stock_gudang_dus_check = floor($stock_gudang_check / $satuan_perdus_check);
            }
            
            // Cek apakah stock gudang (dalam DUS) habis atau tidak cukup untuk jumlah resupply (dalam DUS)
            if ($stock_gudang_dus_check <= 0) {
                $stock_habis[] = [
                    'kd_barang' => $kd_barang,
                    'nama_barang' => $nama_barang_check,
                    'stock_gudang_dus' => 0,
                    'stock_gudang' => $stock_gudang_check,
                    'satuan' => $satuan_check,
                    'pesan' => 'Stock gudang habis'
                ];
            } elseif ($stock_gudang_dus_check < $jumlah_resupply_dus) {
                $stock_habis[] = [
                    'kd_barang' => $kd_barang,
                    'nama_barang' => $nama_barang_check,
                    'jumlah_resupply_dus' => $jumlah_resupply_dus,
                    'stock_gudang_dus' => $stock_gudang_dus_check,
                    'stock_gudang' => $stock_gudang_check,
                    'satuan' => $satuan_check,
                    'pesan' => 'Stock gudang tidak mencukupi'
                ];
            }
        } else {
            // Barang tidak ditemukan di gudang, berarti stock = 0
            $query_nama_barang = "SELECT NAMA_BARANG FROM MASTER_BARANG WHERE KD_BARANG = ?";
            $stmt_nama_barang = $conn->prepare($query_nama_barang);
            $stmt_nama_barang->bind_param("s", $kd_barang);
            $stmt_nama_barang->execute();
            $result_nama_barang = $stmt_nama_barang->get_result();
            $nama_barang_check = $result_nama_barang->num_rows > 0 ? $result_nama_barang->fetch_assoc()['NAMA_BARANG'] : $kd_barang;
            
            $stock_habis[] = [
                'kd_barang' => $kd_barang,
                'nama_barang' => $nama_barang_check,
                'stock_gudang_dus' => 0,
                'stock_gudang' => 0,
                'satuan' => 'DUS',
                'pesan' => 'Stock gudang habis'
            ];
        }
    }
    
    // Filter resupply_data: hanya simpan barang yang stocknya cukup
    $resupply_data_valid = [];
    $kd_barang_stock_habis = [];
    
    foreach ($stock_habis as $item) {
        $kd_barang_stock_habis[] = $item['kd_barang'];
    }
    
    foreach ($resupply_data as $item) {
        $kd_barang = $item['kd_barang'] ?? '';
        // Hanya tambahkan jika tidak ada di list stock habis
        if (!in_array($kd_barang, $kd_barang_stock_habis)) {
            $resupply_data_valid[] = $item;
        }
    }
    
    // Jika tidak ada barang yang valid, tolak semua
    if (empty($resupply_data_valid)) {
        $message = 'Barang berikut tidak dapat di-resupply karena stock gudang habis atau tidak mencukupi:\n\n';
        foreach ($stock_habis as $item) {
            $message .= '- ' . $item['nama_barang'] . ' (' . $item['kd_barang'] . '): ' . $item['pesan'];
            if (isset($item['jumlah_resupply_dus'])) {
                $message .= ' (Butuh: ' . number_format($item['jumlah_resupply_dus'], 0, ',', '.') . ' dus, Stock gudang: ' . number_format($item['stock_gudang_dus'], 0, ',', '.') . ' dus)';
            } else {
                $message .= ' (Stock gudang: ' . number_format($item['stock_gudang_dus'], 0, ',', '.') . ' dus)';
            }
            $message .= '\n';
        }
        echo json_encode(['success' => false, 'message' => $message, 'stock_habis' => $stock_habis]);
        exit();
    }
    
    // Update resupply_data dengan yang valid saja
    $resupply_data = $resupply_data_valid;
    
    // Mulai transaksi
    $conn->begin_transaction();
    
    try {
        // Generate ID transfer
        require_once '../includes/uuid_generator.php';
        $id_transfer = '';
        do {
            $id_transfer = ShortIdGenerator::generate(16, '');
        } while (checkUUIDExists($conn, 'TRANSFER_BARANG', 'ID_TRANSFER_BARANG', $id_transfer));
        
        // Insert TRANSFER_BARANG
        // ID_USERS_PENERIMA diisi NULL karena owner tidak mengisi, nanti toko yang mengisi saat validasi masuk
        $insert_transfer = "INSERT INTO TRANSFER_BARANG 
                          (ID_TRANSFER_BARANG, ID_USERS_PENERIMA, KD_LOKASI_ASAL, KD_LOKASI_TUJUAN, WAKTU_PESAN_TRANSFER, STATUS)
                          VALUES (?, NULL, ?, ?, CURRENT_TIMESTAMP, 'DIPESAN')";
        $stmt_transfer = $conn->prepare($insert_transfer);
        if (!$stmt_transfer) {
            throw new Exception('Gagal prepare query transfer: ' . $conn->error);
        }
        $stmt_transfer->bind_param("sss", $id_transfer, $kd_lokasi_asal, $kd_lokasi);
        if (!$stmt_transfer->execute()) {
            throw new Exception('Gagal insert transfer: ' . $stmt_transfer->error);
        }
        
        // Insert DETAIL_TRANSFER_BARANG untuk setiap barang dan kurangi SISA_STOCK_DUS di PESAN_BARANG
        foreach ($resupply_data as $item) {
            $kd_barang = $item['kd_barang'] ?? '';
            $jumlah_resupply_dus = intval($item['jumlah_resupply_dus'] ?? 0);
            $batches = isset($item['batches']) && is_array($item['batches']) ? $item['batches'] : [];
            
            if (empty($kd_barang) || $jumlah_resupply_dus <= 0) {
                continue; // Skip invalid data
            }
            
            // Validasi batch dan simpan ke tabel DETAIL_TRANSFER_BARANG_BATCH
            // TIDAK mengurangi SISA_STOCK_DUS di sini, akan dikurangi saat validasi kirim di gudang
            foreach ($batches as $batch) {
                $id_pesan_barang = $batch['id_pesan_barang'] ?? '';
                $jumlah_dus_batch = intval($batch['jumlah_dus'] ?? 0);
                
                if (empty($id_pesan_barang) || $jumlah_dus_batch <= 0) {
                    continue;
                }
                
                // Cek apakah batch masih memiliki sisa stock yang cukup
                $query_check_batch = "SELECT SISA_STOCK_DUS FROM PESAN_BARANG WHERE ID_PESAN_BARANG = ? AND KD_LOKASI = ? AND STATUS = 'SELESAI'";
                $stmt_check_batch = $conn->prepare($query_check_batch);
                if (!$stmt_check_batch) {
                    throw new Exception('Gagal prepare query check batch: ' . $conn->error);
                }
                $stmt_check_batch->bind_param("ss", $id_pesan_barang, $kd_lokasi_asal);
                if (!$stmt_check_batch->execute()) {
                    throw new Exception('Gagal execute query check batch: ' . $stmt_check_batch->error);
                }
                $result_check_batch = $stmt_check_batch->get_result();
                
                if ($result_check_batch->num_rows > 0) {
                    $batch_data = $result_check_batch->fetch_assoc();
                    $sisa_stock_dus = intval($batch_data['SISA_STOCK_DUS'] ?? 0);
                    
                    // Validasi sisa stock cukup (hanya validasi, tidak kurangi dulu)
                    if ($sisa_stock_dus < $jumlah_dus_batch) {
                        throw new Exception('Sisa stock batch ' . $id_pesan_barang . ' tidak mencukupi! Sisa: ' . $sisa_stock_dus . ' dus, Butuh: ' . $jumlah_dus_batch . ' dus');
                    }
                } else {
                    throw new Exception('Batch ' . $id_pesan_barang . ' tidak ditemukan atau tidak valid!');
                }
            }
            
            // Generate ID detail transfer
            $id_detail = '';
            do {
                $id_detail = ShortIdGenerator::generate(16, '');
            } while (checkUUIDExists($conn, 'DETAIL_TRANSFER_BARANG', 'ID_DETAIL_TRANSFER_BARANG', $id_detail));
            
            $insert_detail = "INSERT INTO DETAIL_TRANSFER_BARANG 
                             (ID_DETAIL_TRANSFER_BARANG, ID_TRANSFER_BARANG, KD_BARANG, TOTAL_PESAN_TRANSFER_DUS, STATUS)
                             VALUES (?, ?, ?, ?, 'DIPESAN')";
            $stmt_detail = $conn->prepare($insert_detail);
            if (!$stmt_detail) {
                throw new Exception('Gagal prepare query detail transfer: ' . $conn->error);
            }
            $stmt_detail->bind_param("sssi", $id_detail, $id_transfer, $kd_barang, $jumlah_resupply_dus);
            if (!$stmt_detail->execute()) {
                throw new Exception('Gagal insert detail transfer: ' . $stmt_detail->error);
            }
            
            // Simpan batch ke tabel DETAIL_TRANSFER_BARANG_BATCH
            foreach ($batches as $batch) {
                $id_pesan_barang = $batch['id_pesan_barang'] ?? '';
                $jumlah_dus_batch = intval($batch['jumlah_dus'] ?? 0);
                
                if (empty($id_pesan_barang) || $jumlah_dus_batch <= 0) {
                    continue;
                }
                
                // Generate ID detail transfer batch
                $id_detail_batch = '';
                do {
                    $id_detail_batch = ShortIdGenerator::generate(16, '');
                } while (checkUUIDExists($conn, 'DETAIL_TRANSFER_BARANG_BATCH', 'ID_DETAIL_TRANSFER_BARANG_BATCH', $id_detail_batch));
                
                $insert_detail_batch = "INSERT INTO DETAIL_TRANSFER_BARANG_BATCH 
                                       (ID_DETAIL_TRANSFER_BARANG_BATCH, ID_DETAIL_TRANSFER_BARANG, ID_PESAN_BARANG, JUMLAH_PESAN_TRANSFER_BATCH_DUS)
                                       VALUES (?, ?, ?, ?)";
                $stmt_detail_batch = $conn->prepare($insert_detail_batch);
                if (!$stmt_detail_batch) {
                    throw new Exception('Gagal prepare query detail batch: ' . $conn->error);
                }
                $stmt_detail_batch->bind_param("sssi", $id_detail_batch, $id_detail, $id_pesan_barang, $jumlah_dus_batch);
                if (!$stmt_detail_batch->execute()) {
                    throw new Exception('Gagal insert detail batch: ' . $stmt_detail_batch->error);
                }
            }
        }
        
        // Commit transaksi
        $conn->commit();
        
        // Siapkan response dengan informasi barang yang berhasil dan yang gagal
        $message = 'Resupply berhasil dibuat dengan ID: ' . $id_transfer;
        if (!empty($stock_habis)) {
            $message .= '\n\nBarang yang berhasil disimpan: ' . count($resupply_data) . ' item';
            $message .= '\nBarang yang tidak dapat disimpan: ' . count($stock_habis) . ' item (stock gudang habis/tidak mencukupi)';
        }
        
        echo json_encode([
            'success' => true, 
            'message' => $message,
            'id_transfer' => $id_transfer,
            'barang_berhasil' => count($resupply_data),
            'barang_gagal' => count($stock_habis),
            'stock_habis' => $stock_habis
        ]);
    } catch (Exception $e) {
        // Rollback transaksi
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit();
}

// Query untuk mendapatkan data stock dengan informasi lengkap
$query_stock = "SELECT 
    s.KD_BARANG,
    mb.NAMA_BARANG,
    mb.BERAT,
    mb.SATUAN_PERDUS,
    mb.STATUS as STATUS_BARANG,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
    s.JUMLAH_BARANG as STOCK_SEKARANG,
    s.JUMLAH_MIN_STOCK,
    s.JUMLAH_MAX_STOCK,
    s.SATUAN,
    s.LAST_UPDATED,
    COALESCE(
        (SELECT MAX(tb.WAKTU_SELESAI_TRANSFER)
         FROM TRANSFER_BARANG tb
         INNER JOIN DETAIL_TRANSFER_BARANG dtb ON tb.ID_TRANSFER_BARANG = dtb.ID_TRANSFER_BARANG
         WHERE tb.KD_LOKASI_TUJUAN = s.KD_LOKASI 
           AND dtb.KD_BARANG = s.KD_BARANG 
           AND tb.STATUS = 'SELESAI'
           AND dtb.STATUS = 'SELESAI'),
        NULL
    ) as TERAKHIR_RESUPPLY,
    CASE 
        WHEN s.JUMLAH_MIN_STOCK IS NOT NULL AND s.JUMLAH_BARANG < s.JUMLAH_MIN_STOCK THEN 1
        WHEN s.JUMLAH_MIN_STOCK IS NOT NULL AND s.JUMLAH_BARANG >= s.JUMLAH_MIN_STOCK 
             AND s.JUMLAH_BARANG <= (s.JUMLAH_MIN_STOCK * 1.2) THEN 2
        ELSE 3
    END as PRIORITAS
FROM STOCK s
INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
WHERE s.KD_LOKASI = ?
ORDER BY s.JUMLAH_BARANG ASC";

$stmt_stock = $conn->prepare($query_stock);
if ($stmt_stock === false) {
    error_log("SQL Error: " . $conn->error);
    error_log("Query: " . $query_stock);
    $message = 'Error mempersiapkan query: ' . htmlspecialchars($conn->error);
    $message_type = 'danger';
    $result_stock = null;
} else {
    $stmt_stock->bind_param("s", $kd_lokasi);
    $stmt_stock->execute();
    $result_stock = $stmt_stock->get_result();
}

// Format tanggal dan waktu Indonesia
function formatTanggalWaktu($tanggal) {
    if (empty($tanggal) || $tanggal == null) {
        return '-';
    }
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $date = new DateTime($tanggal);
    $tanggal_formatted = $date->format('d') . ' ' . $bulan[(int)$date->format('m')] . ' ' . $date->format('Y');
    $waktu_formatted = $date->format('H:i') . ' WIB';
    
    return $tanggal_formatted . ' ' . $waktu_formatted;
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
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
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
            <?php if (!empty($lokasi['ALAMAT_LOKASI'])): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($lokasi['ALAMAT_LOKASI']); ?></p>
            <?php endif; ?>
        </div>


        <!-- Action Buttons -->
        <div class="mb-3 d-flex gap-2">
            <button type="button" class="btn-primary-custom" onclick="bukaModalResupply()">
                Resupply barang
            </button>
            <button type="button" class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalSettingStock">
                Setting Stock Toko
            </button>
        </div>

        <!-- Table Stock -->
        <div class="table-section" style="width: 100%;">
            <div class="table-responsive" style="width: 100%;">
                <table id="tableStock" class="table table-custom table-striped table-hover" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>
                                <input type="checkbox" id="selectAll" title="Select All">
                            </th>
                            <th>Kode Barang</th>
                            <th>Merek Barang</th>
                            <th>Kategori Barang</th>
                            <th>Nama Barang</th>
                            <th>Berat (gr)</th>
                            <th>Stock Min</th>
                            <th>Stock Max</th>
                            <th>Stock Sekarang</th>
                            <th>Satuan</th>
                            <th>Terakhir Resupply</th>
                            <th>Terakhir Update</th>
                            <th>Status</th>
                            <th>Action</th>
                            <th style="display: none;">PRIORITAS</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_stock && $result_stock->num_rows > 0): ?>
                            <?php while ($row = $result_stock->fetch_assoc()): ?>
                                <tr>
                                    <td>
                                        <?php if ($row['STATUS_BARANG'] == 'AKTIF'): ?>
                                            <input type="checkbox" class="row-checkbox" value="<?php echo htmlspecialchars($row['KD_BARANG']); ?>">
                                        <?php else: ?>
                                            <input type="checkbox" class="row-checkbox" value="<?php echo htmlspecialchars($row['KD_BARANG']); ?>" disabled style="opacity: 0.5;">
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['KD_BARANG']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_MEREK']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_BARANG']); ?></td>
                                    <td><?php echo number_format($row['BERAT'], 0, ',', '.'); ?></td>
                                    <td><?php echo $row['JUMLAH_MIN_STOCK'] ? number_format($row['JUMLAH_MIN_STOCK'], 0, ',', '.') : '-'; ?></td>
                                    <td><?php echo $row['JUMLAH_MAX_STOCK'] ? number_format($row['JUMLAH_MAX_STOCK'], 0, ',', '.') : '-'; ?></td>
                                    <td><?php echo number_format($row['STOCK_SEKARANG'], 0, ',', '.'); ?></td>
                                    <td><?php echo htmlspecialchars($row['SATUAN']); ?></td>
                                    <td><?php echo formatTanggalWaktu($row['TERAKHIR_RESUPPLY']); ?></td>
                                    <td><?php echo formatTanggalWaktu($row['LAST_UPDATED']); ?></td>
                                    <td>
                                        <?php if ($row['STATUS_BARANG'] == 'AKTIF'): ?>
                                            <span class="badge bg-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Tidak Aktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn-view btn-sm" onclick="riwayatResupply('<?php echo htmlspecialchars($row['KD_BARANG']); ?>')">Riwayat Resupply</button>
                                    </td>
                                    <td style="display: none;"><?php echo $row['PRIORITAS']; ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Setting Stock Toko -->
    <div class="modal fade" id="modalSettingStock" tabindex="-1" aria-labelledby="modalSettingStockLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalSettingStockLabel">Setting Stock Toko</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="formSettingStock">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Pilih Barang</label>
                            <select class="form-select" id="setting_kd_barang" name="kd_barang" required>
                                <option value="">-- Pilih Barang --</option>
                                <?php
                                if ($result_stock && $result_stock->num_rows > 0) {
                                    // Reset result pointer
                                    $result_stock->data_seek(0);
                                    while ($row = $result_stock->fetch_assoc()):
                                        // Hanya tampilkan barang aktif di dropdown
                                        if ($row['STATUS_BARANG'] == 'AKTIF'):
                                ?>
                                    <option value="<?php echo htmlspecialchars($row['KD_BARANG']); ?>">
                                        <?php 
                                        $display_text = $row['KD_BARANG'] . '-' . $row['NAMA_MEREK'] . '-' . $row['NAMA_KATEGORI'] . '-' . $row['NAMA_BARANG'] . '-' . number_format($row['BERAT'], 0, ',', '.');
                                        echo htmlspecialchars($display_text);
                                        ?>
                                    </option>
                                <?php
                                        endif;
                                    endwhile;
                                }
                                ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Stock Min</label>
                            <input type="number" class="form-control" id="setting_stock_min" name="jumlah_min_stock" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Stock Max</label>
                            <input type="number" class="form-control" id="setting_stock_max" name="jumlah_max_stock" min="0" required>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" onclick="simpanSettingStock()">Simpan</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Resupply Barang -->
    <div class="modal fade" id="modalResupply" tabindex="-1" aria-labelledby="modalResupplyLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalResupplyLabel">Resupply Barang</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="tableResupply">
                            <thead>
                                <tr>
                                    <th>Kode Barang</th>
                                    <th>Merek Barang</th>
                                    <th>Kategori Barang</th>
                                    <th>Nama Barang</th>
                                    <th>Berat (gr)</th>
                                    <th>Stock Min (pieces)</th>
                                    <th>Stock Max (pieces)</th>
                                    <th>Stock Sekarang (pieces)</th>
                                    <th>Satuan per dus</th>
                                    <th>Pilih Batch</th>
                                    <th>Total Resupply (dus)</th>
                                    <th>Jumlah Resupply (piece)</th>
                                    <th>Jumlah Stock Akhir (piece)</th>
                                    <th>Isi Penuh</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyResupply">
                                <tr>
                                    <td colspan="14" class="text-center text-muted">Pilih barang dari tabel utama terlebih dahulu</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" onclick="konfirmasiSimpanResupply()">Konfirmasi dan Simpan</button>
                </div>
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
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Sidebar Script -->
    <script src="includes/sidebar.js"></script>
    <!-- DataTables Initialization -->
    <script>
        $(document).ready(function() {
            // Disable DataTables error reporting
            $.fn.dataTable.ext.errMode = 'none';
            
            $('#tableStock').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data stock'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[14, 'asc'], [4, 'asc']], // Sort by PRIORITAS (hidden column), then Nama Barang
                columnDefs: [
                    { orderable: false, targets: [0, 13] }, // Disable sorting on checkbox and Action column
                    { visible: false, targets: 14 } // Hide PRIORITAS column
                ],
                scrollX: true,
                autoWidth: false,
                width: '100%',
                drawCallback: function(settings) {
                    if (settings.aoData.length === 0) {
                        return;
                    }
                }
            }).on('error.dt', function(e, settings, techNote, message) {
                console.log('DataTables error suppressed:', message);
                return false;
            });

            // Select All checkbox
            $('#selectAll').on('change', function() {
                $('.row-checkbox').prop('checked', $(this).prop('checked'));
            });

            // Update select all when individual checkbox changes
            $('.row-checkbox').on('change', function() {
                var totalCheckboxes = $('.row-checkbox').length;
                var checkedCheckboxes = $('.row-checkbox:checked').length;
                $('#selectAll').prop('checked', totalCheckboxes === checkedCheckboxes);
            });
            
            // Reset modal resupply saat ditutup
            $('#modalResupply').on('hidden.bs.modal', function() {
                $('#tbodyResupply').html('<tr><td colspan="14" class="text-center text-muted">Pilih barang dari tabel utama terlebih dahulu</td></tr>');
            });
            
            // Event listener global untuk remove batch (menggunakan event delegation)
            $(document).on('click', '.remove-batch', function() {
                var idPesan = $(this).data('id-pesan');
                var rowIndex = $(this).data('index');
                $(this).closest('.selected-batches').find('[data-id-pesan="' + idPesan + '"]').remove();
                
                if (rowIndex !== undefined && rowIndex !== null) {
                    updateTotalJumlahResupply(rowIndex);
                }
            });
            
            // Event listener global untuk input jumlah per batch (menggunakan event delegation)
            // Hanya update total, jangan ubah nilai input
            // Event listener untuk input batch - hanya update total, jangan ubah nilai
            var batchInputHandlers = {};
            $(document).on('input', '.batch-jumlah', function(e) {
                e.stopPropagation();
                var $input = $(this);
                var rowIndex = $input.data('index');
                var inputId = $input.data('id-pesan') + '_' + rowIndex;
                
                // Simpan nilai saat ini
                var currentVal = $input.val();
                
                // Clear timeout sebelumnya
                if (batchInputHandlers[inputId]) {
                    clearTimeout(batchInputHandlers[inputId]);
                }
                
                // Hanya update total, jangan ubah nilai input
                if (rowIndex !== undefined && rowIndex !== null && !isNaN(rowIndex)) {
                    // Update total dengan delay untuk menghindari konflik
                    batchInputHandlers[inputId] = setTimeout(function() {
                        // Pastikan nilai masih sama sebelum update
                        if ($input.val() === currentVal) {
                            updateTotalJumlahResupply(rowIndex);
                        }
                    }, 100);
                }
            });
            
            // Event listener untuk blur (saat user selesai mengetik) - untuk validasi
            $(document).on('blur', '.batch-jumlah', function(e) {
                e.stopPropagation();
                var $input = $(this);
                var idPesan = $input.data('id-pesan');
                var rowIndex = $input.data('index');
                var currentValue = $input.val();
                
                // Simpan nilai asli sebelum validasi
                var originalValue = currentValue;
                
                // Jika kosong, set ke 0
                if (currentValue === '' || currentValue === null || currentValue === undefined) {
                    $input.val(0);
                    currentValue = '0';
                }
                
                var jumlah = parseInt(currentValue) || 0;
                
                // Cari parent div yang memiliki data-sisa-stock
                var parentDiv = $input.closest('[data-id-pesan="' + idPesan + '"]');
                var maxJumlah = parseInt(parentDiv.data('sisa-stock')) || 0;
                
                // Validasi tidak boleh negatif
                if (jumlah < 0 || isNaN(jumlah)) {
                    $input.val(0);
                    jumlah = 0;
                }
                
                // Validasi tidak boleh melebihi sisa stock
                if (jumlah > maxJumlah) {
                    $input.val(maxJumlah);
                    jumlah = maxJumlah;
                }
                
                // Pastikan nilai tidak berubah secara tidak sengaja
                if ($input.val() !== originalValue && originalValue !== '' && originalValue !== null && originalValue !== undefined) {
                    // Jika nilai berubah karena validasi, pastikan perubahan itu valid
                    var finalValue = parseInt($input.val()) || 0;
                    if (finalValue === 0 && parseInt(originalValue) > 0) {
                        // Jangan reset ke 0 jika user sudah input nilai > 0
                        $input.val(originalValue);
                    }
                }
                
                // Update total jumlah resupply setelah validasi
                if (rowIndex !== undefined && rowIndex !== null && !isNaN(rowIndex)) {
                    updateTotalJumlahResupply(rowIndex);
                }
            });

            // Load stock data when barang is selected
            $('#setting_kd_barang').on('change', function() {
                var kdBarang = $(this).val();
                var kdLokasi = '<?php echo htmlspecialchars($kd_lokasi); ?>';
                
                if (kdBarang) {
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
                                $('#setting_stock_min').val(response.stock_min || 0);
                                $('#setting_stock_max').val(response.stock_max || 0);
                            }
                        },
                        error: function() {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: 'Terjadi kesalahan saat mengambil data stock!',
                                confirmButtonColor: '#e74c3c'
                            });
                        }
                    });
                } else {
                    $('#setting_stock_min').val('');
                    $('#setting_stock_max').val('');
                }
            });
        });

        function simpanSettingStock() {
            // Validasi form
            if (!$('#formSettingStock')[0].checkValidity()) {
                $('#formSettingStock')[0].reportValidity();
                return;
            }

            var kdBarang = $('#setting_kd_barang').val();
            var stockMin = parseInt($('#setting_stock_min').val()) || 0;
            var stockMax = parseInt($('#setting_stock_max').val()) || 0;

            // Validasi stock min tidak boleh lebih besar dari stock max
            if (stockMin > stockMax) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error Validasi!',
                    text: 'Stock Min tidak boleh lebih besar dari Stock Max!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }

            // Konfirmasi sebelum simpan
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi',
                text: 'Apakah Anda yakin ingin menyimpan setting stock ini?',
                showCancelButton: true,
                confirmButtonText: 'Ya, Simpan',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    // AJAX request untuk simpan
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: {
                            action: 'update_stock_setting',
                            kd_barang: kdBarang,
                            jumlah_min_stock: stockMin,
                            jumlah_max_stock: stockMax
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Berhasil!',
                                    text: response.message,
                                    confirmButtonColor: '#667eea',
                                    timer: 1500,
                                    timerProgressBar: true
                                }).then(() => {
                                    // Tutup modal dan reload halaman
                                    $('#modalSettingStock').modal('hide');
                                    location.reload();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Gagal!',
                                    text: response.message,
                                    confirmButtonColor: '#e74c3c'
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            var errorMessage = 'Terjadi kesalahan saat menyimpan setting stock!';
                            
                            // Coba parse error response jika ada
                            try {
                                var errorResponse = JSON.parse(xhr.responseText);
                                if (errorResponse.message) {
                                    errorMessage = errorResponse.message;
                                }
                            } catch (e) {
                                // Jika tidak bisa parse, gunakan error default
                                if (xhr.status === 0) {
                                    errorMessage = 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.';
                                } else if (xhr.status === 500) {
                                    errorMessage = 'Terjadi kesalahan server. Silakan hubungi administrator.';
                                }
                            }
                            
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: errorMessage,
                                confirmButtonColor: '#e74c3c'
                            });
                        }
                    });
                }
            });
        }

        function bukaModalResupply() {
            // Cek apakah ada checkbox yang dipilih
            var selectedCheckboxes = $('.row-checkbox:checked');
            
            if (selectedCheckboxes.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Pilih minimal satu barang dari tabel untuk di-resupply!',
                    confirmButtonColor: '#667eea'
                });
                return;
            }
            
            // Kumpulkan kd_barang yang dipilih
            var kdBarangList = [];
            selectedCheckboxes.each(function() {
                kdBarangList.push($(this).val());
            });
            
            // Load data barang yang dipilih
            $.ajax({
                url: '',
                method: 'GET',
                data: {
                    get_resupply_data: '1',
                    kd_barang: kdBarangList
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data.length > 0) {
                        // Render tabel resupply
                        renderTabelResupply(response.data);
                        // Buka modal
                        $('#modalResupply').modal('show');
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: 'Gagal memuat data barang!',
                            confirmButtonColor: '#e74c3c'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Terjadi kesalahan saat memuat data barang!',
                        confirmButtonColor: '#e74c3c'
                    });
                }
            });
        }
        
        function renderTabelResupply(data) {
            var tbody = $('#tbodyResupply');
            tbody.empty();
            
            if (data.length === 0) {
                tbody.append('<tr><td colspan="14" class="text-center text-muted">Tidak ada data</td></tr>');
                return;
            }
            
            data.forEach(function(item, index) {
                // Load batch expired untuk setiap barang
                loadBatchExpired(item.kd_barang, index, item);
            });
        }
        
        function loadBatchExpired(kdBarang, index, itemData) {
            $.ajax({
                url: '',
                method: 'GET',
                data: {
                    get_batch_expired: '1',
                    kd_barang: kdBarang
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        renderRowWithBatch(itemData, index, response.batches);
                    } else {
                        // Jika tidak ada batch, render row tanpa batch
                        renderRowWithBatch(itemData, index, []);
                    }
                },
                error: function() {
                    // Jika error, render row tanpa batch
                    renderRowWithBatch(itemData, index, []);
                }
            });
        }
        
        function renderRowWithBatch(item, index, batches) {
            var tbody = $('#tbodyResupply');
            
            // Simpan data batches di row untuk akses nanti
            var batchesJson = JSON.stringify(batches);
            
            // Buat dropdown batch
            var batchSelect = '<select class="form-select form-select-sm batch-select" data-index="' + index + '" style="width: 200px; min-width: 150px;">' +
                '<option value="">-- Pilih Batch --</option>';
            
            batches.forEach(function(batch) {
                var batchLabel = batch.id_pesan_barang + ' | Exp: ' + batch.tgl_expired_display + ' | Sisa: ' + numberFormat(batch.sisa_stock_dus) + ' dus';
                batchSelect += '<option value="' + escapeHtml(batch.id_pesan_barang) + '" data-sisa-stock="' + batch.sisa_stock_dus + '" data-tgl-expired="' + escapeHtml(batch.tgl_expired || '') + '">' + escapeHtml(batchLabel) + '</option>';
            });
            
            batchSelect += '</select>';
            
            // Buat container untuk batch yang dipilih dan input jumlah per batch
            var batchContainer = '<div class="batch-container" data-index="' + index + '">' +
                batchSelect +
                '<div class="selected-batches mt-2" data-index="' + index + '"></div>' +
                '</div>';
            
            // Buat row menggunakan jQuery untuk menyimpan data batches dengan benar
            var $row = $('<tr>', {
                'data-kd-barang': item.kd_barang
            });
            
            // Simpan batches data sebagai JSON string di attribute
            $row.attr('data-batches', batchesJson);
            
            // Tambahkan kolom-kolom
            $row.append(
                $('<td>').text(item.kd_barang),
                $('<td>').text(item.nama_merek),
                $('<td>').text(item.nama_kategori),
                $('<td>').text(item.nama_barang),
                $('<td>').text(numberFormat(item.berat)),
                $('<td>').text(item.stock_min ? numberFormat(item.stock_min) : '-'),
                $('<td>').text(item.stock_max ? numberFormat(item.stock_max) : '-'),
                $('<td>').text(numberFormat(item.stock_sekarang)),
                $('<td>').text(numberFormat(item.satuan_perdus)),
                $('<td>').html(batchContainer),
                $('<td>').html('<input type="text" class="form-control form-control-sm jumlah-resupply-dus" value="0" data-index="' + index + '" style="width: 80px; background-color: #e9ecef; cursor: not-allowed;" readonly disabled>'),
                $('<td>', {
                    'class': 'jumlah-resupply-piece',
                    'data-index': index
                }).text('0'),
                $('<td>', {
                    'class': 'jumlah-stock-akhir',
                    'data-index': index
                }).text(numberFormat(item.stock_sekarang)),
                $('<td>').html('<input type="checkbox" class="form-check-input isi-penuh" data-index="' + index + '" data-stock-max="' + item.stock_max + '" data-stock-sekarang="' + item.stock_sekarang + '" data-satuan-perdus="' + item.satuan_perdus + '" data-stock-gudang="' + (item.stock_gudang || 0) + '" data-satuan-gudang="' + (item.satuan_gudang || 'PIECES') + '">')
            );
            
            tbody.append($row);
            
            // Attach event listeners untuk batch select
            attachBatchEventListeners(index);
            
            // Attach event listeners untuk resupply
            attachResupplyEventListeners();
        }
        
        function attachBatchEventListeners(index) {
            // Event listener untuk memilih batch
            $(document).off('change', '.batch-select[data-index="' + index + '"]').on('change', '.batch-select[data-index="' + index + '"]', function() {
                var selectedOption = $(this).find('option:selected');
                var idPesanBarang = selectedOption.val();
                var sisaStock = parseInt(selectedOption.data('sisa-stock')) || 0;
                var tglExpired = selectedOption.data('tgl-expired') || '';
                
                if (!idPesanBarang) {
                    return;
                }
                
                // Cek apakah batch sudah dipilih sebelumnya
                var batchContainer = $(this).closest('.batch-container').find('.selected-batches');
                var existingBatch = batchContainer.find('[data-id-pesan="' + escapeHtml(idPesanBarang) + '"]');
                
                if (existingBatch.length > 0) {
                    // Batch sudah dipilih, tidak perlu ditambahkan lagi
                    $(this).val('');
                    return;
                }
                
                // Format tanggal expired untuk display
                var tglExpiredDisplay = '-';
                if (tglExpired) {
                    var dateExpired = new Date(tglExpired);
                    var bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                    tglExpiredDisplay = dateExpired.getDate() + ' ' + bulan[dateExpired.getMonth()] + ' ' + dateExpired.getFullYear();
                }
                
                // Tambahkan batch yang dipilih
                var batchItem = $('<div>', {
                    'class': 'd-flex align-items-center gap-2 mb-2 p-2 border rounded',
                    'data-id-pesan': idPesanBarang,
                    'data-sisa-stock': sisaStock
                });
                
                var batchInfo = $('<div>', {
                    'class': 'flex-grow-1'
                }).append(
                    $('<small>', {
                        'class': 'd-block fw-bold',
                        'text': idPesanBarang
                    })
                ).append(
                    $('<small>', {
                        'class': 'text-muted',
                        'text': 'Exp: ' + tglExpiredDisplay + ' | Sisa: ' + numberFormat(sisaStock) + ' dus'
                    })
                );
                
                var batchInput = $('<input>', {
                    'type': 'number',
                    'class': 'form-control form-control-sm batch-jumlah',
                    'min': 0,
                    'max': sisaStock,
                    'value': 0,
                    'step': 1,
                    'style': 'width: 80px;',
                    'data-id-pesan': idPesanBarang,
                    'data-index': index
                });
                
                var removeBtn = $('<button>', {
                    'type': 'button',
                    'class': 'btn btn-sm btn-danger remove-batch',
                    'data-id-pesan': idPesanBarang,
                    'data-index': index,
                    'text': '×'
                });
                
                batchItem.append(batchInfo).append(batchInput).append(removeBtn);
                batchContainer.append(batchItem);
                
                // Reset select
                $(this).val('');
                
                // Update total jumlah resupply
                updateTotalJumlahResupply(index);
            });
        }
        
        function updateTotalJumlahResupply(index) {
            var row = $('tr[data-kd-barang]').eq(index);
            if (row.length === 0) return; // Row tidak ditemukan
            
            var batchContainer = row.find('.batch-container[data-index="' + index + '"]');
            var selectedBatches = batchContainer.find('.selected-batches');
            
            // Hitung total dari semua batch yang dipilih
            var totalDus = 0;
            selectedBatches.find('.batch-jumlah').each(function() {
                var $input = $(this);
                // Ambil nilai langsung dari input, jangan parse dulu untuk menghindari kehilangan nilai
                var val = $input.val();
                // Pastikan nilai tidak kosong atau null
                if (val !== '' && val !== null && val !== undefined) {
                    var jumlah = parseInt(val) || 0;
                    if (!isNaN(jumlah)) {
                        totalDus += jumlah;
                    }
                }
            });
            
            // Update input total resupply (dus) - readonly, hanya untuk display
            // Jangan ubah nilai input batch, hanya update total
            var $totalInput = row.find('.jumlah-resupply-dus[data-index="' + index + '"]');
            if ($totalInput.length > 0) {
                // Pastikan input tetap disabled/readonly
                $totalInput.prop('disabled', true);
                $totalInput.prop('readonly', true);
                $totalInput.val(totalDus);
            }
            
            // Hitung ulang resupply
            calculateResupply(index);
        }
        
        function isiPenuhOtomatis(index) {
            var row = $('tr[data-kd-barang]').eq(index);
            var stockMax = parseInt(row.find('.isi-penuh').data('stock-max')) || 0;
            var stockSekarang = parseInt(row.find('.isi-penuh').data('stock-sekarang')) || 0;
            var satuanPerdus = parseInt(row.find('.isi-penuh').data('satuan-perdus')) || 1;
            
            // Hitung kebutuhan dalam pieces
            var kebutuhanPieces = stockMax - stockSekarang;
            
            if (kebutuhanPieces <= 0) {
                Swal.fire({
                    icon: 'info',
                    title: 'Info',
                    text: 'Stock sudah mencapai maksimum atau melebihi maksimum.',
                    confirmButtonColor: '#667eea'
                });
                row.find('.isi-penuh').prop('checked', false);
                return;
            }
            
            // Konversi kebutuhan ke dus (pembulatan ke atas)
            var kebutuhanDus = Math.ceil(kebutuhanPieces / satuanPerdus);
            
            // Ambil data batches dari row
            var batchesJson = row.attr('data-batches');
            if (!batchesJson || batchesJson.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Tidak ada batch tersedia untuk barang ini.',
                    confirmButtonColor: '#667eea'
                });
                row.find('.isi-penuh').prop('checked', false);
                return;
            }
            
            // Parse JSON batches
            var batches;
            try {
                batches = JSON.parse(batchesJson);
            } catch (e) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Gagal memproses data batch.',
                    confirmButtonColor: '#e74c3c'
                });
                row.find('.isi-penuh').prop('checked', false);
                return;
            }
            
            if (!batches || batches.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Tidak ada batch tersedia untuk barang ini.',
                    confirmButtonColor: '#667eea'
                });
                row.find('.isi-penuh').prop('checked', false);
                return;
            }
            
            // Sort batch berdasarkan expired date (terdekat dulu)
            batches.sort(function(a, b) {
                // Batch dengan expired date null atau kosong dianggap paling akhir
                if (!a.tgl_expired && !b.tgl_expired) return 0;
                if (!a.tgl_expired) return 1;
                if (!b.tgl_expired) return -1;
                
                var dateA = new Date(a.tgl_expired);
                var dateB = new Date(b.tgl_expired);
                return dateA - dateB;
            });
            
            // Bersihkan batch yang sudah dipilih sebelumnya
            var batchContainer = row.find('.batch-container[data-index="' + index + '"]');
            var selectedBatches = batchContainer.find('.selected-batches');
            selectedBatches.empty();
            
            // Pilih batch secara otomatis dan isi jumlahnya
            var sisaKebutuhan = kebutuhanDus;
            var batchDipilih = false;
            
            for (var i = 0; i < batches.length && sisaKebutuhan > 0; i++) {
                var batch = batches[i];
                var sisaStockBatch = batch.sisa_stock_dus;
                
                if (sisaStockBatch <= 0) {
                    continue; // Skip batch yang tidak ada stock
                }
                
                // Hitung berapa yang akan diambil dari batch ini
                var jumlahAmbil = Math.min(sisaStockBatch, sisaKebutuhan);
                
                // Tambahkan batch ke selected batches
                var tglExpiredDisplay = batch.tgl_expired_display || '-';
                if (batch.tgl_expired) {
                    var dateExpired = new Date(batch.tgl_expired);
                    var bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
                    tglExpiredDisplay = dateExpired.getDate() + ' ' + bulan[dateExpired.getMonth()] + ' ' + dateExpired.getFullYear();
                }
                
                var batchItem = $('<div>', {
                    'class': 'd-flex align-items-center gap-2 mb-2 p-2 border rounded',
                    'data-id-pesan': batch.id_pesan_barang,
                    'data-sisa-stock': sisaStockBatch
                });
                
                var batchInfo = $('<div>', {
                    'class': 'flex-grow-1'
                }).append(
                    $('<small>', {
                        'class': 'd-block fw-bold',
                        'text': batch.id_pesan_barang
                    })
                ).append(
                    $('<small>', {
                        'class': 'text-muted',
                        'text': 'Exp: ' + tglExpiredDisplay + ' | Sisa: ' + numberFormat(sisaStockBatch) + ' dus'
                    })
                );
                
                var batchInput = $('<input>', {
                    'type': 'number',
                    'class': 'form-control form-control-sm batch-jumlah',
                    'min': 0,
                    'max': sisaStockBatch,
                    'value': jumlahAmbil,
                    'step': 1,
                    'style': 'width: 80px;',
                    'data-id-pesan': batch.id_pesan_barang,
                    'data-index': index
                });
                
                var removeBtn = $('<button>', {
                    'type': 'button',
                    'class': 'btn btn-sm btn-danger remove-batch',
                    'data-id-pesan': batch.id_pesan_barang,
                    'data-index': index,
                    'text': '×'
                });
                
                batchItem.append(batchInfo).append(batchInput).append(removeBtn);
                selectedBatches.append(batchItem);
                
                sisaKebutuhan -= jumlahAmbil;
                batchDipilih = true;
            }
            
            if (!batchDipilih || sisaKebutuhan > 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Stock batch tidak mencukupi untuk mengisi penuh. Batch yang tersedia telah dipilih.',
                    confirmButtonColor: '#667eea'
                });
            }
            
            // Update total jumlah resupply
            updateTotalJumlahResupply(index);
        }
        
        function attachResupplyEventListeners() {
            // Event listener untuk checkbox "Isi Penuh"
            // Catatan: Input jumlah-resupply-dus sudah readonly, tidak perlu event listener
            $(document).off('change', '.isi-penuh').on('change', '.isi-penuh', function() {
                var $checkbox = $(this);
                var index = $checkbox.data('index');
                
                if ($checkbox.is(':checked')) {
                    // Fungsi "Isi Penuh" - otomatis pilih batch dan isi jumlah
                    isiPenuhOtomatis(index);
                } else {
                    // Saat uncheck, jangan reset batch yang sudah dipilih
                    // Biarkan batch dan jumlah tetap ada, hanya update total
                    var row = $('tr[data-kd-barang]').eq(index);
                    if (row.length > 0) {
                        updateTotalJumlahResupply(index);
                    }
                }
            });
        }
        
        function calculateResupply(index) {
            var row = $('tr[data-kd-barang]').eq(index);
            var jumlahDus = parseInt(row.find('.jumlah-resupply-dus').val()) || 0;
            var satuanPerdus = parseInt(row.find('.isi-penuh').data('satuan-perdus')) || 1;
            var stockSekarang = parseInt(row.find('.isi-penuh').data('stock-sekarang')) || 0;
            var stockMax = parseInt(row.find('.isi-penuh').data('stock-max')) || 0;
            
            // Hitung jumlah resupply (piece)
            var jumlahPiece = jumlahDus * satuanPerdus;
            
            // Hitung stock akhir
            var stockAkhir = stockSekarang + jumlahPiece;
            
            // Update tampilan (tidak ada validasi yang memblokir input)
            row.find('.jumlah-resupply-piece').text(numberFormat(jumlahPiece));
            row.find('.jumlah-stock-akhir').text(numberFormat(stockAkhir));
            
            // Tampilkan peringatan visual jika melebihi stock max (tapi tidak memblokir)
            if (stockMax > 0 && stockAkhir > stockMax) {
                // Tambahkan class warning untuk styling (opsional)
                row.find('.jumlah-stock-akhir').addClass('text-danger fw-bold');
            } else {
                row.find('.jumlah-stock-akhir').removeClass('text-danger fw-bold');
            }
        }
        
        function konfirmasiSimpanResupply() {
            // Validasi minimal ada satu barang dengan jumlah resupply > 0
            var hasValidData = false;
            var resupplyData = [];
            var hasExceedMax = false;
            var exceedItems = [];
            
            $('tr[data-kd-barang]').each(function() {
                var kdBarang = $(this).data('kd-barang');
                var jumlahDus = parseInt($(this).find('.jumlah-resupply-dus').val()) || 0;
                var stockMax = parseInt($(this).find('.isi-penuh').data('stock-max')) || 0;
                var stockSekarang = parseInt($(this).find('.isi-penuh').data('stock-sekarang')) || 0;
                var satuanPerdus = parseInt($(this).find('.isi-penuh').data('satuan-perdus')) || 1;
                
                if (jumlahDus > 0) {
                    hasValidData = true;
                    var jumlahPiece = jumlahDus * satuanPerdus;
                    var stockAkhir = stockSekarang + jumlahPiece;
                    
                    // Cek apakah melebihi stock max
                    if (stockMax > 0 && stockAkhir > stockMax) {
                        hasExceedMax = true;
                        var namaBarang = $(this).find('td').eq(3).text(); // Kolom Nama Barang
                        exceedItems.push(namaBarang + ' (Stock akhir: ' + numberFormat(stockAkhir) + ', Stock Max: ' + numberFormat(stockMax) + ')');
                    }
                    
                    // Kumpulkan data batch untuk barang ini
                    var batches = [];
                    var batchContainer = $(this).find('.batch-container');
                    batchContainer.find('.selected-batches .batch-jumlah').each(function() {
                        var idPesanBarang = $(this).data('id-pesan');
                        var jumlahBatch = parseInt($(this).val()) || 0;
                        
                        if (jumlahBatch > 0) {
                            batches.push({
                                id_pesan_barang: idPesanBarang,
                                jumlah_dus: jumlahBatch
                            });
                        }
                    });
                    
                    resupplyData.push({
                        kd_barang: kdBarang,
                        jumlah_resupply_dus: jumlahDus,
                        batches: batches
                    });
                }
            });
            
            if (!hasValidData) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Minimal satu barang harus memiliki jumlah resupply lebih dari 0!',
                    confirmButtonColor: '#667eea'
                });
                return;
            }
            
            // Cek stock gudang terlebih dahulu
            $.ajax({
                url: '',
                method: 'GET',
                data: {
                    cek_stock_gudang: '1',
                    resupply_data: JSON.stringify(resupplyData)
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.ada_stock_tidak_cukup) {
                        // Ada stock yang tidak cukup
                        var message = 'Stock gudang tidak mencukupi untuk beberapa barang:\n\n';
                        response.stock_tidak_cukup.forEach(function(item) {
                            if (item.jumlah_resupply_dus) {
                                message += item.nama_barang + ': Butuh ' + numberFormat(item.jumlah_resupply_dus) + ' dus, Stock gudang: ' + numberFormat(item.stock_gudang_dus) + ' dus\n';
                            } else {
                                message += item.nama_barang + ': Stock gudang: ' + numberFormat(item.stock_gudang_dus) + ' dus\n';
                            }
                        });
                        message += '\nApakah Anda yakin ingin melanjutkan?';
                        
                        Swal.fire({
                            icon: 'error',
                            title: 'Stock Gudang Tidak Cukup!',
                            html: message.replace(/\n/g, '<br>'),
                            showCancelButton: true,
                            confirmButtonText: 'Ya, Lanjutkan',
                            cancelButtonText: 'Batal',
                            confirmButtonColor: '#ffc107',
                            cancelButtonColor: '#6c757d'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                konfirmasiFinalResupply(resupplyData, hasExceedMax, exceedItems);
                            }
                        });
                    } else {
                        // Stock gudang cukup, lanjutkan ke konfirmasi normal
                        konfirmasiFinalResupply(resupplyData, hasExceedMax, exceedItems);
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Terjadi kesalahan saat mengecek stock gudang!',
                        confirmButtonColor: '#e74c3c'
                    });
                }
            });
        }
        
        function konfirmasiFinalResupply(resupplyData, hasExceedMax, exceedItems) {
            // Jika ada yang melebihi stock max, tampilkan konfirmasi khusus
            if (hasExceedMax) {
                var message = 'Beberapa barang akan melebihi Stock Max:\n\n' + exceedItems.join('\n') + '\n\nApakah Anda yakin ingin melanjutkan?';
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan Stock Max!',
                    html: message.replace(/\n/g, '<br>'),
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Lanjutkan',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#ffc107',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Konfirmasi final
                        Swal.fire({
                            icon: 'question',
                            title: 'Konfirmasi Final',
                            text: 'Apakah Anda yakin ingin menyimpan resupply ini?',
                            showCancelButton: true,
                            confirmButtonText: 'Ya, Simpan',
                            cancelButtonText: 'Batal',
                            confirmButtonColor: '#667eea',
                            cancelButtonColor: '#6c757d'
                        }).then((result2) => {
                            if (result2.isConfirmed) {
                                simpanResupply(resupplyData);
                            }
                        });
                    }
                });
            } else {
                // Konfirmasi normal jika tidak ada yang melebihi stock max
                Swal.fire({
                    icon: 'question',
                    title: 'Konfirmasi Final',
                    text: 'Apakah Anda yakin ingin menyimpan resupply ini?',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Simpan',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#667eea',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        simpanResupply(resupplyData);
                    }
                });
            }
        }
        
        function simpanResupply(resupplyData) {
            $.ajax({
                url: '',
                method: 'POST',
                data: {
                    action: 'simpan_resupply',
                    resupply_data: resupplyData
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Cek apakah ada barang yang gagal disimpan
                        if (response.stock_habis && response.stock_habis.length > 0) {
                            // Ada yang berhasil dan ada yang gagal
                            var message = 'Resupply berhasil dibuat dengan ID: <strong>' + response.id_transfer + '</strong>\n\n';
                            message += 'Barang yang berhasil disimpan: <strong>' + response.barang_berhasil + ' item</strong>\n';
                            message += 'Barang yang tidak dapat disimpan: <strong>' + response.barang_gagal + ' item</strong> (stock gudang habis/tidak mencukupi)\n\n';
                            message += 'Barang yang tidak dapat disimpan:\n';
                            response.stock_habis.forEach(function(item) {
                                message += '- ' + item.nama_barang + ' (' + item.kd_barang + '): ' + item.pesan;
                                if (item.jumlah_resupply_dus) {
                                    message += ' (Butuh: ' + numberFormat(item.jumlah_resupply_dus) + ' dus, Stock gudang: ' + numberFormat(item.stock_gudang_dus) + ' dus)';
                                } else {
                                    message += ' (Stock gudang: ' + numberFormat(item.stock_gudang_dus) + ' dus)';
                                }
                                message += '\n';
                            });
                            
                            Swal.fire({
                                icon: 'warning',
                                title: 'Resupply Berhasil (Sebagian)',
                                html: message.replace(/\n/g, '<br>'),
                                confirmButtonColor: '#ffc107',
                                width: '600px'
                            }).then(() => {
                                // Tutup modal dan reload halaman
                                $('#modalResupply').modal('hide');
                                location.reload();
                            });
                        } else {
                            // Semua berhasil
                            Swal.fire({
                                icon: 'success',
                                title: 'Berhasil!',
                                text: response.message,
                                confirmButtonColor: '#667eea',
                                timer: 2000,
                                timerProgressBar: true
                            }).then(() => {
                                // Tutup modal dan reload halaman
                                $('#modalResupply').modal('hide');
                                location.reload();
                            });
                        }
                    } else {
                        // Cek apakah ada stock_habis untuk format khusus
                        if (response.stock_habis && response.stock_habis.length > 0) {
                            var message = 'Barang berikut tidak dapat di-resupply karena stock gudang habis atau tidak mencukupi:\n\n';
                            response.stock_habis.forEach(function(item) {
                                message += '- ' + item.nama_barang + ' (' + item.kd_barang + '): ' + item.pesan;
                                if (item.jumlah_resupply_dus) {
                                    message += ' (Butuh: ' + numberFormat(item.jumlah_resupply_dus) + ' dus, Stock gudang: ' + numberFormat(item.stock_gudang_dus) + ' dus)';
                                } else {
                                    message += ' (Stock gudang: ' + numberFormat(item.stock_gudang_dus) + ' dus)';
                                }
                                message += '\n';
                            });
                            
                            Swal.fire({
                                icon: 'error',
                                title: 'Stock Gudang Habis!',
                                html: message.replace(/\n/g, '<br>'),
                                confirmButtonColor: '#e74c3c',
                                width: '600px'
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Gagal!',
                                text: response.message,
                                confirmButtonColor: '#e74c3c'
                            });
                        }
                    }
                },
                error: function(xhr, status, error) {
                    var errorMessage = 'Terjadi kesalahan saat menyimpan resupply!';
                    
                    try {
                        var errorResponse = JSON.parse(xhr.responseText);
                        if (errorResponse.message) {
                            errorMessage = errorResponse.message;
                        }
                    } catch (e) {
                        if (xhr.status === 0) {
                            errorMessage = 'Tidak dapat terhubung ke server. Periksa koneksi internet Anda.';
                        } else if (xhr.status === 500) {
                            errorMessage = 'Terjadi kesalahan server. Silakan hubungi administrator.';
                        }
                    }
                    
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: errorMessage,
                        confirmButtonColor: '#e74c3c'
                    });
                }
            });
        }
        
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text ? text.replace(/[&<>"']/g, function(m) { return map[m]; }) : '';
        }
        
        function numberFormat(num) {
            return num ? num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '0';
        }
        
        function riwayatResupply(kdBarang) {
            // Redirect ke halaman riwayat resupply
            window.location.href = 'riwayat_resupply.php?kd_barang=' + encodeURIComponent(kdBarang) + '&kd_lokasi=<?php echo urlencode($kd_lokasi); ?>';
        }
    </script>
</body>
</html>

