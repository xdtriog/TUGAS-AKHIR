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
                 WHERE KD_LOKASI = ? AND TYPE_LOKASI = 'toko' AND STATUS = 'AKTIF'";
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

// Handle AJAX request untuk get data transfer (untuk form koreksi)
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['get_transfer_data_koreksi'])) {
    header('Content-Type: application/json');
    
    $id_transfer = isset($_GET['id_transfer']) ? trim($_GET['id_transfer']) : '';
    $status_koreksi = isset($_GET['status_koreksi']) ? trim($_GET['status_koreksi']) : 'SELESAI'; // 'DIKIRIM' atau 'SELESAI'
    
    if (empty($id_transfer)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Get data transfer lengkap dengan batch (untuk status DIKIRIM atau SELESAI)
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
        dtb.STATUS as STATUS_DETAIL,
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
    WHERE tb.ID_TRANSFER_BARANG = ? AND tb.KD_LOKASI_TUJUAN = ? AND dtb.STATUS = ?
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
            
            // Simpan jumlah kirim lama untuk setiap batch sebelum update (untuk STOCK_HISTORY)
            $batch_kirim_lama_map = [];
            if ($status_koreksi == 'DIKIRIM') {
                foreach ($batches as $batch) {
                    $id_batch = $batch['id_detail_transfer_batch'] ?? '';
                    if (empty($id_batch)) {
                        continue;
                    }
                    
                    // Get JUMLAH_KIRIM_DUS lama dari batch sebelum update
                    $query_batch_lama = "SELECT JUMLAH_KIRIM_DUS FROM DETAIL_TRANSFER_BARANG_BATCH WHERE ID_DETAIL_TRANSFER_BARANG_BATCH = ?";
                    $stmt_batch_lama = $conn->prepare($query_batch_lama);
                    if (!$stmt_batch_lama) {
                        throw new Exception('Gagal prepare query batch lama: ' . $conn->error);
                    }
                    $stmt_batch_lama->bind_param("s", $id_batch);
                    $stmt_batch_lama->execute();
                    $result_batch_lama = $stmt_batch_lama->get_result();
                    $jumlah_kirim_lama = 0;
                    if ($result_batch_lama->num_rows > 0) {
                        $batch_lama_data = $result_batch_lama->fetch_assoc();
                        $jumlah_kirim_lama = intval($batch_lama_data['JUMLAH_KIRIM_DUS'] ?? 0);
                    }
                    $stmt_batch_lama->close();
                    
                    $batch_kirim_lama_map[$id_batch] = $jumlah_kirim_lama;
                }
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
                    // Untuk status SELESAI, update tiba, ditolak, masuk
                    $jumlah_tiba_batch = intval($batch['jumlah_diterima_dus'] ?? 0);
                    $jumlah_ditolak_batch = intval($batch['jumlah_ditolak_dus'] ?? 0);
                    
                    // Validasi: jumlah ditolak tidak boleh melebihi jumlah tiba
                    if ($jumlah_ditolak_batch > $jumlah_tiba_batch) {
                        $jumlah_ditolak_batch = $jumlah_tiba_batch;
                    }
                    
                    $jumlah_masuk_batch = $jumlah_tiba_batch - $jumlah_ditolak_batch;
                    if ($jumlah_masuk_batch < 0) {
                        $jumlah_masuk_batch = 0;
                    }
                    
                    $update_batch = "UPDATE DETAIL_TRANSFER_BARANG_BATCH 
                                    SET JUMLAH_TIBA_DUS = ?,
                                        JUMLAH_DITOLAK_DUS = ?,
                                        JUMLAH_MASUK_DUS = ?
                                    WHERE ID_DETAIL_TRANSFER_BARANG_BATCH = ? AND ID_DETAIL_TRANSFER_BARANG = ?";
                    $stmt_batch = $conn->prepare($update_batch);
                    if (!$stmt_batch) {
                        throw new Exception('Gagal prepare query update batch: ' . $conn->error);
                    }
                    $stmt_batch->bind_param("iiiss", $jumlah_tiba_batch, $jumlah_ditolak_batch, $jumlah_masuk_batch, $id_batch, $id_detail);
                    if (!$stmt_batch->execute()) {
                        throw new Exception('Gagal update batch: ' . $stmt_batch->error);
                    }
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
                // Get SATUAN, SATUAN_PERDUS, dan lokasi asal
                $query_barang = "SELECT mb.SATUAN_PERDUS, COALESCE(s.SATUAN, 'DUS') as SATUAN, COALESCE(s.JUMLAH_BARANG, 0) as JUMLAH_BARANG
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
                // Untuk status SELESAI, update tiba, ditolak, masuk
                $update_detail = "UPDATE DETAIL_TRANSFER_BARANG 
                                 SET TOTAL_TIBA_DUS = ?,
                                     TOTAL_DITOLAK_DUS = ?,
                                     TOTAL_MASUK_DUS = ?
                                 WHERE ID_DETAIL_TRANSFER_BARANG = ? AND ID_TRANSFER_BARANG = ?";
                $stmt_detail = $conn->prepare($update_detail);
                if (!$stmt_detail) {
                    throw new Exception('Gagal prepare query detail: ' . $conn->error);
                }
                $stmt_detail->bind_param("iiiss", $total_tiba_dus, $total_ditolak_dus, $total_masuk_dus, $id_detail, $id_transfer);
                if (!$stmt_detail->execute()) {
                    throw new Exception('Gagal update detail transfer: ' . $stmt_detail->error);
                }
            }
            
            // Hanya update STOCK dan STOCK_HISTORY jika status SELESAI
            if ($status_koreksi == 'SELESAI') {
                // Get SATUAN, SATUAN_PERDUS
                $query_barang = "SELECT mb.SATUAN_PERDUS, COALESCE(s.SATUAN, 'PIECES') as SATUAN, COALESCE(s.JUMLAH_BARANG, 0) as JUMLAH_BARANG
                                FROM MASTER_BARANG mb
                                LEFT JOIN STOCK s ON mb.KD_BARANG = s.KD_BARANG AND s.KD_LOKASI = ?
                                WHERE mb.KD_BARANG = ?";
                $stmt_barang = $conn->prepare($query_barang);
                if (!$stmt_barang) {
                    throw new Exception('Gagal prepare query barang: ' . $conn->error);
                }
                $stmt_barang->bind_param("ss", $kd_lokasi_tujuan, $kd_barang);
                $stmt_barang->execute();
                $result_barang = $stmt_barang->get_result();
                
                if ($result_barang->num_rows == 0) {
                    continue;
                }
                
                $barang_data = $result_barang->fetch_assoc();
                $satuan = $barang_data['SATUAN'];
                $satuan_perdus = intval($barang_data['SATUAN_PERDUS'] ?? 1);
                $jumlah_awal = intval($barang_data['JUMLAH_BARANG'] ?? 0);
                
                // Hitung selisih total masuk (baru - lama)
                $selisih_masuk_dus = $total_masuk_dus - $old_total_masuk_dus;
                $selisih_masuk_pieces = $selisih_masuk_dus * $satuan_perdus;
                
                // Update STOCK di toko (lokasi tujuan)
                $jumlah_akhir = $jumlah_awal + $selisih_masuk_pieces;
                
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
                    $stmt_update_stock->bind_param("isss", $jumlah_akhir, $user_id, $kd_barang, $kd_lokasi_tujuan);
                } else {
                    $update_stock = "INSERT INTO STOCK (KD_BARANG, KD_LOKASI, JUMLAH_BARANG, UPDATED_BY, SATUAN, LAST_UPDATED)
                                   VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";
                    $stmt_update_stock = $conn->prepare($update_stock);
                    if (!$stmt_update_stock) {
                        throw new Exception('Gagal prepare query insert stock: ' . $conn->error);
                    }
                    $stmt_update_stock->bind_param("ssiss", $kd_barang, $kd_lokasi_tujuan, $jumlah_akhir, $user_id, $satuan);
                }
                
                if (!$stmt_update_stock->execute()) {
                    throw new Exception('Gagal mengupdate stock: ' . $stmt_update_stock->error);
                }
                
                // Insert ke STOCK_HISTORY untuk toko (lokasi tujuan)
                $id_history = '';
                do {
                    $uuid = ShortIdGenerator::generate(12, '');
                    $id_history = 'SKHY' . $uuid;
                } while (checkUUIDExists($conn, 'STOCK_HISTORY', 'ID_HISTORY_STOCK', $id_history));
                
                $jumlah_perubahan = $selisih_masuk_pieces;
                $insert_history = "INSERT INTO STOCK_HISTORY 
                                  (ID_HISTORY_STOCK, KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_AWAL, JUMLAH_PERUBAHAN, JUMLAH_AKHIR, TIPE_PERUBAHAN, REF, SATUAN)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, 'KOREKSI', ?, ?)";
                $stmt_history = $conn->prepare($insert_history);
                if (!$stmt_history) {
                    throw new Exception('Gagal prepare query insert history: ' . $conn->error);
                }
                $stmt_history->bind_param("ssssiiiss", $id_history, $kd_barang, $kd_lokasi_tujuan, $user_id, $jumlah_awal, $jumlah_perubahan, $jumlah_akhir, $id_detail, $satuan);
                if (!$stmt_history->execute()) {
                    throw new Exception('Gagal insert history: ' . $stmt_history->error);
                }
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

// Query untuk mendapatkan riwayat resupply ke toko ini (dikelompokkan per ID_TRANSFER_BARANG)
$query_riwayat = "SELECT 
    tb.ID_TRANSFER_BARANG,
    tb.WAKTU_PESAN_TRANSFER,
    tb.WAKTU_KIRIM_TRANSFER,
    tb.WAKTU_SELESAI_TRANSFER,
    tb.KD_LOKASI_ASAL,
    ml_asal.NAMA_LOKASI as NAMA_LOKASI_ASAL,
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
LEFT JOIN MASTER_LOKASI ml_asal ON tb.KD_LOKASI_ASAL = ml_asal.KD_LOKASI
WHERE tb.KD_LOKASI_TUJUAN = ?
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
                            <th>Lokasi Asal</th>
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
                                    <td><?php echo htmlspecialchars($row['NAMA_LOKASI_ASAL'] ?: $row['KD_LOKASI_ASAL']); ?></td>
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
                                            <button class="btn-view btn-sm" onclick="lihatSuratJalan('<?php echo htmlspecialchars($row['ID_TRANSFER_BARANG']); ?>')">Lihat Surat Jalan</button>
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
                                    <th>ID Detail Transfer</th>
                                    <th>Kode Barang</th>
                                    <th>Merek</th>
                                    <th>Kategori</th>
                                    <th>Nama Barang</th>
                                    <th>Total Pesan Transfer (dus)</th>
                                    <th>Total Kirim (dus)</th>
                                    <th>Batch</th>
                                    <th>Total Masuk (dus)</th>
                                    <th>Jumlah per Dus</th>
                                    <th>Total Masuk (pieces)</th>
                                    <th>Stock Sekarang (pieces)</th>
                                    <th>Jumlah Stock Akhir (pieces)</th>
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
                        thead.append('<th>ID Detail Transfer</th>');
                        thead.append('<th>Kode Barang</th>');
                        thead.append('<th>Merek</th>');
                        thead.append('<th>Kategori</th>');
                        thead.append('<th>Nama Barang</th>');
                        thead.append('<th>Total Pesan Transfer (dus)</th>');
                        thead.append('<th>Batch</th>');
                        thead.append('<th>Total Kirim (dus)</th>');
                        if (isDikirim) {
                            thead.append('<th>Kirim Semua</th>');
                        }
                        if (!isDikirim) {
                            thead.append('<th>Total Masuk (dus)</th>');
                            thead.append('<th>Jumlah per Dus</th>');
                            thead.append('<th>Total Masuk (pieces)</th>');
                            thead.append('<th>Stock Sekarang (pieces)</th>');
                            thead.append('<th>Jumlah Stock Akhir (pieces)</th>');
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
                var colCount = isDikirim ? 9 : 13;
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
                            batchHtml += '<span class="text-muted">Kirim: ' + numberFormat(batch.jumlah_kirim_dus) + ' dus</span><br>' +
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
                    row += '<td class="total-masuk-dus">' + numberFormat(item.jumlah_masuk_dus) + '</td>' +
                        '<td>' + numberFormat(item.satuan_perdus) + '</td>' +
                        '<td class="total-masuk-pieces">' + numberFormat(item.jumlah_masuk_dus * item.satuan_perdus) + '</td>' +
                        '<td>' + numberFormat(item.stock_sekarang) + '</td>' +
                        '<td class="jumlah-stock-akhir">' + numberFormat(jumlahStockAkhir) + '</td>';
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
            
            // Event listener untuk input jumlah tiba per batch
            $(document).off('input', '.batch-diterima-dus').on('input', '.batch-diterima-dus', function() {
                var $input = $(this);
                var index = $input.data('index');
                var idBatch = $input.data('id-batch');
                var jumlahTiba = parseInt($input.val()) || 0;
                var jumlahKirim = parseInt($input.data('jumlah-kirim')) || 0;
                
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
                
                calculateKoreksiTransfer(index, isDikirim);
            });
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
                row.find('.total-masuk-dus').text(numberFormat(totalMasukDus));
                row.find('.total-masuk-pieces').text(numberFormat(totalMasukPieces));
                
                // Hitung stock akhir
                var stockSekarangIndex = 11;
                var stockSekarang = parseInt(row.find('td').eq(stockSekarangIndex).text().replace(/\./g, '')) || 0;
                
                // Hitung jumlah stock akhir
                // Stock akhir = stock sekarang + (total masuk baru - total masuk lama)
                // Simpan nilai lama saat pertama kali render
                if (!row.find('.total-masuk-dus').data('old-value')) {
                    var oldValueText = row.find('.total-masuk-dus').text().replace(/\./g, '');
                    row.find('.total-masuk-dus').data('old-value', oldValueText);
                }
                var oldTotalMasukDus = parseInt(row.find('.total-masuk-dus').data('old-value')) || 0;
                var oldTotalMasukPieces = oldTotalMasukDus * satuanPerdus;
                var selisihPieces = totalMasukPieces - oldTotalMasukPieces;
                var jumlahStockAkhir = stockSekarang + selisihPieces;
                
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
                $('.batch-diterima-dus').each(function() {
                    var $input = $(this);
                    var idDetail = $input.data('id-detail');
                    var idBatch = $input.data('id-batch');
                    var jumlahDiterimaDus = parseInt($input.val()) || 0;
                    var jumlahDitolakDus = parseInt($('.batch-ditolak-dus[data-id-batch="' + idBatch + '"]').val()) || 0;
                    
                    batchKoreksi.push({
                        id_detail_transfer: idDetail,
                        id_detail_transfer_batch: idBatch,
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
            $('#tbodyKoreksiTransfer').html('<tr><td colspan="13" class="text-center text-muted">Memuat data...</td></tr>');
        });
    </script>
</body>
</html>

