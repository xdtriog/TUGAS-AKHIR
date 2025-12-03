<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login dan adalah staff toko
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Cek apakah user adalah staff toko (format ID: TOKO+UUID)
if (substr($user_id, 0, 4) != 'TOKO') {
    header("Location: ../index.php");
    exit();
}

// Get parameter
$id_nota_jual = isset($_GET['id_nota_jual']) ? trim($_GET['id_nota_jual']) : '';

if (empty($id_nota_jual)) {
    header("Location: point_of_sale.php");
    exit();
}

// Query untuk mendapatkan detail nota
$query_nota = "SELECT 
    nj.ID_NOTA_JUAL,
    nj.WAKTU_NOTA,
    nj.TOTAL_JUAL_BARANG,
    nj.SUB_TOTAL_JUAL,
    nj.PAJAK,
    nj.GRAND_TOTAL,
    nj.SUB_TOTAL_BELI,
    nj.GROSS_PROFIT,
    u.NAMA as NAMA_USER,
    ml.NAMA_LOKASI,
    ml.ALAMAT_LOKASI
FROM NOTA_JUAL nj
LEFT JOIN USERS u ON nj.ID_USERS = u.ID_USERS
LEFT JOIN MASTER_LOKASI ml ON nj.KD_LOKASI = ml.KD_LOKASI
WHERE nj.ID_NOTA_JUAL = ? AND nj.ID_USERS = ?";
$stmt_nota = $conn->prepare($query_nota);
$stmt_nota->bind_param("ss", $id_nota_jual, $user_id);
$stmt_nota->execute();
$result_nota = $stmt_nota->get_result();

if ($result_nota->num_rows == 0) {
    header("Location: point_of_sale.php");
    exit();
}

$nota = $result_nota->fetch_assoc();

// Query untuk mendapatkan detail barang
$query_detail = "SELECT 
    dnj.KD_BARANG,
    dnj.JUMLAH_JUAL_BARANG,
    dnj.HARGA_JUAL_BARANG,
    dnj.TOTAL_JUAL_UANG,
    mb.NAMA_BARANG,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI
FROM DETAIL_NOTA_JUAL dnj
INNER JOIN MASTER_BARANG mb ON dnj.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
WHERE dnj.ID_NOTA_JUAL = ?
ORDER BY dnj.ID_DNJB ASC";
$stmt_detail = $conn->prepare($query_detail);
$stmt_detail->bind_param("s", $id_nota_jual);
$stmt_detail->execute();
$result_detail = $stmt_detail->get_result();

// Format waktu
$date = new DateTime($nota['WAKTU_NOTA']);
$bulan = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
$waktu_formatted = $date->format('d') . ' ' . $bulan[(int)$date->format('m')] . ' ' . $date->format('Y') . ' ' . $date->format('H:i') . ' WIB';

// Format rupiah
function formatRupiah($angka) {
    return "Rp " . number_format($angka, 0, ',', '.');
}

// Format number
function formatNumber($num) {
    return number_format($num, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Nota - <?php echo htmlspecialchars($nota['ID_NOTA_JUAL']); ?></title>
    <style>
        @media print {
            body {
                margin: 0;
                padding: 10px;
            }
            .no-print {
                display: none !important;
            }
            @page {
                margin: 0.5cm;
                size: A4;
            }
        }
        
        body {
            font-family: 'Courier New', monospace;
            max-width: 80mm;
            margin: 0 auto;
            padding: 10px;
            background-color: #f5f5f5;
        }
        
        .nota-container {
            background: white;
            padding: 15px;
            border: 1px solid #ddd;
        }
        
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 1px dashed #000;
            padding-bottom: 10px;
        }
        
        .header h3 {
            margin: 0;
            font-size: 16px;
            font-weight: bold;
        }
        
        .header h4 {
            margin: 5px 0;
            font-size: 14px;
            font-weight: bold;
        }
        
        .header p {
            margin: 3px 0;
            font-size: 11px;
        }
        
        .info-section {
            margin-bottom: 10px;
            font-size: 11px;
        }
        
        .info-section .row {
            margin-bottom: 3px;
        }
        
        .info-section strong {
            display: inline-block;
            width: 80px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            font-size: 11px;
        }
        
        table th, table td {
            padding: 4px 2px;
            text-align: left;
        }
        
        table th {
            border-bottom: 1px solid #000;
            font-weight: bold;
        }
        
        table td.text-center {
            text-align: center;
        }
        
        table td.text-right {
            text-align: right;
        }
        
        .summary {
            margin-top: 10px;
            font-size: 11px;
        }
        
        .summary .row {
            margin-bottom: 3px;
            display: flex;
            justify-content: space-between;
        }
        
        .summary .total {
            border-top: 1px solid #000;
            padding-top: 5px;
            margin-top: 5px;
            font-weight: bold;
            font-size: 12px;
        }
        
        .footer {
            text-align: center;
            margin-top: 15px;
            padding-top: 10px;
            border-top: 1px dashed #000;
            font-size: 10px;
            color: #666;
        }
        
        .btn-print {
            background-color: #667eea;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            margin-bottom: 10px;
            width: 100%;
        }
        
        .btn-print:hover {
            background-color: #5568d3;
        }
        
        .btn-back {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            width: 100%;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }
        
        .btn-back:hover {
            background-color: #5a6268;
        }
    </style>
</head>
<body>
    <button class="btn-print no-print" onclick="window.print()">🖨️ Cetak Nota</button>
    <a href="point_of_sale.php" class="btn-back no-print" style="display: block; margin-top: 5px;">Kembali ke POS</a>
    
    <div class="nota-container">
        <div class="header">
            <h3>CV. KHARISMA WIJAYA</h3>
            <h4>ABADI KUSUMA</h4>
            <p><?php echo htmlspecialchars($nota['ALAMAT_LOKASI']); ?></p>
            <p>Telp: 0813653985</p>
        </div>
        
        <div class="info-section">
            <div class="row">
                <strong>ID Nota:</strong>
                <span><?php echo htmlspecialchars($nota['ID_NOTA_JUAL']); ?></span>
            </div>
            <div class="row">
                <strong>Tanggal:</strong>
                <span><?php echo $waktu_formatted; ?></span>
            </div>
            <div class="row">
                <strong>Kasir:</strong>
                <span><?php echo htmlspecialchars($nota['NAMA_USER'] ?? '-'); ?></span>
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th style="width: 5%;">No</th>
                    <th style="width: 40%;">Barang</th>
                    <th style="width: 10%;" class="text-center">Qty</th>
                    <th style="width: 20%;" class="text-right">Harga</th>
                    <th style="width: 25%;" class="text-right">Subtotal</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $no = 1;
                while ($row = $result_detail->fetch_assoc()): 
                ?>
                    <tr>
                        <td><?php echo $no++; ?></td>
                        <td><?php echo htmlspecialchars($row['NAMA_BARANG']); ?></td>
                        <td class="text-center"><?php echo formatNumber($row['JUMLAH_JUAL_BARANG']); ?></td>
                        <td class="text-right"><?php echo formatRupiah($row['HARGA_JUAL_BARANG']); ?></td>
                        <td class="text-right"><?php echo formatRupiah($row['TOTAL_JUAL_UANG']); ?></td>
                    </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
        
        <div class="summary">
            <div class="row">
                <span>Sub Total:</span>
                <span><?php echo formatRupiah($nota['SUB_TOTAL_JUAL']); ?></span>
            </div>
            <div class="row">
                <span>Pajak (11%):</span>
                <span><?php echo formatRupiah($nota['PAJAK']); ?></span>
            </div>
            <div class="row total">
                <span>GRAND TOTAL:</span>
                <span><?php echo formatRupiah($nota['GRAND_TOTAL']); ?></span>
            </div>
        </div>
        
        <div class="footer">
            <p>Terima kasih atas kunjungan Anda</p>
            <p>Barang yang sudah dibeli tidak dapat ditukar/dikembalikan</p>
        </div>
    </div>
    
    <script>
        // Auto print when page loads (optional)
        window.onload = function() {
            // Uncomment line below to auto print
            // window.print();
        }
    </script>
</body>
</html>

