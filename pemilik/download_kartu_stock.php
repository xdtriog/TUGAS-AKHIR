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
$tanggal_dari = isset($_GET['tanggal_dari']) ? trim($_GET['tanggal_dari']) : date('Y-m-01');
$tanggal_sampai = isset($_GET['tanggal_sampai']) ? trim($_GET['tanggal_sampai']) : date('Y-m-t');

if (empty($kd_lokasi) || empty($kd_barang)) {
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

// Validasi barang
$query_barang = "SELECT mb.KD_BARANG, mb.NAMA_BARANG, mb.SATUAN_PERDUS,
                 COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
                 COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
                 s.JUMLAH_BARANG as STOCK_SEKARANG, s.SATUAN
                 FROM STOCK s
                 INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
                 LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
                 LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
                 WHERE s.KD_BARANG = ? AND s.KD_LOKASI = ?";
$stmt_barang = $conn->prepare($query_barang);
$stmt_barang->bind_param("ss", $kd_barang, $kd_lokasi);
$stmt_barang->execute();
$result_barang = $stmt_barang->get_result();

if ($result_barang->num_rows == 0) {
    header("Location: kartu_stock.php?kd_lokasi=" . urlencode($kd_lokasi));
    exit();
}

$barang = $result_barang->fetch_assoc();

// Get stock awal
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

$stock_awal = 0;
if ($result_stock_awal->num_rows > 0) {
    $stock_awal = intval($result_stock_awal->fetch_assoc()['JUMLAH_AKHIR']);
} else {
    $query_check_history = "SELECT COUNT(*) as TOTAL FROM STOCK_HISTORY WHERE KD_BARANG = ? AND KD_LOKASI = ?";
    $stmt_check = $conn->prepare($query_check_history);
    $stmt_check->bind_param("ss", $kd_barang, $kd_lokasi);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();
    $total_history = intval($result_check->fetch_assoc()['TOTAL']);
    
    if ($total_history == 0) {
        $stock_awal = intval($barang['STOCK_SEKARANG']);
    } else {
        $stock_awal = 0;
    }
}

// Query untuk mendapatkan stock history
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

$stock_history = [];
while ($row = $result_history->fetch_assoc()) {
    $stock_history[] = $row;
}

// Hitung total
$total_masuk = 0;
$total_keluar = 0;
foreach ($stock_history as $h) {
    if ($h['JUMLAH_PERUBAHAN'] > 0) {
        $total_masuk += $h['JUMLAH_PERUBAHAN'];
    } else {
        $total_keluar += abs($h['JUMLAH_PERUBAHAN']);
    }
}

$stock_akhir_periode = $stock_awal;
foreach ($stock_history as $h) {
    $stock_akhir_periode = $h['JUMLAH_AKHIR'];
}

// Format tanggal
function formatTanggal($tanggal) {
    if (empty($tanggal) || $tanggal == null) {
        return '-';
    }
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
    if (empty($waktu) || $waktu == null) {
        return '-';
    }
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $date = new DateTime($waktu);
    return $date->format('d') . ' ' . $bulan[(int)$date->format('m')] . ' ' . $date->format('Y') . ' ' . $date->format('H:i') . ' WIB';
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
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kartu Stock - <?php echo htmlspecialchars($barang['NAMA_BARANG']); ?></title>
    <style>
        @media print {
            body {
                margin: 0;
                padding: 20px;
            }
            .no-print {
                display: none !important;
            }
            .print-container {
                page-break-after: avoid;
            }
            @page {
                margin: 2cm;
            }
        }
        
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
            background-color: #f5f5f5;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .header h2 {
            margin: 0;
            font-size: 24px;
            font-weight: bold;
        }
        
        .header h3 {
            margin: 5px 0;
            font-size: 20px;
            font-weight: bold;
        }
        
        .header p {
            margin: 5px 0;
            font-size: 14px;
        }
        
        .info-section {
            margin-bottom: 20px;
            border: 1px solid #000;
            padding: 15px;
            background-color: white;
        }
        
        .info-section .row {
            margin-bottom: 10px;
        }
        
        .info-section label {
            font-weight: bold;
            display: inline-block;
            width: 150px;
        }
        
        .summary-section {
            display: flex;
            justify-content: space-around;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        
        .summary-card {
            border: 1px solid #000;
            padding: 15px;
            text-align: center;
            min-width: 200px;
            margin: 5px;
            background-color: white;
        }
        
        .summary-card .value {
            font-size: 1.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .summary-card .label {
            font-size: 0.9em;
            color: #666;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 11px;
        }
        
        table th, table td {
            border: 1px solid #000;
            padding: 8px;
            text-align: left;
        }
        
        table th {
            background-color: #f0f0f0;
            font-weight: bold;
        }
        
        table td.text-center {
            text-align: center;
        }
        
        table td.text-right {
            text-align: right;
        }
        
        .btn-print {
            background-color: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
            margin-bottom: 20px;
        }
        
        .btn-print:hover {
            background-color: #5568d3;
        }
        
        .footer-summary {
            margin-top: 30px;
            text-align: right;
        }
        
        .footer-summary p {
            margin: 5px 0;
        }
        
        .footer-summary .total {
            font-size: 1.2em;
            font-weight: bold;
        }
    </style>
</head>
<body>
    <button class="btn-print no-print" onclick="window.print()">Print / Download PDF</button>
    
    <div class="print-container">
        <div class="header">
            <h2>KARTU STOCK</h2>
            <h3>CV. KHARISMA WIJAYA ABADI KUSUMA</h3>
            <p>JL. Rembang - 0813653985</p>
            <p><?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?> - <?php echo htmlspecialchars($lokasi['ALAMAT_LOKASI']); ?></p>
        </div>
        
        <div class="info-section">
            <div class="row">
                <div class="col-md-6">
                    <label>Kode Barang:</label>
                    <?php echo htmlspecialchars($barang['KD_BARANG']); ?>
                </div>
                <div class="col-md-6">
                    <label>Nama Barang:</label>
                    <?php echo htmlspecialchars($barang['NAMA_BARANG']); ?>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <label>Merek:</label>
                    <?php echo htmlspecialchars($barang['NAMA_MEREK']); ?>
                </div>
                <div class="col-md-6">
                    <label>Kategori:</label>
                    <?php echo htmlspecialchars($barang['NAMA_KATEGORI']); ?>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <label>Periode:</label>
                    <?php echo formatTanggal($tanggal_dari); ?> s/d <?php echo formatTanggal($tanggal_sampai); ?>
                </div>
                <div class="col-md-6">
                    <label>Stock Saat Ini:</label>
                    <strong><?php echo number_format($barang['STOCK_SEKARANG'], 0, ',', '.'); ?> <?php echo htmlspecialchars($barang['SATUAN']); ?></strong>
                </div>
            </div>
        </div>
        
        <div class="summary-section">
            <div class="summary-card">
                <div class="value"><?php echo number_format($stock_awal, 0, ',', '.'); ?></div>
                <div class="label">Stock Awal Periode</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo number_format($total_masuk, 0, ',', '.'); ?></div>
                <div class="label">Total Masuk</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo number_format($total_keluar, 0, ',', '.'); ?></div>
                <div class="label">Total Keluar</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo number_format($stock_akhir_periode, 0, ',', '.'); ?></div>
                <div class="label">Stock Akhir Periode</div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th style="width: 3%;">No</th>
                    <th style="width: 10%;">Tanggal/Waktu</th>
                    <th style="width: 10%;">Tipe Perubahan</th>
                    <th style="width: 10%;">Referensi</th>
                    <th style="width: 8%;" class="text-center">Jumlah Awal</th>
                    <th style="width: 8%;" class="text-center">Masuk</th>
                    <th style="width: 8%;" class="text-center">Keluar</th>
                    <th style="width: 8%;" class="text-center">Jumlah Akhir</th>
                    <th style="width: 5%;" class="text-center">Satuan</th>
                    <th style="width: 10%;">User</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($stock_history) > 0): ?>
                    <?php 
                    $no = 1;
                    foreach ($stock_history as $h): 
                    ?>
                        <tr>
                            <td class="text-center"><?php echo $no++; ?></td>
                            <td><?php echo formatWaktu($h['WAKTU_CHANGE']); ?></td>
                            <td><?php echo formatTipePerubahan($h['TIPE_PERUBAHAN']); ?></td>
                            <td><?php echo htmlspecialchars($h['REF'] ?? '-'); ?></td>
                            <td class="text-center"><?php echo number_format($h['JUMLAH_AWAL'], 0, ',', '.'); ?></td>
                            <td class="text-center"><?php echo $h['JUMLAH_PERUBAHAN'] > 0 ? number_format($h['JUMLAH_PERUBAHAN'], 0, ',', '.') : '-'; ?></td>
                            <td class="text-center"><?php echo $h['JUMLAH_PERUBAHAN'] < 0 ? number_format(abs($h['JUMLAH_PERUBAHAN']), 0, ',', '.') : '-'; ?></td>
                            <td class="text-center"><strong><?php echo number_format($h['JUMLAH_AKHIR'], 0, ',', '.'); ?></strong></td>
                            <td class="text-center"><?php echo htmlspecialchars($h['SATUAN']); ?></td>
                            <td><?php echo htmlspecialchars($h['NAMA_USER'] ?? '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="text-center">Tidak ada pergerakan stock pada periode yang dipilih</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="footer-summary">
            <p><strong>Stock Awal Periode: <?php echo number_format($stock_awal, 0, ',', '.'); ?></strong></p>
            <p><strong>Total Masuk: <?php echo number_format($total_masuk, 0, ',', '.'); ?></strong></p>
            <p><strong>Total Keluar: <?php echo number_format($total_keluar, 0, ',', '.'); ?></strong></p>
            <p class="total">Stock Akhir Periode: <?php echo number_format($stock_akhir_periode, 0, ',', '.'); ?></p>
        </div>
        
        <div style="margin-top: 30px; text-align: center; color: #666; font-size: 12px;">
            <p>Laporan dicetak pada: <?php echo date('d') . ' ' . ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][(int)date('n') - 1] . ' ' . date('Y') . ' ' . date('H:i') . ' WIB'; ?></p>
        </div>
    </div>
    
    <script>
        // Auto print when page loads (optional, bisa di-comment jika tidak diinginkan)
        // window.onload = function() {
        //     window.print();
        // }
    </script>
</body>
</html>

