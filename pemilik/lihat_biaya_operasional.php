<?php
session_start();
require_once '../dbconnect.php';
require_once '../includes/uuid_generator.php';

// Cek apakah user sudah login
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
    exit();
}

// Get parameter kd_lokasi
$kd_lokasi = isset($_GET['kd_lokasi']) ? trim($_GET['kd_lokasi']) : '';

if (empty($kd_lokasi)) {
    header("Location: master_lokasi.php");
    exit();
}

// Query untuk mendapatkan data lokasi
$query_lokasi = "SELECT KD_LOKASI, NAMA_LOKASI, TYPE_LOKASI, ALAMAT_LOKASI 
                 FROM MASTER_LOKASI 
                 WHERE KD_LOKASI = ? AND STATUS = 'AKTIF'";
$stmt_lokasi = $conn->prepare($query_lokasi);
$stmt_lokasi->bind_param("s", $kd_lokasi);
$stmt_lokasi->execute();
$result_lokasi = $stmt_lokasi->get_result();

if ($result_lokasi->num_rows == 0) {
    header("Location: master_lokasi.php");
    exit();
}

$lokasi = $result_lokasi->fetch_assoc();

// Handle form submission untuk tambah biaya operasional
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'tambah_biaya') {
        $kd_tipe_biaya = trim($_POST['kd_tipe_biaya']);
        $jumlah_biaya = isset($_POST['jumlah_biaya']) ? floatval($_POST['jumlah_biaya']) : 0;
        $periode = trim($_POST['periode']);
        
        if (!empty($kd_tipe_biaya) && $jumlah_biaya > 0 && !empty($periode)) {
            // Generate ID_COST dengan format IBOP+UUID (total 16 karakter: IBOP=4, UUID=12)
            $maxAttempts = 100;
            $attempt = 0;
            do {
                $uuid = ShortIdGenerator::generate(12, '');
                $id_cost = 'IBOP' . $uuid;
                $attempt++;
                if (!checkUUIDExists($conn, 'BIAYA_OPERASIONAL', 'ID_COST', $id_cost)) {
                    break;
                }
            } while ($attempt < $maxAttempts);
            
            if ($attempt >= $maxAttempts) {
                $message = 'Gagal generate ID biaya operasional! Silakan coba lagi.';
                $message_type = 'danger';
            } else {
                // Insert data
                $insert_query = "INSERT INTO BIAYA_OPERASIONAL (ID_COST, KD_LOKASI, KD_TIPE_BIAYA_OPERASIONAL, JUMLAH_BIAYA_UANG, PERIODE) VALUES (?, ?, ?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->bind_param("sssds", $id_cost, $kd_lokasi, $kd_tipe_biaya, $jumlah_biaya, $periode);
                
                if ($insert_stmt->execute()) {
                    $message = 'Biaya operasional berhasil ditambahkan dengan ID: ' . $id_cost;
                    $message_type = 'success';
                    header("Location: lihat_biaya_operasional.php?kd_lokasi=" . urlencode($kd_lokasi) . "&success=1");
                    exit();
                } else {
                    $message = 'Gagal menambahkan biaya operasional!';
                    $message_type = 'danger';
                }
                $insert_stmt->close();
            }
        } else {
            $message = 'Semua field wajib harus diisi dan jumlah biaya harus > 0!';
            $message_type = 'danger';
        }
    } elseif ($_POST['action'] == 'edit_biaya') {
        $id_cost = trim($_POST['id_cost']);
        $kd_tipe_biaya = trim($_POST['kd_tipe_biaya']);
        $jumlah_biaya = isset($_POST['jumlah_biaya']) ? floatval($_POST['jumlah_biaya']) : 0;
        $periode = trim($_POST['periode']);
        
        if (!empty($id_cost) && !empty($kd_tipe_biaya) && $jumlah_biaya > 0 && !empty($periode)) {
            // Update data
            $update_query = "UPDATE BIAYA_OPERASIONAL SET KD_TIPE_BIAYA_OPERASIONAL = ?, JUMLAH_BIAYA_UANG = ?, PERIODE = ? WHERE ID_COST = ? AND KD_LOKASI = ?";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bind_param("sdsss", $kd_tipe_biaya, $jumlah_biaya, $periode, $id_cost, $kd_lokasi);
            
            if ($update_stmt->execute()) {
                $message = 'Biaya operasional berhasil diperbarui';
                $message_type = 'success';
                header("Location: lihat_biaya_operasional.php?kd_lokasi=" . urlencode($kd_lokasi) . "&success=2");
                exit();
            } else {
                $message = 'Gagal memperbarui biaya operasional!';
                $message_type = 'danger';
            }
            $update_stmt->close();
        } else {
            $message = 'Semua field wajib harus diisi dan jumlah biaya harus > 0!';
            $message_type = 'danger';
        }
    }
}

// Handle success message dari redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $message = 'Biaya operasional berhasil ditambahkan';
    $message_type = 'success';
} elseif (isset($_GET['success']) && $_GET['success'] == '2') {
    $message = 'Biaya operasional berhasil diperbarui';
    $message_type = 'success';
}

// Query untuk mendapatkan data tipe biaya operasional yang belum digunakan untuk lokasi ini (untuk dropdown tambah)
$query_tipe_biaya_tambah = "SELECT mtbo.KD_TIPE_BIAYA_OPERASIONAL, mtbo.NAMA_TIPE_BIAYA_OPERASIONAL 
                             FROM MASTER_TIPE_BIAYA_OPERASIONAL mtbo
                             LEFT JOIN BIAYA_OPERASIONAL bo ON mtbo.KD_TIPE_BIAYA_OPERASIONAL = bo.KD_TIPE_BIAYA_OPERASIONAL 
                                 AND bo.KD_LOKASI = ?
                             WHERE bo.KD_TIPE_BIAYA_OPERASIONAL IS NULL
                             ORDER BY mtbo.NAMA_TIPE_BIAYA_OPERASIONAL ASC";
$stmt_tipe_biaya_tambah = $conn->prepare($query_tipe_biaya_tambah);
$stmt_tipe_biaya_tambah->bind_param("s", $kd_lokasi);
$stmt_tipe_biaya_tambah->execute();
$result_tipe_biaya_tambah = $stmt_tipe_biaya_tambah->get_result();

// Query untuk mendapatkan semua data tipe biaya operasional (untuk dropdown edit - semua tipe bisa dipilih)
$query_tipe_biaya_edit = "SELECT KD_TIPE_BIAYA_OPERASIONAL, NAMA_TIPE_BIAYA_OPERASIONAL 
                           FROM MASTER_TIPE_BIAYA_OPERASIONAL 
                           ORDER BY NAMA_TIPE_BIAYA_OPERASIONAL ASC";
$result_tipe_biaya_edit = $conn->query($query_tipe_biaya_edit);

// Query untuk mendapatkan data biaya operasional
$query_biaya = "SELECT 
    bo.ID_COST,
    bo.KD_TIPE_BIAYA_OPERASIONAL,
    bo.JUMLAH_BIAYA_UANG,
    bo.PERIODE,
    bo.LAST_UPDATED,
    mtbo.NAMA_TIPE_BIAYA_OPERASIONAL
FROM BIAYA_OPERASIONAL bo
LEFT JOIN MASTER_TIPE_BIAYA_OPERASIONAL mtbo ON bo.KD_TIPE_BIAYA_OPERASIONAL = mtbo.KD_TIPE_BIAYA_OPERASIONAL
WHERE bo.KD_LOKASI = ?
ORDER BY bo.LAST_UPDATED DESC";
$stmt_biaya = $conn->prepare($query_biaya);
$stmt_biaya->bind_param("s", $kd_lokasi);
$stmt_biaya->execute();
$result_biaya = $stmt_biaya->get_result();

// Format rupiah
function formatRupiah($angka) {
    if (empty($angka) || $angka == null || $angka == 0) {
        return '-';
    }
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Format periode
function formatPeriode($periode) {
    switch($periode) {
        case 'HARIAN':
            return 'Harian';
        case 'BULANAN':
            return 'Bulanan';
        case 'TAHUNAN':
            return 'Tahunan';
        default:
            return $periode;
    }
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
$active_page = 'master_lokasi';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Lihat Biaya Operasional - <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></title>
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
            <h1 class="page-title">Pemilik - Lihat Biaya Operasional - <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></h1>
        </div>

        <!-- Location Details Section -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-4">Informasi Lokasi</h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Kode Lokasi</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($lokasi['KD_LOKASI']); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Nama Lokasi</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Tipe Lokasi</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars(ucfirst($lokasi['TYPE_LOKASI'])); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Alamat Lokasi</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($lokasi['ALAMAT_LOKASI'] ?? '-'); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                </div>
            </div>
        </div>

        <!-- Add Button -->
        <div class="mb-3">
            <button type="button" class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#modalTambahBiaya">
                Tambahkan Biaya Operasional
            </button>
        </div>

        <!-- Table Biaya Operasional -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tableBiaya" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID Biaya</th>
                            <th>Kode Tipe Biaya</th>
                            <th>Nama Biaya</th>
                            <th>Jumlah Biaya</th>
                            <th>Periode</th>
                            <th>Terakhir Update</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_biaya->num_rows > 0): ?>
                            <?php while ($row = $result_biaya->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['ID_COST']); ?></td>
                                    <td><?php echo htmlspecialchars($row['KD_TIPE_BIAYA_OPERASIONAL']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_TIPE_BIAYA_OPERASIONAL'] ?? '-'); ?></td>
                                    <td><?php echo formatRupiah($row['JUMLAH_BIAYA_UANG']); ?></td>
                                    <td><?php echo formatPeriode($row['PERIODE']); ?></td>
                                    <td><?php echo formatTanggalWaktu($row['LAST_UPDATED']); ?></td>
                                    <td>
                                        <button class="btn-view btn-sm" onclick="editBiaya('<?php echo htmlspecialchars($row['ID_COST']); ?>', '<?php echo htmlspecialchars($row['KD_TIPE_BIAYA_OPERASIONAL']); ?>', '<?php echo htmlspecialchars($row['NAMA_TIPE_BIAYA_OPERASIONAL'] ?? ''); ?>', '<?php echo $row['JUMLAH_BIAYA_UANG']; ?>', '<?php echo htmlspecialchars($row['PERIODE']); ?>')">Edit</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Tambah Biaya Operasional -->
    <div class="modal fade" id="modalTambahBiaya" tabindex="-1" aria-labelledby="modalTambahBiayaLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalTambahBiayaLabel">Tambahkan Biaya Operasional</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formTambahBiaya" method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="tambah_biaya">
                        
                        <div class="mb-3">
                            <label for="tambah_kd_tipe_biaya" class="form-label">Tipe Biaya Operasional <span class="text-danger">*</span></label>
                            <select class="form-select" id="tambah_kd_tipe_biaya" name="kd_tipe_biaya" required>
                                <option value="">-- Pilih Tipe Biaya Operasional --</option>
                                <?php 
                                if ($result_tipe_biaya_tambah && $result_tipe_biaya_tambah->num_rows > 0) {
                                    while ($tipe = $result_tipe_biaya_tambah->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo htmlspecialchars($tipe['KD_TIPE_BIAYA_OPERASIONAL']); ?>">
                                        <?php echo htmlspecialchars($tipe['KD_TIPE_BIAYA_OPERASIONAL'] . ' - ' . $tipe['NAMA_TIPE_BIAYA_OPERASIONAL']); ?>
                                    </option>
                                <?php 
                                    endwhile;
                                } else {
                                ?>
                                    <option value="" disabled>Tidak ada tipe biaya operasional yang tersedia</option>
                                <?php 
                                } 
                                ?>
                            </select>
                            <small class="text-muted">Hanya menampilkan tipe biaya operasional yang belum digunakan untuk lokasi ini.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="tambah_jumlah_biaya" class="form-label">Jumlah Biaya <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="tambah_jumlah_biaya" name="jumlah_biaya" placeholder="Rp 0" required>
                            <small class="text-muted">Masukkan jumlah biaya dalam rupiah.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="tambah_periode" class="form-label">Periode <span class="text-danger">*</span></label>
                            <select class="form-select" id="tambah_periode" name="periode" required>
                                <option value="">-- Pilih Periode --</option>
                                <option value="HARIAN">Harian</option>
                                <option value="BULANAN">Bulanan</option>
                                <option value="TAHUNAN">Tahunan</option>
                            </select>
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

    <!-- Modal Edit Biaya Operasional -->
    <div class="modal fade" id="modalEditBiaya" tabindex="-1" aria-labelledby="modalEditBiayaLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                    <h5 class="modal-title" id="modalEditBiayaLabel">Edit Biaya Operasional</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form id="formEditBiaya" method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit_biaya">
                        <input type="hidden" name="id_cost" id="edit_id_cost">
                        
                        <div class="mb-3">
                            <label for="edit_kd_tipe_biaya" class="form-label">Tipe Biaya Operasional <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_kd_tipe_biaya" name="kd_tipe_biaya" required>
                                <option value="">-- Pilih Tipe Biaya Operasional --</option>
                                <?php 
                                if ($result_tipe_biaya_edit && $result_tipe_biaya_edit->num_rows > 0) {
                                    $result_tipe_biaya_edit->data_seek(0);
                                    while ($tipe = $result_tipe_biaya_edit->fetch_assoc()): 
                                ?>
                                    <option value="<?php echo htmlspecialchars($tipe['KD_TIPE_BIAYA_OPERASIONAL']); ?>">
                                        <?php echo htmlspecialchars($tipe['KD_TIPE_BIAYA_OPERASIONAL'] . ' - ' . $tipe['NAMA_TIPE_BIAYA_OPERASIONAL']); ?>
                                    </option>
                                <?php 
                                    endwhile;
                                } 
                                ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_jumlah_biaya" class="form-label">Jumlah Biaya <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_jumlah_biaya" name="jumlah_biaya" placeholder="Rp 0" required>
                            <small class="text-muted">Masukkan jumlah biaya dalam rupiah.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_periode" class="form-label">Periode <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_periode" name="periode" required>
                                <option value="">-- Pilih Periode --</option>
                                <option value="HARIAN">Harian</option>
                                <option value="BULANAN">Bulanan</option>
                                <option value="TAHUNAN">Tahunan</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                        <button type="submit" class="btn-primary-custom" id="btnSimpanEdit">Simpan Perubahan</button>
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
            
            $('#tableBiaya').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data biaya operasional'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[5, 'desc']], // Sort by Terakhir Update descending
                columnDefs: [
                    { orderable: false, targets: 6 } // Disable sorting on Action column
                ],
                scrollX: true,
                responsive: true
            }).on('error.dt', function(e, settings, techNote, message) {
                console.log('DataTables error suppressed:', message);
                return false;
            });
            
            // Reset form saat modal ditutup
            $('#modalTambahBiaya').on('hidden.bs.modal', function() {
                $('#formTambahBiaya')[0].reset();
                // Hapus hidden input jika ada
                $('#tambah_jumlah_biaya_hidden').remove();
                // Kembalikan name attribute
                $('#tambah_jumlah_biaya').attr('name', 'jumlah_biaya');
            });
            
            $('#modalEditBiaya').on('hidden.bs.modal', function() {
                $('#formEditBiaya')[0].reset();
                // Hapus hidden input jika ada
                $('#edit_jumlah_biaya_hidden').remove();
                // Kembalikan name attribute
                $('#edit_jumlah_biaya').attr('name', 'jumlah_biaya');
            });
        });
        
        // Format rupiah untuk input jumlah biaya
        function formatRupiah(angka) {
            if (!angka && angka !== 0) return '';
            
            // Konversi ke string dan hapus semua karakter non-digit kecuali titik dan koma
            var number_string = angka.toString().replace(/[^\d.,]/g, '');
            
            // Pisahkan bagian integer dan desimal
            var parts = number_string.split(/[.,]/);
            var integerPart = parts[0] || '0';
            var decimalPart = parts[1] || '';
            
            // Format integer dengan titik sebagai pemisah ribuan
            var formattedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            
            // Gabungkan dengan desimal jika ada
            var result = formattedInteger;
            if (decimalPart) {
                result += ',' + decimalPart;
            }
            
            return 'Rp ' + result;
        }
        
        function unformatRupiah(rupiah) {
            if (!rupiah) return 0;
            // Hapus "Rp " dan spasi, ganti titik (ribuan) dengan kosong, ganti koma (desimal) dengan titik
            var cleaned = rupiah.toString().replace(/Rp\s?/g, '').replace(/\./g, '').replace(',', '.');
            return parseFloat(cleaned) || 0;
        }
        
        // Event listener untuk format rupiah saat input
        $(document).on('input', '#tambah_jumlah_biaya, #edit_jumlah_biaya', function() {
            var value = $(this).val();
            
            // Simpan posisi cursor
            var cursorPosition = this.selectionStart;
            var originalLength = value.length;
            
            // Unformat dan format ulang
            var unformatted = unformatRupiah(value);
            var formatted = formatRupiah(unformatted);
            
            // Set nilai yang sudah diformat
            $(this).val(formatted);
            
            // Perbaiki posisi cursor setelah format
            var newLength = formatted.length;
            var lengthDiff = newLength - originalLength;
            var newCursorPosition = Math.max(0, Math.min(cursorPosition + lengthDiff, formatted.length));
            
            // Set cursor position
            this.setSelectionRange(newCursorPosition, newCursorPosition);
        });
        
        function editBiaya(idCost, kdTipeBiaya, namaTipeBiaya, jumlahBiaya, periode) {
            $('#edit_id_cost').val(idCost);
            $('#edit_kd_tipe_biaya').val(kdTipeBiaya);
            // Format jumlah biaya saat edit
            $('#edit_jumlah_biaya').val(formatRupiah(jumlahBiaya));
            $('#edit_periode').val(periode);
            
            var modalEdit = new bootstrap.Modal(document.getElementById('modalEditBiaya'));
            modalEdit.show();
        }
        
        // Flag untuk mencegah multiple submission
        var isSubmittingTambah = false;
        var isSubmittingEdit = false;
        
        // Form validation untuk tambah
        $('#formTambahBiaya').on('submit', function(e) {
            if (isSubmittingTambah) {
                e.preventDefault();
                return false;
            }
            
            // Unformat rupiah sebelum validasi
            var jumlahBiaya = unformatRupiah($('#tambah_jumlah_biaya').val());
            
            if (jumlahBiaya <= 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Jumlah biaya harus > 0!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#tambah_jumlah_biaya').focus();
                });
                return false;
            }
            
            // Set nilai yang sudah di-unformat ke hidden input atau langsung ke field
            // Buat hidden input untuk submit nilai numerik
            if ($('#tambah_jumlah_biaya_hidden').length === 0) {
                $('<input>').attr({
                    type: 'hidden',
                    id: 'tambah_jumlah_biaya_hidden',
                    name: 'jumlah_biaya'
                }).appendTo('#formTambahBiaya');
            }
            $('#tambah_jumlah_biaya_hidden').val(jumlahBiaya);
            $('#tambah_jumlah_biaya').removeAttr('name'); // Hapus name dari input yang terformat
            
            isSubmittingTambah = true;
            $('#btnSimpanTambah').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...');
        });
        
        // Reset flag saat modal ditutup
        $('#modalTambahBiaya').on('hidden.bs.modal', function() {
            isSubmittingTambah = false;
            $('#btnSimpanTambah').prop('disabled', false).html('Simpan');
        });
        
        // Form validation untuk edit
        $('#formEditBiaya').on('submit', function(e) {
            if (isSubmittingEdit) {
                e.preventDefault();
                return false;
            }
            
            // Unformat rupiah sebelum validasi
            var jumlahBiaya = unformatRupiah($('#edit_jumlah_biaya').val());
            
            if (jumlahBiaya <= 0) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Jumlah biaya harus > 0!',
                    confirmButtonColor: '#667eea'
                }).then(() => {
                    $('#edit_jumlah_biaya').focus();
                });
                return false;
            }
            
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
                    // Set nilai yang sudah di-unformat ke hidden input atau langsung ke field
                    // Buat hidden input untuk submit nilai numerik
                    if ($('#edit_jumlah_biaya_hidden').length === 0) {
                        $('<input>').attr({
                            type: 'hidden',
                            id: 'edit_jumlah_biaya_hidden',
                            name: 'jumlah_biaya'
                        }).appendTo('#formEditBiaya');
                    }
                    $('#edit_jumlah_biaya_hidden').val(jumlahBiaya);
                    $('#edit_jumlah_biaya').removeAttr('name'); // Hapus name dari input yang terformat
                    
                    isSubmittingEdit = true;
                    $('#btnSimpanEdit').prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-2"></span>Menyimpan...');
                    $('#formEditBiaya')[0].submit();
                }
            });
        });
        
        // Reset flag saat modal ditutup
        $('#modalEditBiaya').on('hidden.bs.modal', function() {
            isSubmittingEdit = false;
            $('#btnSimpanEdit').prop('disabled', false).html('Simpan Perubahan');
        });
    </script>
</body>
</html>

