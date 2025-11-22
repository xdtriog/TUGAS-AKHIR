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

// Query untuk mendapatkan data stock di gudang ini
$query_stock = "SELECT 
                    s.KD_BARANG,
                    mb.NAMA_BARANG,
                    mb.BERAT,
                    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
                    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
                    s.JUMLAH_BARANG as STOCK_SEKARANG,
                    s.SATUAN,
                    s.LAST_UPDATED
                FROM STOCK s
                INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
                LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
                LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
                WHERE s.KD_LOKASI = ?
                ORDER BY s.JUMLAH_BARANG ASC";
$stmt_stock = $conn->prepare($query_stock);
$stmt_stock->bind_param("s", $kd_lokasi);
$stmt_stock->execute();
$result_stock = $stmt_stock->get_result();

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
$active_page = 'dashboard';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gudang - Dashboard</title>
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
            <h1 class="page-title">Gudang <?php echo htmlspecialchars($nama_lokasi); ?> - Dashboard</h1>
            <?php if (!empty($alamat_lokasi)): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($alamat_lokasi); ?></p>
            <?php endif; ?>
        </div>

        <!-- Table Stock -->
        <div class="table-section" style="width: 100%;">
            <div class="table-responsive" style="width: 100%;">
                <table id="tableStock" class="table table-custom table-striped table-hover" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>Kode Barang</th>
                            <th>Merek Barang</th>
                            <th>Kategori Barang</th>
                            <th>Nama Barang</th>
                            <th>Berat (gr)</th>
                            <th>Stock Sekarang</th>
                            <th>Satuan</th>
                            <th>Terakhir Update</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_stock->num_rows > 0): ?>
                            <?php while ($row = $result_stock->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['KD_BARANG']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_MEREK']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_BARANG']); ?></td>
                                    <td><?php echo number_format($row['BERAT'], 0, ',', '.'); ?></td>
                                    <td><?php echo number_format($row['STOCK_SEKARANG'], 0, ',', '.'); ?></td>
                                    <td><?php echo htmlspecialchars($row['SATUAN']); ?></td>
                                    <td><?php echo formatTanggalWaktu($row['LAST_UPDATED']); ?></td>
                                </tr>
                            <?php endwhile; ?>
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
            // Disable DataTables error reporting
            $.fn.dataTable.ext.errMode = 'none';
            
            $('#tableStock').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data stock'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[5, 'asc']], // Sort by Stock Sekarang (index 5) - tersedikit ke terbanyak
                columnDefs: [
                    { orderable: false, targets: [] } // All columns are sortable
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
    </script>
</body>
</html>

