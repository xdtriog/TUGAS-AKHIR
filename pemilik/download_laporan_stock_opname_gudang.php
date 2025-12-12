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

// Validasi lokasi
$query_lokasi = "SELECT KD_LOKASI, NAMA_LOKASI, ALAMAT_LOKASI, TYPE_LOKASI 
                 FROM MASTER_LOKASI 
                 WHERE KD_LOKASI = ? AND STATUS = 'AKTIF'";
$stmt_lokasi = $conn->prepare($query_lokasi);
$stmt_lokasi->bind_param("s", $kd_lokasi);
$stmt_lokasi->execute();
$result_lokasi = $stmt_lokasi->get_result();

if ($result_lokasi->num_rows == 0 || $result_lokasi->fetch_assoc()['TYPE_LOKASI'] != 'gudang') {
    header("Location: laporan.php");
    exit();
}

$result_lokasi->data_seek(0);
$lokasi = $result_lokasi->fetch_assoc();

// Query untuk mendapatkan data stock opname dengan informasi batch
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
    mb.BERAT,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
    so.REF_BATCH as ID_PESAN_BARANG,
    pb.TGL_EXPIRED,
    COALESCE(ms.NAMA_SUPPLIER, '-') as NAMA_SUPPLIER
FROM STOCK_OPNAME so
INNER JOIN MASTER_BARANG mb ON so.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
LEFT JOIN USERS u ON so.ID_USERS = u.ID_USERS
LEFT JOIN PESAN_BARANG pb ON so.REF_BATCH = pb.ID_PESAN_BARANG
LEFT JOIN MASTER_SUPPLIER ms ON pb.KD_SUPPLIER = ms.KD_SUPPLIER
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

// Format tanggal expired
function formatTanggalExpired($tanggal) {
    if (empty($tanggal) || $tanggal == null) {
        return '-';
    }
    
    $date = new DateTime($tanggal);
    $today = new DateTime();
    
    $formatted = formatTanggal($tanggal);
    
    if ($date < $today) {
        return $formatted . ' (EXPIRED)';
    } elseif ($date == $today) {
        return $formatted . ' (HARI INI)';
    } elseif ($today->diff($date)->days <= 7) {
        return $formatted . ' (SEGERA EXPIRED)';
    } else {
        return $formatted;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Stock Opname - <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></title>
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
            margin-bottom: 16px;
            font-size: 10.5px;
            table-layout: fixed;
        }
        
        table th, table td {
            border: 1px solid #000;
            padding: 6px;
            text-align: left;
            word-wrap: break-word;
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
            <h2>LAPORAN STOCK OPNAME</h2>
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
                <div class="value"><?php echo number_format($summary['TOTAL_OPNAME'], 0, ',', '.'); ?></div>
                <div class="label">Total Stock Opname</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo number_format($summary['TOTAL_BARANG'], 0, ',', '.'); ?></div>
                <div class="label">Total Barang</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo number_format($summary['TOTAL_SELISIH_PIECES'], 0, ',', '.'); ?></div>
                <div class="label">Total Selisih (Pieces)</div>
            </div>
            <div class="summary-card">
                <div class="value"><?php echo formatRupiah($summary['TOTAL_NILAI_SELISIH']); ?></div>
                <div class="label">Total Nilai Selisih</div>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th style="width: 3%;">No</th>
                    <th style="width: 8%;">Tanggal/Waktu</th>
                    <th style="width: 6%;">ID Opname</th>
                    <th style="width: 5%;">Kode Barang</th>
                    <th style="width: 6%;">Merek</th>
                    <th style="width: 6%;">Kategori</th>
                    <th style="width: 10%;">Nama Barang</th>
                    <th style="width: 5%;" class="text-center">Berat (gr)</th>
                    <th style="width: 6%;">ID Batch</th>
                    <th style="width: 7%;">Tanggal Expired</th>
                    <th style="width: 8%;">Supplier</th>
                    <th style="width: 5%;" class="text-center">Jumlah Sistem (dus)</th>
                    <th style="width: 5%;" class="text-center">Jumlah Sebenarnya (dus)</th>
                    <th style="width: 5%;" class="text-center">Selisih (dus)</th>
                    <th style="width: 4%;" class="text-center">Satuan per Dus</th>
                    <th style="width: 5%;" class="text-center">Selisih (Pieces)</th>
                    <th style="width: 6%;" class="text-right">Harga (Rp/Piece)</th>
                    <th style="width: 8%;" class="text-right">Total Nilai Selisih</th>
                    <th style="width: 8%;">User</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($result_opname && $result_opname->num_rows > 0): ?>
                    <?php 
                    $no = 1;
                    while ($row = $result_opname->fetch_assoc()): 
                    ?>
                        <tr>
                            <td class="text-center"><?php echo $no++; ?></td>
                            <td><?php echo formatWaktu($row['WAKTU_OPNAME']); ?></td>
                            <td><?php echo htmlspecialchars($row['ID_OPNAME']); ?></td>
                            <td><?php echo htmlspecialchars($row['KD_BARANG']); ?></td>
                            <td><?php echo htmlspecialchars($row['NAMA_MEREK']); ?></td>
                            <td><?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?></td>
                            <td><?php echo htmlspecialchars($row['NAMA_BARANG']); ?></td>
                            <td class="text-center"><?php echo number_format($row['BERAT'], 0, ',', '.'); ?></td>
                            <td><?php echo htmlspecialchars($row['ID_PESAN_BARANG'] ?? '-'); ?></td>
                            <td><?php echo formatTanggalExpired($row['TGL_EXPIRED'] ?? null); ?></td>
                            <td><?php echo htmlspecialchars($row['NAMA_SUPPLIER']); ?></td>
                            <td class="text-center"><?php echo number_format($row['JUMLAH_SISTEM'], 0, ',', '.'); ?></td>
                            <td class="text-center"><?php echo number_format($row['JUMLAH_SEBENARNYA'], 0, ',', '.'); ?></td>
                            <td class="text-center"><?php echo ($row['SELISIH'] > 0 ? '+' : '') . number_format($row['SELISIH'], 0, ',', '.'); ?></td>
                            <td class="text-center"><?php echo number_format($row['SATUAN_PERDUS'], 0, ',', '.'); ?></td>
                            <td class="text-center"><?php echo ($row['TOTAL_BARANG_PIECES'] > 0 ? '+' : '') . number_format($row['TOTAL_BARANG_PIECES'], 0, ',', '.'); ?></td>
                            <td class="text-right"><?php echo formatRupiah($row['HARGA_BARANG_PIECES']); ?></td>
                            <td class="text-right"><?php echo formatRupiah($row['TOTAL_UANG']); ?></td>
                            <td><?php echo htmlspecialchars($row['NAMA_USER'] ?? '-'); ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="18" class="text-center">Tidak ada data stock opname pada periode yang dipilih</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="footer-summary">
            <p><strong>Total Stock Opname: <?php echo number_format($summary['TOTAL_OPNAME'], 0, ',', '.'); ?></strong></p>
            <p><strong>Total Barang: <?php echo number_format($summary['TOTAL_BARANG'], 0, ',', '.'); ?></strong></p>
            <p><strong>Total Selisih (Pieces): <?php echo number_format($summary['TOTAL_SELISIH_PIECES'], 0, ',', '.'); ?></strong></p>
            <p class="total">Total Nilai Selisih: <?php echo formatRupiah($summary['TOTAL_NILAI_SELISIH']); ?></p>
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
