<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
    exit();
}

// Get parameters
$kd_barang = isset($_GET['kd_barang']) ? trim($_GET['kd_barang']) : '';
$kd_lokasi = isset($_GET['kd_lokasi']) ? trim($_GET['kd_lokasi']) : '';

if (empty($kd_barang) || empty($kd_lokasi)) {
    header("Location: stock_detail_toko.php?kd_lokasi=" . urlencode($kd_lokasi));
    exit();
}

// Get barang info
$query_barang = "SELECT 
    mb.KD_BARANG,
    mb.NAMA_BARANG,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI
FROM MASTER_BARANG mb
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
WHERE mb.KD_BARANG = ?";
$stmt_barang = $conn->prepare($query_barang);
$stmt_barang->bind_param("s", $kd_barang);
$stmt_barang->execute();
$result_barang = $stmt_barang->get_result();

if ($result_barang->num_rows == 0) {
    header("Location: stock_detail_toko.php?kd_lokasi=" . urlencode($kd_lokasi));
    exit();
}

$barang = $result_barang->fetch_assoc();

// Get lokasi info
$query_lokasi = "SELECT NAMA_LOKASI FROM MASTER_LOKASI WHERE KD_LOKASI = ?";
$stmt_lokasi = $conn->prepare($query_lokasi);
$stmt_lokasi->bind_param("s", $kd_lokasi);
$stmt_lokasi->execute();
$result_lokasi = $stmt_lokasi->get_result();
$lokasi = $result_lokasi->fetch_assoc();

// Handle AJAX request untuk batalkan transfer
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'batalkan_transfer') {
    header('Content-Type: application/json');
    
    $id_detail_transfer = isset($_POST['id_detail_transfer']) ? trim($_POST['id_detail_transfer']) : '';
    
    if (empty($id_detail_transfer)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Update status detail transfer menjadi DIBATALKAN
    $update_detail = "UPDATE DETAIL_TRANSFER_BARANG SET STATUS = 'DIBATALKAN' WHERE ID_DETAIL_TRANSFER_BARANG = ?";
    $stmt_detail = $conn->prepare($update_detail);
    $stmt_detail->bind_param("s", $id_detail_transfer);
    
    if ($stmt_detail->execute()) {
        // Cek apakah semua detail transfer sudah dibatalkan atau selesai
        $query_check = "SELECT ID_TRANSFER_BARANG FROM DETAIL_TRANSFER_BARANG WHERE ID_DETAIL_TRANSFER_BARANG = ?";
        $stmt_check = $conn->prepare($query_check);
        $stmt_check->bind_param("s", $id_detail_transfer);
        $stmt_check->execute();
        $result_check = $stmt_check->get_result();
        $detail_data = $result_check->fetch_assoc();
        $id_transfer = $detail_data['ID_TRANSFER_BARANG'];
        
        // Cek apakah semua detail sudah dibatalkan atau selesai
        $query_all = "SELECT COUNT(*) as total, 
                     SUM(CASE WHEN STATUS IN ('DIPESAN', 'DIKIRIM') THEN 1 ELSE 0 END) as aktif
                     FROM DETAIL_TRANSFER_BARANG 
                     WHERE ID_TRANSFER_BARANG = ?";
        $stmt_all = $conn->prepare($query_all);
        $stmt_all->bind_param("s", $id_transfer);
        $stmt_all->execute();
        $result_all = $stmt_all->get_result();
        $all_data = $result_all->fetch_assoc();
        
        // Jika tidak ada yang aktif, update status transfer menjadi DIBATALKAN
        if ($all_data['aktif'] == 0) {
            $update_transfer = "UPDATE TRANSFER_BARANG SET STATUS = 'DIBATALKAN' WHERE ID_TRANSFER_BARANG = ?";
            $stmt_transfer = $conn->prepare($update_transfer);
            $stmt_transfer->bind_param("s", $id_transfer);
            $stmt_transfer->execute();
            $stmt_transfer->close();
        }
        
        echo json_encode(['success' => true, 'message' => 'Transfer berhasil dibatalkan!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal membatalkan transfer!']);
    }
    
    $stmt_detail->close();
    exit();
}

// Query untuk mendapatkan riwayat resupply dengan batch
$query_riwayat = "SELECT 
    dtb.ID_DETAIL_TRANSFER_BARANG,
    dtb.ID_TRANSFER_BARANG,
    dtb.TOTAL_PESAN_TRANSFER_DUS,
    dtb.TOTAL_KIRIM_DUS,
    dtb.TOTAL_DITOLAK_DUS,
    dtb.TOTAL_MASUK_DUS,
    dtb.STATUS as STATUS_DETAIL,
    tb.WAKTU_PESAN_TRANSFER,
    tb.WAKTU_KIRIM_TRANSFER,
    tb.WAKTU_SELESAI_TRANSFER,
    tb.STATUS as STATUS_TRANSFER,
    tb.KD_LOKASI_ASAL,
    tb.KD_LOKASI_TUJUAN,
    COALESCE(s.SATUAN, 'PIECES') as SATUAN,
    GROUP_CONCAT(
        CONCAT(dtbb.ID_DETAIL_TRANSFER_BARANG_BATCH, ':', pb.ID_PESAN_BARANG, ':', dtbb.JUMLAH_PESAN_TRANSFER_BATCH_DUS, ':', COALESCE(pb.TGL_EXPIRED, ''))
        ORDER BY pb.TGL_EXPIRED ASC
        SEPARATOR '|'
    ) as BATCH_INFO
FROM DETAIL_TRANSFER_BARANG dtb
INNER JOIN TRANSFER_BARANG tb ON dtb.ID_TRANSFER_BARANG = tb.ID_TRANSFER_BARANG
LEFT JOIN STOCK s ON dtb.KD_BARANG = s.KD_BARANG AND tb.KD_LOKASI_TUJUAN = s.KD_LOKASI
LEFT JOIN DETAIL_TRANSFER_BARANG_BATCH dtbb ON dtb.ID_DETAIL_TRANSFER_BARANG = dtbb.ID_DETAIL_TRANSFER_BARANG
LEFT JOIN PESAN_BARANG pb ON dtbb.ID_PESAN_BARANG = pb.ID_PESAN_BARANG
WHERE dtb.KD_BARANG = ? AND tb.KD_LOKASI_TUJUAN = ?
GROUP BY dtb.ID_DETAIL_TRANSFER_BARANG, dtb.ID_TRANSFER_BARANG, dtb.TOTAL_PESAN_TRANSFER_DUS, 
         dtb.TOTAL_KIRIM_DUS, dtb.TOTAL_DITOLAK_DUS, dtb.TOTAL_MASUK_DUS, dtb.STATUS,
         tb.WAKTU_PESAN_TRANSFER, tb.WAKTU_KIRIM_TRANSFER, tb.WAKTU_SELESAI_TRANSFER, 
         tb.STATUS, tb.KD_LOKASI_ASAL, tb.KD_LOKASI_TUJUAN, s.SATUAN
ORDER BY 
    CASE dtb.STATUS
        WHEN 'DIPESAN' THEN 1
        WHEN 'DIKIRIM' THEN 2
        WHEN 'SELESAI' THEN 3
        ELSE 4
    END,
    CASE dtb.STATUS
        WHEN 'DIPESAN' THEN tb.WAKTU_PESAN_TRANSFER
        WHEN 'DIKIRIM' THEN tb.WAKTU_KIRIM_TRANSFER
        ELSE NULL
    END ASC,
    CASE dtb.STATUS
        WHEN 'SELESAI' THEN tb.WAKTU_SELESAI_TRANSFER
        ELSE NULL
    END DESC,
    dtb.ID_DETAIL_TRANSFER_BARANG DESC";

$stmt_riwayat = $conn->prepare($query_riwayat);
$stmt_riwayat->bind_param("ss", $kd_barang, $kd_lokasi);
$stmt_riwayat->execute();
$result_riwayat = $stmt_riwayat->get_result();

// Get lokasi info untuk display
$lokasi_map = [];
$query_all_lokasi = "SELECT KD_LOKASI, NAMA_LOKASI, ALAMAT_LOKASI FROM MASTER_LOKASI";
$result_all_lokasi = $conn->query($query_all_lokasi);
while ($lok = $result_all_lokasi->fetch_assoc()) {
    $lokasi_map[$lok['KD_LOKASI']] = $lok['KD_LOKASI'] . '-' . $lok['NAMA_LOKASI'] . '-' . $lok['ALAMAT_LOKASI'];
}

// Format waktu stack (dd/mm/yyyy HH:ii WIB)
function formatWaktuStack($waktu_pesan, $waktu_kirim, $waktu_selesai, $status_detail) {
    $html = '<div class="d-flex flex-column gap-1">';
    
    // Waktu diterima (jika ada WAKTU_SELESAI dan status SELESAI) - tampilkan di atas
    if (!empty($waktu_selesai) && $status_detail == 'SELESAI') {
        $date_sampai = new DateTime($waktu_selesai);
        $waktu_sampai_formatted = $date_sampai->format('d/m/Y H:i') . ' WIB';
        $html .= '<div>';
        $html .= htmlspecialchars($waktu_sampai_formatted) . ' ';
        $html .= '<span class="badge bg-success">DITERIMA</span>';
        $html .= '</div>';
    }
    
    // Waktu Dikirim - tampilkan di tengah (jika ada)
    if (!empty($waktu_kirim)) {
        $date_kirim = new DateTime($waktu_kirim);
        $waktu_kirim_formatted = $date_kirim->format('d/m/Y H:i') . ' WIB';
        $html .= '<div>';
        $html .= htmlspecialchars($waktu_kirim_formatted) . ' ';
        $html .= '<span class="badge bg-info">DIKIRIM</span>';
        $html .= '</div>';
    }
    
    // Waktu dipesan - tampilkan di bawah
    if (!empty($waktu_pesan)) {
        $date_pesan = new DateTime($waktu_pesan);
        $waktu_pesan_formatted = $date_pesan->format('d/m/Y H:i') . ' WIB';
        $html .= '<div>';
        $html .= htmlspecialchars($waktu_pesan_formatted) . ' ';
        $html .= '<span class="badge bg-warning">DIPESAN</span>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    return $html;
}

// Set active page untuk sidebar
$active_page = 'stock';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Stock <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?> / Riwayat Resupply</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="dashboard-body">
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Pemilik - Stock <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?> / Riwayat Resupply</h1>
        </div>

        <!-- Item Details Section -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-4">Informasi Barang</h5>
                <div class="row">
                    <div class="col-md-3 mb-3">
                        <label class="form-label fw-bold">Kode Barang</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($barang['KD_BARANG']); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label fw-bold">Merek Barang</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($barang['NAMA_MEREK']); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label fw-bold">Kategori Barang</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($barang['NAMA_KATEGORI']); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label fw-bold">Nama Barang</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($barang['NAMA_BARANG']); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                </div>
            </div>
        </div>

        <!-- Table Riwayat Resupply -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tableRiwayat" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID TRANSFER</th>
                            <th>ID DETAIL TRANSFER</th>
                            <th>Lokasi Awal</th>
                            <th>Lokasi tujuan</th>
                            <th>WAKTU</th>
                            <th>Total Pesan Transfer (dus)</th>
                            <th>Total Masuk (dus)</th>
                            <th>Total Dikirim (dus)</th>
                            <th>Total Ditolak (dus)</th>
                            <th>Batch</th>
                            <th>STATUS</th>
                            <th>ACTION</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_riwayat && $result_riwayat->num_rows > 0): ?>
                            <?php while ($row = $result_riwayat->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['ID_TRANSFER_BARANG']); ?></td>
                                    <td><?php echo htmlspecialchars($row['ID_DETAIL_TRANSFER_BARANG']); ?></td>
                                    <td><?php echo isset($lokasi_map[$row['KD_LOKASI_ASAL']]) ? htmlspecialchars($lokasi_map[$row['KD_LOKASI_ASAL']]) : htmlspecialchars($row['KD_LOKASI_ASAL']); ?></td>
                                    <td><?php echo isset($lokasi_map[$row['KD_LOKASI_TUJUAN']]) ? htmlspecialchars($lokasi_map[$row['KD_LOKASI_TUJUAN']]) : htmlspecialchars($row['KD_LOKASI_TUJUAN']); ?></td>
                                    <td data-order="<?php 
                                        $waktu_order = '';
                                        switch($row['STATUS_DETAIL']) {
                                            case 'DIPESAN':
                                                $waktu_order = !empty($row['WAKTU_PESAN_TRANSFER']) ? strtotime($row['WAKTU_PESAN_TRANSFER']) : 0;
                                                break;
                                            case 'DIKIRIM':
                                                $waktu_order = !empty($row['WAKTU_KIRIM_TRANSFER']) ? strtotime($row['WAKTU_KIRIM_TRANSFER']) : 0;
                                                break;
                                            case 'SELESAI':
                                                // Use negative timestamp for DESC sorting (newest first)
                                                $waktu_order = !empty($row['WAKTU_SELESAI_TRANSFER']) ? -strtotime($row['WAKTU_SELESAI_TRANSFER']) : 0;
                                                break;
                                            default:
                                                $waktu_order = !empty($row['WAKTU_PESAN_TRANSFER']) ? strtotime($row['WAKTU_PESAN_TRANSFER']) : 0;
                                        }
                                        echo $waktu_order;
                                    ?>"><?php echo formatWaktuStack($row['WAKTU_PESAN_TRANSFER'], $row['WAKTU_KIRIM_TRANSFER'], $row['WAKTU_SELESAI_TRANSFER'], $row['STATUS_DETAIL']); ?></td>
                                    <td><?php echo number_format($row['TOTAL_PESAN_TRANSFER_DUS'], 0, ',', '.'); ?></td>
                                    <td><?php echo $row['TOTAL_MASUK_DUS'] ? number_format($row['TOTAL_MASUK_DUS'], 0, ',', '.') : '-'; ?></td>
                                    <td><?php echo $row['TOTAL_KIRIM_DUS'] ? number_format($row['TOTAL_KIRIM_DUS'], 0, ',', '.') : '-'; ?></td>
                                    <td><?php echo $row['TOTAL_DITOLAK_DUS'] ? number_format($row['TOTAL_DITOLAK_DUS'], 0, ',', '.') : '-'; ?></td>
                                    <td>
                                        <?php 
                                        if (!empty($row['BATCH_INFO'])) {
                                            $batches = explode('|', $row['BATCH_INFO']);
                                            echo '<div class="d-flex flex-column gap-1">';
                                            foreach ($batches as $batch_str) {
                                                $batch_parts = explode(':', $batch_str);
                                                if (count($batch_parts) >= 3) {
                                                    $id_pesan = htmlspecialchars($batch_parts[1]);
                                                    $jumlah_dus = number_format(intval($batch_parts[2]), 0, ',', '.');
                                                    $tgl_expired = !empty($batch_parts[3]) ? htmlspecialchars($batch_parts[3]) : '-';
                                                    
                                                    echo '<div class="small">';
                                                    echo '<strong>' . $id_pesan . '</strong><br>';
                                                    echo 'Jumlah: ' . $jumlah_dus . ' dus';
                                                    if ($tgl_expired != '-') {
                                                        $date_expired = new DateTime($tgl_expired);
                                                        echo '<br>Exp: ' . $date_expired->format('d/m/Y');
                                                    }
                                                    echo '</div>';
                                                }
                                            }
                                            echo '</div>';
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                    <td data-order="<?php 
                                        $status_order = 0;
                                        switch($row['STATUS_DETAIL']) {
                                            case 'DIPESAN':
                                                $status_order = 1;
                                                break;
                                            case 'DIKIRIM':
                                                $status_order = 2;
                                                break;
                                            case 'SELESAI':
                                                $status_order = 3;
                                                break;
                                            default:
                                                $status_order = 4;
                                        }
                                        echo $status_order;
                                    ?>">
                                        <?php 
                                        $status_text = '';
                                        $status_class = '';
                                        switch($row['STATUS_DETAIL']) {
                                            case 'DIPESAN':
                                                $status_text = 'Dipesan';
                                                $status_class = 'warning';
                                                break;
                                            case 'DIKIRIM':
                                                $status_text = 'Dikirim';
                                                $status_class = 'info';
                                                break;
                                            case 'SELESAI':
                                                $status_text = 'Selesai';
                                                $status_class = 'success';
                                                break;
                                            case 'DIBATALKAN':
                                                $status_text = 'Dibatalkan';
                                                $status_class = 'danger';
                                                break;
                                            case 'TIDAK_DIKIRIM':
                                                $status_text = 'Tidak Dikirim';
                                                $status_class = 'secondary';
                                                break;
                                            default:
                                                $status_text = $row['STATUS_DETAIL'];
                                                $status_class = 'secondary';
                                        }
                                        ?>
                                        <span class="badge bg-<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <button class="btn-view btn-sm" onclick="lihatSuratJalan('<?php echo htmlspecialchars($row['ID_TRANSFER_BARANG']); ?>')">Lihat Surat Jalan</button>
                                            <?php if ($row['STATUS_DETAIL'] == 'DIPESAN'): ?>
                                                <button class="btn btn-danger btn-sm" onclick="batalkanTransfer('<?php echo htmlspecialchars($row['ID_DETAIL_TRANSFER_BARANG']); ?>')">Batalkan</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" class="text-center text-muted">Tidak ada riwayat resupply</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Lihat Surat Jalan -->
    <div class="modal fade" id="modalLihatSuratJalan" tabindex="-1" aria-labelledby="modalLihatSuratJalanLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalLihatSuratJalanLabel">Lihat Surat Jalan</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0" style="height: 80vh; overflow: hidden;">
                    <iframe id="suratJalanIframe" src="" style="width: 100%; height: 100%; border: none;"></iframe>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>

    <!-- jQuery (required for DataTables) -->
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- DataTables JS -->
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Sidebar Script -->
    <script src="includes/sidebar.js"></script>
    <!-- DataTables Initialization -->
    <script>
        $(document).ready(function() {
            $.fn.dataTable.ext.errMode = 'none';
            
            $('#tableRiwayat').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada riwayat resupply'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[10, 'asc'], [4, 'asc']], // Sort by Status (priority) then Waktu
                columnDefs: [
                    { orderable: false, targets: [9, 11] }, // Disable sorting on Batch and Action columns
                    { type: 'num', targets: [10, 4] } // Status and Waktu columns use numeric sorting
                ],
                scrollX: true,
                autoWidth: false
            }).on('error.dt', function(e, settings, techNote, message) {
                console.log('DataTables error suppressed:', message);
                return false;
            });
        });

        function lihatSuratJalan(idTransfer) {
            // Set iframe source ke download_surat_jalan.php
            $('#suratJalanIframe').attr('src', 'download_surat_jalan.php?id_transfer=' + encodeURIComponent(idTransfer));
            
            // Buka modal
            var modal = new bootstrap.Modal(document.getElementById('modalLihatSuratJalan'));
            modal.show();
        }

        function batalkanTransfer(idDetailTransfer) {
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi',
                text: 'Apakah Anda yakin ingin membatalkan transfer ini?',
                showCancelButton: true,
                confirmButtonText: 'Ya, Ubah',
                cancelButtonText: 'Batal',
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: '',
                        method: 'POST',
                        data: {
                            action: 'batalkan_transfer',
                            id_detail_transfer: idDetailTransfer
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Berhasil!',
                                    text: response.message,
                                    confirmButtonColor: '#667eea',
                                    timer: 1500,
                                    timerProgressBar: true
                                }).then(() => {
                                    location.reload();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Gagal!',
                                    text: response.message,
                                    confirmButtonColor: '#e74c3c'
                                });
                            }
                        },
                        error: function() {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error!',
                                text: 'Terjadi kesalahan saat membatalkan transfer!',
                                confirmButtonColor: '#e74c3c'
                            });
                        }
                    });
                }
            });
        }
    </script>
</body>
</html>

