<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login
if (!isset($_SESSION['user_id']) || $_SESSION['permision'] != 1) {
    header("Location: ../index.php");
    exit();
}

// Query untuk mendapatkan data Gudang
$query_gudang = "SELECT KD_LOKASI, NAMA_LOKASI, ALAMAT_LOKASI 
                 FROM MASTER_LOKASI 
                 WHERE TYPE_LOKASI = 'gudang' AND STATUS = 'AKTIF'
                 ORDER BY KD_LOKASI ASC";
$result_gudang = $conn->query($query_gudang);

// Query untuk mendapatkan data Toko
$query_toko = "SELECT KD_LOKASI, NAMA_LOKASI, ALAMAT_LOKASI 
               FROM MASTER_LOKASI 
               WHERE TYPE_LOKASI = 'toko' AND STATUS = 'AKTIF'
               ORDER BY KD_LOKASI ASC";
$result_toko = $conn->query($query_toko);

// Set active page untuk sidebar
$active_page = 'stock';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Stock</title>
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
            <h1 class="page-title">Pemilik - Stock</h1>
        </div>

        <!-- Table Gudang -->
        <div class="table-section">
            <h3 class="table-section-title">Gudang</h3>
            <div class="table-responsive">
                <table id="tableGudang" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Kode Gudang</th>
                            <th>Nama Gudang</th>
                            <th>Alamat Gudang</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_gudang->num_rows > 0): ?>
                            <?php while ($row = $result_gudang->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['KD_LOKASI']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_LOKASI']); ?></td>
                                    <td><?php echo htmlspecialchars($row['ALAMAT_LOKASI']); ?></td>
                                    <td>
                                        <button class="btn-view" onclick="lihatStock('<?php echo htmlspecialchars($row['KD_LOKASI']); ?>', 'gudang')">Lihat</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Table Toko -->
        <div class="table-section">
            <h3 class="table-section-title">Toko</h3>
            <div class="table-responsive">
                <table id="tableToko" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Kode Toko</th>
                            <th>Nama Toko</th>
                            <th>Alamat Toko</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_toko->num_rows > 0): ?>
                            <?php while ($row = $result_toko->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['KD_LOKASI']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_LOKASI']); ?></td>
                                    <td><?php echo htmlspecialchars($row['ALAMAT_LOKASI']); ?></td>
                                    <td>
                                        <button class="btn-view" onclick="lihatStock('<?php echo htmlspecialchars($row['KD_LOKASI']); ?>', 'toko')">Lihat</button>
                                    </td>
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
            
            // Initialize DataTable for Gudang
            $('#tableGudang').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data gudang'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[0, 'asc']],
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

            // Initialize DataTable for Toko
            $('#tableToko').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data toko'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[0, 'asc']],
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

        function lihatStock(kodeLokasi, type) {
            // Redirect ke halaman detail stock
            if (type === 'gudang') {
                window.location.href = 'stock_detail_gudang.php?kd_lokasi=' + encodeURIComponent(kodeLokasi) + '&type=' + encodeURIComponent(type);
            } else {
                window.location.href = 'stock_detail_toko.php?kd_lokasi=' + encodeURIComponent(kodeLokasi) + '&type=' + encodeURIComponent(type);
            }
        }
    </script>
</body>
</html>

