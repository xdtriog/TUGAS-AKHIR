<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login dan adalah pemilik (OWNR)
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
    exit();
}

require_once '../includes/uuid_generator.php';

// Function untuk generate ID user berdasarkan role
function generateIdUser($conn, $role) {
    // Tentukan prefix berdasarkan role
    $prefix = '';
    switch(strtoupper($role)) {
        case 'OWNER':
            $prefix = 'OWNR';
            break;
        case 'STAFF GUDANG':
            $prefix = 'GDNG';
            break;
        case 'STAFF TOKO':
            $prefix = 'TOKO';
            break;
        default:
            return false;
    }
    
    // Format: PREFIX (4 karakter) + UUID (4 karakter) = 8 karakter total
    // Pattern: generate > check > pass, generate > check (duplikat) > generate > check > pass
    $maxAttempts = 100;
    $attempt = 0;
    do {
        $id_user = $prefix . ShortIdGenerator::generate(4, '');
        $attempt++;
        if (!checkUUIDExists($conn, 'USERS', 'ID_USERS', $id_user)) {
            return $id_user; // UUID unique, return
        }
    } while ($attempt < $maxAttempts);
    
    // Jika masih duplikat setelah 100 percobaan, return false
    return false;
}

// Handle form submission untuk tambah user
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'tambah_user') {
        $role = trim($_POST['role']);
        $kd_lokasi = !empty($_POST['kd_lokasi']) ? trim($_POST['kd_lokasi']) : null;
        $kd_supplier = !empty($_POST['kd_supplier']) ? trim($_POST['kd_supplier']) : null;
        $nama = trim($_POST['nama']);
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $status = 'AKTIF'; // Status selalu Aktif untuk user baru
        
        if (!empty($role) && !empty($nama) && !empty($username) && !empty($password)) {
            // Validasi role dan lokasi
            if (($role == 'STAFF GUDANG' || $role == 'STAFF TOKO') && empty($kd_lokasi)) {
                $message = 'Lokasi bekerja harus dipilih untuk ' . $role . '!';
                $message_type = 'danger';
            } else {
                // Generate ID user otomatis
                $id_user = generateIdUser($conn, $role);
                
                if ($id_user === false) {
                    $message = 'Role tidak valid!';
                    $message_type = 'danger';
                } else {
                    // Cek username sudah ada atau belum
                    $check_username = "SELECT ID_USERS FROM USERS WHERE USERNAME = ?";
                    $check_stmt = $conn->prepare($check_username);
                    $check_stmt->bind_param("s", $username);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result();
                    
                    if ($check_result->num_rows > 0) {
                        $message = 'Username sudah digunakan!';
                        $message_type = 'danger';
                        $check_stmt->close();
                    } else {
                        $check_stmt->close();
                        
                        // Insert data
                        $insert_query = "INSERT INTO USERS (ID_USERS, KD_LOKASI, KD_SUPPLIER, NAMA, USERNAME, PASSWORD, STATUS) VALUES (?, ?, ?, ?, ?, ?, ?)";
                        $insert_stmt = $conn->prepare($insert_query);
                        $insert_stmt->bind_param("sssssss", $id_user, $kd_lokasi, $kd_supplier, $nama, $username, $password, $status);
                        
                        if ($insert_stmt->execute()) {
                            $message = 'User berhasil ditambahkan dengan ID: ' . $id_user;
                            $message_type = 'success';
                            // Redirect untuk mencegah resubmission
                            header("Location: master_user.php?success=1&id_user=" . urlencode($id_user));
                            exit();
                        } else {
                            $message = 'Gagal menambahkan user!';
                            $message_type = 'danger';
                        }
                        $insert_stmt->close();
                    }
                }
            }
        } else {
            $message = 'Semua field wajib harus diisi!';
            $message_type = 'danger';
        }
    } elseif ($_POST['action'] == 'edit_user') {
        $id_user = trim($_POST['id_user']);
        $kd_lokasi = !empty($_POST['kd_lokasi']) ? trim($_POST['kd_lokasi']) : null;
        $kd_supplier = !empty($_POST['kd_supplier']) ? trim($_POST['kd_supplier']) : null;
        $nama = trim($_POST['nama']);
        $username = trim($_POST['username']);
        $password = trim($_POST['password']);
        $status = isset($_POST['status']) ? trim($_POST['status']) : 'AKTIF';
        
        if (!empty($id_user) && !empty($nama) && !empty($username)) {
            // Cek username sudah ada atau belum (kecuali untuk user yang sedang diedit)
            $check_username = "SELECT ID_USERS FROM USERS WHERE USERNAME = ? AND ID_USERS != ?";
            $check_stmt = $conn->prepare($check_username);
            $check_stmt->bind_param("ss", $username, $id_user);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_result->num_rows > 0) {
                $message = 'Username sudah digunakan!';
                $message_type = 'danger';
                $check_stmt->close();
            } else {
                $check_stmt->close();
                
                // Update data
                if (!empty($password)) {
                    // Update dengan password baru
                    $update_query = "UPDATE USERS SET KD_LOKASI = ?, KD_SUPPLIER = ?, NAMA = ?, USERNAME = ?, PASSWORD = ?, STATUS = ? WHERE ID_USERS = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("sssssss", $kd_lokasi, $kd_supplier, $nama, $username, $password, $status, $id_user);
                } else {
                    // Update tanpa mengubah password
                    $update_query = "UPDATE USERS SET KD_LOKASI = ?, KD_SUPPLIER = ?, NAMA = ?, USERNAME = ?, STATUS = ? WHERE ID_USERS = ?";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bind_param("ssssss", $kd_lokasi, $kd_supplier, $nama, $username, $status, $id_user);
                }
                
                if ($update_stmt->execute()) {
                    $message = 'User berhasil diperbarui';
                    $message_type = 'success';
                    // Redirect untuk mencegah resubmission
                    header("Location: master_user.php?success=2&id_user=" . urlencode($id_user));
                    exit();
                } else {
                    $message = 'Gagal memperbarui user!';
                    $message_type = 'danger';
                }
                $update_stmt->close();
            }
        } else {
            $message = 'ID user, nama, dan username harus diisi!';
            $message_type = 'danger';
        }
    }
}

// Handle success message dari redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $message = 'User berhasil ditambahkan dengan ID: ' . htmlspecialchars($_GET['id_user'] ?? '');
    $message_type = 'success';
} elseif (isset($_GET['success']) && $_GET['success'] == '2') {
    $message = 'User berhasil diperbarui';
    $message_type = 'success';
}

// Query untuk mendapatkan data User dengan lokasi bekerja
$query_user = "SELECT 
                    u.ID_USERS,
                    COALESCE(ml.NAMA_LOKASI, ms.NAMA_SUPPLIER, '-') as LOKASI_BEKERJA,
                    u.NAMA,
                    u.USERNAME,
                    u.PASSWORD,
                    u.STATUS,
                    u.KD_LOKASI,
                    u.KD_SUPPLIER
                 FROM USERS u
                 LEFT JOIN MASTER_LOKASI ml ON u.KD_LOKASI = ml.KD_LOKASI
                 LEFT JOIN MASTER_SUPPLIER ms ON u.KD_SUPPLIER = ms.KD_SUPPLIER
                 ORDER BY u.ID_USERS ASC";
$result_user = $conn->query($query_user);

// Query untuk mendapatkan data Lokasi (untuk dropdown)
$query_lokasi = "SELECT KD_LOKASI, NAMA_LOKASI, TYPE_LOKASI 
                 FROM MASTER_LOKASI 
                 WHERE STATUS = 'AKTIF'
                 ORDER BY NAMA_LOKASI ASC";
$result_lokasi = $conn->query($query_lokasi);

// Query untuk mendapatkan data Supplier (untuk dropdown)
$query_supplier = "SELECT KD_SUPPLIER, NAMA_SUPPLIER 
                   FROM MASTER_SUPPLIER 
                   WHERE STATUS = 'AKTIF'
                   ORDER BY NAMA_SUPPLIER ASC";
$result_supplier = $conn->query($query_supplier);

// Set active page untuk sidebar
$active_page = 'master_user';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Master User</title>
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
            <h1 class="page-title">Pemilik - Master User</h1>
        </div>

        <!-- Add Button -->
        <div class="mb-3">
            <button type="button" class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalTambahUser">
                <i class="bi bi-plus-circle"></i> Tambahkan User
            </button>
        </div>

        <!-- Table User -->
        <div class="table-section" style="width: 100%;">
            <div class="table-responsive" style="width: 100%;">
                <table id="tableUser" class="table table-custom table-striped table-hover" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>ID User</th>
                            <th>Lokasi Bekerja</th>
                            <th>Nama</th>
                            <th>Username</th>
                            <th>Password</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_user->num_rows > 0): ?>
                            <?php while ($row = $result_user->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['ID_USERS']); ?></td>
                                    <td><?php echo htmlspecialchars($row['LOKASI_BEKERJA']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA']); ?></td>
                                    <td><?php echo htmlspecialchars($row['USERNAME']); ?></td>
                                    <td><?php echo htmlspecialchars($row['PASSWORD']); ?></td>
                                    <td>
                                        <?php if ($row['STATUS'] == 'AKTIF'): ?>
                                            <span class="badge bg-success">Aktif</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Tidak Aktif</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn-view" onclick="editUser('<?php echo htmlspecialchars($row['ID_USERS']); ?>', '<?php echo htmlspecialchars($row['KD_LOKASI'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['KD_SUPPLIER'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['NAMA'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['USERNAME'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($row['PASSWORD'], ENT_QUOTES); ?>', '<?php echo $row['STATUS']; ?>')">Edit</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Tambah User -->
    <div class="modal fade" id="modalTambahUser" tabindex="-1" aria-labelledby="modalTambahUserLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalTambahUserLabel">Tambahkan User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formTambahUser" method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="tambah_user">
                        
                        <div class="mb-3">
                            <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                            <select class="form-select" id="role" name="role" required>
                                <option value="">Pilih Role</option>
                                <option value="OWNER">OWNER</option>
                                <option value="STAFF GUDANG">STAFF GUDANG</option>
                                <option value="STAFF TOKO">STAFF TOKO</option>
                            </select>
                            <small class="text-muted">ID User akan dibuat otomatis berdasarkan role (OWNR+UUID, GDNG+UUID, TOKO+UUID).</small>
                        </div>
                        
                        <div class="mb-3" id="lokasiField" style="display: none;">
                            <label for="kd_lokasi" class="form-label">Lokasi Bekerja <span class="text-danger">*</span></label>
                            <select class="form-select" id="kd_lokasi" name="kd_lokasi">
                                <option value="">Pilih Lokasi</option>
                                <?php if ($result_lokasi->num_rows > 0): ?>
                                    <?php while ($lokasi = $result_lokasi->fetch_assoc()): ?>
                                        <option value="<?php echo htmlspecialchars($lokasi['KD_LOKASI']); ?>" data-type="<?php echo htmlspecialchars($lokasi['TYPE_LOKASI']); ?>">
                                            <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?> (<?php echo strtoupper($lokasi['TYPE_LOKASI']); ?>)
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="nama" class="form-label">Nama <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="nama" name="nama" placeholder="Masukkan nama user" maxlength="100" required autofocus>
                            <small class="text-muted">Maksimal 100 karakter.</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="username" name="username" placeholder="Masukkan username" maxlength="50" required>
                                    <small class="text-muted">Maksimal 50 karakter. Harus unik.</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                                    <input type="password" class="form-control" id="password" name="password" placeholder="Masukkan password" maxlength="255" required>
                                    <small class="text-muted">Maksimal 255 karakter.</small>
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

    <!-- Modal Edit User -->
    <div class="modal fade" id="modalEditUser" tabindex="-1" aria-labelledby="modalEditUserLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                    <h5 class="modal-title" id="modalEditUserLabel">Edit User</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formEditUser" method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_user">
                        <input type="hidden" name="id_user" id="edit_id_user">
                        
                        <div class="mb-3">
                            <label for="edit_id_user_display" class="form-label">ID User</label>
                            <input type="text" class="form-control" id="edit_id_user_display" readonly style="background-color: #e9ecef;">
                            <small class="text-muted">ID User tidak dapat diubah.</small>
                        </div>
                        
                        <div class="mb-3" id="editLokasiField">
                            <label for="edit_kd_lokasi" class="form-label">Lokasi Bekerja</label>
                            <select class="form-select" id="edit_kd_lokasi" name="kd_lokasi">
                                <option value="">Pilih Lokasi</option>
                                <?php 
                                // Reset pointer untuk query lokasi
                                $result_lokasi->data_seek(0);
                                if ($result_lokasi->num_rows > 0): ?>
                                    <?php while ($lokasi = $result_lokasi->fetch_assoc()): ?>
                                        <option value="<?php echo htmlspecialchars($lokasi['KD_LOKASI']); ?>">
                                            <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?> (<?php echo strtoupper($lokasi['TYPE_LOKASI']); ?>)
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_nama" class="form-label">Nama <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_nama" name="nama" placeholder="Masukkan nama user" maxlength="100" required autofocus>
                            <small class="text-muted">Maksimal 100 karakter.</small>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_username" class="form-label">Username <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="edit_username" name="username" placeholder="Masukkan username" maxlength="50" required>
                                    <small class="text-muted">Maksimal 50 karakter. Harus unik.</small>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_password" class="form-label">Password</label>
                                    <input type="password" class="form-control" id="edit_password" name="password" placeholder="Kosongkan jika tidak ingin mengubah password" maxlength="255">
                                    <small class="text-muted">Kosongkan jika tidak ingin mengubah password.</small>
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
            
            $('#tableUser').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data user'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[0, 'asc']], // Sort by ID User
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
            
            // Show/hide lokasi field berdasarkan role
            $('#role').on('change', function() {
                var role = $(this).val();
                var lokasiField = $('#lokasiField');
                var kdLokasi = $('#kd_lokasi');
                
                // Reset fields
                kdLokasi.val('').removeAttr('required');
                lokasiField.hide();
                
                if (role === 'STAFF GUDANG' || role === 'STAFF TOKO') {
                    lokasiField.show();
                    kdLokasi.attr('required', 'required');
                    
                    // Filter lokasi berdasarkan tipe
                    if (role === 'STAFF GUDANG') {
                        kdLokasi.find('option').each(function() {
                            var $option = $(this);
                            if ($option.data('type') === 'gudang' || $option.val() === '') {
                                $option.show();
                            } else {
                                $option.hide();
                            }
                        });
                    } else if (role === 'STAFF TOKO') {
                        kdLokasi.find('option').each(function() {
                            var $option = $(this);
                            if ($option.data('type') === 'toko' || $option.val() === '') {
                                $option.show();
                            } else {
                                $option.hide();
                            }
                        });
                    }
                }
            });
        });

        // Flag untuk mencegah multiple submission
        var isSubmitting = false;
        
        // Form validation dan prevent multiple submission
        $('#formTambahUser').on('submit', function(e) {
            // Cegah multiple submission
            if (isSubmitting) {
                e.preventDefault();
                return false;
            }
            
            var role = $('#role').val();
            var nama = $('#nama').val().trim();
            var username = $('#username').val().trim();
            var password = $('#password').val();
            
            // Validasi
            if (!role) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Role harus dipilih!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#role').focus();
                });
                return false;
            }
            
            if ((role === 'STAFF GUDANG' || role === 'STAFF TOKO') && !$('#kd_lokasi').val()) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Lokasi bekerja harus dipilih untuk ' + role + '!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#kd_lokasi').focus();
                });
                return false;
            }
            
            if (nama.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Nama harus diisi!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#nama').focus();
                });
                return false;
            }
            
            if (username.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Username harus diisi!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#username').focus();
                });
                return false;
            }
            
            if (password.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Password harus diisi!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#password').focus();
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
        $('#modalTambahUser').on('hidden.bs.modal', function () {
            $('#formTambahUser')[0].reset();
            isSubmitting = false;
            var submitBtn = $('#formTambahUser').find('button[type="submit"]');
            submitBtn.prop('disabled', false);
            submitBtn.html('Simpan');
            $('#lokasiField').hide();
            $('#kd_lokasi').removeAttr('required');
        });

        // Flag untuk mencegah multiple submission edit
        var isSubmittingEdit = false;
        
        function editUser(idUser, kdLokasi, kdSupplier, nama, username, password, status) {
            // Set nilai form edit
            $('#edit_id_user').val(idUser);
            $('#edit_id_user_display').val(idUser);
            $('#edit_kd_lokasi').val(kdLokasi || '');
            $('#edit_nama').val(nama);
            $('#edit_username').val(username);
            $('#edit_password').val(''); // Kosongkan password untuk keamanan
            $('#edit_status').val(status);
            
            // Reset flag
            isSubmittingEdit = false;
            var submitBtn = $('#formEditUser').find('button[type="submit"]');
            submitBtn.prop('disabled', false);
            submitBtn.html('Simpan Perubahan');
            
            // Buka modal
            var modalEdit = new bootstrap.Modal(document.getElementById('modalEditUser'));
            modalEdit.show();
        }
        
        // Form validation dan prevent multiple submission untuk edit
        $('#formEditUser').on('submit', function(e) {
            // Cegah multiple submission
            if (isSubmittingEdit) {
                e.preventDefault();
                return false;
            }
            
            var nama = $('#edit_nama').val().trim();
            var username = $('#edit_username').val().trim();
            
            // Validasi
            if (nama.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Nama harus diisi!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#edit_nama').focus();
                });
                return false;
            }
            
            if (username.length === 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Username harus diisi!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#edit_username').focus();
                });
                return false;
            }
            
            // Prevent default submit untuk menampilkan konfirmasi
            e.preventDefault();
            
            // Tampilkan konfirmasi
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi Perubahan',
                text: 'Apakah Anda yakin ingin menyimpan perubahan data user ini?',
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
                    var submitBtn = $('#formEditUser').find('button[type="submit"]');
                    var originalText = submitBtn.html();
                    submitBtn.prop('disabled', true);
                    submitBtn.html('<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Menyimpan...');
                    
                    // Submit form
                    $('#formEditUser')[0].submit();
                    
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
        $('#modalEditUser').on('hidden.bs.modal', function () {
            $('#formEditUser')[0].reset();
            isSubmittingEdit = false;
            var submitBtn = $('#formEditUser').find('button[type="submit"]');
            submitBtn.prop('disabled', false);
            submitBtn.html('Simpan Perubahan');
        });
    </script>
</body>
</html>

