<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/uuid_generator.php';

// Function untuk generate kode supplier dengan format UUID
function generateKodeSupplier($conn) {
    // Generate UUID (8 karakter, tanpa prefix)
    // Pattern: generate > check > pass, generate > check (duplikat) > generate > check > pass
    $maxAttempts = 100;
    $attempt = 0;
    do {
        $kode = ShortIdGenerator::generate(8, '');
        $attempt++;
        if (!checkUUIDExists($conn, 'MASTER_SUPPLIER', 'KD_SUPPLIER', $kode)) {
            return $kode; // UUID unique, return
        }
    } while ($attempt < $maxAttempts);
    
    // Jika masih duplikat setelah 100 percobaan, return false
    return false;
}

// Handle form submission untuk tambah supplier
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'tambah_supplier') {
        $nama_supplier = trim($_POST['nama_supplier']);
        $alamat_supplier = trim($_POST['alamat_supplier']);
        $pic_supplier = trim($_POST['pic_supplier']);
        $notelp_supplier = trim($_POST['notelp_supplier']);
        
        if (!empty($nama_supplier)) {
            // Generate kode supplier otomatis
            $kd_supplier = generateKodeSupplier($conn);
            if ($kd_supplier === false) {
                $message = 'Gagal generate kode supplier! Silakan coba lagi.';
                $message_type = 'danger';
            } else {
                $status = 'AKTIF'; // Status langsung AKTIF
            
            // Insert data
            $insert_query = "INSERT INTO MASTER_SUPPLIER (KD_SUPPLIER, NAMA_SUPPLIER, ALAMAT_SUPPLIER, PIC_SUPPLIER, NOTELP_SUPPLIER, STATUS) VALUES (?, ?, ?, ?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("ssssss", $kd_supplier, $nama_supplier, $alamat_supplier, $pic_supplier, $notelp_supplier, $status);
            
                if ($insert_stmt->execute()) {
                    $message = 'Supplier berhasil ditambahkan dengan kode: ' . $kd_supplier;
                    $message_type = 'success';
                    // Redirect untuk mencegah resubmission
                    header("Location: master_supplier.php?success=1&kd_supplier=" . urlencode($kd_supplier));
                    exit();
                } else {
                    $message = 'Gagal menambahkan supplier!';
                    $message_type = 'danger';
                }
                $insert_stmt->close();
            }
        } else {
            $message = 'Nama supplier harus diisi!';
            $message_type = 'danger';
        }
    } elseif ($_POST['action'] == 'edit_supplier') {
        $kd_supplier = trim($_POST['kd_supplier']);
        $nama_supplier = trim($_POST['nama_supplier']);
        $alamat_supplier = trim($_POST['alamat_supplier']);
        $pic_supplier = trim($_POST['pic_supplier']);
        $notelp_supplier = trim($_POST['notelp_supplier']);
        $status = trim($_POST['status']);
        
        if (!empty($kd_supplier) && !empty($nama_supplier) && !empty($status)) {
            // Update data
            $update_query = "UPDATE MASTER_SUPPLIER SET NAMA_SUPPLIER = ?, ALAMAT_SUPPLIER = ?, PIC_SUPPLIER = ?, NOTELP_SUPPLIER = ?, STATUS = ? WHERE KD_SUPPLIER = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("ssssss", $nama_supplier, $alamat_supplier, $pic_supplier, $notelp_supplier, $status, $kd_supplier);
            
            if ($update_stmt->execute()) {
                $message = 'Supplier berhasil diperbarui';
                $message_type = 'success';
                // Redirect untuk mencegah resubmission
                header("Location: master_supplier.php?success=2&kd_supplier=" . urlencode($kd_supplier));
                exit();
            } else {
                $message = 'Gagal memperbarui supplier!';
                $message_type = 'danger';
            }
            $update_stmt->close();
        } else {
            $message = 'Nama supplier dan status harus diisi!';
            $message_type = 'danger';
        }
    }
}

// Handle success message dari redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $message = 'Supplier berhasil ditambahkan dengan kode: ' . htmlspecialchars($_GET['kd_supplier'] ?? '');
    $message_type = 'success';
} elseif (isset($_GET['success']) && $_GET['success'] == '2') {
    $message = 'Supplier berhasil diperbarui';
    $message_type = 'success';
}

// Query untuk mendapatkan data Supplier
$query_supplier = "SELECT KD_SUPPLIER, NAMA_SUPPLIER, ALAMAT_SUPPLIER, PIC_SUPPLIER, NOTELP_SUPPLIER, STATUS 
                   FROM MASTER_SUPPLIER 
                   ORDER BY KD_SUPPLIER ASC";
$result_supplier = $conn->query($query_supplier);

// Set active page untuk sidebar
$active_page = 'master_supplier';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Master Supplier</title>
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
            <h1 class="page-title">Pemilik - Supplier</h1>
        </div>

        <!-- Add Button -->
        <div class="mb-3">
            <button type="button" class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalTambahSupplier">
                <i class="bi bi-plus-circle"></i> Tambahkan Supplier
            </button>
        </div>

        <!-- Table Supplier -->
        <div class="table-section" style="width: 100%;">
            <div class="table-responsive" style="width: 100%;">
                <table id="tableSupplier" class="table table-custom table-striped table-hover" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Kode Supplier</th>
                            <th>Nama Supplier</th>
                            <th>Alamat</th>
                            <th>PIC</th>
                            <th>Nomor Telepon</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_supplier->num_rows > 0): ?>
                            <?php while ($row = $result_supplier->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['KD_SUPPLIER']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_SUPPLIER']); ?></td>
                                    <td><?php echo htmlspecialchars($row['ALAMAT_SUPPLIER'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($row['PIC_SUPPLIER'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($row['NOTELP_SUPPLIER'] ?? '-'); ?></td>
                                    <td>
                                        <?php if ($row['STATUS'] == 'AKTIF'): ?>
                                            <span class="badge bg-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Tidak Aktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn-view" onclick="editSupplier('<?php echo htmlspecialchars($row['KD_SUPPLIER']); ?>', '<?php echo htmlspecialchars($row['NAMA_SUPPLIER'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['ALAMAT_SUPPLIER'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['PIC_SUPPLIER'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['NOTELP_SUPPLIER'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['STATUS']); ?>')">Edit</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Tambah Supplier -->
    <div class="modal fade" id="modalTambahSupplier" tabindex="-1" aria-labelledby="modalTambahSupplierLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalTambahSupplierLabel">Tambahkan Supplier</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formTambahSupplier" method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="tambah_supplier">
                        
                        <div class="mb-3">
                            <label for="nama_supplier" class="form-label">Nama Supplier <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="nama_supplier" name="nama_supplier" placeholder="Masukkan nama supplier" maxlength="150" required autofocus>
                            <small class="text-muted">Maksimal 150 karakter. Kode supplier akan dibuat otomatis dengan format UUID.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="alamat_supplier" class="form-label">Alamat</label>
                            <textarea class="form-control" id="alamat_supplier" name="alamat_supplier" rows="3" placeholder="Masukkan alamat supplier" maxlength="300"></textarea>
                            <small class="text-muted">Maksimal 300 karakter.</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="pic_supplier" class="form-label">PIC (Person In Charge)</label>
                                    <input type="text" class="form-control" id="pic_supplier" name="pic_supplier" placeholder="Masukkan nama PIC" maxlength="100">
                                    <small class="text-muted">Maksimal 100 karakter.</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="notelp_supplier" class="form-label">Nomor Telepon</label>
                                    <input type="text" class="form-control" id="notelp_supplier" name="notelp_supplier" placeholder="Masukkan nomor telepon" maxlength="20">
                                    <small class="text-muted">Maksimal 20 karakter.</small>
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

    <!-- Modal Edit Supplier -->
    <div class="modal fade" id="modalEditSupplier" tabindex="-1" aria-labelledby="modalEditSupplierLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                    <h5 class="modal-title" id="modalEditSupplierLabel">Edit Supplier</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formEditSupplier" method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_supplier">
                        <input type="hidden" name="kd_supplier" id="edit_kd_supplier">
                        
                        <div class="mb-3">
                            <label for="edit_kd_supplier_display" class="form-label">Kode Supplier</label>
                            <input type="text" class="form-control" id="edit_kd_supplier_display" readonly style="background-color: #e9ecef;">
                            <small class="text-muted">Kode supplier tidak dapat diubah.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_nama_supplier" class="form-label">Nama Supplier <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_nama_supplier" name="nama_supplier" placeholder="Masukkan nama supplier" maxlength="150" required autofocus>
                            <small class="text-muted">Maksimal 150 karakter.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_alamat_supplier" class="form-label">Alamat</label>
                            <textarea class="form-control" id="edit_alamat_supplier" name="alamat_supplier" rows="3" placeholder="Masukkan alamat supplier" maxlength="300"></textarea>
                            <small class="text-muted">Maksimal 300 karakter.</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_pic_supplier" class="form-label">PIC (Person In Charge)</label>
                                    <input type="text" class="form-control" id="edit_pic_supplier" name="pic_supplier" placeholder="Masukkan nama PIC" maxlength="100">
                                    <small class="text-muted">Maksimal 100 karakter.</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_notelp_supplier" class="form-label">Nomor Telepon</label>
                                    <input type="text" class="form-control" id="edit_notelp_supplier" name="notelp_supplier" placeholder="Masukkan nomor telepon" maxlength="20">
                                    <small class="text-muted">Maksimal 20 karakter.</small>
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
            
            $('#tableSupplier').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data supplier'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[0, 'asc']], // Sort by Kode Supplier
                columnDefs: [
                    { orderable: false, targets: 6 } // Disable sorting on Action column
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
        });

        // Flag untuk mencegah multiple submission
        var isSubmitting = false;
        
        // Form validation dan prevent multiple submission
        $('#formTambahSupplier').on('submit', function(e) {
            // Cegah multiple submission
            if (isSubmitting) {
                e.preventDefault();
                return false;
            }
            
            var namaSupplier = $('#nama_supplier').val().trim();
            
            // Validasi
            if (namaSupplier.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Nama supplier harus diisi!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#nama_supplier').focus();
                });
                return false;
            }
            
            if (namaSupplier.length > 150) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Nama Supplier maksimal 150 karakter!',
                    confirmButtonColor: '#e74c3c'
                }).then(() => {
                    $('#nama_supplier').focus();
                });
                return false;
            }
            
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
                }
            }, 3000);
        });
        
        // Reset form dan flag ketika modal ditutup
        $('#modalTambahSupplier').on('hidden.bs.modal', function () {
            $('#formTambahSupplier')[0].reset();
            isSubmitting = false;
            var submitBtn = $('#formTambahSupplier').find('button[type="submit"]');
            submitBtn.prop('disabled', false);
            submitBtn.html('Simpan');
        });

        // Flag untuk mencegah multiple submission edit
        var isSubmittingEdit = false;
        
        function editSupplier(kdSupplier, namaSupplier, alamatSupplier, picSupplier, notelpSupplier, status) {
            // Set nilai form edit
            $('#edit_kd_supplier').val(kdSupplier);
            $('#edit_kd_supplier_display').val(kdSupplier);
            $('#edit_nama_supplier').val(namaSupplier);
            $('#edit_alamat_supplier').val(alamatSupplier || '');
            $('#edit_pic_supplier').val(picSupplier || '');
            $('#edit_notelp_supplier').val(notelpSupplier || '');
            $('#edit_status').val(status);
            
            // Reset flag
            isSubmittingEdit = false;
            var submitBtn = $('#formEditSupplier').find('button[type="submit"]');
            submitBtn.prop('disabled', false);
            submitBtn.html('Simpan Perubahan');
            
            // Buka modal
            var modalEdit = new bootstrap.Modal(document.getElementById('modalEditSupplier'));
            modalEdit.show();
        }
        
        // Form validation dan prevent multiple submission untuk edit
        $('#formEditSupplier').on('submit', function(e) {
            // Cegah multiple submission
            if (isSubmittingEdit) {
                e.preventDefault();
                return false;
            }
            
            var namaSupplier = $('#edit_nama_supplier').val().trim();
            var status = $('#edit_status').val();
            
            // Validasi
            if (namaSupplier.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Nama supplier harus diisi!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#edit_nama_supplier').focus();
                });
                return false;
            }
            
            if (namaSupplier.length > 150) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Nama Supplier maksimal 150 karakter!',
                    confirmButtonColor: '#e74c3c'
                }).then(() => {
                    $('#edit_nama_supplier').focus();
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
            
            // Prevent default submit untuk menampilkan konfirmasi
            e.preventDefault();
            
            // Tampilkan konfirmasi
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi Perubahan',
                text: 'Apakah Anda yakin ingin menyimpan perubahan data supplier ini?',
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
                    var submitBtn = $('#formEditSupplier').find('button[type="submit"]');
                    var originalText = submitBtn.html();
                    submitBtn.prop('disabled', true);
                    submitBtn.html('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Menyimpan...');
                    
                    // Submit form
                    $('#formEditSupplier')[0].submit();
                    
                    // Jika form gagal submit karena error lain, reset setelah 3 detik
                    setTimeout(function() {
                        if (isSubmittingEdit) {
                            isSubmittingEdit = false;
                            submitBtn.prop('disabled', false);
                            submitBtn.html(originalText);
                        }
                    }, 3000);
                } else {
                    // Jika user batal, tidak perlu melakukan apa-apa
                    // Form tetap terbuka dan user bisa melanjutkan editing
                }
            });
        });
        
        // Reset form dan flag ketika modal edit ditutup
        $('#modalEditSupplier').on('hidden.bs.modal', function () {
            $('#formEditSupplier')[0].reset();
            isSubmittingEdit = false;
            var submitBtn = $('#formEditSupplier').find('button[type="submit"]');
            submitBtn.prop('disabled', false);
            submitBtn.html('Simpan Perubahan');
        });
    </script>
</body>
</html>

