<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login (bisa diakses oleh OWNR, GDNG, atau TOKO)
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

$user_prefix = substr($_SESSION['user_id'], 0, 4);
if ($user_prefix != 'OWNR' && $user_prefix != 'GDNG' && $user_prefix != 'TOKO') {
    header("Location: ../index.php");
    exit();
}

// Get parameter id_transfer
$id_transfer = isset($_GET['id_transfer']) ? trim($_GET['id_transfer']) : '';

if (empty($id_transfer)) {
    echo "ID Transfer tidak valid!";
    exit();
}

// Query untuk mendapatkan data surat jalan (semua detail transfer dengan ID_TRANSFER_BARANG yang sama)
$query_surat_jalan = "SELECT 
    tb.ID_TRANSFER_BARANG,
    tb.WAKTU_PESAN_TRANSFER,
    dtb.ID_DETAIL_TRANSFER_BARANG,
    dtb.KD_BARANG,
    dtb.JUMLAH_PESAN_TRANSFER_DUS,
    dtb.JUMLAH_KIRIM_DUS,
    dtb.STATUS as STATUS_DETAIL,
    tb.KD_LOKASI_ASAL,
    tb.KD_LOKASI_TUJUAN,
    mb.NAMA_BARANG,
    mb.BERAT,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
    ml_asal.NAMA_LOKASI as NAMA_LOKASI_ASAL,
    ml_asal.ALAMAT_LOKASI as ALAMAT_LOKASI_ASAL,
    ml_tujuan.NAMA_LOKASI as NAMA_LOKASI_TUJUAN,
    ml_tujuan.ALAMAT_LOKASI as ALAMAT_LOKASI_TUJUAN
FROM TRANSFER_BARANG tb
INNER JOIN DETAIL_TRANSFER_BARANG dtb ON tb.ID_TRANSFER_BARANG = dtb.ID_TRANSFER_BARANG
INNER JOIN MASTER_BARANG mb ON dtb.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
LEFT JOIN MASTER_LOKASI ml_asal ON tb.KD_LOKASI_ASAL = ml_asal.KD_LOKASI
LEFT JOIN MASTER_LOKASI ml_tujuan ON tb.KD_LOKASI_TUJUAN = ml_tujuan.KD_LOKASI
WHERE tb.ID_TRANSFER_BARANG = ?
ORDER BY 
    CASE 
        WHEN dtb.STATUS = 'DIPESAN' THEN 1
        WHEN dtb.STATUS = 'DIKIRIM' THEN 2
        WHEN dtb.STATUS = 'SELESAI' THEN 3
        WHEN dtb.STATUS = 'TIDAK_DIKIRIM' THEN 4
        WHEN dtb.STATUS = 'DIBATALKAN' THEN 5
        ELSE 6
    END ASC,
    dtb.ID_DETAIL_TRANSFER_BARANG ASC";
$stmt_surat = $conn->prepare($query_surat_jalan);
$stmt_surat->bind_param("s", $id_transfer);
$stmt_surat->execute();
$result_surat = $stmt_surat->get_result();

if ($result_surat->num_rows == 0) {
    header("Location: stock_detail_toko.php");
    exit();
}

// Ambil semua data untuk processing
$surat_jalan_data = [];
$id_transfer_barang = '';
$lokasi_tujuan_text = '';
$waktu_pesan_transfer = '';

// Cek status untuk watermark
$all_cancelled = true;
$has_completed = false;
$has_cancelled = false;
$total_items = 0;

while ($row = $result_surat->fetch_assoc()) {
    $surat_jalan_data[] = $row;
    $total_items++;
    
    // Ambil info lokasi dari row pertama (semua row punya lokasi yang sama)
    if (empty($id_transfer_barang)) {
        $id_transfer_barang = $row['ID_TRANSFER_BARANG'];
        $waktu_pesan_transfer = $row['WAKTU_PESAN_TRANSFER'];
        if (!empty($row['NAMA_LOKASI_TUJUAN'])) {
            $lokasi_tujuan_text = $row['NAMA_LOKASI_TUJUAN'];
            if (!empty($row['ALAMAT_LOKASI_TUJUAN'])) {
                $lokasi_tujuan_text .= ' - ' . $row['ALAMAT_LOKASI_TUJUAN'];
            }
        } else {
            $lokasi_tujuan_text = '-';
        }
    }
    
    // Cek status
    if ($row['STATUS_DETAIL'] != 'DIBATALKAN') {
        $all_cancelled = false;
    }
    if ($row['STATUS_DETAIL'] == 'SELESAI') {
        $has_completed = true;
    }
    if ($row['STATUS_DETAIL'] == 'DIBATALKAN') {
        $has_cancelled = true;
    }
    // TIDAK_DIKIRIM tidak dianggap sebagai cancelled (hanya untuk watermark)
}
?>
<!DOCTYPE html>
<html lang="id">
<head></head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Surat Jalan - <?php echo htmlspecialchars($id_transfer_barang); ?></title>
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
            /* Hilangkan link di footer saat print */
            @page {
                margin: 2cm;
            }
            /* Sembunyikan semua link dan URL di footer browser */
            a {
                text-decoration: none;
                color: inherit;
            }
            a[href]:after {
                content: "" !important;
            }
            /* Sembunyikan URL yang muncul di footer browser saat print */
            @page {
                @bottom-right {
                    content: counter(page);
                }
            }
        }
        
        /* CSS untuk menyembunyikan URL di footer saat print (browser-specific) */
        @media print {
            /* Chrome/Edge */
            @page {
                margin-bottom: 2cm;
            }
            /* Firefox */
            @-moz-document url-prefix() {
                @page {
                    margin-bottom: 2cm;
                }
            }
        }
        
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            max-width: 800px;
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
        
        .strikethrough {
            text-decoration: line-through;
            color: #999;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .status-badge.dibatalkan {
            background-color: #dc3545;
            color: white;
        }
    </style>
</head>
<body>
    <button class="btn-print no-print" onclick="printDocument()">Print / Download PDF</button>
    
    <div class="print-container">
        <div class="header">
            <h2>SURAT JALAN</h2>
            <h2>CV. KHARISMA WIJAYA ABADI KUSUMA</h2>
            <p>JL. Rembang - 0813653985</p>
        </div>
        
        <div class="info-section">
            <label>ID Surat Jalan:</label>
            <input type="text" value="<?php echo htmlspecialchars($id_transfer_barang); ?>" readonly>
        </div>
        
        <?php if (!empty($waktu_pesan_transfer)): 
            $bulan = [
                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
            ];
            $date_pesan = new DateTime($waktu_pesan_transfer);
            $tanggal_pesan = $date_pesan->format('d') . ' ' . $bulan[(int)$date_pesan->format('m')] . ' ' . $date_pesan->format('Y');
            $waktu_pesan = $date_pesan->format('H:i') . ' WIB';
        ?>
        <div class="info-section">
            <label>Waktu Dibuat:</label>
            <input type="text" value="<?php echo htmlspecialchars($tanggal_pesan . ' ' . $waktu_pesan); ?>" readonly>
        </div>
        <?php endif; ?>
        
        <div class="info-section">
            <label>Ke:</label>
            <input type="text" value="<?php echo htmlspecialchars($lokasi_tujuan_text); ?>" readonly>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>ID Detail Transfer</th>
                    <th>Merek Barang</th>
                    <th>Kategori Barang</th>
                    <th>Nama Barang</th>
                    <th>Berat (gr)</th>
                    <th>Jumlah (dus)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($surat_jalan_data as $row): ?>
                    <?php 
                    $is_cancelled = ($row['STATUS_DETAIL'] == 'DIBATALKAN');
                    $row_class = $is_cancelled ? 'strikethrough' : '';
                    ?>
                    <tr class="<?php echo $row_class; ?>">
                        <td><?php echo htmlspecialchars($row['ID_DETAIL_TRANSFER_BARANG']); ?></td>
                        <td><?php echo htmlspecialchars($row['NAMA_MEREK']); ?></td>
                        <td><?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?></td>
                        <td><?php echo htmlspecialchars($row['NAMA_BARANG']); ?></td>
                        <td><?php echo number_format($row['BERAT'], 0, ',', '.'); ?></td>
                        <td><?php echo number_format($row['JUMLAH_PESAN_TRANSFER_DUS'], 0, ',', '.'); ?></td>
                        <td>
                            <?php if ($is_cancelled): ?>
                                <span class="status-badge dibatalkan">Dibatalkan</span>
                            <?php else: ?>
                                <?php
                                $status_text = '';
                                $status_bg = '';
                                switch($row['STATUS_DETAIL']) {
                                    case 'DIPESAN':
                                        $status_text = 'Dipesan';
                                        $status_bg = '#ffc107';
                                        break;
                                    case 'DIKIRIM':
                                        $status_text = 'Dikirim';
                                        $status_bg = '#0dcaf0';
                                        break;
                                    case 'SELESAI':
                                        $status_text = 'Selesai';
                                        $status_bg = '#28a745';
                                        break;
                                    case 'TIDAK_DIKIRIM':
                                        $status_text = 'Tidak Dikirim';
                                        $status_bg = '#6c757d';
                                        break;
                                    default:
                                        $status_text = $row['STATUS_DETAIL'];
                                        $status_bg = '#6c757d';
                                }
                                ?>
                                <span class="status-badge" style="background-color: <?php echo $status_bg; ?>; color: white;"><?php echo htmlspecialchars($status_text); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if ($all_cancelled): ?>
        <div class="watermark dibatalkan">
            <span>DIBATALKAN</span>
        </div>
        <?php elseif ($has_completed): ?>
        <div class="watermark selesai">
            <span>SELESAI</span>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        function printDocument() {
            // Sembunyikan URL di footer saat print dengan mengatur print options
            window.print();
        }
        
        // Event listener untuk saat print dialog dibuka
        window.addEventListener('beforeprint', function() {
            // Sembunyikan semua link di halaman
            document.querySelectorAll('a').forEach(function(link) {
                link.style.textDecoration = 'none';
                link.style.color = 'inherit';
            });
        });
    </script>
</body>
</html>

