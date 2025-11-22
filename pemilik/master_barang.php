<?php
session_start();
require_once '../dbconnect.php';
require_once '../includes/uuid_generator.php';

// Cek apakah user sudah login
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
    exit();
}

// Handle AJAX request untuk get data barang (untuk form edit)
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['get_barang_data'])) {
    header('Content-Type: application/json');
    
    $kd_barang = isset($_GET['kd_barang']) ? trim($_GET['kd_barang']) : '';
    
    if (empty($kd_barang)) {
        echo json_encode(['success' => false, 'message' => 'Kode barang tidak valid!']);
        exit();
    }
    
    // Query untuk mendapatkan data barang
    $query_barang_edit = "SELECT 
        mb.KD_BARANG,
        mb.KD_MEREK_BARANG,
        mb.KD_KATEGORI_BARANG,
        mb.NAMA_BARANG,
        mb.BERAT,
        mb.SATUAN_PERDUS,
        mb.AVG_HARGA_BELI,
        mb.HARGA_JUAL_BARANG,
        mb.STATUS
    FROM MASTER_BARANG mb
    WHERE mb.KD_BARANG = ?";
    $stmt_barang_edit = $conn->prepare($query_barang_edit);
    $stmt_barang_edit->bind_param("s", $kd_barang);
    $stmt_barang_edit->execute();
    $result_barang_edit = $stmt_barang_edit->get_result();
    
    if ($result_barang_edit->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Data barang tidak ditemukan!']);
        exit();
    }
    
    $barang_data = $result_barang_edit->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'kd_barang' => $barang_data['KD_BARANG'],
            'kd_merek' => $barang_data['KD_MEREK_BARANG'] ?? '',
            'kd_kategori' => $barang_data['KD_KATEGORI_BARANG'] ?? '',
            'nama_barang' => $barang_data['NAMA_BARANG'],
            'berat' => $barang_data['BERAT'] ?? 0,
            'satuan_perdus' => $barang_data['SATUAN_PERDUS'],
            'avg_harga_beli' => $barang_data['AVG_HARGA_BELI'] ?? 0,
            'harga_jual' => $barang_data['HARGA_JUAL_BARANG'] ?? 0,
            'status' => $barang_data['STATUS']
        ]
    ]);
    exit();
}

// Handle form submission untuk tambah barang
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'tambah_barang') {
        $kd_merek = trim($_POST['kd_merek']);
        $kd_kategori = trim($_POST['kd_kategori']);
        $nama_barang = trim($_POST['nama_barang']);
        $berat = isset($_POST['berat']) ? intval($_POST['berat']) : 0;
        $satuan_perdus = intval($_POST['satuan_perdus']);
        
        if (!empty($nama_barang) && $satuan_perdus > 0) {
            // Generate kode barang UUID (16 karakter, tanpa prefix)
            // Pattern: generate > check > pass, generate > check (duplikat) > generate > check > pass
            $maxAttempts = 100;
            $attempt = 0;
            do {
                $kd_barang = ShortIdGenerator::generate(16, '');
                $attempt++;
                if (!checkUUIDExists($conn, 'MASTER_BARANG', 'KD_BARANG', $kd_barang)) {
                    break; // UUID unique, keluar dari loop
                }
            } while ($attempt < $maxAttempts);
            
            if ($attempt >= $maxAttempts) {
                $message = 'Gagal generate kode barang! Silakan coba lagi.';
                $message_type = 'danger';
            } else {
                $status = 'AKTIF'; // Status langsung AKTIF
            
            // Set NULL jika kosong
            $kd_merek = !empty($kd_merek) ? $kd_merek : null;
            $kd_kategori = !empty($kd_kategori) ? $kd_kategori : null;
            
                // Insert data
                $insert_query = "INSERT INTO MASTER_BARANG (KD_BARANG, KD_MEREK_BARANG, KD_KATEGORI_BARANG, NAMA_BARANG, BERAT, SATUAN_PERDUS, STATUS) VALUES (?, ?, ?, ?, ?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bind_param("ssssiis", $kd_barang, $kd_merek, $kd_kategori, $nama_barang, $berat, $satuan_perdus, $status);
                
                if ($insert_stmt->execute()) {
                    // Setelah barang berhasil ditambahkan, tambahkan ke tabel STOCK untuk semua lokasi aktif
                    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
                    
                    // Ambil semua lokasi aktif
                    $query_lokasi = "SELECT KD_LOKASI, SATUAN FROM MASTER_LOKASI WHERE STATUS = 'AKTIF'";
                    $result_lokasi = $conn->query($query_lokasi);
                    
                    if ($result_lokasi && $result_lokasi->num_rows > 0) {
                        // Insert stock untuk setiap lokasi dengan nilai awal 0
                        $insert_stock_query = "INSERT INTO STOCK (KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_BARANG, SATUAN) VALUES (?, ?, ?, 0, ?)";
                        $insert_stock_stmt = $conn->prepare($insert_stock_query);
                        
                        while ($lokasi = $result_lokasi->fetch_assoc()) {
                            $kd_lokasi = $lokasi['KD_LOKASI'];
                            $satuan_lokasi = $lokasi['SATUAN']; // PIECES atau DUS sesuai lokasi
                            
                            $insert_stock_stmt->bind_param("ssss", $kd_barang, $kd_lokasi, $user_id, $satuan_lokasi);
                            if (!$insert_stock_stmt->execute()) {
                                // Log error jika ada, tapi tetap lanjutkan untuk lokasi lain
                                error_log("Gagal insert stock untuk lokasi: " . $kd_lokasi);
                            }
                        }
                        
                        $insert_stock_stmt->close();
                    }
                    
                    $message = 'Barang berhasil ditambahkan dengan kode: ' . $kd_barang;
                    $message_type = 'success';
                    // Redirect untuk mencegah resubmission
                    header("Location: master_barang.php?success=1&kd_barang=" . urlencode($kd_barang));
                    exit();
                } else {
                    $message = 'Gagal menambahkan barang!';
                    $message_type = 'danger';
                }
                $insert_stmt->close();
            }
        } else {
            $message = 'Nama barang dan satuan per dus harus diisi!';
            $message_type = 'danger';
        }
    } elseif ($_POST['action'] == 'edit_barang') {
        $kd_barang = trim($_POST['kd_barang']);
        $kd_merek = trim($_POST['kd_merek']);
        $kd_kategori = trim($_POST['kd_kategori']);
        $nama_barang = trim($_POST['nama_barang']);
        $berat = isset($_POST['berat']) ? intval($_POST['berat']) : 0;
        $satuan_perdus = intval($_POST['satuan_perdus']);
        $harga_jual = floatval($_POST['harga_jual']);
        $status = trim($_POST['status']);
        
        if (!empty($kd_barang) && !empty($nama_barang) && $satuan_perdus > 0 && !empty($status)) {
            // Set NULL jika kosong
            $kd_merek = !empty($kd_merek) ? $kd_merek : null;
            $kd_kategori = !empty($kd_kategori) ? $kd_kategori : null;
            
            // Update data (AVG_HARGA_BELI tidak diupdate karena readonly)
            $update_query = "UPDATE MASTER_BARANG SET KD_MEREK_BARANG = ?, KD_KATEGORI_BARANG = ?, NAMA_BARANG = ?, BERAT = ?, SATUAN_PERDUS = ?, HARGA_JUAL_BARANG = ?, STATUS = ? WHERE KD_BARANG = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("sssiidss", $kd_merek, $kd_kategori, $nama_barang, $berat, $satuan_perdus, $harga_jual, $status, $kd_barang);
            
            if ($update_stmt->execute()) {
                $message = 'Barang berhasil diperbarui';
                $message_type = 'success';
                // Redirect untuk mencegah resubmission
                header("Location: master_barang.php?success=2&kd_barang=" . urlencode($kd_barang));
                exit();
            } else {
                $message = 'Gagal memperbarui barang!';
                $message_type = 'danger';
            }
            $update_stmt->close();
        } else {
            $message = 'Semua field wajib harus diisi!';
            $message_type = 'danger';
        }
    }
}

// Handle success message dari redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $message = 'Barang berhasil ditambahkan dengan kode: ' . htmlspecialchars($_GET['kd_barang'] ?? '');
    $message_type = 'success';
} elseif (isset($_GET['success']) && $_GET['success'] == '2') {
    $message = 'Barang berhasil diperbarui';
    $message_type = 'success';
}

// Query untuk mendapatkan data Barang dengan join ke master data
$query_barang = "SELECT 
                    mb.KD_BARANG,
                    mb.KD_MEREK_BARANG,
                    mb.KD_KATEGORI_BARANG,
                    mb.NAMA_BARANG,
                    mb.BERAT,
                    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
                    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
                    mb.SATUAN_PERDUS,
                    mb.AVG_HARGA_BELI,
                    mb.HARGA_JUAL_BARANG,
                    mb.LAST_UPDATED,
                    mb.STATUS
                 FROM MASTER_BARANG mb
                 LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
                 LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
                 ORDER BY mb.KD_BARANG ASC";
$result_barang = $conn->query($query_barang);

// Query untuk mendapatkan data Merek (untuk dropdown)
$query_merek = "SELECT KD_MEREK_BARANG, NAMA_MEREK 
                FROM MASTER_MEREK 
                WHERE STATUS = 'AKTIF'
                ORDER BY NAMA_MEREK ASC";
$result_merek = $conn->query($query_merek);

// Query untuk mendapatkan data Kategori (untuk dropdown)
$query_kategori = "SELECT KD_KATEGORI_BARANG, NAMA_KATEGORI 
                   FROM MASTER_KATEGORI_BARANG 
                   WHERE STATUS = 'AKTIF'
                   ORDER BY NAMA_KATEGORI ASC";
$result_kategori = $conn->query($query_kategori);

// Set active page untuk sidebar
$active_page = 'master_barang';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Master Barang</title>
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
            <h1 class="page-title">Pemilik - Master Barang</h1>
        </div>

        <!-- Action Button -->
        <div class="mb-3">
            <button type="button" class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalTambahBarang">
                Tambahkan Barang
            </button>
        </div>

        <!-- Table Section -->
        <div class="table-section" style="width: 100%;">
            <div class="table-responsive" style="width: 100%;">
                <table id="tableBarang" class="table table-custom table-striped table-hover" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Kode Barang</th>
                            <th>Merek Barang</th>
                            <th>Kategori Barang</th>
                            <th>Nama Barang</th>
                            <th>Berat (gr)</th>
                            <th>Satuan Per Dus</th>
                            <th>AVG Harga Beli</th>
                            <th>Harga Jual</th>
                            <th>Terakhir Update</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_barang->num_rows > 0): ?>
                            <?php while ($row = $result_barang->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['KD_BARANG']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_MEREK']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_BARANG']); ?></td>
                                    <td><?php echo number_format($row['BERAT'], 0, ',', '.'); ?></td>
                                    <td><?php echo number_format($row['SATUAN_PERDUS'], 0, ',', '.'); ?></td>
                                    <td>Rp <?php echo number_format($row['AVG_HARGA_BELI'], 0, ',', '.'); ?></td>
                                    <td>Rp <?php echo number_format($row['HARGA_JUAL_BARANG'], 0, ',', '.'); ?></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($row['LAST_UPDATED'])); ?> WIB</td>
                                    <td>
                                        <span class="badge <?php echo $row['STATUS'] == 'AKTIF' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo htmlspecialchars($row['STATUS']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button class="btn-view" onclick="openEditModal('<?php echo htmlspecialchars($row['KD_BARANG'], ENT_QUOTES); ?>')">Edit</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Tambah Barang -->
    <div class="modal fade" id="modalTambahBarang" tabindex="-1" aria-labelledby="modalTambahBarangLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalTambahBarangLabel">Tambahkan Barang</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formTambahBarang" method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="tambah_barang">
                        
                        <div class="mb-3">
                            <label for="kd_merek" class="form-label">Merek Barang</label>
                            <select class="form-select" id="kd_merek" name="kd_merek">
                                <option value="">-- Pilih Merek --</option>
                                <?php if ($result_merek->num_rows > 0): ?>
                                    <?php while ($merek = $result_merek->fetch_assoc()): ?>
                                        <option value="<?php echo htmlspecialchars($merek['KD_MEREK_BARANG']); ?>">
                                            <?php echo htmlspecialchars($merek['NAMA_MEREK']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                            <small class="text-muted">Pilih merek barang (opsional).</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="kd_kategori" class="form-label">Kategori Barang</label>
                            <select class="form-select" id="kd_kategori" name="kd_kategori">
                                <option value="">-- Pilih Kategori --</option>
                                <?php if ($result_kategori->num_rows > 0): ?>
                                    <?php 
                                    // Reset pointer result untuk kategori
                                    $result_kategori->data_seek(0);
                                    while ($kategori = $result_kategori->fetch_assoc()): 
                                    ?>
                                        <option value="<?php echo htmlspecialchars($kategori['KD_KATEGORI_BARANG']); ?>">
                                            <?php echo htmlspecialchars($kategori['NAMA_KATEGORI']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                            <small class="text-muted">Pilih kategori barang (opsional).</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="nama_barang" class="form-label">Nama Barang <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="nama_barang" name="nama_barang" placeholder="Masukkan nama barang" maxlength="150" required autofocus>
                            <small class="text-muted">Maksimal 150 karakter. Kode barang akan dibuat otomatis (UUID).</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="berat" class="form-label">Berat (gr)</label>
                            <input type="number" class="form-control" id="berat" name="berat" placeholder="Masukkan berat dalam gram" min="0" value="0">
                            <small class="text-muted">Berat barang dalam gram (opsional).</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="satuan_perdus" class="form-label">Satuan Per Dus <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="satuan_perdus" name="satuan_perdus" placeholder="Masukkan satuan per dus" min="1" required>
                            <small class="text-muted">Jumlah satuan dalam 1 dus (minimal 1).</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn-primary-custom" id="btnSimpanTambah">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Edit Barang -->
    <div class="modal fade" id="modalEditBarang" tabindex="-1" aria-labelledby="modalEditBarangLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                    <h5 class="modal-title" id="modalEditBarangLabel">Edit Barang</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formEditBarang" method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_barang">
                        <input type="hidden" name="kd_barang" id="edit_kd_barang">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_kd_barang_display" class="form-label">Kode Barang</label>
                                    <input type="text" class="form-control form-control-sm" id="edit_kd_barang_display" readonly style="background-color: #e9ecef;">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_status" class="form-label">Status <span class="text-danger">*</span></label>
                                    <select class="form-select form-select-sm" id="edit_status" name="status" required>
                                        <option value="AKTIF">Aktif</option>
                                        <option value="TIDAK AKTIF">Tidak Aktif</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_kd_merek" class="form-label">Merek Barang</label>
                                    <select class="form-select form-select-sm" id="edit_kd_merek" name="kd_merek">
                                        <option value="">-- Pilih Merek --</option>
                                        <?php 
                                        // Reset pointer result untuk merek
                                        $result_merek->data_seek(0);
                                        if ($result_merek->num_rows > 0): ?>
                                            <?php while ($merek = $result_merek->fetch_assoc()): ?>
                                                <option value="<?php echo htmlspecialchars($merek['KD_MEREK_BARANG']); ?>">
                                                    <?php echo htmlspecialchars($merek['NAMA_MEREK']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_kd_kategori" class="form-label">Kategori Barang</label>
                                    <select class="form-select form-select-sm" id="edit_kd_kategori" name="kd_kategori">
                                        <option value="">-- Pilih Kategori --</option>
                                        <?php 
                                        // Reset pointer result untuk kategori
                                        $result_kategori->data_seek(0);
                                        if ($result_kategori->num_rows > 0): ?>
                                            <?php while ($kategori = $result_kategori->fetch_assoc()): ?>
                                                <option value="<?php echo htmlspecialchars($kategori['KD_KATEGORI_BARANG']); ?>">
                                                    <?php echo htmlspecialchars($kategori['NAMA_KATEGORI']); ?>
                                                </option>
                                            <?php endwhile; ?>
                                        <?php endif; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_nama_barang" class="form-label">Nama Barang <span class="text-danger">*</span></label>
                            <input type="text" class="form-control form-control-sm" id="edit_nama_barang" name="nama_barang" placeholder="Masukkan nama barang" maxlength="150" required autofocus>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_berat" class="form-label">Berat (gr)</label>
                                    <input type="number" class="form-control form-control-sm" id="edit_berat" name="berat" placeholder="0" min="0" value="0">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_satuan_perdus" class="form-label">Satuan Per Dus <span class="text-danger">*</span></label>
                                    <input type="number" class="form-control form-control-sm" id="edit_satuan_perdus" name="satuan_perdus" placeholder="0" min="1" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_avg_harga_beli" class="form-label">AVG Harga Beli</label>
                                    <input type="text" class="form-control form-control-sm" id="edit_avg_harga_beli" readonly style="background-color: #e9ecef;" disabled>
                                    <small class="text-muted" style="font-size: 0.75rem;">AVG Harga Beli tidak dapat diubah (dihitung otomatis dari transaksi pembelian).</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_harga_jual" class="form-label">Harga Jual</label>
                                    <input type="number" class="form-control form-control-sm" id="edit_harga_jual" name="harga_jual" placeholder="0" min="0" step="0.01" value="0">
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn-primary-custom btn-sm" id="btnSimpanEdit">Simpan Perubahan</button>
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
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Sidebar Script -->
    <script src="includes/sidebar.js"></script>
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
            
            $('#tableBarang').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data barang'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[0, 'asc']], // Sort by Kode Barang
                scrollX: true,
                autoWidth: false,
                width: '100%',
                columnDefs: [
                    { orderable: false, targets: 10 } // Disable sorting on Action column
                ],
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

        // Flag untuk mencegah multiple submission
        var isSubmitting = false;
        var isSubmittingEdit = false;
        
        // Form validation dan prevent multiple submission - Tambah
        $('#formTambahBarang').on('submit', function(e) {
            if (isSubmitting) {
                e.preventDefault();
                return false;
            }
            
            var namaBarang = $('#nama_barang').val().trim();
            var satuanPerdus = $('#satuan_perdus').val();
            
            if (namaBarang.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Nama barang harus diisi!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#nama_barang').focus();
                });
                return false;
            }
            
            if (satuanPerdus <= 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Satuan per dus harus lebih dari 0!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#satuan_perdus').focus();
                });
                return false;
            }
            
            isSubmitting = true;
            $('#btnSimpanTambah').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...');
        });
        
        // Reset flag saat modal ditutup
        $('#modalTambahBarang').on('hidden.bs.modal', function() {
            isSubmitting = false;
            $('#btnSimpanTambah').prop('disabled', false).html('Simpan');
            $('#formTambahBarang')[0].reset();
        });
        
        // Form validation dan prevent multiple submission - Edit
        $('#formEditBarang').on('submit', function(e) {
            if (isSubmittingEdit) {
                e.preventDefault();
                return false;
            }
            
            var namaBarang = $('#edit_nama_barang').val().trim();
            var satuanPerdus = $('#edit_satuan_perdus').val();
            
            if (namaBarang.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Nama barang harus diisi!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#edit_nama_barang').focus();
                });
                return false;
            }
            
            if (satuanPerdus <= 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Satuan per dus harus lebih dari 0!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#edit_satuan_perdus').focus();
                });
                return false;
            }
            
            // Konfirmasi dengan SweetAlert
            e.preventDefault();
            Swal.fire({
                title: 'Konfirmasi',
                text: 'Apakah Anda yakin ingin menyimpan perubahan?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Ya, Simpan',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    isSubmittingEdit = true;
                    $('#btnSimpanEdit').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...');
                    $('#formEditBarang')[0].submit();
                }
            });
        });
        
        // Reset flag saat modal ditutup
        $('#modalEditBarang').on('hidden.bs.modal', function() {
            isSubmittingEdit = false;
            $('#btnSimpanEdit').prop('disabled', false).html('Simpan Perubahan');
        });
        
        // Function untuk membuka modal edit barang
        function openEditModal(kdBarang) {
            // AJAX untuk mengambil data barang
            $.ajax({
                url: 'master_barang.php',
                method: 'GET',
                data: {
                    get_barang_data: '1',
                    kd_barang: kdBarang
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var data = response.data;
                        
                        // Set nilai form
                        $('#edit_kd_barang').val(data.kd_barang);
                        $('#edit_kd_barang_display').val(data.kd_barang);
                        $('#edit_kd_merek').val(data.kd_merek || '');
                        $('#edit_kd_kategori').val(data.kd_kategori || '');
                        $('#edit_nama_barang').val(data.nama_barang);
                        $('#edit_berat').val(data.berat || 0);
                        $('#edit_satuan_perdus').val(data.satuan_perdus || 1);
                        
                        // Format AVG Harga Beli dengan rupiah
                        var avgHargaBeliNum = parseFloat(data.avg_harga_beli) || 0;
                        var avgHargaBeliFormatted = avgHargaBeliNum > 0 
                            ? 'Rp ' + avgHargaBeliNum.toLocaleString('id-ID') 
                            : 'Rp 0';
                        $('#edit_avg_harga_beli').val(avgHargaBeliFormatted);
                        
                        $('#edit_harga_jual').val(data.harga_jual || 0);
                        $('#edit_status').val(data.status || 'AKTIF');
                        
                        // Buka modal menggunakan Bootstrap 5
                        var modalElement = document.getElementById('modalEditBarang');
                        var modal = new bootstrap.Modal(modalElement);
                        modal.show();
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: response.message || 'Gagal mengambil data barang!',
                            confirmButtonColor: '#e74c3c'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Terjadi kesalahan saat mengambil data barang!',
                        confirmButtonColor: '#e74c3c'
                    });
                }
            });
        }
    </script>
</body>
</html>

