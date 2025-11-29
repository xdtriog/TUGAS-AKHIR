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
            // Pattern: generate > check > pass, generate > check (duplikat) > generate > check > pass
            $maxAttempts = 100;
            $attempt = 0;
            do {
                $id_pesan_barang = ShortIdGenerator::generate(16, '');
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
    END as PRIORITAS_JATUH_TEMPO
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
    $stmt_stock->bind_param("ss", $kd_lokasi, $kd_lokasi);
    if (!$stmt_stock->execute()) {
        error_log("Execute Error: " . $stmt_stock->error);
        $message = 'Error menjalankan query: ' . htmlspecialchars($stmt_stock->error);
        $message_type = 'danger';
        $result_stock = null;
    } else {
        $result_stock = $stmt_stock->get_result();
    }
}

// Format tanggal Indonesia
function formatTanggal($tanggal) {
    if (empty($tanggal) || $tanggal == null) {
        return '-';
    }
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $date = new DateTime($tanggal);
    return $date->format('d') . ' ' . $bulan[(int)$date->format('m')] . ' ' . $date->format('Y');
}

// Format tanggal dan waktu Indonesia (untuk Terakhir Update)
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
    return $date->format('d') . ' ' . $bulan[(int)$date->format('m')] . ' ' . $date->format('Y') . ' ' . $date->format('H:i') . ' WIB';
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
            // Redirect ke halaman hitung POQ
            window.location.href = 'hitung_poq.php?kd_barang=' + encodeURIComponent(kdBarang) + '&kd_lokasi=' + encodeURIComponent(kdLokasi);
        }

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

