<?php
session_start();
require_once '../dbconnect.php';

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
    
    // Get data transfer lengkap
    $query_transfer_data = "SELECT 
        tb.ID_TRANSFER_BARANG,
        tb.KD_LOKASI_ASAL,
        ml_asal.KD_LOKASI as KD_LOKASI_ASAL,
        ml_asal.NAMA_LOKASI as NAMA_LOKASI_ASAL,
        ml_asal.ALAMAT_LOKASI as ALAMAT_LOKASI_ASAL,
        dtb.ID_DETAIL_TRANSFER_BARANG,
        dtb.KD_BARANG,
        dtb.JUMLAH_PESAN_TRANSFER_DUS,
        dtb.JUMLAH_KIRIM_DUS,
        mb.NAMA_BARANG,
        mb.BERAT,
        mb.SATUAN_PERDUS,
        mb.AVG_HARGA_BELI_PIECES,
        COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
        COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
        COALESCE(s.JUMLAH_BARANG, 0) as STOCK_SEKARANG,
        s.SATUAN
    FROM TRANSFER_BARANG tb
    INNER JOIN DETAIL_TRANSFER_BARANG dtb ON tb.ID_TRANSFER_BARANG = dtb.ID_TRANSFER_BARANG
    INNER JOIN MASTER_BARANG mb ON dtb.KD_BARANG = mb.KD_BARANG
    LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
    LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
    LEFT JOIN MASTER_LOKASI ml_asal ON tb.KD_LOKASI_ASAL = ml_asal.KD_LOKASI
    LEFT JOIN STOCK s ON dtb.KD_BARANG = s.KD_BARANG AND s.KD_LOKASI = ?
    WHERE tb.ID_TRANSFER_BARANG = ? AND tb.KD_LOKASI_TUJUAN = ? AND tb.STATUS = 'DIKIRIM' AND dtb.STATUS = 'DIKIRIM'
    ORDER BY dtb.ID_DETAIL_TRANSFER_BARANG ASC";
    
    $stmt_transfer_data = $conn->prepare($query_transfer_data);
    if (!$stmt_transfer_data) {
        echo json_encode(['success' => false, 'message' => 'Gagal prepare query: ' . $conn->error]);
        exit();
    }
    $stmt_transfer_data->bind_param("sss", $kd_lokasi, $id_transfer, $kd_lokasi);
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
    
    while ($row = $result_transfer_data->fetch_assoc()) {
        if ($transfer_info === null) {
            $transfer_info = [
                'id_transfer' => $row['ID_TRANSFER_BARANG'],
                'kd_lokasi_asal' => $row['KD_LOKASI_ASAL'],
                'nama_lokasi_asal' => $row['NAMA_LOKASI_ASAL'] ?? '',
                'alamat_lokasi_asal' => $row['ALAMAT_LOKASI_ASAL'] ?? ''
            ];
        }
        
        $detail_data[] = [
            'id_detail_transfer' => $row['ID_DETAIL_TRANSFER_BARANG'],
            'kd_barang' => $row['KD_BARANG'],
            'nama_barang' => $row['NAMA_BARANG'],
            'nama_merek' => $row['NAMA_MEREK'],
            'nama_kategori' => $row['NAMA_KATEGORI'],
            'berat' => $row['BERAT'],
            'jumlah_pesan_dus' => $row['JUMLAH_PESAN_TRANSFER_DUS'],
            'jumlah_kirim_dus' => $row['JUMLAH_KIRIM_DUS'],
            'satuan_perdus' => $row['SATUAN_PERDUS'] ?? 1,
            'avg_harga_beli' => $row['AVG_HARGA_BELI_PIECES'] ?? 0,
            'stock_sekarang' => intval($row['STOCK_SEKARANG'] ?? 0),
            'satuan' => $row['SATUAN'] ?? 'PIECES'
        ];
    }
    
    echo json_encode([
        'success' => true,
        'transfer_info' => $transfer_info,
        'detail_data' => $detail_data
    ]);
    exit();
}

// Handle AJAX request untuk validasi masuk
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'validasi_masuk') {
    header('Content-Type: application/json');
    
    $id_transfer = isset($_POST['id_transfer']) ? trim($_POST['id_transfer']) : '';
    $detail_masuk = isset($_POST['detail_masuk']) ? $_POST['detail_masuk'] : [];
    
    if (empty($id_transfer) || !is_array($detail_masuk) || empty($detail_masuk)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Mulai transaksi
    $conn->begin_transaction();
    
    try {
        require_once '../includes/uuid_generator.php';
        
        // Update status transfer menjadi SELESAI dan set WAKTU_SELESAI_TRANSFER
        $update_transfer = "UPDATE TRANSFER_BARANG 
                           SET STATUS = 'SELESAI', 
                               WAKTU_SELESAI_TRANSFER = CURRENT_TIMESTAMP,
                               ID_USERS_PENERIMA = ?
                           WHERE ID_TRANSFER_BARANG = ? AND KD_LOKASI_TUJUAN = ? AND STATUS = 'DIKIRIM'";
        $stmt_transfer = $conn->prepare($update_transfer);
        if (!$stmt_transfer) {
            throw new Exception('Gagal prepare query transfer: ' . $conn->error);
        }
        $stmt_transfer->bind_param("sss", $user_id, $id_transfer, $kd_lokasi);
        if (!$stmt_transfer->execute()) {
            throw new Exception('Gagal update transfer: ' . $stmt_transfer->error);
        }
        
        // Get KD_LOKASI_ASAL dari transfer (sekali saja, digunakan untuk semua detail)
        $query_transfer_asal = "SELECT KD_LOKASI_ASAL FROM TRANSFER_BARANG WHERE ID_TRANSFER_BARANG = ?";
        $stmt_transfer_asal = $conn->prepare($query_transfer_asal);
        if (!$stmt_transfer_asal) {
            throw new Exception('Gagal prepare query transfer asal: ' . $conn->error);
        }
        $stmt_transfer_asal->bind_param("s", $id_transfer);
        $stmt_transfer_asal->execute();
        $result_transfer_asal = $stmt_transfer_asal->get_result();
        if ($result_transfer_asal->num_rows == 0) {
            throw new Exception('Data transfer tidak ditemukan!');
        }
        $transfer_asal_data = $result_transfer_asal->fetch_assoc();
        $kd_lokasi_asal = $transfer_asal_data['KD_LOKASI_ASAL'];
        
        // Update setiap detail transfer dan tambahkan stock
        foreach ($detail_masuk as $detail) {
            $id_detail = $detail['id_detail_transfer'] ?? '';
            $jumlah_diterima_dus = intval($detail['jumlah_diterima_dus'] ?? 0);
            $jumlah_ditolak_dus = intval($detail['jumlah_ditolak_dus'] ?? 0);
            $kd_barang = $detail['kd_barang'] ?? '';
            
            if (empty($id_detail) || empty($kd_barang)) {
                continue; // Skip invalid data
            }
            
            // Get JUMLAH_KIRIM_DUS dari detail transfer
            $query_detail_kirim = "SELECT JUMLAH_KIRIM_DUS FROM DETAIL_TRANSFER_BARANG WHERE ID_DETAIL_TRANSFER_BARANG = ?";
            $stmt_detail_kirim = $conn->prepare($query_detail_kirim);
            if (!$stmt_detail_kirim) {
                throw new Exception('Gagal prepare query detail kirim: ' . $conn->error);
            }
            $stmt_detail_kirim->bind_param("s", $id_detail);
            $stmt_detail_kirim->execute();
            $result_detail_kirim = $stmt_detail_kirim->get_result();
            if ($result_detail_kirim->num_rows == 0) {
                continue; // Skip jika detail tidak ditemukan
            }
            $detail_kirim_data = $result_detail_kirim->fetch_assoc();
            $jumlah_kirim_dus = intval($detail_kirim_data['JUMLAH_KIRIM_DUS'] ?? 0);
            
            // Hitung total masuk (diterima - ditolak)
            $total_masuk_dus = $jumlah_diterima_dus - $jumlah_ditolak_dus;
            if ($total_masuk_dus < 0) {
                $total_masuk_dus = 0;
            }
            
            // Hitung jumlah rusak = Jumlah Dikirim - Total Masuk
            $jumlah_rusak_dus = $jumlah_kirim_dus - $total_masuk_dus;
            if ($jumlah_rusak_dus < 0) {
                $jumlah_rusak_dus = 0;
            }
            
            // Update detail transfer: set JUMLAH_DITOLAK_DUS, TOTAL_MASUK_DUS, STATUS = 'SELESAI'
            // Note: JUMLAH_DITERIMA_DUS tidak ada di database, menggunakan TOTAL_MASUK_DUS = jumlah_diterima_dus - jumlah_ditolak_dus
            $update_detail = "UPDATE DETAIL_TRANSFER_BARANG 
                             SET JUMLAH_DITOLAK_DUS = ?,
                                 TOTAL_MASUK_DUS = ?,
                                 STATUS = 'SELESAI'
                             WHERE ID_DETAIL_TRANSFER_BARANG = ? AND ID_TRANSFER_BARANG = ? AND STATUS = 'DIKIRIM'";
            $stmt_detail = $conn->prepare($update_detail);
            if (!$stmt_detail) {
                throw new Exception('Gagal prepare query detail: ' . $conn->error);
            }
            $stmt_detail->bind_param("iiss", $jumlah_ditolak_dus, $total_masuk_dus, $id_detail, $id_transfer);
            if (!$stmt_detail->execute()) {
                throw new Exception('Gagal update detail transfer: ' . $stmt_detail->error);
            }
            
            // Get SATUAN, SATUAN_PERDUS, dan AVG_HARGA_BELI_PIECES
            $query_barang = "SELECT mb.SATUAN_PERDUS, mb.AVG_HARGA_BELI_PIECES, COALESCE(s.SATUAN, 'PIECES') as SATUAN, COALESCE(s.JUMLAH_BARANG, 0) as JUMLAH_BARANG
                            FROM MASTER_BARANG mb
                            LEFT JOIN STOCK s ON mb.KD_BARANG = s.KD_BARANG AND s.KD_LOKASI = ?
                            WHERE mb.KD_BARANG = ?";
            $stmt_barang = $conn->prepare($query_barang);
            if (!$stmt_barang) {
                throw new Exception('Gagal prepare query barang: ' . $conn->error);
            }
            $stmt_barang->bind_param("ss", $kd_lokasi, $kd_barang);
            $stmt_barang->execute();
            $result_barang = $stmt_barang->get_result();
            
            if ($result_barang->num_rows == 0) {
                continue; // Skip jika barang tidak ditemukan
            }
            
            $barang_data = $result_barang->fetch_assoc();
            $satuan = $barang_data['SATUAN'];
            $satuan_perdus = intval($barang_data['SATUAN_PERDUS'] ?? 1);
            $avg_harga_beli = floatval($barang_data['AVG_HARGA_BELI_PIECES'] ?? 0);
            $jumlah_awal = intval($barang_data['JUMLAH_BARANG'] ?? 0);
            
            // Get stock gudang (lokasi asal) untuk STOCK_HISTORY
            $query_stock_gudang = "SELECT COALESCE(JUMLAH_BARANG, 0) as JUMLAH_BARANG, COALESCE(SATUAN, 'DUS') as SATUAN
                                  FROM STOCK
                                  WHERE KD_BARANG = ? AND KD_LOKASI = ?";
            $stmt_stock_gudang = $conn->prepare($query_stock_gudang);
            if (!$stmt_stock_gudang) {
                throw new Exception('Gagal prepare query stock gudang: ' . $conn->error);
            }
            $stmt_stock_gudang->bind_param("ss", $kd_barang, $kd_lokasi_asal);
            $stmt_stock_gudang->execute();
            $result_stock_gudang = $stmt_stock_gudang->get_result();
            $stock_gudang_data = $result_stock_gudang->num_rows > 0 ? $result_stock_gudang->fetch_assoc() : ['JUMLAH_BARANG' => 0, 'SATUAN' => 'DUS'];
            $jumlah_awal_gudang = intval($stock_gudang_data['JUMLAH_BARANG'] ?? 0);
            $satuan_gudang = $stock_gudang_data['SATUAN'] ?? 'DUS';
            
            // Konversi total masuk dari DUS ke PIECES
            $total_masuk_pieces = $total_masuk_dus * $satuan_perdus;
            
            // Update atau Insert STOCK di toko (lokasi tujuan) - tambah stock
            $jumlah_akhir = $jumlah_awal + $total_masuk_pieces;
            
            if ($jumlah_awal > 0 || $result_barang->num_rows > 0) {
                // Update existing stock
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
            } else {
                // Insert new stock
                $update_stock = "INSERT INTO STOCK (KD_BARANG, KD_LOKASI, JUMLAH_BARANG, UPDATED_BY, SATUAN, LAST_UPDATED)
                               VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP)";
                $stmt_update_stock = $conn->prepare($update_stock);
                if (!$stmt_update_stock) {
                    throw new Exception('Gagal prepare query insert stock: ' . $conn->error);
                }
                $stmt_update_stock->bind_param("ssiss", $kd_barang, $kd_lokasi, $jumlah_akhir, $user_id, $satuan);
            }
            
            if (!$stmt_update_stock->execute()) {
                throw new Exception('Gagal mengupdate stock: ' . $stmt_update_stock->error);
            }
            
            // Insert ke STOCK_HISTORY
            $id_history = '';
            do {
                $id_history = ShortIdGenerator::generate(16, '');
            } while (checkUUIDExists($conn, 'STOCK_HISTORY', 'ID_HISTORY_STOCK', $id_history));
            
            $jumlah_perubahan = $total_masuk_pieces; // Positif karena menambah stock
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
            
            // Insert ke MUTASI_BARANG_RUSAK jika ada barang rusak
            // Jumlah rusak = Jumlah Dikirim - Total Masuk
            if ($jumlah_rusak_dus > 0) {
                // Hitung harga rusak: avg_harga_beli adalah per piece, jadi harga total = avg_harga_beli * jumlah rusak (dalam pieces)
                $jumlah_rusak_pieces = $jumlah_rusak_dus * $satuan_perdus;
                $harga_rusak = $avg_harga_beli * $jumlah_rusak_pieces;
                
                $id_mutasi_rusak = '';
                do {
                    $id_mutasi_rusak = ShortIdGenerator::generate(16, '');
                } while (checkUUIDExists($conn, 'MUTASI_BARANG_RUSAK', 'ID_MUTASI_BARANG_RUSAK', $id_mutasi_rusak));
                
                $insert_mutasi_rusak = "INSERT INTO MUTASI_BARANG_RUSAK 
                                      (ID_MUTASI_BARANG_RUSAK, KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_MUTASI, HARGA_BARANG_PIECES, SATUAN)
                                      VALUES (?, ?, ?, ?, ?, ?, ?)";
                $stmt_mutasi_rusak = $conn->prepare($insert_mutasi_rusak);
                if (!$stmt_mutasi_rusak) {
                    throw new Exception('Gagal prepare query mutasi rusak: ' . $conn->error);
                }
                // Jumlah rusak dalam DUS, jadi SATUAN = 'DUS'
                $satuan_mutasi = 'DUS';
                $stmt_mutasi_rusak->bind_param("ssssids", $id_mutasi_rusak, $kd_barang, $kd_lokasi_asal, $user_id, $jumlah_rusak_dus, $harga_rusak, $satuan_mutasi);
                if (!$stmt_mutasi_rusak->execute()) {
                    throw new Exception('Gagal insert mutasi rusak: ' . $stmt_mutasi_rusak->error);
                }
                
                // Insert ke STOCK_HISTORY untuk gudang (lokasi asal) dengan REF = ID_DETAIL_TRANSFER_BARANG
                $id_history_gudang = '';
                do {
                    $id_history_gudang = ShortIdGenerator::generate(16, '');
                } while (checkUUIDExists($conn, 'STOCK_HISTORY', 'ID_HISTORY_STOCK', $id_history_gudang));
                
                // Konversi jumlah rusak dari DUS ke PIECES untuk gudang
                $jumlah_rusak_pieces_gudang = $jumlah_rusak_dus * $satuan_perdus;
                $jumlah_akhir_gudang = $jumlah_awal_gudang - $jumlah_rusak_pieces_gudang;
                if ($jumlah_akhir_gudang < 0) {
                    $jumlah_akhir_gudang = 0;
                }
                
                $jumlah_perubahan_gudang = -$jumlah_rusak_pieces_gudang; // Negatif karena mengurangi stock
                $insert_history_gudang = "INSERT INTO STOCK_HISTORY 
                                        (ID_HISTORY_STOCK, KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_AWAL, JUMLAH_PERUBAHAN, JUMLAH_AKHIR, TIPE_PERUBAHAN, REF, SATUAN)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, 'RUSAK', ?, ?)";
                $stmt_history_gudang = $conn->prepare($insert_history_gudang);
                if (!$stmt_history_gudang) {
                    throw new Exception('Gagal prepare query insert history gudang: ' . $conn->error);
                }
                $stmt_history_gudang->bind_param("ssssiiiss", $id_history_gudang, $kd_barang, $kd_lokasi_asal, $user_id, $jumlah_awal_gudang, $jumlah_perubahan_gudang, $jumlah_akhir_gudang, $id_detail, $satuan_gudang);
                if (!$stmt_history_gudang->execute()) {
                    throw new Exception('Gagal insert history gudang: ' . $stmt_history_gudang->error);
                }
            }
        }
        
        // Commit transaksi
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Barang berhasil divalidasi dan stock diperbarui!']);
    } catch (Exception $e) {
        // Rollback transaksi
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit();
}

// Query untuk mendapatkan data barang masuk (transfer ke toko ini)
$query_transfer = "SELECT 
    tb.ID_TRANSFER_BARANG,
    tb.WAKTU_PESAN_TRANSFER,
    tb.WAKTU_KIRIM_TRANSFER,
    tb.WAKTU_SELESAI_TRANSFER,
    tb.STATUS,
    tb.KD_LOKASI_ASAL,
    ml_asal.KD_LOKASI as KD_LOKASI_ASAL,
    ml_asal.NAMA_LOKASI as NAMA_LOKASI_ASAL,
    ml_asal.ALAMAT_LOKASI as ALAMAT_LOKASI_ASAL,
    COALESCE(SUM(dtb.JUMLAH_PESAN_TRANSFER_DUS), 0) as TOTAL_DIPESAN_DUS
FROM TRANSFER_BARANG tb
LEFT JOIN MASTER_LOKASI ml_asal ON tb.KD_LOKASI_ASAL = ml_asal.KD_LOKASI
LEFT JOIN DETAIL_TRANSFER_BARANG dtb ON tb.ID_TRANSFER_BARANG = dtb.ID_TRANSFER_BARANG
WHERE tb.KD_LOKASI_TUJUAN = ? AND tb.STATUS IN ('DIKIRIM', 'SELESAI')
GROUP BY tb.ID_TRANSFER_BARANG, tb.WAKTU_PESAN_TRANSFER, tb.WAKTU_KIRIM_TRANSFER, tb.WAKTU_SELESAI_TRANSFER, tb.STATUS, tb.KD_LOKASI_ASAL, ml_asal.KD_LOKASI, ml_asal.NAMA_LOKASI, ml_asal.ALAMAT_LOKASI
ORDER BY tb.WAKTU_PESAN_TRANSFER DESC";

$stmt_transfer = $conn->prepare($query_transfer);
$stmt_transfer->bind_param("s", $kd_lokasi);
$stmt_transfer->execute();
$result_transfer = $stmt_transfer->get_result();

// Format waktu stack
function formatWaktuStack($waktu_pesan, $waktu_kirim, $waktu_selesai, $status) {
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    
    $html = '<div class="d-flex flex-column gap-1">';
    
    // Waktu diterima (jika status SELESAI) - tampilkan di atas
    if (!empty($waktu_selesai) && $status == 'SELESAI') {
        $date_sampai = new DateTime($waktu_selesai);
        $tanggal_sampai = $date_sampai->format('d') . ' ' . $bulan[(int)$date_sampai->format('m')] . ' ' . $date_sampai->format('Y');
        $waktu_sampai_formatted = $date_sampai->format('H:i') . ' WIB';
        $html .= '<div>';
        $html .= htmlspecialchars($tanggal_sampai . ' ' . $waktu_sampai_formatted) . ' ';
        $html .= '<span class="badge bg-success">DITERIMA</span>';
        $html .= '</div>';
    }
    
    // Waktu Dikirim (jika ada)
    if (!empty($waktu_kirim)) {
        $date_kirim = new DateTime($waktu_kirim);
        $tanggal_kirim = $date_kirim->format('d') . ' ' . $bulan[(int)$date_kirim->format('m')] . ' ' . $date_kirim->format('Y');
        $waktu_kirim_formatted = $date_kirim->format('H:i') . ' WIB';
        $html .= '<div>';
        $html .= htmlspecialchars($tanggal_kirim . ' ' . $waktu_kirim_formatted) . ' ';
        $html .= '<span class="badge bg-info">DIKIRIM</span>';
        $html .= '</div>';
    }
    
    // Waktu dipesan - tampilkan di bawah
    if (!empty($waktu_pesan)) {
        $date_pesan = new DateTime($waktu_pesan);
        $tanggal_pesan = $date_pesan->format('d') . ' ' . $bulan[(int)$date_pesan->format('m')] . ' ' . $date_pesan->format('Y');
        $waktu_pesan_formatted = $date_pesan->format('H:i') . ' WIB';
        $html .= '<div>';
        $html .= htmlspecialchars($tanggal_pesan . ' ' . $waktu_pesan_formatted) . ' ';
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
    <title>Kasir - Barang Masuk</title>
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
            <h1 class="page-title">Toko <?php echo htmlspecialchars($nama_lokasi); ?> - Barang Masuk</h1>
            <?php if (!empty($alamat_lokasi)): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($alamat_lokasi); ?></p>
            <?php endif; ?>
        </div>

        <!-- Table Barang Masuk -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tableBarangMasuk" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID Transfer</th>
                            <th>Waktu</th>
                            <th>Lokasi Asal</th>
                            <th>Total Dipesan (dus)</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_transfer && $result_transfer->num_rows > 0): ?>
                            <?php while ($row = $result_transfer->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['ID_TRANSFER_BARANG']); ?></td>
                                    <td><?php echo formatWaktuStack($row['WAKTU_PESAN_TRANSFER'], $row['WAKTU_KIRIM_TRANSFER'], $row['WAKTU_SELESAI_TRANSFER'], $row['STATUS']); ?></td>
                                    <td>
                                        <?php 
                                        if (!empty($row['NAMA_LOKASI_ASAL'])) {
                                            $lokasi_text = $row['KD_LOKASI_ASAL'] . '-' . $row['NAMA_LOKASI_ASAL'];
                                            if (!empty($row['ALAMAT_LOKASI_ASAL'])) {
                                                $lokasi_text .= '-' . $row['ALAMAT_LOKASI_ASAL'];
                                            }
                                            echo htmlspecialchars($lokasi_text);
                                        } else {
                                            echo htmlspecialchars($row['KD_LOKASI_ASAL']);
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo number_format($row['TOTAL_DIPESAN_DUS'], 0, ',', '.'); ?></td>
                                    <td>
                                        <?php 
                                        $status_text = '';
                                        $status_class = '';
                                        switch($row['STATUS']) {
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
                                            default:
                                                $status_text = $row['STATUS'];
                                                $status_class = 'secondary';
                                        }
                                        ?>
                                        <span class="badge bg-<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <button class="btn-view btn-sm" onclick="lihatSuratJalan('<?php echo htmlspecialchars($row['ID_TRANSFER_BARANG']); ?>')">Lihat Surat Jalan</button>
                                            <?php if ($row['STATUS'] == 'DIKIRIM'): ?>
                                                <button class="btn btn-success btn-sm" onclick="validasiMasuk('<?php echo htmlspecialchars($row['ID_TRANSFER_BARANG']); ?>')">Validasi Masuk</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">Tidak ada data barang masuk</td>
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

    <!-- Modal Validasi Masuk -->
    <div class="modal fade" id="modalValidasiMasuk" tabindex="-1" aria-labelledby="modalValidasiMasukLabel" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalValidasiMasukLabel">Validasi Barang Masuk Toko</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Info Asal -->
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <label class="form-label fw-bold">ID Transfer</label>
                            <input type="text" class="form-control" id="validasi_id_transfer" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Kode Asal</label>
                            <input type="text" class="form-control" id="validasi_kd_asal" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Nama Asal</label>
                            <input type="text" class="form-control" id="validasi_nama_asal" readonly style="background-color: #e9ecef;">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-bold">Alamat Asal</label>
                            <input type="text" class="form-control" id="validasi_alamat_asal" readonly style="background-color: #e9ecef;">
                        </div>
                    </div>
                    
                    <!-- Tabel Detail Barang -->
                    <div class="table-responsive" style="max-height: 60vh; overflow-y: auto;">
                        <table class="table table-bordered table-hover" id="tableValidasiMasuk">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th>ID Detail Transfer</th>
                                    <th>Kode Barang</th>
                                    <th>Merek</th>
                                    <th>Kategori</th>
                                    <th>Nama Barang</th>
                                    <th>Jumlah Pesan (dus)</th>
                                    <th>Jumlah Dikirim (dus)</th>
                                    <th>Jumlah Diterima (dus)</th>
                                    <th>Jumlah Ditolak (dus)</th>
                                    <th>Total Masuk (dus)</th>
                                    <th>Jumlah per Dus</th>
                                    <th>Total Masuk (pieces)</th>
                                    <th>Stock Sekarang (pieces)</th>
                                    <th>Jumlah Stock Akhir (pieces)</th>
                                    <th>Diterima Semua</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyValidasiMasuk">
                                <tr>
                                    <td colspan="15" class="text-center text-muted">Memuat data...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" onclick="simpanValidasiMasuk()">Simpan</button>
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
            
            $('#tableBarangMasuk').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data barang masuk'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[0, 'desc']], // Sort by ID Transfer descending
                columnDefs: [
                    { orderable: false, targets: [5] } // Disable sorting on Action column
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

        function validasiMasuk(idTransfer) {
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
                        // Set info asal
                        $('#validasi_id_transfer').val(response.transfer_info.id_transfer);
                        $('#validasi_kd_asal').val(response.transfer_info.kd_lokasi_asal);
                        $('#validasi_nama_asal').val(response.transfer_info.nama_lokasi_asal);
                        $('#validasi_alamat_asal').val(response.transfer_info.alamat_lokasi_asal);
                        
                        // Render tabel
                        renderTabelValidasiMasuk(response.detail_data);
                        
                        // Buka modal
                        $('#modalValidasiMasuk').modal('show');
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
        
        function renderTabelValidasiMasuk(data) {
            var tbody = $('#tbodyValidasiMasuk');
            tbody.empty();
            
            if (data.length === 0) {
                tbody.append('<tr><td colspan="15" class="text-center text-muted">Tidak ada data</td></tr>');
                return;
            }
            
            data.forEach(function(item, index) {
                var totalMasukDus = 0; // Default: 0 karena checkbox tidak tercentang
                var totalMasukPieces = 0;
                var jumlahStockAkhir = item.stock_sekarang;
                
                var row = '<tr data-id-detail="' + escapeHtml(item.id_detail_transfer) + '" data-kd-barang="' + escapeHtml(item.kd_barang) + '" data-index="' + index + '">' +
                    '<td>' + escapeHtml(item.id_detail_transfer) + '</td>' +
                    '<td>' + escapeHtml(item.kd_barang) + '</td>' +
                    '<td>' + escapeHtml(item.nama_merek) + '</td>' +
                    '<td>' + escapeHtml(item.nama_kategori) + '</td>' +
                    '<td>' + escapeHtml(item.nama_barang) + '</td>' +
                    '<td>' + numberFormat(item.jumlah_pesan_dus) + '</td>' +
                    '<td>' + numberFormat(item.jumlah_kirim_dus) + '</td>' +
                    '                                    <td><input type="number" class="form-control form-control-sm jumlah-diterima-dus" min="0" max="' + item.jumlah_kirim_dus + '" value="0" data-index="' + index + '" data-jumlah-kirim="' + item.jumlah_kirim_dus + '" style="width: 80px;"></td>' +
                    '<td><input type="number" class="form-control form-control-sm jumlah-ditolak-dus" min="0" max="' + item.jumlah_kirim_dus + '" value="0" data-index="' + index + '" style="width: 80px;"></td>' +
                    '<td class="total-masuk-dus">' + numberFormat(totalMasukDus) + '</td>' +
                    '<td>' + numberFormat(item.satuan_perdus) + '</td>' +
                    '<td class="total-masuk-pieces">' + numberFormat(totalMasukPieces) + '</td>' +
                    '<td>' + numberFormat(item.stock_sekarang) + '</td>' +
                    '<td class="jumlah-stock-akhir">' + numberFormat(jumlahStockAkhir) + '</td>' +
                    '<td><input type="checkbox" class="form-check-input diterima-semua" data-index="' + index + '" data-jumlah-kirim="' + item.jumlah_kirim_dus + '"></td>' +
                    '</tr>';
                tbody.append(row);
            });
            
            // Attach event listeners
            attachValidasiMasukEventListeners();
            
            // Hitung total masuk untuk setiap row setelah render
            data.forEach(function(item, index) {
                calculateValidasiMasuk(index);
            });
        }
        
        function attachValidasiMasukEventListeners() {
            // Event listener untuk checkbox "Diterima Semua"
            $(document).off('change', '.diterima-semua').on('change', '.diterima-semua', function() {
                var index = $(this).data('index');
                var jumlahKirim = parseInt($(this).data('jumlah-kirim')) || 0;
                
                if ($(this).is(':checked')) {
                    // Set jumlah diterima = jumlah kirim, jumlah ditolak = 0
                    $('.jumlah-diterima-dus[data-index="' + index + '"]').val(jumlahKirim);
                    $('.jumlah-ditolak-dus[data-index="' + index + '"]').val(0);
                    calculateValidasiMasuk(index);
                } else {
                    // Reset ke 0
                    $('.jumlah-diterima-dus[data-index="' + index + '"]').val(0);
                    $('.jumlah-ditolak-dus[data-index="' + index + '"]').val(0);
                    calculateValidasiMasuk(index);
                }
            });
            
            // Event listener untuk input jumlah diterima
            $(document).off('input', '.jumlah-diterima-dus').on('input', '.jumlah-diterima-dus', function() {
                var index = $(this).data('index');
                var jumlahDiterima = parseInt($(this).val()) || 0;
                var jumlahKirim = parseInt($(this).data('jumlah-kirim')) || 0;
                
                // Validasi: tidak boleh melebihi jumlah kirim
                if (jumlahDiterima > jumlahKirim) {
                    $(this).val(jumlahKirim);
                    jumlahDiterima = jumlahKirim;
                }
                
                // Update checkbox "Diterima Semua"
                if (jumlahDiterima == jumlahKirim) {
                    $('.diterima-semua[data-index="' + index + '"]').prop('checked', true);
                } else {
                    $('.diterima-semua[data-index="' + index + '"]').prop('checked', false);
                }
                
                calculateValidasiMasuk(index);
            });
            
            // Event listener untuk input jumlah ditolak
            $(document).off('input', '.jumlah-ditolak-dus').on('input', '.jumlah-ditolak-dus', function() {
                var index = $(this).data('index');
                var jumlahDitolak = parseInt($(this).val()) || 0;
                var jumlahDiterima = parseInt($('.jumlah-diterima-dus[data-index="' + index + '"]').val()) || 0;
                
                // Validasi: jumlah ditolak tidak boleh melebihi jumlah diterima
                if (jumlahDitolak > jumlahDiterima) {
                    $(this).val(jumlahDiterima);
                    jumlahDitolak = jumlahDiterima;
                }
                
                calculateValidasiMasuk(index);
            });
        }
        
        function calculateValidasiMasuk(index) {
            var row = $('tr[data-index="' + index + '"]');
            var jumlahDiterimaDus = parseInt(row.find('.jumlah-diterima-dus').val()) || 0;
            var jumlahDitolakDus = parseInt(row.find('.jumlah-ditolak-dus').val()) || 0;
            var jumlahKirimDus = parseInt(row.find('.jumlah-diterima-dus').data('jumlah-kirim')) || 0;
            var satuanPerdus = parseInt(row.find('.total-masuk-pieces').text().replace(/\./g, '')) || 0;
            var stockSekarang = parseInt(row.find('td').eq(12).text().replace(/\./g, '')) || 0;
            
            // Get satuan perdus dari jumlah per dus column
            var jumlahPerDusText = row.find('td').eq(10).text().replace(/\./g, '');
            satuanPerdus = parseInt(jumlahPerDusText) || 1;
            
            // Hitung total masuk (diterima - ditolak)
            var totalMasukDus = jumlahDiterimaDus - jumlahDitolakDus;
            if (totalMasukDus < 0) {
                totalMasukDus = 0;
            }
            
            // Hitung total masuk (pieces)
            var totalMasukPieces = totalMasukDus * satuanPerdus;
            
            // Hitung jumlah stock akhir
            var jumlahStockAkhir = stockSekarang + totalMasukPieces;
            
            // Update tampilan
            row.find('.total-masuk-dus').text(numberFormat(totalMasukDus));
            row.find('.total-masuk-pieces').text(numberFormat(totalMasukPieces));
            row.find('.jumlah-stock-akhir').text(numberFormat(jumlahStockAkhir));
        }
        
        function simpanValidasiMasuk() {
            var idTransfer = $('#validasi_id_transfer').val();
            var detailMasuk = [];
            
            // Kumpulkan data dari tabel
            $('#tbodyValidasiMasuk tr[data-id-detail]').each(function() {
                var idDetail = $(this).data('id-detail');
                var kdBarang = $(this).data('kd-barang');
                var jumlahDiterimaDus = parseInt($(this).find('.jumlah-diterima-dus').val()) || 0;
                var jumlahDitolakDus = parseInt($(this).find('.jumlah-ditolak-dus').val()) || 0;
                
                if (jumlahDiterimaDus > 0 || jumlahDitolakDus > 0) {
                    detailMasuk.push({
                        id_detail_transfer: idDetail,
                        kd_barang: kdBarang,
                        jumlah_diterima_dus: jumlahDiterimaDus,
                        jumlah_ditolak_dus: jumlahDitolakDus
                    });
                }
            });
            
            if (detailMasuk.length === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Minimal satu barang harus memiliki jumlah diterima atau ditolak!',
                    confirmButtonColor: '#667eea'
                });
                return;
            }
            
            // Konfirmasi
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi',
                text: 'Apakah Anda yakin ingin memvalidasi dan menerima barang masuk ini?',
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
                            action: 'validasi_masuk',
                            id_transfer: idTransfer,
                            detail_masuk: detailMasuk
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
                                    $('#modalValidasiMasuk').modal('hide');
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
                            var errorMessage = 'Terjadi kesalahan saat memvalidasi barang masuk!';
                            
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
        $('#modalValidasiMasuk').on('hidden.bs.modal', function() {
            $('#tbodyValidasiMasuk').html('<tr><td colspan="15" class="text-center text-muted">Memuat data...</td></tr>');
        });
    </script>
</body>
</html>

