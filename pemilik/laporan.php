<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login dan adalah pemilik (OWNR)
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
    exit();
}

// Handle AJAX request untuk check lokasi type
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['check_lokasi_type'])) {
    header('Content-Type: application/json');
    
    $kd_lokasi = isset($_GET['kd_lokasi']) ? trim($_GET['kd_lokasi']) : '';
    
    if (empty($kd_lokasi)) {
        echo json_encode(['success' => false, 'message' => 'Kode lokasi tidak valid!']);
        exit();
    }
    
    $query_check = "SELECT TYPE_LOKASI FROM MASTER_LOKASI WHERE KD_LOKASI = ? AND STATUS = 'AKTIF'";
    $stmt_check = $conn->prepare($query_check);
    $stmt_check->bind_param("s", $kd_lokasi);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    
    if ($result_check->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Lokasi tidak ditemukan!']);
        exit();
    }
    
    $lokasi_data = $result_check->fetch_assoc();
    echo json_encode(['success' => true, 'type_lokasi' => $lokasi_data['TYPE_LOKASI']]);
    exit();
}

// Query untuk mendapatkan semua lokasi aktif
$query_lokasi = "SELECT 
    KD_LOKASI,
    NAMA_LOKASI,
    ALAMAT_LOKASI,
    TYPE_LOKASI
FROM MASTER_LOKASI 
WHERE STATUS = 'AKTIF'
ORDER BY TYPE_LOKASI ASC, NAMA_LOKASI ASC";
$result_lokasi = $conn->query($query_lokasi);

// Set active page untuk sidebar
$active_page = 'laporan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Laporan</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .action-buttons .btn {
            font-size: 0.875rem;
            padding: 0.375rem 0.75rem;
            white-space: nowrap;
        }
    </style>
</head>
<body class="dashboard-body">
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Pemilik - Laporan</h1>
        </div>

        <!-- Table Lokasi -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tableLaporan" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Kode Lokasi</th>
                            <th>Nama Toko</th>
                            <th>Alamat Toko</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_lokasi && $result_lokasi->num_rows > 0): ?>
                            <?php while ($row = $result_lokasi->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['KD_LOKASI']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_LOKASI']); ?></td>
                                    <td><?php echo htmlspecialchars($row['ALAMAT_LOKASI']); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($row['TYPE_LOKASI'] == 'gudang'): ?>
                                                <!-- Action untuk Gudang -->
                                                <button class="btn-view btn-sm" onclick="laporanStockOpname('<?php echo htmlspecialchars($row['KD_LOKASI']); ?>')">
                                                    Laporan Stock Opname
                                                </button>
                                                <button class="btn-view btn-sm" onclick="laporanMutasiBarangRusak('<?php echo htmlspecialchars($row['KD_LOKASI']); ?>')">
                                                    Laporan Mutasi Barang Rusak
                                                </button>
                                                <button class="btn-view btn-sm" onclick="laporanPOQ('<?php echo htmlspecialchars($row['KD_LOKASI']); ?>')">
                                                    Laporan POQ
                                                </button>
                                                <button class="btn-view btn-sm" onclick="kartuStock('<?php echo htmlspecialchars($row['KD_LOKASI']); ?>')">
                                                    Kartu Stock
                                                </button>
                                            <?php else: ?>
                                                <!-- Action untuk Toko -->
                                                <button class="btn-view btn-sm" onclick="laporanPenjualan('<?php echo htmlspecialchars($row['KD_LOKASI']); ?>')">
                                                    Laporan Penjualan
                                                </button>
                                                <button class="btn-view btn-sm" onclick="laporanStockOpname('<?php echo htmlspecialchars($row['KD_LOKASI']); ?>')">
                                                    Laporan Stock Opname
                                                </button>
                                                <button class="btn-view btn-sm" onclick="laporanMutasiBarangRusak('<?php echo htmlspecialchars($row['KD_LOKASI']); ?>')">
                                                    Laporan Mutasi Barang Rusak
                                                </button>
                                                <button class="btn-view btn-sm" onclick="kartuStock('<?php echo htmlspecialchars($row['KD_LOKASI']); ?>')">
                                                    Kartu Stock
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="4" class="text-center">Tidak ada data lokasi</td>
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
            
            $('#tableLaporan').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data lokasi'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[1, 'asc']], // Sort by Nama Toko
                columnDefs: [
                    { orderable: false, targets: 3 } // Disable sorting on Action column
                ],
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

        // Fungsi untuk Laporan Penjualan (Toko)
        function laporanPenjualan(kdLokasi) {
            if (!kdLokasi || kdLokasi.trim() === '') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Kode lokasi tidak valid!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }
            window.location.href = 'laporan_penjualan.php?kd_lokasi=' + encodeURIComponent(kdLokasi);
        }

        // Fungsi untuk Laporan Stock Opname
        function laporanStockOpname(kdLokasi) {
            if (!kdLokasi || kdLokasi.trim() === '') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Kode lokasi tidak valid!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }
            // Cek apakah gudang atau toko dengan AJAX
            $.ajax({
                url: '',
                method: 'GET',
                data: { check_lokasi_type: '1', kd_lokasi: kdLokasi },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        if (response.type_lokasi === 'gudang') {
                            window.location.href = 'laporan_stock_opname_gudang.php?kd_lokasi=' + encodeURIComponent(kdLokasi);
                        } else {
                            window.location.href = 'laporan_stock_opname_toko.php?kd_lokasi=' + encodeURIComponent(kdLokasi);
                        }
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: response.message || 'Gagal mendapatkan informasi lokasi!',
                            confirmButtonColor: '#e74c3c'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Gagal memuat data lokasi!',
                        confirmButtonColor: '#e74c3c'
                    });
                }
            });
        }

        // Fungsi untuk Laporan Mutasi Barang Rusak
        function laporanMutasiBarangRusak(kdLokasi) {
            if (!kdLokasi || kdLokasi.trim() === '') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Kode lokasi tidak valid!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }
            // Cek apakah gudang atau toko dengan AJAX
            $.ajax({
                url: '',
                method: 'GET',
                data: { check_lokasi_type: '1', kd_lokasi: kdLokasi },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        if (response.type_lokasi === 'gudang') {
                            window.location.href = 'laporan_mutasi_barang_rusak_gudang.php?kd_lokasi=' + encodeURIComponent(kdLokasi);
                        } else {
                            window.location.href = 'laporan_mutasi_barang_rusak_toko.php?kd_lokasi=' + encodeURIComponent(kdLokasi);
                        }
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error!',
                            text: response.message || 'Gagal mendapatkan informasi lokasi!',
                            confirmButtonColor: '#e74c3c'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error!',
                        text: 'Gagal memuat data lokasi!',
                        confirmButtonColor: '#e74c3c'
                    });
                }
            });
        }

        // Fungsi untuk Laporan POQ (Gudang)
        function laporanPOQ(kdLokasi) {
            if (!kdLokasi || kdLokasi.trim() === '') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Kode lokasi tidak valid!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }
            window.location.href = 'laporan_poq.php?kd_lokasi=' + encodeURIComponent(kdLokasi);
        }

        // Fungsi untuk Kartu Stock
        function kartuStock(kdLokasi) {
            if (!kdLokasi || kdLokasi.trim() === '') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'Kode lokasi tidak valid!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }
            window.location.href = 'kartu_stock.php?kd_lokasi=' + encodeURIComponent(kdLokasi);
        }
    </script>
</body>
</html>

