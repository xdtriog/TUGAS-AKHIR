<?php
session_start();
require_once '../dbconnect.php';
require_once '../includes/uuid_generator.php';

// Cek apakah user sudah login
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
    exit();
}

// Handle AJAX request untuk update status
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_status') {
    header('Content-Type: application/json');
    
    $id_pesan = isset($_POST['id_pesan']) ? trim($_POST['id_pesan']) : '';
    $status_baru = isset($_POST['status']) ? trim($_POST['status']) : '';
    
    if (empty($id_pesan) || empty($status_baru)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Update status
    $update_query = "UPDATE PESAN_BARANG SET STATUS = ? WHERE ID_PESAN_BARANG = ?";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bind_param("ss", $status_baru, $id_pesan);
    
    if ($update_stmt->execute()) {
        // Jika status diubah menjadi DIKIRIM, update WAKTU_SELESAI
        if ($status_baru == 'DIKIRIM') {
            $update_waktu = "UPDATE PESAN_BARANG SET WAKTU_SELESAI = CURRENT_TIMESTAMP WHERE ID_PESAN_BARANG = ?";
            $update_waktu_stmt = $conn->prepare($update_waktu);
            $update_waktu_stmt->bind_param("s", $id_pesan);
            $update_waktu_stmt->execute();
            $update_waktu_stmt->close();
        }
        
        echo json_encode(['success' => true, 'message' => 'Status berhasil diperbarui']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal memperbarui status!']);
    }
    
    $update_stmt->close();
    exit();
}

// Handle AJAX request untuk koreksi
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'koreksi') {
    header('Content-Type: application/json');
    
    $id_pesan = isset($_POST['id_pesan']) ? trim($_POST['id_pesan']) : '';
    $jumlah_tiba = isset($_POST['jumlah_tiba']) ? intval($_POST['jumlah_tiba']) : 0;
    $jumlah_ditolak = isset($_POST['jumlah_ditolak']) ? intval($_POST['jumlah_ditolak']) : 0;
    $harga_pesan_dus = isset($_POST['harga_pesan_dus']) ? floatval($_POST['harga_pesan_dus']) : 0;
    $biaya_pengiriman = isset($_POST['biaya_pengiriman']) ? floatval($_POST['biaya_pengiriman']) : 0;
    $tgl_expired = isset($_POST['tgl_expired']) ? trim($_POST['tgl_expired']) : '';
    $user_id = $_SESSION['user_id'];
    
    if (empty($id_pesan) || $jumlah_tiba < 0 || $jumlah_ditolak < 0 || $harga_pesan_dus < 0 || $biaya_pengiriman < 0 || empty($tgl_expired)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid! Semua field wajib diisi!']);
        exit();
    }
    
    // Mulai transaksi
    $conn->begin_transaction();
    
    try {
        // Get data pesan barang LAMA (sebelum koreksi) - ambil TOTAL_MASUK_DUS lama
        $query_pesan = "SELECT KD_BARANG, KD_LOKASI, JUMLAH_PESAN_BARANG_DUS, TOTAL_MASUK_DUS 
                       FROM PESAN_BARANG 
                       WHERE ID_PESAN_BARANG = ?";
        $stmt_pesan = $conn->prepare($query_pesan);
        $stmt_pesan->bind_param("s", $id_pesan);
        $stmt_pesan->execute();
        $result_pesan = $stmt_pesan->get_result();
        
        if ($result_pesan->num_rows == 0) {
            throw new Exception('Data pesan tidak ditemukan!');
        }
        
        $pesan_data = $result_pesan->fetch_assoc();
        $kd_barang = $pesan_data['KD_BARANG'];
        $kd_lokasi = $pesan_data['KD_LOKASI'];
        $jumlah_pesan = $pesan_data['JUMLAH_PESAN_BARANG_DUS'];
        $total_masuk_lama = intval($pesan_data['TOTAL_MASUK_DUS'] ?? 0); // TOTAL_MASUK_DUS sebelum koreksi
        
        // Validasi: jumlah_tiba tidak boleh melebihi jumlah_pesan
        if ($jumlah_tiba > $jumlah_pesan) {
            throw new Exception('Jumlah Tiba tidak boleh melebihi Jumlah Pesan!');
        }
        
        // Validasi: jumlah_ditolak tidak boleh melebihi jumlah_tiba
        if ($jumlah_ditolak > $jumlah_tiba) {
            throw new Exception('Jumlah Ditolak tidak boleh melebihi Jumlah Tiba!');
        }
        
        // Hitung total masuk dan sisa stock baru dari input
        $total_masuk_baru = $jumlah_tiba - $jumlah_ditolak; // Total Masuk (dus) dari input
        $sisa_stock_baru = $total_masuk_baru; // SISA_STOCK_DUS = TOTAL_MASUK_DUS
        
        // Pastikan semua nilai dalam tipe yang benar
        $jumlah_tiba = (int)$jumlah_tiba;
        $jumlah_ditolak = (int)$jumlah_ditolak;
        $total_masuk_baru = (int)$total_masuk_baru;
        $sisa_stock_baru = (int)$sisa_stock_baru;
        $harga_pesan_dus = (float)$harga_pesan_dus;
        $biaya_pengiriman = (float)$biaya_pengiriman;
        $tgl_expired = trim($tgl_expired);
        $id_pesan = trim($id_pesan);
        
        // Update PESAN_BARANG berdasarkan ID_PESAN_BARANG yang dipilih
        $update_pesan = "UPDATE PESAN_BARANG 
                        SET JUMLAH_TIBA_DUS = ?,
                            JUMLAH_DITOLAK_DUS = ?,
                            TOTAL_MASUK_DUS = ?,
                            SISA_STOCK_DUS = ?,
                            HARGA_PESAN_BARANG_DUS = ?,
                            BIAYA_PENGIRIMAAN = ?,
                            TGL_EXPIRED = ?
                        WHERE ID_PESAN_BARANG = ?";
        $stmt_update_pesan = $conn->prepare($update_pesan);
        if (!$stmt_update_pesan) {
            throw new Exception('Gagal prepare query update pesan: ' . $conn->error);
        }
        // 8 parameter: iiidddss (int, int, int, int, double, double, string, string)
        $stmt_update_pesan->bind_param("iiidddss", $jumlah_tiba, $jumlah_ditolak, $total_masuk_baru, $sisa_stock_baru, $harga_pesan_dus, $biaya_pengiriman, $tgl_expired, $id_pesan);
        if (!$stmt_update_pesan->execute()) {
            throw new Exception('Gagal update pesan barang: ' . $stmt_update_pesan->error);
        }
        
        // Get stock saat ini (total semua batch) SEBELUM koreksi untuk history
        $query_stock = "SELECT JUMLAH_BARANG FROM STOCK WHERE KD_BARANG = ? AND KD_LOKASI = ?";
        $stmt_stock = $conn->prepare($query_stock);
        $stmt_stock->bind_param("ss", $kd_barang, $kd_lokasi);
        $stmt_stock->execute();
        $result_stock = $stmt_stock->get_result();
        $stock_sebelum = $result_stock->num_rows > 0 ? intval($result_stock->fetch_assoc()['JUMLAH_BARANG']) : 0;
        
        // Hitung perubahan stock untuk update STOCK
        // Selisih perubahan = TOTAL_MASUK_DUS (baru) - TOTAL_MASUK_DUS (lama)
        $jumlah_perubahan = $total_masuk_baru - $total_masuk_lama;
        
        // Hitung stock setelah koreksi
        $stock_setelah = $stock_sebelum + $jumlah_perubahan;
        
        // Untuk STOCK_HISTORY: catat JUMLAH_BARANG di STOCK (total semua batch)
        // JUMLAH_AWAL = JUMLAH_BARANG di STOCK sebelum koreksi
        $jumlah_awal_history = $stock_sebelum;
        // JUMLAH_PERUBAHAN = selisih perubahan (positif = masuk, negatif = keluar)
        // JUMLAH_AKHIR = JUMLAH_BARANG di STOCK setelah koreksi
        $jumlah_akhir_history = $stock_setelah;
        
        // Insert ke STOCK_HISTORY dengan REF = ID_PESAN_BARANG
        $id_history = '';
        do {
            $uuid = ShortIdGenerator::generate(12, '');
            $id_history = 'SKHY' . $uuid;
        } while (checkUUIDExists($conn, 'STOCK_HISTORY', 'ID_HISTORY_STOCK', $id_history));
        
        $insert_history = "INSERT INTO STOCK_HISTORY 
                          (ID_HISTORY_STOCK, KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_AWAL, JUMLAH_PERUBAHAN, JUMLAH_AKHIR, TIPE_PERUBAHAN, REF, SATUAN)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 'KOREKSI', ?, 'DUS')";
        $stmt_history = $conn->prepare($insert_history);
        if (!$stmt_history) {
            throw new Exception('Gagal prepare query insert history: ' . $conn->error);
        }
        $stmt_history->bind_param("ssssiiis", $id_history, $kd_barang, $kd_lokasi, $user_id, $jumlah_awal_history, $jumlah_perubahan, $jumlah_akhir_history, $id_pesan);
        if (!$stmt_history->execute()) {
            throw new Exception('Gagal insert history: ' . $stmt_history->error);
        }
        
        // Update STOCK untuk total semua batch (tambahkan selisih perubahan)
        if ($jumlah_perubahan != 0) {
            // Cek apakah STOCK sudah ada, jika tidak buat baru
            if ($result_stock->num_rows == 0) {
                // Insert STOCK baru
                $insert_stock = "INSERT INTO STOCK (KD_BARANG, KD_LOKASI, JUMLAH_BARANG, SATUAN, UPDATED_BY, LAST_UPDATED)
                                VALUES (?, ?, ?, 'DUS', ?, CURRENT_TIMESTAMP)";
                $stmt_insert_stock = $conn->prepare($insert_stock);
                if (!$stmt_insert_stock) {
                    throw new Exception('Gagal prepare query insert stock: ' . $conn->error);
                }
                $stmt_insert_stock->bind_param("ssis", $kd_barang, $kd_lokasi, $stock_setelah, $user_id);
                if (!$stmt_insert_stock->execute()) {
                    throw new Exception('Gagal insert stock: ' . $stmt_insert_stock->error);
                }
            } else {
                // Update STOCK yang sudah ada
                $update_stock = "UPDATE STOCK 
                                SET JUMLAH_BARANG = ?,
                                    LAST_UPDATED = CURRENT_TIMESTAMP,
                                    UPDATED_BY = ?
                                WHERE KD_BARANG = ? AND KD_LOKASI = ?";
                $stmt_update_stock = $conn->prepare($update_stock);
                if (!$stmt_update_stock) {
                    throw new Exception('Gagal prepare query update stock: ' . $conn->error);
                }
                $stmt_update_stock->bind_param("isss", $stock_setelah, $user_id, $kd_barang, $kd_lokasi);
                if (!$stmt_update_stock->execute()) {
                    throw new Exception('Gagal update stock: ' . $stmt_update_stock->error);
                }
            }
        }
        
        // Update AVG_HARGA_BELI_PIECES di MASTER_BARANG (sama seperti di gudang/barang_masuk.php)
        if ($harga_pesan_dus > 0 && $total_masuk_baru > 0) {
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
        echo json_encode(['success' => true, 'message' => 'Koreksi berhasil disimpan!']);
    } catch (Exception $e) {
        $conn->rollback();
        // Log error untuk debugging
        error_log("Koreksi Error - ID Pesan: " . $id_pesan . " - Error: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => $e->getMessage(),
            'debug' => [
                'id_pesan' => $id_pesan,
                'jumlah_tiba' => $jumlah_tiba,
                'jumlah_ditolak' => $jumlah_ditolak,
                'harga_pesan_dus' => $harga_pesan_dus,
                'biaya_pengiriman' => $biaya_pengiriman,
                'tgl_expired' => $tgl_expired,
                'error_message' => $e->getMessage(),
                'mysql_error' => $conn->error ?? 'No MySQL error'
            ]
        ]);
    } catch (Error $e) {
        $conn->rollback();
        error_log("Koreksi Fatal Error - ID Pesan: " . $id_pesan . " - Error: " . $e->getMessage());
        echo json_encode([
            'success' => false, 
            'message' => 'Terjadi kesalahan fatal: ' . $e->getMessage(),
            'debug' => [
                'id_pesan' => $id_pesan,
                'error_message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]
        ]);
    }
    exit();
}

// Get parameter kd_lokasi
$kd_lokasi = isset($_GET['kd_lokasi']) ? trim($_GET['kd_lokasi']) : '';

if (empty($kd_lokasi)) {
    header("Location: stock.php");
    exit();
}

// Query untuk mendapatkan informasi lokasi
$query_lokasi = "SELECT KD_LOKASI, NAMA_LOKASI, ALAMAT_LOKASI, TYPE_LOKASI 
                 FROM MASTER_LOKASI 
                 WHERE KD_LOKASI = ? AND TYPE_LOKASI = 'gudang' AND STATUS = 'AKTIF'";
$stmt_lokasi = $conn->prepare($query_lokasi);
$stmt_lokasi->bind_param("s", $kd_lokasi);
$stmt_lokasi->execute();
$result_lokasi = $stmt_lokasi->get_result();

if ($result_lokasi->num_rows == 0) {
    header("Location: stock.php");
    exit();
}

$lokasi = $result_lokasi->fetch_assoc();

// Query untuk mendapatkan riwayat pembelian untuk lokasi gudang ini (semua barang)
$query_riwayat = "SELECT 
    pb.ID_PESAN_BARANG,
    pb.KD_BARANG,
    pb.KD_SUPPLIER,
    pb.JUMLAH_PESAN_BARANG_DUS,
    pb.HARGA_PESAN_BARANG_DUS,
    pb.BIAYA_PENGIRIMAAN,
    pb.TOTAL_MASUK_DUS,
    pb.JUMLAH_TIBA_DUS,
    pb.JUMLAH_DITOLAK_DUS,
    pb.SISA_STOCK_DUS,
    pb.TGL_EXPIRED,
    pb.WAKTU_PESAN,
    pb.WAKTU_ESTIMASI_SELESAI,
    pb.WAKTU_SELESAI,
    pb.STATUS,
    pb.ID_PERHITUNGAN_KUANTITAS_POQ,
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
WHERE pb.KD_LOKASI = ?
ORDER BY 
    CASE pb.STATUS
        WHEN 'DIPESAN' THEN 1
        WHEN 'DIKIRIM' THEN 2
        WHEN 'SELESAI' THEN 3
        ELSE 4
    END,
    CASE pb.STATUS
        WHEN 'DIPESAN' THEN pb.WAKTU_PESAN
        WHEN 'DIKIRIM' THEN pb.WAKTU_SELESAI
        ELSE NULL
    END ASC,
    CASE pb.STATUS
        WHEN 'SELESAI' THEN pb.WAKTU_SELESAI
        ELSE NULL
    END DESC,
    pb.WAKTU_PESAN DESC";
$stmt_riwayat = $conn->prepare($query_riwayat);
$stmt_riwayat->bind_param("s", $kd_lokasi);
$stmt_riwayat->execute();
$result_riwayat = $stmt_riwayat->get_result();

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

// Format rupiah
function formatRupiah($angka) {
    if (empty($angka) || $angka == null || $angka == 0) {
        return '-';
    }
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Set active page untuk sidebar
$active_page = 'stock';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Riwayat Pembelian - <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></title>
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
            <h1 class="page-title">Pemilik - Riwayat Pembelian - <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></h1>
            <?php if (!empty($lokasi['ALAMAT_LOKASI'])): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($lokasi['ALAMAT_LOKASI']); ?></p>
            <?php endif; ?>
        </div>

        <!-- Table Riwayat Pembelian -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tableRiwayat" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID PESAN</th>
                            <th>Kode Barang</th>
                            <th>Nama Barang</th>
                            <th>Supplier</th>
                            <th>Waktu</th>
                            <th>Sisa Stock (dus)</th>
                            <th>Jumlah Pesan (dus)</th>
                            <th>Total Masuk (dus)</th>
                            <th>Jumlah Tiba (dus)</th>
                            <th>Jumlah Ditolak (dus)</th>
                            <th>Harga Beli</th>
                            <th>Total Bayar</th>
                            <th>Biaya Pengiriman</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_riwayat->num_rows > 0): ?>
                            <?php while ($row = $result_riwayat->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['ID_PESAN_BARANG']); ?></td>
                                    <td><?php echo htmlspecialchars($row['KD_BARANG']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_BARANG']); ?></td>
                                    <td>
                                        <?php 
                                        $supplier_display = '';
                                        if ($row['NAMA_SUPPLIER'] != '-') {
                                            $supplier_display = htmlspecialchars($row['NAMA_SUPPLIER']);
                                            if ($row['ALAMAT_SUPPLIER'] != '-') {
                                                $supplier_display .= ' - ' . htmlspecialchars($row['ALAMAT_SUPPLIER']);
                                            }
                                        } else {
                                            $supplier_display = '-';
                                        }
                                        echo $supplier_display;
                                        ?>
                                    </td>
                                    <td data-order="<?php 
                                        $waktu_order = '';
                                        switch($row['STATUS']) {
                                            case 'DIPESAN':
                                                $waktu_order = !empty($row['WAKTU_PESAN']) ? strtotime($row['WAKTU_PESAN']) : 0;
                                                break;
                                            case 'DIKIRIM':
                                                $waktu_order = !empty($row['WAKTU_SELESAI']) ? strtotime($row['WAKTU_SELESAI']) : 0;
                                                break;
                                            case 'SELESAI':
                                                // Use negative timestamp for DESC sorting (newest first)
                                                $waktu_order = !empty($row['WAKTU_SELESAI']) ? -strtotime($row['WAKTU_SELESAI']) : 0;
                                                break;
                                            default:
                                                $waktu_order = !empty($row['WAKTU_PESAN']) ? strtotime($row['WAKTU_PESAN']) : 0;
                                        }
                                        echo $waktu_order;
                                    ?>"><?php echo formatWaktuStack($row['WAKTU_PESAN'], $row['WAKTU_ESTIMASI_SELESAI'], $row['WAKTU_SELESAI'], $row['STATUS']); ?></td>
                                    <td><?php echo $row['SISA_STOCK_DUS'] !== null ? number_format($row['SISA_STOCK_DUS'], 0, ',', '.') : '-'; ?></td>
                                    <td><?php echo $row['JUMLAH_PESAN_BARANG_DUS'] ? number_format($row['JUMLAH_PESAN_BARANG_DUS'], 0, ',', '.') : '-'; ?></td>
                                    <td><?php echo $row['TOTAL_MASUK_DUS'] ? number_format($row['TOTAL_MASUK_DUS'], 0, ',', '.') : '-'; ?></td>
                                    <td><?php echo $row['JUMLAH_TIBA_DUS'] ? number_format($row['JUMLAH_TIBA_DUS'], 0, ',', '.') : '-'; ?></td>
                                    <td><?php echo $row['JUMLAH_DITOLAK_DUS'] ? number_format($row['JUMLAH_DITOLAK_DUS'], 0, ',', '.') : '-'; ?></td>
                                    <td><?php echo formatRupiah($row['HARGA_PESAN_BARANG_DUS']); ?></td>
                                    <td><?php 
                                        $total_bayar = 0;
                                        if ($row['TOTAL_MASUK_DUS'] && $row['HARGA_PESAN_BARANG_DUS']) {
                                            $total_bayar = $row['TOTAL_MASUK_DUS'] * $row['HARGA_PESAN_BARANG_DUS'];
                                        }
                                        echo formatRupiah($total_bayar);
                                    ?></td>
                                    <td><?php echo formatRupiah($row['BIAYA_PENGIRIMAAN']); ?></td>
                                    <td data-order="<?php 
                                        $status_text = '';
                                        $status_class = '';
                                        $status_order = 0;
                                        switch($row['STATUS']) {
                                            case 'DIPESAN':
                                                $status_text = 'Dipesan';
                                                $status_class = 'warning';
                                                $status_order = 1;
                                                break;
                                            case 'DIKIRIM':
                                                $status_text = 'Dikirim';
                                                $status_class = 'info';
                                                $status_order = 2;
                                                break;
                                            case 'SELESAI':
                                                $status_text = 'Selesai';
                                                $status_class = 'success';
                                                $status_order = 3;
                                                break;
                                            default:
                                                $status_text = $row['STATUS'];
                                                $status_class = 'secondary';
                                                $status_order = 4;
                                        }
                                        echo $status_order;
                                    ?>">
                                        <div class="d-flex flex-column gap-1">
                                            <span class="badge bg-<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                            <?php if (!empty($row['ID_PERHITUNGAN_KUANTITAS_POQ'])): ?>
                                                <span class="badge mt-1" style="background-color: #6f42c1; color: white;">POQ</span>
                                            <?php else: ?>
                                                <span class="badge mt-1" style="background-color: #fd7e14; color: white;">Manual</span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <button class="btn-view btn-sm" onclick="lihatPO('<?php echo htmlspecialchars($row['ID_PESAN_BARANG']); ?>')">Lihat PO</button>
                                            <?php if ($row['STATUS'] == 'DIPESAN'): ?>
                                                <button class="btn btn-success btn-sm" onclick="ubahStatus('<?php echo htmlspecialchars($row['ID_PESAN_BARANG']); ?>', 'DIKIRIM')">Dikirim</button>
                                                <button class="btn btn-danger btn-sm" onclick="ubahStatus('<?php echo htmlspecialchars($row['ID_PESAN_BARANG']); ?>', 'DIBATALKAN')">Batalkan</button>
                                            <?php endif; ?>
                                            <?php if ($row['SISA_STOCK_DUS'] == $row['JUMLAH_PESAN_BARANG_DUS']): ?>
                                                <button class="btn btn-warning btn-sm" onclick="koreksiPesan('<?php echo htmlspecialchars($row['ID_PESAN_BARANG']); ?>', '<?php echo htmlspecialchars($row['KD_BARANG']); ?>', '<?php echo htmlspecialchars($row['NAMA_BARANG']); ?>', '<?php echo htmlspecialchars($row['NAMA_MEREK']); ?>', '<?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?>', <?php echo intval($row['BERAT']); ?>, '<?php 
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
                                                ?>', <?php echo intval($row['JUMLAH_PESAN_BARANG_DUS']); ?>, <?php echo intval($row['JUMLAH_TIBA_DUS'] ?: 0); ?>, <?php echo intval($row['JUMLAH_DITOLAK_DUS'] ?: 0); ?>, <?php echo floatval($row['HARGA_PESAN_BARANG_DUS'] ?: 0); ?>, <?php echo floatval($row['BIAYA_PENGIRIMAAN'] ?: 0); ?>, '<?php echo !empty($row['TGL_EXPIRED']) ? date('d/m/Y', strtotime($row['TGL_EXPIRED'])) : ''; ?>')">Koreksi</button>
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

    <!-- Modal Koreksi -->
    <div class="modal fade" id="modalKoreksi" tabindex="-1" aria-labelledby="modalKoreksiLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white;">
                    <h5 class="modal-title" id="modalKoreksiLabel">KOREKSI PESAN BARANG</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="formKoreksi">
                        <input type="hidden" id="koreksi_id_pesan" name="id_pesan">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">ID PESAN</label>
                                <input type="text" class="form-control form-control-sm" id="koreksi_id_pesan_display" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-bold small">Supplier</label>
                                <input type="text" class="form-control form-control-sm" id="koreksi_supplier" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Kode Barang</label>
                                <input type="text" class="form-control form-control-sm" id="koreksi_kd_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Merek Barang</label>
                                <input type="text" class="form-control form-control-sm" id="koreksi_merek" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Kategori Barang</label>
                                <input type="text" class="form-control form-control-sm" id="koreksi_kategori" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label fw-bold small">Nama Barang</label>
                                <input type="text" class="form-control form-control-sm" id="koreksi_nama_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Berat (gr)</label>
                                <input type="text" class="form-control form-control-sm" id="koreksi_berat" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Jumlah Dipesan (dus)</label>
                                <input type="text" class="form-control form-control-sm" id="koreksi_jumlah_pesan" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Jumlah Tiba (dus) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control form-control-sm" id="koreksi_jumlah_tiba" name="jumlah_tiba" min="0" max="" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Jumlah Ditolak (dus) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control form-control-sm" id="koreksi_jumlah_ditolak" name="jumlah_ditolak" min="0" max="" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Harga Barang (dus) <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="koreksi_harga_pesan" name="harga_pesan_dus" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Biaya Pengiriman <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-sm" id="koreksi_biaya_pengiriman" name="biaya_pengiriman" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Tanggal Expired <span class="text-danger">*</span></label>
                                <input type="date" class="form-control form-control-sm" id="koreksi_tgl_expired" name="tgl_expired" 
                                       min="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold small">Total Masuk (dus)</label>
                                <input type="text" class="form-control form-control-sm" id="koreksi_total_masuk" name="total_masuk" readonly style="background-color: #e9ecef;">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-warning" onclick="simpanKoreksi()">Simpan</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Lihat PO -->
    <div class="modal fade" id="modalLihatPO" tabindex="-1" aria-labelledby="modalLihatPOLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalLihatPOLabel">Lihat Purchase Order</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0" style="height: 80vh; overflow: hidden;">
                    <iframe id="poIframe" src="" style="width: 100%; height: 100%; border: none;"></iframe>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
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
            
            $('#tableRiwayat').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data riwayat pembelian'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[13, 'asc'], [4, 'asc']], // Sort by Status (priority) then Waktu
                columnDefs: [
                    { orderable: false, targets: 14 }, // Disable sorting on Action column
                    { type: 'num', targets: [13, 4] } // Status and Waktu columns use numeric sorting
                ],
                scrollX: true,
                responsive: true,
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

        function lihatPO(idPesan) {
            // Set iframe source ke download_po.php
            $('#poIframe').attr('src', 'download_po.php?id_pesan=' + encodeURIComponent(idPesan));
            
            // Buka modal
            var modal = new bootstrap.Modal(document.getElementById('modalLihatPO'));
            modal.show();
        }

        function ubahStatus(idPesan, statusBaru) {
            var statusText = statusBaru == 'DIKIRIM' ? 'Dikirim' : 'Dibatalkan';
            var confirmText = statusBaru == 'DIKIRIM' 
                ? 'Apakah Anda yakin ingin mengubah status pesanan menjadi "Dikirim"?'
                : 'Apakah Anda yakin ingin membatalkan pesanan ini?';
            
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi',
                text: confirmText,
                showCancelButton: true,
                confirmButtonText: 'Ya, Ubah',
                cancelButtonText: 'Batal',
                confirmButtonColor: statusBaru == 'DIKIRIM' ? '#0dcaf0' : '#dc3545',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    // AJAX request untuk update status
                    $.ajax({
                        url: 'riwayat_pembelian_gudang.php',
                        method: 'POST',
                        data: {
                            action: 'update_status',
                            id_pesan: idPesan,
                            status: statusBaru
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
                        error: function() {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: 'Terjadi kesalahan saat mengubah status!',
                                confirmButtonColor: '#e74c3c'
                            });
                        }
                    });
                }
            });
        }

        function koreksiPesan(idPesan, kdBarang, namaBarang, namaMerek, namaKategori, berat, supplier, jumlahPesan, jumlahTiba, jumlahDitolak, hargaPesan, biayaPengiriman, tglExpired) {
            // Set nilai ke form
            $('#koreksi_id_pesan').val(idPesan);
            $('#koreksi_id_pesan_display').val(idPesan);
            $('#koreksi_supplier').val(supplier || '-');
            $('#koreksi_kd_barang').val(kdBarang);
            $('#koreksi_merek').val(namaMerek || '-');
            $('#koreksi_kategori').val(namaKategori || '-');
            $('#koreksi_nama_barang').val(namaBarang);
            $('#koreksi_berat').val(berat ? berat.toLocaleString('id-ID') : '-');
            $('#koreksi_jumlah_pesan').val(jumlahPesan);
            $('#koreksi_jumlah_tiba').val(jumlahTiba || 0);
            $('#koreksi_jumlah_ditolak').val(jumlahDitolak || 0);
            $('#koreksi_harga_pesan').val(formatRupiah(hargaPesan || 0));
            $('#koreksi_biaya_pengiriman').val(formatRupiah(biayaPengiriman || 0));
            // Konversi dd/mm/yyyy ke YYYY-MM-DD untuk input type="date"
            if (tglExpired) {
                $('#koreksi_tgl_expired').val(convertDateToYMD(tglExpired));
            } else {
                $('#koreksi_tgl_expired').val('');
            }
            
            // Set max attribute untuk validasi HTML5
            $('#koreksi_jumlah_tiba').attr('max', jumlahPesan);
            $('#koreksi_jumlah_ditolak').attr('max', jumlahTiba || jumlahPesan);
            
            // Hitung total masuk
            hitungTotalMasukKoreksi();
            
            // Hapus event listener lama jika ada, lalu pasang yang baru
            // Gunakan 'input' dan 'change' untuk menangkap perubahan dari spinner dan keyboard
            $('#koreksi_jumlah_tiba').off('input change').on('input change', function() {
                var jumlahTibaBaru = parseInt($(this).val()) || 0;
                var jumlahPesan = parseInt($('#koreksi_jumlah_pesan').val()) || 0;
                
                // Update max untuk jumlah ditolak
                $('#koreksi_jumlah_ditolak').attr('max', jumlahTibaBaru);
                
                hitungTotalMasukKoreksi();
            });
            
            $('#koreksi_jumlah_ditolak').off('input change').on('input change', function() {
                hitungTotalMasukKoreksi();
            });
            
            var modalElement = document.getElementById('modalKoreksi');
            var modal = new bootstrap.Modal(modalElement);
            
            // Event listener untuk saat modal ditampilkan
            modalElement.addEventListener('shown.bs.modal', function() {
                hitungTotalMasukKoreksi();
            });
            
            // Reset form saat modal ditutup
            modalElement.addEventListener('hidden.bs.modal', function() {
                $('#formKoreksi')[0].reset();
            });
            
            modal.show();
        }
        
        function hitungTotalMasukKoreksi() {
            var jumlahDikirim = parseInt($('#koreksi_jumlah_tiba').val()) || 0;
            var jumlahDitolak = parseInt($('#koreksi_jumlah_ditolak').val()) || 0;
            var totalMasuk = jumlahDikirim - jumlahDitolak;
            
            if (totalMasuk < 0) {
                totalMasuk = 0;
            }
            
            $('#koreksi_total_masuk').val(totalMasuk.toLocaleString('id-ID'));
        }
        
        // Format rupiah untuk input harga dan biaya pengiriman
        function formatRupiah(angka) {
            if (!angka && angka !== 0) return '';
            var number_string = angka.toString().replace(/[^\d.,]/g, '');
            var parts = number_string.split(/[.,]/);
            var integerPart = parts[0] || '0';
            var decimalPart = parts[1] || '';
            var formattedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            var result = formattedInteger;
            if (decimalPart) {
                result += ',' + decimalPart;
            }
            return 'Rp ' + result;
        }
        
        function unformatRupiah(rupiah) {
            if (!rupiah) return 0;
            var cleaned = rupiah.toString().replace(/Rp\s?/g, '').replace(/\./g, '').replace(',', '.');
            return parseFloat(cleaned) || 0;
        }
        
        // Event listener untuk format rupiah saat input
        $(document).on('input', '#koreksi_harga_pesan, #koreksi_biaya_pengiriman', function() {
            var value = $(this).val();
            var cursorPosition = this.selectionStart;
            var originalLength = value.length;
            var unformatted = unformatRupiah(value);
            var formatted = formatRupiah(unformatted);
            $(this).val(formatted);
            var newLength = formatted.length;
            var lengthDiff = newLength - originalLength;
            var newCursorPosition = cursorPosition + lengthDiff;
            this.setSelectionRange(newCursorPosition, newCursorPosition);
        });
        
        // Konversi dd/mm/yyyy ke YYYY-MM-DD untuk input type="date"
        function convertDateToYMD(dateString) {
            if (!dateString) return '';
            // Jika sudah format YYYY-MM-DD, return as is
            if (/^\d{4}-\d{2}-\d{2}$/.test(dateString)) {
                return dateString;
            }
            // Jika format dd/mm/yyyy, konversi
            var parts = dateString.split('/');
            if (parts.length === 3) {
                return parts[2] + '-' + parts[1] + '-' + parts[0];
            }
            return dateString;
        }


        function simpanKoreksi() {
            // Validasi form
            if (!$('#formKoreksi')[0].checkValidity()) {
                $('#formKoreksi')[0].reportValidity();
                return;
            }
            
            var idPesan = $('#koreksi_id_pesan').val();
            var jumlahTiba = parseInt($('#koreksi_jumlah_tiba').val()) || 0;
            var jumlahDitolak = parseInt($('#koreksi_jumlah_ditolak').val()) || 0;
            var jumlahPesan = parseInt($('#koreksi_jumlah_pesan').val()) || 0;
            var hargaPesanDus = unformatRupiah($('#koreksi_harga_pesan').val()) || 0;
            var biayaPengiriman = unformatRupiah($('#koreksi_biaya_pengiriman').val()) || 0;
            var tglExpired = $('#koreksi_tgl_expired').val().trim();
            
            // Validasi 1: Jumlah Tiba tidak boleh melebihi Jumlah Pesan
            if (jumlahTiba > jumlahPesan) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Jumlah Tiba tidak boleh melebihi Jumlah Pesan!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }
            
            // Validasi 2: Jumlah Ditolak tidak boleh melebihi Jumlah Tiba
            if (jumlahDitolak > jumlahTiba) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Jumlah Ditolak tidak boleh melebihi Jumlah Tiba!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }
            
            // Validasi 3: Jumlah tidak boleh negatif
            if (jumlahTiba < 0 || jumlahDitolak < 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Jumlah tidak boleh negatif!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }
            
            // Validasi 4: Tanggal expired wajib diisi
            if (!tglExpired) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Tanggal expired wajib diisi!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }
            
            // Validasi 5: Harga dan biaya tidak boleh negatif
            if (hargaPesanDus < 0 || biayaPengiriman < 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Harga barang dan biaya pengiriman tidak boleh negatif!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }
            
            // Input type="date" sudah dalam format YYYY-MM-DD
            var tglExpiredYMD = tglExpired;
            
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi',
                text: 'Apakah Anda yakin ingin menyimpan koreksi ini?',
                showCancelButton: true,
                confirmButtonText: 'Ya, Simpan',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#f59e0b',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'riwayat_pembelian_gudang.php',
                        method: 'POST',
                        data: {
                            action: 'koreksi',
                            id_pesan: idPesan,
                            jumlah_tiba: jumlahTiba,
                            jumlah_ditolak: jumlahDitolak,
                            harga_pesan_dus: hargaPesanDus,
                            biaya_pengiriman: biayaPengiriman,
                            tgl_expired: tglExpiredYMD
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
                                    location.reload();
                                });
                            } else {
                                var errorMessage = response.message || 'Terjadi kesalahan saat menyimpan koreksi!';
                                var debugInfo = '';
                                
                                if (response.debug) {
                                    debugInfo = '\n\nDetail Error:\n';
                                    debugInfo += 'ID Pesan: ' + (response.debug.id_pesan || 'N/A') + '\n';
                                    debugInfo += 'Jumlah Tiba: ' + (response.debug.jumlah_tiba || 'N/A') + '\n';
                                    debugInfo += 'Jumlah Ditolak: ' + (response.debug.jumlah_ditolak || 'N/A') + '\n';
                                    debugInfo += 'Harga Pesan: ' + (response.debug.harga_pesan_dus || 'N/A') + '\n';
                                    debugInfo += 'Biaya Pengiriman: ' + (response.debug.biaya_pengiriman || 'N/A') + '\n';
                                    debugInfo += 'Tanggal Expired: ' + (response.debug.tgl_expired || 'N/A') + '\n';
                                    if (response.debug.mysql_error && response.debug.mysql_error !== 'No MySQL error') {
                                        debugInfo += 'MySQL Error: ' + response.debug.mysql_error + '\n';
                                    }
                                }
                                
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Gagal!',
                                    html: '<div style="text-align: left;">' + 
                                          '<strong>' + errorMessage + '</strong>' +
                                          (debugInfo ? '<pre style="font-size: 11px; margin-top: 10px; white-space: pre-wrap; background: #f8f9fa; padding: 10px; border-radius: 5px;">' + debugInfo + '</pre>' : '') +
                                          '</div>',
                                    confirmButtonColor: '#e74c3c',
                                    width: '600px'
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            var errorMessage = 'Terjadi kesalahan saat menyimpan koreksi!';
                            var debugInfo = '';
                            
                            // Coba parse response untuk mendapatkan error detail
                            try {
                                var response = JSON.parse(xhr.responseText);
                                if (response.message) {
                                    errorMessage = response.message;
                                }
                                if (response.debug) {
                                    debugInfo = '\n\nDetail Error:\n';
                                    debugInfo += 'ID Pesan: ' + (response.debug.id_pesan || 'N/A') + '\n';
                                    debugInfo += 'Jumlah Tiba: ' + (response.debug.jumlah_tiba || 'N/A') + '\n';
                                    debugInfo += 'Jumlah Ditolak: ' + (response.debug.jumlah_ditolak || 'N/A') + '\n';
                                    debugInfo += 'Harga Pesan: ' + (response.debug.harga_pesan_dus || 'N/A') + '\n';
                                    debugInfo += 'Biaya Pengiriman: ' + (response.debug.biaya_pengiriman || 'N/A') + '\n';
                                    debugInfo += 'Tanggal Expired: ' + (response.debug.tgl_expired || 'N/A') + '\n';
                                    if (response.debug.mysql_error) {
                                        debugInfo += 'MySQL Error: ' + response.debug.mysql_error + '\n';
                                    }
                                }
                            } catch (e) {
                                // Jika tidak bisa parse, gunakan error default
                                console.error('Error parsing response:', e);
                                console.error('Response:', xhr.responseText);
                                debugInfo = '\n\nResponse dari server:\n' + xhr.responseText;
                            }
                            
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                html: '<div style="text-align: left;">' + 
                                      '<strong>' + errorMessage + '</strong>' +
                                      (debugInfo ? '<pre style="font-size: 11px; margin-top: 10px; white-space: pre-wrap; background: #f8f9fa; padding: 10px; border-radius: 5px;">' + debugInfo + '</pre>' : '') +
                                      '</div>',
                                confirmButtonColor: '#e74c3c',
                                width: '600px'
                            });
                        }
                    });
                }
            });
        }
    </script>
</body>
</html>

