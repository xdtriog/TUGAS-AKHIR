<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login
if (!isset($_SESSION['user_id']) || $_SESSION['permision'] != 1) {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/uuid_generator.php';

// Function untuk generate kode merek random
function generateKodeMerek($conn) {
    // Generate UUID (8 karakter, tanpa prefix)
    // Pattern: generate > check > pass, generate > check (duplikat) > generate > check > pass
    $maxAttempts = 100;
    $attempt = 0;
    do {
        $kode = ShortIdGenerator::generate(8, '');
        $attempt++;
        if (!checkUUIDExists($conn, 'MASTER_MEREK', 'KD_MEREK_BARANG', $kode)) {
            return $kode; // UUID unique, return
        }
    } while ($attempt < $maxAttempts);
    
    // Jika masih duplikat setelah 100 percobaan, return false
    return false;
}

// Handle form submission untuk tambah merek
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'tambah_merek') {
        $nama_merek = trim($_POST['nama_merek']);
        
        if (!empty($nama_merek)) {
            // Generate kode merek otomatis
            $kd_merek = generateKodeMerek($conn);
            if ($kd_merek === false) {
                $message = 'Gagal generate kode merek! Silakan coba lagi.';
                $message_type = 'danger';
            } else {
                $status = 'AKTIF'; // Status langsung AKTIF
            
            // Insert data
            $insert_query = "INSERT INTO MASTER_MEREK (KD_MEREK_BARANG, NAMA_MEREK, STATUS) VALUES (?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bind_param("sss", $kd_merek, $nama_merek, $status);
            
                if ($insert_stmt->execute()) {
                    $message = 'Merek berhasil ditambahkan dengan kode: ' . $kd_merek;
                    $message_type = 'success';
                    // Redirect untuk mencegah resubmission
                    header("Location: master_merek.php?success=1&kd_merek=" . urlencode($kd_merek));
                    exit();
                } else {
                    $message = 'Gagal menambahkan merek!';
                    $message_type = 'danger';
                }
                $insert_stmt->close();
            }
        } else {
            $message = 'Nama merek harus diisi!';
            $message_type = 'danger';
        }
    } elseif ($_POST['action'] == 'edit_merek') {
        $kd_merek = trim($_POST['kd_merek']);
        $nama_merek = trim($_POST['nama_merek']);
        $status = trim($_POST['status']);
        
        if (!empty($kd_merek) && !empty($nama_merek) && !empty($status)) {
            // Update data
            $update_query = "UPDATE MASTER_MEREK SET NAMA_MEREK = ?, STATUS = ? WHERE KD_MEREK_BARANG = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("sss", $nama_merek, $status, $kd_merek);
            
            if ($update_stmt->execute()) {
                $message = 'Merek berhasil diperbarui';
                $message_type = 'success';
                // Redirect untuk mencegah resubmission
                header("Location: master_merek.php?success=2&kd_merek=" . urlencode($kd_merek));
                exit();
            } else {
                $message = 'Gagal memperbarui merek!';
                $message_type = 'danger';
            }
            $update_stmt->close();
        } else {
            $message = 'Semua field harus diisi!';
            $message_type = 'danger';
        }
    }
}

// Handle success message dari redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $message = 'Merek berhasil ditambahkan dengan kode: ' . htmlspecialchars($_GET['kd_merek'] ?? '');
    $message_type = 'success';
} elseif (isset($_GET['success']) && $_GET['success'] == '2') {
    $message = 'Merek berhasil diperbarui';
    $message_type = 'success';
}

// Query untuk mendapatkan data Merek
$query_merek = "SELECT KD_MEREK_BARANG, NAMA_MEREK, STATUS 
                FROM MASTER_MEREK 
                ORDER BY KD_MEREK_BARANG ASC";
$result_merek = $conn->query($query_merek);

// Set active page untuk sidebar
$active_page = 'master_merek';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Master Merek</title>
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
            <h1 class="page-title">Pemilik - Master Merek</h1>
        </div>


        <!-- Add Button -->
        <div class="mb-3">
            <button type="button" class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalTambahMerek">
                <i class="bi bi-plus-circle"></i> Tambahkan Merek
            </button>
        </div>

        <!-- Table Merek -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tableMerek" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Kode Merek</th>
                            <th>Nama Merek</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_merek->num_rows > 0): ?>
                            <?php while ($row = $result_merek->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['KD_MEREK_BARANG']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_MEREK']); ?></td>
                                    <td>
                                        <?php if ($row['STATUS'] == 'AKTIF'): ?>
                                            <span class="badge bg-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Tidak Aktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn-view" onclick="editMerek('<?php echo htmlspecialchars($row['KD_MEREK_BARANG']); ?>', '<?php echo htmlspecialchars($row['NAMA_MEREK'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['STATUS']); ?>')">Edit</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Tambah Merek -->
    <div class="modal fade" id="modalTambahMerek" tabindex="-1" aria-labelledby="modalTambahMerekLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalTambahMerekLabel">Tambahkan Merek</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formTambahMerek" method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="tambah_merek">
                        
                        <div class="mb-3">
                            <label for="nama_merek" class="form-label">Nama Merek <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="nama_merek" name="nama_merek" placeholder="Masukkan nama merek" maxlength="100" required autofocus>
                            <small class="text-muted">Maksimal 100 karakter. Kode merek akan dibuat otomatis.</small>
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

    <!-- Modal Edit Merek -->
    <div class="modal fade" id="modalEditMerek" tabindex="-1" aria-labelledby="modalEditMerekLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                    <h5 class="modal-title" id="modalEditMerekLabel">Edit Merek</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formEditMerek" method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_merek">
                        <input type="hidden" name="kd_merek" id="edit_kd_merek">
                        
                        <div class="mb-3">
                            <label for="edit_kd_merek_display" class="form-label">Kode Merek</label>
                            <input type="text" class="form-control" id="edit_kd_merek_display" readonly style="background-color: #e9ecef;">
                            <small class="text-muted">Kode merek tidak dapat diubah.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_nama_merek" class="form-label">Nama Merek <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_nama_merek" name="nama_merek" placeholder="Masukkan nama merek" maxlength="100" required autofocus>
                            <small class="text-muted">Maksimal 100 karakter.</small>
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
            
            $('#tableMerek').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data merek'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[0, 'asc']], // Sort by Kode Merek
                columnDefs: [
                    { orderable: false, targets: 3 } // Disable sorting on Action column
                ],
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
        $('#formTambahMerek').on('submit', function(e) {
            // Cegah multiple submission
            if (isSubmitting) {
                e.preventDefault();
                return false;
            }
            
            var namaMerek = $('#nama_merek').val().trim();
            
            // Validasi
            if (namaMerek.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Nama merek harus diisi!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#nama_merek').focus();
                });
                return false;
            }
            
            if (namaMerek.length > 100) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Nama Merek maksimal 100 karakter!',
                    confirmButtonColor: '#e74c3c'
                }).then(() => {
                    $('#nama_merek').focus();
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
        $('#modalTambahMerek').on('hidden.bs.modal', function () {
            $('#formTambahMerek')[0].reset();
            isSubmitting = false;
            var submitBtn = $('#formTambahMerek').find('button[type="submit"]');
            submitBtn.prop('disabled', false);
            submitBtn.html('Simpan');
        });

        // Flag untuk mencegah multiple submission edit
        var isSubmittingEdit = false;
        
        function editMerek(kdMerek, namaMerek, status) {
            // Set nilai form edit
            $('#edit_kd_merek').val(kdMerek);
            $('#edit_kd_merek_display').val(kdMerek);
            $('#edit_nama_merek').val(namaMerek);
            $('#edit_status').val(status);
            
            // Reset flag
            isSubmittingEdit = false;
            var submitBtn = $('#formEditMerek').find('button[type="submit"]');
            submitBtn.prop('disabled', false);
            submitBtn.html('Simpan Perubahan');
            
            // Buka modal
            var modalEdit = new bootstrap.Modal(document.getElementById('modalEditMerek'));
            modalEdit.show();
        }
        
        // Form validation dan prevent multiple submission untuk edit
        $('#formEditMerek').on('submit', function(e) {
            // Cegah multiple submission
            if (isSubmittingEdit) {
                e.preventDefault();
                return false;
            }
            
            var namaMerek = $('#edit_nama_merek').val().trim();
            var status = $('#edit_status').val();
            
            // Validasi
            if (namaMerek.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Nama merek harus diisi!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#edit_nama_merek').focus();
                });
                return false;
            }
            
            if (namaMerek.length > 100) {
                e.preventDefault();
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Nama Merek maksimal 100 karakter!',
                    confirmButtonColor: '#e74c3c'
                }).then(() => {
                    $('#edit_nama_merek').focus();
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
                text: 'Apakah Anda yakin ingin menyimpan perubahan data merek ini?',
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
                    var submitBtn = $('#formEditMerek').find('button[type="submit"]');
                    var originalText = submitBtn.html();
                    submitBtn.prop('disabled', true);
                    submitBtn.html('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Menyimpan...');
                    
                    // Submit form
                    $('#formEditMerek')[0].submit();
                    
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
        $('#modalEditMerek').on('hidden.bs.modal', function () {
            $('#formEditMerek')[0].reset();
            isSubmittingEdit = false;
            var submitBtn = $('#formEditMerek').find('button[type="submit"]');
            submitBtn.prop('disabled', false);
            submitBtn.html('Simpan Perubahan');
        });
    </script>
</body>
</html>

