<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login dan adalah staff gudang
if (!isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit();
}

$user_id = $_SESSION['user_id'];

// Cek apakah user adalah staff gudang (format ID: GDNG+UUID)
if (substr($user_id, 0, 4) != 'GDNG') {
    header("Location: ../index.php");
    exit();
}

// Get parameter
$id_pesan = isset($_GET['id_pesan']) ? trim($_GET['id_pesan']) : '';

if (empty($id_pesan)) {
    echo "ID Pesan tidak valid!";
    exit();
}

// Get user info dan lokasi gudang
$query_user = "SELECT u.ID_USERS, u.KD_LOKASI, ml.NAMA_LOKASI 
               FROM USERS u
               LEFT JOIN MASTER_LOKASI ml ON u.KD_LOKASI = ml.KD_LOKASI
               WHERE u.ID_USERS = ?";
$stmt_user = $conn->prepare($query_user);
$stmt_user->bind_param("s", $user_id);
$stmt_user->execute();
$result_user = $stmt_user->get_result();

if ($result_user->num_rows == 0) {
    header("Location: ../index.php");
    exit();
}

$user_data = $result_user->fetch_assoc();
$kd_lokasi = $user_data['KD_LOKASI'];
$nama_lokasi = $user_data['NAMA_LOKASI'] ?? 'Gudang';

// Query untuk mendapatkan data pesan barang
$query_pesan = "SELECT 
    pb.ID_PESAN_BARANG,
    pb.KD_BARANG,
    pb.TOTAL_MASUK_DUS,
    pb.TGL_EXPIRED,
    pb.WAKTU_SELESAI,
    mb.NAMA_BARANG,
    mb.BERAT,
    mb.SATUAN_PERDUS,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
    COALESCE(ms.KD_SUPPLIER, '-') as SUPPLIER_KD,
    COALESCE(ms.NAMA_SUPPLIER, '-') as NAMA_SUPPLIER
FROM PESAN_BARANG pb
INNER JOIN MASTER_BARANG mb ON pb.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
LEFT JOIN MASTER_SUPPLIER ms ON pb.KD_SUPPLIER = ms.KD_SUPPLIER
WHERE pb.ID_PESAN_BARANG = ? AND pb.KD_LOKASI = ? AND pb.STATUS = 'SELESAI'";
$stmt_pesan = $conn->prepare($query_pesan);
$stmt_pesan->bind_param("ss", $id_pesan, $kd_lokasi);
$stmt_pesan->execute();
$result_pesan = $stmt_pesan->get_result();

if ($result_pesan->num_rows == 0) {
    echo "Data pesan tidak ditemukan atau belum selesai!";
    exit();
}

$pesan_data = $result_pesan->fetch_assoc();

// Jumlah cetak = TOTAL_MASUK_DUS (1 label per dus)
$jumlah_cetak = max(1, intval($pesan_data['TOTAL_MASUK_DUS']));

// Format tanggal expired
$tgl_expired_formatted = '-';
if (!empty($pesan_data['TGL_EXPIRED'])) {
    $date_expired = new DateTime($pesan_data['TGL_EXPIRED']);
    $tgl_expired_formatted = $date_expired->format('d/m/Y');
}

// Format waktu selesai
$waktu_selesai_formatted = '-';
if (!empty($pesan_data['WAKTU_SELESAI'])) {
    $date_selesai = new DateTime($pesan_data['WAKTU_SELESAI']);
    $waktu_selesai_formatted = $date_selesai->format('d/m/Y H:i') . ' WIB';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cetak Label Batch - <?php echo htmlspecialchars($pesan_data['ID_PESAN_BARANG']); ?></title>
    <style>
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
            .label-container {
                page-break-after: always;
            }
            .label-container:last-child {
                page-break-after: auto;
            }
            @page {
                margin: 0.5cm;
                size: A4;
            }
        }
        
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
            background-color: #f5f5f5;
        }
        
        .print-controls {
            text-align: center;
            margin-bottom: 20px;
            padding: 15px;
            background-color: white;
            border-radius: 5px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .print-controls input {
            width: 80px;
            padding: 5px;
            margin: 0 10px;
            text-align: center;
        }
        
        .label-container {
            width: 8cm;
            height: 5cm;
            border: 2px solid #000;
            padding: 10px;
            margin: 10px auto;
            background-color: white;
            box-sizing: border-box;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        
        .label-header {
            text-align: center;
            border-bottom: 1px solid #000;
            padding-bottom: 5px;
            margin-bottom: 5px;
        }
        
        .label-header h3 {
            margin: 0;
            font-size: 14px;
            font-weight: bold;
        }
        
        .label-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            font-size: 11px;
        }
        
        .label-row {
            display: flex;
            justify-content: space-between;
            margin: 2px 0;
        }
        
        .label-label {
            font-weight: bold;
            min-width: 80px;
        }
        
        .label-value {
            flex: 1;
            text-align: right;
        }
        
        .label-footer {
            text-align: center;
            border-top: 1px solid #000;
            padding-top: 5px;
            margin-top: 5px;
            font-size: 10px;
        }
        
        .btn-print {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
            margin: 0 5px;
        }
        
        .btn-print:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="print-controls no-print">
        <h3>Cetak Label Batch</h3>
        <p>
            <strong>Jumlah Label: <?php echo number_format($jumlah_cetak, 0, ',', '.'); ?> label</strong> (1 label per dus)
        </p>
        <p>
            <button class="btn-print" onclick="window.print()">Print / Download PDF</button>
            <button class="btn-print" onclick="window.close()">Tutup</button>
        </p>
    </div>
    
    <div id="labels-container">
        <?php for ($i = 0; $i < $jumlah_cetak; $i++): ?>
        <div class="label-container">
            <div class="label-header">
                <h3>LABEL BATCH BARANG</h3>
            </div>
            <div class="label-content">
                <div class="label-row">
                    <span class="label-label">ID Pesan / ID Batch:</span>
                    <span class="label-value"><?php echo htmlspecialchars($pesan_data['ID_PESAN_BARANG']); ?></span>
                </div>
                <div class="label-row">
                    <span class="label-label">Kode Barang:</span>
                    <span class="label-value"><?php echo htmlspecialchars($pesan_data['KD_BARANG']); ?></span>
                </div>
                <div class="label-row">
                    <span class="label-label">Nama Barang:</span>
                    <span class="label-value"><?php echo htmlspecialchars($pesan_data['NAMA_BARANG']); ?></span>
                </div>
                <div class="label-row">
                    <span class="label-label">Merek:</span>
                    <span class="label-value"><?php echo htmlspecialchars($pesan_data['NAMA_MEREK']); ?></span>
                </div>
                <div class="label-row">
                    <span class="label-label">Kategori:</span>
                    <span class="label-value"><?php echo htmlspecialchars($pesan_data['NAMA_KATEGORI']); ?></span>
                </div>
                <div class="label-row">
                    <span class="label-label">Jumlah:</span>
                    <span class="label-value"><?php echo number_format($pesan_data['SATUAN_PERDUS'], 0, ',', '.'); ?> pieces/dus</span>
                </div>
                <div class="label-row">
                    <span class="label-label">Berat:</span>
                    <span class="label-value"><?php echo number_format($pesan_data['BERAT'], 0, ',', '.'); ?> gr/piece</span>
                </div>
                <div class="label-row">
                    <span class="label-label">Expired:</span>
                    <span class="label-value"><?php echo $tgl_expired_formatted; ?></span>
                </div>
                <div class="label-row">
                    <span class="label-label">Supplier:</span>
                    <span class="label-value"><?php echo htmlspecialchars($pesan_data['SUPPLIER_KD']); ?></span>
                </div>
            </div>
            <div class="label-footer">
                <strong><?php echo htmlspecialchars($nama_lokasi); ?></strong> | <?php echo $waktu_selesai_formatted; ?>
            </div>
        </div>
        <?php endfor; ?>
    </div>
    
    <script>
        // Auto print saat halaman dimuat
        window.onload = function() {
            setTimeout(function() {
                window.print();
            }, 500);
        };
    </script>
</body>
</html>

