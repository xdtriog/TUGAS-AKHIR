<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login dan adalah pemilik (OWNR)
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
    exit();
}

// Get kd_lokasi dari parameter
$kd_lokasi = isset($_GET['kd_lokasi']) ? trim($_GET['kd_lokasi']) : '';

if (empty($kd_lokasi)) {
    header("Location: laporan.php");
    exit();
}

// Validasi bahwa lokasi adalah toko
$query_lokasi = "SELECT KD_LOKASI, NAMA_LOKASI, ALAMAT_LOKASI, TYPE_LOKASI 
                 FROM MASTER_LOKASI 
                 WHERE KD_LOKASI = ? AND STATUS = 'AKTIF'";
$stmt_lokasi = $conn->prepare($query_lokasi);
$stmt_lokasi->bind_param("s", $kd_lokasi);
$stmt_lokasi->execute();
$result_lokasi = $stmt_lokasi->get_result();

if ($result_lokasi->num_rows == 0) {
    header("Location: laporan.php");
    exit();
}

$lokasi = $result_lokasi->fetch_assoc();

// Validasi bahwa lokasi adalah toko
if ($lokasi['TYPE_LOKASI'] != 'toko') {
    header("Location: laporan.php");
    exit();
}

// Get filter tanggal (default: bulan ini)
$tanggal_dari = isset($_GET['tanggal_dari']) ? trim($_GET['tanggal_dari']) : date('Y-m-01');
$tanggal_sampai = isset($_GET['tanggal_sampai']) ? trim($_GET['tanggal_sampai']) : date('Y-m-t');

// Query untuk mendapatkan data stock opname (tanpa batch karena toko)
$query_opname = "SELECT 
    so.ID_OPNAME,
    so.KD_BARANG,
    so.WAKTU_OPNAME,
    so.JUMLAH_SISTEM,
    so.JUMLAH_SEBENARNYA,
    so.SELISIH,
    so.SATUAN,
    so.SATUAN_PERDUS,
    so.TOTAL_BARANG_PIECES,
    so.HARGA_BARANG_PIECES,
    so.TOTAL_UANG,
    u.NAMA as NAMA_USER,
    mb.NAMA_BARANG,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI
FROM STOCK_OPNAME so
INNER JOIN MASTER_BARANG mb ON so.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
LEFT JOIN USERS u ON so.ID_USERS = u.ID_USERS
WHERE so.KD_LOKASI = ?
AND DATE(so.WAKTU_OPNAME) BETWEEN ? AND ?
ORDER BY so.WAKTU_OPNAME DESC, mb.NAMA_BARANG ASC";

$stmt_opname = $conn->prepare($query_opname);
$stmt_opname->bind_param("sss", $kd_lokasi, $tanggal_dari, $tanggal_sampai);
$stmt_opname->execute();
$result_opname = $stmt_opname->get_result();

// Query untuk mendapatkan summary
$query_summary = "SELECT 
    COUNT(DISTINCT so.ID_OPNAME) as TOTAL_OPNAME,
    COUNT(DISTINCT so.KD_BARANG) as TOTAL_BARANG,
    COALESCE(SUM(so.TOTAL_BARANG_PIECES), 0) as TOTAL_SELISIH_PIECES,
    COALESCE(SUM(so.TOTAL_UANG), 0) as TOTAL_NILAI_SELISIH
FROM STOCK_OPNAME so
WHERE so.KD_LOKASI = ?
AND DATE(so.WAKTU_OPNAME) BETWEEN ? AND ?";

$stmt_summary = $conn->prepare($query_summary);
$stmt_summary->bind_param("sss", $kd_lokasi, $tanggal_dari, $tanggal_sampai);
$stmt_summary->execute();
$result_summary = $stmt_summary->get_result();
$summary = $result_summary->fetch_assoc();

// Format rupiah
function formatRupiah($angka) {
    return "Rp. " . number_format($angka, 0, ',', '.');
}

// Format tanggal (dd/mm/yyyy)
function formatTanggal($tanggal) {
    if (empty($tanggal) || $tanggal == null) {
        return '-';
    }
    $date = new DateTime($tanggal);
    return $date->format('d/m/Y');
}

// Format waktu (dd/mm/yyyy HH:ii WIB)
function formatWaktu($waktu) {
    if (empty($waktu) || $waktu == null) {
        return '-';
    }
    $date = new DateTime($waktu);
    return $date->format('d/m/Y H:i') . ' WIB';
}

// Set active page untuk sidebar
$active_page = 'laporan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Laporan Stock Opname <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></title>
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
            <h1 class="page-title">Pemilik - Laporan Stock Opname</h1>
            <p class="text-muted mb-0"><?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?> - <?php echo htmlspecialchars($lokasi['ALAMAT_LOKASI']); ?></p>
        </div>

        <!-- Filter Section -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">Filter Laporan</h5>
                <form method="GET" action="" class="row g-3">
                    <input type="hidden" name="kd_lokasi" value="<?php echo htmlspecialchars($kd_lokasi); ?>">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Tanggal Dari</label>
                        <input type="date" class="form-control" name="tanggal_dari" value="<?php echo htmlspecialchars($tanggal_dari); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Tanggal Sampai</label>
                        <input type="date" class="form-control" name="tanggal_sampai" value="<?php echo htmlspecialchars($tanggal_sampai); ?>" required>
                    </div>
                    <div class="col-md-4 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="download_laporan_stock_opname_toko.php?kd_lokasi=<?php echo urlencode($kd_lokasi); ?>&tanggal_dari=<?php echo urlencode($tanggal_dari); ?>&tanggal_sampai=<?php echo urlencode($tanggal_sampai); ?>" 
                           class="btn btn-success" target="_blank">Download Laporan</a>
                        <a href="laporan.php" class="btn btn-secondary">Kembali</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card primary">
                    <div class="stat-value"><?php echo number_format($summary['TOTAL_OPNAME'], 0, ',', '.'); ?></div>
                    <div class="stat-label">Total Stock Opname</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card info">
                    <div class="stat-value"><?php echo number_format($summary['TOTAL_BARANG'], 0, ',', '.'); ?></div>
                    <div class="stat-label">Total Barang</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card warning">
                    <div class="stat-value"><?php echo number_format($summary['TOTAL_SELISIH_PIECES'], 0, ',', '.'); ?></div>
                    <div class="stat-label">Total Selisih (Pieces)</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card <?php echo $summary['TOTAL_NILAI_SELISIH'] < 0 ? 'danger' : ($summary['TOTAL_NILAI_SELISIH'] > 0 ? 'success' : 'secondary'); ?>">
                    <div class="stat-value"><?php echo formatRupiah($summary['TOTAL_NILAI_SELISIH']); ?></div>
                    <div class="stat-label">Total Nilai Selisih</div>
                </div>
            </div>
        </div>

        <!-- Table Laporan Stock Opname -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tableLaporanStockOpname" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Tanggal/Waktu</th>
                            <th>ID Opname</th>
                            <th>Kode Barang</th>
                            <th>Merek</th>
                            <th>Kategori</th>
                            <th>Nama Barang</th>
                            <th>Jumlah Sistem (pieces)</th>
                            <th>Jumlah Sebenarnya (pieces)</th>
                            <th>Selisih (pieces)</th>
                            <th>Harga (Rp/Piece)</th>
                            <th>Total Nilai Selisih</th>
                            <th>User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_opname && $result_opname->num_rows > 0): ?>
                            <?php while ($row = $result_opname->fetch_assoc()): ?>
                                <tr>
                                    <td data-order="<?php echo strtotime($row['WAKTU_OPNAME']); ?>"><?php echo formatWaktu($row['WAKTU_OPNAME']); ?></td>
                                    <td><?php echo htmlspecialchars($row['ID_OPNAME']); ?></td>
                                    <td><?php echo htmlspecialchars($row['KD_BARANG']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_MEREK']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_BARANG']); ?></td>
                                    <td><?php echo number_format($row['JUMLAH_SISTEM'], 0, ',', '.'); ?></td>
                                    <td><?php echo number_format($row['JUMLAH_SEBENARNYA'], 0, ',', '.'); ?></td>
                                    <td>
                                        <span class="<?php echo $row['SELISIH'] < 0 ? 'text-danger' : ($row['SELISIH'] > 0 ? 'text-success' : 'text-muted'); ?> fw-bold">
                                            <?php echo ($row['SELISIH'] > 0 ? '+' : '') . number_format($row['SELISIH'], 0, ',', '.'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatRupiah($row['HARGA_BARANG_PIECES']); ?></td>
                                    <td>
                                        <span class="<?php echo $row['TOTAL_UANG'] < 0 ? 'text-danger' : ($row['TOTAL_UANG'] > 0 ? 'text-success' : 'text-muted'); ?> fw-bold">
                                            <?php echo formatRupiah($row['TOTAL_UANG']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['NAMA_USER'] ?? '-'); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" class="text-center text-muted">Tidak ada data stock opname pada periode yang dipilih</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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
    
    <script>
        $(document).ready(function() {
            // Disable DataTables error reporting
            $.fn.dataTable.ext.errMode = 'none';
            
            $('#tableLaporanStockOpname').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data stock opname'
                },
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Semua"]],
                order: [[0, 'desc']], // Sort by Tanggal descending
                scrollX: true,
                responsive: true,
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
    </script>
</body>
</html>

