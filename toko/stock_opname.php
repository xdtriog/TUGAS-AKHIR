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

// Handle AJAX request untuk simpan stock opname (multiple barang)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'simpan_opname') {
    header('Content-Type: application/json');
    
    $barang_data = isset($_POST['barang']) ? $_POST['barang'] : [];
    
    if (!is_array($barang_data) || count($barang_data) == 0) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Mulai transaksi
    $conn->begin_transaction();
    
    try {
        require_once '../includes/uuid_generator.php';
        
        $opname_results = [];
        $total_selisih_pieces = 0;
        $total_uang = 0;
        
        // Process setiap barang
        foreach ($barang_data as $barang_item) {
            $kd_barang = isset($barang_item['kd_barang']) ? trim($barang_item['kd_barang']) : '';
            $jumlah_sebenarnya_pieces = isset($barang_item['jumlah_sebenarnya']) ? intval($barang_item['jumlah_sebenarnya']) : -1;
            
            if (empty($kd_barang) || $jumlah_sebenarnya_pieces < 0) {
                continue; // Skip jika data tidak valid
            }
            
            // Get data stock dan master barang
            $query_barang = "SELECT 
                s.JUMLAH_BARANG as JUMLAH_SISTEM,
                s.SATUAN as SATUAN_STOCK,
                mb.SATUAN_PERDUS,
                mb.AVG_HARGA_BELI_PIECES,
                mb.NAMA_BARANG,
                COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
                COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI
            FROM STOCK s
            INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
            LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
            LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
            WHERE s.KD_BARANG = ? AND s.KD_LOKASI = ?";
            $stmt_barang = $conn->prepare($query_barang);
            if (!$stmt_barang) {
                throw new Exception('Gagal prepare query barang: ' . $conn->error);
            }
            $stmt_barang->bind_param("ss", $kd_barang, $kd_lokasi);
            if (!$stmt_barang->execute()) {
                throw new Exception('Gagal execute query barang: ' . $stmt_barang->error);
            }
            $result_barang = $stmt_barang->get_result();
            
            if ($result_barang->num_rows == 0) {
                continue; // Skip jika barang tidak ditemukan
            }
            
            $barang_info = $result_barang->fetch_assoc();
            $jumlah_sistem_pieces = intval($barang_info['JUMLAH_SISTEM'] ?? 0);
            $satuan_stock = $barang_info['SATUAN_STOCK'] ?? 'PIECES';
            $satuan_perdus = intval($barang_info['SATUAN_PERDUS'] ?? 1);
            $avg_harga_beli = floatval($barang_info['AVG_HARGA_BELI_PIECES'] ?? 0);
            
            // Hitung selisih
            $selisih_pieces = $jumlah_sebenarnya_pieces - $jumlah_sistem_pieces;
            $uang_barang = $selisih_pieces * $avg_harga_beli;
            
            // Generate ID opname
            // Generate ID_OPNAME dengan format OPNM+UUID (total 16 karakter: OPNM=4, UUID=12)
            $id_opname = '';
            do {
                $uuid = ShortIdGenerator::generate(12, '');
                $id_opname = 'OPNM' . $uuid;
            } while (checkUUIDExists($conn, 'STOCK_OPNAME', 'ID_OPNAME', $id_opname));
            
            // Insert ke STOCK_OPNAME
            $insert_opname = "INSERT INTO STOCK_OPNAME 
                            (ID_OPNAME, KD_BARANG, KD_LOKASI, ID_USERS, JUMLAH_SEBENARNYA, JUMLAH_SISTEM, SELISIH, SATUAN, SATUAN_PERDUS, TOTAL_BARANG_PIECES, HARGA_BARANG_PIECES, TOTAL_UANG)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_opname = $conn->prepare($insert_opname);
            if (!$stmt_opname) {
                throw new Exception('Gagal prepare query opname: ' . $conn->error);
            }
            
            $stmt_opname->bind_param("ssssiiisiiid", $id_opname, $kd_barang, $kd_lokasi, $user_id, $jumlah_sebenarnya_pieces, $jumlah_sistem_pieces, $selisih_pieces, $satuan_stock, $satuan_perdus, $selisih_pieces, $avg_harga_beli, $uang_barang);
            if (!$stmt_opname->execute()) {
                throw new Exception('Gagal insert opname: ' . $stmt_opname->error);
            }
            
            // Update STOCK dengan jumlah sebenarnya (dalam PIECES)
            $update_stock = "UPDATE STOCK 
                            SET JUMLAH_BARANG = ?, 
                                LAST_UPDATED = CURRENT_TIMESTAMP,
                                UPDATED_BY = ?
                            WHERE KD_BARANG = ? AND KD_LOKASI = ?";
            $stmt_update_stock = $conn->prepare($update_stock);
            if (!$stmt_update_stock) {
                throw new Exception('Gagal prepare query update stock: ' . $conn->error);
            }
            $stmt_update_stock->bind_param("isss", $jumlah_sebenarnya_pieces, $user_id, $kd_barang, $kd_lokasi);
            if (!$stmt_update_stock->execute()) {
                throw new Exception('Gagal update stock: ' . $stmt_update_stock->error);
            }
            
            // Insert ke STOCK_HISTORY
            $id_history = '';
            do {
                // Generate ID_HISTORY_STOCK dengan format SKHY+UUID (total 16 karakter: SKHY=4, UUID=12)
                $uuid = ShortIdGenerator::generate(12, '');
                $id_history = 'SKHY' . $uuid;
            } while (checkUUIDExists($conn, 'STOCK_HISTORY', 'ID_HISTORY_STOCK', $id_history));
            
            $jumlah_awal_history = $jumlah_sistem_pieces;
            $jumlah_perubahan_history = $selisih_pieces;
            $jumlah_akhir_history = $jumlah_sebenarnya_pieces;
            $satuan_history = 'PIECES';
            
            $insert_history = "INSERT INTO STOCK_HISTORY 
                              (ID_HISTORY_STOCK, KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_AWAL, JUMLAH_PERUBAHAN, JUMLAH_AKHIR, TIPE_PERUBAHAN, REF, SATUAN)
                              VALUES (?, ?, ?, ?, ?, ?, ?, 'OPNAME', ?, ?)";
            $stmt_history = $conn->prepare($insert_history);
            if (!$stmt_history) {
                throw new Exception('Gagal prepare query insert history: ' . $conn->error);
            }
            $stmt_history->bind_param("ssssiiiss", $id_history, $kd_barang, $kd_lokasi, $user_id, $jumlah_awal_history, $jumlah_perubahan_history, $jumlah_akhir_history, $id_opname, $satuan_history);
            if (!$stmt_history->execute()) {
                throw new Exception('Gagal insert history: ' . $stmt_history->error);
            }
            
            // Simpan hasil untuk popup
            $opname_results[] = [
                'kd_barang' => $kd_barang,
                'nama_barang' => $barang_info['NAMA_BARANG'],
                'merek' => $barang_info['NAMA_MEREK'],
                'kategori' => $barang_info['NAMA_KATEGORI'],
                'jumlah_sistem' => $jumlah_sistem_pieces,
                'jumlah_sebenarnya' => $jumlah_sebenarnya_pieces,
                'selisih' => $selisih_pieces,
                'uang' => round($uang_barang, 2)
            ];
            
            $total_selisih_pieces += $selisih_pieces;
            $total_uang += $uang_barang;
        }
        
        // Commit transaksi
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Stock opname berhasil disimpan!',
            'results' => $opname_results,
            'total_selisih_pieces' => $total_selisih_pieces,
            'total_uang' => round($total_uang, 2)
        ]);
    } catch (Exception $e) {
        // Rollback transaksi
        $conn->rollback();
        
        $error_message = $e->getMessage();
        $error_file = $e->getFile();
        $error_line = $e->getLine();
        
        if ($conn->error) {
            $error_message .= ' | Database Error: ' . $conn->error;
        }
        
        $debug_info = '';
        if (isset($_GET['debug']) || true) {
            $debug_info = ' | File: ' . basename($error_file) . ' | Line: ' . $error_line;
        }
        
        echo json_encode([
            'success' => false, 
            'message' => $error_message . $debug_info,
            'error_detail' => [
                'message' => $e->getMessage(),
                'file' => basename($error_file),
                'line' => $error_line,
                'db_error' => $conn->error
            ]
        ]);
    } catch (Error $e) {
        // Rollback transaksi
        $conn->rollback();
        
        $error_message = $e->getMessage();
        $error_file = $e->getFile();
        $error_line = $e->getLine();
        
        echo json_encode([
            'success' => false, 
            'message' => 'Fatal Error: ' . $error_message . ' | File: ' . basename($error_file) . ' | Line: ' . $error_line,
            'error_detail' => [
                'message' => $e->getMessage(),
                'file' => basename($error_file),
                'line' => $error_line
            ]
        ]);
    }
    
    exit();
}

// Query untuk mendapatkan data stock dengan waktu terakhir stock opname
$query_stock = "SELECT 
    s.KD_BARANG,
    mb.NAMA_BARANG,
    mb.BERAT,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
    s.JUMLAH_BARANG as STOCK_SEKARANG,
    s.SATUAN,
    (
        SELECT MAX(so.WAKTU_OPNAME)
        FROM STOCK_OPNAME so
        WHERE so.KD_BARANG = s.KD_BARANG AND so.KD_LOKASI = s.KD_LOKASI
    ) as WAKTU_TERAKHIR_OPNAME
FROM STOCK s
INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
WHERE s.KD_LOKASI = ?
ORDER BY s.KD_BARANG ASC";

$stmt_stock = $conn->prepare($query_stock);
$stmt_stock->bind_param("s", $kd_lokasi);
$stmt_stock->execute();
$result_stock = $stmt_stock->get_result();

// Format waktu terakhir stock opname
function formatWaktuTerakhirOpname($waktu) {
    if (empty($waktu) || $waktu == null) {
        return '-';
    }
    
    // Set timezone ke Asia/Jakarta (WIB)
    date_default_timezone_set('Asia/Jakarta');
    
    // Buat DateTime dengan timezone Asia/Jakarta
    $timezone = new DateTimeZone('Asia/Jakarta');
    $date = new DateTime($waktu, $timezone);
    $now = new DateTime('now', $timezone);
    $diff = $now->diff($date);
    
    $waktu_formatted = $date->format('d/m/Y H:i') . ' WIB';
    
    // Hitung selisih waktu
    $selisih_text = '';
    if ($diff->y > 0) {
        $selisih_text = $diff->y . ' tahun lalu';
    } elseif ($diff->m > 0) {
        $selisih_text = $diff->m . ' bulan lalu';
    } elseif ($diff->d > 0) {
        $selisih_text = $diff->d . ' hari lalu';
    } elseif ($diff->h > 0) {
        $selisih_text = $diff->h . ' jam lalu';
    } elseif ($diff->i > 0) {
        $selisih_text = $diff->i . ' menit lalu';
    } else {
        $selisih_text = 'baru saja';
    }
    
    return $waktu_formatted . ' (' . $selisih_text . ')';
}

// Set active page untuk sidebar
$active_page = 'stock_opname';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Toko - Stock Opname</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .table-barang-opname input[type="number"] {
            text-align: right;
        }
    </style>
</head>
<body class="dashboard-body">
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Toko <?php echo htmlspecialchars($nama_lokasi); ?> - Stock Opname</h1>
            <?php if (!empty($alamat_lokasi)): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($alamat_lokasi); ?></p>
            <?php endif; ?>
        </div>

        <!-- Button untuk buka modal stock opname -->
        <div class="mb-4">
            <button class="btn btn-primary" onclick="bukaModalOpname()">
                <i class="bi bi-clipboard-check"></i> Stock Opname
            </button>
        </div>

        <!-- Table Stock Opname -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tableStockOpname" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Kode Barang</th>
                            <th>Merek Barang</th>
                            <th>Kategori Barang</th>
                            <th>Nama Barang</th>
                            <th>Berat (gr)</th>
                            <th>Waktu Terakhir Stock Opname</th>
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
                                    <td><?php echo formatWaktuTerakhirOpname($row['WAKTU_TERAKHIR_OPNAME']); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">Tidak ada data stock</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Stock Opname -->
    <div class="modal fade" id="modalStockOpname" tabindex="-1" aria-labelledby="modalStockOpnameLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalStockOpnameLabel">Stock Opname</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="formOpname">
                        <div class="mb-3">
                            <label class="form-label fw-bold">Input Jumlah Sebenarnya (pieces) <span class="text-danger">*</span></label>
                            <small class="text-muted d-block mb-2">Masukkan jumlah sebenarnya untuk setiap barang. Selisih akan ditampilkan setelah menyimpan.</small>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered table-barang-opname">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 12%;">Kode Barang</th>
                                            <th style="width: 15%;">Merek</th>
                                            <th style="width: 15%;">Kategori</th>
                                            <th style="width: 20%;">Nama Barang</th>
                                            <th style="width: 10%;">Berat (gr)</th>
                                            <th style="width: 28%;">Jumlah Sebenarnya (pieces) <span class="text-danger">*</span></th>
                                        </tr>
                                    </thead>
                                    <tbody id="tbodyBarang">
                                        <?php 
                                        $result_stock->data_seek(0);
                                        if ($result_stock && $result_stock->num_rows > 0): 
                                            while ($row = $result_stock->fetch_assoc()): 
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($row['KD_BARANG']); ?></td>
                                                <td><?php echo htmlspecialchars($row['NAMA_MEREK']); ?></td>
                                                <td><?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?></td>
                                                <td><?php echo htmlspecialchars($row['NAMA_BARANG']); ?></td>
                                                <td class="text-center"><?php echo number_format($row['BERAT'], 0, ',', '.'); ?></td>
                                                <td>
                                                    <input type="number" class="form-control form-control-sm text-end" 
                                                        name="jumlah_sebenarnya_<?php echo htmlspecialchars($row['KD_BARANG']); ?>" 
                                                        data-kd-barang="<?php echo htmlspecialchars($row['KD_BARANG']); ?>" 
                                                        data-sistem="<?php echo $row['STOCK_SEKARANG']; ?>" 
                                                        placeholder="Masukkan jumlah sebenarnya" 
                                                        min="0" step="1">
                                                </td>
                                            </tr>
                                        <?php 
                                            endwhile; 
                                        else: 
                                        ?>
                                            <tr>
                                                <td colspan="6" class="text-center text-muted">Tidak ada data barang</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" onclick="simpanOpname()">Simpan</button>
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
            
            $('#tableStockOpname').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data stock opname'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[0, 'asc']],
                scrollX: true,
                autoWidth: false
            }).on('error.dt', function(e, settings, techNote, message) {
                console.log('DataTables error suppressed:', message);
                return false;
            });
        });

        function bukaModalOpname() {
            // Reset form
            $('#formOpname')[0].reset();
            
            // Buka modal
            $('#modalStockOpname').modal('show');
        }

        function simpanOpname() {
            // Collect barang data
            var barang = [];
            $('input[data-kd-barang]').each(function() {
                var kdBarang = $(this).data('kd-barang');
                var jumlahSebenarnya = parseInt($(this).val()) || -1;
                
                if (jumlahSebenarnya >= 0) {
                    barang.push({
                        kd_barang: kdBarang,
                        jumlah_sebenarnya: jumlahSebenarnya
                    });
                }
            });

            if (barang.length === 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Tidak ada barang yang diinput!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }

            // Konfirmasi
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi',
                text: 'Apakah Anda yakin ingin menyimpan stock opname untuk ' + barang.length + ' barang?',
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
                            action: 'simpan_opname',
                            barang: barang
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                // Tutup modal dulu
                                $('#modalStockOpname').modal('hide');
                                
                                // Buat HTML untuk popup hasil
                                var htmlContent = '<div class="text-start">';
                                htmlContent += '<h6 class="mb-3">Hasil Stock Opname:</h6>';
                                htmlContent += '<div class="table-responsive">';
                                htmlContent += '<table class="table table-sm table-bordered">';
                                htmlContent += '<thead class="table-light"><tr>';
                                htmlContent += '<th>Kode Barang</th>';
                                htmlContent += '<th>Merek</th>';
                                htmlContent += '<th>Kategori</th>';
                                htmlContent += '<th>Nama Barang</th>';
                                htmlContent += '<th class="text-end">Jumlah Sistem (pieces)</th>';
                                htmlContent += '<th class="text-end">Jumlah Sebenarnya (pieces)</th>';
                                htmlContent += '<th class="text-center">Selisih (pieces)</th>';
                                htmlContent += '<th class="text-end">Nilai (Rp)</th>';
                                htmlContent += '</tr></thead><tbody>';
                                
                                response.results.forEach(function(result) {
                                    var selisihClass = result.selisih > 0 ? 'text-success fw-bold' : (result.selisih < 0 ? 'text-danger fw-bold' : 'text-muted');
                                    var selisihText = '';
                                    if (result.selisih > 0) {
                                        selisihText = '<span class="badge bg-success">+ ' + numberFormat(result.selisih) + ' (LEBIH)</span>';
                                    } else if (result.selisih < 0) {
                                        selisihText = '<span class="badge bg-danger">' + numberFormat(result.selisih) + ' (KURANG)</span>';
                                    } else {
                                        selisihText = '<span class="badge bg-secondary">0 (SESUAI)</span>';
                                    }
                                    var uangText = 'Rp. ' + numberFormat(result.uang);
                                    
                                    htmlContent += '<tr>';
                                    htmlContent += '<td>' + result.kd_barang + '</td>';
                                    htmlContent += '<td>' + (result.merek || '-') + '</td>';
                                    htmlContent += '<td>' + (result.kategori || '-') + '</td>';
                                    htmlContent += '<td>' + result.nama_barang + '</td>';
                                    htmlContent += '<td class="text-end">' + numberFormat(result.jumlah_sistem) + '</td>';
                                    htmlContent += '<td class="text-end">' + numberFormat(result.jumlah_sebenarnya) + '</td>';
                                    htmlContent += '<td class="text-center ' + selisihClass + '">' + selisihText + '</td>';
                                    htmlContent += '<td class="text-end ' + selisihClass + '">' + uangText + '</td>';
                                    htmlContent += '</tr>';
                                });
                                
                                htmlContent += '</tbody></table>';
                                htmlContent += '</div>';
                                
                                // Total
                                var totalSelisihClass = response.total_selisih_pieces > 0 ? 'text-success fw-bold' : (response.total_selisih_pieces < 0 ? 'text-danger fw-bold' : 'text-muted');
                                var totalSelisihText = '';
                                if (response.total_selisih_pieces > 0) {
                                    totalSelisihText = '<span class="badge bg-success">+ ' + numberFormat(response.total_selisih_pieces) + ' (LEBIH)</span>';
                                } else if (response.total_selisih_pieces < 0) {
                                    totalSelisihText = '<span class="badge bg-danger">' + numberFormat(response.total_selisih_pieces) + ' (KURANG)</span>';
                                } else {
                                    totalSelisihText = '<span class="badge bg-secondary">0 (SESUAI)</span>';
                                }
                                var totalUangText = 'Rp. ' + numberFormat(response.total_uang);
                                
                                htmlContent += '<div class="mt-3 p-3 bg-light rounded border">';
                                htmlContent += '<div class="mb-2"><strong>Total Selisih: </strong>' + totalSelisihText + '</div>';
                                htmlContent += '<div><strong>Total Nilai: <span class="' + totalSelisihClass + '">' + totalUangText + '</span></strong></div>';
                                htmlContent += '</div>';
                                htmlContent += '</div>';
                                
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Berhasil!',
                                    html: htmlContent,
                                    confirmButtonText: 'OK',
                                    confirmButtonColor: '#667eea',
                                    width: '900px'
                                }).then(() => {
                                    location.reload();
                                });
                            } else {
                                var errorText = response.message || 'Terjadi kesalahan tidak diketahui';
                                if (response.error_detail) {
                                    errorText += '\n\nDetail Error:';
                                    if (response.error_detail.file) {
                                        errorText += '\nFile: ' + response.error_detail.file;
                                    }
                                    if (response.error_detail.line) {
                                        errorText += '\nLine: ' + response.error_detail.line;
                                    }
                                    if (response.error_detail.db_error) {
                                        errorText += '\nDatabase Error: ' + response.error_detail.db_error;
                                    }
                                }
                                
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Gagal!',
                                    text: errorText,
                                    confirmButtonColor: '#e74c3c',
                                    width: '700px'
                                });
                                
                                console.error('Stock Opname Error:', response);
                            }
                        },
                        error: function(xhr, status, error) {
                            var errorMessage = 'Terjadi kesalahan saat menyimpan stock opname!';
                            
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

        function numberFormat(num) {
            if (!num && num !== 0) return '0';
            
            // Konversi ke number jika string
            var number = typeof num === 'string' ? parseFloat(num) : num;
            
            // Cek jika bukan angka valid
            if (isNaN(number)) return '0';
            
            // Pisahkan bagian negatif, integer, dan desimal
            var isNegative = number < 0;
            var absNumber = Math.abs(number);
            
            // Pisahkan integer dan desimal
            var parts = absNumber.toString().split('.');
            var integerPart = parts[0] || '0';
            var decimalPart = parts[1] || '';
            
            // Format integer dengan titik sebagai pemisah ribuan
            var formattedInteger = integerPart.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            
            // Gabungkan dengan desimal jika ada
            var result = formattedInteger;
            if (decimalPart) {
                result += ',' + decimalPart;
            }
            
            // Tambahkan tanda negatif jika perlu
            if (isNegative) {
                result = '-' + result;
            }
            
            return result;
        }

        // Reset modal saat ditutup
        $('#modalStockOpname').on('hidden.bs.modal', function() {
            $('#formOpname')[0].reset();
        });
    </script>
</body>
</html>
