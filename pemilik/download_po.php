<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
    exit();
}

// Get parameter id_pesan
$id_pesan = isset($_GET['id_pesan']) ? trim($_GET['id_pesan']) : '';

if (empty($id_pesan)) {
    header("Location: riwayat_pembelian.php");
    exit();
}

// Query untuk mendapatkan data PO
$query_po = "SELECT 
    pb.ID_PESAN_BARANG,
    pb.KD_BARANG,
    pb.KD_LOKASI,
    pb.KD_SUPPLIER,
    pb.JUMLAH_PESAN_BARANG_DUS,
    pb.HARGA_PESAN_BARANG_DUS,
    pb.TOTAL_MASUK_DUS,
    pb.JUMLAH_DITOLAK_DUS,
    pb.WAKTU_PESAN,
    pb.STATUS,
    mb.NAMA_BARANG,
    mb.BERAT,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
    COALESCE(ms.KD_SUPPLIER, '-') as SUPPLIER_KD,
    COALESCE(ms.NAMA_SUPPLIER, '-') as NAMA_SUPPLIER,
    COALESCE(ms.ALAMAT_SUPPLIER, '-') as ALAMAT_SUPPLIER,
    ml.NAMA_LOKASI,
    ml.ALAMAT_LOKASI
FROM PESAN_BARANG pb
LEFT JOIN MASTER_BARANG mb ON pb.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
LEFT JOIN MASTER_SUPPLIER ms ON pb.KD_SUPPLIER = ms.KD_SUPPLIER
LEFT JOIN MASTER_LOKASI ml ON pb.KD_LOKASI = ml.KD_LOKASI
WHERE pb.ID_PESAN_BARANG = ?";
$stmt_po = $conn->prepare($query_po);
$stmt_po->bind_param("s", $id_pesan);
$stmt_po->execute();
$result_po = $stmt_po->get_result();

if ($result_po->num_rows == 0) {
    header("Location: riwayat_pembelian.php");
    exit();
}

$po = $result_po->fetch_assoc();

// Format supplier text
$supplier_text = '';
if ($po['SUPPLIER_KD'] != '-' && $po['NAMA_SUPPLIER'] != '-') {
    $supplier_text = $po['NAMA_SUPPLIER'];
    if ($po['ALAMAT_SUPPLIER'] != '-') {
        $supplier_text .= ' - ' . $po['ALAMAT_SUPPLIER'];
    }
} else {
    $supplier_text = '-';
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
    <title>PO - <?php echo htmlspecialchars($po['ID_PESAN_BARANG']); ?></title>
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
        }
        
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            max-width: 800px;
            margin: 0 auto;
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
            width: 120px;
        }
        
        .info-section input {
            border: none;
            border-bottom: 1px solid #000;
            padding: 5px;
            width: calc(100% - 130px);
            font-size: 14px;
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
        
        .watermark {
            text-align: center;
            margin-top: 30px;
            position: relative;
        }
        
        .watermark span {
            display: inline-block;
            padding: 30px 60px;
            font-size: 48px;
            font-weight: bold;
            border-radius: 10px;
            letter-spacing: 3px;
        }
        
        .watermark.dibatalkan span {
            background-color: #dc3545;
            color: white;
        }
        
        .watermark.selesai span {
            background-color: #28a745;
            color: white;
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
    </style>
</head>
<body>
    <button class="btn-print no-print" onclick="window.print()">Print / Download PDF</button>
    
    <div class="print-container">
        <div class="header">
            <h2>CV. KHARISMA WIJAYA ABADI KUSUMA</h2>
            <p>JL. Rembang - 0813653985</p>
        </div>
        
        <div class="info-section">
            <label>Kode PO:</label>
            <input type="text" value="<?php echo htmlspecialchars($po['ID_PESAN_BARANG']); ?>" readonly>
        </div>
        
        <div class="info-section">
            <label>Ke:</label>
            <input type="text" value="<?php echo htmlspecialchars($supplier_text); ?>" readonly>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Merek Barang</th>
                    <th>Kategori Barang</th>
                    <th>Nama Barang</th>
                    <th>Berat (gr)</th>
                    <th>Jumlah Pesan (dus)</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo htmlspecialchars($po['NAMA_MEREK']); ?></td>
                    <td><?php echo htmlspecialchars($po['NAMA_KATEGORI']); ?></td>
                    <td><?php echo htmlspecialchars($po['NAMA_BARANG']); ?></td>
                    <td><?php echo number_format($po['BERAT'], 0, ',', '.'); ?></td>
                    <td><?php echo number_format($po['JUMLAH_PESAN_BARANG_DUS'], 0, ',', '.'); ?></td>
                </tr>
            </tbody>
        </table>
        
        <?php if ($po['STATUS'] == 'DIBATALKAN'): ?>
        <div class="watermark dibatalkan">
            <span>DIBATALKAN</span>
        </div>
        <?php elseif ($po['STATUS'] == 'SELESAI'): ?>
        <div class="watermark selesai">
            <span>SELESAI</span>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Auto print when page loads (optional, bisa di-comment jika tidak diinginkan)
        // window.onload = function() {
        //     window.print();
        // }
    </script>
</body>
</html>

