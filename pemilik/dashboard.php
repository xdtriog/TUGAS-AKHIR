<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login dan adalah pemilik (OWNR)
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
    exit();
}

// Query untuk mendapatkan statistik
// 1. Barang terjual hari ini (hanya dari toko)
$query_hari_ini = "SELECT COALESCE(SUM(dnj.JUMLAH_JUAL_BARANG), 0) as total_barang
                   FROM DETAIL_NOTA_JUAL dnj
                   INNER JOIN NOTA_JUAL nj ON dnj.ID_NOTA_JUAL = nj.ID_NOTA_JUAL
                   INNER JOIN MASTER_LOKASI ml ON nj.KD_LOKASI = ml.KD_LOKASI
                   WHERE DATE(nj.WAKTU_NOTA) = CURDATE()
                   AND ml.TYPE_LOKASI = 'toko'
                   AND ml.STATUS = 'AKTIF'";
$result_hari_ini = $conn->query($query_hari_ini);
$data_hari_ini = $result_hari_ini->fetch_assoc();
$total_barang_hari_ini = $data_hari_ini['total_barang'];

// 2. Barang terjual bulan ini (hanya dari toko)
$query_bulan_ini = "SELECT COALESCE(SUM(dnj.JUMLAH_JUAL_BARANG), 0) as total_barang
                    FROM DETAIL_NOTA_JUAL dnj
                    INNER JOIN NOTA_JUAL nj ON dnj.ID_NOTA_JUAL = nj.ID_NOTA_JUAL
                    INNER JOIN MASTER_LOKASI ml ON nj.KD_LOKASI = ml.KD_LOKASI
                    WHERE MONTH(nj.WAKTU_NOTA) = MONTH(CURDATE()) 
                    AND YEAR(nj.WAKTU_NOTA) = YEAR(CURDATE())
                    AND ml.TYPE_LOKASI = 'toko'
                    AND ml.STATUS = 'AKTIF'";
$result_bulan_ini = $conn->query($query_bulan_ini);
$data_bulan_ini = $result_bulan_ini->fetch_assoc();
$total_barang_bulan_ini = $data_bulan_ini['total_barang'];

// 3. Gross Profit bulan ini (hanya dari toko)
// Gross Profit = (Harga Jual - Harga Beli) × Jumlah Barang
$query_profit = "SELECT COALESCE(SUM((dnj.HARGA_JUAL_BARANG - COALESCE(mb.AVG_HARGA_BELI_PIECES, 0)) * dnj.JUMLAH_JUAL_BARANG), 0) as gross_profit
                 FROM DETAIL_NOTA_JUAL dnj
                 INNER JOIN NOTA_JUAL nj ON dnj.ID_NOTA_JUAL = nj.ID_NOTA_JUAL
                 INNER JOIN MASTER_BARANG mb ON dnj.KD_BARANG = mb.KD_BARANG
                 INNER JOIN MASTER_LOKASI ml ON nj.KD_LOKASI = ml.KD_LOKASI
                 WHERE MONTH(nj.WAKTU_NOTA) = MONTH(CURDATE()) 
                 AND YEAR(nj.WAKTU_NOTA) = YEAR(CURDATE())
                 AND ml.TYPE_LOKASI = 'toko'
                 AND ml.STATUS = 'AKTIF'";
$result_profit = $conn->query($query_profit);
$data_profit = $result_profit->fetch_assoc();
$gross_profit = $data_profit['gross_profit'];

// Format rupiah
function formatRupiah($angka) {
    return "Rp. " . number_format($angka, 0, ',', '.');
}

// Set active page untuk sidebar
$active_page = 'dashboard';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Dashboard</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="dashboard-body">
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Pemilik - Dashboard</h1>
        </div>

        <!-- Statistics Cards -->
        <div class="row">
            <div class="col-md-4">
                <div class="stat-card primary">
                    <div class="stat-value"><?php echo number_format($total_barang_hari_ini, 0, ',', '.'); ?></div>
                    <div class="stat-label">Barang terjual hari ini</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card info">
                    <div class="stat-value"><?php echo number_format($total_barang_bulan_ini, 0, ',', '.'); ?></div>
                    <div class="stat-label">Barang terjual bulan ini</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card success">
                    <div class="stat-value"><?php echo formatRupiah($gross_profit); ?></div>
                    <div class="stat-label">Gross Profit Bulan ini</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- Sidebar Script -->
    <script src="includes/sidebar.js"></script>
</body>
</html>

