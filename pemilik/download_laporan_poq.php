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

// Validasi bahwa lokasi adalah gudang
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

// Validasi bahwa lokasi adalah gudang
if ($lokasi['TYPE_LOKASI'] != 'gudang') {
    header("Location: laporan.php");
    exit();
}

// Query untuk mendapatkan data Interval POQ (hanya satu per barang - yang pertama kali dihitung)
$query_interval_poq = "SELECT 
    interval_poq.ID_PERHITUNGAN_INTERVAL_POQ,
    interval_poq.KD_BARANG,
    interval_poq.DEMAND_RATE,
    interval_poq.SETUP_COST,
    interval_poq.HOLDING_COST,
    interval_poq.INTERVAL_HARI,
    interval_poq.WAKTU_PERHITUNGAN_INTERVAL_POQ,
    mb.NAMA_BARANG,
    mb.BERAT,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI
FROM PERHITUNGAN_INTERVAL_POQ interval_poq
INNER JOIN MASTER_BARANG mb ON interval_poq.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
WHERE interval_poq.KD_LOKASI = ?";

$query_interval_poq .= " AND DATE(interval_poq.WAKTU_PERHITUNGAN_INTERVAL_POQ) BETWEEN ? AND ?
ORDER BY mb.NAMA_BARANG ASC";

$params_interval = [$kd_lokasi, $tanggal_dari, $tanggal_sampai];
$param_types_interval = "sss";

$stmt_interval_poq = $conn->prepare($query_interval_poq);
$stmt_interval_poq->bind_param($param_types_interval, ...$params_interval);
$stmt_interval_poq->execute();
$result_interval_poq = $stmt_interval_poq->get_result();

// Query untuk mendapatkan data Kuantitas POQ (semua perhitungan kuantitas)
$query_kuantitas_poq = "SELECT 
    poq.ID_PERHITUNGAN_KUANTITAS_POQ,
    poq.KD_BARANG,
    poq.INTERVAL_HARI,
    poq.DEMAND_RATE,
    poq.LEAD_TIME,
    poq.STOCK_SEKARANG,
    poq.KUANTITAS_POQ,
    poq.WAKTU_PERHITUNGAN_KUANTITAS_POQ,
    DATE_ADD(poq.WAKTU_PERHITUNGAN_KUANTITAS_POQ, INTERVAL poq.INTERVAL_HARI DAY) as JATUH_TEMPO_POQ,
    mb.NAMA_BARANG,
    mb.BERAT,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
    s.JUMLAH_BARANG as STOCK_SAAT_INI
FROM PERHITUNGAN_KUANTITAS_POQ poq
INNER JOIN MASTER_BARANG mb ON poq.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
LEFT JOIN STOCK s ON poq.KD_BARANG = s.KD_BARANG AND poq.KD_LOKASI = s.KD_LOKASI
WHERE poq.KD_LOKASI = ?";

$query_kuantitas_poq .= " AND DATE(poq.WAKTU_PERHITUNGAN_KUANTITAS_POQ) BETWEEN ? AND ?
ORDER BY poq.WAKTU_PERHITUNGAN_KUANTITAS_POQ DESC, mb.NAMA_BARANG ASC";

$params_kuantitas = [$kd_lokasi, $tanggal_dari, $tanggal_sampai];
$param_types_kuantitas = "sss";

$stmt_kuantitas_poq = $conn->prepare($query_kuantitas_poq);
$stmt_kuantitas_poq->bind_param($param_types_kuantitas, ...$params_kuantitas);
$stmt_kuantitas_poq->execute();
$result_kuantitas_poq = $stmt_kuantitas_poq->get_result();

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
    <title>Laporan POQ - <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></title>
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
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 12px;
        }
        
        table th, table td {
            border: 1px solid #000;
            padding: 6px;
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
        
        .summary-section {
            margin-top: 30px;
            text-align: center;
            color: #666;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <button class="btn-print no-print" onclick="window.print()">Print / Download PDF</button>
    
    <div class="print-container">
        <div class="header">
            <h2>LAPORAN PERHITUNGAN POQ</h2>
            <h3>CV. KHARISMA WIJAYA ABADI KUSUMA</h3>
            <p>JL. Rembang - 0813653985</p>
            <p><?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?> - <?php echo htmlspecialchars($lokasi['ALAMAT_LOKASI']); ?></p>
        </div>
        
        <div class="info-section">
            <label>Tanggal Laporan:</label>
            <input type="text" value="<?php echo date('d') . ' ' . ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][(int)date('n') - 1] . ' ' . date('Y'); ?>" readonly>
        </div>
        
        <div class="info-section">
            <label>Periode:</label>
            <input type="text" value="<?php echo formatTanggal($tanggal_dari); ?> s/d <?php echo formatTanggal($tanggal_sampai); ?>" readonly>
        </div>
        
        <h3 style="margin-top: 20px; margin-bottom: 15px; font-size: 18px;">1. Interval POQ (Dihitung Sekali untuk Selamanya)</h3>
        <table>
            <thead>
                <tr>
                    <th style="width: 3%;">No</th>
                    <th style="width: 8%;">Kode Barang</th>
                    <th style="width: 10%;">Merek</th>
                    <th style="width: 10%;">Kategori</th>
                    <th style="width: 15%;">Nama Barang</th>
                    <th style="width: 8%;" class="text-center">Demand Rate<br>(dus/hari)</th>
                    <th style="width: 10%;" class="text-right">Setup Cost<br>(Rp)</th>
                    <th style="width: 10%;" class="text-right">Holding Cost<br>(Rp/dus/hari)</th>
                    <th style="width: 8%;" class="text-center">Interval POQ<br>(hari)</th>
                    <th style="width: 8%;">Waktu Perhitungan Interval POQ</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result_interval_poq && $result_interval_poq->num_rows > 0): ?>
                    <?php 
                    $no = 1;
                    while ($row = $result_interval_poq->fetch_assoc()): 
                    ?>
                        <tr>
                            <td class="text-center"><?php echo $no++; ?></td>
                            <td><?php echo htmlspecialchars($row['KD_BARANG']); ?></td>
                            <td><?php echo htmlspecialchars($row['NAMA_MEREK']); ?></td>
                            <td><?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?></td>
                            <td><?php echo htmlspecialchars($row['NAMA_BARANG']); ?></td>
                            <td class="text-center"><?php echo number_format($row['DEMAND_RATE'], 2, ',', '.'); ?></td>
                            <td class="text-right"><?php echo formatRupiah($row['SETUP_COST']); ?></td>
                            <td class="text-right"><?php echo formatRupiah($row['HOLDING_COST']); ?></td>
                            <td class="text-center"><strong><?php echo number_format($row['INTERVAL_HARI'], 0, ',', '.'); ?></strong></td>
                            <td><?php echo formatWaktu($row['WAKTU_PERHITUNGAN_INTERVAL_POQ']); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="10" class="text-center">Tidak ada data interval POQ</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <h3 style="margin-top: 30px; margin-bottom: 15px; font-size: 18px;">2. Kuantitas POQ (Dapat Berubah Setiap Perhitungan)</h3>
        <table>
            <thead>
                <tr>
                    <th style="width: 3%;">No</th>
                    <th style="width: 8%;">Kode Barang</th>
                    <th style="width: 10%;">Merek</th>
                    <th style="width: 10%;">Kategori</th>
                    <th style="width: 15%;">Nama Barang</th>
                    <th style="width: 8%;" class="text-center">Demand Rate<br>(dus/hari)</th>
                    <th style="width: 6%;" class="text-center">Lead Time<br>(hari)</th>
                    <th style="width: 6%;" class="text-center">Interval POQ<br>(hari)</th>
                    <th style="width: 8%;" class="text-center">Stock Saat Perhitungan<br>(dus)</th>
                    <th style="width: 8%;" class="text-center">Stock Saat Ini<br>(dus)</th>
                    <th style="width: 8%;" class="text-center">Kuantitas POQ<br>(dus)</th>
                    <th style="width: 10%;">Waktu Perhitungan Kuantitas POQ</th>
                    <th style="width: 10%;">Jatuh Tempo POQ</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result_kuantitas_poq && $result_kuantitas_poq->num_rows > 0): ?>
                    <?php 
                    $no = 1;
                    while ($row = $result_kuantitas_poq->fetch_assoc()): 
                    ?>
                        <tr>
                            <td class="text-center"><?php echo $no++; ?></td>
                            <td><?php echo htmlspecialchars($row['KD_BARANG']); ?></td>
                            <td><?php echo htmlspecialchars($row['NAMA_MEREK']); ?></td>
                            <td><?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?></td>
                            <td><?php echo htmlspecialchars($row['NAMA_BARANG']); ?></td>
                            <td class="text-center"><?php echo number_format($row['DEMAND_RATE'], 2, ',', '.'); ?></td>
                            <td class="text-center"><?php echo number_format($row['LEAD_TIME'], 0, ',', '.'); ?></td>
                            <td class="text-center"><?php echo number_format($row['INTERVAL_HARI'], 0, ',', '.'); ?></td>
                            <td class="text-center"><?php echo number_format($row['STOCK_SEKARANG'], 0, ',', '.'); ?></td>
                            <td class="text-center"><?php echo number_format($row['STOCK_SAAT_INI'] ?? 0, 0, ',', '.'); ?></td>
                            <td class="text-center"><strong><?php echo number_format($row['KUANTITAS_POQ'], 0, ',', '.'); ?></strong></td>
                            <td><?php echo formatWaktu($row['WAKTU_PERHITUNGAN_KUANTITAS_POQ']); ?></td>
                            <td><?php echo formatTanggal($row['JATUH_TEMPO_POQ']); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="13" class="text-center">Tidak ada data perhitungan kuantitas POQ</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="summary-section">
            <p>Laporan dicetak pada: <?php echo date('d') . ' ' . ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][(int)date('n') - 1] . ' ' . date('Y') . ' ' . date('H:i') . ' WIB'; ?></p>
            <p>Total Interval POQ: <?php echo $result_interval_poq ? $result_interval_poq->num_rows : 0; ?> barang</p>
            <p>Total Perhitungan Kuantitas POQ: <?php echo $result_kuantitas_poq ? $result_kuantitas_poq->num_rows : 0; ?> perhitungan</p>
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

