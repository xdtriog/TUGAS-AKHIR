<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login dan adalah pemilik (OWNR)
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
    exit();
}

// Get parameter
$kd_lokasi = isset($_GET['kd_lokasi']) ? trim($_GET['kd_lokasi']) : '';
$kd_barang = isset($_GET['kd_barang']) ? trim($_GET['kd_barang']) : '';

if (empty($kd_lokasi)) {
    header("Location: laporan.php");
    exit();
}

// Validasi lokasi
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

// Get filter tanggal (default: bulan ini)
$tanggal_dari = isset($_GET['tanggal_dari']) ? trim($_GET['tanggal_dari']) : date('Y-m-01');
$tanggal_sampai = isset($_GET['tanggal_sampai']) ? trim($_GET['tanggal_sampai']) : date('Y-m-t');

// Query untuk mendapatkan daftar barang di lokasi ini
$query_barang = "SELECT DISTINCT
    mb.KD_BARANG,
    mb.NAMA_BARANG,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
    s.JUMLAH_BARANG as STOCK_SEKARANG,
    s.SATUAN
FROM STOCK s
INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
WHERE s.KD_LOKASI = ?
ORDER BY mb.NAMA_BARANG ASC";

$stmt_barang = $conn->prepare($query_barang);
$stmt_barang->bind_param("s", $kd_lokasi);
$stmt_barang->execute();
$result_barang = $stmt_barang->get_result();

// Jika kd_barang dipilih, ambil data stock history
$stock_history = [];
$barang_selected = null;
$stock_awal = 0;

if (!empty($kd_barang)) {
    // Validasi bahwa barang ada di lokasi ini
    $query_validate = "SELECT mb.KD_BARANG, mb.NAMA_BARANG, mb.SATUAN_PERDUS,
                       COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
                       COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
                       s.JUMLAH_BARANG as STOCK_SEKARANG, s.SATUAN
                       FROM STOCK s
                       INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
                       LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
                       LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
                       WHERE s.KD_BARANG = ? AND s.KD_LOKASI = ?";
    $stmt_validate = $conn->prepare($query_validate);
    $stmt_validate->bind_param("ss", $kd_barang, $kd_lokasi);
    $stmt_validate->execute();
    $result_validate = $stmt_validate->get_result();
    
    if ($result_validate->num_rows > 0) {
        $barang_selected = $result_validate->fetch_assoc();
        
        // Get stock awal (sebelum periode filter)
        $query_stock_awal = "SELECT JUMLAH_AKHIR 
                            FROM STOCK_HISTORY 
                            WHERE KD_BARANG = ? AND KD_LOKASI = ? 
                            AND DATE(WAKTU_CHANGE) < ?
                            ORDER BY WAKTU_CHANGE DESC 
                            LIMIT 1";
        $stmt_stock_awal = $conn->prepare($query_stock_awal);
        $stmt_stock_awal->bind_param("sss", $kd_barang, $kd_lokasi, $tanggal_dari);
        $stmt_stock_awal->execute();
        $result_stock_awal = $stmt_stock_awal->get_result();
        
        if ($result_stock_awal->num_rows > 0) {
            $stock_awal = intval($result_stock_awal->fetch_assoc()['JUMLAH_AKHIR']);
        } else {
            // Jika tidak ada history sebelum periode, ambil dari stock awal (0 atau dari stock saat ini jika tidak ada history sama sekali)
            $query_check_history = "SELECT COUNT(*) as TOTAL FROM STOCK_HISTORY WHERE KD_BARANG = ? AND KD_LOKASI = ?";
            $stmt_check = $conn->prepare($query_check_history);
            $stmt_check->bind_param("ss", $kd_barang, $kd_lokasi);
            $stmt_check->execute();
            $result_check = $stmt_check->get_result();
            $total_history = intval($result_check->fetch_assoc()['TOTAL']);
            
            if ($total_history == 0) {
                // Tidak ada history sama sekali, stock awal = stock saat ini
                $stock_awal = intval($barang_selected['STOCK_SEKARANG']);
            } else {
                // Ada history tapi tidak ada sebelum periode, stock awal = 0
                $stock_awal = 0;
            }
        }
        
        // Query untuk mendapatkan stock history dalam periode
        $query_history = "SELECT 
            sh.ID_HISTORY_STOCK,
            sh.WAKTU_CHANGE,
            sh.TIPE_PERUBAHAN,
            sh.REF,
            sh.JUMLAH_AWAL,
            sh.JUMLAH_PERUBAHAN,
            sh.JUMLAH_AKHIR,
            sh.SATUAN,
            u.NAMA as NAMA_USER
        FROM STOCK_HISTORY sh
        LEFT JOIN USERS u ON sh.UPDATED_BY = u.ID_USERS
        WHERE sh.KD_BARANG = ? AND sh.KD_LOKASI = ?
        AND DATE(sh.WAKTU_CHANGE) BETWEEN ? AND ?
        ORDER BY sh.WAKTU_CHANGE ASC, sh.ID_HISTORY_STOCK ASC";
        
        $stmt_history = $conn->prepare($query_history);
        $stmt_history->bind_param("ssss", $kd_barang, $kd_lokasi, $tanggal_dari, $tanggal_sampai);
        $stmt_history->execute();
        $result_history = $stmt_history->get_result();
        
        while ($row = $result_history->fetch_assoc()) {
            $stock_history[] = $row;
        }
    }
}

// Format tanggal
function formatTanggal($tanggal) {
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    
    $date = new DateTime($tanggal);
    return $date->format('d') . ' ' . $bulan[(int)$date->format('m')] . ' ' . $date->format('Y');
}

// Format waktu
function formatWaktu($waktu) {
    $date = new DateTime($waktu);
    return $date->format('d/m/Y H:i');
}

// Format tipe perubahan
function formatTipePerubahan($tipe) {
    $labels = [
        'PEMESANAN' => 'Pemesanan',
        'TRANSFER' => 'Transfer',
        'OPNAME' => 'Stock Opname',
        'RUSAK' => 'Mutasi Rusak',
        'PENJUALAN' => 'Penjualan'
    ];
    return $labels[$tipe] ?? $tipe;
}

// Set active page untuk sidebar
$active_page = 'laporan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Kartu Stock</title>
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
            <h1 class="page-title">Pemilik - Kartu Stock</h1>
            <p class="text-muted mb-0"><?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?> - <?php echo htmlspecialchars($lokasi['ALAMAT_LOKASI']); ?></p>
        </div>

        <!-- Filter Section -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">Filter Kartu Stock</h5>
                <form method="GET" action="" class="row g-3">
                    <input type="hidden" name="kd_lokasi" value="<?php echo htmlspecialchars($kd_lokasi); ?>">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Pilih Barang</label>
                        <select class="form-select" name="kd_barang" id="selectBarang" required>
                            <option value="">-- Pilih Barang --</option>
                            <?php if ($result_barang && $result_barang->num_rows > 0): ?>
                                <?php while ($row = $result_barang->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($row['KD_BARANG']); ?>" 
                                            <?php echo ($kd_barang == $row['KD_BARANG']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($row['NAMA_BARANG']); ?> 
                                        (<?php echo htmlspecialchars($row['KD_BARANG']); ?>)
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Tanggal Dari</label>
                        <input type="date" class="form-control" name="tanggal_dari" value="<?php echo htmlspecialchars($tanggal_dari); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Tanggal Sampai</label>
                        <input type="date" class="form-control" name="tanggal_sampai" value="<?php echo htmlspecialchars($tanggal_sampai); ?>" required>
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary">Tampilkan</button>
                        <?php if (!empty($kd_barang)): ?>
                            <a href="download_kartu_stock.php?kd_lokasi=<?php echo urlencode($kd_lokasi); ?>&kd_barang=<?php echo urlencode($kd_barang); ?>&tanggal_dari=<?php echo urlencode($tanggal_dari); ?>&tanggal_sampai=<?php echo urlencode($tanggal_sampai); ?>" 
                               class="btn btn-success" target="_blank">Download</a>
                        <?php endif; ?>
                        <a href="laporan.php" class="btn btn-secondary">Kembali</a>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($kd_barang) && $barang_selected): ?>
            <!-- Informasi Barang -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">Informasi Barang</h5>
                    <div class="row">
                        <div class="col-md-3">
                            <strong>Kode Barang:</strong><br>
                            <?php echo htmlspecialchars($barang_selected['KD_BARANG']); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Nama Barang:</strong><br>
                            <?php echo htmlspecialchars($barang_selected['NAMA_BARANG']); ?>
                        </div>
                        <div class="col-md-2">
                            <strong>Merek:</strong><br>
                            <?php echo htmlspecialchars($barang_selected['NAMA_MEREK']); ?>
                        </div>
                        <div class="col-md-2">
                            <strong>Kategori:</strong><br>
                            <?php echo htmlspecialchars($barang_selected['NAMA_KATEGORI']); ?>
                        </div>
                        <div class="col-md-2">
                            <strong>Stock Saat Ini:</strong><br>
                            <span class="badge bg-primary"><?php echo number_format($barang_selected['STOCK_SEKARANG'], 0, ',', '.'); ?> <?php echo htmlspecialchars($barang_selected['SATUAN']); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-card primary">
                        <div class="stat-value"><?php echo number_format($stock_awal, 0, ',', '.'); ?></div>
                        <div class="stat-label">Stock Awal Periode</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card success">
                        <div class="stat-value">
                            <?php 
                            $total_masuk = 0;
                            foreach ($stock_history as $h) {
                                if ($h['JUMLAH_PERUBAHAN'] > 0) {
                                    $total_masuk += $h['JUMLAH_PERUBAHAN'];
                                }
                            }
                            echo number_format($total_masuk, 0, ',', '.');
                            ?>
                        </div>
                        <div class="stat-label">Total Masuk</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card danger">
                        <div class="stat-value">
                            <?php 
                            $total_keluar = 0;
                            foreach ($stock_history as $h) {
                                if ($h['JUMLAH_PERUBAHAN'] < 0) {
                                    $total_keluar += abs($h['JUMLAH_PERUBAHAN']);
                                }
                            }
                            echo number_format($total_keluar, 0, ',', '.');
                            ?>
                        </div>
                        <div class="stat-label">Total Keluar</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card info">
                        <div class="stat-value">
                            <?php 
                            $stock_akhir_periode = $stock_awal;
                            foreach ($stock_history as $h) {
                                $stock_akhir_periode = $h['JUMLAH_AKHIR'];
                            }
                            echo number_format($stock_akhir_periode, 0, ',', '.');
                            ?>
                        </div>
                        <div class="stat-label">Stock Akhir Periode</div>
                    </div>
                </div>
            </div>

            <!-- Table Kartu Stock -->
            <div class="table-section">
                <div class="table-responsive">
                    <table id="tableKartuStock" class="table table-custom table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Tanggal/Waktu</th>
                                <th>Tipe Perubahan</th>
                                <th>Referensi</th>
                                <th>Jumlah Awal</th>
                                <th>Masuk</th>
                                <th>Keluar</th>
                                <th>Jumlah Akhir</th>
                                <th>Satuan</th>
                                <th>User</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $saldo_berjalan = $stock_awal;
                            if (count($stock_history) > 0): 
                            ?>
                                <?php foreach ($stock_history as $h): ?>
                                    <tr>
                                        <td><?php echo formatWaktu($h['WAKTU_CHANGE']); ?></td>
                                        <td>
                                            <span class="badge 
                                                <?php 
                                                echo $h['TIPE_PERUBAHAN'] == 'PEMESANAN' ? 'bg-success' : 
                                                    ($h['TIPE_PERUBAHAN'] == 'TRANSFER' ? 'bg-info' : 
                                                    ($h['TIPE_PERUBAHAN'] == 'OPNAME' ? 'bg-warning' : 
                                                    ($h['TIPE_PERUBAHAN'] == 'RUSAK' ? 'bg-danger' : 'bg-primary'))); 
                                                ?>">
                                                <?php echo formatTipePerubahan($h['TIPE_PERUBAHAN']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($h['REF'] ?? '-'); ?></td>
                                        <td><?php echo number_format($h['JUMLAH_AWAL'], 0, ',', '.'); ?></td>
                                        <td class="text-success fw-bold">
                                            <?php echo $h['JUMLAH_PERUBAHAN'] > 0 ? number_format($h['JUMLAH_PERUBAHAN'], 0, ',', '.') : '-'; ?>
                                        </td>
                                        <td class="text-danger fw-bold">
                                            <?php echo $h['JUMLAH_PERUBAHAN'] < 0 ? number_format(abs($h['JUMLAH_PERUBAHAN']), 0, ',', '.') : '-'; ?>
                                        </td>
                                        <td class="fw-bold"><?php echo number_format($h['JUMLAH_AKHIR'], 0, ',', '.'); ?></td>
                                        <td><?php echo htmlspecialchars($h['SATUAN']); ?></td>
                                        <td><?php echo htmlspecialchars($h['NAMA_USER'] ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted">Tidak ada pergerakan stock pada periode yang dipilih</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> Silakan pilih barang untuk menampilkan kartu stock.
            </div>
        <?php endif; ?>
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
            
            $('#tableKartuStock').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada pergerakan stock'
                },
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Semua"]],
                order: [[0, 'asc']], // Sort by Tanggal ascending
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

