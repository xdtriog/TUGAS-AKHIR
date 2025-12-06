<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login dan adalah staff gudang
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Cek apakah user adalah staff gudang (format ID: GDNG+UUID)
if (substr($user_id, 0, 4) != 'GDNG') {
    header("Location: ../index.php");
    exit();
}

// Get user info dan lokasi gudang
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
$nama_lokasi = $user_data['NAMA_LOKASI'] ?? 'Gudang';

// Get alamat lokasi
$query_alamat = "SELECT ALAMAT_LOKASI FROM MASTER_LOKASI WHERE KD_LOKASI = ?";
$stmt_alamat = $conn->prepare($query_alamat);
$stmt_alamat->bind_param("s", $kd_lokasi);
$stmt_alamat->execute();
$result_alamat = $stmt_alamat->get_result();
$alamat_lokasi = $result_alamat->num_rows > 0 ? $result_alamat->fetch_assoc()['ALAMAT_LOKASI'] : '';

// Handle AJAX request untuk get data barang dan semua batch
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['get_barang_data'])) {
    header('Content-Type: application/json');
    
    $kd_barang = isset($_GET['kd_barang']) ? trim($_GET['kd_barang']) : '';
    
    if (empty($kd_barang)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Get data master barang
    $query_barang = "SELECT mb.SATUAN_PERDUS, mb.BERAT, mb.AVG_HARGA_BELI_PIECES
                    FROM MASTER_BARANG mb
                    WHERE mb.KD_BARANG = ?";
    $stmt_barang = $conn->prepare($query_barang);
    if (!$stmt_barang) {
        echo json_encode(['success' => false, 'message' => 'Gagal prepare query: ' . $conn->error]);
        exit();
    }
    $stmt_barang->bind_param("s", $kd_barang);
    if (!$stmt_barang->execute()) {
        echo json_encode(['success' => false, 'message' => 'Gagal execute query: ' . $stmt_barang->error]);
        exit();
    }
    $result_barang = $stmt_barang->get_result();
    
    if ($result_barang->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Data barang tidak ditemukan!']);
        exit();
    }
    
    $barang_data = $result_barang->fetch_assoc();
    
    // Get semua batch yang tersedia (SISA_STOCK_DUS > 0, STATUS = 'SELESAI')
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
    if (!$stmt_batch) {
        echo json_encode(['success' => false, 'message' => 'Gagal prepare query batch: ' . $conn->error]);
        exit();
    }
    $stmt_batch->bind_param("ss", $kd_barang, $kd_lokasi);
    if (!$stmt_batch->execute()) {
        echo json_encode(['success' => false, 'message' => 'Gagal execute query batch: ' . $stmt_batch->error]);
        exit();
    }
    $result_batch = $stmt_batch->get_result();
    
    $batches = [];
    while ($row = $result_batch->fetch_assoc()) {
        // Format supplier
        $supplier_display = '-';
        if ($row['SUPPLIER_KD'] != '-' && $row['NAMA_SUPPLIER'] != '-') {
            $supplier_display = $row['SUPPLIER_KD'] . ' - ' . $row['NAMA_SUPPLIER'];
        }
        
        // Format tanggal expired
        $tgl_expired_display = '-';
        $is_expired = false;
        if (!empty($row['TGL_EXPIRED'])) {
            $date_expired = new DateTime($row['TGL_EXPIRED']);
            $today = new DateTime();
            $today->setTime(0, 0, 0);
            $date_expired->setTime(0, 0, 0);
            $is_expired = $date_expired < $today;
            
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
            'supplier' => $supplier_display,
            'is_expired' => $is_expired
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'satuan_perdus' => intval($barang_data['SATUAN_PERDUS'] ?? 1),
            'berat' => intval($barang_data['BERAT'] ?? 0),
            'avg_harga_beli' => floatval($barang_data['AVG_HARGA_BELI_PIECES'] ?? 0),
            'batches' => $batches
        ]
    ]);
    exit();
}

// Handle AJAX request untuk simpan stock opname (multiple batches)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'simpan_opname') {
    header('Content-Type: application/json');
    
    // Enable error reporting untuk debugging
    error_reporting(E_ALL);
    ini_set('display_errors', 0); // Jangan tampilkan di output, tapi log
    
    $kd_barang = isset($_POST['kd_barang']) ? trim($_POST['kd_barang']) : '';
    $batches_data = isset($_POST['batches']) ? $_POST['batches'] : [];
    
    if (empty($kd_barang) || !is_array($batches_data) || count($batches_data) == 0) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Mulai transaksi
    $conn->begin_transaction();
    
    try {
        require_once '../includes/uuid_generator.php';
        
        // Get data stock dan master barang
        $query_barang = "SELECT 
            s.JUMLAH_BARANG as JUMLAH_SISTEM,
            s.SATUAN as SATUAN_STOCK,
            mb.SATUAN_PERDUS,
            mb.AVG_HARGA_BELI_PIECES
        FROM STOCK s
        INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
        WHERE s.KD_BARANG = ? AND s.KD_LOKASI = ?";
        $stmt_barang = $conn->prepare($query_barang);
        if (!$stmt_barang) {
            throw new Exception('Gagal prepare query barang: ' . $conn->error);
        }
        $stmt_barang->bind_param("ss", $kd_barang, $kd_lokasi);
        if (!$stmt_barang->execute()) {
            throw new Exception('Gagal execute query barang: ' . $stmt_barang->error);
        }
        $result_barang = $stmt_barang->get_result();
        
        if (!$result_barang) {
            throw new Exception('Gagal mendapatkan hasil query barang: ' . $stmt_barang->error);
        }
        
        if ($result_barang->num_rows == 0) {
            throw new Exception('Data barang tidak ditemukan!');
        }
        
        $barang_data = $result_barang->fetch_assoc();
        $satuan_stock = $barang_data['SATUAN_STOCK'] ?? 'DUS';
        $satuan_perdus = intval($barang_data['SATUAN_PERDUS'] ?? 1);
        $avg_harga_beli = floatval($barang_data['AVG_HARGA_BELI_PIECES'] ?? 0);
        
        $opname_results = [];
        $total_selisih_dus = 0;
        $total_selisih_pieces = 0;
        $total_uang = 0;
        
        // Process setiap batch
        foreach ($batches_data as $batch_item) {
            $id_pesan_barang = isset($batch_item['id_pesan_barang']) ? trim($batch_item['id_pesan_barang']) : '';
            $jumlah_sebenarnya_dus = isset($batch_item['jumlah_sebenarnya']) ? intval($batch_item['jumlah_sebenarnya']) : -1;
            
            if (empty($id_pesan_barang) || $jumlah_sebenarnya_dus < 0) {
                continue; // Skip jika data tidak valid
            }
            
            // Get data batch
            $query_batch = "SELECT SISA_STOCK_DUS, TGL_EXPIRED
                           FROM PESAN_BARANG
                           WHERE ID_PESAN_BARANG = ? AND KD_BARANG = ? AND KD_LOKASI = ? AND STATUS = 'SELESAI'";
            $stmt_batch = $conn->prepare($query_batch);
            if (!$stmt_batch) {
                throw new Exception('Gagal prepare query batch: ' . $conn->error);
            }
            $stmt_batch->bind_param("sss", $id_pesan_barang, $kd_barang, $kd_lokasi);
            if (!$stmt_batch->execute()) {
                throw new Exception('Gagal execute query batch: ' . $stmt_batch->error);
            }
            $result_batch = $stmt_batch->get_result();
            
            if ($result_batch->num_rows == 0) {
                continue; // Skip jika batch tidak ditemukan
            }
            
            $batch_data = $result_batch->fetch_assoc();
            $jumlah_sistem_batch_dus = intval($batch_data['SISA_STOCK_DUS'] ?? 0);
            
            // Hitung selisih
            $selisih_dus = $jumlah_sebenarnya_dus - $jumlah_sistem_batch_dus;
            $selisih_pieces = $selisih_dus * $satuan_perdus;
            $uang_batch = $selisih_pieces * $avg_harga_beli;
            
            // Generate ID opname untuk setiap batch
            // Generate ID_OPNAME dengan format OPNM+UUID (total 16 karakter: OPNM=4, UUID=12)
            $id_opname = '';
            do {
                $uuid = ShortIdGenerator::generate(12, '');
                $id_opname = 'OPNM' . $uuid;
            } while (checkUUIDExists($conn, 'STOCK_OPNAME', 'ID_OPNAME', $id_opname));
            
            // Insert ke STOCK_OPNAME dengan REF_BATCH
            $insert_opname = "INSERT INTO STOCK_OPNAME 
                            (ID_OPNAME, KD_BARANG, KD_LOKASI, ID_USERS, JUMLAH_SEBENARNYA, JUMLAH_SISTEM, SELISIH, SATUAN, SATUAN_PERDUS, TOTAL_BARANG_PIECES, HARGA_BARANG_PIECES, TOTAL_UANG, REF_BATCH)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_opname = $conn->prepare($insert_opname);
            if (!$stmt_opname) {
                throw new Exception('Gagal prepare query opname: ' . $conn->error);
            }
            // bind_param: 13 parameters total
            // s: id_opname, kd_barang, kd_lokasi, user_id, satuan_stock, id_pesan_barang (6 strings)
            // i: jumlah_sebenarnya_dus, jumlah_sistem_batch_dus, selisih_dus, satuan_perdus, selisih_pieces (5 integers)
            // d: avg_harga_beli, uang_batch (2 doubles)
            // Format: ssssiiisiiidds = 4s + 3i + 1s + 2i + 2d + 1s = 13 karakter
            // Verifikasi: s(4) + i(3) + s(1) + i(2) + d(2) + s(1) = 13 karakter
            // Format: ssss (4) + iii (3) + s (1) + ii (2) + dd (2) + s (1) = 13
            // Format string: 13 parameter
            // s(4): id_opname, kd_barang, kd_lokasi, user_id
            // i(3): jumlah_sebenarnya_dus, jumlah_sistem_batch_dus, selisih_dus
            // s(1): satuan_stock
            // i(2): satuan_perdus, selisih_pieces
            // d(2): avg_harga_beli, uang_batch
            // s(1): id_pesan_barang
            // Total: 4+3+1+2+2+1 = 13 karakter
            // Format string: 13 karakter untuk 13 variabel
            // VERIFIED: "ssssiiisiiidds" = 14 karakter (SALAH - ada 1 karakter ekstra!)
            // Variabel: s(4) + i(3) + s(1) + i(2) + d(2) + s(1) = 13
            // Format yang benar: "ssssiiisiiidd" = 13 karakter
            // TAPI REF_BATCH adalah string, jadi harus ada s di akhir!
            // Mari saya hitung ulang: mungkin ada satu 'i' ekstra di tengah
            // Format asli: "ssssiiisiiidds" = ssss(4) + iii(3) + s(1) + ii(2) + dd(2) + s(1) = 14
            // Format yang benar: "ssssiiisiiidd" = ssss(4) + iii(3) + s(1) + ii(2) + dd(2) = 12 (KURANG!)
            // Atau: "ssssiiisiiidds" tanpa satu 'i' = "ssssiiisiiidds" -> hapus 'i' ke-11 = "ssssiiisiidds"
            // Mari saya coba: "ssssiiisiidds" = ssss(4) + iii(3) + s(1) + i(1) + i(1) + dd(2) + s(1) = 13 ✓
            $stmt_opname->bind_param("ssssiiisiidds", 
                $id_opname,           // s
                $kd_barang,           // s
                $kd_lokasi,           // s
                $user_id,             // s
                $jumlah_sebenarnya_dus, // i
                $jumlah_sistem_batch_dus, // i
                $selisih_dus,         // i
                $satuan_stock,        // s
                $satuan_perdus,       // i
                $selisih_pieces,      // i
                $avg_harga_beli,      // d
                $uang_batch,          // d
                $id_pesan_barang      // s
            );
            if (!$stmt_opname->execute()) {
                throw new Exception('Gagal insert opname: ' . $stmt_opname->error);
            }
            
            // Update SISA_STOCK_DUS di PESAN_BARANG dengan jumlah sebenarnya
            $update_batch = "UPDATE PESAN_BARANG 
                            SET SISA_STOCK_DUS = ?
                            WHERE ID_PESAN_BARANG = ?";
            $stmt_update_batch = $conn->prepare($update_batch);
            if (!$stmt_update_batch) {
                throw new Exception('Gagal prepare query update batch: ' . $conn->error);
            }
            $stmt_update_batch->bind_param("is", $jumlah_sebenarnya_dus, $id_pesan_barang);
            if (!$stmt_update_batch->execute()) {
                throw new Exception('Gagal update batch: ' . $stmt_update_batch->error);
            }
            
            // Insert ke STOCK_HISTORY
            $id_history = '';
            do {
                // Generate ID_HISTORY_STOCK dengan format SKHY+UUID (total 16 karakter: SKHY=4, UUID=12)
                $uuid = ShortIdGenerator::generate(12, '');
                $id_history = 'SKHY' . $uuid;
            } while (checkUUIDExists($conn, 'STOCK_HISTORY', 'ID_HISTORY_STOCK', $id_history));
            
            $insert_history = "INSERT INTO STOCK_HISTORY 
                              (ID_HISTORY_STOCK, KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_AWAL, JUMLAH_PERUBAHAN, JUMLAH_AKHIR, TIPE_PERUBAHAN, REF, SATUAN)
                              VALUES (?, ?, ?, ?, ?, ?, ?, 'OPNAME', ?, ?)";
            $stmt_history = $conn->prepare($insert_history);
            if (!$stmt_history) {
                throw new Exception('Gagal prepare query insert history: ' . $conn->error);
            }
            $satuan_history = 'DUS';
            $stmt_history->bind_param("ssssiiiss", $id_history, $kd_barang, $kd_lokasi, $user_id, $jumlah_sistem_batch_dus, $selisih_dus, $jumlah_sebenarnya_dus, $id_pesan_barang, $satuan_history);
            if (!$stmt_history->execute()) {
                throw new Exception('Gagal insert history: ' . $stmt_history->error);
            }
            
            // Simpan hasil untuk popup
            $opname_results[] = [
                'id_pesan_barang' => $id_pesan_barang,
                'jumlah_sistem' => $jumlah_sistem_batch_dus,
                'jumlah_sebenarnya' => $jumlah_sebenarnya_dus,
                'selisih' => $selisih_dus,
                'uang' => $uang_batch
            ];
            
            $total_selisih_dus += $selisih_dus;
            $total_selisih_pieces += $selisih_pieces;
            $total_uang += $uang_batch;
        }
        
        // Update STOCK total dengan sum dari semua SISA_STOCK_DUS dari semua batch
        $query_sum_batch = "SELECT COALESCE(SUM(SISA_STOCK_DUS), 0) as TOTAL_STOCK_DUS
                           FROM PESAN_BARANG
                           WHERE KD_BARANG = ? AND KD_LOKASI = ? AND STATUS = 'SELESAI'";
        $stmt_sum_batch = $conn->prepare($query_sum_batch);
        if (!$stmt_sum_batch) {
            throw new Exception('Gagal prepare query sum batch: ' . $conn->error);
        }
        $stmt_sum_batch->bind_param("ss", $kd_barang, $kd_lokasi);
        if (!$stmt_sum_batch->execute()) {
            throw new Exception('Gagal execute query sum batch: ' . $stmt_sum_batch->error);
        }
        $result_sum_batch = $stmt_sum_batch->get_result();
        $sum_batch_data = $result_sum_batch->fetch_assoc();
        $total_stock_dus = intval($sum_batch_data['TOTAL_STOCK_DUS'] ?? 0);
        
        // Konversi ke satuan stock yang sesuai
        $jumlah_update_stock = $total_stock_dus;
        if ($satuan_stock == 'PIECES') {
            $jumlah_update_stock = $total_stock_dus * $satuan_perdus;
        }
        
        $update_stock = "UPDATE STOCK 
                        SET JUMLAH_BARANG = ?, 
                            LAST_UPDATED = CURRENT_TIMESTAMP,
                            UPDATED_BY = ?
                        WHERE KD_BARANG = ? AND KD_LOKASI = ?";
        $stmt_update_stock = $conn->prepare($update_stock);
        if (!$stmt_update_stock) {
            throw new Exception('Gagal prepare query update stock: ' . $conn->error);
        }
        $stmt_update_stock->bind_param("isss", $jumlah_update_stock, $user_id, $kd_barang, $kd_lokasi);
        if (!$stmt_update_stock->execute()) {
            throw new Exception('Gagal update stock: ' . $stmt_update_stock->error);
        }
        
        // Commit transaksi
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Stock opname berhasil disimpan!',
            'results' => $opname_results,
            'total_selisih_dus' => $total_selisih_dus,
            'total_selisih_pieces' => $total_selisih_pieces,
            'total_uang' => $total_uang
        ]);
    } catch (Exception $e) {
        // Rollback transaksi
        $conn->rollback();
        
        // Tampilkan error message yang lebih detail untuk debugging
        $error_message = $e->getMessage();
        $error_file = $e->getFile();
        $error_line = $e->getLine();
        
        // Jika ada error dari database, tambahkan info database error
        if ($conn->error) {
            $error_message .= ' | Database Error: ' . $conn->error;
        }
        
        // Untuk development, tampilkan detail error
        $debug_info = '';
        if (isset($_GET['debug']) || true) { // Selalu tampilkan detail untuk debugging
            $debug_info = ' | File: ' . basename($error_file) . ' | Line: ' . $error_line;
        }
        
        echo json_encode([
            'success' => false, 
            'message' => $error_message . $debug_info,
            'error_detail' => [
                'message' => $e->getMessage(),
                'file' => basename($error_file),
                'line' => $error_line,
                'db_error' => $conn->error
            ]
        ]);
    } catch (Error $e) {
        // Rollback transaksi
        $conn->rollback();
        
        $error_message = $e->getMessage();
        $error_file = $e->getFile();
        $error_line = $e->getLine();
        
        echo json_encode([
            'success' => false, 
            'message' => 'Fatal Error: ' . $error_message . ' | File: ' . basename($error_file) . ' | Line: ' . $error_line,
            'error_detail' => [
                'message' => $e->getMessage(),
                'file' => basename($error_file),
                'line' => $error_line
            ]
        ]);
    }
    
    exit();
}

// Query untuk mendapatkan data stock dengan waktu terakhir stock opname
$query_stock = "SELECT 
    s.KD_BARANG,
    mb.NAMA_BARANG,
    mb.BERAT,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
    s.JUMLAH_BARANG as STOCK_SEKARANG,
    s.SATUAN,
    (
        SELECT MAX(so.WAKTU_OPNAME)
        FROM STOCK_OPNAME so
        WHERE so.KD_BARANG = s.KD_BARANG AND so.KD_LOKASI = s.KD_LOKASI
    ) as WAKTU_TERAKHIR_OPNAME
FROM STOCK s
INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
WHERE s.KD_LOKASI = ?
ORDER BY s.KD_BARANG ASC";

$stmt_stock = $conn->prepare($query_stock);
$stmt_stock->bind_param("s", $kd_lokasi);
$stmt_stock->execute();
$result_stock = $stmt_stock->get_result();

// Format waktu terakhir stock opname
function formatWaktuTerakhirOpname($waktu) {
    if (empty($waktu) || $waktu == null) {
        return '-';
    }
    
    // Set timezone ke Asia/Jakarta (WIB)
    date_default_timezone_set('Asia/Jakarta');
    
    // Buat DateTime dengan timezone Asia/Jakarta
    $timezone = new DateTimeZone('Asia/Jakarta');
    $date = new DateTime($waktu, $timezone);
    $now = new DateTime('now', $timezone);
    $diff = $now->diff($date);
    
    $waktu_formatted = $date->format('d/m/Y H:i') . ' WIB';
    
    // Hitung selisih waktu
    $selisih_text = '';
    if ($diff->y > 0) {
        $selisih_text = $diff->y . ' tahun lalu';
    } elseif ($diff->m > 0) {
        $selisih_text = $diff->m . ' bulan lalu';
    } elseif ($diff->d > 0) {
        $selisih_text = $diff->d . ' hari lalu';
    } elseif ($diff->h > 0) {
        $selisih_text = $diff->h . ' jam lalu';
    } elseif ($diff->i > 0) {
        $selisih_text = $diff->i . ' menit lalu';
    } else {
        $selisih_text = 'baru saja';
    }
    
    return $waktu_formatted . ' (' . $selisih_text . ')';
}

// Set active page untuk sidebar
$active_page = 'stock_opname';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gudang - Stock Opname</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .batch-expired {
            background-color: #ffebee !important;
            color: #c62828 !important;
            font-weight: bold;
        }
        .table-batch-opname input[type="number"] {
            text-align: right;
        }
    </style>
</head>
<body class="dashboard-body">
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Gudang <?php echo htmlspecialchars($nama_lokasi); ?> - Stock Opname</h1>
            <?php if (!empty($alamat_lokasi)): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($alamat_lokasi); ?></p>
            <?php endif; ?>
        </div>

        <!-- Table Stock Opname -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tableStockOpname" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Kode Barang</th>
                            <th>Merek Barang</th>
                            <th>Kategori Barang</th>
                            <th>Nama Barang</th>
                            <th>Berat (gr)</th>
                            <th>Stock Sekarang</th>
                            <th>Satuan</th>
                            <th>Waktu Terakhir Stock Opname</th>
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
                                    <td><?php echo number_format($row['STOCK_SEKARANG'], 0, ',', '.'); ?></td>
                                    <td><?php echo htmlspecialchars($row['SATUAN']); ?></td>
                                    <td><?php echo formatWaktuTerakhirOpname($row['WAKTU_TERAKHIR_OPNAME']); ?></td>
                                    <td>
                                        <button class="btn-view btn-sm" onclick="bukaModalOpname('<?php echo htmlspecialchars($row['KD_BARANG']); ?>', '<?php echo htmlspecialchars($row['NAMA_MEREK']); ?>', '<?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?>', '<?php echo htmlspecialchars($row['NAMA_BARANG']); ?>', <?php echo $row['BERAT']; ?>, <?php echo $row['STOCK_SEKARANG']; ?>, '<?php echo htmlspecialchars($row['SATUAN']); ?>')">Stock Opname</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted">Tidak ada data stock</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Stock Opname -->
    <div class="modal fade" id="modalStockOpname" tabindex="-1" aria-labelledby="modalStockOpnameLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalStockOpnameLabel">Stock Opname</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="formOpname">
                        <input type="hidden" id="opname_kd_barang" name="kd_barang">
                        
                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Merek Barang</label>
                                <input type="text" class="form-control form-control-sm" id="opname_merek_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Kode Barang</label>
                                <input type="text" class="form-control form-control-sm" id="opname_kode_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Kategori Barang</label>
                                <input type="text" class="form-control form-control-sm" id="opname_kategori_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Berat (gr)</label>
                                <input type="text" class="form-control form-control-sm" id="opname_berat" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold small">Nama Barang</label>
                                <input type="text" class="form-control form-control-sm" id="opname_nama_barang" readonly style="background-color: #e9ecef;">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Input Jumlah Sebenarnya (dus) <span class="text-danger">*</span></label>
                            <small class="text-muted d-block mb-2">Masukkan jumlah sebenarnya untuk setiap batch. Selisih akan ditampilkan setelah menyimpan.</small>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered table-batch-opname">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 20%;">ID Batch</th>
                                            <th style="width: 20%;">Tanggal Expired</th>
                                            <th style="width: 20%;">Supplier</th>
                                            <th style="width: 40%;">Jumlah Sebenarnya (dus) <span class="text-danger">*</span></th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbodyBatches">
                                        <!-- Batch rows akan diisi via JavaScript -->
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" onclick="simpanOpname()">Simpan</button>
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
            $.fn.dataTable.ext.errMode = 'none';
            
            $('#tableStockOpname').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data stock opname'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[0, 'asc']],
                columnDefs: [
                    { orderable: false, targets: [8] }
                ],
                scrollX: true,
                autoWidth: false
            }).on('error.dt', function(e, settings, techNote, message) {
                console.log('DataTables error suppressed:', message);
                return false;
            });
        });

        function bukaModalOpname(kdBarang, namaMerek, namaKategori, namaBarang, berat, stockSistem, satuan) {
            // Get data barang dan semua batch
            $.ajax({
                url: '',
                method: 'GET',
                data: {
                    get_barang_data: '1',
                    kd_barang: kdBarang
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var batches = response.data.batches || [];
                        
                        // Set form values
                        $('#opname_kd_barang').val(kdBarang);
                        $('#opname_merek_barang').val(namaMerek);
                        $('#opname_kategori_barang').val(namaKategori);
                        $('#opname_kode_barang').val(kdBarang);
                        $('#opname_nama_barang').val(namaBarang);
                        $('#opname_berat').val(numberFormat(berat));
                        
                        // Populate batch table
                        var tbody = $('#tbodyBatches');
                        tbody.empty();
                        
                        if (batches.length === 0) {
                            tbody.append('<tr><td colspan="4" class="text-center text-muted">Tidak ada batch tersedia</td></tr>');
                            Swal.fire({
                                icon: 'warning',
                                title: 'Peringatan!',
                                text: 'Tidak ada batch tersedia untuk barang ini!',
                                confirmButtonColor: '#667eea'
                            });
                        } else {
                            batches.forEach(function(batch) {
                                var rowClass = batch.is_expired ? 'batch-expired' : '';
                                var expiredBadge = batch.is_expired ? ' <span class="badge bg-danger">EXPIRED</span>' : '';
                                
                                var row = '<tr class="' + rowClass + '">' +
                                    '<td>' + batch.id_pesan_barang + '</td>' +
                                    '<td>' + batch.tgl_expired_display + expiredBadge + '</td>' +
                                    '<td>' + (batch.supplier !== '-' ? batch.supplier : '-') + '</td>' +
                                    '<td>' +
                                    '<input type="number" class="form-control form-control-sm text-end" ' +
                                    'name="jumlah_sebenarnya_' + batch.id_pesan_barang + '" ' +
                                    'data-batch-id="' + batch.id_pesan_barang + '" ' +
                                    'data-sistem="' + batch.sisa_stock_dus + '" ' +
                                    'placeholder="Masukkan jumlah sebenarnya" ' +
                                    'min="0" step="1" required>' +
                                    '</td>' +
                                    '</tr>';
                                tbody.append(row);
                            });
                        }
                        
                        // Buka modal
                        $('#modalStockOpname').modal('show');
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: response.message || 'Gagal memuat data barang!',
                            confirmButtonColor: '#e74c3c'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Gagal memuat data barang dan batch!',
                        confirmButtonColor: '#e74c3c'
                    });
                }
            });
        }

        function simpanOpname() {
            var kdBarang = $('#opname_kd_barang').val();
            if (!kdBarang) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Data barang tidak valid!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }

            // Validasi form
            if (!$('#formOpname')[0].checkValidity()) {
                $('#formOpname')[0].reportValidity();
                return;
            }

            // Collect batch data
            var batches = [];
            $('input[data-batch-id]').each(function() {
                var batchId = $(this).data('batch-id');
                var jumlahSebenarnya = parseInt($(this).val()) || 0;
                
                if (jumlahSebenarnya >= 0) {
                    batches.push({
                        id_pesan_barang: batchId,
                        jumlah_sebenarnya: jumlahSebenarnya
                    });
                }
            });

            if (batches.length === 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Tidak ada batch yang diinput!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }

            // Konfirmasi
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi',
                text: 'Apakah Anda yakin ingin menyimpan stock opname ini?',
                showCancelButton: true,
                confirmButtonText: 'Ya, Simpan',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: {
                            action: 'simpan_opname',
                            kd_barang: kdBarang,
                            batches: batches
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                // Tutup modal dulu
                                $('#modalStockOpname').modal('hide');
                                
                                // Buat HTML untuk popup hasil
                                var htmlContent = '<div class="text-start">';
                                htmlContent += '<h6 class="mb-3">Hasil Stock Opname:</h6>';
                                htmlContent += '<div class="table-responsive">';
                                htmlContent += '<table class="table table-sm table-bordered">';
                                htmlContent += '<thead class="table-light"><tr>';
                                htmlContent += '<th>ID Batch</th>';
                                htmlContent += '<th class="text-end">Jumlah Sistem</th>';
                                htmlContent += '<th class="text-end">Jumlah Sebenarnya</th>';
                                htmlContent += '<th class="text-center">Selisih (Lebih/Kurang)</th>';
                                htmlContent += '<th class="text-end">Nilai (Rp)</th>';
                                htmlContent += '</tr></thead><tbody>';
                                
                                response.results.forEach(function(result) {
                                    var selisihClass = result.selisih > 0 ? 'text-success fw-bold' : (result.selisih < 0 ? 'text-danger fw-bold' : 'text-muted');
                                    var selisihText = '';
                                    if (result.selisih > 0) {
                                        selisihText = '<span class="badge bg-success">+ ' + numberFormat(result.selisih) + ' dus (LEBIH)</span>';
                                    } else if (result.selisih < 0) {
                                        selisihText = '<span class="badge bg-danger">' + numberFormat(result.selisih) + ' dus (KURANG)</span>';
                                    } else {
                                        selisihText = '<span class="badge bg-secondary">0 dus (SESUAI)</span>';
                                    }
                                    var uangText = result.uang >= 0 ? 'Rp. ' + numberFormat(result.uang) : '-Rp. ' + numberFormat(Math.abs(result.uang));
                                    
                                    htmlContent += '<tr>';
                                    htmlContent += '<td>' + result.id_pesan_barang + '</td>';
                                    htmlContent += '<td class="text-end">' + numberFormat(result.jumlah_sistem) + ' dus</td>';
                                    htmlContent += '<td class="text-end">' + numberFormat(result.jumlah_sebenarnya) + ' dus</td>';
                                    htmlContent += '<td class="text-center ' + selisihClass + '">' + selisihText + '</td>';
                                    htmlContent += '<td class="text-end ' + selisihClass + '">' + uangText + '</td>';
                                    htmlContent += '</tr>';
                                });
                                
                                htmlContent += '</tbody></table>';
                                htmlContent += '</div>';
                                
                                // Total
                                var totalSelisihClass = response.total_selisih_dus > 0 ? 'text-success fw-bold' : (response.total_selisih_dus < 0 ? 'text-danger fw-bold' : 'text-muted');
                                var totalSelisihText = '';
                                if (response.total_selisih_dus > 0) {
                                    totalSelisihText = '<span class="badge bg-success">+ ' + numberFormat(response.total_selisih_dus) + ' dus (LEBIH)</span>';
                                } else if (response.total_selisih_dus < 0) {
                                    totalSelisihText = '<span class="badge bg-danger">' + numberFormat(response.total_selisih_dus) + ' dus (KURANG)</span>';
                                } else {
                                    totalSelisihText = '<span class="badge bg-secondary">0 dus (SESUAI)</span>';
                                }
                                var totalUangText = response.total_uang >= 0 ? 'Rp. ' + numberFormat(response.total_uang) : '-Rp. ' + numberFormat(Math.abs(response.total_uang));
                                
                                htmlContent += '<div class="mt-3 p-3 bg-light rounded border">';
                                htmlContent += '<div class="mb-2"><strong>Total Selisih: </strong>' + totalSelisihText + '</div>';
                                htmlContent += '<div><strong>Total Nilai: <span class="' + totalSelisihClass + '">' + totalUangText + '</span></strong></div>';
                                htmlContent += '</div>';
                                htmlContent += '</div>';
                                
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Berhasil!',
                                    html: htmlContent,
                                    confirmButtonText: 'OK',
                                    confirmButtonColor: '#667eea',
                                    width: '800px'
                                }).then(() => {
                                    location.reload();
                                });
                            } else {
                                // Tampilkan error detail jika ada
                                var errorText = response.message || 'Terjadi kesalahan tidak diketahui';
                                if (response.error_detail) {
                                    errorText += '\n\nDetail Error:';
                                    if (response.error_detail.file) {
                                        errorText += '\nFile: ' + response.error_detail.file;
                                    }
                                    if (response.error_detail.line) {
                                        errorText += '\nLine: ' + response.error_detail.line;
                                    }
                                    if (response.error_detail.db_error) {
                                        errorText += '\nDatabase Error: ' + response.error_detail.db_error;
                                    }
                                }
                                
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Gagal!',
                                    text: errorText,
                                    confirmButtonColor: '#e74c3c',
                                    width: '700px'
                                });
                                
                                // Log ke console untuk debugging
                                console.error('Stock Opname Error:', response);
                            }
                        },
                        error: function(xhr, status, error) {
                            var errorMessage = 'Terjadi kesalahan saat menyimpan stock opname!';
                            
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
            });
        }

        function numberFormat(num) {
            return num ? num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '0';
        }

        // Reset modal saat ditutup
        $('#modalStockOpname').on('hidden.bs.modal', function() {
            $('#formOpname')[0].reset();
            $('#tbodyBatches').empty();
        });
    </script>
</body>
</html>
