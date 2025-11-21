<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/uuid_generator.php';

// Function untuk generate kode lokasi dengan format UUID
function generateKodeLokasi($conn, $type_lokasi) {
    // Generate UUID (8 karakter, tanpa prefix)
    // Pattern: generate > check > pass, generate > check (duplikat) > generate > check > pass
    $maxAttempts = 100;
    $attempt = 0;
    do {
        $kode = ShortIdGenerator::generate(8, '');
        $attempt++;
        if (!checkUUIDExists($conn, 'MASTER_LOKASI', 'KD_LOKASI', $kode)) {
            return $kode; // UUID unique, return
        }
    } while ($attempt < $maxAttempts);
    
    // Jika masih duplikat setelah 100 percobaan, return false
    return false;
}

// Handle form submission untuk tambah lokasi
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'tambah_lokasi') {
        $type_lokasi = trim($_POST['type_lokasi']);
        $nama_lokasi = trim($_POST['nama_lokasi']);
        $alamat_lokasi = trim($_POST['alamat_lokasi']);
        $max_stock_total = isset($_POST['max_stock_total']) ? intval($_POST['max_stock_total']) : 0;
        $satuan = trim($_POST['satuan']);
        
        if (!empty($type_lokasi) && !empty($nama_lokasi) && !empty($satuan)) {
            // Generate kode lokasi otomatis
            $kd_lokasi = generateKodeLokasi($conn, $type_lokasi);
            if ($kd_lokasi === false) {
                $message = 'Gagal generate kode lokasi! Silakan coba lagi.';
                $message_type = 'danger';
            } else {
                $status = 'AKTIF'; // Status langsung AKTIF
            
            // Insert data
            $insert_query = "INSERT INTO MASTER_LOKASI (KD_LOKASI, NAMA_LOKASI, TYPE_LOKASI, ALAMAT_LOKASI, MAX_STOCK_TOTAL, SATUAN, STATUS) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("ssssiss", $kd_lokasi, $nama_lokasi, $type_lokasi, $alamat_lokasi, $max_stock_total, $satuan, $status);
            
                if ($insert_stmt->execute()) {
                    // Setelah lokasi berhasil ditambahkan, tambahkan ke tabel STOCK untuk semua barang aktif
                    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
                    
                    // Ambil semua barang aktif
                    $query_barang = "SELECT KD_BARANG FROM MASTER_BARANG WHERE STATUS = 'AKTIF'";
                    $result_barang = $conn->query($query_barang);
                    
                    if ($result_barang && $result_barang->num_rows > 0) {
                        // Insert stock untuk setiap barang dengan nilai awal 0
                        $insert_stock_query = "INSERT INTO STOCK (KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_BARANG, SATUAN) VALUES (?, ?, ?, 0, ?)";
                        $insert_stock_stmt = $conn->prepare($insert_stock_query);
                        
                        while ($barang = $result_barang->fetch_assoc()) {
                            $kd_barang = $barang['KD_BARANG'];
                            
                            $insert_stock_stmt->bind_param("ssss", $kd_barang, $kd_lokasi, $user_id, $satuan);
                            if (!$insert_stock_stmt->execute()) {
                                // Log error jika ada, tapi tetap lanjutkan untuk barang lain
                                error_log("Gagal insert stock untuk barang: " . $kd_barang);
                            }
                        }
                        
                        $insert_stock_stmt->close();
                    }
                    
                    $message = 'Lokasi berhasil ditambahkan dengan kode: ' . $kd_lokasi;
                    $message_type = 'success';
                    // Redirect untuk mencegah resubmission
                    header("Location: master_lokasi.php?success=1&kd_lokasi=" . urlencode($kd_lokasi));
                    exit();
                } else {
                    $message = 'Gagal menambahkan lokasi!';
                    $message_type = 'danger';
                }
                $insert_stmt->close();
            }
        } else {
            $message = 'Tipe lokasi, nama lokasi, dan satuan harus diisi!';
            $message_type = 'danger';
        }
    } elseif ($_POST['action'] == 'edit_lokasi') {
        $kd_lokasi = trim($_POST['kd_lokasi']);
        $nama_lokasi = trim($_POST['nama_lokasi']);
        $alamat_lokasi = trim($_POST['alamat_lokasi']);
        $max_stock_total = isset($_POST['max_stock_total']) ? intval($_POST['max_stock_total']) : 0;
        $satuan = trim($_POST['satuan']);
        $status = trim($_POST['status']);
        
        if (!empty($kd_lokasi) && !empty($nama_lokasi) && !empty($satuan) && !empty($status)) {
            // Update data
            $update_query = "UPDATE MASTER_LOKASI SET NAMA_LOKASI = ?, ALAMAT_LOKASI = ?, MAX_STOCK_TOTAL = ?, SATUAN = ?, STATUS = ? WHERE KD_LOKASI = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ssisss", $nama_lokasi, $alamat_lokasi, $max_stock_total, $satuan, $status, $kd_lokasi);
            
            if ($update_stmt->execute()) {
                $message = 'Lokasi berhasil diperbarui';
                $message_type = 'success';
                // Redirect untuk mencegah resubmission
                header("Location: master_lokasi.php?success=2&kd_lokasi=" . urlencode($kd_lokasi));
                exit();
            } else {
                $message = 'Gagal memperbarui lokasi!';
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
    $message = 'Lokasi berhasil ditambahkan dengan kode: ' . htmlspecialchars($_GET['kd_lokasi'] ?? '');
    $message_type = 'success';
} elseif (isset($_GET['success']) && $_GET['success'] == '2') {
    $message = 'Lokasi berhasil diperbarui';
    $message_type = 'success';
}

// Query untuk mendapatkan data Lokasi dengan total stock sekarang
$query_lokasi = "SELECT 
                    ml.KD_LOKASI,
                    ml.TYPE_LOKASI,
                    ml.NAMA_LOKASI,
                    ml.ALAMAT_LOKASI,
                    COALESCE(SUM(s.JUMLAH_BARANG), 0) as TOTAL_STOCK_SEKARANG,
                    ml.MAX_STOCK_TOTAL,
                    ml.SATUAN,
                    ml.STATUS
                 FROM MASTER_LOKASI ml
                 LEFT JOIN STOCK s ON ml.KD_LOKASI = s.KD_LOKASI
                 GROUP BY ml.KD_LOKASI, ml.TYPE_LOKASI, ml.NAMA_LOKASI, ml.ALAMAT_LOKASI, ml.MAX_STOCK_TOTAL, ml.SATUAN, ml.STATUS
                 ORDER BY ml.KD_LOKASI ASC";
$result_lokasi = $conn->query($query_lokasi);

// Set active page untuk sidebar
$active_page = 'master_lokasi';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Master Lokasi</title>
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
            <h1 class="page-title">Pemilik - Master Lokasi</h1>
        </div>

        <!-- Add Button -->
        <div class="mb-3">
            <button type="button" class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalTambahLokasi">
                <i class="bi bi-plus-circle"></i> Tambahkan Lokasi
            </button>
        </div>

        <!-- Table Lokasi -->
        <div class="table-section" style="width: 100%;">
            <div class="table-responsive" style="width: 100%;">
                <table id="tableLokasi" class="table table-custom table-striped table-hover" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Kode Lokasi</th>
                            <th>Tipe Lokasi</th>
                            <th>Nama Lokasi</th>
                            <th>Alamat Lokasi</th>
                            <th>Total Stock Sekarang</th>
                            <th>Total Max Stock</th>
                            <th>Satuan</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_lokasi->num_rows > 0): ?>
                            <?php while ($row = $result_lokasi->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['KD_LOKASI']); ?></td>
                                    <td>
                                        <?php if (strtolower($row['TYPE_LOKASI']) == 'gudang'): ?>
                                            <span class="badge bg-primary">Gudang</span>
                                        <?php else: ?>
                                            <span class="badge bg-info">Toko</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['NAMA_LOKASI']); ?></td>
                                    <td><?php echo htmlspecialchars($row['ALAMAT_LOKASI'] ?? '-'); ?></td>
                                    <td><?php echo number_format($row['TOTAL_STOCK_SEKARANG'], 0, ',', '.'); ?></td>
                                    <td><?php echo number_format($row['MAX_STOCK_TOTAL'], 0, ',', '.'); ?></td>
                                    <td><?php echo htmlspecialchars($row['SATUAN']); ?></td>
                                    <td>
                                        <?php if ($row['STATUS'] == 'AKTIF'): ?>
                                            <span class="badge bg-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Tidak Aktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (strtolower($row['TYPE_LOKASI']) == 'gudang'): ?>
                                            <button class="btn-view btn-sm me-1" onclick="lihatBiayaOperasional('<?php echo htmlspecialchars($row['KD_LOKASI']); ?>')">Lihat Biaya Operasional</button>
                                            <button class="btn-view btn-sm" onclick="editLokasi('<?php echo htmlspecialchars($row['KD_LOKASI']); ?>', '<?php echo htmlspecialchars($row['TYPE_LOKASI']); ?>', '<?php echo htmlspecialchars($row['NAMA_LOKASI'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['ALAMAT_LOKASI'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['MAX_STOCK_TOTAL']); ?>', '<?php echo htmlspecialchars($row['SATUAN']); ?>', '<?php echo htmlspecialchars($row['STATUS']); ?>')">Edit</button>
                                        <?php else: ?>
                                            <button class="btn-view btn-sm" onclick="editLokasi('<?php echo htmlspecialchars($row['KD_LOKASI']); ?>', '<?php echo htmlspecialchars($row['TYPE_LOKASI']); ?>', '<?php echo htmlspecialchars($row['NAMA_LOKASI'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['ALAMAT_LOKASI'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['MAX_STOCK_TOTAL']); ?>', '<?php echo htmlspecialchars($row['SATUAN']); ?>', '<?php echo htmlspecialchars($row['STATUS']); ?>')">Edit</button>
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

    <!-- Modal Tambah Lokasi -->
    <div class="modal fade" id="modalTambahLokasi" tabindex="-1" aria-labelledby="modalTambahLokasiLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalTambahLokasiLabel">Tambahkan Lokasi</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formTambahLokasi" method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="tambah_lokasi">
                        
                        <div class="mb-3">
                            <label for="type_lokasi" class="form-label">Tipe Lokasi <span class="text-danger">*</span></label>
                            <select class="form-select" id="type_lokasi" name="type_lokasi" required>
                                <option value="">Pilih Tipe Lokasi</option>
                                <option value="gudang">Gudang</option>
                                <option value="toko">Toko</option>
                            </select>
                            <small class="text-muted">Kode lokasi akan dibuat otomatis dengan format UUID.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="nama_lokasi" class="form-label">Nama Lokasi <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="nama_lokasi" name="nama_lokasi" placeholder="Masukkan nama lokasi" maxlength="150" required autofocus>
                            <small class="text-muted">Maksimal 150 karakter.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="alamat_lokasi" class="form-label">Alamat Lokasi</label>
                            <textarea class="form-control" id="alamat_lokasi" name="alamat_lokasi" rows="3" placeholder="Masukkan alamat lokasi" maxlength="300"></textarea>
                            <small class="text-muted">Maksimal 300 karakter.</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="max_stock_total" class="form-label">Total Max Stock</label>
                                    <input type="number" class="form-control" id="max_stock_total" name="max_stock_total" placeholder="0" min="0" value="0">
                                    <small class="text-muted">Maksimal total stock yang dapat ditampung.</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="satuan" class="form-label">Satuan <span class="text-danger">*</span></label>
                                    <select class="form-select" id="satuan" name="satuan" required disabled>
                                        <option value="">Pilih Tipe Lokasi terlebih dahulu</option>
                                        <option value="PIECES">Pieces</option>
                                        <option value="DUS">Dus</option>
                                    </select>
                                    <small class="text-muted">Satuan akan otomatis di-set berdasarkan tipe lokasi (Gudang = Dus, Toko = Pieces).</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn-primary-custom">Simpan</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Edit Lokasi -->
    <div class="modal fade" id="modalEditLokasi" tabindex="-1" aria-labelledby="modalEditLokasiLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                    <h5 class="modal-title" id="modalEditLokasiLabel">Edit Lokasi</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formEditLokasi" method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_lokasi">
                        <input type="hidden" name="kd_lokasi" id="edit_kd_lokasi">
                        
                        <div class="mb-3">
                            <label for="edit_kd_lokasi_display" class="form-label">Kode Lokasi</label>
                            <input type="text" class="form-control" id="edit_kd_lokasi_display" readonly style="background-color: #e9ecef;">
                            <small class="text-muted">Kode lokasi tidak dapat diubah.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_type_lokasi_display" class="form-label">Tipe Lokasi</label>
                            <input type="text" class="form-control" id="edit_type_lokasi_display" readonly style="background-color: #e9ecef;">
                            <small class="text-muted">Tipe lokasi tidak dapat diubah.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_nama_lokasi" class="form-label">Nama Lokasi <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_nama_lokasi" name="nama_lokasi" placeholder="Masukkan nama lokasi" maxlength="150" required autofocus>
                            <small class="text-muted">Maksimal 150 karakter.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_alamat_lokasi" class="form-label">Alamat Lokasi</label>
                            <textarea class="form-control" id="edit_alamat_lokasi" name="alamat_lokasi" rows="3" placeholder="Masukkan alamat lokasi" maxlength="300"></textarea>
                            <small class="text-muted">Maksimal 300 karakter.</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_max_stock_total" class="form-label">Total Max Stock</label>
                                    <input type="number" class="form-control" id="edit_max_stock_total" name="max_stock_total" placeholder="0" min="0" value="0">
                                    <small class="text-muted">Maksimal total stock yang dapat ditampung.</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_satuan" class="form-label">Satuan <span class="text-danger">*</span></label>
                                    <select class="form-select" id="edit_satuan" name="satuan" required disabled>
                                        <option value="PIECES">Pieces</option>
                                        <option value="DUS">Dus</option>
                                    </select>
                                    <small class="text-muted">Satuan terkunci dan mengikuti tipe lokasi (Gudang = Dus, Toko = Pieces).</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_status" class="form-label">Status <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_status" name="status" required>
                                <option value="AKTIF">Aktif</option>
                                <option value="TIDAK AKTIF">Tidak Aktif</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn-primary-custom">Simpan Perubahan</button>
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
            
            $('#tableLokasi').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data lokasi'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[0, 'asc']], // Sort by Kode Lokasi
                columnDefs: [
                    { orderable: false, targets: 8 } // Disable sorting on Action column
                ],
                scrollX: true, // Enable horizontal scrolling for responsive
                autoWidth: false, // Disable auto width calculation
                width: '100%', // Set width to 100%
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
            
            // Auto-set satuan berdasarkan tipe lokasi (pasti, tidak bisa diubah)
            $('#type_lokasi').on('change', function() {
                var typeLokasi = $(this).val();
                var satuanSelect = $('#satuan');
                
                if (typeLokasi === 'gudang') {
                    satuanSelect.val('DUS'); // Pasti DUS untuk Gudang
                    // Tetap disabled agar user tidak bisa mengubah (nilai akan di-enable saat submit)
                } else if (typeLokasi === 'toko') {
                    satuanSelect.val('PIECES'); // Pasti PIECES untuk Toko
                    // Tetap disabled agar user tidak bisa mengubah (nilai akan di-enable saat submit)
                } else {
                    satuanSelect.val('').prop('disabled', true); // Disabled jika belum pilih tipe
                }
            });
        });

        // Flag untuk mencegah multiple submission
        var isSubmitting = false;
        
        // Form validation dan prevent multiple submission
        $('#formTambahLokasi').on('submit', function(e) {
            // Cegah multiple submission
            if (isSubmitting) {
                e.preventDefault();
                return false;
            }
            
            var typeLokasi = $('#type_lokasi').val();
            var namaLokasi = $('#nama_lokasi').val().trim();
            var satuan = $('#satuan').val();
            
            // Validasi
            if (!typeLokasi) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Tipe lokasi harus dipilih!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#type_lokasi').focus();
                });
                return false;
            }
            
            if (namaLokasi.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Nama lokasi harus diisi!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#nama_lokasi').focus();
                });
                return false;
            }
            
            if (namaLokasi.length > 150) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Nama Lokasi maksimal 150 karakter!',
                    confirmButtonColor: '#e74c3c'
                }).then(() => {
                    $('#nama_lokasi').focus();
                });
                return false;
            }
            
            if (!satuan) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Satuan harus dipilih!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#satuan').focus();
                });
                return false;
            }
            
            // Enable satuan sebelum submit agar nilainya terkirim (field disabled tidak terkirim)
            $('#satuan').prop('disabled', false);
            
            // Jika validasi berhasil, set flag dan disable button
            isSubmitting = true;
            var submitBtn = $(this).find('button[type="submit"]');
            var originalText = submitBtn.html();
            submitBtn.prop('disabled', true);
            submitBtn.html('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Menyimpan...');
            
            // Jika form gagal submit karena error lain, reset setelah 3 detik
            setTimeout(function() {
                if (isSubmitting) {
                    isSubmitting = false;
                    submitBtn.prop('disabled', false);
                    submitBtn.html(originalText);
                    // Kembalikan disabled state berdasarkan tipe lokasi
                    var typeLokasi = $('#type_lokasi').val();
                    if (typeLokasi) {
                        $('#satuan').prop('disabled', false);
                    } else {
                        $('#satuan').prop('disabled', true);
                    }
                }
            }, 3000);
        });
        
        // Reset form dan flag ketika modal ditutup
        $('#modalTambahLokasi').on('hidden.bs.modal', function () {
            $('#formTambahLokasi')[0].reset();
            isSubmitting = false;
            var submitBtn = $('#formTambahLokasi').find('button[type="submit"]');
            submitBtn.prop('disabled', false);
            submitBtn.html('Simpan');
            // Reset satuan ke disabled
            $('#satuan').prop('disabled', true).val('');
        });

        // Flag untuk mencegah multiple submission edit
        var isSubmittingEdit = false;
        
        function editLokasi(kdLokasi, typeLokasi, namaLokasi, alamatLokasi, maxStockTotal, satuan, status) {
            // Set nilai form edit
            $('#edit_kd_lokasi').val(kdLokasi);
            $('#edit_kd_lokasi_display').val(kdLokasi);
            
            // Set tipe lokasi (readonly display)
            var typeLokasiDisplay = typeLokasi === 'gudang' ? 'Gudang' : 'Toko';
            $('#edit_type_lokasi_display').val(typeLokasiDisplay);
            
            $('#edit_nama_lokasi').val(namaLokasi);
            $('#edit_alamat_lokasi').val(alamatLokasi || '');
            $('#edit_max_stock_total').val(maxStockTotal || 0);
            
            // Set satuan (disabled, mengikuti data yang sudah ada)
            $('#edit_satuan').val(satuan).prop('disabled', true);
            
            $('#edit_status').val(status);
            
            // Reset flag
            isSubmittingEdit = false;
            var submitBtn = $('#formEditLokasi').find('button[type="submit"]');
            submitBtn.prop('disabled', false);
            submitBtn.html('Simpan Perubahan');
            
            // Buka modal
            var modalEdit = new bootstrap.Modal(document.getElementById('modalEditLokasi'));
            modalEdit.show();
        }
        
        function lihatBiayaOperasional(kdLokasi) {
            // TODO: Implementasi halaman biaya operasional
            Swal.fire({
                icon: 'info',
                title: 'Biaya Operasional',
                text: 'Fitur ini akan segera tersedia untuk lokasi: ' + kdLokasi,
                confirmButtonColor: '#667eea'
            });
        }
        
        // Form validation dan prevent multiple submission untuk edit
        $('#formEditLokasi').on('submit', function(e) {
            // Cegah multiple submission
            if (isSubmittingEdit) {
                e.preventDefault();
                return false;
            }
            
            var namaLokasi = $('#edit_nama_lokasi').val().trim();
            var satuan = $('#edit_satuan').val();
            var status = $('#edit_status').val();
            
            // Validasi
            if (namaLokasi.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Nama lokasi harus diisi!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#edit_nama_lokasi').focus();
                });
                return false;
            }
            
            if (namaLokasi.length > 150) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Nama Lokasi maksimal 150 karakter!',
                    confirmButtonColor: '#e74c3c'
                }).then(() => {
                    $('#edit_nama_lokasi').focus();
                });
                return false;
            }
            
            if (!satuan) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Satuan harus dipilih!',
                    confirmButtonColor: '#667eea'
                });
                return false;
            }
            
            if (!status) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Status harus dipilih!',
                    confirmButtonColor: '#667eea'
                });
                return false;
            }
            
            // Enable satuan sebelum submit agar nilainya terkirim (field disabled tidak terkirim)
            $('#edit_satuan').prop('disabled', false);
            
            // Prevent default submit untuk menampilkan konfirmasi
            e.preventDefault();
            
            // Tampilkan konfirmasi
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi Perubahan',
                text: 'Apakah Anda yakin ingin menyimpan perubahan data lokasi ini?',
                showCancelButton: true,
                confirmButtonText: 'Ya, Simpan',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#667eea',
                cancelButtonColor: '#6c757d',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // Jika user konfirmasi, set flag dan disable button
                    isSubmittingEdit = true;
                    var submitBtn = $('#formEditLokasi').find('button[type="submit"]');
                    var originalText = submitBtn.html();
                    submitBtn.prop('disabled', true);
                    submitBtn.html('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Menyimpan...');
                    
                    // Submit form
                    $('#formEditLokasi')[0].submit();
                    
                    // Jika form gagal submit karena error lain, reset setelah 3 detik
                    setTimeout(function() {
                        if (isSubmittingEdit) {
                            isSubmittingEdit = false;
                            submitBtn.prop('disabled', false);
                            submitBtn.html(originalText);
                        }
                    }, 3000);
                } else {
                    // Jika user batal, kembalikan satuan ke disabled
                    $('#edit_satuan').prop('disabled', true);
                    // Form tetap terbuka dan user bisa melanjutkan editing
                }
            });
        });
        
        // Reset form dan flag ketika modal edit ditutup
        $('#modalEditLokasi').on('hidden.bs.modal', function () {
            $('#formEditLokasi')[0].reset();
            isSubmittingEdit = false;
            var submitBtn = $('#formEditLokasi').find('button[type="submit"]');
            submitBtn.prop('disabled', false);
            submitBtn.html('Simpan Perubahan');
            // Reset satuan ke disabled
            $('#edit_satuan').prop('disabled', true);
        });
    </script>
</body>
</html>

