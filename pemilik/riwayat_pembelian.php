<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
    exit();
}

// Handle AJAX request untuk update status
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_status') {
    header('Content-Type: application/json');
    
    $id_pesan = isset($_POST['id_pesan']) ? trim($_POST['id_pesan']) : '';
    $status_baru = isset($_POST['status']) ? trim($_POST['status']) : '';
    
    if (empty($id_pesan) || empty($status_baru)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid!']);
        exit();
    }
    
    // Update status
    $update_query = "UPDATE PESAN_BARANG SET STATUS = ? WHERE ID_PESAN_BARANG = ?";
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bind_param("ss", $status_baru, $id_pesan);
    
    if ($update_stmt->execute()) {
        // Jika status diubah menjadi DIKIRIM, update WAKTU_SAMPAI
        if ($status_baru == 'DIKIRIM') {
            $update_waktu = "UPDATE PESAN_BARANG SET WAKTU_SAMPAI = CURRENT_TIMESTAMP WHERE ID_PESAN_BARANG = ?";
            $update_waktu_stmt = $conn->prepare($update_waktu);
            $update_waktu_stmt->bind_param("s", $id_pesan);
            $update_waktu_stmt->execute();
            $update_waktu_stmt->close();
        }
        
        echo json_encode(['success' => true, 'message' => 'Status berhasil diperbarui']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Gagal memperbarui status!']);
    }
    
    $update_stmt->close();
    exit();
}

// Get parameter kd_barang
$kd_barang = isset($_GET['kd_barang']) ? trim($_GET['kd_barang']) : '';

if (empty($kd_barang)) {
    header("Location: stock.php");
    exit();
}

// Query untuk mendapatkan data barang (tidak filter status agar bisa lihat riwayat barang tidak aktif)
$query_barang = "SELECT 
    mb.KD_BARANG,
    mb.NAMA_BARANG,
    mb.BERAT,
    mb.STATUS as STATUS_BARANG,
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
    header("Location: stock.php");
    exit();
}

$barang = $result_barang->fetch_assoc();

// Query untuk mendapatkan lokasi dari riwayat pembelian pertama (untuk header)
$query_lokasi_header = "SELECT DISTINCT
    ml.NAMA_LOKASI,
    ml.ALAMAT_LOKASI
FROM PESAN_BARANG pb
LEFT JOIN MASTER_LOKASI ml ON pb.KD_LOKASI = ml.KD_LOKASI
WHERE pb.KD_BARANG = ? AND ml.NAMA_LOKASI IS NOT NULL
LIMIT 1";
$stmt_lokasi_header = $conn->prepare($query_lokasi_header);
$stmt_lokasi_header->bind_param("s", $kd_barang);
$stmt_lokasi_header->execute();
$result_lokasi_header = $stmt_lokasi_header->get_result();
$lokasi_header = $result_lokasi_header->num_rows > 0 ? $result_lokasi_header->fetch_assoc() : ['NAMA_LOKASI' => '', 'ALAMAT_LOKASI' => ''];

// Query untuk mendapatkan riwayat pembelian
$query_riwayat = "SELECT 
    pb.ID_PESAN_BARANG,
    pb.KD_LOKASI,
    pb.KD_SUPPLIER,
    pb.JUMLAH_PESAN_BARANG_DUS,
    pb.HARGA_PESAN_BARANG_DUS,
    pb.BIAYA_PENGIRIMAAN,
    pb.TOTAL_MASUK_DUS,
    pb.JUMLAH_DITOLAK_DUS,
    pb.WAKTU_PESAN,
    pb.WAKTU_ESTIMASI_SAMPAI,
    pb.WAKTU_SAMPAI,
    pb.STATUS,
    ml.NAMA_LOKASI,
    COALESCE(ms.KD_SUPPLIER, '-') as SUPPLIER_KD,
    COALESCE(ms.NAMA_SUPPLIER, '-') as NAMA_SUPPLIER,
    COALESCE(ms.ALAMAT_SUPPLIER, '-') as ALAMAT_SUPPLIER,
    s.SATUAN
FROM PESAN_BARANG pb
LEFT JOIN MASTER_LOKASI ml ON pb.KD_LOKASI = ml.KD_LOKASI
LEFT JOIN MASTER_SUPPLIER ms ON pb.KD_SUPPLIER = ms.KD_SUPPLIER
LEFT JOIN STOCK s ON pb.KD_BARANG = s.KD_BARANG AND pb.KD_LOKASI = s.KD_LOKASI
WHERE pb.KD_BARANG = ?
ORDER BY pb.WAKTU_PESAN DESC";
$stmt_riwayat = $conn->prepare($query_riwayat);
$stmt_riwayat->bind_param("s", $kd_barang);
$stmt_riwayat->execute();
$result_riwayat = $stmt_riwayat->get_result();

// Format tanggal dan waktu Indonesia (tanpa status)
function formatTanggalWaktu($tanggal) {
    if (empty($tanggal) || $tanggal == null) {
        return '-';
    }
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $date = new DateTime($tanggal);
    $tanggal_formatted = $date->format('d') . ' ' . $bulan[(int)$date->format('m')] . ' ' . $date->format('Y');
    $waktu_formatted = $date->format('H:i') . ' WIB';
    
    return $tanggal_formatted . ' ' . $waktu_formatted;
}

// Format waktu dengan badge untuk kolom waktu (stack)
function formatWaktuStack($waktu_pesan, $waktu_estimasi, $waktu_sampai, $status) {
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    
    $html = '<div class="d-flex flex-column gap-1">';
    
    // Waktu diterima (jika ada WAKTU_SAMPAI dan status SELESAI) - tampilkan di atas
    if (!empty($waktu_sampai) && $status == 'SELESAI') {
        $date_sampai = new DateTime($waktu_sampai);
        $tanggal_sampai = $date_sampai->format('d') . ' ' . $bulan[(int)$date_sampai->format('m')] . ' ' . $date_sampai->format('Y');
        $waktu_sampai_formatted = $date_sampai->format('H:i') . ' WIB';
        $html .= '<div>';
        $html .= htmlspecialchars($tanggal_sampai . ' ' . $waktu_sampai_formatted) . ' ';
        $html .= '<span class="badge bg-success">DITERIMA</span>';
        $html .= '</div>';
    }
    
    // Waktu estimasi - tampilkan di tengah (jika ada)
    if (!empty($waktu_estimasi)) {
        $date_estimasi = new DateTime($waktu_estimasi);
        $tanggal_estimasi = $date_estimasi->format('d') . ' ' . $bulan[(int)$date_estimasi->format('m')] . ' ' . $date_estimasi->format('Y');
        $waktu_estimasi_formatted = $date_estimasi->format('H:i') . ' WIB';
        $html .= '<div>';
        $html .= htmlspecialchars($tanggal_estimasi . ' ' . $waktu_estimasi_formatted) . ' ';
        $html .= '<span class="badge bg-info">ESTIMASI</span>';
        $html .= '</div>';
    }
    
    // Waktu dipesan - tampilkan di bawah
    if (!empty($waktu_pesan)) {
        $date_pesan = new DateTime($waktu_pesan);
        $tanggal_pesan = $date_pesan->format('d') . ' ' . $bulan[(int)$date_pesan->format('m')] . ' ' . $date_pesan->format('Y');
        $waktu_pesan_formatted = $date_pesan->format('H:i') . ' WIB';
        $html .= '<div>';
        $html .= htmlspecialchars($tanggal_pesan . ' ' . $waktu_pesan_formatted) . ' ';
        $html .= '<span class="badge bg-warning">DIPESAN</span>';
        $html .= '</div>';
    }
    
    $html .= '</div>';
    
    return $html;
}

// Format rupiah
function formatRupiah($angka) {
    if (empty($angka) || $angka == null || $angka == 0) {
        return '-';
    }
    return 'Rp ' . number_format($angka, 0, ',', '.');
}

// Set active page untuk sidebar
$active_page = 'stock';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Riwayat Pembelian</title>
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
            <h1 class="page-title">Pemilik - Riwayat Pembelian - <?php echo htmlspecialchars($lokasi_header['NAMA_LOKASI'] ?: 'Gudang'); ?></h1>
            <?php if (!empty($lokasi_header['ALAMAT_LOKASI'])): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars($lokasi_header['ALAMAT_LOKASI']); ?></p>
            <?php endif; ?>
        </div>

        <!-- Item Details Section -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-4">Informasi Barang</h5>
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Kode Barang</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($barang['KD_BARANG']); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Merek Barang</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($barang['NAMA_MEREK']); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Kategori Barang</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($barang['NAMA_KATEGORI']); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label fw-bold">Berat Barang (gr)</label>
                        <input type="text" class="form-control" value="<?php echo number_format($barang['BERAT'], 0, ',', '.'); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                    <div class="col-md-12 mb-3">
                        <label class="form-label fw-bold">Nama Barang</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($barang['NAMA_BARANG']); ?>" readonly style="background-color: #e9ecef;">
                    </div>
                </div>
            </div>
        </div>

        <!-- Table Riwayat Pembelian -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tableRiwayat" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>ID PESAN</th>
                            <th>Supplier</th>
                            <th>Waktu</th>
                            <th>Jumlah Pemesanan</th>
                            <th>Total Masuk</th>
                            <th>Jumlah Ditolak</th>
                            <th>Satuan</th>
                            <th>Harga Beli</th>
                            <th>Total Bayar</th>
                            <th>Biaya Pengiriman</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_riwayat->num_rows > 0): ?>
                            <?php while ($row = $result_riwayat->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['ID_PESAN_BARANG']); ?></td>
                                    <td>
                                        <?php 
                                        $supplier_display = '';
                                        if ($row['SUPPLIER_KD'] != '-' && $row['NAMA_SUPPLIER'] != '-') {
                                            $supplier_display = htmlspecialchars($row['SUPPLIER_KD'] . ' - ' . $row['NAMA_SUPPLIER']);
                                            if ($row['ALAMAT_SUPPLIER'] != '-') {
                                                $supplier_display .= ' - ' . htmlspecialchars($row['ALAMAT_SUPPLIER']);
                                            }
                                        } else {
                                            $supplier_display = '-';
                                        }
                                        echo $supplier_display;
                                        ?>
                                    </td>
                                    <td><?php echo formatWaktuStack($row['WAKTU_PESAN'], $row['WAKTU_ESTIMASI_SAMPAI'], $row['WAKTU_SAMPAI'], $row['STATUS']); ?></td>
                                    <td><?php echo $row['JUMLAH_PESAN_BARANG_DUS'] ? number_format($row['JUMLAH_PESAN_BARANG_DUS'], 0, ',', '.') : '-'; ?></td>
                                    <td><?php echo $row['TOTAL_MASUK_DUS'] ? number_format($row['TOTAL_MASUK_DUS'], 0, ',', '.') : '-'; ?></td>
                                    <td><?php echo $row['JUMLAH_DITOLAK_DUS'] ? number_format($row['JUMLAH_DITOLAK_DUS'], 0, ',', '.') : '-'; ?></td>
                                    <td><?php echo htmlspecialchars($row['SATUAN'] ?? 'Dus'); ?></td>
                                    <td><?php echo formatRupiah($row['HARGA_PESAN_BARANG_DUS']); ?></td>
                                    <td><?php 
                                        $total_bayar = 0;
                                        if ($row['TOTAL_MASUK_DUS'] && $row['HARGA_PESAN_BARANG_DUS']) {
                                            $total_bayar = $row['TOTAL_MASUK_DUS'] * $row['HARGA_PESAN_BARANG_DUS'];
                                        }
                                        echo formatRupiah($total_bayar);
                                    ?></td>
                                    <td><?php echo formatRupiah($row['BIAYA_PENGIRIMAAN']); ?></td>
                                    <td>
                                        <?php 
                                        $status_text = '';
                                        $status_class = '';
                                        switch($row['STATUS']) {
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
                                            default:
                                                $status_text = $row['STATUS'];
                                                $status_class = 'secondary';
                                        }
                                        ?>
                                        <span class="badge bg-<?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                    </td>
                                    <td>
                                        <div class="d-flex flex-wrap gap-1">
                                            <button class="btn-view btn-sm" onclick="lihatPO('<?php echo htmlspecialchars($row['ID_PESAN_BARANG']); ?>')">Lihat PO</button>
                                            <?php if ($row['STATUS'] == 'DIPESAN'): ?>
                                                <button class="btn btn-success btn-sm" onclick="ubahStatus('<?php echo htmlspecialchars($row['ID_PESAN_BARANG']); ?>', 'DIKIRIM')">Dikirim</button>
                                                <button class="btn btn-danger btn-sm" onclick="ubahStatus('<?php echo htmlspecialchars($row['ID_PESAN_BARANG']); ?>', 'DIBATALKAN')">Batalkan</button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Modal Lihat PO -->
    <div class="modal fade" id="modalLihatPO" tabindex="-1" aria-labelledby="modalLihatPOLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalLihatPOLabel">Lihat Purchase Order</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0" style="height: 80vh; overflow: hidden;">
                    <iframe id="poIframe" src="" style="width: 100%; height: 100%; border: none;"></iframe>
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
            // Disable DataTables error reporting
            $.fn.dataTable.ext.errMode = 'none';
            
            $('#tableRiwayat').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data riwayat pembelian'
                },
                pageLength: 10,
                lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
                order: [[2, 'desc']], // Sort by Waktu descending
                columnDefs: [
                    { orderable: false, targets: 11 } // Disable sorting on Action column
                ],
                scrollX: true,
                responsive: true,
                drawCallback: function(settings) {
                    if (settings.aoData.length === 0) {
                        return;
                    }
                }
            }).on('error.dt', function(e, settings, techNote, message) {
                console.log('DataTables error suppressed:', message);
                return false;
            });
        });

        function lihatPO(idPesan) {
            // Set iframe source ke download_po.php
            $('#poIframe').attr('src', 'download_po.php?id_pesan=' + encodeURIComponent(idPesan));
            
            // Buka modal
            var modal = new bootstrap.Modal(document.getElementById('modalLihatPO'));
            modal.show();
        }

        function ubahStatus(idPesan, statusBaru) {
            var statusText = statusBaru == 'DIKIRIM' ? 'Dikirim' : 'Dibatalkan';
            var confirmText = statusBaru == 'DIKIRIM' 
                ? 'Apakah Anda yakin ingin mengubah status pesanan menjadi "Dikirim"?'
                : 'Apakah Anda yakin ingin membatalkan pesanan ini?';
            
            Swal.fire({
                icon: 'question',
                title: 'Konfirmasi',
                text: confirmText,
                showCancelButton: true,
                confirmButtonText: 'Ya, Ubah',
                cancelButtonText: 'Batal',
                confirmButtonColor: statusBaru == 'DIKIRIM' ? '#0dcaf0' : '#dc3545',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    // AJAX request untuk update status
                    $.ajax({
                        url: 'riwayat_pembelian.php',
                        method: 'POST',
                        data: {
                            action: 'update_status',
                            id_pesan: idPesan,
                            status: statusBaru
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
                                text: 'Terjadi kesalahan saat mengubah status!',
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

