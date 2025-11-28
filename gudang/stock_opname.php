<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login dan adalah staff gudang
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Cek apakah user adalah staff gudang (format ID: GDNG+UUID)
if (substr($user_id, 0, 4) != 'GDNG') {
    header("Location: ../index.php");
    exit();
}

// Get user info dan lokasi gudang
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
$nama_lokasi = $user_data['NAMA_LOKASI'] ?? 'Gudang';

// Get alamat lokasi
$query_alamat = "SELECT ALAMAT_LOKASI FROM MASTER_LOKASI WHERE KD_LOKASI = ?";
$stmt_alamat = $conn->prepare($query_alamat);
$stmt_alamat->bind_param("s", $kd_lokasi);
$stmt_alamat->execute();
$result_alamat = $stmt_alamat->get_result();
$alamat_lokasi = $result_alamat->num_rows > 0 ? $result_alamat->fetch_assoc()['ALAMAT_LOKASI'] : '';

// Handle AJAX request untuk get data barang
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['get_barang_data'])) {
    header('Content-Type: application/json');
    
    $kd_barang = isset($_GET['kd_barang']) ? trim($_GET['kd_barang']) : '';
    
    if (empty($kd_barang)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    $query_barang = "SELECT mb.SATUAN_PERDUS, mb.BERAT
                    FROM MASTER_BARANG mb
                    WHERE mb.KD_BARANG = ?";
    $stmt_barang = $conn->prepare($query_barang);
    if (!$stmt_barang) {
        echo json_encode(['success' => false, 'message' => 'Gagal prepare query: ' . $conn->error]);
        exit();
    }
    $stmt_barang->bind_param("s", $kd_barang);
    if (!$stmt_barang->execute()) {
        echo json_encode(['success' => false, 'message' => 'Gagal execute query: ' . $stmt_barang->error]);
        exit();
    }
    $result_barang = $stmt_barang->get_result();
    
    if ($result_barang->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Data barang tidak ditemukan!']);
        exit();
    }
    
    $barang_data = $result_barang->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'data' => [
            'satuan_perdus' => intval($barang_data['SATUAN_PERDUS'] ?? 1),
            'berat' => intval($barang_data['BERAT'] ?? 0)
        ]
    ]);
    exit();
}

// Handle AJAX request untuk simpan stock opname
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'simpan_opname') {
    header('Content-Type: application/json');
    
    $kd_barang = isset($_POST['kd_barang']) ? trim($_POST['kd_barang']) : '';
    $jumlah_sebenarnya_dus = isset($_POST['jumlah_sebenarnya']) ? intval($_POST['jumlah_sebenarnya']) : 0;
    $satuan = 'DUS'; // Selalu DUS sesuai form
    
    if (empty($kd_barang) || $jumlah_sebenarnya_dus < 0) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Mulai transaksi
    $conn->begin_transaction();
    
    try {
        require_once '../includes/uuid_generator.php';
        
        // Get data stock dan master barang
        $query_barang = "SELECT 
            s.JUMLAH_BARANG as JUMLAH_SISTEM,
            s.SATUAN as SATUAN_STOCK,
            mb.SATUAN_PERDUS,
            mb.AVG_HARGA_BELI_PIECES
        FROM STOCK s
        INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
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
            throw new Exception('Data barang tidak ditemukan!');
        }
        
        $barang_data = $result_barang->fetch_assoc();
        $jumlah_sistem = intval($barang_data['JUMLAH_SISTEM'] ?? 0);
        $satuan_stock = $barang_data['SATUAN_STOCK'] ?? 'DUS';
        $satuan_perdus = intval($barang_data['SATUAN_PERDUS'] ?? 1);
        $avg_harga_beli = floatval($barang_data['AVG_HARGA_BELI_PIECES'] ?? 0);
        
        // Stock gudang selalu DUS, jadi:
        // JUMLAH_SISTEM: dari STOCK, disesuaikan satuan (jika DUS langsung, jika PIECES dikonversi ke DUS)
        $jumlah_sistem_display = $jumlah_sistem;
        if ($satuan_stock == 'PIECES') {
            $jumlah_sistem_display = floor($jumlah_sistem / $satuan_perdus);
        }
        
        // JUMLAH_SEBENARNYA: mengikuti satuan JUMLAH_SISTEM (karena gudang selalu DUS, maka dalam DUS)
        // Sudah dalam DUS dari form input
        
        // SELISIH: dalam satuan yang sama dengan JUMLAH_SISTEM (DUS)
        $selisih_dus = $jumlah_sebenarnya_dus - $jumlah_sistem_display;
        
        // TOTAL_BARANG_PIECES: selisih dalam pieces (bisa negatif)
        // Jika selisih dalam DUS, dikali satuan_perdus; jika pieces langsung
        if ($satuan_stock == 'DUS') {
            $total_barang_pieces = $selisih_dus * $satuan_perdus; // Bisa negatif
        } else {
            // Jika satuan stock PIECES, selisih sudah dalam pieces
            $selisih_pieces = $selisih_dus * $satuan_perdus; // Konversi selisih DUS ke pieces
            $total_barang_pieces = $selisih_pieces; // Bisa negatif
        }
        
        // HARGA_BARANG_PIECES: diambil dari MASTER_BARANG.AVG_HARGA_BELI_PIECES (per piece)
        $harga_barang_pieces = $avg_harga_beli;
        
        // TOTAL_UANG: TOTAL_BARANG_PIECES * HARGA_BARANG_PIECES (bisa negatif jika selisih negatif)
        $total_uang = $total_barang_pieces * $harga_barang_pieces;
        
        // Untuk update STOCK, konversi ke pieces jika diperlukan
        $jumlah_sebenarnya_pieces = $jumlah_sebenarnya_dus * $satuan_perdus;
        
        // Generate ID opname
        $id_opname = '';
        do {
            $id_opname = ShortIdGenerator::generate(16, '');
        } while (checkUUIDExists($conn, 'STOCK_OPNAME', 'ID_OPNAME', $id_opname));
        
        // Insert ke STOCK_OPNAME
        $insert_opname = "INSERT INTO STOCK_OPNAME 
                        (ID_OPNAME, KD_BARANG, KD_LOKASI, ID_USERS, JUMLAH_SEBENARNYA, JUMLAH_SISTEM, SELISIH, SATUAN, SATUAN_PERDUS, TOTAL_BARANG_PIECES, HARGA_BARANG_PIECES, TOTAL_UANG)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_opname = $conn->prepare($insert_opname);
        if (!$stmt_opname) {
            throw new Exception('Gagal prepare query opname: ' . $conn->error);
        }
        
        // Untuk STOCK_OPNAME:
        // JUMLAH_SISTEM: dari STOCK, disesuaikan satuan (dalam satuan yang sama dengan STOCK)
        // JUMLAH_SEBENARNYA: mengikuti satuan JUMLAH_SISTEM (dalam satuan yang sama)
        // SATUAN: mengikuti satuan JUMLAH_SISTEM
        // SELISIH: dalam satuan yang sama dengan JUMLAH_SISTEM
        // SATUAN_PERDUS: dari MASTER_BARANG
        // TOTAL_BARANG_PIECES: selisih dalam pieces
        // HARGA_BARANG_PIECES: dari MASTER_BARANG.AVG_HARGA_BELI_PIECES
        // TOTAL_UANG: TOTAL_BARANG_PIECES * HARGA_BARANG_PIECES
        $stmt_opname->bind_param("ssssiiisiiid", $id_opname, $kd_barang, $kd_lokasi, $user_id, $jumlah_sebenarnya_dus, $jumlah_sistem_display, $selisih_dus, $satuan_stock, $satuan_perdus, $total_barang_pieces, $harga_barang_pieces, $total_uang);
        if (!$stmt_opname->execute()) {
            throw new Exception('Gagal insert opname: ' . $stmt_opname->error);
        }
        
        // Update STOCK dengan jumlah sebenarnya
        // Karena stock gudang selalu DUS, update dengan jumlah sebenarnya dalam DUS
        // Tapi STOCK menyimpan dalam satuan yang ada (DUS atau PIECES), jadi:
        // - Jika satuan stock DUS: update dengan jumlah_sebenarnya_dus
        // - Jika satuan stock PIECES: update dengan jumlah_sebenarnya_pieces
        $jumlah_update_stock = $jumlah_sebenarnya_dus;
        if ($satuan_stock == 'PIECES') {
            $jumlah_update_stock = $jumlah_sebenarnya_pieces;
        }
        
        $update_stock = "UPDATE STOCK 
                        SET JUMLAH_BARANG = ?, 
                            LAST_UPDATED = CURRENT_TIMESTAMP,
                            UPDATED_BY = ?
                        WHERE KD_BARANG = ? AND KD_LOKASI = ?";
        $stmt_update_stock = $conn->prepare($update_stock);
        if (!$stmt_update_stock) {
            throw new Exception('Gagal prepare query update stock: ' . $conn->error);
        }
        $stmt_update_stock->bind_param("isss", $jumlah_update_stock, $user_id, $kd_barang, $kd_lokasi);
        if (!$stmt_update_stock->execute()) {
            throw new Exception('Gagal update stock: ' . $stmt_update_stock->error);
        }
        
        // Insert ke STOCK_HISTORY
        $id_history = '';
        do {
            $id_history = ShortIdGenerator::generate(16, '');
        } while (checkUUIDExists($conn, 'STOCK_HISTORY', 'ID_HISTORY_STOCK', $id_history));
        
        // Untuk STOCK_HISTORY, semua dalam DUS (karena stock gudang selalu DUS)
        // JUMLAH_AWAL: JUMLAH_SISTEM (dalam DUS)
        // JUMLAH_PERUBAHAN: SELISIH (dalam DUS, bisa positif atau negatif)
        // JUMLAH_AKHIR: JUMLAH_SEBENARNYA (dalam DUS)
        // SATUAN: 'DUS'
        $jumlah_awal_history = $jumlah_sistem_display; // JUMLAH_SISTEM dalam DUS
        $jumlah_perubahan_history = $selisih_dus; // SELISIH dalam DUS
        $jumlah_akhir_history = $jumlah_sebenarnya_dus; // JUMLAH_SEBENARNYA dalam DUS
        $satuan_history = 'DUS'; // Stock gudang selalu DUS
        
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
        
        // Commit transaksi
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Stock opname berhasil disimpan!']);
    } catch (Exception $e) {
        // Rollback transaksi
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
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
    
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    
    // Buat DateTime dengan timezone Asia/Jakarta
    $timezone = new DateTimeZone('Asia/Jakarta');
    $date = new DateTime($waktu, $timezone);
    $now = new DateTime('now', $timezone);
    $diff = $now->diff($date);
    
    $tanggal_formatted = $date->format('d') . ' ' . $bulan[(int)$date->format('m')] . ' ' . $date->format('Y');
    $waktu_formatted = $date->format('H:i') . ' WIB';
    
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
    
    return $tanggal_formatted . ' ' . $waktu_formatted . ' (' . $selisih_text . ')';
}

// Set active page untuk sidebar
$active_page = 'stock_opname';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gudang - Stock Opname</title>
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
            <h1 class="page-title">Gudang <?php echo htmlspecialchars($nama_lokasi); ?> - Stock Opname</h1>
            <?php if (!empty($alamat_lokasi)): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($alamat_lokasi); ?></p>
            <?php endif; ?>
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
                            <th>Stock Sekarang</th>
                            <th>Satuan</th>
                            <th>Waktu Terakhir Stock Opname</th>
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
                                    <td><?php echo number_format($row['STOCK_SEKARANG'], 0, ',', '.'); ?></td>
                                    <td><?php echo htmlspecialchars($row['SATUAN']); ?></td>
                                    <td><?php echo formatWaktuTerakhirOpname($row['WAKTU_TERAKHIR_OPNAME']); ?></td>
                                    <td>
                                        <button class="btn-view btn-sm" onclick="bukaModalOpname('<?php echo htmlspecialchars($row['KD_BARANG']); ?>', '<?php echo htmlspecialchars($row['NAMA_MEREK']); ?>', '<?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?>', '<?php echo htmlspecialchars($row['NAMA_BARANG']); ?>', <?php echo $row['BERAT']; ?>, <?php echo $row['STOCK_SEKARANG']; ?>, '<?php echo htmlspecialchars($row['SATUAN']); ?>')">Stock Opname</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" class="text-center text-muted">Tidak ada data stock</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Stock Opname -->
    <div class="modal fade" id="modalStockOpname" tabindex="-1" aria-labelledby="modalStockOpnameLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalStockOpnameLabel">Stock Opname</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="formOpname">
                        <input type="hidden" id="opname_kd_barang" name="kd_barang">
                        <input type="hidden" id="opname_satuan" name="satuan" value="DUS">
                        
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Merek Barang</label>
                                <input type="text" class="form-control form-control-sm" id="opname_merek_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Kode Barang</label>
                                <input type="text" class="form-control form-control-sm" id="opname_kode_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Kategori Barang</label>
                                <input type="text" class="form-control form-control-sm" id="opname_kategori_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Berat (gr)</label>
                                <input type="text" class="form-control form-control-sm" id="opname_berat" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold small">Nama Barang</label>
                                <input type="text" class="form-control form-control-sm" id="opname_nama_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Jumlah di sistem (dus)</label>
                                <input type="text" class="form-control form-control-sm" id="opname_stock_sistem" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Jumlah sebenarnya (dus) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control form-control-sm" id="opname_jumlah_sebenarnya" name="jumlah_sebenarnya" min="0" step="1" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold small">Selisih (dus)</label>
                                <input type="text" class="form-control form-control-sm" id="opname_selisih" readonly style="background-color: #e9ecef;">
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
                order: [[0, 'asc']], // Sort by Kode Barang ascending
                columnDefs: [
                    { orderable: false, targets: [8] } // Disable sorting on Action column
                ],
                scrollX: true,
                autoWidth: false
            }).on('error.dt', function(e, settings, techNote, message) {
                console.log('DataTables error suppressed:', message);
                return false;
            });
        });

        function bukaModalOpname(kdBarang, namaMerek, namaKategori, namaBarang, berat, stockSistem, satuan) {
            // Get data barang untuk mendapatkan satuan perdus
            $.ajax({
                url: '',
                method: 'GET',
                data: {
                    get_barang_data: '1',
                    kd_barang: kdBarang
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var satuanPerdus = response.data.satuan_perdus || 1;
                        
                        // Konversi stock sistem ke DUS untuk display
                        var stockSistemDus = stockSistem;
                        if (satuan == 'PIECES') {
                            stockSistemDus = Math.floor(stockSistem / satuanPerdus);
                        }
                        
                        // Set form values
                        $('#opname_kd_barang').val(kdBarang);
                        $('#opname_merek_barang').val(namaMerek);
                        $('#opname_kategori_barang').val(namaKategori);
                        $('#opname_kode_barang').val(kdBarang);
                        $('#opname_nama_barang').val(namaBarang);
                        $('#opname_berat').val(numberFormat(berat));
                        $('#opname_stock_sistem').val(numberFormat(stockSistemDus));
                        $('#opname_jumlah_sebenarnya').val('');
                        $('#opname_selisih').val('');
                        
                        // Store data untuk perhitungan
                        $('#opname_stock_sistem').data('stock-sistem-dus', stockSistemDus);
                        $('#opname_stock_sistem').data('satuan-perdus', satuanPerdus);
                        
                        // Buka modal
                        $('#modalStockOpname').modal('show');
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: response.message || 'Gagal memuat data barang!',
                            confirmButtonColor: '#e74c3c'
                        });
                    }
                },
                error: function() {
                    // Jika AJAX gagal, tetap buka modal dengan data yang ada
                    var stockSistemDus = stockSistem;
                    if (satuan == 'PIECES') {
                        stockSistemDus = Math.floor(stockSistem / 1); // Default satuan perdus = 1
                    }
                    
                    $('#opname_kd_barang').val(kdBarang);
                    $('#opname_merek_barang').val(namaMerek);
                    $('#opname_kategori_barang').val(namaKategori);
                    $('#opname_kode_barang').val(kdBarang);
                    $('#opname_nama_barang').val(namaBarang);
                    $('#opname_berat').val(numberFormat(berat));
                    $('#opname_stock_sistem').val(numberFormat(stockSistemDus));
                    $('#opname_jumlah_sebenarnya').val('');
                    $('#opname_selisih').val('');
                    
                    $('#opname_stock_sistem').data('stock-sistem-dus', stockSistemDus);
                    $('#opname_stock_sistem').data('satuan-perdus', 1);
                    
                    $('#modalStockOpname').modal('show');
                }
            });
        }


        // Event listener untuk hitung selisih
        $(document).on('input', '#opname_jumlah_sebenarnya', function() {
            hitungSelisih();
        });

        function hitungSelisih() {
            var stockSistemDus = parseInt($('#opname_stock_sistem').data('stock-sistem-dus')) || 0;
            var jumlahSebenarnya = parseInt($('#opname_jumlah_sebenarnya').val()) || 0;
            
            // Hitung selisih dalam DUS
            var selisihDus = jumlahSebenarnya - stockSistemDus;
            
            // Format selisih
            var selisihText = numberFormat(Math.abs(selisihDus));
            if (selisihDus > 0) {
                selisihText = '+' + selisihText + ' (Lebih)';
            } else if (selisihDus < 0) {
                selisihText = '-' + selisihText + ' (Kurang)';
            } else {
                selisihText = '0 (Sesuai)';
            }
            
            $('#opname_selisih').val(selisihText);
        }

        function simpanOpname() {
            // Validasi form
            if (!$('#formOpname')[0].checkValidity()) {
                $('#formOpname')[0].reportValidity();
                return;
            }

            var kdBarang = $('#opname_kd_barang').val();
            var jumlahSebenarnya = parseInt($('#opname_jumlah_sebenarnya').val()) || 0;

            if (jumlahSebenarnya < 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Jumlah sebenarnya tidak boleh negatif!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }

            // Konfirmasi
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi',
                text: 'Apakah Anda yakin ingin menyimpan stock opname ini?',
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
                            kd_barang: kdBarang,
                            jumlah_sebenarnya: jumlahSebenarnya
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
                                    $('#modalStockOpname').modal('hide');
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
            return num ? num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '0';
        }

        // Reset modal saat ditutup
        $('#modalStockOpname').on('hidden.bs.modal', function() {
            $('#formOpname')[0].reset();
            $('#opname_selisih').val('');
        });
    </script>
</body>
</html>

