<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
    exit();
}

// Get parameters
$kd_barang = isset($_GET['kd_barang']) ? trim($_GET['kd_barang']) : '';
$kd_lokasi = isset($_GET['kd_lokasi']) ? trim($_GET['kd_lokasi']) : '';

if (empty($kd_barang) || empty($kd_lokasi)) {
    header("Location: stock.php");
    exit();
}

// Query untuk mendapatkan data barang
$query_barang = "SELECT 
    mb.KD_BARANG,
    mb.NAMA_BARANG,
    mb.BERAT,
    mb.STATUS as STATUS_BARANG,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI
FROM MASTER_BARANG mb
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
WHERE mb.KD_BARANG = ?";
$stmt_barang = $conn->prepare($query_barang);
$stmt_barang->bind_param("s", $kd_barang);
$stmt_barang->execute();
$result_barang = $stmt_barang->get_result();

if ($result_barang->num_rows == 0) {
    header("Location: stock.php");
    exit();
}

$barang = $result_barang->fetch_assoc();

// Get lokasi info
$query_lokasi = "SELECT NAMA_LOKASI, ALAMAT_LOKASI FROM MASTER_LOKASI WHERE KD_LOKASI = ?";
$stmt_lokasi = $conn->prepare($query_lokasi);
$stmt_lokasi->bind_param("s", $kd_lokasi);
$stmt_lokasi->execute();
$result_lokasi = $stmt_lokasi->get_result();
$lokasi = $result_lokasi->num_rows > 0 ? $result_lokasi->fetch_assoc() : ['NAMA_LOKASI' => '', 'ALAMAT_LOKASI' => ''];

// Query untuk mendapatkan batch expired (per ID_PESAN_BARANG dan TGL_EXPIRED)
// Hanya ambil yang STATUS = 'SELESAI' dan SISA_STOCK_DUS > 0
$query_expired = "SELECT 
    pb.ID_PESAN_BARANG,
    pb.TGL_EXPIRED,
    pb.SISA_STOCK_DUS,
    pb.TOTAL_MASUK_DUS,
    pb.WAKTU_SELESAI,
    pb.HARGA_PESAN_BARANG_DUS,
    COALESCE(ms.KD_SUPPLIER, '-') as SUPPLIER_KD,
    COALESCE(ms.NAMA_SUPPLIER, '-') as NAMA_SUPPLIER,
    COALESCE(ms.ALAMAT_SUPPLIER, '-') as ALAMAT_SUPPLIER,
    CASE 
        WHEN pb.TGL_EXPIRED IS NULL THEN 999
        WHEN pb.TGL_EXPIRED < CURDATE() THEN 1
        WHEN pb.TGL_EXPIRED = CURDATE() THEN 2
        WHEN pb.TGL_EXPIRED <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 3
        ELSE 4
    END as PRIORITAS_EXPIRED
FROM PESAN_BARANG pb
LEFT JOIN MASTER_SUPPLIER ms ON pb.KD_SUPPLIER = ms.KD_SUPPLIER
WHERE pb.KD_BARANG = ? AND pb.KD_LOKASI = ? AND pb.STATUS = 'SELESAI' AND pb.SISA_STOCK_DUS > 0
ORDER BY 
    PRIORITAS_EXPIRED ASC,
    COALESCE(pb.TGL_EXPIRED, '9999-12-31') ASC";
$stmt_expired = $conn->prepare($query_expired);
$stmt_expired->bind_param("ss", $kd_barang, $kd_lokasi);
$stmt_expired->execute();
$result_expired = $stmt_expired->get_result();

// Format tanggal (dd/mm/yyyy)
function formatTanggal($tanggal) {
    if (empty($tanggal) || $tanggal == null) {
        return '-';
    }
    $date = new DateTime($tanggal);
    return $date->format('d/m/Y');
}

// Format tanggal dan waktu (dd/mm/yyyy HH:ii WIB)
function formatTanggalWaktu($tanggal) {
    if (empty($tanggal) || $tanggal == null) {
        return '-';
    }
    $date = new DateTime($tanggal);
    return $date->format('d/m/Y H:i') . ' WIB';
}

// Format rupiah
function formatRupiah($angka) {
    if (empty($angka) || $angka == null || $angka == 0) {
        return '-';
    }
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Set active page untuk sidebar
$active_page = 'stock';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Lihat Expired - <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></title>
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
        <div class="page-header">
            <h1 class="page-title">Pemilik - Lihat Expired - <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></h1>
            <?php if (!empty($lokasi['ALAMAT_LOKASI'])): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($lokasi['ALAMAT_LOKASI']); ?></p>
            <?php endif; ?>
        </div>

        <!-- Item Details Section -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-4">Informasi Barang</h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Kode Barang</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($barang['KD_BARANG']); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Merek Barang</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($barang['NAMA_MEREK']); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Kategori Barang</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($barang['NAMA_KATEGORI']); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Berat Barang (gr)</label>
                        <input type="text" class="form-control" value="<?php echo number_format($barang['BERAT'], 0, ',', '.'); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Nama Barang</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($barang['NAMA_BARANG']); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Status Barang</label>
                        <input type="text" class="form-control" value="<?php echo $barang['STATUS_BARANG'] == 'AKTIF' ? 'Aktif' : 'Tidak Aktif'; ?>" readonly style="background-color: #e9ecef;">
                    </div>
                </div>
            </div>
        </div>

        <!-- Table Expired Batch -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tableExpired" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID PESAN</th>
                            <th>Supplier</th>
                            <th>Tanggal Expired</th>
                            <th>Sisa Stock (dus)</th>
                            <th>Total Masuk (dus)</th>
                            <th>Harga Beli (dus)</th>
                            <th>Waktu Diterima</th>
                            <th>Status Expired</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_expired->num_rows > 0): ?>
                            <?php while ($row = $result_expired->fetch_assoc()): ?>
                                <?php
                                $tgl_expired = $row['TGL_EXPIRED'];
                                $status_expired = '';
                                $badge_class = '';
                                
                                if (empty($tgl_expired)) {
                                    $status_expired = 'Tidak Ada Expired Date';
                                    $badge_class = 'secondary';
                                } elseif ($tgl_expired < date('Y-m-d')) {
                                    $status_expired = 'Sudah Expired';
                                    $badge_class = 'danger';
                                } elseif ($tgl_expired == date('Y-m-d')) {
                                    $status_expired = 'Expired Hari Ini';
                                    $badge_class = 'warning';
                                } elseif ($tgl_expired <= date('Y-m-d', strtotime('+7 days'))) {
                                    $status_expired = 'Akan Expired (≤7 hari)';
                                    $badge_class = 'info';
                                } else {
                                    $status_expired = 'Masih Valid';
                                    $badge_class = 'success';
                                }
                                
                                $supplier_display = '';
                                if ($row['SUPPLIER_KD'] != '-' && $row['NAMA_SUPPLIER'] != '-') {
                                    $supplier_display = htmlspecialchars($row['SUPPLIER_KD'] . ' - ' . $row['NAMA_SUPPLIER']);
                                    if ($row['ALAMAT_SUPPLIER'] != '-') {
                                        $supplier_display .= ' - ' . htmlspecialchars($row['ALAMAT_SUPPLIER']);
                                    }
                                } else {
                                    $supplier_display = '-';
                                }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['ID_PESAN_BARANG']); ?></td>
                                    <td><?php echo $supplier_display; ?></td>
                                    <td><?php echo formatTanggal($row['TGL_EXPIRED']); ?></td>
                                    <td><?php echo number_format($row['SISA_STOCK_DUS'], 0, ',', '.'); ?></td>
                                    <td><?php echo number_format($row['TOTAL_MASUK_DUS'], 0, ',', '.'); ?></td>
                                    <td><?php echo formatRupiah($row['HARGA_PESAN_BARANG_DUS']); ?></td>
                                    <td><?php echo formatTanggalWaktu($row['WAKTU_SELESAI']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $badge_class; ?>"><?php echo $status_expired; ?></span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted">Tidak ada batch expired</td>
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
    <!-- DataTables Initialization -->
    <script>
        $(document).ready(function() {
            $.fn.dataTable.ext.errMode = 'none';
            
            $('#tableExpired').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data batch expired'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[2, 'asc']], // Sort by Tanggal Expired ascending
                scrollX: true,
                autoWidth: false
            }).on('error.dt', function(e, settings, techNote, message) {
                console.log('DataTables error suppressed:', message);
                return false;
            });
        });
    </script>
</body>
</html>

