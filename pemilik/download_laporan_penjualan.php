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
$tanggal_dari = isset($_GET['tanggal_dari']) ? trim($_GET['tanggal_dari']) : date('Y-m-01');
$tanggal_sampai = isset($_GET['tanggal_sampai']) ? trim($_GET['tanggal_sampai']) : date('Y-m-t');

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

// Query untuk mendapatkan data penjualan (dikelompokkan per nota)
$query_penjualan = "SELECT 
    nj.ID_NOTA_JUAL,
    nj.WAKTU_NOTA,
    nj.TOTAL_JUAL_BARANG,
    nj.SUB_TOTAL_JUAL,
    nj.PAJAK,
    nj.GRAND_TOTAL,
    nj.SUB_TOTAL_BELI,
    nj.GROSS_PROFIT,
    u.NAMA as NAMA_USER
FROM NOTA_JUAL nj
LEFT JOIN USERS u ON nj.ID_USERS = u.ID_USERS
WHERE nj.KD_LOKASI = ?
AND DATE(nj.WAKTU_NOTA) BETWEEN ? AND ?
ORDER BY nj.WAKTU_NOTA DESC, nj.ID_NOTA_JUAL ASC";

$stmt_penjualan = $conn->prepare($query_penjualan);
$stmt_penjualan->bind_param("sss", $kd_lokasi, $tanggal_dari, $tanggal_sampai);
$stmt_penjualan->execute();
$result_penjualan = $stmt_penjualan->get_result();

// Query untuk mendapatkan summary
$query_summary = "SELECT 
    COUNT(DISTINCT nj.ID_NOTA_JUAL) as TOTAL_TRANSAKSI,
    COALESCE(SUM(nj.TOTAL_JUAL_BARANG), 0) as TOTAL_BARANG_TERJUAL,
    COALESCE(SUM(nj.SUB_TOTAL_JUAL), 0) as TOTAL_PENJUALAN,
    COALESCE(SUM(nj.PAJAK), 0) as TOTAL_PAJAK,
    COALESCE(SUM(nj.GRAND_TOTAL), 0) as TOTAL_GRAND_TOTAL,
    COALESCE(SUM(nj.SUB_TOTAL_BELI), 0) as TOTAL_BELI,
    COALESCE(SUM(nj.GROSS_PROFIT), 0) as TOTAL_GROSS_PROFIT
FROM NOTA_JUAL nj
WHERE nj.KD_LOKASI = ?
AND DATE(nj.WAKTU_NOTA) BETWEEN ? AND ?";

$stmt_summary = $conn->prepare($query_summary);
$stmt_summary->bind_param("sss", $kd_lokasi, $tanggal_dari, $tanggal_sampai);
$stmt_summary->execute();
$result_summary = $stmt_summary->get_result();
$summary = $result_summary->fetch_assoc();

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

// Format rupiah
function formatRupiah($angka) {
    if (empty($angka) || $angka == null || $angka == 0) {
        return '-';
    }
    return 'Rp ' . number_format($angka, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Penjualan - <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></title>
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
            max-width: 1000px;
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
        }
        
        .info-section label {
            font-weight: bold;
            display: inline-block;
            width: 150px;
        }
        
        .info-section input {
            border: none;
            border-bottom: 1px solid #000;
            padding: 5px;
            width: calc(100% - 160px);
            font-size: 14px;
            background-color: transparent;
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
            <h2>LAPORAN PENJUALAN</h2>
            <h3>CV. KHARISMA WIJAYA ABADI KUSUMA</h3>
            <p>JL. Rembang - 0813653985</p>
            <p><?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?> - <?php echo htmlspecialchars($lokasi['ALAMAT_LOKASI']); ?></p>
        </div>
        
        <div class="info-section">
            <label>Periode:</label>
            <input type="text" value="<?php echo formatTanggal($tanggal_dari); ?> s/d <?php echo formatTanggal($tanggal_sampai); ?>" readonly>
        </div>
        
        <div class="summary-section">
            <div class="summary-card">
                <div class="value"><?php echo number_format($summary['TOTAL_TRANSAKSI'], 0, ',', '.'); ?></div>
                <div class="label">Total Transaksi</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo number_format($summary['TOTAL_BARANG_TERJUAL'], 0, ',', '.'); ?></div>
                <div class="label">Total Barang Terjual</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo formatRupiah($summary['TOTAL_PENJUALAN']); ?></div>
                <div class="label">Sub Total Jual</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo formatRupiah($summary['TOTAL_BELI']); ?></div>
                <div class="label">Sub Total Beli</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo formatRupiah($summary['TOTAL_GROSS_PROFIT']); ?></div>
                <div class="label">Gross Profit</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo formatRupiah($summary['TOTAL_GRAND_TOTAL']); ?></div>
                <div class="label">Grand Total</div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 20%;">Tanggal/Waktu</th>
                    <th style="width: 15%;">ID Nota Jual</th>
                    <th style="width: 15%;">Kasir</th>
                    <th style="width: 10%;" class="text-center">Jumlah Barang</th>
                    <th style="width: 15%;" class="text-right">Pajak</th>
                    <th style="width: 20%;" class="text-right">Grand Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result_penjualan && $result_penjualan->num_rows > 0): ?>
                    <?php 
                    $no = 1;
                    while ($row = $result_penjualan->fetch_assoc()): 
                    ?>
                        <tr>
                            <td class="text-center"><?php echo $no++; ?></td>
                            <td><?php echo formatWaktu($row['WAKTU_NOTA']); ?></td>
                            <td><?php echo htmlspecialchars($row['ID_NOTA_JUAL']); ?></td>
                            <td><?php echo htmlspecialchars($row['NAMA_USER'] ?? '-'); ?></td>
                            <td class="text-center"><?php echo number_format($row['TOTAL_JUAL_BARANG'], 0, ',', '.'); ?></td>
                            <td class="text-right"><?php echo formatRupiah($row['PAJAK']); ?></td>
                            <td class="text-right"><?php echo formatRupiah($row['GRAND_TOTAL']); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="7" class="text-center">Tidak ada data penjualan pada periode yang dipilih</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="footer-summary">
            <p><strong>Total Transaksi: <?php echo number_format($summary['TOTAL_TRANSAKSI'], 0, ',', '.'); ?></strong></p>
            <p><strong>Total Barang Terjual: <?php echo number_format($summary['TOTAL_BARANG_TERJUAL'], 0, ',', '.'); ?></strong></p>
            <p><strong>Sub Total Jual: <?php echo formatRupiah($summary['TOTAL_PENJUALAN']); ?></strong></p>
            <p><strong>Sub Total Beli: <?php echo formatRupiah($summary['TOTAL_BELI']); ?></strong></p>
            <p><strong>Gross Profit: <?php echo formatRupiah($summary['TOTAL_GROSS_PROFIT']); ?></strong></p>
            <p><strong>Total Pajak: <?php echo formatRupiah($summary['TOTAL_PAJAK']); ?></strong></p>
            <p class="total">Grand Total: <?php echo formatRupiah($summary['TOTAL_GRAND_TOTAL']); ?></p>
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

