<?php
session_start();
require_once '../dbconnect.php';
require_once '../includes/uuid_generator.php';

// Cek apakah user sudah login dan adalah staff toko
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Cek apakah user adalah staff toko (format ID: TOKO+UUID)
if (substr($user_id, 0, 4) != 'TOKO') {
    header("Location: ../index.php");
    exit();
}

// Get user info dan lokasi toko
$query_user = "SELECT u.ID_USERS, u.KD_LOKASI, ml.NAMA_LOKASI 
               FROM USERS u
               LEFT JOIN MASTER_LOKASI ml ON u.KD_LOKASI = ml.KD_LOKASI
               WHERE u.ID_USERS = ?";
$stmt_user = $conn->prepare($query_user);
$stmt_user->bind_param("s", $user_id);
$stmt_user->execute();
$result_user = $stmt_user->get_result();

if ($result_user->num_rows == 0) {
    header("Location: ../index.php");
    exit();
}

$user_data = $result_user->fetch_assoc();
$kd_lokasi = $user_data['KD_LOKASI'];
$nama_lokasi = $user_data['NAMA_LOKASI'] ?? 'Toko';

// Handle AJAX request untuk get products
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['get_products'])) {
    header('Content-Type: application/json');
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $filter_merek = isset($_GET['filter_merek']) ? trim($_GET['filter_merek']) : '';
    $filter_kategori = isset($_GET['filter_kategori']) ? trim($_GET['filter_kategori']) : '';
    $filter_berat = isset($_GET['filter_berat']) ? trim($_GET['filter_berat']) : '';
    
    // Query untuk mendapatkan produk yang ada stock di toko ini
    $query_products = "SELECT 
        mb.KD_BARANG,
        mb.NAMA_BARANG,
        mb.BERAT,
        mb.HARGA_JUAL_BARANG_PIECES,
        mb.SATUAN_PERDUS,
        mb.GAMBAR_BARANG,
        COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
        COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
        COALESCE(s.JUMLAH_BARANG, 0) as STOCK_SEKARANG,
        mb.KD_MEREK_BARANG,
        mb.KD_KATEGORI_BARANG
    FROM STOCK s
    INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
    LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
    LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
    WHERE s.KD_LOKASI = ? 
        AND mb.STATUS = 'AKTIF'
        AND s.JUMLAH_BARANG > 0
        AND s.SATUAN = 'PIECES'";
    
    $params = [$kd_lokasi];
    $types = "s";
    
    // Add search filter
    if (!empty($search)) {
        $query_products .= " AND (mb.NAMA_BARANG LIKE ? OR mb.KD_BARANG LIKE ?)";
        $search_param = "%{$search}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "ss";
    }
    
    // Add filter merek
    if (!empty($filter_merek)) {
        $query_products .= " AND mb.KD_MEREK_BARANG = ?";
        $params[] = $filter_merek;
        $types .= "s";
    }
    
    // Add filter kategori
    if (!empty($filter_kategori)) {
        $query_products .= " AND mb.KD_KATEGORI_BARANG = ?";
        $params[] = $filter_kategori;
        $types .= "s";
    }
    
    // Add filter berat
    if (!empty($filter_berat)) {
        $query_products .= " AND mb.BERAT = ?";
        $params[] = intval($filter_berat);
        $types .= "i";
    }
    
    $query_products .= " ORDER BY mb.NAMA_BARANG ASC";
    
    $stmt_products = $conn->prepare($query_products);
    if (!$stmt_products) {
        echo json_encode(['success' => false, 'message' => 'Gagal prepare query: ' . $conn->error]);
        exit();
    }
    
    if (count($params) > 1) {
        $stmt_products->bind_param($types, ...$params);
    } else {
        $stmt_products->bind_param($types, $kd_lokasi);
    }
    
    if (!$stmt_products->execute()) {
        echo json_encode(['success' => false, 'message' => 'Gagal execute query: ' . $stmt_products->error]);
        exit();
    }
    
    $result_products = $stmt_products->get_result();
    $products = [];
    
    while ($row = $result_products->fetch_assoc()) {
        $products[] = [
            'kd_barang' => $row['KD_BARANG'],
            'nama_barang' => $row['NAMA_BARANG'],
            'berat' => $row['BERAT'],
            'harga_jual' => floatval($row['HARGA_JUAL_BARANG_PIECES']),
            'stock_sekarang' => intval($row['STOCK_SEKARANG']),
            'nama_merek' => $row['NAMA_MEREK'],
            'nama_kategori' => $row['NAMA_KATEGORI'],
            'gambar_barang' => $row['GAMBAR_BARANG'] ?? ''
        ];
    }
    
    echo json_encode(['success' => true, 'products' => $products]);
    exit();
}

// Handle AJAX request untuk get filter options (dinamis berdasarkan search/filter aktif)
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['get_filter_options'])) {
    header('Content-Type: application/json');
    
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $filter_merek = isset($_GET['filter_merek']) ? trim($_GET['filter_merek']) : '';
    $filter_kategori = isset($_GET['filter_kategori']) ? trim($_GET['filter_kategori']) : '';
    $filter_berat = isset($_GET['filter_berat']) ? trim($_GET['filter_berat']) : '';
    
    // Base query untuk filter options
    $base_query = "FROM STOCK s
        INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
        LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
        LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
        WHERE s.KD_LOKASI = ? 
            AND mb.STATUS = 'AKTIF'
            AND s.JUMLAH_BARANG > 0
            AND s.SATUAN = 'PIECES'";
    
    $params = [$kd_lokasi];
    $types = "s";
    
    // Add search filter
    if (!empty($search)) {
        $base_query .= " AND (mb.NAMA_BARANG LIKE ? OR mb.KD_BARANG LIKE ?)";
        $search_param = "%{$search}%";
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= "ss";
    }
    
    // Add filter merek (jika sudah dipilih, tetap filter)
    if (!empty($filter_merek)) {
        $base_query .= " AND mb.KD_MEREK_BARANG = ?";
        $params[] = $filter_merek;
        $types .= "s";
    }
    
    // Add filter kategori (jika sudah dipilih, tetap filter)
    if (!empty($filter_kategori)) {
        $base_query .= " AND mb.KD_KATEGORI_BARANG = ?";
        $params[] = $filter_kategori;
        $types .= "s";
    }
    
    // Add filter berat (jika sudah dipilih, tetap filter)
    if (!empty($filter_berat)) {
        $base_query .= " AND mb.BERAT = ?";
        $params[] = intval($filter_berat);
        $types .= "i";
    }
    
    $filter_options = [
        'merek' => [],
        'kategori' => [],
        'berat' => []
    ];
    
    // Get Merek options
    $query_merek = "SELECT DISTINCT mb.KD_MEREK_BARANG, mm.NAMA_MEREK " . $base_query . " AND mb.KD_MEREK_BARANG IS NOT NULL ORDER BY mm.NAMA_MEREK ASC";
    $stmt_merek = $conn->prepare($query_merek);
    if ($stmt_merek) {
        if (count($params) > 1) {
            $stmt_merek->bind_param($types, ...$params);
        } else {
            $stmt_merek->bind_param($types, $kd_lokasi);
        }
        $stmt_merek->execute();
        $result_merek = $stmt_merek->get_result();
        while ($row = $result_merek->fetch_assoc()) {
            $filter_options['merek'][] = [
                'kd_merek' => $row['KD_MEREK_BARANG'],
                'nama_merek' => $row['NAMA_MEREK']
            ];
        }
    }
    
    // Get Kategori options
    $query_kategori = "SELECT DISTINCT mb.KD_KATEGORI_BARANG, mk.NAMA_KATEGORI " . $base_query . " AND mb.KD_KATEGORI_BARANG IS NOT NULL ORDER BY mk.NAMA_KATEGORI ASC";
    $stmt_kategori = $conn->prepare($query_kategori);
    if ($stmt_kategori) {
        if (count($params) > 1) {
            $stmt_kategori->bind_param($types, ...$params);
        } else {
            $stmt_kategori->bind_param($types, $kd_lokasi);
        }
        $stmt_kategori->execute();
        $result_kategori = $stmt_kategori->get_result();
        while ($row = $result_kategori->fetch_assoc()) {
            $filter_options['kategori'][] = [
                'kd_kategori' => $row['KD_KATEGORI_BARANG'],
                'nama_kategori' => $row['NAMA_KATEGORI']
            ];
        }
    }
    
    // Get Berat options
    $query_berat = "SELECT DISTINCT mb.BERAT " . $base_query . " AND mb.BERAT IS NOT NULL ORDER BY mb.BERAT ASC";
    $stmt_berat = $conn->prepare($query_berat);
    if ($stmt_berat) {
        if (count($params) > 1) {
            $stmt_berat->bind_param($types, ...$params);
        } else {
            $stmt_berat->bind_param($types, $kd_lokasi);
        }
        $stmt_berat->execute();
        $result_berat = $stmt_berat->get_result();
        while ($row = $result_berat->fetch_assoc()) {
            $filter_options['berat'][] = [
                'berat' => $row['BERAT']
            ];
        }
    }
    
    echo json_encode(['success' => true, 'filter_options' => $filter_options]);
    exit();
}

// Handle AJAX request untuk process payment
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'process_payment') {
    header('Content-Type: application/json');
    
    // $user_id dan $kd_lokasi sudah didefinisikan di awal file (sama seperti toko/barang_masuk.php)
    // Tidak perlu verifikasi tambahan karena sudah dicek di awal file
    
    $cart_items = isset($_POST['cart_items']) ? json_decode($_POST['cart_items'], true) : [];
    
    if (empty($cart_items)) {
        echo json_encode(['success' => false, 'message' => 'Keranjang kosong!']);
        exit();
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Generate ID_NOTA_JUAL dengan format NTJB+UUID (total 16 karakter: NTJB=4, UUID=12)
        $id_nota_jual = '';
        $maxAttempts = 100;
        $attempt = 0;
        do {
            $uuid = ShortIdGenerator::generate(12, '');
            $id_nota_jual = 'NTJB' . $uuid;
            $attempt++;
            if (!checkUUIDExists($conn, 'NOTA_JUAL', 'ID_NOTA_JUAL', $id_nota_jual)) {
                break;
            }
        } while ($attempt < $maxAttempts);
        
        if ($attempt >= $maxAttempts) {
            throw new Exception('Gagal generate ID nota jual!');
        }
        
        // Calculate totals
        $total = 0;
        foreach ($cart_items as $item) {
            $total += floatval($item['total']);
        }
        
        $ppn = $total * 0.11; // PPN 11%
        $grand_total = $total + $ppn;
        
        // Insert NOTA_JUAL
        $insert_nota = "INSERT INTO NOTA_JUAL (ID_NOTA_JUAL, ID_USERS, KD_LOKASI, GRAND_TOTAL, PAJAK) VALUES (?, ?, ?, ?, ?)";
        $stmt_nota = $conn->prepare($insert_nota);
        if (!$stmt_nota) {
            throw new Exception('Gagal prepare query nota: ' . $conn->error);
        }
        $stmt_nota->bind_param("sssdd", $id_nota_jual, $user_id, $kd_lokasi, $grand_total, $ppn);
        
        if (!$stmt_nota->execute()) {
            throw new Exception('Gagal insert nota jual: ' . $stmt_nota->error);
        }
        
        // Insert DETAIL_NOTA_JUAL dan update STOCK untuk setiap item
        foreach ($cart_items as $item) {
            $kd_barang = $item['kd_barang'];
            $jumlah_jual = intval($item['jumlah']);
            $harga_jual = floatval($item['harga']);
            
            // Generate ID_DNJB dengan format DNJB+UUID (total 16 karakter: DNJB=4, UUID=12)
            $id_dnjb = '';
            $attempt = 0;
            do {
                $uuid = ShortIdGenerator::generate(12, '');
                $id_dnjb = 'DNJB' . $uuid;
                $attempt++;
                if (!checkUUIDExists($conn, 'DETAIL_NOTA_JUAL', 'ID_DNJB', $id_dnjb)) {
                    break;
                }
            } while ($attempt < $maxAttempts);
            
            if ($attempt >= $maxAttempts) {
                throw new Exception('Gagal generate ID detail nota jual!');
            }
            
            // Insert DETAIL_NOTA_JUAL
            $insert_detail = "INSERT INTO DETAIL_NOTA_JUAL (ID_DNJB, KD_BARANG, ID_NOTA_JUAL, JUMLAH_JUAL_BARANG, HARGA_JUAL_BARANG) VALUES (?, ?, ?, ?, ?)";
            $stmt_detail = $conn->prepare($insert_detail);
            if (!$stmt_detail) {
                throw new Exception('Gagal prepare query detail: ' . $conn->error);
            }
            $stmt_detail->bind_param("sssid", $id_dnjb, $kd_barang, $id_nota_jual, $jumlah_jual, $harga_jual);
            
            if (!$stmt_detail->execute()) {
                throw new Exception('Gagal insert detail nota jual: ' . $stmt_detail->error);
            }
            
            // Get previous stock (before update) untuk STOCK_HISTORY
            $query_previous_stock = "SELECT JUMLAH_BARANG FROM STOCK WHERE KD_BARANG = ? AND KD_LOKASI = ?";
            $stmt_previous = $conn->prepare($query_previous_stock);
            $stmt_previous->bind_param("ss", $kd_barang, $kd_lokasi);
            $stmt_previous->execute();
            $result_previous = $stmt_previous->get_result();
            $previous_stock_row = $result_previous->fetch_assoc();
            $jumlah_awal = $previous_stock_row ? intval($previous_stock_row['JUMLAH_BARANG']) : 0;
            
            // Hitung jumlah akhir (setelah update)
            $jumlah_akhir = $jumlah_awal - $jumlah_jual;
            if ($jumlah_akhir < 0) {
                $jumlah_akhir = 0;
            }
            
            // Insert STOCK_HISTORY per barang - SEBELUM UPDATE STOCK
            // REF mengacu ke ID_DNJB (detail nota jual) karena per barang
            $id_history = '';
            do {
                $id_history = ShortIdGenerator::generate(16, '');
            } while (checkUUIDExists($conn, 'STOCK_HISTORY', 'ID_HISTORY_STOCK', $id_history));
            
            $jumlah_perubahan = -$jumlah_jual; // Negative karena mengurangi stock
            $insert_history = "INSERT INTO STOCK_HISTORY 
                              (ID_HISTORY_STOCK, KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_AWAL, JUMLAH_PERUBAHAN, JUMLAH_AKHIR, TIPE_PERUBAHAN, REF, SATUAN)
                              VALUES (?, ?, ?, ?, ?, ?, ?, 'PENJUALAN', ?, 'PIECES')";
            $stmt_history = $conn->prepare($insert_history);
            if (!$stmt_history) {
                throw new Exception('Gagal prepare query insert history: ' . $conn->error);
            }
            // bind_param: s(1)=id_history, s(2)=kd_barang, s(3)=kd_lokasi, s(4)=user_id, 
            //            i(5)=jumlah_awal, i(6)=jumlah_perubahan, i(7)=jumlah_akhir, s(8)=id_dnjb
            // Total: 8 parameter (ssssiiis = 8 karakter)
            $stmt_history->bind_param("ssssiiis", $id_history, $kd_barang, $kd_lokasi, $user_id, $jumlah_awal, $jumlah_perubahan, $jumlah_akhir, $id_dnjb);
            
            if (!$stmt_history->execute()) {
                throw new Exception('Gagal insert history: ' . $stmt_history->error);
            }
            
            // Update STOCK (kurangi jumlah barang) - SETELAH INSERT STOCK_HISTORY
            $update_stock = "UPDATE STOCK SET JUMLAH_BARANG = JUMLAH_BARANG - ?, UPDATED_BY = ?, LAST_UPDATED = CURRENT_TIMESTAMP WHERE KD_BARANG = ? AND KD_LOKASI = ?";
            $stmt_stock = $conn->prepare($update_stock);
            if (!$stmt_stock) {
                throw new Exception('Gagal prepare query stock: ' . $conn->error);
            }
            $stmt_stock->bind_param("isss", $jumlah_jual, $user_id, $kd_barang, $kd_lokasi);
            
            if (!$stmt_stock->execute()) {
                throw new Exception('Gagal update stock: ' . $stmt_stock->error);
            }
        }
        
        // Commit transaction
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Pembayaran berhasil diproses!',
            'id_nota_jual' => $id_nota_jual,
            'grand_total' => $grand_total
        ]);
        exit();
        
    } catch (Exception $e) {
        // Rollback transaction
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'message' => 'Gagal memproses pembayaran: ' . $e->getMessage()
        ]);
        exit();
    }
}

// Get filter options (Merek, Kategori, Berat)
$query_merek = "SELECT DISTINCT mb.KD_MEREK_BARANG, mm.NAMA_MEREK 
                FROM STOCK s
                INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
                LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
                WHERE s.KD_LOKASI = ? AND mb.STATUS = 'AKTIF' AND s.JUMLAH_BARANG > 0 AND s.SATUAN = 'PIECES' AND mb.KD_MEREK_BARANG IS NOT NULL
                ORDER BY mm.NAMA_MEREK ASC";
$stmt_merek = $conn->prepare($query_merek);
$stmt_merek->bind_param("s", $kd_lokasi);
$stmt_merek->execute();
$result_merek = $stmt_merek->get_result();

$query_kategori = "SELECT DISTINCT mb.KD_KATEGORI_BARANG, mk.NAMA_KATEGORI 
                   FROM STOCK s
                   INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
                   LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
                   WHERE s.KD_LOKASI = ? AND mb.STATUS = 'AKTIF' AND s.JUMLAH_BARANG > 0 AND s.SATUAN = 'PIECES' AND mb.KD_KATEGORI_BARANG IS NOT NULL
                   ORDER BY mk.NAMA_KATEGORI ASC";
$stmt_kategori = $conn->prepare($query_kategori);
$stmt_kategori->bind_param("s", $kd_lokasi);
$stmt_kategori->execute();
$result_kategori = $stmt_kategori->get_result();

$query_berat = "SELECT DISTINCT mb.BERAT 
                FROM STOCK s
                INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
                WHERE s.KD_LOKASI = ? AND mb.STATUS = 'AKTIF' AND s.JUMLAH_BARANG > 0 AND s.SATUAN = 'PIECES' AND mb.BERAT IS NOT NULL
                ORDER BY mb.BERAT ASC";
$stmt_berat = $conn->prepare($query_berat);
$stmt_berat->bind_param("s", $kd_lokasi);
$stmt_berat->execute();
$result_berat = $stmt_berat->get_result();

// Set active page untuk sidebar
$active_page = 'point_of_sale';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kasir - Point Of Sale</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .pos-container {
            display: flex;
            height: calc(100vh - 80px);
            gap: 20px;
            padding: 20px;
        }
        .pos-left {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .pos-right {
            width: 500px;
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        .product-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 15px;
            overflow-y: auto;
            flex: 1;
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
        }
        .product-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            cursor: pointer;
            transition: all 0.3s;
            background: white;
            display: flex;
            flex-direction: column;
            height: auto;
            min-height: 0;
        }
        .product-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .product-card.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .product-image {
            width: 100%;
            height: 120px;
            background: #f8f9fa;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
            font-size: 40px;
            overflow: hidden;
            flex-shrink: 0;
        }
        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .product-name {
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 5px;
            word-wrap: break-word;
            word-break: break-word;
            line-height: 1.4;
            min-height: 2.8em;
        }
        .product-price {
            color: #667eea;
            font-weight: bold;
            font-size: 16px;
        }
        .product-stock {
            font-size: 12px;
            color: #6c757d;
        }
        .cart-section {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 15px;
            height: 100%;
            overflow: hidden;
        }
        .cart-table {
            flex: 1;
            overflow-y: auto;
        }
        .summary-section {
            border-top: 2px solid #dee2e6;
            padding-top: 15px;
        }
        .summary-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .summary-label {
            font-weight: bold;
        }
        .summary-value {
            font-weight: bold;
            color: #667eea;
        }
        .btn-bayar {
            width: 100%;
            padding: 15px;
            font-size: 18px;
            font-weight: bold;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn-bayar:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        .btn-bayar:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .filter-section {
            display: flex;
            gap: 10px;
            flex-wrap: nowrap;
            align-items: center;
        }
        .filter-section .form-select {
            flex: 1;
        }
        .search-section {
            margin-bottom: 15px;
        }
    </style>
</head>
<body class="dashboard-body">
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Kasir - Point Of Sale</h1>
        </div>

        <div class="pos-container">
            <!-- Left Section: Product Selection -->
            <div class="pos-left">
                <!-- Search Bar -->
                <div class="search-section">
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-search">🔍</i></span>
                        <input type="text" class="form-control" id="searchInput" placeholder="search">
                    </div>
                </div>

                <!-- Filter Section -->
                <div class="filter-section">
                    <select class="form-select" id="filterMerek">
                        <option value="">Merek</option>
                    </select>
                    
                    <select class="form-select" id="filterKategori">
                        <option value="">Kategori</option>
                    </select>
                    
                    <select class="form-select" id="filterBerat">
                        <option value="">Berat</option>
                    </select>
                </div>

                <!-- Product Grid -->
                <div class="product-grid" id="productGrid">
                    <div class="text-center text-muted p-4">Memuat produk...</div>
                </div>
            </div>

            <!-- Right Section: Cart and Payment -->
            <div class="pos-right">
                <div class="cart-section">
                    <h5 class="mb-3">Keranjang</h5>
                    
                    <!-- Cart Table -->
                    <div class="cart-table">
                        <table class="table table-sm table-striped" id="cartTable">
                            <thead>
                                <tr>
                                    <th>ID Barang</th>
                                    <th>Nama Barang</th>
                                    <th>Jumlah</th>
                                    <th>Harga</th>
                                    <th>Total</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody id="cartTableBody">
                            </tbody>
                        </table>
                    </div>

                    <!-- Summary Section -->
                    <div class="summary-section">
                        <div class="summary-item">
                            <span class="summary-label">Total:</span>
                            <span class="summary-value" id="summaryTotal">Rp 0</span>
                        </div>
                        <div class="summary-item">
                            <span class="summary-label">PPN (11%):</span>
                            <span class="summary-value" id="summaryPPN">Rp 0</span>
                        </div>
                        <div class="summary-item" style="font-size: 18px; border-top: 2px solid #dee2e6; padding-top: 10px; margin-top: 10px;">
                            <span class="summary-label">Grand Total:</span>
                            <span class="summary-value" id="summaryGrandTotal">Rp 0</span>
                        </div>
                    </div>

                    <!-- Payment Button -->
                    <button class="btn-bayar" id="btnBayar" disabled>BAYAR</button>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery -->
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
    
    <script>
        let cart = [];
        let cartTable = null;
        
        $(document).ready(function() {
            // Initialize DataTables for cart
            cartTable = $('#cartTable').DataTable({
                paging: false,
                searching: false,
                info: false,
                ordering: false,
                columnDefs: [
                    { targets: [5], orderable: false } // Action column
                ],
                language: {
                    emptyTable: "Keranjang kosong"
                }
            });
            
            // Load filter options and products on page load
            loadFilterOptions();
            loadProducts();
            
            // Search input - langsung mengetik tanpa perlu enter
            $('#searchInput').on('input', debounce(function() {
                loadFilterOptions();
                loadProducts();
            }, 300));
            
            // Filter change
            $('#filterMerek, #filterKategori, #filterBerat').on('change', function() {
                loadFilterOptions();
                loadProducts();
            });
            
            // Payment button
            $('#btnBayar').on('click', function() {
                processPayment();
            });
        });
        
        function debounce(func, wait) {
            let timeout;
            return function executedFunction(...args) {
                const later = () => {
                    clearTimeout(timeout);
                    func(...args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
        
        function loadFilterOptions() {
            const search = $('#searchInput').val();
            const filterMerek = $('#filterMerek').val();
            const filterKategori = $('#filterKategori').val();
            const filterBerat = $('#filterBerat').val();
            
            $.ajax({
                url: 'point_of_sale.php',
                method: 'GET',
                data: {
                    get_filter_options: 1,
                    search: search,
                    filter_merek: filterMerek,
                    filter_kategori: filterKategori,
                    filter_berat: filterBerat
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        const options = response.filter_options;
                        
                        // Update Merek dropdown
                        const currentMerek = $('#filterMerek').val();
                        let htmlMerek = '<option value="">Merek</option>';
                        options.merek.forEach(function(merek) {
                            htmlMerek += `<option value="${merek.kd_merek}" ${merek.kd_merek === currentMerek ? 'selected' : ''}>${merek.nama_merek}</option>`;
                        });
                        $('#filterMerek').html(htmlMerek);
                        
                        // Update Kategori dropdown
                        const currentKategori = $('#filterKategori').val();
                        let htmlKategori = '<option value="">Kategori</option>';
                        options.kategori.forEach(function(kategori) {
                            htmlKategori += `<option value="${kategori.kd_kategori}" ${kategori.kd_kategori === currentKategori ? 'selected' : ''}>${kategori.nama_kategori}</option>`;
                        });
                        $('#filterKategori').html(htmlKategori);
                        
                        // Update Berat dropdown
                        const currentBerat = $('#filterBerat').val();
                        let htmlBerat = '<option value="">Berat</option>';
                        options.berat.forEach(function(berat) {
                            htmlBerat += `<option value="${berat.berat}" ${berat.berat === currentBerat ? 'selected' : ''}>${berat.berat} gr</option>`;
                        });
                        $('#filterBerat').html(htmlBerat);
                    }
                },
                error: function() {
                    console.error('Gagal memuat filter options');
                }
            });
        }
        
        function loadProducts() {
            const search = $('#searchInput').val();
            const filterMerek = $('#filterMerek').val();
            const filterKategori = $('#filterKategori').val();
            const filterBerat = $('#filterBerat').val();
            
            $.ajax({
                url: 'point_of_sale.php',
                method: 'GET',
                data: {
                    get_products: 1,
                    search: search,
                    filter_merek: filterMerek,
                    filter_kategori: filterKategori,
                    filter_berat: filterBerat
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        renderProducts(response.products);
                    } else {
                        $('#productGrid').html('<div class="text-center text-danger p-4">' + response.message + '</div>');
                    }
                },
                error: function() {
                    $('#productGrid').html('<div class="text-center text-danger p-4">Gagal memuat produk!</div>');
                }
            });
        }
        
        function renderProducts(products) {
            if (products.length === 0) {
                $('#productGrid').html('<div class="text-center text-muted p-4">Tidak ada produk ditemukan</div>');
                return;
            }
            
            let html = '';
            products.forEach(function(product) {
                const inCart = cart.find(item => item.kd_barang === product.kd_barang);
                const isDisabled = product.stock_sekarang === 0 || (inCart && inCart.jumlah >= product.stock_sekarang);
                
                // Handle gambar
                let imageHtml = '📦';
                if (product.gambar_barang && product.gambar_barang.trim() !== '') {
                    imageHtml = `<img src="../${product.gambar_barang}" alt="${product.nama_barang}" onerror="this.parentElement.innerHTML='📦'">`;
                }
                
                html += `
                    <div class="product-card ${isDisabled ? 'disabled' : ''}" data-kd-barang="${product.kd_barang}" ${!isDisabled ? 'onclick="addToCart(\'' + product.kd_barang + '\', \'' + product.nama_barang.replace(/'/g, "\\'") + '\', ' + product.harga_jual + ', ' + product.stock_sekarang + ')"' : ''}>
                        <div class="product-image">${imageHtml}</div>
                        <div class="product-name" title="${product.nama_barang}">${product.nama_barang}</div>
                        <div class="product-price">Rp ${formatNumber(product.harga_jual)}</div>
                        <div class="product-stock">Stock: ${product.stock_sekarang}</div>
                    </div>
                `;
            });
            
            $('#productGrid').html(html);
        }
        
        function addToCart(kdBarang, namaBarang, harga, stockSekarang) {
            const existingItem = cart.find(item => item.kd_barang === kdBarang);
            
            if (existingItem) {
                if (existingItem.jumlah >= stockSekarang) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Stock tidak cukup!',
                        text: 'Jumlah di keranjang sudah mencapai stock yang tersedia.',
                        confirmButtonColor: '#667eea'
                    });
                    return;
                }
                existingItem.jumlah++;
                existingItem.total = existingItem.jumlah * existingItem.harga;
            } else {
                cart.push({
                    kd_barang: kdBarang,
                    nama_barang: namaBarang,
                    harga: harga,
                    jumlah: 1,
                    total: harga,
                    stock_sekarang: stockSekarang
                });
            }
            
            updateCartDisplay();
            loadProducts(); // Reload to update disabled state
        }
        
        function removeFromCart(kdBarang) {
            cart = cart.filter(item => item.kd_barang !== kdBarang);
            updateCartDisplay();
            loadProducts(); // Reload to update disabled state
        }
        
        function updateQuantity(kdBarang, change) {
            const item = cart.find(item => item.kd_barang === kdBarang);
            if (!item) return;
            
            const newQuantity = item.jumlah + change;
            if (newQuantity < 1) {
                removeFromCart(kdBarang);
                return;
            }
            
            if (newQuantity > item.stock_sekarang) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Stock tidak cukup!',
                    text: 'Stock yang tersedia hanya ' + item.stock_sekarang,
                    confirmButtonColor: '#667eea'
                });
                return;
            }
            
            item.jumlah = newQuantity;
            item.total = item.jumlah * item.harga;
            updateCartDisplay();
            loadProducts(); // Reload to update disabled state
        }
        
        function updateCartDisplay() {
            // Clear DataTable
            cartTable.clear();
            
            if (cart.length === 0) {
                cartTable.draw();
                $('#btnBayar').prop('disabled', true);
                updateSummary();
                return;
            }
            
            // Add rows to DataTable
            cart.forEach(function(item) {
                cartTable.row.add([
                    item.kd_barang,
                    item.nama_barang,
                    `<div class="d-flex align-items-center gap-2">
                        <button class="btn btn-sm btn-outline-secondary" onclick="updateQuantity('${item.kd_barang}', -1)">-</button>
                        <span>${item.jumlah}</span>
                        <button class="btn btn-sm btn-outline-secondary" onclick="updateQuantity('${item.kd_barang}', 1)">+</button>
                    </div>`,
                    `Rp ${formatNumber(item.harga)}`,
                    `Rp ${formatNumber(item.total)}`,
                    `<button class="btn btn-sm btn-danger" onclick="removeFromCart('${item.kd_barang}')">Delete</button>`
                ]);
            });
            
            cartTable.draw();
            $('#btnBayar').prop('disabled', false);
            updateSummary();
        }
        
        function updateSummary() {
            let total = 0;
            cart.forEach(function(item) {
                total += item.total;
            });
            
            const ppn = total * 0.11;
            const grandTotal = total + ppn;
            
            $('#summaryTotal').text('Rp ' + formatNumber(total));
            $('#summaryPPN').text('Rp ' + formatNumber(ppn));
            $('#summaryGrandTotal').text('Rp ' + formatNumber(grandTotal));
        }
        
        function processPayment() {
            if (cart.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Keranjang kosong!',
                    text: 'Tambahkan produk ke keranjang terlebih dahulu.',
                    confirmButtonColor: '#667eea'
                });
                return;
            }
            
            // Hitung grand total dengan benar (sama seperti updateSummary)
            let total = 0;
            cart.forEach(function(item) {
                total += parseFloat(item.total) || 0;
            });
            const ppn = total * 0.11;
            const grandTotal = total + ppn;
            
            Swal.fire({
                title: 'Konfirmasi Pembayaran',
                html: 'Apakah Anda yakin ingin memproses pembayaran?<br><br><strong>Grand Total: Rp ' + formatNumber(grandTotal) + '</strong>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya, Proses',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Disable button
                    $('#btnBayar').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Memproses...');
                    
                    $.ajax({
                        url: 'point_of_sale.php',
                        method: 'POST',
                        data: {
                            action: 'process_payment',
                            cart_items: JSON.stringify(cart)
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Berhasil!',
                                    html: 'Pembayaran berhasil diproses!<br><br>ID Nota: <strong>' + response.id_nota_jual + '</strong><br>Grand Total: <strong>Rp ' + formatNumber(response.grand_total) + '</strong>',
                                    confirmButtonColor: '#667eea',
                                    timer: 3000,
                                    timerProgressBar: true
                                }).then(() => {
                                    // Clear cart and reload
                                    cart = [];
                                    updateCartDisplay();
                                    loadProducts();
                                    $('#btnBayar').prop('disabled', false).html('BAYAR');
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Gagal!',
                                    text: response.message,
                                    confirmButtonColor: '#e74c3c'
                                });
                                $('#btnBayar').prop('disabled', false).html('BAYAR');
                            }
                        },
                        error: function(xhr) {
                            let errorMessage = 'Terjadi kesalahan saat memproses pembayaran!';
                            try {
                                const errorResponse = JSON.parse(xhr.responseText);
                                if (errorResponse.message) {
                                    errorMessage = errorResponse.message;
                                }
                            } catch (e) {
                                // Use default message
                            }
                            
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: errorMessage,
                                confirmButtonColor: '#e74c3c'
                            });
                            $('#btnBayar').prop('disabled', false).html('BAYAR');
                        }
                    });
                }
            });
        }
        
        function formatNumber(num) {
            return num ? num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '0';
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
        
        // Make functions global for onclick handlers
        window.addToCart = addToCart;
        window.removeFromCart = removeFromCart;
        window.updateQuantity = updateQuantity;
    </script>
</body>
</html>

