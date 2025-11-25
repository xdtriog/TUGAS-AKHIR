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
    
    $query_barang = "SELECT mb.SATUAN_PERDUS, mb.AVG_HARGA_BELI_PIECES
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
            'avg_harga_beli' => floatval($barang_data['AVG_HARGA_BELI_PIECES'] ?? 0)
        ]
    ]);
    exit();
}

// Handle AJAX request untuk simpan mutasi barang rusak
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'simpan_mutasi') {
    header('Content-Type: application/json');
    
    $kd_barang = isset($_POST['kd_barang']) ? trim($_POST['kd_barang']) : '';
    $jumlah_rusak_dus = isset($_POST['jumlah_rusak']) ? intval($_POST['jumlah_rusak']) : 0;
    
    if (empty($kd_barang)) {
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
            throw new Exception('Gagal execute query barang: ' . $conn->error);
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
        // Konversi jumlah sistem ke DUS jika satuan stock PIECES
        $jumlah_sistem_dus = $jumlah_sistem;
        if ($satuan_stock == 'PIECES') {
            $jumlah_sistem_dus = floor($jumlah_sistem / $satuan_perdus);
        }
        
        // Validasi: jumlah rusak tidak boleh melebihi stock sistem
        if (abs($jumlah_rusak_dus) > $jumlah_sistem_dus) {
            throw new Exception('Jumlah mutasi tidak boleh melebihi stock sistem!');
        }
        
        // Hitung stock akhir (dalam DUS)
        $stock_akhir_dus = $jumlah_sistem_dus - $jumlah_rusak_dus;
        if ($stock_akhir_dus < 0) {
            $stock_akhir_dus = 0;
        }
        
        // Untuk MUTASI_BARANG_RUSAK, karena ini barang rusak, nilainya harus negatif (mengurangi stock)
        // JUMLAH_MUTASI_DUS: negatif karena mengurangi stock
        $jumlah_mutasi_dus = -$jumlah_rusak_dus;
        
        // TOTAL_BARANG_PIECES: jumlah mutasi dalam pieces (negatif karena rusak)
        $total_barang_pieces = $jumlah_mutasi_dus * $satuan_perdus;
        
        // HARGA_BARANG_PIECES: diambil dari MASTER_BARANG.AVG_HARGA_BELI_PIECES (per piece, selalu positif)
        $harga_barang_pieces = $avg_harga_beli;
        
        // TOTAL_UANG: TOTAL_BARANG_PIECES * HARGA_BARANG_PIECES (negatif karena TOTAL_BARANG_PIECES negatif)
        $total_uang = $total_barang_pieces * $harga_barang_pieces;
        
        // Generate ID mutasi
        $id_mutasi = '';
        do {
            $id_mutasi = ShortIdGenerator::generate(16, '');
        } while (checkUUIDExists($conn, 'MUTASI_BARANG_RUSAK', 'ID_MUTASI_BARANG_RUSAK', $id_mutasi));
        
        // Insert ke MUTASI_BARANG_RUSAK
        // JUMLAH_MUTASI_DUS, TOTAL_BARANG_PIECES, dan TOTAL_UANG harus negatif karena barang rusak
        $insert_mutasi = "INSERT INTO MUTASI_BARANG_RUSAK 
                        (ID_MUTASI_BARANG_RUSAK, KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_MUTASI_DUS, SATUAN_PERDUS, TOTAL_BARANG_PIECES, HARGA_BARANG_PIECES, TOTAL_UANG)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt_mutasi = $conn->prepare($insert_mutasi);
        if (!$stmt_mutasi) {
            throw new Exception('Gagal prepare query mutasi: ' . $conn->error);
        }
        $stmt_mutasi->bind_param("ssssiiidd", $id_mutasi, $kd_barang, $kd_lokasi, $user_id, $jumlah_mutasi_dus, $satuan_perdus, $total_barang_pieces, $harga_barang_pieces, $total_uang);
        if (!$stmt_mutasi->execute()) {
            throw new Exception('Gagal insert mutasi: ' . $stmt_mutasi->error);
        }
        
        // Update STOCK dengan stock akhir
        // Stock gudang selalu DUS, tapi STOCK menyimpan dalam satuan yang ada (DUS atau PIECES)
        // Jadi:
        // - Jika satuan stock DUS: update dengan jumlah_sebenarnya_dus
        // - Jika satuan stock PIECES: update dengan jumlah_sebenarnya_pieces
        $jumlah_update_stock = $stock_akhir_dus;
        if ($satuan_stock == 'PIECES') {
            $jumlah_update_stock = $stock_akhir_dus * $satuan_perdus;
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
        // JUMLAH_PERUBAHAN: -JUMLAH_RUSAK (dalam DUS, negatif karena mengurangi stock)
        // JUMLAH_AKHIR: STOCK_AKHIR (dalam DUS)
        // SATUAN: 'DUS'
        $jumlah_awal_history = $jumlah_sistem_dus; // JUMLAH_SISTEM dalam DUS
        $jumlah_perubahan_history = -$jumlah_rusak_dus; // Negatif karena mengurangi stock
        $jumlah_akhir_history = $stock_akhir_dus; // STOCK_AKHIR dalam DUS
        $satuan_history = 'DUS'; // Stock gudang selalu DUS
        
        $insert_history = "INSERT INTO STOCK_HISTORY 
                          (ID_HISTORY_STOCK, KD_BARANG, KD_LOKASI, UPDATED_BY, JUMLAH_AWAL, JUMLAH_PERUBAHAN, JUMLAH_AKHIR, TIPE_PERUBAHAN, REF, SATUAN)
                          VALUES (?, ?, ?, ?, ?, ?, ?, 'RUSAK', ?, ?)";
        $stmt_history = $conn->prepare($insert_history);
        if (!$stmt_history) {
            throw new Exception('Gagal prepare query insert history: ' . $conn->error);
        }
        $stmt_history->bind_param("ssssiiiss", $id_history, $kd_barang, $kd_lokasi, $user_id, $jumlah_awal_history, $jumlah_perubahan_history, $jumlah_akhir_history, $id_mutasi, $satuan_history);
        if (!$stmt_history->execute()) {
            throw new Exception('Gagal insert history: ' . $stmt_history->error);
        }
        
        // Commit transaksi
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Mutasi barang rusak berhasil disimpan!']);
    } catch (Exception $e) {
        // Rollback transaksi
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    
    exit();
}

// Query untuk mendapatkan data stock dengan waktu terakhir mutasi barang rusak
$query_stock = "SELECT 
    s.KD_BARANG,
    mb.NAMA_BARANG,
    mb.BERAT,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
    s.JUMLAH_BARANG as STOCK_SEKARANG,
    s.SATUAN,
    (
        SELECT MAX(mbr.WAKTU_MUTASI)
        FROM MUTASI_BARANG_RUSAK mbr
        WHERE mbr.KD_BARANG = s.KD_BARANG AND mbr.KD_LOKASI = s.KD_LOKASI
    ) as WAKTU_TERAKHIR_MUTASI
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

// Format waktu terakhir mutasi barang rusak
function formatWaktuTerakhirMutasi($waktu) {
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
$active_page = 'mutasi_barang_rusak';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gudang - Mutasi Barang Rusak</title>
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
            <h1 class="page-title">Gudang <?php echo htmlspecialchars($nama_lokasi); ?> - Mutasi Barang Rusak</h1>
            <?php if (!empty($alamat_lokasi)): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($alamat_lokasi); ?></p>
            <?php endif; ?>
        </div>

        <!-- Button Lihat Riwayat Mutasi -->
        <div class="mb-3">
            <button class="btn-view btn-sm" onclick="lihatRiwayatMutasi()">Lihat Riwayat Mutasi</button>
        </div>

        <!-- Table Mutasi Barang Rusak -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tableMutasiBarangRusak" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Kode Barang</th>
                            <th>Merek Barang</th>
                            <th>Kategori Barang</th>
                            <th>Nama Barang</th>
                            <th>Berat (gr)</th>
                            <th>Stock Sekarang</th>
                            <th>Satuan</th>
                            <th>Waktu Terakhir Mutasi Barang Rusak</th>
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
                                    <td><?php echo formatWaktuTerakhirMutasi($row['WAKTU_TERAKHIR_MUTASI']); ?></td>
                                    <td>
                                        <button class="btn-view btn-sm" onclick="bukaModalMutasi('<?php echo htmlspecialchars($row['KD_BARANG']); ?>', '<?php echo htmlspecialchars($row['NAMA_MEREK']); ?>', '<?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?>', '<?php echo htmlspecialchars($row['NAMA_BARANG']); ?>', <?php echo $row['BERAT']; ?>, <?php echo $row['STOCK_SEKARANG']; ?>, '<?php echo htmlspecialchars($row['SATUAN']); ?>')">Mutasi</button>
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

    <!-- Modal Mutasi Barang Rusak -->
    <div class="modal fade" id="modalMutasiBarangRusak" tabindex="-1" aria-labelledby="modalMutasiBarangRusakLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalMutasiBarangRusakLabel">Mutasi Barang Rusak</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <form id="formMutasi">
                        <input type="hidden" id="mutasi_kd_barang" name="kd_barang">
                        
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Merek Barang</label>
                                <input type="text" class="form-control form-control-sm" id="mutasi_merek_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Kode Barang</label>
                                <input type="text" class="form-control form-control-sm" id="mutasi_kode_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Kategori Barang</label>
                                <input type="text" class="form-control form-control-sm" id="mutasi_kategori_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Berat (gr)</label>
                                <input type="text" class="form-control form-control-sm" id="mutasi_berat" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-bold small">Nama Barang</label>
                                <input type="text" class="form-control form-control-sm" id="mutasi_nama_barang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Stock Sekarang (dus)</label>
                                <input type="text" class="form-control form-control-sm" id="mutasi_stock_sekarang" readonly style="background-color: #e9ecef;">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold small">Jumlah Rusak (dus) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control form-control-sm" id="mutasi_jumlah_rusak" name="jumlah_rusak" min="0" step="1" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold small">Stock Akhir (dus)</label>
                                <input type="text" class="form-control form-control-sm" id="mutasi_stock_akhir" readonly style="background-color: #e9ecef;">
                            </div>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="button" class="btn btn-primary" onclick="simpanMutasi()">Simpan</button>
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
            
            $('#tableMutasiBarangRusak').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data mutasi barang rusak'
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

        function lihatRiwayatMutasi() {
            // Redirect ke halaman riwayat mutasi (akan dibuat nanti)
            window.location.href = 'riwayat_mutasi_barang_rusak.php';
        }

        function bukaModalMutasi(kdBarang, namaMerek, namaKategori, namaBarang, berat, stockSistem, satuan) {
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
                        $('#mutasi_kd_barang').val(kdBarang);
                        $('#mutasi_merek_barang').val(namaMerek);
                        $('#mutasi_kode_barang').val(kdBarang);
                        $('#mutasi_kategori_barang').val(namaKategori);
                        $('#mutasi_berat').val(numberFormat(berat));
                        $('#mutasi_nama_barang').val(namaBarang);
                        $('#mutasi_stock_sekarang').val(numberFormat(stockSistemDus));
                        $('#mutasi_jumlah_rusak').val('');
                        $('#mutasi_jumlah_rusak').attr('max', stockSistemDus);
                        $('#mutasi_stock_akhir').val(numberFormat(stockSistemDus));
                        
                        // Store data untuk perhitungan
                        $('#mutasi_stock_sekarang').data('stock-sistem-dus', stockSistemDus);
                        
                        // Buka modal
                        $('#modalMutasiBarangRusak').modal('show');
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
                    
                    $('#mutasi_kd_barang').val(kdBarang);
                    $('#mutasi_merek_barang').val(namaMerek);
                    $('#mutasi_kode_barang').val(kdBarang);
                    $('#mutasi_kategori_barang').val(namaKategori);
                    $('#mutasi_berat').val(numberFormat(berat));
                    $('#mutasi_nama_barang').val(namaBarang);
                    $('#mutasi_stock_sekarang').val(numberFormat(stockSistemDus));
                    $('#mutasi_jumlah_rusak').val('');
                    $('#mutasi_jumlah_rusak').attr('max', stockSistemDus);
                    $('#mutasi_stock_akhir').val(numberFormat(stockSistemDus));
                    
                    $('#mutasi_stock_sekarang').data('stock-sistem-dus', stockSistemDus);
                    
                    $('#modalMutasiBarangRusak').modal('show');
                }
            });
        }

        // Event listener untuk hitung stock akhir
        $(document).on('input', '#mutasi_jumlah_rusak', function() {
            hitungStockAkhir();
        });

        function hitungStockAkhir() {
            var stockSistemDus = parseInt($('#mutasi_stock_sekarang').data('stock-sistem-dus')) || 0;
            var jumlahRusak = parseInt($('#mutasi_jumlah_rusak').val()) || 0;
            
            // Validasi: jumlah rusak tidak boleh melebihi stock sistem
            if (jumlahRusak > stockSistemDus) {
                $('#mutasi_jumlah_rusak').val(stockSistemDus);
                jumlahRusak = stockSistemDus;
            }
            
            // Hitung stock akhir
            var stockAkhirDus = stockSistemDus - jumlahRusak;
            if (stockAkhirDus < 0) {
                stockAkhirDus = 0;
            }
            
            // Update tampilan
            $('#mutasi_stock_akhir').val(numberFormat(stockAkhirDus));
        }

        function simpanMutasi() {
            // Validasi form
            if (!$('#formMutasi')[0].checkValidity()) {
                $('#formMutasi')[0].reportValidity();
                return;
            }

            var kdBarang = $('#mutasi_kd_barang').val();
            var jumlahRusak = parseInt($('#mutasi_jumlah_rusak').val()) || 0;
            var stockSistemDus = parseInt($('#mutasi_stock_sekarang').data('stock-sistem-dus')) || 0;

            if (jumlahRusak < 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Jumlah rusak tidak boleh negatif!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }

            if (jumlahRusak > stockSistemDus) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Jumlah rusak tidak boleh melebihi stock sistem!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }

            if (jumlahRusak === 0) {
                Swal.fire({
                    icon: 'warning',
                    title: 'Peringatan!',
                    text: 'Jumlah rusak tidak boleh 0!',
                    confirmButtonColor: '#667eea'
                });
                return;
            }

            // Konfirmasi
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi',
                text: 'Apakah Anda yakin ingin menyimpan mutasi barang rusak ini?',
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
                            action: 'simpan_mutasi',
                            kd_barang: kdBarang,
                            jumlah_rusak: jumlahRusak
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
                                    $('#modalMutasiBarangRusak').modal('hide');
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
                            var errorMessage = 'Terjadi kesalahan saat menyimpan mutasi barang rusak!';
                            
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
        $('#modalMutasiBarangRusak').on('hidden.bs.modal', function() {
            $('#formMutasi')[0].reset();
            $('#mutasi_stock_akhir').val('');
        });
    </script>
</body>
</html>

