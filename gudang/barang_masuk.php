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

// Handle AJAX request untuk get data pesan barang (untuk form validasi)
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['get_pesan_data'])) {
    header('Content-Type: application/json');
    
    $id_pesan = isset($_GET['id_pesan']) ? trim($_GET['id_pesan']) : '';
    
    if (empty($id_pesan)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Get data pesan barang lengkap
    $query_pesan = "SELECT 
        pb.ID_PESAN_BARANG,
        pb.KD_BARANG,
        pb.JUMLAH_PESAN_BARANG_DUS,
        pb.TOTAL_MASUK_DUS,
        pb.JUMLAH_DITOLAK_DUS,
        pb.TGL_EXPIRED,
        pb.HARGA_PESAN_BARANG_DUS,
        pb.BIAYA_PENGIRIMAAN,
        mb.NAMA_BARANG,
        mb.BERAT,
        COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
        COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
        COALESCE(ms.KD_SUPPLIER, '-') as SUPPLIER_KD,
        COALESCE(ms.NAMA_SUPPLIER, '-') as NAMA_SUPPLIER,
        COALESCE(ms.ALAMAT_SUPPLIER, '-') as ALAMAT_SUPPLIER
    FROM PESAN_BARANG pb
    INNER JOIN MASTER_BARANG mb ON pb.KD_BARANG = mb.KD_BARANG
    LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
    LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
    LEFT JOIN MASTER_SUPPLIER ms ON pb.KD_SUPPLIER = ms.KD_SUPPLIER
    WHERE pb.ID_PESAN_BARANG = ? AND pb.KD_LOKASI = ? AND pb.STATUS = 'DIKIRIM'";
    $stmt_pesan = $conn->prepare($query_pesan);
    if (!$stmt_pesan) {
        echo json_encode(['success' => false, 'message' => 'Gagal prepare query: ' . $conn->error]);
        exit();
    }
    $stmt_pesan->bind_param("ss", $id_pesan, $kd_lokasi);
    if (!$stmt_pesan->execute()) {
        echo json_encode(['success' => false, 'message' => 'Gagal execute query: ' . $stmt_pesan->error]);
        exit();
    }
    $result_pesan = $stmt_pesan->get_result();
    
    if ($result_pesan->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Data pesan tidak ditemukan atau sudah divalidasi!']);
        exit();
    }
    
    $pesan_data = $result_pesan->fetch_assoc();
    
    // Format supplier
    $supplier_display = '';
    if ($pesan_data['SUPPLIER_KD'] != '-' && $pesan_data['NAMA_SUPPLIER'] != '-') {
        $supplier_display = $pesan_data['SUPPLIER_KD'] . ' - ' . $pesan_data['NAMA_SUPPLIER'];
        if ($pesan_data['ALAMAT_SUPPLIER'] != '-') {
            $supplier_display .= ' - ' . $pesan_data['ALAMAT_SUPPLIER'];
        }
    } else {
        $supplier_display = '-';
    }
    
    // Format tanggal expired
    $tgl_expired = '';
    if (!empty($pesan_data['TGL_EXPIRED'])) {
        $date_expired = new DateTime($pesan_data['TGL_EXPIRED']);
        $tgl_expired = $date_expired->format('Y-m-d');
    }
    
    echo json_encode([
        'success' => true,
        'data' => [
            'id_pesan' => $pesan_data['ID_PESAN_BARANG'],
            'supplier' => $supplier_display,
            'kd_barang' => $pesan_data['KD_BARANG'],
            'nama_merek' => $pesan_data['NAMA_MEREK'],
            'nama_kategori' => $pesan_data['NAMA_KATEGORI'],
            'nama_barang' => $pesan_data['NAMA_BARANG'],
            'berat' => $pesan_data['BERAT'],
            'jumlah_dipesan' => $pesan_data['JUMLAH_PESAN_BARANG_DUS'],
            'jumlah_dikirim' => $pesan_data['TOTAL_MASUK_DUS'] ?? $pesan_data['JUMLAH_PESAN_BARANG_DUS'],
            'jumlah_ditolak' => $pesan_data['JUMLAH_DITOLAK_DUS'] ?? 0,
            'harga_pesan_dus' => $pesan_data['HARGA_PESAN_BARANG_DUS'] ?? 0,
            'biaya_pengiriman' => $pesan_data['BIAYA_PENGIRIMAAN'] ?? 0,
            'tgl_expired' => $tgl_expired
        ]
    ]);
    exit();
}

// Handle AJAX request untuk validasi barang masuk
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'validasi') {
    header('Content-Type: application/json');
    
    $id_pesan = isset($_POST['id_pesan']) ? trim($_POST['id_pesan']) : '';
    $jumlah_dikirim = isset($_POST['jumlah_dikirim']) ? (int)$_POST['jumlah_dikirim'] : 0;
    $jumlah_ditolak = isset($_POST['jumlah_ditolak']) ? (int)$_POST['jumlah_ditolak'] : 0;
    $total_masuk = isset($_POST['total_masuk']) ? (int)$_POST['total_masuk'] : 0;
    $harga_pesan_dus = isset($_POST['harga_pesan_dus']) ? floatval($_POST['harga_pesan_dus']) : 0;
    $biaya_pengiriman = isset($_POST['biaya_pengiriman']) ? floatval($_POST['biaya_pengiriman']) : 0;
    $tgl_expired = isset($_POST['tgl_expired']) ? trim($_POST['tgl_expired']) : null;
    
    if (empty($id_pesan) || $jumlah_dikirim < 0 || $jumlah_ditolak < 0 || $total_masuk < 0 || $harga_pesan_dus < 0 || $biaya_pengiriman < 0) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Validasi: total masuk harus sama dengan jumlah tiba - jumlah ditolak
    if ($total_masuk != ($jumlah_dikirim - $jumlah_ditolak)) {
        echo json_encode(['success' => false, 'message' => 'Total masuk harus sama dengan (Jumlah Tiba - Jumlah Ditolak)!']);
        exit();
    }
    
    // Mulai transaksi
    $conn->begin_transaction();
    
    try {
        // Get data pesan barang
        $query_pesan = "SELECT KD_BARANG, KD_LOKASI, JUMLAH_PESAN_BARANG_DUS, TOTAL_MASUK_DUS 
                       FROM PESAN_BARANG 
                       WHERE ID_PESAN_BARANG = ? AND KD_LOKASI = ? AND STATUS = 'DIKIRIM'";
        $stmt_pesan = $conn->prepare($query_pesan);
        if (!$stmt_pesan) {
            throw new Exception('Gagal prepare query get pesan barang: ' . $conn->error);
        }
        $stmt_pesan->bind_param("ss", $id_pesan, $kd_lokasi);
        if (!$stmt_pesan->execute()) {
            throw new Exception('Gagal execute query get pesan barang: ' . $stmt_pesan->error);
        }
        $result_pesan = $stmt_pesan->get_result();
        
        if ($result_pesan->num_rows == 0) {
            throw new Exception('Data pesan tidak ditemukan atau sudah divalidasi!');
        }
        
        $pesan_data = $result_pesan->fetch_assoc();
        $kd_barang = $pesan_data['KD_BARANG'];
        
        // JUMLAH_TIBA_DUS = jumlah yang tiba (jumlah_dikirim, termasuk yang ditolak)
        $jumlah_tiba_dus = $jumlah_dikirim;
        
        // Update status PESAN_BARANG menjadi SELESAI dan set WAKTU_SELESAI, HARGA_PESAN_BARANG_DUS, BIAYA_PENGIRIMAAN, dan SISA_STOCK_DUS
        if (!empty($tgl_expired)) {
            $update_pesan = "UPDATE PESAN_BARANG 
                            SET STATUS = 'SELESAI', 
                                WAKTU_SELESAI = CURRENT_TIMESTAMP,
                                JUMLAH_TIBA_DUS = ?,
                                TOTAL_MASUK_DUS = ?,
                                JUMLAH_DITOLAK_DUS = ?,
                                HARGA_PESAN_BARANG_DUS = ?,
                                BIAYA_PENGIRIMAAN = ?,
                                SISA_STOCK_DUS = ?,
                                TGL_EXPIRED = ?
                            WHERE ID_PESAN_BARANG = ?";
            $stmt_update = $conn->prepare($update_pesan);
            if (!$stmt_update) {
                throw new Exception('Gagal prepare query update pesan barang: ' . $conn->error);
            }
            $stmt_update->bind_param("iiiddiss", $jumlah_tiba_dus, $total_masuk, $jumlah_ditolak, $harga_pesan_dus, $biaya_pengiriman, $total_masuk, $tgl_expired, $id_pesan);
        } else {
            $update_pesan = "UPDATE PESAN_BARANG 
                            SET STATUS = 'SELESAI', 
                                WAKTU_SELESAI = CURRENT_TIMESTAMP,
                                JUMLAH_TIBA_DUS = ?,
                                TOTAL_MASUK_DUS = ?,
                                JUMLAH_DITOLAK_DUS = ?,
                                HARGA_PESAN_BARANG_DUS = ?,
                                BIAYA_PENGIRIMAAN = ?,
                                SISA_STOCK_DUS = ?
                            WHERE ID_PESAN_BARANG = ?";
            $stmt_update = $conn->prepare($update_pesan);
            if (!$stmt_update) {
                throw new Exception('Gagal prepare query update pesan barang: ' . $conn->error);
            }
            $stmt_update->bind_param("iiiddis", $jumlah_tiba_dus, $total_masuk, $jumlah_ditolak, $harga_pesan_dus, $biaya_pengiriman, $total_masuk, $id_pesan);
        }
        if (!$stmt_update->execute()) {
            throw new Exception('Gagal mengupdate data pesan barang: ' . $stmt_update->error);
        }
        
        // Get stock saat ini
        $query_stock = "SELECT JUMLAH_BARANG, SATUAN FROM STOCK WHERE KD_BARANG = ? AND KD_LOKASI = ?";
        $stmt_stock = $conn->prepare($query_stock);
        $stmt_stock->bind_param("ss", $kd_barang, $kd_lokasi);
        $stmt_stock->execute();
        $result_stock = $stmt_stock->get_result();
        
        if ($result_stock->num_rows > 0) {
            $stock_data = $result_stock->fetch_assoc();
            $jumlah_awal = $stock_data['JUMLAH_BARANG'];
            $jumlah_akhir = $jumlah_awal + $total_masuk;
            $satuan = $stock_data['SATUAN'];
            
            // Update STOCK
            $update_stock = "UPDATE STOCK 
                           SET JUMLAH_BARANG = ?, 
                               LAST_UPDATED = CURRENT_TIMESTAMP,
                               UPDATED_BY = ?
                           WHERE KD_BARANG = ? AND KD_LOKASI = ?";
            $stmt_update_stock = $conn->prepare($update_stock);
            if (!$stmt_update_stock) {
                throw new Exception('Gagal prepare query update stock: ' . $conn->error);
            }
            $stmt_update_stock->bind_param("isss", $jumlah_akhir, $user_id, $kd_barang, $kd_lokasi);
            if (!$stmt_update_stock->execute()) {
                throw new Exception('Gagal mengupdate stock: ' . $stmt_update_stock->error);
            }
            
            // Insert ke STOCK_HISTORY
            require_once '../includes/uuid_generator.php';
            $id_history = '';
            do {
                // Generate ID_HISTORY_STOCK dengan format SKHY+UUID (total 16 karakter: SKHY=4, UUID=12)
                $uuid = ShortIdGenerator::generate(12, '');
                $id_history = 'SKHY' . $uuid;
            } while (checkUUIDExists($conn, 'STOCK_HISTORY', 'ID_HISTORY_STOCK', $id_history));
            
            $insert_history = "INSERT INTO STOCK_HISTORY 
                              (ID_HISTORY_STOCK, KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_AWAL, JUMLAH_PERUBAHAN, JUMLAH_AKHIR, TIPE_PERUBAHAN, REF, SATUAN)
                              VALUES (?, ?, ?, ?, ?, ?, ?, 'PEMESANAN', ?, ?)";
            $stmt_history = $conn->prepare($insert_history);
            if (!$stmt_history) {
                throw new Exception('Gagal prepare query insert history: ' . $conn->error);
            }
            $stmt_history->bind_param("ssssiiiss", $id_history, $kd_barang, $kd_lokasi, $user_id, $jumlah_awal, $total_masuk, $jumlah_akhir, $id_pesan, $satuan);
            if (!$stmt_history->execute()) {
                throw new Exception('Gagal insert history: ' . $stmt_history->error);
            }
        }
        
        // Update AVG_HARGA_BELI_PIECES di MASTER_BARANG
        // Hitung weighted average per PIECE dari semua pembelian yang sudah selesai
        // HARGA_PESAN_BARANG_DUS adalah harga per dus, perlu dikonversi ke harga per piece
        if ($harga_pesan_dus > 0 && $total_masuk > 0) {
            // Query untuk menghitung weighted average per PIECE
            // HARGA_PESAN_BARANG_DUS adalah harga per dus
            // TOTAL_MASUK_DUS adalah jumlah dus yang masuk
            // mb.SATUAN_PERDUS adalah jumlah pieces per dus
            // 
            // Untuk menghitung harga per piece:
            // - Total harga untuk semua pieces = HARGA_PESAN_BARANG_DUS * TOTAL_MASUK_DUS (total harga dalam rupiah)
            // - Total pieces = TOTAL_MASUK_DUS * SATUAN_PERDUS
            // - Harga per piece = (HARGA_PESAN_BARANG_DUS * TOTAL_MASUK_DUS) / (TOTAL_MASUK_DUS * SATUAN_PERDUS)
            //                    = HARGA_PESAN_BARANG_DUS / SATUAN_PERDUS
            //
            // Untuk weighted average dari semua pembelian:
            // - total_harga_quantity = SUM(HARGA_PESAN_BARANG_DUS * TOTAL_MASUK_DUS) untuk semua pembelian
            // - total_quantity = SUM(TOTAL_MASUK_DUS * SATUAN_PERDUS) untuk semua pembelian
            // - avg_harga_beli = total_harga_quantity / total_quantity (harga per piece)
            $query_avg = "SELECT 
                COALESCE(SUM(pb.HARGA_PESAN_BARANG_DUS * pb.TOTAL_MASUK_DUS), 0) as total_harga_quantity,
                COALESCE(SUM(pb.TOTAL_MASUK_DUS * mb.SATUAN_PERDUS), 0) as total_quantity
            FROM PESAN_BARANG pb
            INNER JOIN MASTER_BARANG mb ON pb.KD_BARANG = mb.KD_BARANG
            WHERE pb.KD_BARANG = ? AND pb.STATUS = 'SELESAI' AND pb.TOTAL_MASUK_DUS > 0";
            $stmt_avg = $conn->prepare($query_avg);
            if (!$stmt_avg) {
                throw new Exception('Gagal prepare query avg harga: ' . $conn->error);
            }
            $stmt_avg->bind_param("s", $kd_barang);
            if (!$stmt_avg->execute()) {
                throw new Exception('Gagal execute query avg harga: ' . $stmt_avg->error);
            }
            $result_avg = $stmt_avg->get_result();
            
            if ($result_avg->num_rows > 0) {
                $avg_data = $result_avg->fetch_assoc();
                $total_harga_quantity = $avg_data['total_harga_quantity'];
                $total_quantity = $avg_data['total_quantity'];
                
                if ($total_quantity > 0) {
                    // AVG_HARGA_BELI_PIECES per piece = total_harga_quantity / total_quantity
                    // total_harga_quantity = total harga dalam rupiah untuk semua pieces
                    // total_quantity = total pieces dari semua pembelian
                    $avg_harga_beli = $total_harga_quantity / $total_quantity;
                    
                    // Update AVG_HARGA_BELI_PIECES di MASTER_BARANG (per piece)
                    $update_avg = "UPDATE MASTER_BARANG SET AVG_HARGA_BELI_PIECES = ? WHERE KD_BARANG = ?";
                    $stmt_update_avg = $conn->prepare($update_avg);
                    if (!$stmt_update_avg) {
                        throw new Exception('Gagal prepare query update avg harga: ' . $conn->error);
                    }
                    $stmt_update_avg->bind_param("ds", $avg_harga_beli, $kd_barang);
                    if (!$stmt_update_avg->execute()) {
                        throw new Exception('Gagal mengupdate AVG_HARGA_BELI_PIECES: ' . $stmt_update_avg->error);
                    }
                }
            }
        }
        
        // Commit transaksi
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Barang berhasil divalidasi dan stock diperbarui!']);
    } catch (Exception $e) {
        // Rollback transaksi
        $conn->rollback();
        // Log error untuk debugging
        error_log('Error validasi barang masuk: ' . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Gagal memvalidasi barang: ' . $e->getMessage()
        ]);
    } catch (Error $e) {
        // Rollback transaksi
        $conn->rollback();
        // Log error untuk debugging
        error_log('Fatal error validasi barang masuk: ' . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Terjadi kesalahan sistem: ' . $e->getMessage()
        ]);
    }
    
    exit();
}

// Query untuk mendapatkan data barang masuk (status DIKIRIM dan SELESAI)
$query_barang_masuk = "SELECT 
    pb.ID_PESAN_BARANG,
    pb.KD_BARANG,
    pb.JUMLAH_PESAN_BARANG_DUS,
    pb.TOTAL_MASUK_DUS,
    pb.JUMLAH_DITOLAK_DUS,
    pb.WAKTU_PESAN,
    pb.WAKTU_ESTIMASI_SELESAI,
    pb.WAKTU_SELESAI,
    pb.STATUS,
    mb.NAMA_BARANG,
    mb.BERAT,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
    COALESCE(ms.KD_SUPPLIER, '-') as SUPPLIER_KD,
    COALESCE(ms.NAMA_SUPPLIER, '-') as NAMA_SUPPLIER,
    COALESCE(ms.ALAMAT_SUPPLIER, '-') as ALAMAT_SUPPLIER,
    s.SATUAN
FROM PESAN_BARANG pb
INNER JOIN MASTER_BARANG mb ON pb.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
LEFT JOIN MASTER_SUPPLIER ms ON pb.KD_SUPPLIER = ms.KD_SUPPLIER
LEFT JOIN STOCK s ON pb.KD_BARANG = s.KD_BARANG AND pb.KD_LOKASI = s.KD_LOKASI
WHERE pb.KD_LOKASI = ? AND pb.STATUS IN ('DIKIRIM', 'SELESAI')
ORDER BY pb.WAKTU_PESAN DESC";
$stmt_barang_masuk = $conn->prepare($query_barang_masuk);
$stmt_barang_masuk->bind_param("s", $kd_lokasi);
$stmt_barang_masuk->execute();
$result_barang_masuk = $stmt_barang_masuk->get_result();

// Format tanggal dan waktu (dd/mm/yyyy HH:ii WIB)
function formatTanggalWaktu($tanggal) {
    if (empty($tanggal) || $tanggal == null) {
        return '-';
    }
    $date = new DateTime($tanggal);
    return $date->format('d/m/Y H:i') . ' WIB';
}

// Format waktu dengan badge untuk kolom waktu (stack) (dd/mm/yyyy HH:ii WIB)
function formatWaktuStack($waktu_pesan, $waktu_estimasi, $waktu_sampai, $status) {
    $html = '<div class="d-flex flex-column gap-1">';
    
    // Waktu diterima (jika ada WAKTU_SELESAI dan status SELESAI) - tampilkan di atas
    if (!empty($waktu_sampai) && $status == 'SELESAI') {
        $date_sampai = new DateTime($waktu_sampai);
        $waktu_sampai_formatted = $date_sampai->format('d/m/Y H:i') . ' WIB';
        $html .= '<div>';
        $html .= htmlspecialchars($waktu_sampai_formatted) . ' ';
        $html .= '<span class="badge bg-success">DITERIMA</span>';
        $html .= '</div>';
    }
    
    // Waktu estimasi - tampilkan di tengah (jika ada)
    if (!empty($waktu_estimasi)) {
        $date_estimasi = new DateTime($waktu_estimasi);
        $waktu_estimasi_formatted = $date_estimasi->format('d/m/Y H:i') . ' WIB';
        $html .= '<div>';
        $html .= htmlspecialchars($waktu_estimasi_formatted) . ' ';
        $html .= '<span class="badge bg-info">ESTIMASI</span>';
        $html .= '</div>';
    }
    
    // Waktu dipesan - tampilkan di bawah
    if (!empty($waktu_pesan)) {
        $date_pesan = new DateTime($waktu_pesan);
        $waktu_pesan_formatted = $date_pesan->format('d/m/Y H:i') . ' WIB';
        $html .= '<div>';
        $html .= htmlspecialchars($waktu_pesan_formatted) . ' ';
        $html .= '<span class="badge bg-warning">DIPESAN</span>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    return $html;
}

// Set active page untuk sidebar
$active_page = 'barang_masuk';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gudang - Barang Masuk</title>
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
            <h1 class="page-title">Gudang <?php echo htmlspecialchars($nama_lokasi); ?> - Barang Masuk</h1>
            <?php if (!empty($alamat_lokasi)): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($alamat_lokasi); ?></p>
            <?php endif; ?>
        </div>

        <!-- Table Barang Masuk -->
        <div class="table-section" style="width: 100%;">
            <div class="table-responsive" style="width: 100%;">
                <table id="tableBarangMasuk" class="table table-custom table-striped table-hover" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>ID Pesan</th>
                            <th>Supplier</th>
                            <th>Waktu</th>
                            <th>Kode Barang</th>
                            <th>Merek Barang</th>
                            <th>Kategori Barang</th>
                            <th>Nama Barang</th>
                            <th>Berat</th>
                            <th>Jumlah Pesan</th>
                            <th>Total Masuk</th>
                            <th>Jumlah Ditolak</th>
                            <th>Satuan</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_barang_masuk->num_rows > 0): ?>
                            <?php while ($row = $result_barang_masuk->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['ID_PESAN_BARANG']); ?></td>
                                    <td>
                                        <?php 
                                        $supplier_display = '';
                                        if ($row['SUPPLIER_KD'] != '-' && $row['NAMA_SUPPLIER'] != '-') {
                                            $supplier_display = htmlspecialchars($row['SUPPLIER_KD'] . ' - ' . $row['NAMA_SUPPLIER']);
                                            if ($row['ALAMAT_SUPPLIER'] != '-') {
                                                $supplier_display .= ' - ' . htmlspecialchars($row['ALAMAT_SUPPLIER']);
                                            }
                                        } else {
                                            $supplier_display = '-';
                                        }
                                        echo $supplier_display;
                                        ?>
                                    </td>
                                    <td><?php echo formatWaktuStack($row['WAKTU_PESAN'], $row['WAKTU_ESTIMASI_SELESAI'], $row['WAKTU_SELESAI'], $row['STATUS']); ?></td>
                                    <td><?php echo htmlspecialchars($row['KD_BARANG']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_MEREK']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_BARANG']); ?></td>
                                    <td><?php echo number_format($row['BERAT'], 0, ',', '.'); ?></td>
                                    <td><?php echo number_format($row['JUMLAH_PESAN_BARANG_DUS'], 0, ',', '.'); ?></td>
                                    <td><?php echo $row['TOTAL_MASUK_DUS'] ? number_format($row['TOTAL_MASUK_DUS'], 0, ',', '.') : '-'; ?></td>
                                    <td><?php echo $row['JUMLAH_DITOLAK_DUS'] ? number_format($row['JUMLAH_DITOLAK_DUS'], 0, ',', '.') : '-'; ?></td>
                                    <td><?php echo htmlspecialchars($row['SATUAN'] ?? 'Dus'); ?></td>
                                    <td>
                                        <?php 
                                        $status_text = '';
                                        $status_class = '';
                                        switch($row['STATUS']) {
                                            case 'DIKIRIM':
                                                $status_text = 'Dikirim';
                                                $status_class = 'info';
                                                break;
                                            case 'SELESAI':
                                                $status_text = 'Selesai';
                                                $status_class = 'success';
                                                break;
                                            default:
                                                $status_text = $row['STATUS'];
                                                $status_class = 'secondary';
                                        }
                                        ?>
                                        <span class="badge bg-<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                    </td>
                                    <td>
                                        <?php if ($row['STATUS'] == 'DIKIRIM'): ?>
                                            <button class="btn btn-success btn-sm" onclick="validasiBarang('<?php echo htmlspecialchars($row['ID_PESAN_BARANG']); ?>')">Validasi</button>
                                        <?php elseif ($row['STATUS'] == 'SELESAI'): ?>
                                            <span class="text-muted">-</span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Validasi Barang Masuk -->
    <div class="modal fade" id="modalValidasi" tabindex="-1" aria-labelledby="modalValidasiLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalValidasiLabel">VALIDASI BARANG MASUK</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="formValidasi">
                        <input type="hidden" id="validasi_id_pesan" name="id_pesan">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">ID PESAN</label>
                                <input type="text" class="form-control form-control-sm" id="validasi_id_pesan_display" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-bold small">Supplier</label>
                                <input type="text" class="form-control form-control-sm" id="validasi_supplier" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Kode Barang</label>
                                <input type="text" class="form-control form-control-sm" id="validasi_kd_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Merek Barang</label>
                                <input type="text" class="form-control form-control-sm" id="validasi_merek" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Kategori Barang</label>
                                <input type="text" class="form-control form-control-sm" id="validasi_kategori" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-bold small">Nama Barang</label>
                                <input type="text" class="form-control form-control-sm" id="validasi_nama_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Berat (gr)</label>
                                <input type="text" class="form-control form-control-sm" id="validasi_berat" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Jumlah Dipesan (dus)</label>
                                <input type="text" class="form-control form-control-sm" id="validasi_jumlah_dipesan" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Jumlah Tiba (dus) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control form-control-sm" id="validasi_jumlah_dikirim" name="jumlah_dikirim" min="0" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Jumlah Ditolak (dus) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control form-control-sm" id="validasi_jumlah_ditolak" name="jumlah_ditolak" min="0" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Harga Barang (dus) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="validasi_harga_pesan" name="harga_pesan_dus" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Biaya Pengiriman <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="validasi_biaya_pengiriman" name="biaya_pengiriman" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Tanggal Expired</label>
                                <input type="date" class="form-control form-control-sm" id="validasi_tgl_expired" name="tgl_expired">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Total Masuk (dus)</label>
                                <input type="text" class="form-control form-control-sm" id="validasi_total_masuk" name="total_masuk" readonly style="background-color: #e9ecef;">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-success" onclick="simpanValidasi()">Simpan</button>
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
            
            $('#tableBarangMasuk').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data barang masuk'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[2, 'desc']], // Sort by Waktu descending
                columnDefs: [
                    { orderable: false, targets: 13 } // Disable sorting on Action column
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
        });

        function validasiBarang(idPesan) {
            // Load data pesan barang
            $.ajax({
                url: 'barang_masuk.php',
                method: 'GET',
                data: {
                    get_pesan_data: '1',
                    id_pesan: idPesan
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        
                        // Isi form
                        $('#validasi_id_pesan').val(data.id_pesan);
                        $('#validasi_id_pesan_display').val(data.id_pesan);
                        $('#validasi_supplier').val(data.supplier);
                        $('#validasi_kd_barang').val(data.kd_barang);
                        $('#validasi_merek').val(data.nama_merek);
                        $('#validasi_kategori').val(data.nama_kategori);
                        $('#validasi_nama_barang').val(data.nama_barang);
                        $('#validasi_berat').val(data.berat.toLocaleString('id-ID'));
                        $('#validasi_jumlah_dipesan').val(data.jumlah_dipesan.toLocaleString('id-ID'));
                        // Simpan jumlah dipesan dalam format angka untuk perbandingan
                        $('#validasi_jumlah_dipesan').data('jumlah-dipesan', data.jumlah_dipesan);
                        $('#validasi_jumlah_dikirim').val(data.jumlah_dikirim);
                        $('#validasi_jumlah_ditolak').val(data.jumlah_ditolak);
                        // Format rupiah untuk harga dan biaya pengiriman
                        $('#validasi_harga_pesan').val(formatRupiah(data.harga_pesan_dus || 0));
                        $('#validasi_biaya_pengiriman').val(formatRupiah(data.biaya_pengiriman || 0));
                        $('#validasi_tgl_expired').val(data.tgl_expired);
                        
                        // Hitung total masuk
                        hitungTotalMasuk();
                        
                        // Buka modal
                        var modal = new bootstrap.Modal(document.getElementById('modalValidasi'));
                        modal.show();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: response.message,
                            confirmButtonColor: '#e74c3c'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Terjadi kesalahan saat mengambil data!',
                        confirmButtonColor: '#e74c3c'
                    });
                }
            });
        }

        function hitungTotalMasuk() {
            var jumlahDikirim = parseInt($('#validasi_jumlah_dikirim').val()) || 0;
            var jumlahDitolak = parseInt($('#validasi_jumlah_ditolak').val()) || 0;
            var totalMasuk = jumlahDikirim - jumlahDitolak;
            
            if (totalMasuk < 0) {
                totalMasuk = 0;
            }
            
            $('#validasi_total_masuk').val(totalMasuk.toLocaleString('id-ID'));
        }

        // Event listener untuk hitung otomatis
        $(document).on('input', '#validasi_jumlah_dikirim, #validasi_jumlah_ditolak', function() {
            hitungTotalMasuk();
        });
        
        // Format rupiah untuk input harga dan biaya pengiriman
        function formatRupiah(angka) {
            if (!angka && angka !== 0) return '';
            
            // Konversi ke string dan hapus semua karakter non-digit kecuali titik dan koma
            var number_string = angka.toString().replace(/[^\d.,]/g, '');
            
            // Pisahkan bagian integer dan desimal
            var parts = number_string.split(/[.,]/);
            var integerPart = parts[0] || '0';
            var decimalPart = parts[1] || '';
            
            // Format integer dengan titik sebagai pemisah ribuan
            var formattedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            
            // Gabungkan dengan desimal jika ada
            var result = formattedInteger;
            if (decimalPart) {
                result += ',' + decimalPart;
            }
            
            return 'Rp ' + result;
        }
        
        function unformatRupiah(rupiah) {
            if (!rupiah) return 0;
            // Hapus "Rp " dan spasi, ganti titik (ribuan) dengan kosong, ganti koma (desimal) dengan titik
            var cleaned = rupiah.toString().replace(/Rp\s?/g, '').replace(/\./g, '').replace(',', '.');
            return parseFloat(cleaned) || 0;
        }
        
        // Event listener untuk format rupiah saat input
        $(document).on('input', '#validasi_harga_pesan, #validasi_biaya_pengiriman', function() {
            var value = $(this).val();
            // Simpan posisi cursor
            var cursorPosition = this.selectionStart;
            var originalLength = value.length;
            
            // Unformat dan format ulang
            var unformatted = unformatRupiah(value);
            var formatted = formatRupiah(unformatted);
            
            // Set nilai yang sudah diformat
            $(this).val(formatted);
            
            // Perbaiki posisi cursor setelah format
            var newLength = formatted.length;
            var lengthDiff = newLength - originalLength;
            var newCursorPosition = cursorPosition + lengthDiff;
            
            // Set cursor position
            this.setSelectionRange(newCursorPosition, newCursorPosition);
        });

        function simpanValidasi() {
            // Validasi form
            if (!$('#formValidasi')[0].checkValidity()) {
                $('#formValidasi')[0].reportValidity();
                return;
            }

            var jumlahDikirim = parseInt($('#validasi_jumlah_dikirim').val()) || 0;
            var jumlahDitolak = parseInt($('#validasi_jumlah_ditolak').val()) || 0;
            
            // Ambil total masuk dari input (hilangkan format angka)
            var totalMasukStr = $('#validasi_total_masuk').val().replace(/\./g, '').replace(/,/g, '');
            var totalMasuk = parseInt(totalMasukStr) || 0;

            if (jumlahDikirim < 0 || jumlahDitolak < 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error Validasi!',
                    text: 'Jumlah tiba dan jumlah ditolak tidak boleh negatif!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }

            if (jumlahDitolak > jumlahDikirim) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error Validasi!',
                    text: 'Jumlah ditolak tidak boleh lebih besar dari jumlah tiba!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }

            // Validasi total masuk
            var calculatedTotal = jumlahDikirim - jumlahDitolak;
            if (totalMasuk !== calculatedTotal) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error Validasi!',
                    text: 'Total masuk (' + totalMasuk + ') harus sama dengan (Jumlah Tiba - Jumlah Ditolak) = ' + calculatedTotal,
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }

            // Ambil harga dan biaya pengiriman (hapus format rupiah)
            var hargaPesanDus = unformatRupiah($('#validasi_harga_pesan').val()) || 0;
            var biayaPengiriman = unformatRupiah($('#validasi_biaya_pengiriman').val()) || 0;
            
            if (hargaPesanDus < 0 || biayaPengiriman < 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error Validasi!',
                    text: 'Harga barang dan biaya pengiriman tidak boleh negatif!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }

            // Cek apakah total masuk sesuai dengan jumlah yang dipesan
            var jumlahDipesan = parseInt($('#validasi_jumlah_dipesan').data('jumlah-dipesan')) || 0;
            
            if (totalMasuk !== jumlahDipesan) {
                // Tampilkan konfirmasi jika total masuk tidak sesuai dengan yang dipesan
                Swal.fire({
                    icon: 'warning',
                    title: 'Konfirmasi Total Masuk',
                    html: 'Total masuk (' + totalMasuk.toLocaleString('id-ID') + ' dus) tidak sesuai dengan jumlah yang dipesan (' + jumlahDipesan.toLocaleString('id-ID') + ' dus).<br><br>Apakah Anda yakin ingin melanjutkan?',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Lanjutkan',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#28a745',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        // Jika user konfirmasi, lanjutkan proses validasi
                        prosesValidasi();
                    }
                });
                return;
            }

            // Jika total masuk sesuai dengan yang dipesan, langsung proses validasi
            prosesValidasi();
        }

        function prosesValidasi() {
            var jumlahDikirim = parseInt($('#validasi_jumlah_dikirim').val()) || 0;
            var jumlahDitolak = parseInt($('#validasi_jumlah_ditolak').val()) || 0;
            
            // Ambil total masuk dari input (hilangkan format angka)
            var totalMasukStr = $('#validasi_total_masuk').val().replace(/\./g, '').replace(/,/g, '');
            var totalMasuk = parseInt(totalMasukStr) || 0;

            // Ambil harga dan biaya pengiriman (hapus format rupiah)
            var hargaPesanDus = unformatRupiah($('#validasi_harga_pesan').val()) || 0;
            var biayaPengiriman = unformatRupiah($('#validasi_biaya_pengiriman').val()) || 0;

            // AJAX request untuk validasi
            $.ajax({
                url: 'barang_masuk.php',
                method: 'POST',
                data: {
                    action: 'validasi',
                    id_pesan: $('#validasi_id_pesan').val(),
                    jumlah_dikirim: jumlahDikirim,
                    jumlah_ditolak: jumlahDitolak,
                    total_masuk: totalMasuk,
                    harga_pesan_dus: hargaPesanDus,
                    biaya_pengiriman: biayaPengiriman,
                    tgl_expired: $('#validasi_tgl_expired').val() || ''
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'Berhasil!',
                            text: response.message,
                            confirmButtonColor: '#28a745',
                            timer: 1500,
                            timerProgressBar: true
                        }).then(() => {
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
                    var errorMessage = 'Terjadi kesalahan saat memvalidasi barang!';
                    
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
                        } else {
                            errorMessage = 'Error ' + xhr.status + ': ' + error;
                        }
                    }
                    
                    Swal.fire({
                        icon: 'error',
                        title: 'Error Sistem!',
                        text: errorMessage,
                        confirmButtonColor: '#e74c3c',
                        footer: '<small>Status: ' + status + ' | Error: ' + error + '</small>'
                    });
                }
            });
        }
    </script>
</body>
</html>

