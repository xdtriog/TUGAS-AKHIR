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
        // Query untuk mendapatkan stock data
        $query_stock_ajax = "SELECT JUMLAH_MAX_STOCK, JUMLAH_BARANG 
                            FROM STOCK 
                            WHERE KD_BARANG = ? AND KD_LOKASI = ?";
        $stmt_stock_ajax = $conn->prepare($query_stock_ajax);
        $stmt_stock_ajax->bind_param("ss", $kd_barang_ajax, $kd_lokasi_ajax);
        $stmt_stock_ajax->execute();
        $result_stock_ajax = $stmt_stock_ajax->get_result();
        
        // Query untuk mendapatkan supplier terakhir
        $query_supplier_last = "SELECT KD_SUPPLIER 
                               FROM PESAN_BARANG 
                               WHERE KD_BARANG = ? AND KD_LOKASI = ? AND KD_SUPPLIER IS NOT NULL 
                               ORDER BY WAKTU_PESAN DESC 
                               LIMIT 1";
        $stmt_supplier_last = $conn->prepare($query_supplier_last);
        $stmt_supplier_last->bind_param("ss", $kd_barang_ajax, $kd_lokasi_ajax);
        $stmt_supplier_last->execute();
        $result_supplier_last = $stmt_supplier_last->get_result();
        $last_supplier = $result_supplier_last->num_rows > 0 ? $result_supplier_last->fetch_assoc()['KD_SUPPLIER'] : null;
        
        if ($result_stock_ajax->num_rows > 0) {
            $stock_data = $result_stock_ajax->fetch_assoc();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'stock_max' => $stock_data['JUMLAH_MAX_STOCK'],
                'stock_sekarang' => $stock_data['JUMLAH_BARANG'],
                'last_supplier' => $last_supplier
            ]);
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
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
    s.JUMLAH_BARANG as STOCK_SEKARANG,
    s.JUMLAH_MIN_STOCK,
    s.JUMLAH_MAX_STOCK,
    s.SATUAN,
    s.LAST_UPDATED,
    COALESCE(
        DATE_FORMAT(DATE_ADD(poq.WAKTU_POQ, INTERVAL poq.INTERVAL_HARI DAY), '%Y-%m-%d'),
        NULL
    ) as JATUH_TEMPO_POQ,
    COALESCE(
        DATE_FORMAT(poq.WAKTU_POQ, '%Y-%m-%d %H:%i:%s'),
        NULL
    ) as WAKTU_TERAKHIR_POQ
FROM STOCK s
INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
LEFT JOIN (
    SELECT 
        poq1.KD_BARANG,
        poq1.KD_LOKASI,
        poq1.WAKTU_POQ,
        poq1.INTERVAL_HARI,
        poq1.ID_POQ
    FROM PERHITUNGAN_POQ poq1
    INNER JOIN (
        SELECT KD_BARANG, KD_LOKASI, MAX(WAKTU_POQ) as MAX_WAKTU
        FROM PERHITUNGAN_POQ
        WHERE KD_LOKASI = ?
        GROUP BY KD_BARANG, KD_LOKASI
    ) poq2 ON poq1.KD_BARANG = poq2.KD_BARANG 
        AND poq1.KD_LOKASI = poq2.KD_LOKASI 
        AND poq1.WAKTU_POQ = poq2.MAX_WAKTU
) poq ON s.KD_BARANG = poq.KD_BARANG AND s.KD_LOKASI = poq.KD_LOKASI
WHERE s.KD_LOKASI = ? AND mb.STATUS = 'AKTIF'
ORDER BY mb.NAMA_BARANG ASC";

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
                                        <div class="d-flex flex-column gap-1">
                                            <button class="btn-view btn-sm" onclick="lihatRiwayatPembelian('<?php echo htmlspecialchars($row['KD_BARANG']); ?>')">Lihat Riwayat Pembelian</button>
                                            <button class="btn-view btn-sm" onclick="hitungPOQ('<?php echo htmlspecialchars($row['KD_BARANG']); ?>', '<?php echo htmlspecialchars($kd_lokasi); ?>')">Hitung POQ</button>
                                            <button class="btn-view btn-sm" onclick="pesanManual('<?php echo htmlspecialchars($row['KD_BARANG']); ?>', '<?php echo htmlspecialchars($kd_lokasi); ?>')">Pesan Manual</button>
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
                                        <option value="<?php echo htmlspecialchars($row['KD_BARANG']); ?>" 
                                                data-max-stock="<?php echo $row['JUMLAH_MAX_STOCK']; ?>">
                                            <?php echo htmlspecialchars($row['KD_BARANG'] . ' - ' . $row['NAMA_BARANG']); ?>
                                        </option>
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
                        
                        <div class="mb-3">
                            <label for="pesan_supplier" class="form-label">Supplier <span class="text-danger">*</span></label>
                            <select class="form-select" id="pesan_supplier" name="kd_supplier" required>
                                <option value="">-- Pilih Supplier --</option>
                                <?php 
                                // Reset result pointer untuk supplier
                                if ($result_supplier && $result_supplier->num_rows > 0) {
                                    $result_supplier->data_seek(0);
                                    while ($supplier = $result_supplier->fetch_assoc()): 
                                        $alamat_display = !empty($supplier['ALAMAT_SUPPLIER']) ? ' - ' . htmlspecialchars($supplier['ALAMAT_SUPPLIER']) : '';
                                    ?>
                                        <option value="<?php echo htmlspecialchars($supplier['KD_SUPPLIER']); ?>" 
                                                data-alamat="<?php echo htmlspecialchars($supplier['ALAMAT_SUPPLIER'] ?? ''); ?>"
                                                data-is-last="false">
                                            <?php echo htmlspecialchars($supplier['KD_SUPPLIER'] . ' - ' . $supplier['NAMA_SUPPLIER'] . $alamat_display); ?>
                                        </option>
                                    <?php endwhile;
                                } ?>
                            </select>
                            <small class="text-muted" id="supplier_alamat_display"></small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="pesan_stock_max" class="form-label">Stock maksimal (dus)</label>
                            <input type="number" class="form-control" id="pesan_stock_max" readonly style="background-color: #e9ecef;" disabled>
                        </div>
                        
                        <div class="mb-3">
                            <label for="pesan_stock_sekarang" class="form-label">Stock Saat Ini (dus)</label>
                            <input type="number" class="form-control" id="pesan_stock_sekarang" readonly style="background-color: #e9ecef;" disabled>
                        </div>
                        
                        <div class="mb-3">
                            <label for="pesan_stock_dipesan" class="form-label">Stock yg dipesan (dus) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="number" class="form-control" id="pesan_stock_dipesan" name="jumlah_dipesan" placeholder="0" min="0" required>
                                <button type="button" class="btn btn-outline-secondary" onclick="setMaxStock()">Max</button>
                            </div>
                            <small class="text-muted">Jumlah stock yang akan dipesan.</small>
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
                    { orderable: false, targets: 11 } // Disable sorting on Action column
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
            
            // Handle perubahan pilihan supplier di modal pesan manual
            $('#pesan_supplier').on('change', function() {
                var selectedOption = $(this).find('option:selected');
                var alamat = selectedOption.data('alamat') || '';
                
                if (alamat) {
                    $('#supplier_alamat_display').text('Alamat: ' + alamat);
                } else {
                    $('#supplier_alamat_display').text('');
                }
            });
            
            // Reset alamat display dan tanda supplier terakhir saat modal ditutup
            $('#modalPesanManual').on('hidden.bs.modal', function() {
                $('#supplier_alamat_display').text('');
                // Reset tanda supplier terakhir
                $('#pesan_supplier option').each(function() {
                    $(this).removeAttr('data-is-last');
                    var optionText = $(this).text();
                    if (optionText.includes('(Pesan Terakhir)')) {
                        $(this).text(optionText.replace(' (Pesan Terakhir)', ''));
                    }
                });
                $('#pesan_supplier').val('');
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
            window.location.href = 'riwayat_pembelian.php?kd_barang=' + encodeURIComponent(kdBarang);
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
                        $('#pesan_kd_barang').val(kdBarang);
                        $('#pesan_kd_lokasi').val(kdLokasi);
                        $('#pesan_stock_max').val(response.stock_max);
                        $('#pesan_stock_sekarang').val(response.stock_sekarang);
                        $('#pesan_stock_dipesan').val(0);
                        
                        // Reset tanda supplier terakhir
                        $('#pesan_supplier option').each(function() {
                            $(this).removeAttr('data-is-last');
                            var optionText = $(this).text();
                            // Hapus badge jika ada
                            if (optionText.includes('(Pesan Terakhir)')) {
                                $(this).text(optionText.replace(' (Pesan Terakhir)', ''));
                            }
                        });
                        
                        // Tandai dan auto-select supplier terakhir jika ada
                        if (response.last_supplier) {
                            var $lastSupplierOption = $('#pesan_supplier option[value="' + response.last_supplier + '"]');
                            if ($lastSupplierOption.length > 0) {
                                var originalText = $lastSupplierOption.text();
                                $lastSupplierOption.attr('data-is-last', 'true');
                                $lastSupplierOption.text(originalText + ' (Pesan Terakhir)');
                                $lastSupplierOption.prop('selected', true);
                                
                                // Trigger change untuk menampilkan alamat
                                $('#pesan_supplier').trigger('change');
                            }
                        }
                        
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
        
        function setMaxStock() {
            var stockMax = parseInt($('#pesan_stock_max').val()) || 0;
            var stockSekarang = parseInt($('#pesan_stock_sekarang').val()) || 0;
            var stockDipesan = stockMax - stockSekarang;
            
            if (stockDipesan < 0) {
                stockDipesan = 0;
            }
            
            $('#pesan_stock_dipesan').val(stockDipesan);
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

