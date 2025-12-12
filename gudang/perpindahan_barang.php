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

// Handle AJAX request untuk get data transfer (untuk form validasi)
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['get_transfer_data'])) {
    header('Content-Type: application/json');
    
    $id_transfer = isset($_GET['id_transfer']) ? trim($_GET['id_transfer']) : '';
    
    if (empty($id_transfer)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Get data transfer lengkap dengan batch
    $query_transfer_data = "SELECT 
        tb.ID_TRANSFER_BARANG,
        ml_tujuan.KD_LOKASI as KD_LOKASI_TUJUAN,
        ml_tujuan.NAMA_LOKASI as NAMA_LOKASI_TUJUAN,
        ml_tujuan.ALAMAT_LOKASI as ALAMAT_LOKASI_TUJUAN,
        dtb.ID_DETAIL_TRANSFER_BARANG,
        dtb.KD_BARANG,
        dtb.TOTAL_PESAN_TRANSFER_DUS,
        mb.NAMA_BARANG,
        mb.BERAT,
        COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
        COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
        dtbb.ID_DETAIL_TRANSFER_BARANG_BATCH,
        dtbb.ID_PESAN_BARANG,
        dtbb.JUMLAH_PESAN_TRANSFER_BATCH_DUS as JUMLAH_DUS_BATCH,
        pb.TGL_EXPIRED,
        pb.SISA_STOCK_DUS
    FROM TRANSFER_BARANG tb
    INNER JOIN DETAIL_TRANSFER_BARANG dtb ON tb.ID_TRANSFER_BARANG = dtb.ID_TRANSFER_BARANG
    INNER JOIN MASTER_BARANG mb ON dtb.KD_BARANG = mb.KD_BARANG
    LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
    LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
    LEFT JOIN MASTER_LOKASI ml_tujuan ON tb.KD_LOKASI_TUJUAN = ml_tujuan.KD_LOKASI
    LEFT JOIN DETAIL_TRANSFER_BARANG_BATCH dtbb ON dtb.ID_DETAIL_TRANSFER_BARANG = dtbb.ID_DETAIL_TRANSFER_BARANG
    LEFT JOIN PESAN_BARANG pb ON dtbb.ID_PESAN_BARANG = pb.ID_PESAN_BARANG
    WHERE tb.ID_TRANSFER_BARANG = ? AND tb.KD_LOKASI_ASAL = ? AND tb.STATUS = 'DIPESAN' AND dtb.STATUS = 'DIPESAN'
    ORDER BY dtb.ID_DETAIL_TRANSFER_BARANG ASC, pb.TGL_EXPIRED ASC";
    
    $stmt_transfer_data = $conn->prepare($query_transfer_data);
    if (!$stmt_transfer_data) {
        echo json_encode(['success' => false, 'message' => 'Gagal prepare query: ' . $conn->error]);
        exit();
    }
    $stmt_transfer_data->bind_param("ss", $id_transfer, $kd_lokasi);
    if (!$stmt_transfer_data->execute()) {
        echo json_encode(['success' => false, 'message' => 'Gagal execute query: ' . $stmt_transfer_data->error]);
        exit();
    }
    $result_transfer_data = $stmt_transfer_data->get_result();
    
    if ($result_transfer_data->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Data transfer tidak ditemukan atau sudah divalidasi!']);
        exit();
    }
    
    $transfer_info = null;
    $detail_data = [];
    $detail_map = []; // Untuk mengelompokkan batch per detail
    
    while ($row = $result_transfer_data->fetch_assoc()) {
        if ($transfer_info === null) {
            $transfer_info = [
                'id_transfer' => $row['ID_TRANSFER_BARANG'],
                'kd_lokasi_tujuan' => $row['KD_LOKASI_TUJUAN'],
                'nama_lokasi_tujuan' => $row['NAMA_LOKASI_TUJUAN'] ?? '',
                'alamat_lokasi_tujuan' => $row['ALAMAT_LOKASI_TUJUAN'] ?? ''
            ];
        }
        
        $id_detail = $row['ID_DETAIL_TRANSFER_BARANG'];
        
        // Jika detail belum ada, buat entry baru
        if (!isset($detail_map[$id_detail])) {
            $detail_map[$id_detail] = [
                'id_detail_transfer' => $id_detail,
                'kd_barang' => $row['KD_BARANG'],
                'nama_barang' => $row['NAMA_BARANG'],
                'nama_merek' => $row['NAMA_MEREK'],
                'nama_kategori' => $row['NAMA_KATEGORI'],
                'berat' => $row['BERAT'],
                'jumlah_pesan_dus' => $row['TOTAL_PESAN_TRANSFER_DUS'],
                'batches' => []
            ];
        }
        
        // Tambahkan batch jika ada
        if (!empty($row['ID_DETAIL_TRANSFER_BARANG_BATCH']) && !empty($row['ID_PESAN_BARANG'])) {
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
            
            $detail_map[$id_detail]['batches'][] = [
                'id_detail_transfer_batch' => $row['ID_DETAIL_TRANSFER_BARANG_BATCH'],
                'id_pesan_barang' => $row['ID_PESAN_BARANG'],
                'jumlah_dus' => intval($row['JUMLAH_DUS_BATCH'] ?? 0),
                'tgl_expired' => $row['TGL_EXPIRED'],
                'tgl_expired_display' => $tgl_expired_display,
                'sisa_stock_dus' => intval($row['SISA_STOCK_DUS'] ?? 0)
            ];
        }
    }
    
    // Convert map ke array
    $detail_data = array_values($detail_map);
    
    echo json_encode([
        'success' => true,
        'transfer_info' => $transfer_info,
        'detail_data' => $detail_data
    ]);
    exit();
}

// Handle AJAX request untuk validasi kirim
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'validasi_kirim') {
    header('Content-Type: application/json');
    
    $id_transfer = isset($_POST['id_transfer']) ? trim($_POST['id_transfer']) : '';
    $batch_kirim = isset($_POST['batch_kirim']) ? $_POST['batch_kirim'] : [];
    
    if (empty($id_transfer) || !is_array($batch_kirim)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Boleh kosong karena bisa saja semua batch 0 (tidak dikirim)
    
    // Mulai transaksi
    $conn->begin_transaction();
    
    try {
        require_once '../includes/uuid_generator.php';
        
        // Tentukan status transfer berdasarkan apakah ada detail yang dikirim
        // Status transfer akan diupdate setelah loop detail selesai
        
        // Get semua detail transfer untuk transfer ini (untuk mengecek yang tidak dikirim)
        $query_all_details = "SELECT ID_DETAIL_TRANSFER_BARANG FROM DETAIL_TRANSFER_BARANG 
                             WHERE ID_TRANSFER_BARANG = ? AND STATUS = 'DIPESAN'";
        $stmt_all_details = $conn->prepare($query_all_details);
        if (!$stmt_all_details) {
            throw new Exception('Gagal prepare query all details: ' . $conn->error);
        }
        $stmt_all_details->bind_param("s", $id_transfer);
        $stmt_all_details->execute();
        $result_all_details = $stmt_all_details->get_result();
        
        // Kumpulkan semua ID detail yang ada
        $all_detail_ids = [];
        while ($row = $result_all_details->fetch_assoc()) {
            $all_detail_ids[] = $row['ID_DETAIL_TRANSFER_BARANG'];
        }
        
        // Kelompokkan batch per detail transfer
        $detail_batch_map = [];
        foreach ($batch_kirim as $batch) {
            $id_detail = $batch['id_detail_transfer'] ?? '';
            if (empty($id_detail)) {
                continue;
            }
            
            if (!isset($detail_batch_map[$id_detail])) {
                $detail_batch_map[$id_detail] = [];
            }
            
            $detail_batch_map[$id_detail][] = $batch;
        }
        
        // Kumpulkan ID detail yang dikirim (total > 0)
        $detail_kirim_ids = [];
        $total_detail_kirim = 0;
        
        // Update setiap detail transfer berdasarkan total batch
        foreach ($detail_batch_map as $id_detail => $batches) {
            // Hitung total kirim dari semua batch untuk detail ini
            $total_kirim_dus = 0;
            foreach ($batches as $batch) {
                $total_kirim_dus += intval($batch['jumlah_kirim_dus'] ?? 0);
            }
            
            // Get kd_barang dari detail transfer
            $query_detail_info = "SELECT KD_BARANG FROM DETAIL_TRANSFER_BARANG WHERE ID_DETAIL_TRANSFER_BARANG = ?";
            $stmt_detail_info = $conn->prepare($query_detail_info);
            if (!$stmt_detail_info) {
                throw new Exception('Gagal prepare query detail info: ' . $conn->error);
            }
            $stmt_detail_info->bind_param("s", $id_detail);
            $stmt_detail_info->execute();
            $result_detail_info = $stmt_detail_info->get_result();
            if ($result_detail_info->num_rows == 0) {
                continue; // Skip jika detail tidak ditemukan
            }
            $detail_info = $result_detail_info->fetch_assoc();
            $kd_barang = $detail_info['KD_BARANG'];
            
            // Update batch terlebih dahulu
            foreach ($batches as $batch) {
                $id_pesan_barang = $batch['id_pesan_barang'] ?? '';
                $id_batch = $batch['id_detail_transfer_batch'] ?? '';
                $jumlah_kirim_batch = intval($batch['jumlah_kirim_dus'] ?? 0);
                
                if (empty($id_pesan_barang) || empty($id_batch)) {
                    continue;
                }
                
                // Update JUMLAH_KIRIM_DUS di DETAIL_TRANSFER_BARANG_BATCH
                $update_batch = "UPDATE DETAIL_TRANSFER_BARANG_BATCH 
                                 SET JUMLAH_KIRIM_DUS = ?
                                 WHERE ID_DETAIL_TRANSFER_BARANG_BATCH = ? AND ID_DETAIL_TRANSFER_BARANG = ? AND ID_PESAN_BARANG = ?";
                $stmt_batch = $conn->prepare($update_batch);
                if (!$stmt_batch) {
                    throw new Exception('Gagal prepare query update batch: ' . $conn->error);
                }
                $stmt_batch->bind_param("isss", $jumlah_kirim_batch, $id_batch, $id_detail, $id_pesan_barang);
                if (!$stmt_batch->execute()) {
                    throw new Exception('Gagal update batch: ' . $stmt_batch->error);
                }
                
                // Kurangi SISA_STOCK_DUS di PESAN_BARANG langsung dari jumlah batch
                if ($jumlah_kirim_batch > 0) {
                    // Get SISA_STOCK_DUS saat ini (sebelum dikurangi)
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
                        
                        // Kurangi SISA_STOCK_DUS
                        $sisa_stock_dus_akhir = max(0, $sisa_stock_dus_awal - $jumlah_kirim_batch); // Pastikan tidak negatif
                        $update_sisa = "UPDATE PESAN_BARANG SET SISA_STOCK_DUS = ? WHERE ID_PESAN_BARANG = ?";
                        $stmt_update_sisa = $conn->prepare($update_sisa);
                        if (!$stmt_update_sisa) {
                            throw new Exception('Gagal prepare query update sisa stock: ' . $conn->error);
                        }
                        $stmt_update_sisa->bind_param("is", $sisa_stock_dus_akhir, $id_pesan_barang);
                        if (!$stmt_update_sisa->execute()) {
                            throw new Exception('Gagal update SISA_STOCK_DUS: ' . $stmt_update_sisa->error);
                        }
                    }
                }
            }
            
            // Update STOCK di gudang (lokasi asal) - kurangi stock untuk total keseluruhan
            // Hanya update jika total kirim > 0
            if ($total_kirim_dus > 0) {
                // Get SATUAN dari STOCK
                $query_satuan = "SELECT SATUAN, JUMLAH_BARANG FROM STOCK WHERE KD_BARANG = ? AND KD_LOKASI = ?";
                $stmt_satuan = $conn->prepare($query_satuan);
                if (!$stmt_satuan) {
                    throw new Exception('Gagal prepare query satuan: ' . $conn->error);
                }
                $stmt_satuan->bind_param("ss", $kd_barang, $kd_lokasi);
                $stmt_satuan->execute();
                $result_satuan = $stmt_satuan->get_result();
                
                if ($result_satuan->num_rows > 0) {
                    $stock_data = $result_satuan->fetch_assoc();
                    $satuan = $stock_data['SATUAN'];
                    $jumlah_awal = $stock_data['JUMLAH_BARANG'];
                    
                    // Konversi jumlah kirim dari DUS ke PIECES jika perlu
                    $jumlah_kirim_pieces = $total_kirim_dus;
                    if ($satuan == 'PIECES') {
                        // Get SATUAN_PERDUS dari MASTER_BARANG
                        $query_satuan_perdus = "SELECT SATUAN_PERDUS FROM MASTER_BARANG WHERE KD_BARANG = ?";
                        $stmt_satuan_perdus = $conn->prepare($query_satuan_perdus);
                        $stmt_satuan_perdus->bind_param("s", $kd_barang);
                        $stmt_satuan_perdus->execute();
                        $result_satuan_perdus = $stmt_satuan_perdus->get_result();
                        if ($result_satuan_perdus->num_rows > 0) {
                            $satuan_perdus = $result_satuan_perdus->fetch_assoc()['SATUAN_PERDUS'];
                            $jumlah_kirim_pieces = $total_kirim_dus * $satuan_perdus;
                        }
                    }
                    
                    // Update STOCK di gudang (lokasi asal) - kurangi stock
                    $jumlah_akhir = $jumlah_awal - $jumlah_kirim_pieces;
                    if ($jumlah_akhir < 0) {
                        throw new Exception('Stock tidak mencukupi untuk barang: ' . $kd_barang);
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
                    $stmt_update_stock->bind_param("isss", $jumlah_akhir, $user_id, $kd_barang, $kd_lokasi);
                    if (!$stmt_update_stock->execute()) {
                        throw new Exception('Gagal mengupdate stock: ' . $stmt_update_stock->error);
                    }
                    
                    // Insert ke STOCK_HISTORY dengan REF = ID_TRANSFER_BARANG
                    $id_history = '';
                    do {
                        // Generate ID_HISTORY_STOCK dengan format SKHY+UUID (total 16 karakter: SKHY=4, UUID=12)
                        $uuid = ShortIdGenerator::generate(12, '');
                        $id_history = 'SKHY' . $uuid;
                    } while (checkUUIDExists($conn, 'STOCK_HISTORY', 'ID_HISTORY_STOCK', $id_history));
                    
                    $jumlah_perubahan = -$jumlah_kirim_pieces; // Negatif karena mengurangi stock
                    $insert_history = "INSERT INTO STOCK_HISTORY 
                                      (ID_HISTORY_STOCK, KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_AWAL, JUMLAH_PERUBAHAN, JUMLAH_AKHIR, TIPE_PERUBAHAN, REF, SATUAN)
                                      VALUES (?, ?, ?, ?, ?, ?, ?, 'TRANSFER', ?, ?)";
                    $stmt_history = $conn->prepare($insert_history);
                    if (!$stmt_history) {
                        throw new Exception('Gagal prepare query insert history: ' . $conn->error);
                    }
                    $stmt_history->bind_param("ssssiiiss", $id_history, $kd_barang, $kd_lokasi, $user_id, $jumlah_awal, $jumlah_perubahan, $jumlah_akhir, $id_transfer, $satuan);
                    if (!$stmt_history->execute()) {
                        throw new Exception('Gagal insert history: ' . $stmt_history->error);
                    }
                }
            }
            
            // Update TOTAL_KIRIM_DUS dan STATUS di DETAIL_TRANSFER_BARANG
            // Jika total kirim = 0, ubah status menjadi TIDAK_DIKIRIM
            if ($total_kirim_dus <= 0) {
                $update_detail_tidak_kirim = "UPDATE DETAIL_TRANSFER_BARANG 
                                             SET TOTAL_KIRIM_DUS = 0, STATUS = 'TIDAK_DIKIRIM'
                                             WHERE ID_DETAIL_TRANSFER_BARANG = ? AND ID_TRANSFER_BARANG = ? AND STATUS = 'DIPESAN'";
                $stmt_detail_tidak_kirim = $conn->prepare($update_detail_tidak_kirim);
                if (!$stmt_detail_tidak_kirim) {
                    throw new Exception('Gagal prepare query detail tidak kirim: ' . $conn->error);
                }
                $stmt_detail_tidak_kirim->bind_param("ss", $id_detail, $id_transfer);
                if (!$stmt_detail_tidak_kirim->execute()) {
                    throw new Exception('Gagal update detail transfer tidak kirim: ' . $stmt_detail_tidak_kirim->error);
                }
                
                // Update semua batch terkait menjadi JUMLAH_KIRIM_DUS = 0
                $update_batch_tidak_kirim = "UPDATE DETAIL_TRANSFER_BARANG_BATCH 
                                             SET JUMLAH_KIRIM_DUS = 0
                                             WHERE ID_DETAIL_TRANSFER_BARANG = ?";
                $stmt_batch_tidak_kirim = $conn->prepare($update_batch_tidak_kirim);
                if (!$stmt_batch_tidak_kirim) {
                    throw new Exception('Gagal prepare query batch tidak kirim: ' . $conn->error);
                }
                $stmt_batch_tidak_kirim->bind_param("s", $id_detail);
                if (!$stmt_batch_tidak_kirim->execute()) {
                    throw new Exception('Gagal update batch tidak kirim: ' . $stmt_batch_tidak_kirim->error);
                }
            } else {
                // Jika total kirim > 0, update detail transfer: set TOTAL_KIRIM_DUS dan STATUS = 'DIKIRIM'
                $update_detail = "UPDATE DETAIL_TRANSFER_BARANG 
                                 SET TOTAL_KIRIM_DUS = ?, STATUS = 'DIKIRIM'
                                 WHERE ID_DETAIL_TRANSFER_BARANG = ? AND ID_TRANSFER_BARANG = ? AND STATUS = 'DIPESAN'";
                $stmt_detail = $conn->prepare($update_detail);
                if (!$stmt_detail) {
                    throw new Exception('Gagal prepare query detail: ' . $conn->error);
                }
                $stmt_detail->bind_param("iss", $total_kirim_dus, $id_detail, $id_transfer);
                if (!$stmt_detail->execute()) {
                    throw new Exception('Gagal update detail transfer: ' . $stmt_detail->error);
                }
                
                $detail_kirim_ids[] = $id_detail;
                $total_detail_kirim++;
            }
        }
        
        // Kumpulkan ID detail yang sudah diproses (dari detail_batch_map)
        $detail_processed_ids = array_keys($detail_batch_map);
        
        // Update status detail yang tidak ada di $detail_kirim (tidak diproses sama sekali)
        foreach ($all_detail_ids as $detail_id) {
            if (!in_array($detail_id, $detail_processed_ids)) {
                // Detail ini tidak ada di request, ubah menjadi TIDAK_DIKIRIM
                $update_detail_tidak_kirim = "UPDATE DETAIL_TRANSFER_BARANG 
                                             SET TOTAL_KIRIM_DUS = 0, STATUS = 'TIDAK_DIKIRIM'
                                             WHERE ID_DETAIL_TRANSFER_BARANG = ? AND ID_TRANSFER_BARANG = ? AND STATUS = 'DIPESAN'";
                $stmt_detail_tidak_kirim = $conn->prepare($update_detail_tidak_kirim);
                if (!$stmt_detail_tidak_kirim) {
                    throw new Exception('Gagal prepare query detail tidak kirim: ' . $conn->error);
                }
                $stmt_detail_tidak_kirim->bind_param("ss", $detail_id, $id_transfer);
                if (!$stmt_detail_tidak_kirim->execute()) {
                    throw new Exception('Gagal update detail transfer tidak kirim: ' . $stmt_detail_tidak_kirim->error);
                }
                
                // Update semua batch terkait menjadi JUMLAH_KIRIM_DUS = 0
                $update_batch_tidak_kirim = "UPDATE DETAIL_TRANSFER_BARANG_BATCH 
                                             SET JUMLAH_KIRIM_DUS = 0
                                             WHERE ID_DETAIL_TRANSFER_BARANG = ?";
                $stmt_batch_tidak_kirim = $conn->prepare($update_batch_tidak_kirim);
                if (!$stmt_batch_tidak_kirim) {
                    throw new Exception('Gagal prepare query batch tidak kirim: ' . $conn->error);
                }
                $stmt_batch_tidak_kirim->bind_param("s", $detail_id);
                if (!$stmt_batch_tidak_kirim->execute()) {
                    throw new Exception('Gagal update batch tidak kirim: ' . $stmt_batch_tidak_kirim->error);
                }
            }
        }
        
        // Update status transfer berdasarkan apakah ada detail yang dikirim
        if ($total_detail_kirim > 0) {
            // Ada yang dikirim, status = DIKIRIM
            $update_transfer = "UPDATE TRANSFER_BARANG 
                               SET STATUS = 'DIKIRIM', 
                                   WAKTU_KIRIM_TRANSFER = CURRENT_TIMESTAMP,
                                   ID_USERS_PENGIRIM = ?
                               WHERE ID_TRANSFER_BARANG = ? AND KD_LOKASI_ASAL = ? AND STATUS = 'DIPESAN'";
            $stmt_transfer = $conn->prepare($update_transfer);
            if (!$stmt_transfer) {
                throw new Exception('Gagal prepare query transfer: ' . $conn->error);
            }
            $stmt_transfer->bind_param("sss", $user_id, $id_transfer, $kd_lokasi);
            if (!$stmt_transfer->execute()) {
                throw new Exception('Gagal update transfer: ' . $stmt_transfer->error);
            }
            $message = 'Transfer berhasil divalidasi dan dikirim!';
        } else {
            // Semua tidak dikirim, tetap status DIPESAN (tidak diubah ke DIBATALKAN karena itu untuk pemilik)
            // Transfer tetap DIPESAN, hanya detail yang statusnya TIDAK_DIKIRIM
            $message = 'Validasi selesai. Semua barang tidak dikirim.';
        }
        
        // Commit transaksi
        $conn->commit();
        echo json_encode(['success' => true, 'message' => $message]);
    } catch (Exception $e) {
        // Rollback transaksi
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit();
}

// Query untuk mendapatkan data perpindahan barang (transfer dari gudang ini)
$query_transfer = "SELECT 
    tb.ID_TRANSFER_BARANG,
    tb.WAKTU_PESAN_TRANSFER,
    tb.WAKTU_KIRIM_TRANSFER,
    tb.WAKTU_SELESAI_TRANSFER,
    tb.STATUS,
    tb.KD_LOKASI_TUJUAN,
    ml_tujuan.NAMA_LOKASI as NAMA_LOKASI_TUJUAN,
    ml_tujuan.ALAMAT_LOKASI as ALAMAT_LOKASI_TUJUAN,
    COALESCE(SUM(dtb.TOTAL_PESAN_TRANSFER_DUS), 0) as TOTAL_DIPESAN_DUS,
    COUNT(CASE WHEN dtb.STATUS = 'DIPESAN' THEN 1 END) as JUMLAH_DETAIL_DIPESAN,
    COUNT(CASE WHEN dtb.STATUS = 'TIDAK_DIKIRIM' THEN 1 END) as JUMLAH_DETAIL_TIDAK_DIKIRIM,
    COUNT(dtb.ID_DETAIL_TRANSFER_BARANG) as TOTAL_DETAIL
FROM TRANSFER_BARANG tb
LEFT JOIN MASTER_LOKASI ml_tujuan ON tb.KD_LOKASI_TUJUAN = ml_tujuan.KD_LOKASI
LEFT JOIN DETAIL_TRANSFER_BARANG dtb ON tb.ID_TRANSFER_BARANG = dtb.ID_TRANSFER_BARANG
WHERE tb.KD_LOKASI_ASAL = ? AND tb.STATUS IN ('DIPESAN', 'DIKIRIM', 'SELESAI')
GROUP BY tb.ID_TRANSFER_BARANG, tb.WAKTU_PESAN_TRANSFER, tb.WAKTU_KIRIM_TRANSFER, tb.WAKTU_SELESAI_TRANSFER, tb.STATUS, tb.KD_LOKASI_TUJUAN, ml_tujuan.NAMA_LOKASI, ml_tujuan.ALAMAT_LOKASI
ORDER BY 
    CASE tb.STATUS
        WHEN 'DIPESAN' THEN 1
        WHEN 'DIKIRIM' THEN 2
        WHEN 'SELESAI' THEN 3
        ELSE 4
    END,
    CASE tb.STATUS
        WHEN 'DIPESAN' THEN tb.WAKTU_PESAN_TRANSFER
        WHEN 'DIKIRIM' THEN tb.WAKTU_KIRIM_TRANSFER
        ELSE NULL
    END ASC,
    CASE tb.STATUS
        WHEN 'SELESAI' THEN tb.WAKTU_SELESAI_TRANSFER
        ELSE NULL
    END DESC";

$stmt_transfer = $conn->prepare($query_transfer);
$stmt_transfer->bind_param("s", $kd_lokasi);
$stmt_transfer->execute();
$result_transfer = $stmt_transfer->get_result();

// Format waktu stack (dd/mm/yyyy HH:ii WIB)
function formatWaktuStack($waktu_pesan, $waktu_kirim, $waktu_selesai, $status) {
    $html = '<div class="d-flex flex-column gap-1">';
    
    // Waktu diterima (jika status SELESAI) - tampilkan di atas
    if (!empty($waktu_selesai) && $status == 'SELESAI') {
        $date_sampai = new DateTime($waktu_selesai);
        $waktu_sampai_formatted = $date_sampai->format('d/m/Y H:i') . ' WIB';
        $html .= '<div>';
        $html .= htmlspecialchars($waktu_sampai_formatted) . ' ';
        $html .= '<span class="badge bg-success">DITERIMA</span>';
        $html .= '</div>';
    }
    
    // Waktu Dikirim (jika ada)
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
$active_page = 'perpindahan_barang';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gudang - Perpindahan Barang</title>
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
            <h1 class="page-title">Gudang <?php echo htmlspecialchars($nama_lokasi); ?> - Perpindahan Barang</h1>
            <?php if (!empty($alamat_lokasi)): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($alamat_lokasi); ?></p>
            <?php endif; ?>
        </div>

        <!-- Table Perpindahan Barang -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tablePerpindahan" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID Transfer</th>
                            <th>Waktu</th>
                            <th>Lokasi Tujuan</th>
                            <th>Total Pesan Transfer (dus)</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_transfer && $result_transfer->num_rows > 0): ?>
                            <?php while ($row = $result_transfer->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['ID_TRANSFER_BARANG']); ?></td>
                                    <td data-order="<?php 
                                        $waktu_order = '';
                                        switch($row['STATUS']) {
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
                                    ?>"><?php echo formatWaktuStack($row['WAKTU_PESAN_TRANSFER'], $row['WAKTU_KIRIM_TRANSFER'], $row['WAKTU_SELESAI_TRANSFER'], $row['STATUS']); ?></td>
                                    <td>
                                        <?php 
                                        if (!empty($row['NAMA_LOKASI_TUJUAN'])) {
                                            $lokasi_text = $row['KD_LOKASI_TUJUAN'] . '-' . $row['NAMA_LOKASI_TUJUAN'];
                                            if (!empty($row['ALAMAT_LOKASI_TUJUAN'])) {
                                                $lokasi_text .= '-' . $row['ALAMAT_LOKASI_TUJUAN'];
                                            }
                                            echo htmlspecialchars($lokasi_text);
                                        } else {
                                            echo htmlspecialchars($row['KD_LOKASI_TUJUAN']);
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo number_format($row['TOTAL_DIPESAN_DUS'], 0, ',', '.'); ?></td>
                                    <td data-order="<?php 
                                        $status_text = '';
                                        $status_class = '';
                                        $status_order = 0;
                                        
                                        // Cek apakah semua detail sudah TIDAK_DIKIRIM
                                        $total_detail = intval($row['TOTAL_DETAIL'] ?? 0);
                                        $jumlah_detail_tidak_dikirim = intval($row['JUMLAH_DETAIL_TIDAK_DIKIRIM'] ?? 0);
                                        $status_transfer = $row['STATUS'];
                                        
                                        // Jika status transfer = DIPESAN dan semua detail sudah TIDAK_DIKIRIM, tampilkan sebagai TIDAK_DIKIRIM
                                        if ($status_transfer == 'DIPESAN' && $total_detail > 0 && $jumlah_detail_tidak_dikirim == $total_detail) {
                                            $status_text = 'Tidak Dikirim';
                                            $status_class = 'secondary';
                                            $status_order = 4;
                                        } else {
                                            // Gunakan status transfer asli
                                            switch($status_transfer) {
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
                                                case 'DIBATALKAN':
                                                    $status_text = 'Dibatalkan';
                                                    $status_class = 'danger';
                                                    $status_order = 4;
                                                    break;
                                                case 'TIDAK_DIKIRIM':
                                                    $status_text = 'Tidak Dikirim';
                                                    $status_class = 'secondary';
                                                    $status_order = 4;
                                                    break;
                                                default:
                                                    $status_text = $status_transfer;
                                                    $status_class = 'secondary';
                                                    $status_order = 4;
                                            }
                                        }
                                        echo $status_order;
                                    ?>">
                                        <span class="badge bg-<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <button class="btn-view btn-sm" onclick="lihatSuratJalan('<?php echo htmlspecialchars($row['ID_TRANSFER_BARANG']); ?>')">Surat Jalan</button>
                                            <?php 
                                            // Tombol Validasi Kirim hanya muncul jika:
                                            // 1. Status transfer = DIPESAN
                                            // 2. Masih ada detail dengan status DIPESAN (bisa divalidasi)
                                            $bisa_validasi = ($row['STATUS'] == 'DIPESAN' && intval($row['JUMLAH_DETAIL_DIPESAN'] ?? 0) > 0);
                                            if ($bisa_validasi): 
                                            ?>
                                                <button class="btn btn-success btn-sm" onclick="validasiKirim('<?php echo htmlspecialchars($row['ID_TRANSFER_BARANG']); ?>')">Validasi Kirim</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">Tidak ada data perpindahan barang</td>
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

    <!-- Modal Validasi Kirim -->
    <div class="modal fade" id="modalValidasiKirim" tabindex="-1" aria-labelledby="modalValidasiKirimLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalValidasiKirimLabel">Validasi Kirim Barang</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Info Tujuan -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">ID Transfer/Surat Jalan</label>
                            <input type="text" class="form-control" id="validasi_id_transfer" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Kode Tujuan</label>
                            <input type="text" class="form-control" id="validasi_kd_tujuan" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Nama Tujuan</label>
                            <input type="text" class="form-control" id="validasi_nama_tujuan" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Alamat Tujuan</label>
                            <input type="text" class="form-control" id="validasi_alamat_tujuan" readonly style="background-color: #e9ecef;">
                        </div>
                    </div>
                    
                    <!-- Tabel Detail Barang -->
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover" id="tableValidasiKirim">
                            <thead>
                                <tr>
                                    <th>ID Detail Transfer</th>
                                    <th>Kode Barang</th>
                                    <th>Merek</th>
                                    <th>Kategori</th>
                                    <th>Nama Barang</th>
                                    <th>Berat (gr)</th>
                                    <th>Total Pesan Transfer (dus)</th>
                                    <th>Batch</th>
                                    <th>Total Kirim (dus)</th>
                                    <th>Kirim Semua</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyValidasiKirim">
                                <tr>
                                    <td colspan="10" class="text-center text-muted">Memuat data...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" onclick="simpanValidasiKirim()">Simpan</button>
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
            
            $('#tablePerpindahan').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data perpindahan barang'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[4, 'asc'], [1, 'asc']], // Sort by Status (priority) then Waktu
                columnDefs: [
                    { orderable: false, targets: [5] }, // Disable sorting on Action column
                    { type: 'num', targets: [4, 1] } // Status and Waktu columns use numeric sorting
                ],
                scrollX: true,
                autoWidth: false
            }).on('error.dt', function(e, settings, techNote, message) {
                console.log('DataTables error suppressed:', message);
                return false;
            });
        });

        function lihatSuratJalan(idTransfer) {
            // Set iframe source ke download_surat_jalan.php (di folder pemilik, bisa diakses semua user)
            $('#suratJalanIframe').attr('src', '../pemilik/download_surat_jalan.php?id_transfer=' + encodeURIComponent(idTransfer));
            
            // Buka modal
            var modal = new bootstrap.Modal(document.getElementById('modalLihatSuratJalan'));
            modal.show();
        }

        function validasiKirim(idTransfer) {
            // Load data transfer
            $.ajax({
                url: '',
                method: 'GET',
                data: {
                    get_transfer_data: '1',
                    id_transfer: idTransfer
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.transfer_info && response.detail_data.length > 0) {
                        // Set info tujuan
                        $('#validasi_kd_tujuan').val(response.transfer_info.kd_lokasi_tujuan);
                        $('#validasi_nama_tujuan').val(response.transfer_info.nama_lokasi_tujuan);
                        $('#validasi_id_transfer').val(response.transfer_info.id_transfer);
                        $('#validasi_alamat_tujuan').val(response.transfer_info.alamat_lokasi_tujuan);
                        
                        // Render tabel
                        renderTabelValidasiKirim(response.detail_data, idTransfer);
                        
                        // Buka modal
                        $('#modalValidasiKirim').modal('show');
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
        
        function renderTabelValidasiKirim(data, idTransfer) {
            var tbody = $('#tbodyValidasiKirim');
            tbody.empty();
            
            if (data.length === 0) {
                tbody.append('<tr><td colspan="10" class="text-center text-muted">Tidak ada data</td></tr>');
                return;
            }
            
            data.forEach(function(item, index) {
                // Render batch info dengan input per batch
                var batchHtml = '';
                if (item.batches && item.batches.length > 0) {
                    batchHtml = '<div class="d-flex flex-column gap-2">';
                    item.batches.forEach(function(batch, batchIndex) {
                        batchHtml += '<div class="small border rounded p-2">' +
                            '<strong>' + escapeHtml(batch.id_pesan_barang) + '</strong><br>' +
                            '<span class="text-muted">Exp: ' + escapeHtml(batch.tgl_expired_display) + '</span><br>' +
                            '<span class="text-muted">Sisa: ' + numberFormat(batch.sisa_stock_dus) + ' dus</span><br>' +
                            '<span class="text-muted">Pesan: ' + numberFormat(batch.jumlah_dus) + ' dus</span><br>' +
                            '<label class="form-label small mb-1">Kirim (dus):</label>' +
                            '<input type="number" class="form-control form-control-sm batch-kirim-dus" ' +
                            'min="0" max="' + Math.min(batch.sisa_stock_dus, batch.jumlah_dus) + '" ' +
                            'value="0" ' +
                            'data-id-detail="' + escapeHtml(item.id_detail_transfer) + '" ' +
                            'data-id-pesan-barang="' + escapeHtml(batch.id_pesan_barang) + '" ' +
                            'data-id-batch="' + escapeHtml(batch.id_detail_transfer_batch) + '" ' +
                            'data-jumlah-pesan="' + batch.jumlah_dus + '" ' +
                            'data-index="' + index + '" ' +
                            'data-batch-index="' + batchIndex + '" ' +
                            'style="width: 100px;">' +
                            '</div>';
                    });
                    batchHtml += '</div>';
                } else {
                    batchHtml = '<span class="text-muted">-</span>';
                }
                
                var row = '<tr data-id-detail="' + escapeHtml(item.id_detail_transfer) + '" data-kd-barang="' + escapeHtml(item.kd_barang) + '">' +
                    '<td>' + escapeHtml(item.id_detail_transfer) + '</td>' +
                    '<td>' + escapeHtml(item.kd_barang) + '</td>' +
                    '<td>' + escapeHtml(item.nama_merek) + '</td>' +
                    '<td>' + escapeHtml(item.nama_kategori) + '</td>' +
                    '<td>' + escapeHtml(item.nama_barang) + '</td>' +
                    '<td>' + numberFormat(item.berat) + '</td>' +
                    '<td>' + numberFormat(item.jumlah_pesan_dus) + '</td>' +
                    '<td style="min-width: 250px;">' + batchHtml + '</td>' +
                    '<td><input type="text" class="form-control form-control-sm total-kirim-dus" value="0" data-index="' + index + '" style="width: 100px; background-color: #e9ecef; cursor: not-allowed;" readonly disabled></td>' +
                    '<td><input type="checkbox" class="form-check-input kirim-semua-barang" data-index="' + index + '" data-id-detail="' + escapeHtml(item.id_detail_transfer) + '"></td>' +
                    '</tr>';
                tbody.append(row);
            });
            
            // Attach event listeners
            attachValidasiKirimEventListeners();
        }
        
        function attachValidasiKirimEventListeners() {
            // Event listener untuk input jumlah kirim per batch
            $(document).off('input change', '.batch-kirim-dus').on('input change', '.batch-kirim-dus', function() {
                var $input = $(this);
                var idDetail = $input.data('id-detail');
                var index = $input.data('index');
                var jumlahKirim = parseInt($input.val()) || 0;
                var maxJumlah = parseInt($input.attr('max')) || 0;
                
                // Validasi tidak boleh melebihi max
                if (jumlahKirim > maxJumlah) {
                    $input.val(maxJumlah);
                    jumlahKirim = maxJumlah;
                }
                
                // Validasi tidak boleh negatif
                if (jumlahKirim < 0) {
                    $input.val(0);
                    jumlahKirim = 0;
                }
                
                // Update total kirim untuk detail transfer ini
                updateTotalKirimDetail(idDetail, index);
                
                // Update checkbox "Kirim Semua" berdasarkan apakah semua batch sudah terisi penuh
                updateCheckboxKirimSemua(idDetail, index);
            });
            
            // Event listener untuk checkbox "Kirim Semua"
            $(document).off('change', '.kirim-semua-barang').on('change', '.kirim-semua-barang', function() {
                var $checkbox = $(this);
                var index = $checkbox.data('index');
                var idDetail = $checkbox.data('id-detail');
                
                if ($checkbox.is(':checked')) {
                    // Isi semua batch dengan jumlah pesan masing-masing
                    $('.batch-kirim-dus[data-id-detail="' + idDetail + '"]').each(function() {
                        var $batchInput = $(this);
                        var jumlahPesan = parseInt($batchInput.data('jumlah-pesan')) || 0;
                        var maxJumlah = parseInt($batchInput.attr('max')) || 0;
                        // Gunakan jumlah pesan atau max, ambil yang lebih kecil
                        var jumlahIsi = Math.min(jumlahPesan, maxJumlah);
                        $batchInput.val(jumlahIsi);
                    });
                    
                    // Update total kirim
                    updateTotalKirimDetail(idDetail, index);
                } else {
                    // Reset semua batch ke 0
                    $('.batch-kirim-dus[data-id-detail="' + idDetail + '"]').each(function() {
                        $(this).val(0);
                    });
                    
                    // Update total kirim
                    updateTotalKirimDetail(idDetail, index);
                }
            });
        }
        
        function updateCheckboxKirimSemua(idDetail, index) {
            var semuaTerisiPenuh = true;
            var adaYangTerisi = false;
            
            $('.batch-kirim-dus[data-id-detail="' + idDetail + '"]').each(function() {
                var $input = $(this);
                var jumlahKirim = parseInt($input.val()) || 0;
                var jumlahPesan = parseInt($input.data('jumlah-pesan')) || 0;
                var maxJumlah = parseInt($input.attr('max')) || 0;
                var jumlahMaksimal = Math.min(jumlahPesan, maxJumlah);
                
                if (jumlahKirim > 0) {
                    adaYangTerisi = true;
                }
                
                if (jumlahKirim < jumlahMaksimal) {
                    semuaTerisiPenuh = false;
                }
            });
            
            // Update checkbox: checked jika semua terisi penuh, unchecked jika tidak
            var $checkbox = $('.kirim-semua-barang[data-id-detail="' + idDetail + '"]');
            if (semuaTerisiPenuh && adaYangTerisi) {
                $checkbox.prop('checked', true);
            } else {
                $checkbox.prop('checked', false);
            }
        }
        
        function updateTotalKirimDetail(idDetail, index) {
            var totalKirim = 0;
            
            // Hitung total dari semua batch untuk detail transfer ini
            $('.batch-kirim-dus[data-id-detail="' + idDetail + '"]').each(function() {
                var jumlah = parseInt($(this).val()) || 0;
                totalKirim += jumlah;
            });
            
            // Update field total kirim (readonly)
            $('.total-kirim-dus[data-index="' + index + '"]').val(totalKirim);
        }
        
        function simpanValidasiKirim() {
            var idTransfer = $('#validasi_id_transfer').val();
            var batchKirim = [];
            var detailMap = {}; // Untuk tracking total per detail
            var detailZero = [];
            var detailTidakPenuh = [];
            
            // Kumpulkan semua data batch dari tabel
            $('.batch-kirim-dus').each(function() {
                var $input = $(this);
                var idDetail = $input.data('id-detail');
                var idPesanBarang = $input.data('id-pesan-barang');
                var idBatch = $input.data('id-batch');
                var jumlahKirimDus = parseInt($input.val()) || 0;
                var jumlahPesanDus = parseInt($input.data('jumlah-pesan')) || 0;
                
                // Kumpulkan data batch
                batchKirim.push({
                    id_detail_transfer: idDetail,
                    id_pesan_barang: idPesanBarang,
                    id_detail_transfer_batch: idBatch,
                    jumlah_kirim_dus: jumlahKirimDus,
                    jumlah_pesan_dus: jumlahPesanDus
                });
                
                // Track total per detail
                if (!detailMap[idDetail]) {
                    var $row = $input.closest('tr[data-id-detail]');
                    detailMap[idDetail] = {
                        id_detail_transfer: idDetail,
                        kd_barang: $row.data('kd-barang'),
                        nama_barang: $row.find('td').eq(4).text(),
                        total_kirim_dus: 0,
                        total_pesan_dus: 0
                    };
                }
                detailMap[idDetail].total_kirim_dus += jumlahKirimDus;
                detailMap[idDetail].total_pesan_dus += jumlahPesanDus;
            });
            
            // Analisis detail untuk konfirmasi
            Object.keys(detailMap).forEach(function(idDetail) {
                var detail = detailMap[idDetail];
                if (detail.total_kirim_dus > 0) {
                    // Cek apakah tidak kirim semua/penuh
                    if (detail.total_kirim_dus < detail.total_pesan_dus) {
                        detailTidakPenuh.push({
                            nama_barang: detail.nama_barang,
                            jumlah_kirim_dus: detail.total_kirim_dus,
                            jumlah_pesan_dus: detail.total_pesan_dus
                        });
                    }
                } else {
                    detailZero.push({
                        nama_barang: detail.nama_barang
                    });
                }
            });
            
            // Jika hanya 1 barang dan jumlah kirim = 0, konfirmasi
            var detailKeys = Object.keys(detailMap);
            if (detailKeys.length === 1 && detailZero.length === 1) {
                Swal.fire({
                    icon: 'question',
                    title: 'Konfirmasi',
                    html: 'Jumlah kirim untuk barang <strong>' + detailZero[0].nama_barang + '</strong> adalah 0. Apakah Anda yakin tidak akan mengirim barang ini?',
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Yakin',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#ffc107',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        konfirmasiFinalKirim(idTransfer, batchKirim);
                    }
                });
                return;
            }
            
            // Jika ada yang jumlah kirim = 0 (dan bukan hanya 1 barang)
            if (detailZero.length > 0 && detailKeys.length > 1) {
                var message = 'Beberapa barang memiliki jumlah kirim 0:\n\n';
                detailZero.forEach(function(item) {
                    message += '- ' + item.nama_barang + '\n';
                });
                message += '\nBarang tersebut akan ditandai sebagai "Tidak Dikirim". Apakah Anda yakin?';
                
                Swal.fire({
                    icon: 'question',
                    title: 'Konfirmasi',
                    html: message.replace(/\n/g, '<br>'),
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Yakin',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#ffc107',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        konfirmasiTidakPenuh(idTransfer, batchKirim, detailTidakPenuh);
                    }
                });
                return;
            }
            
            // Jika tidak ada yang 0, langsung cek tidak penuh
            konfirmasiTidakPenuh(idTransfer, batchKirim, detailTidakPenuh);
        }
        
        function konfirmasiTidakPenuh(idTransfer, batchKirim, detailTidakPenuh) {
            // Jika ada yang tidak kirim semua/penuh
            if (detailTidakPenuh.length > 0) {
                var message = 'Beberapa barang tidak dikirim secara penuh:\n\n';
                detailTidakPenuh.forEach(function(item) {
                    message += '- ' + item.nama_barang + ': Dikirim ' + numberFormat(item.jumlah_kirim_dus) + ' dus dari ' + numberFormat(item.jumlah_pesan_dus) + ' dus\n';
                });
                message += '\nApakah Anda yakin ingin melanjutkan?';
                
                Swal.fire({
                    icon: 'question',
                    title: 'Konfirmasi',
                    html: message.replace(/\n/g, '<br>'),
                    showCancelButton: true,
                    confirmButtonText: 'Ya, Lanjutkan',
                    cancelButtonText: 'Batal',
                    confirmButtonColor: '#ffc107',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        konfirmasiFinalKirim(idTransfer, batchKirim);
                    }
                });
            } else {
                // Semua kirim penuh, langsung konfirmasi final
                konfirmasiFinalKirim(idTransfer, batchKirim);
            }
        }
        
        function konfirmasiFinalKirim(idTransfer, batchKirim) {
            // Konfirmasi final
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi Final',
                text: 'Apakah Anda yakin ingin memvalidasi dan mengirim transfer ini?',
                showCancelButton: true,
                confirmButtonText: 'Ya, Simpan',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#28a745',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Pastikan semua batch (termasuk yang 0) dikumpulkan
                    // batchKirim sudah dikumpulkan sebelumnya di fungsi simpanValidasiKirim
                    // Tapi kita perlu memastikan semua batch ada, termasuk yang tidak diinput (nilai 0)
                    var batchKirimFinal = [];
                    $('.batch-kirim-dus').each(function() {
                        var $input = $(this);
                        var idDetail = $input.data('id-detail');
                        var idPesanBarang = $input.data('id-pesan-barang');
                        var idBatch = $input.data('id-batch');
                        var $row = $input.closest('tr[data-id-detail]');
                        var kdBarang = $row.data('kd-barang');
                        var jumlahKirimDus = parseInt($input.val()) || 0;
                        var jumlahPesanDus = parseInt($input.data('jumlah-pesan')) || 0;
                        
                        batchKirimFinal.push({
                            id_detail_transfer: idDetail,
                            id_pesan_barang: idPesanBarang,
                            id_detail_transfer_batch: idBatch,
                            kd_barang: kdBarang,
                            jumlah_kirim_dus: jumlahKirimDus,
                            jumlah_pesan_dus: jumlahPesanDus
                        });
                    });
                    
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: {
                            action: 'validasi_kirim',
                            id_transfer: idTransfer,
                            batch_kirim: batchKirimFinal
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
                                    $('#modalValidasiKirim').modal('hide');
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
                            var errorMessage = 'Terjadi kesalahan saat memvalidasi transfer!';
                            
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
        $('#modalValidasiKirim').on('hidden.bs.modal', function() {
            $('#tbodyValidasiKirim').html('<tr><td colspan="10" class="text-center text-muted">Memuat data...</td></tr>');
        });
    </script>
</body>
</html>

