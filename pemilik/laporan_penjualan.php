<?php
session_start();
require_once '../dbconnect.php';

// Cek apakah user sudah login dan adalah pemilik (OWNR)
if (!isset($_SESSION['user_id']) || substr($_SESSION['user_id'], 0, 4) != 'OWNR') {
    header("Location: ../index.php");
    exit();
}

// Get kd_lokasi dari parameter
$kd_lokasi = isset($_GET['kd_lokasi']) ? trim($_GET['kd_lokasi']) : '';

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

// Handle AJAX request untuk get detail nota (harus di atas query lainnya)
if ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['get_detail_nota'])) {
    header('Content-Type: application/json');
    
    $id_nota_jual = isset($_GET['id_nota_jual']) ? trim($_GET['id_nota_jual']) : '';
    
    if (empty($id_nota_jual)) {
        echo json_encode(['success' => false, 'message' => 'ID Nota tidak valid!']);
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
    WHERE nj.ID_NOTA_JUAL = ? AND nj.KD_LOKASI = ?";
    $stmt_nota = $conn->prepare($query_nota);
    $stmt_nota->bind_param("ss", $id_nota_jual, $kd_lokasi);
    $stmt_nota->execute();
    $result_nota = $stmt_nota->get_result();
    
    if ($result_nota->num_rows == 0) {
        echo json_encode(['success' => false, 'message' => 'Nota tidak ditemukan!']);
        exit();
    }
    
    $nota = $result_nota->fetch_assoc();
    
    // Query untuk mendapatkan detail barang
    $query_detail = "SELECT 
        dnj.KD_BARANG,
        dnj.JUMLAH_JUAL_BARANG,
        dnj.HARGA_JUAL_BARANG,
        dnj.TOTAL_JUAL_UANG,
        dnj.HARGA_BELI_BARANG,
        dnj.TOTAL_BELI_UANG,
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
    
    $detail_items = [];
    while ($row = $result_detail->fetch_assoc()) {
        $detail_items[] = [
            'kd_barang' => $row['KD_BARANG'],
            'nama_barang' => $row['NAMA_BARANG'],
            'nama_merek' => $row['NAMA_MEREK'],
            'nama_kategori' => $row['NAMA_KATEGORI'],
            'jumlah' => intval($row['JUMLAH_JUAL_BARANG']),
            'harga_jual' => floatval($row['HARGA_JUAL_BARANG']),
            'total_jual_uang' => floatval($row['TOTAL_JUAL_UANG']),
            'harga_beli' => floatval($row['HARGA_BELI_BARANG']),
            'total_beli_uang' => floatval($row['TOTAL_BELI_UANG'])
        ];
    }
    
    // Format waktu
    $date = new DateTime($nota['WAKTU_NOTA']);
    $bulan = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
    ];
    $waktu_formatted = $date->format('d') . ' ' . $bulan[(int)$date->format('m')] . ' ' . $date->format('Y') . ' ' . $date->format('H:i') . ' WIB';
    
    echo json_encode([
        'success' => true,
        'nota' => [
            'id_nota_jual' => $nota['ID_NOTA_JUAL'],
            'waktu_nota' => $waktu_formatted,
            'total_jual_barang' => intval($nota['TOTAL_JUAL_BARANG']),
            'sub_total_jual' => floatval($nota['SUB_TOTAL_JUAL']),
            'pajak' => floatval($nota['PAJAK']),
            'grand_total' => floatval($nota['GRAND_TOTAL']),
            'sub_total_beli' => floatval($nota['SUB_TOTAL_BELI']),
            'gross_profit' => floatval($nota['GROSS_PROFIT']),
            'nama_user' => $nota['NAMA_USER'] ?? '-',
            'nama_lokasi' => $nota['NAMA_LOKASI'],
            'alamat_lokasi' => $nota['ALAMAT_LOKASI']
        ],
        'items' => $detail_items
    ]);
    exit();
}

// Get filter tanggal (default: bulan ini)
$tanggal_dari = isset($_GET['tanggal_dari']) ? trim($_GET['tanggal_dari']) : date('Y-m-01');
$tanggal_sampai = isset($_GET['tanggal_sampai']) ? trim($_GET['tanggal_sampai']) : date('Y-m-t');


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

// Format rupiah
function formatRupiah($angka) {
    return "Rp. " . number_format($angka, 0, ',', '.');
}

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

// Set active page untuk sidebar
$active_page = 'laporan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Laporan Penjualan <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Custom CSS -->
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="dashboard-body">
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Pemilik - Laporan Penjualan</h1>
            <p class="text-muted mb-0"><?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?> - <?php echo htmlspecialchars($lokasi['ALAMAT_LOKASI']); ?></p>
        </div>

        <!-- Filter Section -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">Filter Laporan</h5>
                <form method="GET" action="" class="row g-3">
                    <input type="hidden" name="kd_lokasi" value="<?php echo htmlspecialchars($kd_lokasi); ?>">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Tanggal Dari</label>
                        <input type="date" class="form-control" name="tanggal_dari" value="<?php echo htmlspecialchars($tanggal_dari); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Tanggal Sampai</label>
                        <input type="date" class="form-control" name="tanggal_sampai" value="<?php echo htmlspecialchars($tanggal_sampai); ?>" required>
                    </div>
                    <div class="col-md-4 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="download_laporan_penjualan.php?kd_lokasi=<?php echo urlencode($kd_lokasi); ?>&tanggal_dari=<?php echo urlencode($tanggal_dari); ?>&tanggal_sampai=<?php echo urlencode($tanggal_sampai); ?>" 
                           class="btn btn-success" target="_blank">Download Laporan</a>
                        <a href="laporan.php" class="btn btn-secondary">Kembali</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="stat-card primary">
                    <div class="stat-value"><?php echo number_format($summary['TOTAL_TRANSAKSI'], 0, ',', '.'); ?></div>
                    <div class="stat-label">Total Transaksi</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card info">
                    <div class="stat-value"><?php echo number_format($summary['TOTAL_BARANG_TERJUAL'], 0, ',', '.'); ?></div>
                    <div class="stat-label">Total Barang Terjual</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card success">
                    <div class="stat-value"><?php echo formatRupiah($summary['TOTAL_PENJUALAN']); ?></div>
                    <div class="stat-label">Sub Total Jual</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card danger">
                    <div class="stat-value"><?php echo formatRupiah($summary['TOTAL_BELI']); ?></div>
                    <div class="stat-label">Sub Total Beli</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card" style="background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white;">
                    <div class="stat-value"><?php echo formatRupiah($summary['TOTAL_GROSS_PROFIT']); ?></div>
                    <div class="stat-label">Gross Profit</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-card warning">
                    <div class="stat-value"><?php echo formatRupiah($summary['TOTAL_GRAND_TOTAL']); ?></div>
                    <div class="stat-label">Grand Total (Termasuk Pajak)</div>
                </div>
            </div>
        </div>

        <!-- Table Laporan Penjualan -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tableLaporanPenjualan" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Tanggal/Waktu</th>
                            <th>ID Nota Jual</th>
                            <th>Kasir</th>
                            <th>Jumlah Barang</th>
                            <th>Sub Total Jual</th>
                            <th>Sub Total Beli</th>
                            <th>Gross Profit</th>
                            <th>Pajak</th>
                            <th>Grand Total</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_penjualan && $result_penjualan->num_rows > 0): ?>
                            <?php while ($row = $result_penjualan->fetch_assoc()): ?>
                                <tr>
                                    <td data-order="<?php echo strtotime($row['WAKTU_NOTA']); ?>"><?php echo formatWaktu($row['WAKTU_NOTA']); ?></td>
                                    <td><?php echo htmlspecialchars($row['ID_NOTA_JUAL']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_USER'] ?? '-'); ?></td>
                                    <td class="text-center"><?php echo number_format($row['TOTAL_JUAL_BARANG'], 0, ',', '.'); ?></td>
                                    <td class="text-end"><?php echo formatRupiah($row['SUB_TOTAL_JUAL']); ?></td>
                                    <td class="text-end"><?php echo formatRupiah($row['SUB_TOTAL_BELI']); ?></td>
                                    <td class="text-end" style="color: #28a745; font-weight: bold;"><?php echo formatRupiah($row['GROSS_PROFIT']); ?></td>
                                    <td class="text-end"><?php echo formatRupiah($row['PAJAK']); ?></td>
                                    <td class="text-end" style="font-weight: bold;"><?php echo formatRupiah($row['GRAND_TOTAL']); ?></td>
                                    <td>
                                        <button class="btn-view btn-sm" onclick="lihatNota('<?php echo htmlspecialchars($row['ID_NOTA_JUAL']); ?>')">
                                            Lihat Nota
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted">Tidak ada data penjualan pada periode yang dipilih</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
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
    <!-- Sidebar Script -->
    <script src="includes/sidebar.js"></script>
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <!-- Modal Lihat Nota -->
    <div class="modal fade" id="modalLihatNota" tabindex="-1" aria-labelledby="modalLihatNotaLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <h5 class="modal-title" id="modalLihatNotaLabel">Detail Nota Jual</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body" id="notaContent">
                    <div class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        $(document).ready(function() {
            // Disable DataTables error reporting
            $.fn.dataTable.ext.errMode = 'none';
            
            $('#tableLaporanPenjualan').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data penjualan'
                },
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Semua"]],
                order: [[0, 'desc']], // Sort by Tanggal descending
                columnDefs: [
                    { orderable: false, targets: [9] } // Disable sorting on Action column
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

        function lihatNota(idNotaJual) {
            if (!idNotaJual || idNotaJual.trim() === '') {
                Swal.fire({
                    icon: 'error',
                    title: 'Error!',
                    text: 'ID Nota tidak valid!',
                    confirmButtonColor: '#e74c3c'
                });
                return;
            }

            // Show loading
            $('#notaContent').html('<div class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>');
            $('#modalLihatNota').modal('show');

            // Get detail nota via AJAX
            $.ajax({
                url: '',
                method: 'GET',
                data: {
                    get_detail_nota: '1',
                    id_nota_jual: idNotaJual
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        var nota = response.nota;
                        var items = response.items;
                        
                        // Build HTML nota
                        var html = '<div class="nota-container" style="font-family: Arial, sans-serif;">';
                        html += '<div class="text-center mb-4">';
                        html += '<h4 class="mb-1">NOTA PENJUALAN</h4>';
                        html += '<h5 class="mb-1">CV. KHARISMA WIJAYA ABADI KUSUMA</h5>';
                        html += '<p class="mb-0 text-muted">' + escapeHtml(nota.alamat_lokasi) + '</p>';
                        html += '</div>';
                        
                        html += '<hr>';
                        
                        html += '<div class="mb-3">';
                        html += '<div class="row mb-2">';
                        html += '<div class="col-4"><strong>ID Nota:</strong></div>';
                        html += '<div class="col-8">' + escapeHtml(nota.id_nota_jual) + '</div>';
                        html += '</div>';
                        html += '<div class="row mb-2">';
                        html += '<div class="col-4"><strong>Tanggal:</strong></div>';
                        html += '<div class="col-8">' + escapeHtml(nota.waktu_nota) + '</div>';
                        html += '</div>';
                        html += '<div class="row mb-2">';
                        html += '<div class="col-4"><strong>Kasir:</strong></div>';
                        html += '<div class="col-8">' + escapeHtml(nota.nama_user) + '</div>';
                        html += '</div>';
                        html += '<div class="row">';
                        html += '<div class="col-4"><strong>Toko:</strong></div>';
                        html += '<div class="col-8">' + escapeHtml(nota.nama_lokasi) + '</div>';
                        html += '</div>';
                        html += '</div>';
                        
                        html += '<hr>';
                        
                        html += '<table class="table table-bordered mb-3" style="font-size: 0.9em;">';
                        html += '<thead class="table-light">';
                        html += '<tr>';
                        html += '<th style="width: 5%;">No</th>';
                        html += '<th style="width: 40%;">Nama Barang</th>';
                        html += '<th style="width: 10%;" class="text-center">Jumlah</th>';
                        html += '<th style="width: 20%;" class="text-end">Harga</th>';
                        html += '<th style="width: 25%;" class="text-end">Subtotal</th>';
                        html += '</tr>';
                        html += '</thead>';
                        html += '<tbody>';
                        
                        items.forEach(function(item, index) {
                            html += '<tr>';
                            html += '<td>' + (index + 1) + '</td>';
                            html += '<td>' + escapeHtml(item.nama_barang) + '</td>';
                            html += '<td class="text-center">' + numberFormat(item.jumlah) + '</td>';
                            html += '<td class="text-end">' + formatRupiah(item.harga_jual) + '</td>';
                            html += '<td class="text-end">' + formatRupiah(item.total_jual_uang) + '</td>';
                            html += '</tr>';
                        });
                        
                        html += '</tbody>';
                        html += '</table>';
                        
                        html += '<div class="text-end mb-3" style="font-size: 0.95em;">';
                        html += '<div class="row mb-2">';
                        html += '<div class="col-6 text-start">Sub Total:</div>';
                        html += '<div class="col-6 text-end">' + formatRupiah(nota.sub_total_jual) + '</div>';
                        html += '</div>';
                        html += '<div class="row mb-2">';
                        html += '<div class="col-6 text-start">Pajak (11%):</div>';
                        html += '<div class="col-6 text-end">' + formatRupiah(nota.pajak) + '</div>';
                        html += '</div>';
                        html += '<div class="row" style="border-top: 2px solid #000; padding-top: 8px; margin-top: 8px;">';
                        html += '<div class="col-6 text-start"><strong>Grand Total:</strong></div>';
                        html += '<div class="col-6 text-end"><strong style="font-size: 1.3em;">' + formatRupiah(nota.grand_total) + '</strong></div>';
                        html += '</div>';
                        html += '</div>';
                        
                        // Tambahkan informasi internal (untuk admin) di bagian bawah
                        html += '<hr style="border-top: 1px dashed #ccc; margin: 15px 0;">';
                        html += '<div class="text-start mb-2" style="font-size: 0.85em; color: #666;">';
                        html += '<div class="row mb-1">';
                        html += '<div class="col-6"><strong>Info Internal:</strong></div>';
                        html += '</div>';
                        html += '<div class="row mb-1">';
                        html += '<div class="col-6">Sub Total Beli:</div>';
                        html += '<div class="col-6 text-end">' + formatRupiah(nota.sub_total_beli) + '</div>';
                        html += '</div>';
                        html += '<div class="row">';
                        html += '<div class="col-6">Gross Profit:</div>';
                        html += '<div class="col-6 text-end" style="color: #28a745; font-weight: bold;">' + formatRupiah(nota.gross_profit) + '</div>';
                        html += '</div>';
                        html += '</div>';
                        
                        html += '<hr>';
                        html += '<div class="text-center text-muted mt-3">';
                        html += '<small>Terima kasih atas kunjungan Anda</small>';
                        html += '</div>';
                        html += '</div>';
                        
                        $('#notaContent').html(html);
                    } else {
                        $('#notaContent').html('<div class="alert alert-danger">' + escapeHtml(response.message) + '</div>');
                    }
                },
                error: function(xhr, status, error) {
                    $('#notaContent').html('<div class="alert alert-danger">Gagal memuat detail nota!</div>');
                }
            });
        }

        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text ? text.replace(/[&<>"']/g, function(m) { return map[m]; }) : '';
        }

        function numberFormat(num) {
            return num ? num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.') : '0';
        }

        function formatRupiah(angka) {
            return "Rp. " + numberFormat(Math.round(angka));
        }

        // Reset modal saat ditutup
        $('#modalLihatNota').on('hidden.bs.modal', function() {
            $('#notaContent').html('<div class="text-center"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>');
        });
    </script>
</body>
</html>

