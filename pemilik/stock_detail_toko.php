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
        s.SATUAN
    FROM STOCK s
    INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
    LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
    LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
    WHERE s.KD_BARANG IN ($placeholders) AND s.KD_LOKASI = ? AND mb.STATUS = 'AKTIF'
    ORDER BY mb.NAMA_BARANG ASC";
    // Note: Filter STATUS = 'AKTIF' tetap diperlukan untuk resupply karena hanya barang aktif yang bisa di-resupply
    
    $stmt_resupply = $conn->prepare($query_resupply);
    $types = str_repeat('s', count($kd_barang_list)) . 's';
    $params = array_merge($kd_barang_list, [$kd_lokasi]);
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
            'satuan' => $row['SATUAN']
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $data]);
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
        $insert_transfer = "INSERT INTO TRANSFER_BARANG 
                          (ID_TRANSFER_BARANG, ID_USERS_PENERIMA, KD_LOKASI_ASAL, KD_LOKASI_TUJUAN, WAKTU_PESAN_TRANSFER, STATUS)
                          VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, 'DIPESAN')";
        $stmt_transfer = $conn->prepare($insert_transfer);
        if (!$stmt_transfer) {
            throw new Exception('Gagal prepare query transfer: ' . $conn->error);
        }
        $stmt_transfer->bind_param("ssss", $id_transfer, $user_id, $kd_lokasi_asal, $kd_lokasi);
        if (!$stmt_transfer->execute()) {
            throw new Exception('Gagal insert transfer: ' . $stmt_transfer->error);
        }
        
        // Insert DETAIL_TRANSFER_BARANG untuk setiap barang
        foreach ($resupply_data as $item) {
            $kd_barang = $item['kd_barang'] ?? '';
            $jumlah_resupply_dus = intval($item['jumlah_resupply_dus'] ?? 0);
            
            if (empty($kd_barang) || $jumlah_resupply_dus <= 0) {
                continue; // Skip invalid data
            }
            
            // Generate ID detail transfer
            $id_detail = '';
            do {
                $id_detail = ShortIdGenerator::generate(16, '');
            } while (checkUUIDExists($conn, 'DETAIL_TRANSFER_BARANG', 'ID_DETAIL_TRANSFER_BARANG', $id_detail));
            
            $insert_detail = "INSERT INTO DETAIL_TRANSFER_BARANG 
                             (ID_DETAIL_TRANSFER_BARANG, ID_TRANSFER_BARANG, KD_BARANG, JUMLAH_PESAN_TRANSFER_DUS, STATUS)
                             VALUES (?, ?, ?, ?, 'DIPESAN')";
            $stmt_detail = $conn->prepare($insert_detail);
            if (!$stmt_detail) {
                throw new Exception('Gagal prepare query detail transfer: ' . $conn->error);
            }
            $stmt_detail->bind_param("sssi", $id_detail, $id_transfer, $kd_barang, $jumlah_resupply_dus);
            if (!$stmt_detail->execute()) {
                throw new Exception('Gagal insert detail transfer: ' . $stmt_detail->error);
            }
        }
        
        // Commit transaksi
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Resupply berhasil dibuat dengan ID: ' . $id_transfer]);
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
                                    <th>Jumlah Resupply (dus)</th>
                                    <th>Jumlah Resupply (piece)</th>
                                    <th>Jumlah Stock Akhir (piece)</th>
                                    <th>Isi Penuh</th>
                                </tr>
                            </thead>
                            <tbody id="tbodyResupply">
                                <tr>
                                    <td colspan="13" class="text-center text-muted">Pilih barang dari tabel utama terlebih dahulu</td>
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
                $('#tbodyResupply').html('<tr><td colspan="13" class="text-center text-muted">Pilih barang dari tabel utama terlebih dahulu</td></tr>');
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
                tbody.append('<tr><td colspan="13" class="text-center text-muted">Tidak ada data</td></tr>');
                return;
            }
            
            data.forEach(function(item, index) {
                var row = '<tr data-kd-barang="' + escapeHtml(item.kd_barang) + '">' +
                    '<td>' + escapeHtml(item.kd_barang) + '</td>' +
                    '<td>' + escapeHtml(item.nama_merek) + '</td>' +
                    '<td>' + escapeHtml(item.nama_kategori) + '</td>' +
                    '<td>' + escapeHtml(item.nama_barang) + '</td>' +
                    '<td>' + numberFormat(item.berat) + '</td>' +
                    '<td>' + (item.stock_min ? numberFormat(item.stock_min) : '-') + '</td>' +
                    '<td>' + (item.stock_max ? numberFormat(item.stock_max) : '-') + '</td>' +
                    '<td>' + numberFormat(item.stock_sekarang) + '</td>' +
                    '<td>' + numberFormat(item.satuan_perdus) + '</td>' +
                    '<td><input type="number" class="form-control form-control-sm jumlah-resupply-dus" min="0" value="0" data-index="' + index + '" style="width: 80px;"></td>' +
                    '<td class="jumlah-resupply-piece" data-index="' + index + '">0</td>' +
                    '<td class="jumlah-stock-akhir" data-index="' + index + '">' + numberFormat(item.stock_sekarang) + '</td>' +
                    '<td><input type="checkbox" class="form-check-input isi-penuh" data-index="' + index + '" data-stock-max="' + item.stock_max + '" data-stock-sekarang="' + item.stock_sekarang + '" data-satuan-perdus="' + item.satuan_perdus + '"></td>' +
                    '</tr>';
                tbody.append(row);
            });
            
            // Attach event listeners
            attachResupplyEventListeners();
        }
        
        function attachResupplyEventListeners() {
            // Event listener untuk input jumlah resupply (dus)
            $(document).off('input', '.jumlah-resupply-dus').on('input', '.jumlah-resupply-dus', function() {
                var index = $(this).data('index');
                calculateResupply(index);
            });
            
            // Event listener untuk checkbox "Isi Penuh"
            $(document).off('change', '.isi-penuh').on('change', '.isi-penuh', function() {
                var index = $(this).data('index');
                var stockMax = parseInt($(this).data('stock-max')) || 0;
                var stockSekarang = parseInt($(this).data('stock-sekarang')) || 0;
                var satuanPerdus = parseInt($(this).data('satuan-perdus')) || 1;
                
                if ($(this).is(':checked')) {
                    // Hitung jumlah dus yang membuat jumlah akhir (piece) sampai stock max (boleh sama dengan stock max, tidak boleh melebihi)
                    // Maksimal pieces yang bisa di-resupply = Stock Max - Stock Sekarang
                    var maksimalPieces = stockMax - stockSekarang;
                    
                    // Jika maksimalPieces <= 0, berarti sudah mencapai atau melebihi stock max
                    if (maksimalPieces <= 0) {
                        // Tidak bisa resupply karena sudah mencapai atau melebihi stock max
                        $('.jumlah-resupply-dus[data-index="' + index + '"]').val(0);
                        $(this).prop('checked', false);
                        Swal.fire({
                            icon: 'warning',
                            title: 'Peringatan!',
                            text: 'Stock sudah mencapai atau melebihi Stock Max. Tidak dapat melakukan resupply.',
                            confirmButtonColor: '#667eea',
                            timer: 2000,
                            timerProgressBar: true
                        });
                        return;
                    }
                    
                    // Hitung jumlah dus: floor(maksimalPieces / satuanPerdus)
                    // Ini akan menghasilkan jumlah dus yang membuat stock akhir <= stock max
                    var jumlahDus = Math.floor(maksimalPieces / satuanPerdus);
                    
                    // Set nilai input
                    $('.jumlah-resupply-dus[data-index="' + index + '"]').val(jumlahDus);
                    calculateResupply(index);
                } else {
                    // Reset ke 0
                    $('.jumlah-resupply-dus[data-index="' + index + '"]').val(0);
                    calculateResupply(index);
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
            
            // Validasi: stock akhir tidak boleh melebihi stock max (boleh sama dengan stock max)
            if (stockMax > 0 && stockAkhir > stockMax) {
                // Kurangi jumlah dus jika melebihi stock max
                var maksimalPieces = stockMax - stockSekarang;
                if (maksimalPieces > 0) {
                    jumlahDus = Math.floor(maksimalPieces / satuanPerdus);
                    jumlahPiece = jumlahDus * satuanPerdus;
                    stockAkhir = stockSekarang + jumlahPiece;
                    
                    // Update input jika diubah
                    row.find('.jumlah-resupply-dus').val(jumlahDus);
                    
                    // Uncheck "Isi Penuh" jika ada
                    row.find('.isi-penuh').prop('checked', false);
                } else {
                    // Tidak bisa resupply
                    jumlahDus = 0;
                    jumlahPiece = 0;
                    stockAkhir = stockSekarang;
                    row.find('.jumlah-resupply-dus').val(0);
                    row.find('.isi-penuh').prop('checked', false);
                }
            }
            
            // Update tampilan
            row.find('.jumlah-resupply-piece').text(numberFormat(jumlahPiece));
            row.find('.jumlah-stock-akhir').text(numberFormat(stockAkhir));
        }
        
        function konfirmasiSimpanResupply() {
            // Validasi minimal ada satu barang dengan jumlah resupply > 0
            var hasValidData = false;
            var resupplyData = [];
            
            $('tr[data-kd-barang]').each(function() {
                var kdBarang = $(this).data('kd-barang');
                var jumlahDus = parseInt($(this).find('.jumlah-resupply-dus').val()) || 0;
                
                if (jumlahDus > 0) {
                    hasValidData = true;
                    resupplyData.push({
                        kd_barang: kdBarang,
                        jumlah_resupply_dus: jumlahDus
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
            
            // Konfirmasi
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi',
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

