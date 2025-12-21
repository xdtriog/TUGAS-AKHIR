<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
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

// Handle AJAX request untuk batalkan transfer
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'batalkan_transfer') {
    header('Content-Type: application/json');
    
    $id_transfer = isset($_POST['id_transfer']) ? trim($_POST['id_transfer']) : '';
    
    if (empty($id_transfer)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Update semua detail transfer menjadi DIBATALKAN
    $update_detail = "UPDATE DETAIL_TRANSFER_BARANG SET STATUS = 'DIBATALKAN' WHERE ID_TRANSFER_BARANG = ? AND STATUS IN ('DIPESAN', 'DIKIRIM')";
    $stmt_detail = $conn->prepare($update_detail);
    $stmt_detail->bind_param("s", $id_transfer);
    
    if ($stmt_detail->execute()) {
        // Update status transfer menjadi DIBATALKAN
        $update_transfer = "UPDATE TRANSFER_BARANG SET STATUS = 'DIBATALKAN' WHERE ID_TRANSFER_BARANG = ?";
        $stmt_transfer = $conn->prepare($update_transfer);
        $stmt_transfer->bind_param("s", $id_transfer);
        $stmt_transfer->execute();
        $stmt_transfer->close();
        
        echo json_encode(['success' => true, 'message' => 'Transfer berhasil dibatalkan!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal membatalkan transfer!']);
    }
    
    $stmt_detail->close();
    exit();
}

// Handle AJAX request untuk get detail transfer (untuk popup lihat detail)
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['get_detail_transfer'])) {
    header('Content-Type: application/json');
    
    $id_transfer = isset($_GET['id_transfer']) ? trim($_GET['id_transfer']) : '';
    
    if (empty($id_transfer)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Get data DETAIL_TRANSFER_BARANG dan DETAIL_TRANSFER_BARANG_BATCH
    $query_detail = "SELECT 
        dtb.ID_DETAIL_TRANSFER_BARANG,
        dtb.KD_BARANG,
        dtb.TOTAL_PESAN_TRANSFER_DUS,
        dtb.TOTAL_KIRIM_DUS,
        dtb.TOTAL_TIBA_DUS,
        dtb.TOTAL_DITOLAK_DUS,
        dtb.TOTAL_MASUK_DUS,
        dtb.STATUS as STATUS_DETAIL,
        mb.NAMA_BARANG,
        mb.BERAT,
        mb.SATUAN_PERDUS,
        COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
        COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
        dtbb.ID_DETAIL_TRANSFER_BARANG_BATCH,
        dtbb.ID_PESAN_BARANG,
        dtbb.JUMLAH_PESAN_TRANSFER_BATCH_DUS,
        dtbb.JUMLAH_KIRIM_DUS,
        dtbb.JUMLAH_TIBA_DUS,
        dtbb.JUMLAH_DITOLAK_DUS,
        dtbb.JUMLAH_MASUK_DUS,
        pb.TGL_EXPIRED,
        COALESCE(ms.NAMA_SUPPLIER, '-') as NAMA_SUPPLIER
    FROM DETAIL_TRANSFER_BARANG dtb
    INNER JOIN MASTER_BARANG mb ON dtb.KD_BARANG = mb.KD_BARANG
    LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
    LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
    LEFT JOIN DETAIL_TRANSFER_BARANG_BATCH dtbb ON dtb.ID_DETAIL_TRANSFER_BARANG = dtbb.ID_DETAIL_TRANSFER_BARANG
    LEFT JOIN PESAN_BARANG pb ON dtbb.ID_PESAN_BARANG = pb.ID_PESAN_BARANG
    LEFT JOIN MASTER_SUPPLIER ms ON pb.KD_SUPPLIER = ms.KD_SUPPLIER
    WHERE dtb.ID_TRANSFER_BARANG = ?
    ORDER BY dtb.ID_DETAIL_TRANSFER_BARANG ASC, pb.TGL_EXPIRED ASC";
    
    $stmt_detail = $conn->prepare($query_detail);
    if (!$stmt_detail) {
        echo json_encode(['success' => false, 'message' => 'Gagal prepare query: ' . $conn->error]);
        exit();
    }
    $stmt_detail->bind_param("s", $id_transfer);
    if (!$stmt_detail->execute()) {
        echo json_encode(['success' => false, 'message' => 'Gagal execute query: ' . $stmt_detail->error]);
        exit();
    }
    $result_detail = $stmt_detail->get_result();
    
    $detail_map = [];
    while ($row = $result_detail->fetch_assoc()) {
        $id_detail = $row['ID_DETAIL_TRANSFER_BARANG'];
        
        if (!isset($detail_map[$id_detail])) {
            $detail_map[$id_detail] = [
                'id_detail_transfer' => $id_detail,
                'kd_barang' => $row['KD_BARANG'],
                'nama_barang' => $row['NAMA_BARANG'],
                'nama_merek' => $row['NAMA_MEREK'],
                'nama_kategori' => $row['NAMA_KATEGORI'],
                'berat' => $row['BERAT'],
                'satuan_perdus' => $row['SATUAN_PERDUS'],
                'total_pesan_dus' => $row['TOTAL_PESAN_TRANSFER_DUS'],
                'total_kirim_dus' => $row['TOTAL_KIRIM_DUS'],
                'total_tiba_dus' => $row['TOTAL_TIBA_DUS'] ?? 0,
                'total_ditolak_dus' => $row['TOTAL_DITOLAK_DUS'] ?? 0,
                'total_masuk_dus' => $row['TOTAL_MASUK_DUS'] ?? 0,
                'status_detail' => $row['STATUS_DETAIL'],
                'batches' => []
            ];
        }
        
        if (!empty($row['ID_DETAIL_TRANSFER_BARANG_BATCH'])) {
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
            
            $detail_map[$id_detail]['batches'][] = [
                'id_detail_transfer_batch' => $row['ID_DETAIL_TRANSFER_BARANG_BATCH'],
                'id_pesan_barang' => $row['ID_PESAN_BARANG'],
                'jumlah_pesan_batch_dus' => intval($row['JUMLAH_PESAN_TRANSFER_BATCH_DUS'] ?? 0),
                'jumlah_kirim_dus' => intval($row['JUMLAH_KIRIM_DUS'] ?? 0),
                'jumlah_tiba_dus' => intval($row['JUMLAH_TIBA_DUS'] ?? 0),
                'jumlah_ditolak_dus' => intval($row['JUMLAH_DITOLAK_DUS'] ?? 0),
                'jumlah_masuk_dus' => intval($row['JUMLAH_MASUK_DUS'] ?? 0),
                'tgl_expired' => $row['TGL_EXPIRED'],
                'tgl_expired_display' => $tgl_expired_display,
                'nama_supplier' => $row['NAMA_SUPPLIER']
            ];
        }
    }
    
    $detail_data = array_values($detail_map);
    
    echo json_encode([
        'success' => true,
        'detail_data' => $detail_data
    ]);
    exit();
}

// Handle AJAX request untuk get data transfer (untuk form koreksi)
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['get_transfer_data_koreksi'])) {
    header('Content-Type: application/json');
    
    $id_transfer = isset($_GET['id_transfer']) ? trim($_GET['id_transfer']) : '';
    $status_koreksi = isset($_GET['status_koreksi']) ? trim($_GET['status_koreksi']) : 'SELESAI'; // 'DIKIRIM' atau 'SELESAI'
    
    if (empty($id_transfer)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Get data transfer lengkap dengan batch (hanya untuk yang sudah SELESAI)
    $query_transfer_data = "SELECT 
        tb.ID_TRANSFER_BARANG,
        tb.KD_LOKASI_ASAL,
        ml_asal.KD_LOKASI as KD_LOKASI_ASAL,
        ml_asal.NAMA_LOKASI as NAMA_LOKASI_ASAL,
        ml_asal.ALAMAT_LOKASI as ALAMAT_LOKASI_ASAL,
        dtb.ID_DETAIL_TRANSFER_BARANG,
        dtb.KD_BARANG,
        dtb.TOTAL_PESAN_TRANSFER_DUS,
        dtb.TOTAL_KIRIM_DUS,
        dtb.TOTAL_TIBA_DUS,
        dtb.TOTAL_DITOLAK_DUS,
        dtb.TOTAL_MASUK_DUS,
        mb.NAMA_BARANG,
        mb.BERAT,
        mb.SATUAN_PERDUS,
        COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
        COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
        COALESCE(s.JUMLAH_BARANG, 0) as STOCK_SEKARANG,
        s.SATUAN,
        dtbb.ID_DETAIL_TRANSFER_BARANG_BATCH,
        dtbb.ID_PESAN_BARANG,
        dtbb.JUMLAH_PESAN_TRANSFER_BATCH_DUS,
        dtbb.JUMLAH_KIRIM_DUS,
        dtbb.JUMLAH_TIBA_DUS,
        dtbb.JUMLAH_DITOLAK_DUS,
        dtbb.JUMLAH_MASUK_DUS,
        pb.TGL_EXPIRED
    FROM TRANSFER_BARANG tb
    INNER JOIN DETAIL_TRANSFER_BARANG dtb ON tb.ID_TRANSFER_BARANG = dtb.ID_TRANSFER_BARANG
    INNER JOIN MASTER_BARANG mb ON dtb.KD_BARANG = mb.KD_BARANG
    LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
    LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
    LEFT JOIN MASTER_LOKASI ml_asal ON tb.KD_LOKASI_ASAL = ml_asal.KD_LOKASI
    LEFT JOIN STOCK s ON dtb.KD_BARANG = s.KD_BARANG AND s.KD_LOKASI = ?
    LEFT JOIN DETAIL_TRANSFER_BARANG_BATCH dtbb ON dtb.ID_DETAIL_TRANSFER_BARANG = dtbb.ID_DETAIL_TRANSFER_BARANG
    LEFT JOIN PESAN_BARANG pb ON dtbb.ID_PESAN_BARANG = pb.ID_PESAN_BARANG
    WHERE tb.ID_TRANSFER_BARANG = ? AND tb.KD_LOKASI_ASAL = ? AND dtb.STATUS = ?
    ORDER BY dtb.ID_DETAIL_TRANSFER_BARANG ASC, pb.TGL_EXPIRED ASC";
    
    $stmt_transfer_data = $conn->prepare($query_transfer_data);
    if (!$stmt_transfer_data) {
        echo json_encode(['success' => false, 'message' => 'Gagal prepare query: ' . $conn->error]);
        exit();
    }
    $stmt_transfer_data->bind_param("ssss", $kd_lokasi, $id_transfer, $kd_lokasi, $status_koreksi);
    if (!$stmt_transfer_data->execute()) {
        echo json_encode(['success' => false, 'message' => 'Gagal execute query: ' . $stmt_transfer_data->error]);
        exit();
    }
    $result_transfer_data = $stmt_transfer_data->get_result();
    
    if ($result_transfer_data->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Data transfer tidak ditemukan!']);
        exit();
    }
    
    $transfer_info = null;
    $detail_data = [];
    $detail_map = [];
    
    while ($row = $result_transfer_data->fetch_assoc()) {
        if ($transfer_info === null) {
            $transfer_info = [
                'id_transfer' => $row['ID_TRANSFER_BARANG'],
                'kd_lokasi_asal' => $row['KD_LOKASI_ASAL'],
                'nama_lokasi_asal' => $row['NAMA_LOKASI_ASAL'] ?? '',
                'alamat_lokasi_asal' => $row['ALAMAT_LOKASI_ASAL'] ?? ''
            ];
        }
        
        $id_detail = $row['ID_DETAIL_TRANSFER_BARANG'];
        
        if (!isset($detail_map[$id_detail])) {
            $detail_map[$id_detail] = [
                'id_detail_transfer' => $id_detail,
                'kd_barang' => $row['KD_BARANG'],
                'nama_barang' => $row['NAMA_BARANG'],
                'nama_merek' => $row['NAMA_MEREK'],
                'nama_kategori' => $row['NAMA_KATEGORI'],
                'berat' => $row['BERAT'],
                'jumlah_pesan_dus' => $row['TOTAL_PESAN_TRANSFER_DUS'],
                'jumlah_kirim_dus' => $row['TOTAL_KIRIM_DUS'],
                'jumlah_tiba_dus' => $row['TOTAL_TIBA_DUS'] ?? 0,
                'jumlah_ditolak_dus' => $row['TOTAL_DITOLAK_DUS'] ?? 0,
                'jumlah_masuk_dus' => $row['TOTAL_MASUK_DUS'] ?? 0,
                'satuan_perdus' => $row['SATUAN_PERDUS'] ?? 1,
                'stock_sekarang' => intval($row['STOCK_SEKARANG'] ?? 0),
                'satuan' => $row['SATUAN'] ?? 'PIECES',
                'batches' => []
            ];
        }
        
        if (!empty($row['ID_DETAIL_TRANSFER_BARANG_BATCH']) && !empty($row['ID_PESAN_BARANG'])) {
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
            
            $detail_map[$id_detail]['batches'][] = [
                'id_detail_transfer_batch' => $row['ID_DETAIL_TRANSFER_BARANG_BATCH'],
                'id_pesan_barang' => $row['ID_PESAN_BARANG'],
                'jumlah_pesan_batch_dus' => intval($row['JUMLAH_PESAN_TRANSFER_BATCH_DUS'] ?? 0),
                'jumlah_kirim_dus' => intval($row['JUMLAH_KIRIM_DUS'] ?? 0),
                'jumlah_tiba_dus' => intval($row['JUMLAH_TIBA_DUS'] ?? 0),
                'jumlah_ditolak_dus' => intval($row['JUMLAH_DITOLAK_DUS'] ?? 0),
                'jumlah_masuk_dus' => intval($row['JUMLAH_MASUK_DUS'] ?? 0),
                'tgl_expired' => $row['TGL_EXPIRED'],
                'tgl_expired_display' => $tgl_expired_display
            ];
        }
    }
    
    $detail_data = array_values($detail_map);
    
    echo json_encode([
        'success' => true,
        'transfer_info' => $transfer_info,
        'detail_data' => $detail_data,
        'status_koreksi' => $status_koreksi
    ]);
    exit();
}

// Handle AJAX request untuk koreksi transfer
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'koreksi_transfer') {
    header('Content-Type: application/json');
    
    $id_transfer = isset($_POST['id_transfer']) ? trim($_POST['id_transfer']) : '';
    $batch_koreksi = isset($_POST['batch_koreksi']) ? $_POST['batch_koreksi'] : [];
    $status_koreksi = isset($_POST['status_koreksi']) ? trim($_POST['status_koreksi']) : 'SELESAI'; // 'DIKIRIM' atau 'SELESAI'
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    
    if (empty($id_transfer) || !is_array($batch_koreksi) || empty($user_id)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Mulai transaksi
    $conn->begin_transaction();
    
    try {
        require_once '../includes/uuid_generator.php';
        
        // Get KD_LOKASI_ASAL dan KD_LOKASI_TUJUAN dari transfer
        $query_transfer_info = "SELECT KD_LOKASI_ASAL, KD_LOKASI_TUJUAN FROM TRANSFER_BARANG WHERE ID_TRANSFER_BARANG = ?";
        $stmt_transfer_info = $conn->prepare($query_transfer_info);
        if (!$stmt_transfer_info) {
            throw new Exception('Gagal prepare query transfer info: ' . $conn->error);
        }
        $stmt_transfer_info->bind_param("s", $id_transfer);
        $stmt_transfer_info->execute();
        $result_transfer_info = $stmt_transfer_info->get_result();
        if ($result_transfer_info->num_rows == 0) {
            throw new Exception('Data transfer tidak ditemukan!');
        }
        $transfer_info = $result_transfer_info->fetch_assoc();
        $kd_lokasi_asal = $transfer_info['KD_LOKASI_ASAL'];
        $kd_lokasi_tujuan = $transfer_info['KD_LOKASI_TUJUAN'];
        
        // Kelompokkan batch per detail transfer
        $detail_batch_map = [];
        foreach ($batch_koreksi as $batch) {
            $id_detail = $batch['id_detail_transfer'] ?? '';
            if (empty($id_detail)) {
                continue;
            }
            
            if (!isset($detail_batch_map[$id_detail])) {
                $detail_batch_map[$id_detail] = [];
            }
            
            $detail_batch_map[$id_detail][] = $batch;
        }
        
        // Update setiap detail transfer berdasarkan total batch
        foreach ($detail_batch_map as $id_detail => $batches) {
            // Get kd_barang dari detail transfer
            $query_detail_info = "SELECT KD_BARANG, TOTAL_KIRIM_DUS, TOTAL_TIBA_DUS, TOTAL_DITOLAK_DUS, TOTAL_MASUK_DUS FROM DETAIL_TRANSFER_BARANG WHERE ID_DETAIL_TRANSFER_BARANG = ?";
            $stmt_detail_info = $conn->prepare($query_detail_info);
            if (!$stmt_detail_info) {
                throw new Exception('Gagal prepare query detail info: ' . $conn->error);
            }
            $stmt_detail_info->bind_param("s", $id_detail);
            $stmt_detail_info->execute();
            $result_detail_info = $stmt_detail_info->get_result();
            if ($result_detail_info->num_rows == 0) {
                continue;
            }
            $detail_info = $result_detail_info->fetch_assoc();
            $kd_barang = $detail_info['KD_BARANG'];
            $old_total_kirim_dus = intval($detail_info['TOTAL_KIRIM_DUS'] ?? 0);
            $old_total_tiba_dus = intval($detail_info['TOTAL_TIBA_DUS'] ?? 0);
            $old_total_ditolak_dus = intval($detail_info['TOTAL_DITOLAK_DUS'] ?? 0);
            $old_total_masuk_dus = intval($detail_info['TOTAL_MASUK_DUS'] ?? 0);
            
            // Get STOCK.JUMLAH_BARANG sebelum koreksi (untuk STOCK_HISTORY)
            $query_stock_awal = "SELECT COALESCE(s.JUMLAH_BARANG, 0) as JUMLAH_BARANG, COALESCE(s.SATUAN, 'DUS') as SATUAN
                                FROM STOCK s
                                WHERE s.KD_BARANG = ? AND s.KD_LOKASI = ?";
            $stmt_stock_awal = $conn->prepare($query_stock_awal);
            if (!$stmt_stock_awal) {
                throw new Exception('Gagal prepare query stock awal: ' . $conn->error);
            }
            $stmt_stock_awal->bind_param("ss", $kd_barang, $kd_lokasi_asal);
            $stmt_stock_awal->execute();
            $result_stock_awal = $stmt_stock_awal->get_result();
            $stock_awal_data = $result_stock_awal->fetch_assoc();
            $jumlah_stock_awal = intval($stock_awal_data['JUMLAH_BARANG'] ?? 0);
            $satuan_stock_awal = $stock_awal_data['SATUAN'] ?? 'DUS';
            $stmt_stock_awal->close();
            
            // Hitung total baru dari semua batch
            $total_kirim_dus = 0;
            $total_tiba_dus = 0;
            $total_ditolak_dus = 0;
            $total_masuk_dus = 0;
            
            // Simpan jumlah kirim dan masuk lama untuk setiap batch sebelum update (untuk STOCK_HISTORY)
            $batch_kirim_lama_map = [];
            $batch_masuk_lama_map = [];
            foreach ($batches as $batch) {
                $id_batch = $batch['id_detail_transfer_batch'] ?? '';
                if (empty($id_batch)) {
                    continue;
                }
                
                // Get nilai lama dari batch sebelum update
                $query_batch_lama = "SELECT JUMLAH_KIRIM_DUS, JUMLAH_MASUK_DUS FROM DETAIL_TRANSFER_BARANG_BATCH WHERE ID_DETAIL_TRANSFER_BARANG_BATCH = ?";
                $stmt_batch_lama = $conn->prepare($query_batch_lama);
                if (!$stmt_batch_lama) {
                    throw new Exception('Gagal prepare query batch lama: ' . $conn->error);
                }
                $stmt_batch_lama->bind_param("s", $id_batch);
                $stmt_batch_lama->execute();
                $result_batch_lama = $stmt_batch_lama->get_result();
                $jumlah_kirim_lama = 0;
                $jumlah_masuk_lama = 0;
                if ($result_batch_lama->num_rows > 0) {
                    $batch_lama_data = $result_batch_lama->fetch_assoc();
                    $jumlah_kirim_lama = intval($batch_lama_data['JUMLAH_KIRIM_DUS'] ?? 0);
                    $jumlah_masuk_lama = intval($batch_lama_data['JUMLAH_MASUK_DUS'] ?? 0);
                }
                $stmt_batch_lama->close();
                
                $batch_kirim_lama_map[$id_batch] = $jumlah_kirim_lama;
                $batch_masuk_lama_map[$id_batch] = $jumlah_masuk_lama;
            }
            
            // Update setiap batch
            foreach ($batches as $batch) {
                $id_batch = $batch['id_detail_transfer_batch'] ?? '';
                
                if (empty($id_batch)) {
                    continue;
                }
                
                // Update DETAIL_TRANSFER_BARANG_BATCH
                if ($status_koreksi == 'DIKIRIM') {
                    // Untuk status DIKIRIM, update JUMLAH_KIRIM_DUS, SISA_STOCK_DUS, STOCK, dan STOCK_HISTORY
                    $jumlah_kirim_batch = intval($batch['jumlah_kirim_dus'] ?? 0);
                    
                    // Get ID_PESAN_BARANG dari batch
                    $query_batch_info = "SELECT ID_PESAN_BARANG FROM DETAIL_TRANSFER_BARANG_BATCH WHERE ID_DETAIL_TRANSFER_BARANG_BATCH = ?";
                    $stmt_batch_info = $conn->prepare($query_batch_info);
                    if (!$stmt_batch_info) {
                        throw new Exception('Gagal prepare query batch info: ' . $conn->error);
                    }
                    $stmt_batch_info->bind_param("s", $id_batch);
                    $stmt_batch_info->execute();
                    $result_batch_info = $stmt_batch_info->get_result();
                    if ($result_batch_info->num_rows == 0) {
                        $stmt_batch_info->close();
                        continue;
                    }
                    $batch_info = $result_batch_info->fetch_assoc();
                    $id_pesan_barang = $batch_info['ID_PESAN_BARANG'] ?? '';
                    $stmt_batch_info->close();
                    
                    if (empty($id_pesan_barang)) {
                        continue;
                    }
                    
                    // Get jumlah kirim lama dari map
                    $jumlah_kirim_lama = $batch_kirim_lama_map[$id_batch] ?? 0;
                    
                    // Update JUMLAH_KIRIM_DUS di DETAIL_TRANSFER_BARANG_BATCH
                    $update_batch = "UPDATE DETAIL_TRANSFER_BARANG_BATCH 
                                    SET JUMLAH_KIRIM_DUS = ?
                                    WHERE ID_DETAIL_TRANSFER_BARANG_BATCH = ? AND ID_DETAIL_TRANSFER_BARANG = ?";
                    $stmt_batch = $conn->prepare($update_batch);
                    if (!$stmt_batch) {
                        throw new Exception('Gagal prepare query update batch: ' . $conn->error);
                    }
                    $stmt_batch->bind_param("iss", $jumlah_kirim_batch, $id_batch, $id_detail);
                    if (!$stmt_batch->execute()) {
                        throw new Exception('Gagal update batch: ' . $stmt_batch->error);
                    }
                    $stmt_batch->close();
                    
                    // Hitung selisih jumlah kirim
                    $selisih_kirim = $jumlah_kirim_batch - $jumlah_kirim_lama;
                    
                    // Update SISA_STOCK_DUS di PESAN_BARANG
                    // Jika jumlah kirim bertambah, sisa stock berkurang (selisih negatif)
                    // Jika jumlah kirim berkurang, sisa stock bertambah (selisih positif)
                    if ($selisih_kirim != 0) {
                        // Get SISA_STOCK_DUS saat ini
                        $query_sisa = "SELECT SISA_STOCK_DUS FROM PESAN_BARANG WHERE ID_PESAN_BARANG = ?";
                        $stmt_sisa = $conn->prepare($query_sisa);
                        if (!$stmt_sisa) {
                            throw new Exception('Gagal prepare query sisa stock: ' . $conn->error);
                        }
                        $stmt_sisa->bind_param("s", $id_pesan_barang);
                        $stmt_sisa->execute();
                        $result_sisa = $stmt_sisa->get_result();
                        if ($result_sisa->num_rows > 0) {
                            $sisa_data = $result_sisa->fetch_assoc();
                            $sisa_stock_dus_awal = intval($sisa_data['SISA_STOCK_DUS'] ?? 0);
                            
                            // Update SISA_STOCK_DUS: sisa_stock_awal - selisih_kirim
                            // Jika selisih_kirim positif (kirim bertambah), sisa_stock berkurang
                            // Jika selisih_kirim negatif (kirim berkurang), sisa_stock bertambah
                            $sisa_stock_dus_akhir = max(0, $sisa_stock_dus_awal - $selisih_kirim);
                            
                            $update_sisa = "UPDATE PESAN_BARANG SET SISA_STOCK_DUS = ? WHERE ID_PESAN_BARANG = ?";
                            $stmt_update_sisa = $conn->prepare($update_sisa);
                            if (!$stmt_update_sisa) {
                                throw new Exception('Gagal prepare query update sisa stock: ' . $conn->error);
                            }
                            $stmt_update_sisa->bind_param("is", $sisa_stock_dus_akhir, $id_pesan_barang);
                            if (!$stmt_update_sisa->execute()) {
                                throw new Exception('Gagal update SISA_STOCK_DUS: ' . $stmt_update_sisa->error);
                            }
                            $stmt_update_sisa->close();
                        }
                        $stmt_sisa->close();
                    }
                    
                    $total_kirim_dus += $jumlah_kirim_batch;
                } else {
                    // Untuk status SELESAI, update kirim, tiba, ditolak, masuk
                    $jumlah_kirim_batch = intval($batch['jumlah_kirim_dus'] ?? 0);
                    $jumlah_tiba_batch = intval($batch['jumlah_diterima_dus'] ?? 0);
                    $jumlah_ditolak_batch = intval($batch['jumlah_ditolak_dus'] ?? 0);
                    
                    // Validasi: jumlah tiba tidak boleh melebihi jumlah kirim
                    if ($jumlah_tiba_batch > $jumlah_kirim_batch) {
                        $jumlah_tiba_batch = $jumlah_kirim_batch;
                    }
                    
                    // Validasi: jumlah ditolak tidak boleh melebihi jumlah tiba
                    if ($jumlah_ditolak_batch > $jumlah_tiba_batch) {
                        $jumlah_ditolak_batch = $jumlah_tiba_batch;
                    }
                    
                    $jumlah_masuk_batch = $jumlah_tiba_batch - $jumlah_ditolak_batch;
                    if ($jumlah_masuk_batch < 0) {
                        $jumlah_masuk_batch = 0;
                    }
                    
                    $update_batch = "UPDATE DETAIL_TRANSFER_BARANG_BATCH 
                                    SET JUMLAH_KIRIM_DUS = ?,
                                        JUMLAH_TIBA_DUS = ?,
                                        JUMLAH_DITOLAK_DUS = ?,
                                        JUMLAH_MASUK_DUS = ?
                                    WHERE ID_DETAIL_TRANSFER_BARANG_BATCH = ? AND ID_DETAIL_TRANSFER_BARANG = ?";
                    $stmt_batch = $conn->prepare($update_batch);
                    if (!$stmt_batch) {
                        throw new Exception('Gagal prepare query update batch: ' . $conn->error);
                    }
                    $stmt_batch->bind_param("iiiiss", $jumlah_kirim_batch, $jumlah_tiba_batch, $jumlah_ditolak_batch, $jumlah_masuk_batch, $id_batch, $id_detail);
                    if (!$stmt_batch->execute()) {
                        throw new Exception('Gagal update batch: ' . $stmt_batch->error);
                    }
                    $total_kirim_dus += $jumlah_kirim_batch;
                    $total_tiba_dus += $jumlah_tiba_batch;
                    $total_ditolak_dus += $jumlah_ditolak_batch;
                    $total_masuk_dus += $jumlah_masuk_batch;
                }
            }
            
            // Update detail transfer
            if ($status_koreksi == 'DIKIRIM') {
                // Untuk status DIKIRIM, update TOTAL_KIRIM_DUS
                $update_detail = "UPDATE DETAIL_TRANSFER_BARANG 
                                 SET TOTAL_KIRIM_DUS = ?
                                 WHERE ID_DETAIL_TRANSFER_BARANG = ? AND ID_TRANSFER_BARANG = ?";
                $stmt_detail = $conn->prepare($update_detail);
                if (!$stmt_detail) {
                    throw new Exception('Gagal prepare query detail: ' . $conn->error);
                }
                $stmt_detail->bind_param("iss", $total_kirim_dus, $id_detail, $id_transfer);
                if (!$stmt_detail->execute()) {
                    throw new Exception('Gagal update detail transfer: ' . $stmt_detail->error);
                }
                $stmt_detail->close();
                
                // Update STOCK dan STOCK_HISTORY untuk status DIKIRIM
                // Get SATUAN, SATUAN_PERDUS
                $query_barang = "SELECT mb.SATUAN_PERDUS, COALESCE(s.SATUAN, 'DUS') as SATUAN
                                FROM MASTER_BARANG mb
                                LEFT JOIN STOCK s ON mb.KD_BARANG = s.KD_BARANG AND s.KD_LOKASI = ?
                                WHERE mb.KD_BARANG = ?";
                $stmt_barang = $conn->prepare($query_barang);
                if (!$stmt_barang) {
                    throw new Exception('Gagal prepare query barang: ' . $conn->error);
                }
                $stmt_barang->bind_param("ss", $kd_lokasi_asal, $kd_barang);
                $stmt_barang->execute();
                $result_barang = $stmt_barang->get_result();
                
                if ($result_barang->num_rows > 0) {
                    $barang_data = $result_barang->fetch_assoc();
                    $satuan = $barang_data['SATUAN'];
                    $satuan_perdus = intval($barang_data['SATUAN_PERDUS'] ?? 1);
                    $jumlah_awal = $jumlah_stock_awal; // Gunakan nilai yang sudah diambil sebelumnya
                    
                    // Hitung total SISA_STOCK_DUS dari semua batch untuk kode barang ini di lokasi asal
                    $query_sum_sisa = "SELECT COALESCE(SUM(SISA_STOCK_DUS), 0) as TOTAL_SISA_STOCK_DUS
                                      FROM PESAN_BARANG
                                      WHERE KD_BARANG = ? AND KD_LOKASI = ? AND STATUS = 'SELESAI'";
                    $stmt_sum_sisa = $conn->prepare($query_sum_sisa);
                    if (!$stmt_sum_sisa) {
                        throw new Exception('Gagal prepare query sum sisa stock: ' . $conn->error);
                    }
                    $stmt_sum_sisa->bind_param("ss", $kd_barang, $kd_lokasi_asal);
                    $stmt_sum_sisa->execute();
                    $result_sum_sisa = $stmt_sum_sisa->get_result();
                    $sum_sisa_data = $result_sum_sisa->fetch_assoc();
                    $total_sisa_stock_dus = intval($sum_sisa_data['TOTAL_SISA_STOCK_DUS'] ?? 0);
                    $stmt_sum_sisa->close();
                    
                    // Konversi ke satuan stock yang sesuai
                    $jumlah_update_stock = $total_sisa_stock_dus;
                    if ($satuan == 'PIECES') {
                        $jumlah_update_stock = $total_sisa_stock_dus * $satuan_perdus;
                    }
                    
                    // Update STOCK
                    if ($jumlah_awal > 0 || $result_barang->num_rows > 0) {
                        $update_stock = "UPDATE STOCK 
                                       SET JUMLAH_BARANG = ?, 
                                           LAST_UPDATED = CURRENT_TIMESTAMP,
                                           UPDATED_BY = ?
                                       WHERE KD_BARANG = ? AND KD_LOKASI = ?";
                        $stmt_update_stock = $conn->prepare($update_stock);
                        if (!$stmt_update_stock) {
                            throw new Exception('Gagal prepare query update stock: ' . $conn->error);
                        }
                        $stmt_update_stock->bind_param("isss", $jumlah_update_stock, $user_id, $kd_barang, $kd_lokasi_asal);
                    } else {
                        $update_stock = "INSERT INTO STOCK (KD_BARANG, KD_LOKASI, JUMLAH_BARANG, UPDATED_BY, SATUAN, LAST_UPDATED)
                                       VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";
                        $stmt_update_stock = $conn->prepare($update_stock);
                        if (!$stmt_update_stock) {
                            throw new Exception('Gagal prepare query insert stock: ' . $conn->error);
                        }
                        $stmt_update_stock->bind_param("ssiss", $kd_barang, $kd_lokasi_asal, $jumlah_update_stock, $user_id, $satuan);
                    }
                    
                    if (!$stmt_update_stock->execute()) {
                        throw new Exception('Gagal mengupdate stock: ' . $stmt_update_stock->error);
                    }
                    $stmt_update_stock->close();
                    
                    // Catat ke STOCK_HISTORY untuk setiap batch dengan REF = ID_DETAIL_TRANSFER_BARANG_BATCH
                    // Setiap batch dicatat secara berurutan dengan stock awal dari batch sebelumnya
                    $stock_current = $jumlah_awal; // Stock saat ini (akan berubah per batch)
                    
                    // Catat per batch dengan REF = ID_DETAIL_TRANSFER_BARANG_BATCH
                    foreach ($batches as $batch) {
                        $id_batch_history = $batch['id_detail_transfer_batch'] ?? '';
                        if (empty($id_batch_history)) {
                            continue;
                        }
                        
                        // Get jumlah kirim baru dan lama untuk batch ini
                        $jumlah_kirim_batch_baru = intval($batch['jumlah_kirim_dus'] ?? 0);
                        $jumlah_kirim_batch_lama = $batch_kirim_lama_map[$id_batch_history] ?? 0;
                        
                        // Hitung selisih jumlah kirim untuk batch ini (dalam DUS)
                        $selisih_kirim_batch = $jumlah_kirim_batch_baru - $jumlah_kirim_batch_lama;
                        
                        // Konversi selisih ke satuan stock yang sesuai
                        $selisih_kirim_batch_stock = $selisih_kirim_batch;
                        if ($satuan == 'PIECES') {
                            $selisih_kirim_batch_stock = $selisih_kirim_batch * $satuan_perdus;
                        }
                        
                        // Untuk STOCK_HISTORY per batch:
                        // JUMLAH_AWAL = stock saat ini (dari batch sebelumnya atau stock awal)
                        // JUMLAH_PERUBAHAN = selisih kirim batch ini (negatif karena mengurangi stock saat kirim bertambah)
                        // JUMLAH_AKHIR = JUMLAH_AWAL + JUMLAH_PERUBAHAN
                        // REF = ID_DETAIL_TRANSFER_BARANG_BATCH (berbeda per batch)
                        
                        $jumlah_awal_batch = $stock_current;
                        $jumlah_perubahan_batch = -$selisih_kirim_batch_stock; // Negatif karena mengurangi stock saat kirim bertambah
                        $jumlah_akhir_batch = $jumlah_awal_batch + $jumlah_perubahan_batch;
                        
                        $id_history_batch = '';
                        do {
                            $uuid = ShortIdGenerator::generate(12, '');
                            $id_history_batch = 'SKHY' . $uuid;
                        } while (checkUUIDExists($conn, 'STOCK_HISTORY', 'ID_HISTORY_STOCK', $id_history_batch));
                        
                        $insert_history_batch = "INSERT INTO STOCK_HISTORY 
                                               (ID_HISTORY_STOCK, KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_AWAL, JUMLAH_PERUBAHAN, JUMLAH_AKHIR, TIPE_PERUBAHAN, REF, SATUAN)
                                               VALUES (?, ?, ?, ?, ?, ?, ?, 'KOREKSI', ?, ?)";
                        $stmt_history_batch = $conn->prepare($insert_history_batch);
                        if (!$stmt_history_batch) {
                            throw new Exception('Gagal prepare query insert history batch: ' . $conn->error);
                        }
                        $stmt_history_batch->bind_param("ssssiiiss", $id_history_batch, $kd_barang, $kd_lokasi_asal, $user_id, $jumlah_awal_batch, $jumlah_perubahan_batch, $jumlah_akhir_batch, $id_batch_history, $satuan);
                        if (!$stmt_history_batch->execute()) {
                            throw new Exception('Gagal insert history batch: ' . $stmt_history_batch->error);
                        }
                        $stmt_history_batch->close();
                        
                        // Update stock_current untuk batch berikutnya
                        $stock_current = $jumlah_akhir_batch;
                    }
                    
                    $stmt_barang->close();
                }
            } else {
                // Untuk status SELESAI, update kirim, tiba, ditolak, masuk
                $update_detail = "UPDATE DETAIL_TRANSFER_BARANG 
                                 SET TOTAL_KIRIM_DUS = ?,
                                     TOTAL_TIBA_DUS = ?,
                                     TOTAL_DITOLAK_DUS = ?,
                                     TOTAL_MASUK_DUS = ?
                                 WHERE ID_DETAIL_TRANSFER_BARANG = ? AND ID_TRANSFER_BARANG = ?";
                $stmt_detail = $conn->prepare($update_detail);
                if (!$stmt_detail) {
                    throw new Exception('Gagal prepare query detail: ' . $conn->error);
                }
                $stmt_detail->bind_param("iiiiss", $total_kirim_dus, $total_tiba_dus, $total_ditolak_dus, $total_masuk_dus, $id_detail, $id_transfer);
                if (!$stmt_detail->execute()) {
                    throw new Exception('Gagal update detail transfer: ' . $stmt_detail->error);
                }
            }
            
            // Hanya update STOCK dan STOCK_HISTORY jika status SELESAI
            if ($status_koreksi == 'SELESAI') {
                // Get SATUAN, SATUAN_PERDUS untuk gudang (lokasi asal)
                $query_barang_gudang = "SELECT mb.SATUAN_PERDUS, COALESCE(s.JUMLAH_BARANG, 0) as JUMLAH_BARANG, COALESCE(s.SATUAN, 'DUS') as SATUAN
                            FROM MASTER_BARANG mb
                            LEFT JOIN STOCK s ON mb.KD_BARANG = s.KD_BARANG AND s.KD_LOKASI = ?
                            WHERE mb.KD_BARANG = ?";
                $stmt_barang_gudang = $conn->prepare($query_barang_gudang);
                if (!$stmt_barang_gudang) {
                    throw new Exception('Gagal prepare query barang gudang: ' . $conn->error);
                }
                $stmt_barang_gudang->bind_param("ss", $kd_lokasi_asal, $kd_barang);
                $stmt_barang_gudang->execute();
                $result_barang_gudang = $stmt_barang_gudang->get_result();
                
                if ($result_barang_gudang->num_rows == 0) {
                    $stmt_barang_gudang->close();
                    continue;
                }
                
                $barang_data_gudang = $result_barang_gudang->fetch_assoc();
                $satuan_gudang = $barang_data_gudang['SATUAN'];
                $satuan_perdus = intval($barang_data_gudang['SATUAN_PERDUS'] ?? 1);
                $stock_awal_gudang = intval($barang_data_gudang['JUMLAH_BARANG'] ?? 0);
                
                // Catat ke STOCK_HISTORY untuk gudang per batch secara berurutan
                // Setiap batch dicatat dengan stock awal dari batch sebelumnya
                // Untuk gudang, perubahan berdasarkan selisih kirim (bukan selisih masuk)
                $stock_current_gudang = $stock_awal_gudang;
                
                foreach ($batches as $batch) {
                    $id_batch_history = $batch['id_detail_transfer_batch'] ?? '';
                    if (empty($id_batch_history)) {
                        continue;
                    }
                    
                    // Get jumlah kirim baru dan lama untuk batch ini
                    $jumlah_kirim_batch_baru = intval($batch['jumlah_kirim_dus'] ?? 0);
                    $jumlah_kirim_batch_lama = $batch_kirim_lama_map[$id_batch_history] ?? 0;
                    
                    // Hitung selisih jumlah kirim untuk batch ini (dalam DUS)
                    $selisih_kirim_batch = $jumlah_kirim_batch_baru - $jumlah_kirim_batch_lama;
                    
                    // Hanya catat ke STOCK_HISTORY gudang jika ada perubahan jumlah kirim
                    if ($selisih_kirim_batch != 0) {
                        // Konversi selisih ke satuan stock yang sesuai
                        $selisih_kirim_batch_stock = $selisih_kirim_batch;
                        if ($satuan_gudang == 'PIECES') {
                            $selisih_kirim_batch_stock = $selisih_kirim_batch * $satuan_perdus;
                        }
                        
                        // Untuk STOCK_HISTORY gudang per batch:
                        // JUMLAH_AWAL = stock gudang saat ini (dari batch sebelumnya atau stock awal)
                        // JUMLAH_PERUBAHAN = selisih kirim batch ini (negatif karena mengurangi stock saat kirim bertambah)
                        // JUMLAH_AKHIR = JUMLAH_AWAL + JUMLAH_PERUBAHAN
                        // REF = ID_DETAIL_TRANSFER_BARANG_BATCH (berbeda per batch)
                        
                        $jumlah_awal_batch_gudang = $stock_current_gudang;
                        $jumlah_perubahan_batch_gudang = -$selisih_kirim_batch_stock; // Negatif karena mengurangi stock saat kirim bertambah
                        $jumlah_akhir_batch_gudang = $jumlah_awal_batch_gudang + $jumlah_perubahan_batch_gudang;
                        
                        $id_history_batch_gudang = '';
                        do {
                            $uuid = ShortIdGenerator::generate(12, '');
                            $id_history_batch_gudang = 'SKHY' . $uuid;
                        } while (checkUUIDExists($conn, 'STOCK_HISTORY', 'ID_HISTORY_STOCK', $id_history_batch_gudang));
                        
                        $insert_history_batch_gudang = "INSERT INTO STOCK_HISTORY 
                                                   (ID_HISTORY_STOCK, KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_AWAL, JUMLAH_PERUBAHAN, JUMLAH_AKHIR, TIPE_PERUBAHAN, REF, SATUAN)
                                                   VALUES (?, ?, ?, ?, ?, ?, ?, 'KOREKSI', ?, ?)";
                        $stmt_history_batch_gudang = $conn->prepare($insert_history_batch_gudang);
                        if (!$stmt_history_batch_gudang) {
                            throw new Exception('Gagal prepare query insert history batch gudang: ' . $conn->error);
                        }
                        $stmt_history_batch_gudang->bind_param("ssssiiiss", $id_history_batch_gudang, $kd_barang, $kd_lokasi_asal, $user_id, $jumlah_awal_batch_gudang, $jumlah_perubahan_batch_gudang, $jumlah_akhir_batch_gudang, $id_batch_history, $satuan_gudang);
                        if (!$stmt_history_batch_gudang->execute()) {
                            throw new Exception('Gagal insert history batch gudang: ' . $stmt_history_batch_gudang->error);
                        }
                        $stmt_history_batch_gudang->close();
                        
                        // Update stock_current_gudang untuk batch berikutnya
                        $stock_current_gudang = $jumlah_akhir_batch_gudang;
                    }
                }
                
                // Finally, update the main STOCK table gudang dengan final calculated stock
                if ($stock_awal_gudang > 0 || $result_barang_gudang->num_rows > 0) {
                    $update_stock_gudang = "UPDATE STOCK 
                                       SET JUMLAH_BARANG = ?, 
                                           LAST_UPDATED = CURRENT_TIMESTAMP,
                                           UPDATED_BY = ?
                                       WHERE KD_BARANG = ? AND KD_LOKASI = ?";
                    $stmt_update_stock_gudang = $conn->prepare($update_stock_gudang);
                    if (!$stmt_update_stock_gudang) {
                        throw new Exception('Gagal prepare query update stock gudang: ' . $conn->error);
                    }
                    $stmt_update_stock_gudang->bind_param("isss", $stock_current_gudang, $user_id, $kd_barang, $kd_lokasi_asal);
                } else {
                    $update_stock_gudang = "INSERT INTO STOCK (KD_BARANG, KD_LOKASI, JUMLAH_BARANG, UPDATED_BY, SATUAN, LAST_UPDATED)
                                       VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";
                    $stmt_update_stock_gudang = $conn->prepare($update_stock_gudang);
                    if (!$stmt_update_stock_gudang) {
                        throw new Exception('Gagal prepare query insert stock gudang: ' . $conn->error);
                    }
                    $stmt_update_stock_gudang->bind_param("ssiss", $kd_barang, $kd_lokasi_asal, $stock_current_gudang, $user_id, $satuan_gudang);
                }
                
                if (!$stmt_update_stock_gudang->execute()) {
                    throw new Exception('Gagal mengupdate stock gudang: ' . $stmt_update_stock_gudang->error);
                }
                $stmt_update_stock_gudang->close();
                $stmt_barang_gudang->close();
                
                // Update STOCK dan STOCK_HISTORY untuk toko (lokasi tujuan) berdasarkan selisih masuk
                // Get SATUAN, SATUAN_PERDUS untuk toko (lokasi tujuan)
                $query_barang_toko = "SELECT mb.SATUAN_PERDUS, COALESCE(s.JUMLAH_BARANG, 0) as JUMLAH_BARANG, COALESCE(s.SATUAN, 'PIECES') as SATUAN
                            FROM MASTER_BARANG mb
                            LEFT JOIN STOCK s ON mb.KD_BARANG = s.KD_BARANG AND s.KD_LOKASI = ?
                            WHERE mb.KD_BARANG = ?";
                $stmt_barang_toko = $conn->prepare($query_barang_toko);
                if (!$stmt_barang_toko) {
                    throw new Exception('Gagal prepare query barang toko: ' . $conn->error);
                }
                $stmt_barang_toko->bind_param("ss", $kd_lokasi_tujuan, $kd_barang);
                $stmt_barang_toko->execute();
                $result_barang_toko = $stmt_barang_toko->get_result();
                
                if ($result_barang_toko->num_rows > 0) {
                    $barang_data_toko = $result_barang_toko->fetch_assoc();
                    $satuan_toko = $barang_data_toko['SATUAN'];
                    $satuan_perdus_toko = intval($barang_data_toko['SATUAN_PERDUS'] ?? 1);
                    $stock_awal_toko = intval($barang_data_toko['JUMLAH_BARANG'] ?? 0);
                    
                    // Catat ke STOCK_HISTORY untuk toko per batch secara berurutan
                    // Setiap batch dicatat dengan stock awal dari batch sebelumnya
                    // Untuk toko, perubahan berdasarkan selisih masuk (bukan selisih kirim)
                    $stock_current_toko = $stock_awal_toko;
                    
                    foreach ($batches as $batch) {
                        $id_batch_history = $batch['id_detail_transfer_batch'] ?? '';
                        if (empty($id_batch_history)) {
                            continue;
                        }
                        
                        // Get jumlah masuk baru dan lama untuk batch ini
                        $jumlah_masuk_batch_baru = intval($batch['jumlah_diterima_dus'] ?? 0) - intval($batch['jumlah_ditolak_dus'] ?? 0);
                        if ($jumlah_masuk_batch_baru < 0) {
                            $jumlah_masuk_batch_baru = 0;
                        }
                        $jumlah_masuk_batch_lama = $batch_masuk_lama_map[$id_batch_history] ?? 0;
                        
                        // Hitung selisih jumlah masuk untuk batch ini (dalam DUS)
                        $selisih_masuk_batch = $jumlah_masuk_batch_baru - $jumlah_masuk_batch_lama;
                        
                        // Hanya catat ke STOCK_HISTORY toko jika ada perubahan jumlah masuk
                        if ($selisih_masuk_batch != 0) {
                            // Konversi selisih ke satuan stock yang sesuai
                            $selisih_masuk_batch_stock = $selisih_masuk_batch;
                            if ($satuan_toko == 'PIECES') {
                                $selisih_masuk_batch_stock = $selisih_masuk_batch * $satuan_perdus_toko;
                            }
                            
                            // Untuk STOCK_HISTORY toko per batch:
                            // JUMLAH_AWAL = stock toko saat ini (dari batch sebelumnya atau stock awal)
                            // JUMLAH_PERUBAHAN = selisih masuk batch ini (positif karena menambah stock)
                            // JUMLAH_AKHIR = JUMLAH_AWAL + JUMLAH_PERUBAHAN
                            // REF = ID_DETAIL_TRANSFER_BARANG_BATCH (berbeda per batch)
                            
                            $jumlah_awal_batch_toko = $stock_current_toko;
                            $jumlah_perubahan_batch_toko = $selisih_masuk_batch_stock; // Positif karena menambah stock
                            $jumlah_akhir_batch_toko = $jumlah_awal_batch_toko + $jumlah_perubahan_batch_toko;
                            
                            $id_history_batch_toko = '';
                            do {
                                $uuid = ShortIdGenerator::generate(12, '');
                                $id_history_batch_toko = 'SKHY' . $uuid;
                            } while (checkUUIDExists($conn, 'STOCK_HISTORY', 'ID_HISTORY_STOCK', $id_history_batch_toko));
                            
                            $insert_history_batch_toko = "INSERT INTO STOCK_HISTORY 
                                                       (ID_HISTORY_STOCK, KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_AWAL, JUMLAH_PERUBAHAN, JUMLAH_AKHIR, TIPE_PERUBAHAN, REF, SATUAN)
                                                       VALUES (?, ?, ?, ?, ?, ?, ?, 'KOREKSI', ?, ?)";
                            $stmt_history_batch_toko = $conn->prepare($insert_history_batch_toko);
                            if (!$stmt_history_batch_toko) {
                                throw new Exception('Gagal prepare query insert history batch toko: ' . $conn->error);
                            }
                            $stmt_history_batch_toko->bind_param("ssssiiiss", $id_history_batch_toko, $kd_barang, $kd_lokasi_tujuan, $user_id, $jumlah_awal_batch_toko, $jumlah_perubahan_batch_toko, $jumlah_akhir_batch_toko, $id_batch_history, $satuan_toko);
                            if (!$stmt_history_batch_toko->execute()) {
                                throw new Exception('Gagal insert history batch toko: ' . $stmt_history_batch_toko->error);
                            }
                            $stmt_history_batch_toko->close();
                            
                            // Update stock_current_toko untuk batch berikutnya
                            $stock_current_toko = $jumlah_akhir_batch_toko;
                        }
                        
                        // Hitung selisih lama dan baru untuk kompensasi MUTASI_BARANG_RUSAK
                        $jumlah_kirim_batch_baru = intval($batch['jumlah_kirim_dus'] ?? 0);
                        $jumlah_kirim_batch_lama = $batch_kirim_lama_map[$id_batch_history] ?? 0;
                        
                        // Selisih lama = masuk_lama - kirim_lama
                        $selisih_lama_dus = $jumlah_masuk_batch_lama - $jumlah_kirim_batch_lama;
                        // Selisih baru = masuk_baru - kirim_baru
                        $selisih_baru_dus = $jumlah_masuk_batch_baru - $jumlah_kirim_batch_baru;
                        // Perubahan selisih = selisih_baru - selisih_lama
                        $perubahan_selisih_dus = $selisih_baru_dus - $selisih_lama_dus;
                        
                        // Jika ada perubahan selisih, buat kompensasi di MUTASI_BARANG_RUSAK
                        if ($perubahan_selisih_dus != 0) {
                            // Konversi ke pieces
                            $perubahan_selisih_pieces = $perubahan_selisih_dus * $satuan_perdus_toko;
                            
                            // Get AVG_HARGA_BELI_PIECES untuk menghitung total uang
                            $query_harga = "SELECT AVG_HARGA_BELI_PIECES FROM MASTER_BARANG WHERE KD_BARANG = ?";
                            $stmt_harga = $conn->prepare($query_harga);
                            if (!$stmt_harga) {
                                throw new Exception('Gagal prepare query harga: ' . $conn->error);
                            }
                            $stmt_harga->bind_param("s", $kd_barang);
                            $stmt_harga->execute();
                            $result_harga = $stmt_harga->get_result();
                            $harga_barang_pieces = 0;
                            if ($result_harga->num_rows > 0) {
                                $harga_data = $result_harga->fetch_assoc();
                                $harga_barang_pieces = floatval($harga_data['AVG_HARGA_BELI_PIECES'] ?? 0);
                            }
                            $stmt_harga->close();
                            
                            // TOTAL_UANG = perubahan_selisih_pieces * harga_barang_pieces
                            $total_uang = $perubahan_selisih_pieces * $harga_barang_pieces;
                            
                            // Generate ID_MUTASI_BARANG_RUSAK dengan format MTRK+UUID
                            $id_mutasi_rusak = '';
                            do {
                                $uuid = ShortIdGenerator::generate(12, '');
                                $id_mutasi_rusak = 'MTRK' . $uuid;
                            } while (checkUUIDExists($conn, 'MUTASI_BARANG_RUSAK', 'ID_MUTASI_BARANG_RUSAK', $id_mutasi_rusak));
                            
                            // Insert kompensasi ke MUTASI_BARANG_RUSAK
                            $insert_mutasi_rusak = "INSERT INTO MUTASI_BARANG_RUSAK 
                                                  (ID_MUTASI_BARANG_RUSAK, KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_MUTASI_DUS, SATUAN_PERDUS, TOTAL_BARANG_PIECES, HARGA_BARANG_PIECES, TOTAL_UANG, REF)
                                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            $stmt_mutasi_rusak = $conn->prepare($insert_mutasi_rusak);
                            if (!$stmt_mutasi_rusak) {
                                throw new Exception('Gagal prepare query mutasi rusak: ' . $conn->error);
                            }
                            $stmt_mutasi_rusak->bind_param("ssssiiidds", $id_mutasi_rusak, $kd_barang, $kd_lokasi_tujuan, $user_id, $perubahan_selisih_dus, $satuan_perdus_toko, $perubahan_selisih_pieces, $harga_barang_pieces, $total_uang, $id_batch_history);
                            if (!$stmt_mutasi_rusak->execute()) {
                                throw new Exception('Gagal insert mutasi rusak: ' . $stmt_mutasi_rusak->error);
                            }
                            $stmt_mutasi_rusak->close();
                        }
                    }
                    
                    // Finally, update the main STOCK table toko dengan final calculated stock
                    if ($stock_awal_toko > 0 || $result_barang_toko->num_rows > 0) {
                        $update_stock_toko = "UPDATE STOCK 
                                           SET JUMLAH_BARANG = ?, 
                                               LAST_UPDATED = CURRENT_TIMESTAMP,
                                               UPDATED_BY = ?
                                           WHERE KD_BARANG = ? AND KD_LOKASI = ?";
                        $stmt_update_stock_toko = $conn->prepare($update_stock_toko);
                        if (!$stmt_update_stock_toko) {
                            throw new Exception('Gagal prepare query update stock toko: ' . $conn->error);
                        }
                        $stmt_update_stock_toko->bind_param("isss", $stock_current_toko, $user_id, $kd_barang, $kd_lokasi_tujuan);
                    } else {
                        $update_stock_toko = "INSERT INTO STOCK (KD_BARANG, KD_LOKASI, JUMLAH_BARANG, UPDATED_BY, SATUAN, LAST_UPDATED)
                                           VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";
                        $stmt_update_stock_toko = $conn->prepare($update_stock_toko);
                        if (!$stmt_update_stock_toko) {
                            throw new Exception('Gagal prepare query insert stock toko: ' . $conn->error);
                        }
                        $stmt_update_stock_toko->bind_param("ssiss", $kd_barang, $kd_lokasi_tujuan, $stock_current_toko, $user_id, $satuan_toko);
                    }
                    
                    if (!$stmt_update_stock_toko->execute()) {
                        throw new Exception('Gagal mengupdate stock toko: ' . $stmt_update_stock_toko->error);
                    }
                    $stmt_update_stock_toko->close();
                }
                $stmt_barang_toko->close();
            }
        }
        
        // Commit transaksi
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Koreksi transfer berhasil disimpan!']);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit();
}

// Query untuk mendapatkan riwayat resupply dari gudang ini (dikelompokkan per ID_TRANSFER_BARANG)
$query_riwayat = "SELECT 
    tb.ID_TRANSFER_BARANG,
    tb.WAKTU_PESAN_TRANSFER,
    tb.WAKTU_KIRIM_TRANSFER,
    tb.WAKTU_SELESAI_TRANSFER,
    tb.KD_LOKASI_TUJUAN,
    ml_tujuan.NAMA_LOKASI as NAMA_LOKASI_TUJUAN,
    -- Tentukan status transfer berdasarkan status detail
    CASE 
        WHEN EXISTS (SELECT 1 FROM DETAIL_TRANSFER_BARANG WHERE ID_TRANSFER_BARANG = tb.ID_TRANSFER_BARANG AND STATUS = 'DIPESAN') THEN 'DIPESAN'
        WHEN EXISTS (SELECT 1 FROM DETAIL_TRANSFER_BARANG WHERE ID_TRANSFER_BARANG = tb.ID_TRANSFER_BARANG AND STATUS = 'DIKIRIM') THEN 'DIKIRIM'
        WHEN EXISTS (SELECT 1 FROM DETAIL_TRANSFER_BARANG WHERE ID_TRANSFER_BARANG = tb.ID_TRANSFER_BARANG AND STATUS = 'SELESAI') 
             AND NOT EXISTS (SELECT 1 FROM DETAIL_TRANSFER_BARANG WHERE ID_TRANSFER_BARANG = tb.ID_TRANSFER_BARANG AND STATUS IN ('DIPESAN', 'DIKIRIM')) THEN 'SELESAI'
        WHEN EXISTS (SELECT 1 FROM DETAIL_TRANSFER_BARANG WHERE ID_TRANSFER_BARANG = tb.ID_TRANSFER_BARANG AND STATUS = 'DIBATALKAN') 
             AND NOT EXISTS (SELECT 1 FROM DETAIL_TRANSFER_BARANG WHERE ID_TRANSFER_BARANG = tb.ID_TRANSFER_BARANG AND STATUS IN ('DIPESAN', 'DIKIRIM', 'SELESAI')) THEN 'DIBATALKAN'
        ELSE tb.STATUS
    END as STATUS_TRANSFER
FROM TRANSFER_BARANG tb
LEFT JOIN MASTER_LOKASI ml_tujuan ON tb.KD_LOKASI_TUJUAN = ml_tujuan.KD_LOKASI
WHERE tb.KD_LOKASI_ASAL = ?
ORDER BY 
    CASE 
        WHEN EXISTS (SELECT 1 FROM DETAIL_TRANSFER_BARANG WHERE ID_TRANSFER_BARANG = tb.ID_TRANSFER_BARANG AND STATUS = 'DIPESAN') THEN 1
        WHEN EXISTS (SELECT 1 FROM DETAIL_TRANSFER_BARANG WHERE ID_TRANSFER_BARANG = tb.ID_TRANSFER_BARANG AND STATUS = 'DIKIRIM') THEN 2
        WHEN EXISTS (SELECT 1 FROM DETAIL_TRANSFER_BARANG WHERE ID_TRANSFER_BARANG = tb.ID_TRANSFER_BARANG AND STATUS = 'SELESAI') 
             AND NOT EXISTS (SELECT 1 FROM DETAIL_TRANSFER_BARANG WHERE ID_TRANSFER_BARANG = tb.ID_TRANSFER_BARANG AND STATUS IN ('DIPESAN', 'DIKIRIM')) THEN 3
        ELSE 4
    END,
    CASE 
        WHEN EXISTS (SELECT 1 FROM DETAIL_TRANSFER_BARANG WHERE ID_TRANSFER_BARANG = tb.ID_TRANSFER_BARANG AND STATUS = 'DIPESAN') THEN tb.WAKTU_PESAN_TRANSFER
        WHEN EXISTS (SELECT 1 FROM DETAIL_TRANSFER_BARANG WHERE ID_TRANSFER_BARANG = tb.ID_TRANSFER_BARANG AND STATUS = 'DIKIRIM') THEN tb.WAKTU_KIRIM_TRANSFER
        ELSE NULL
    END ASC,
    CASE 
        WHEN EXISTS (SELECT 1 FROM DETAIL_TRANSFER_BARANG WHERE ID_TRANSFER_BARANG = tb.ID_TRANSFER_BARANG AND STATUS = 'SELESAI') 
             AND NOT EXISTS (SELECT 1 FROM DETAIL_TRANSFER_BARANG WHERE ID_TRANSFER_BARANG = tb.ID_TRANSFER_BARANG AND STATUS IN ('DIPESAN', 'DIKIRIM')) THEN tb.WAKTU_SELESAI_TRANSFER
        ELSE NULL
    END DESC,
    tb.WAKTU_PESAN_TRANSFER DESC";
$stmt_riwayat = $conn->prepare($query_riwayat);
$stmt_riwayat->bind_param("s", $kd_lokasi);
$stmt_riwayat->execute();
$result_riwayat = $stmt_riwayat->get_result();

// Format waktu stack (dd/mm/yyyy HH:ii WIB)
function formatWaktuStack($waktu_pesan, $waktu_kirim, $waktu_selesai, $status_transfer) {
    $html = '<div class="d-flex flex-column gap-1">';
    
    // Waktu diterima (jika ada WAKTU_SELESAI dan status SELESAI) - tampilkan di atas
    if (!empty($waktu_selesai) && $status_transfer == 'SELESAI') {
        $date_sampai = new DateTime($waktu_selesai);
        $waktu_sampai_formatted = $date_sampai->format('d/m/Y H:i') . ' WIB';
        $html .= '<div>';
        $html .= htmlspecialchars($waktu_sampai_formatted) . ' ';
        $html .= '<span class="badge bg-success">DITERIMA</span>';
        $html .= '</div>';
    }
    
    // Waktu Dikirim - tampilkan di tengah (jika ada)
    if (!empty($waktu_kirim)) {
        $date_kirim = new DateTime($waktu_kirim);
        $waktu_kirim_formatted = $date_kirim->format('d/m/Y H:i') . ' WIB';
        $html .= '<div>';
        $html .= htmlspecialchars($waktu_kirim_formatted) . ' ';
        $html .= '<span class="badge bg-info">DIKIRIM</span>';
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
$active_page = 'stock';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Riwayat Resupply - <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></title>
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
            <h1 class="page-title">Pemilik - Riwayat Resupply - <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></h1>
            <?php if (!empty($lokasi['ALAMAT_LOKASI'])): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($lokasi['ALAMAT_LOKASI']); ?></p>
            <?php endif; ?>
        </div>

        <!-- Table Riwayat Resupply -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tableRiwayat" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID Transfer</th>
                            <th>Lokasi Tujuan</th>
                            <th>Waktu</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_riwayat && $result_riwayat->num_rows > 0): ?>
                            <?php while ($row = $result_riwayat->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['ID_TRANSFER_BARANG']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_LOKASI_TUJUAN'] ?: $row['KD_LOKASI_TUJUAN']); ?></td>
                                    <td data-order="<?php 
                                        $waktu_order = '';
                                        switch($row['STATUS_TRANSFER']) {
                                            case 'DIPESAN':
                                                $waktu_order = !empty($row['WAKTU_PESAN_TRANSFER']) ? strtotime($row['WAKTU_PESAN_TRANSFER']) : 0;
                                                break;
                                            case 'DIKIRIM':
                                                $waktu_order = !empty($row['WAKTU_KIRIM_TRANSFER']) ? strtotime($row['WAKTU_KIRIM_TRANSFER']) : 0;
                                                break;
                                            case 'SELESAI':
                                                // Use negative timestamp for DESC sorting (newest first)
                                                $waktu_order = !empty($row['WAKTU_SELESAI_TRANSFER']) ? -strtotime($row['WAKTU_SELESAI_TRANSFER']) : 0;
                                                break;
                                            default:
                                                $waktu_order = !empty($row['WAKTU_PESAN_TRANSFER']) ? strtotime($row['WAKTU_PESAN_TRANSFER']) : 0;
                                        }
                                        echo $waktu_order;
                                    ?>"><?php echo formatWaktuStack($row['WAKTU_PESAN_TRANSFER'], $row['WAKTU_KIRIM_TRANSFER'], $row['WAKTU_SELESAI_TRANSFER'], $row['STATUS_TRANSFER']); ?></td>
                                    <td data-order="<?php 
                                        $status_order = 0;
                                        switch($row['STATUS_TRANSFER']) {
                                            case 'DIPESAN':
                                                $status_order = 1;
                                                break;
                                            case 'DIKIRIM':
                                                $status_order = 2;
                                                break;
                                            case 'SELESAI':
                                                $status_order = 3;
                                                break;
                                            default:
                                                $status_order = 4;
                                        }
                                        echo $status_order;
                                    ?>">
                                        <?php 
                                        $status_text = '';
                                        $status_class = '';
                                        switch($row['STATUS_TRANSFER']) {
                                            case 'DIPESAN':
                                                $status_text = 'Dipesan';
                                                $status_class = 'warning';
                                                break;
                                            case 'DIKIRIM':
                                                $status_text = 'Dikirim';
                                                $status_class = 'info';
                                                break;
                                            case 'SELESAI':
                                                $status_text = 'Selesai';
                                                $status_class = 'success';
                                                break;
                                            case 'DIBATALKAN':
                                                $status_text = 'Dibatalkan';
                                                $status_class = 'danger';
                                                break;
                                            case 'TIDAK_DIKIRIM':
                                                $status_text = 'Tidak Dikirim';
                                                $status_class = 'secondary';
                                                break;
                                            default:
                                                $status_text = $row['STATUS_TRANSFER'];
                                                $status_class = 'secondary';
                                        }
                                        ?>
                                        <span class="badge bg-<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <button class="btn-view btn-sm" onclick="lihatSuratJalan('<?php echo htmlspecialchars($row['ID_TRANSFER_BARANG']); ?>')" style="white-space: nowrap; width: auto;">Lihat Surat Jalan</button>
                                            <button class="btn-view btn-sm" onclick="lihatDetailTransfer('<?php echo htmlspecialchars($row['ID_TRANSFER_BARANG']); ?>')" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; white-space: nowrap; width: auto;">Lihat Detail Transfer</button>
                                            <?php if ($row['STATUS_TRANSFER'] == 'DIPESAN'): ?>
                                                <button class="btn btn-danger btn-sm" onclick="batalkanTransfer('<?php echo htmlspecialchars($row['ID_TRANSFER_BARANG']); ?>')">Batalkan</button>
                                            <?php endif; ?>
                                            <?php if ($row['STATUS_TRANSFER'] == 'DIKIRIM'): ?>
                                                <button class="btn btn-warning btn-sm" onclick="koreksiTransfer('<?php echo htmlspecialchars($row['ID_TRANSFER_BARANG']); ?>', 'DIKIRIM')">Koreksi</button>
                                            <?php endif; ?>
                                            <?php if ($row['STATUS_TRANSFER'] == 'SELESAI'): ?>
                                                <button class="btn btn-warning btn-sm" onclick="koreksiTransfer('<?php echo htmlspecialchars($row['ID_TRANSFER_BARANG']); ?>', 'SELESAI')">Koreksi</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted">Tidak ada riwayat resupply</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Lihat Surat Jalan -->
    <div class="modal fade" id="modalLihatSuratJalan" tabindex="-1" aria-labelledby="modalLihatSuratJalanLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalLihatSuratJalanLabel">Lihat Surat Jalan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0" style="height: 80vh; overflow: hidden;">
                    <iframe id="suratJalanIframe" src="" style="width: 100%; height: 100%; border: none;"></iframe>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Lihat Detail Transfer -->
    <div class="modal fade" id="modalDetailTransfer" tabindex="-1" aria-labelledby="modalDetailTransferLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalDetailTransferLabel">Detail Transfer Barang</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive" style="max-height: 70vh; overflow-y: auto;">
                        <table class="table table-bordered table-hover" id="tableDetailTransfer">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>ID Detail Transfer</th>
                                    <th>Kode Barang</th>
                                    <th>Merek</th>
                                    <th>Kategori</th>
                                    <th>Nama Barang</th>
                                    <th>Total Pesan (dus)</th>
                                    <th>Total Kirim (dus)</th>
                                    <th>Total Tiba (dus)</th>
                                    <th>Total Ditolak (dus)</th>
                                    <th>Total Masuk (dus)</th>
                                    <th>Status Detail</th>
                                    <th>Batch</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyDetailTransfer">
                                <tr>
                                    <td colspan="12" class="text-center text-muted">Memuat data...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Koreksi Transfer -->
    <div class="modal fade" id="modalKoreksiTransfer" tabindex="-1" aria-labelledby="modalKoreksiTransferLabel" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%); color: white;">
                    <h5 class="modal-title" id="modalKoreksiTransferLabel">Koreksi Transfer Barang</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Info Asal -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">ID Transfer</label>
                            <input type="text" class="form-control" id="koreksi_id_transfer" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Kode Asal</label>
                            <input type="text" class="form-control" id="koreksi_kd_asal" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Nama Asal</label>
                            <input type="text" class="form-control" id="koreksi_nama_asal" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Alamat Asal</label>
                            <input type="text" class="form-control" id="koreksi_alamat_asal" readonly style="background-color: #e9ecef;">
                        </div>
                    </div>
                    <input type="hidden" id="koreksi_status_koreksi" value="SELESAI">
                    
                    <!-- Tabel Detail Barang -->
                    <div class="table-responsive" style="max-height: 60vh; overflow-y: auto;">
                        <table class="table table-bordered table-hover" id="tableKoreksiTransfer">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th style="vertical-align: middle;">ID Detail Transfer</th>
                                    <th style="vertical-align: middle;">Kode Barang</th>
                                    <th style="vertical-align: middle;">Merek</th>
                                    <th style="vertical-align: middle;">Kategori</th>
                                    <th style="vertical-align: middle;">Nama Barang</th>
                                    <th style="vertical-align: middle;">Total Pesan Transfer (dus)</th>
                                    <th style="vertical-align: middle;">Batch</th>
                                    <th style="vertical-align: middle;">Total Kirim (dus)</th>
                                    <th style="vertical-align: middle;">Total Masuk (dus)</th>
                                    <th style="vertical-align: middle;">Jumlah per Dus</th>
                                    <th style="vertical-align: middle;">Total Masuk (pieces)</th>
                                    <th style="vertical-align: middle;">Stock Sekarang (pieces)</th>
                                    <th style="vertical-align: middle;">Jumlah Stock Akhir (pieces)</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyKoreksiTransfer">
                                <tr>
                                    <td colspan="13" class="text-center text-muted">Memuat data...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" onclick="simpanKoreksiTransfer()">Simpan Koreksi</button>
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
            
            $('#tableRiwayat').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada riwayat resupply'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[3, 'asc'], [2, 'asc']], // Sort by Status (priority) then Waktu
                columnDefs: [
                    { orderable: false, targets: 4 }, // Disable sorting on Action column
                    { type: 'num', targets: [3, 2] } // Status and Waktu columns use numeric sorting
                ],
                scrollX: true,
                autoWidth: false
            }).on('error.dt', function(e, settings, techNote, message) {
                console.log('DataTables error suppressed:', message);
                return false;
            });
        });

        function lihatSuratJalan(idTransfer) {
            // Set iframe source ke download_surat_jalan.php
            $('#suratJalanIframe').attr('src', 'download_surat_jalan.php?id_transfer=' + encodeURIComponent(idTransfer));
            
            // Buka modal
            var modal = new bootstrap.Modal(document.getElementById('modalLihatSuratJalan'));
            modal.show();
        }

        function lihatDetailTransfer(idTransfer) {
            // Load data detail transfer
            $.ajax({
                url: '',
                method: 'GET',
                data: {
                    get_detail_transfer: '1',
                    id_transfer: idTransfer
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.detail_data.length > 0) {
                        // Render tabel
                        renderTabelDetailTransfer(response.detail_data);
                        
                        // Buka modal
                        $('#modalDetailTransfer').modal('show');
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: response.message || 'Gagal memuat data detail transfer!',
                            confirmButtonColor: '#e74c3c'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Terjadi kesalahan saat memuat data detail transfer!',
                        confirmButtonColor: '#e74c3c'
                    });
                }
            });
        }

        function renderTabelDetailTransfer(data) {
            var tbody = $('#tbodyDetailTransfer');
            tbody.empty();
            
            if (data.length === 0) {
                tbody.append('<tr><td colspan="12" class="text-center text-muted">Tidak ada data</td></tr>');
                return;
            }
            
            data.forEach(function(item) {
                // Render batch info
                var batchHtml = '';
                if (item.batches && item.batches.length > 0) {
                    batchHtml = '<div class="d-flex flex-column gap-2">';
                    item.batches.forEach(function(batch) {
                        batchHtml += '<div class="small border rounded p-2">' +
                            '<strong>ID Batch: ' + escapeHtml(batch.id_pesan_barang) + '</strong><br>' +
                            '<strong>ID Detail Transfer Batch: ' + escapeHtml(batch.id_detail_transfer_batch) + '</strong><br>' +
                            '<span class="text-muted">Supplier: ' + escapeHtml(batch.nama_supplier) + '</span><br>' +
                            '<span class="text-muted">Exp: ' + escapeHtml(batch.tgl_expired_display) + '</span><br>' +
                            '<span class="text-muted">Pesan: ' + numberFormat(batch.jumlah_pesan_batch_dus) + ' dus</span><br>' +
                            '<span class="text-muted">Kirim: ' + numberFormat(batch.jumlah_kirim_dus) + ' dus</span><br>' +
                            '<span class="text-muted">Tiba: ' + numberFormat(batch.jumlah_tiba_dus) + ' dus</span><br>' +
                            '<span class="text-muted">Ditolak: ' + numberFormat(batch.jumlah_ditolak_dus) + ' dus</span><br>' +
                            '<span class="text-muted">Masuk: ' + numberFormat(batch.jumlah_masuk_dus) + ' dus</span>' +
                            '</div>';
                    });
                    batchHtml += '</div>';
                } else {
                    batchHtml = '<span class="text-muted">-</span>';
                }
                
                var statusClass = '';
                var statusText = item.status_detail || '-';
                switch(item.status_detail) {
                    case 'DIPESAN':
                        statusClass = 'warning';
                        break;
                    case 'DIKIRIM':
                        statusClass = 'info';
                        break;
                    case 'SELESAI':
                        statusClass = 'success';
                        break;
                    case 'DIBATALKAN':
                        statusClass = 'danger';
                        break;
                    default:
                        statusClass = 'secondary';
                }
                
                var row = '<tr>' +
                    '<td>' + escapeHtml(item.id_detail_transfer) + '</td>' +
                    '<td>' + escapeHtml(item.kd_barang) + '</td>' +
                    '<td>' + escapeHtml(item.nama_merek) + '</td>' +
                    '<td>' + escapeHtml(item.nama_kategori) + '</td>' +
                    '<td>' + escapeHtml(item.nama_barang) + '</td>' +
                    '<td>' + numberFormat(item.total_pesan_dus) + '</td>' +
                    '<td>' + numberFormat(item.total_kirim_dus) + '</td>' +
                    '<td>' + numberFormat(item.total_tiba_dus) + '</td>' +
                    '<td>' + numberFormat(item.total_ditolak_dus) + '</td>' +
                    '<td>' + numberFormat(item.total_masuk_dus) + '</td>' +
                    '<td><span class="badge bg-' + statusClass + '">' + escapeHtml(statusText) + '</span></td>' +
                    '<td style="min-width: 300px;">' + batchHtml + '</td>' +
                    '</tr>';
                tbody.append(row);
            });
        }

        function batalkanTransfer(idTransfer) {
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi',
                text: 'Apakah Anda yakin ingin membatalkan transfer ini?',
                showCancelButton: true,
                confirmButtonText: 'Ya, Batalkan',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: {
                            action: 'batalkan_transfer',
                            id_transfer: idTransfer
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
                                text: 'Terjadi kesalahan saat membatalkan transfer!',
                                confirmButtonColor: '#e74c3c'
                            });
                        }
                    });
                }
            });
        }

        function koreksiTransfer(idTransfer, statusKoreksi) {
            // Load data transfer
            $.ajax({
                url: '',
                method: 'GET',
                data: {
                    get_transfer_data_koreksi: '1',
                    id_transfer: idTransfer,
                    status_koreksi: statusKoreksi || 'SELESAI'
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.transfer_info && response.detail_data.length > 0) {
                        // Set info asal
                        $('#koreksi_id_transfer').val(response.transfer_info.id_transfer);
                        $('#koreksi_kd_asal').val(response.transfer_info.kd_lokasi_asal);
                        $('#koreksi_nama_asal').val(response.transfer_info.nama_lokasi_asal);
                        $('#koreksi_alamat_asal').val(response.transfer_info.alamat_lokasi_asal);
                        
                        // Simpan status koreksi untuk digunakan saat simpan
                        $('#koreksi_status_koreksi').val(response.status_koreksi || 'SELESAI');
                        
                        // Update header tabel berdasarkan status
                        var isDikirim = response.status_koreksi == 'DIKIRIM';
                        var thead = $('#tableKoreksiTransfer thead tr');
                        thead.empty();
                        thead.append('<th style="vertical-align: middle;">ID Detail Transfer</th>');
                        thead.append('<th style="vertical-align: middle;">Kode Barang</th>');
                        thead.append('<th style="vertical-align: middle;">Merek</th>');
                        thead.append('<th style="vertical-align: middle;">Kategori</th>');
                        thead.append('<th style="vertical-align: middle;">Nama Barang</th>');
                        thead.append('<th style="vertical-align: middle;">Total Pesan Transfer (dus)</th>');
                        thead.append('<th style="vertical-align: middle;">Batch</th>');
                        thead.append('<th style="vertical-align: middle;">Total Kirim (dus)</th>');
                        if (isDikirim) {
                            thead.append('<th style="vertical-align: middle;">Kirim Semua</th>');
                        }
                        if (!isDikirim) {
                            thead.append('<th style="vertical-align: middle;">Total Masuk (dus)</th>');
                            thead.append('<th style="vertical-align: middle;">Jumlah per Dus</th>');
                            thead.append('<th style="vertical-align: middle;">Total Masuk (pieces)</th>');
                            thead.append('<th style="vertical-align: middle;">Stock Sekarang (pieces)</th>');
                            thead.append('<th style="vertical-align: middle;">Jumlah Stock Akhir (pieces)</th>');
                            thead.append('<th style="vertical-align: middle;">Kirim dan Masuk Semua</th>');
                        }
                        
                        // Render tabel
                        renderTabelKoreksiTransfer(response.detail_data, response.status_koreksi || 'SELESAI');
                        
                        // Update modal title berdasarkan status
                        if (response.status_koreksi == 'DIKIRIM') {
                            $('#modalKoreksiTransferLabel').text('Koreksi Transfer Barang (Status: Dikirim)');
                        } else {
                            $('#modalKoreksiTransferLabel').text('Koreksi Transfer Barang (Status: Selesai)');
                        }
                        
                        // Buka modal
                        $('#modalKoreksiTransfer').modal('show');
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: response.message || 'Gagal memuat data transfer!',
                            confirmButtonColor: '#e74c3c'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Terjadi kesalahan saat memuat data transfer!',
                        confirmButtonColor: '#e74c3c'
                    });
                }
            });
        }
        
        function renderTabelKoreksiTransfer(data, statusKoreksi) {
            var tbody = $('#tbodyKoreksiTransfer');
            tbody.empty();
            
            statusKoreksi = statusKoreksi || 'SELESAI';
            var isDikirim = statusKoreksi == 'DIKIRIM';
            
            if (data.length === 0) {
                var colCount = isDikirim ? 9 : 14;
                tbody.append('<tr><td colspan="' + colCount + '" class="text-center text-muted">Tidak ada data</td></tr>');
                return;
            }
            
            data.forEach(function(item, index) {
                var totalMasukDus = 0;
                var totalMasukPieces = 0;
                var jumlahStockAkhir = item.stock_sekarang;
                
                // Render batch info dengan input per batch
                var batchHtml = '';
                if (item.batches && item.batches.length > 0) {
                    batchHtml = '<div class="d-flex flex-column gap-2">';
                    item.batches.forEach(function(batch, batchIndex) {
                        var jumlahPesanBatch = batch.jumlah_pesan_batch_dus || batch.jumlah_kirim_dus;
                        batchHtml += '<div class="small border rounded p-2">' +
                            '<strong>' + escapeHtml(batch.id_pesan_barang) + '</strong><br>' +
                            '<span class="text-muted">Exp: ' + escapeHtml(batch.tgl_expired_display) + '</span><br>' +
                            '<span class="text-muted">Pesan: ' + numberFormat(jumlahPesanBatch) + ' dus</span><br>';
                        
                        // Jika status DIKIRIM, tampilkan input jumlah kirim saja
                        if (isDikirim) {
                            batchHtml += '<label class="form-label small mb-1">Kirim (dus):</label>' +
                                '<input type="number" class="form-control form-control-sm batch-kirim-dus" ' +
                                'min="0" max="' + jumlahPesanBatch + '" ' +
                                'value="' + batch.jumlah_kirim_dus + '" ' +
                                'data-id-detail="' + escapeHtml(item.id_detail_transfer) + '" ' +
                                'data-id-batch="' + escapeHtml(batch.id_detail_transfer_batch) + '" ' +
                                'data-jumlah-pesan="' + jumlahPesanBatch + '" ' +
                                'data-index="' + index + '" ' +
                                'data-batch-index="' + batchIndex + '" ' +
                                'style="width: 100px;">';
                        } else {
                            // Untuk status SELESAI, bisa rubah kirim, tiba, dan ditolak
                            batchHtml += '<label class="form-label small mb-1">Kirim (dus):</label>' +
                                '<input type="number" class="form-control form-control-sm batch-kirim-dus" ' +
                                'min="0" max="' + jumlahPesanBatch + '" ' +
                                'value="' + batch.jumlah_kirim_dus + '" ' +
                                'data-id-detail="' + escapeHtml(item.id_detail_transfer) + '" ' +
                                'data-id-batch="' + escapeHtml(batch.id_detail_transfer_batch) + '" ' +
                                'data-jumlah-pesan="' + jumlahPesanBatch + '" ' +
                                'data-index="' + index + '" ' +
                                'data-batch-index="' + batchIndex + '" ' +
                                'style="width: 100px;">' +
                                '<label class="form-label small mb-1 mt-1">Tiba (dus):</label>' +
                                '<input type="number" class="form-control form-control-sm batch-diterima-dus" ' +
                                'min="0" max="' + batch.jumlah_kirim_dus + '" ' +
                                'value="' + batch.jumlah_tiba_dus + '" ' +
                                'data-id-detail="' + escapeHtml(item.id_detail_transfer) + '" ' +
                                'data-id-batch="' + escapeHtml(batch.id_detail_transfer_batch) + '" ' +
                                'data-jumlah-kirim="' + batch.jumlah_kirim_dus + '" ' +
                                'data-index="' + index + '" ' +
                                'data-batch-index="' + batchIndex + '" ' +
                                'style="width: 100px;">' +
                                '<label class="form-label small mb-1 mt-1">Ditolak (dus):</label>' +
                                '<input type="number" class="form-control form-control-sm batch-ditolak-dus" ' +
                                'min="0" max="' + batch.jumlah_kirim_dus + '" ' +
                                'value="' + batch.jumlah_ditolak_dus + '" ' +
                                'data-id-detail="' + escapeHtml(item.id_detail_transfer) + '" ' +
                                'data-id-batch="' + escapeHtml(batch.id_detail_transfer_batch) + '" ' +
                                'data-jumlah-kirim="' + batch.jumlah_kirim_dus + '" ' +
                                'data-index="' + index + '" ' +
                                'data-batch-index="' + batchIndex + '" ' +
                                'style="width: 100px;">';
                        }
                        
                        batchHtml += '</div>';
                    });
                    batchHtml += '</div>';
                } else {
                    batchHtml = '<span class="text-muted">-</span>';
                }
                
                var row = '<tr data-id-detail="' + escapeHtml(item.id_detail_transfer) + '" data-kd-barang="' + escapeHtml(item.kd_barang) + '" data-index="' + index + '">' +
                    '<td>' + escapeHtml(item.id_detail_transfer) + '</td>' +
                    '<td>' + escapeHtml(item.kd_barang) + '</td>' +
                    '<td>' + escapeHtml(item.nama_merek) + '</td>' +
                    '<td>' + escapeHtml(item.nama_kategori) + '</td>' +
                    '<td>' + escapeHtml(item.nama_barang) + '</td>' +
                    '<td>' + numberFormat(item.jumlah_pesan_dus) + '</td>' +
                    '<td style="min-width: ' + (isDikirim ? '200px' : '250px') + ';">' + batchHtml + '</td>';
                
                // Jika status DIKIRIM, hanya tampilkan Total Kirim dan checkbox Kirim Semua
                if (isDikirim) {
                    row += '<td class="total-kirim-dus">' + numberFormat(item.jumlah_kirim_dus) + '</td>' +
                        '<td><input type="checkbox" class="form-check-input kirim-semua-checkbox" ' +
                        'data-id-detail="' + escapeHtml(item.id_detail_transfer) + '" ' +
                        'data-index="' + index + '"></td>';
                } else {
                    row += '<td class="total-kirim-dus">' + numberFormat(item.jumlah_kirim_dus) + '</td>' +
                        '<td class="total-masuk-dus">' + numberFormat(item.jumlah_masuk_dus) + '</td>' +
                        '<td>' + numberFormat(item.satuan_perdus) + '</td>' +
                        '<td class="total-masuk-pieces">' + numberFormat(item.jumlah_masuk_dus * item.satuan_perdus) + '</td>' +
                        '<td>' + numberFormat(item.stock_sekarang) + '</td>' +
                        '<td class="jumlah-stock-akhir">' + numberFormat(jumlahStockAkhir) + '</td>' +
                        '<td style="vertical-align: middle; text-align: center;">' +
                        '<div class="form-check d-inline-block">' +
                        '<input type="checkbox" class="form-check-input kirim-masuk-semua-checkbox" ' +
                        'data-id-detail="' + escapeHtml(item.id_detail_transfer) + '" ' +
                        'data-index="' + index + '" id="kirim-masuk-semua-' + index + '">' +
                        '<label class="form-check-label small" for="kirim-masuk-semua-' + index + '">Kirim dan Masuk Semua</label>' +
                        '</div>' +
                        '</td>';
                }
                
                row += '</tr>';
                tbody.append(row);
            });
            
            // Attach event listeners
            attachKoreksiTransferEventListeners(isDikirim);
            
            // Hitung total masuk untuk setiap row setelah render
            data.forEach(function(item, index) {
                calculateKoreksiTransfer(index, isDikirim);
            });
        }
        
        function attachKoreksiTransferEventListeners(isDikirim) {
            isDikirim = isDikirim || false;
            
            // Event listener untuk input jumlah kirim per batch (hanya untuk status DIKIRIM)
            if (isDikirim) {
                $(document).off('input', '.batch-kirim-dus').on('input', '.batch-kirim-dus', function() {
                    var $input = $(this);
                    var index = $input.data('index');
                    var jumlahKirim = parseInt($input.val()) || 0;
                    var jumlahPesan = parseInt($input.data('jumlah-pesan')) || 0;
                    
                    // Validasi: tidak boleh melebihi jumlah pesan batch
                    if (jumlahKirim > jumlahPesan) {
                        $input.val(jumlahPesan);
                        jumlahKirim = jumlahPesan;
                    }
                    
                    // Validasi: tidak boleh negatif
                    if (jumlahKirim < 0) {
                        $input.val(0);
                        jumlahKirim = 0;
                    }
                    
                    calculateKoreksiTransfer(index, isDikirim);
                });
                
                // Event listener untuk checkbox "Kirim Semua"
                $(document).off('change', '.kirim-semua-checkbox').on('change', '.kirim-semua-checkbox', function() {
                    var $checkbox = $(this);
                    var idDetail = $checkbox.data('id-detail');
                    var isChecked = $checkbox.is(':checked');
                    
                    $('.batch-kirim-dus[data-id-detail="' + idDetail + '"]').each(function() {
                        var $input = $(this);
                        var jumlahPesan = parseInt($input.data('jumlah-pesan')) || 0;
                        if (isChecked) {
                            $input.val(jumlahPesan);
                        } else {
                            $input.val(0);
                        }
                        var index = $input.data('index');
                        calculateKoreksiTransfer(index, true);
                    });
                });
            }
            
            // Event listener untuk input jumlah kirim per batch (untuk status SELESAI)
            if (!isDikirim) {
                $(document).off('input', '.batch-kirim-dus').on('input', '.batch-kirim-dus', function() {
                    var $input = $(this);
                    var index = $input.data('index');
                    var idBatch = $input.data('id-batch');
                    var jumlahKirim = parseInt($input.val()) || 0;
                    var jumlahPesan = parseInt($input.data('jumlah-pesan')) || 0;
                    
                    // Validasi: tidak boleh melebihi jumlah pesan batch
                    if (jumlahKirim > jumlahPesan) {
                        $input.val(jumlahPesan);
                        jumlahKirim = jumlahPesan;
                    }
                    
                    // Validasi: tidak boleh negatif
                    if (jumlahKirim < 0) {
                        $input.val(0);
                        jumlahKirim = 0;
                    }
                    
                    // Update data-jumlah-kirim untuk batch ini
                    $input.attr('data-jumlah-kirim', jumlahKirim);
                    
                    // Update max untuk batch-diterima-dus dan batch-ditolak-dus yang sama
                    var $diterimaInput = $('.batch-diterima-dus[data-id-batch="' + idBatch + '"]');
                    var $ditolakInput = $('.batch-ditolak-dus[data-id-batch="' + idBatch + '"]');
                    var jumlahTiba = parseInt($diterimaInput.val()) || 0;
                    var jumlahDitolak = parseInt($ditolakInput.val()) || 0;
                    
                    // Update max dan data-jumlah-kirim
                    $diterimaInput.attr('max', jumlahKirim);
                    $diterimaInput.attr('data-jumlah-kirim', jumlahKirim);
                    $ditolakInput.attr('max', jumlahKirim);
                    $ditolakInput.attr('data-jumlah-kirim', jumlahKirim);
                    
                    // Jika jumlah tiba melebihi jumlah kirim baru, set ke jumlah kirim
                    if (jumlahTiba > jumlahKirim) {
                        $diterimaInput.val(jumlahKirim);
                        jumlahTiba = jumlahKirim;
                    }
                    
                    // Jika jumlah ditolak melebihi jumlah tiba, set ke jumlah tiba
                    if (jumlahDitolak > jumlahTiba) {
                        $ditolakInput.val(jumlahTiba);
                    }
                    
                    // Update checkbox "Kirim dan Masuk Semua"
                    if (!isDikirim) {
                        updateCheckboxKirimMasukSemua(index);
                    }
                    
                    calculateKoreksiTransfer(index, isDikirim);
                });
            }
            
            // Event listener untuk input jumlah tiba per batch
            $(document).off('input', '.batch-diterima-dus').on('input', '.batch-diterima-dus', function() {
                var $input = $(this);
                var index = $input.data('index');
                var idBatch = $input.data('id-batch');
                var jumlahTiba = parseInt($input.val()) || 0;
                // Ambil jumlah kirim dari input atau data attribute
                var jumlahKirim = parseInt($input.attr('data-jumlah-kirim')) || parseInt($input.data('jumlah-kirim')) || 0;
                
                // Validasi: tidak boleh melebihi jumlah kirim
                if (jumlahTiba > jumlahKirim) {
                    $input.val(jumlahKirim);
                    jumlahTiba = jumlahKirim;
                }
                
                // Validasi: tidak boleh negatif
                if (jumlahTiba < 0) {
                    $input.val(0);
                    jumlahTiba = 0;
                }
                
                // Update max untuk batch-ditolak-dus yang sama
                var $ditolakInput = $('.batch-ditolak-dus[data-id-batch="' + idBatch + '"]');
                var jumlahDitolak = parseInt($ditolakInput.val()) || 0;
                if (jumlahDitolak > jumlahTiba) {
                    $ditolakInput.val(jumlahTiba);
                }
                $ditolakInput.attr('max', jumlahTiba);
                
                // Update checkbox "Kirim dan Masuk Semua"
                if (!isDikirim) {
                    updateCheckboxKirimMasukSemua(index);
                }
                
                calculateKoreksiTransfer(index, isDikirim);
            });
            
            // Event listener untuk input jumlah ditolak per batch
            $(document).off('input', '.batch-ditolak-dus').on('input', '.batch-ditolak-dus', function() {
                var $input = $(this);
                var index = $input.data('index');
                var idBatch = $input.data('id-batch');
                var jumlahDitolak = parseInt($input.val()) || 0;
                var jumlahTiba = parseInt($('.batch-diterima-dus[data-id-batch="' + idBatch + '"]').val()) || 0;
                
                // Validasi: jumlah ditolak tidak boleh melebihi jumlah tiba
                if (jumlahDitolak > jumlahTiba) {
                    $input.val(jumlahTiba);
                    jumlahDitolak = jumlahTiba;
                }
                
                // Validasi: tidak boleh negatif
                if (jumlahDitolak < 0) {
                    $input.val(0);
                    jumlahDitolak = 0;
                }
                
                // Update checkbox "Kirim dan Masuk Semua"
                if (!isDikirim) {
                    updateCheckboxKirimMasukSemua(index);
                }
                
                calculateKoreksiTransfer(index, isDikirim);
            });
            
            // Event listener untuk checkbox "Kirim dan Masuk Semua" (untuk status SELESAI)
            if (!isDikirim) {
                $(document).off('change', '.kirim-masuk-semua-checkbox').on('change', '.kirim-masuk-semua-checkbox', function() {
                    var $checkbox = $(this);
                    var idDetail = $checkbox.data('id-detail');
                    var isChecked = $checkbox.is(':checked');
                    
                    if (isChecked) {
                        // Set semua batch: kirim = jumlah pesan, tiba = jumlah kirim, ditolak = 0
                        $('.batch-kirim-dus[data-id-detail="' + idDetail + '"]').each(function() {
                            var $inputKirim = $(this);
                            var jumlahPesan = parseInt($inputKirim.data('jumlah-pesan')) || 0;
                            var idBatch = $inputKirim.data('id-batch');
                            
                            // Set kirim = jumlah pesan
                            $inputKirim.val(jumlahPesan);
                            $inputKirim.attr('data-jumlah-kirim', jumlahPesan);
                            
                            // Set tiba = jumlah kirim (jumlah pesan)
                            var $inputTiba = $('.batch-diterima-dus[data-id-batch="' + idBatch + '"]');
                            $inputTiba.val(jumlahPesan);
                            $inputTiba.attr('data-jumlah-kirim', jumlahPesan);
                            $inputTiba.attr('max', jumlahPesan);
                            
                            // Set ditolak = 0
                            var $inputDitolak = $('.batch-ditolak-dus[data-id-batch="' + idBatch + '"]');
                            $inputDitolak.val(0);
                            $inputDitolak.attr('max', jumlahPesan);
                            $inputDitolak.attr('data-jumlah-kirim', jumlahPesan);
                        });
                    } else {
                        // Reset semua batch ke 0
                        $('.batch-kirim-dus[data-id-detail="' + idDetail + '"]').each(function() {
                            var $inputKirim = $(this);
                            var idBatch = $inputKirim.data('id-batch');
                            
                            $inputKirim.val(0);
                            $inputKirim.attr('data-jumlah-kirim', 0);
                            
                            var $inputTiba = $('.batch-diterima-dus[data-id-batch="' + idBatch + '"]');
                            $inputTiba.val(0);
                            $inputTiba.attr('data-jumlah-kirim', 0);
                            $inputTiba.attr('max', 0);
                            
                            var $inputDitolak = $('.batch-ditolak-dus[data-id-batch="' + idBatch + '"]');
                            $inputDitolak.val(0);
                            $inputDitolak.attr('max', 0);
                            $inputDitolak.attr('data-jumlah-kirim', 0);
                        });
                    }
                    
                    var index = $checkbox.data('index');
                    calculateKoreksiTransfer(index, false);
                });
            }
        }
        
        function updateCheckboxKirimMasukSemua(index) {
            var idDetail = $('tr[data-index="' + index + '"]').data('id-detail');
            var semuaTerisiPenuh = true;
            var adaYangTerisi = false;
            
            $('.batch-kirim-dus[data-id-detail="' + idDetail + '"]').each(function() {
                var $inputKirim = $(this);
                var jumlahKirim = parseInt($inputKirim.val()) || 0;
                var jumlahPesan = parseInt($inputKirim.data('jumlah-pesan')) || 0;
                var idBatch = $inputKirim.data('id-batch');
                
                var jumlahTiba = parseInt($('.batch-diterima-dus[data-id-batch="' + idBatch + '"]').val()) || 0;
                var jumlahDitolak = parseInt($('.batch-ditolak-dus[data-id-batch="' + idBatch + '"]').val()) || 0;
                
                if (jumlahKirim > 0 || jumlahTiba > 0 || jumlahDitolak > 0) {
                    adaYangTerisi = true;
                }
                
                // Cek apakah kirim = pesan, tiba = kirim, dan ditolak = 0
                if (jumlahKirim < jumlahPesan || jumlahTiba < jumlahKirim || jumlahDitolak > 0) {
                    semuaTerisiPenuh = false;
                }
            });
            
            var $checkbox = $('.kirim-masuk-semua-checkbox[data-index="' + index + '"]');
            if (semuaTerisiPenuh && adaYangTerisi) {
                $checkbox.prop('checked', true);
            } else {
                $checkbox.prop('checked', false);
            }
        }
        
        function calculateKoreksiTransfer(index, isDikirim) {
            isDikirim = isDikirim || false;
            var row = $('tr[data-index="' + index + '"]');
            var idDetail = row.data('id-detail');
            
            // Hitung total kirim dari semua batch (jika status DIKIRIM)
            if (isDikirim) {
                var totalKirimDus = 0;
                $('.batch-kirim-dus[data-id-detail="' + idDetail + '"]').each(function() {
                    var jumlahKirim = parseInt($(this).val()) || 0;
                    totalKirimDus += jumlahKirim;
                });
                row.find('.total-kirim-dus').text(numberFormat(totalKirimDus));
            } else {
                // Get satuan perdus dari jumlah per dus column
                var jumlahPerDusText = row.find('td').eq(9).text().replace(/\./g, '');
                var satuanPerdus = parseInt(jumlahPerDusText) || 1;
                
                // Hitung total kirim dari semua batch (untuk status SELESAI)
                var totalKirimDus = 0;
                $('.batch-kirim-dus[data-id-detail="' + idDetail + '"]').each(function() {
                    var jumlahKirim = parseInt($(this).val()) || 0;
                    totalKirimDus += jumlahKirim;
                });
                
                // Hitung total dari semua batch
                var totalMasukDus = 0;
                $('.batch-diterima-dus[data-id-detail="' + idDetail + '"]').each(function() {
                    var jumlahTiba = parseInt($(this).val()) || 0;
                    var idBatch = $(this).data('id-batch');
                    var jumlahDitolak = parseInt($('.batch-ditolak-dus[data-id-batch="' + idBatch + '"]').val()) || 0;
                    var jumlahMasukBatch = jumlahTiba - jumlahDitolak;
                    if (jumlahMasukBatch < 0) {
                        jumlahMasukBatch = 0;
                    }
                    totalMasukDus += jumlahMasukBatch;
                });
                
                // Hitung total masuk (pieces)
                var totalMasukPieces = totalMasukDus * satuanPerdus;
                
                // Update tampilan
                row.find('.total-kirim-dus').text(numberFormat(totalKirimDus));
                row.find('.total-masuk-dus').text(numberFormat(totalMasukDus));
                row.find('.total-masuk-pieces').text(numberFormat(totalMasukPieces));
                
                // Hitung stock akhir
                var stockSekarangIndex = 11;
                var stockSekarang = parseInt(row.find('td').eq(stockSekarangIndex).text().replace(/\./g, '')) || 0;
                
                // Hitung jumlah stock akhir
                // Stock akhir = stock sekarang - (total masuk baru - total masuk lama)
                // Simpan nilai lama saat pertama kali render
                if (!row.find('.total-masuk-dus').data('old-value')) {
                    var oldValueText = row.find('.total-masuk-dus').text().replace(/\./g, '');
                    row.find('.total-masuk-dus').data('old-value', oldValueText);
                }
                var oldTotalMasukDus = parseInt(row.find('.total-masuk-dus').data('old-value')) || 0;
                var oldTotalMasukPieces = oldTotalMasukDus * satuanPerdus;
                var selisihPieces = totalMasukPieces - oldTotalMasukPieces;
                var jumlahStockAkhir = stockSekarang - selisihPieces; // Kurangi karena mengurangi stock gudang saat total masuk bertambah
                
                row.find('.jumlah-stock-akhir').text(numberFormat(jumlahStockAkhir));
            }
        }
        
        function simpanKoreksiTransfer() {
            var idTransfer = $('#koreksi_id_transfer').val();
            var statusKoreksi = $('#koreksi_status_koreksi').val() || 'SELESAI';
            var batchKoreksi = [];
            var isDikirim = statusKoreksi == 'DIKIRIM';
            
            // Kumpulkan data batch dari tabel
            if (isDikirim) {
                $('.batch-kirim-dus').each(function() {
                    var $inputKirim = $(this);
                    var idDetail = $inputKirim.data('id-detail');
                    var idBatch = $inputKirim.data('id-batch');
                    var jumlahKirimDus = parseInt($inputKirim.val()) || 0;
                    
                    batchKoreksi.push({
                        id_detail_transfer: idDetail,
                        id_detail_transfer_batch: idBatch,
                        jumlah_kirim_dus: jumlahKirimDus
                    });
                });
            } else {
                // Untuk status SELESAI, kumpulkan kirim, tiba, dan ditolak
                $('.batch-kirim-dus').each(function() {
                    var $inputKirim = $(this);
                    var idDetail = $inputKirim.data('id-detail');
                    var idBatch = $inputKirim.data('id-batch');
                    var jumlahKirimDus = parseInt($inputKirim.val()) || 0;
                    var jumlahDiterimaDus = parseInt($('.batch-diterima-dus[data-id-batch="' + idBatch + '"]').val()) || 0;
                    var jumlahDitolakDus = parseInt($('.batch-ditolak-dus[data-id-batch="' + idBatch + '"]').val()) || 0;
                    
                    batchKoreksi.push({
                        id_detail_transfer: idDetail,
                        id_detail_transfer_batch: idBatch,
                        jumlah_kirim_dus: jumlahKirimDus,
                        jumlah_diterima_dus: jumlahDiterimaDus,
                        jumlah_ditolak_dus: jumlahDitolakDus
                    });
                });
            }
            
            if (batchKoreksi.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Tidak ada batch yang ditemukan!',
                    confirmButtonColor: '#667eea'
                });
                return;
            }
            
            // Konfirmasi
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi',
                text: 'Apakah Anda yakin ingin menyimpan koreksi transfer ini?',
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
                            action: 'koreksi_transfer',
                            id_transfer: idTransfer,
                            status_koreksi: statusKoreksi,
                            batch_koreksi: batchKoreksi
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
                                    $('#modalKoreksiTransfer').modal('hide');
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
                                text: 'Terjadi kesalahan saat menyimpan koreksi!',
                                confirmButtonColor: '#e74c3c'
                            });
                        }
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
        
        // Reset modal saat ditutup
        $('#modalKoreksiTransfer').on('hidden.bs.modal', function() {
            $('#tbodyKoreksiTransfer').html('<tr><td colspan="14" class="text-center text-muted">Memuat data...</td></tr>');
        });
    </script>
</body>
</html>

