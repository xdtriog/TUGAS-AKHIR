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

// Get filter tanggal (default: bulan ini)
// Konversi format dd/mm/yyyy ke Y-m-d jika diperlukan
$tanggal_dari_raw = isset($_GET['tanggal_dari']) ? trim($_GET['tanggal_dari']) : date('d/m/Y', strtotime(date('Y-m-01')));
$tanggal_sampai_raw = isset($_GET['tanggal_sampai']) ? trim($_GET['tanggal_sampai']) : date('d/m/Y', strtotime(date('Y-m-t')));

// Konversi dari dd/mm/yyyy ke Y-m-d
function convertDateToYMD($dateString) {
    if (empty($dateString)) return '';
    // Jika sudah format Y-m-d, return as is
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
        return $dateString;
    }
    // Jika format dd/mm/yyyy, konversi
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dateString, $matches)) {
        return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
    }
    return $dateString;
}

$tanggal_dari = convertDateToYMD($tanggal_dari_raw);
$tanggal_sampai = convertDateToYMD($tanggal_sampai_raw);

// Jika konversi gagal, gunakan default
if (empty($tanggal_dari) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_dari)) {
    $tanggal_dari = date('Y-m-01');
}
if (empty($tanggal_sampai) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal_sampai)) {
    $tanggal_sampai = date('Y-m-t');
}

// Query untuk mendapatkan data mutasi barang rusak (tanpa batch karena toko)
$query_mutasi = "SELECT 
    mbr.ID_MUTASI_BARANG_RUSAK,
    mbr.KD_BARANG,
    mbr.WAKTU_MUTASI,
    mbr.JUMLAH_MUTASI_DUS,
    mbr.SATUAN_PERDUS,
    mbr.TOTAL_BARANG_PIECES,
    mbr.HARGA_BARANG_PIECES,
    mbr.TOTAL_UANG,
    u.NAMA as NAMA_USER,
    mb.NAMA_BARANG,
    mb.BERAT,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI
FROM MUTASI_BARANG_RUSAK mbr
INNER JOIN MASTER_BARANG mb ON mbr.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
LEFT JOIN USERS u ON mbr.UPDATED_BY = u.ID_USERS
WHERE mbr.KD_LOKASI = ?
AND DATE(mbr.WAKTU_MUTASI) BETWEEN ? AND ?
ORDER BY mbr.WAKTU_MUTASI DESC, mb.NAMA_BARANG ASC";

$stmt_mutasi = $conn->prepare($query_mutasi);
$stmt_mutasi->bind_param("sss", $kd_lokasi, $tanggal_dari, $tanggal_sampai);
$stmt_mutasi->execute();
$result_mutasi = $stmt_mutasi->get_result();

// Query untuk mendapatkan summary
$query_summary = "SELECT 
    COUNT(DISTINCT mbr.ID_MUTASI_BARANG_RUSAK) as TOTAL_MUTASI,
    COUNT(DISTINCT mbr.KD_BARANG) as TOTAL_BARANG,
    COALESCE(SUM(mbr.TOTAL_BARANG_PIECES), 0) as TOTAL_MUTASI_PIECES,
    COALESCE(SUM(mbr.TOTAL_UANG), 0) as TOTAL_NILAI_MUTASI
FROM MUTASI_BARANG_RUSAK mbr
WHERE mbr.KD_LOKASI = ?
AND DATE(mbr.WAKTU_MUTASI) BETWEEN ? AND ?";

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
    <title>Pemilik - Laporan Mutasi Barang Rusak <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- Flatpickr CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <!-- Custom CSS -->
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body class="dashboard-body">
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Pemilik - Laporan Mutasi Barang Rusak</h1>
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
                        <input type="text" class="form-control" name="tanggal_dari" id="tanggal_dari" 
                               value="<?php echo !empty($tanggal_dari) ? date('d/m/Y', strtotime($tanggal_dari)) : ''; ?>" 
                               placeholder="dd/mm/yyyy" required readonly>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Tanggal Sampai</label>
                        <input type="text" class="form-control" name="tanggal_sampai" id="tanggal_sampai" 
                               value="<?php echo !empty($tanggal_sampai) ? date('d/m/Y', strtotime($tanggal_sampai)) : ''; ?>" 
                               placeholder="dd/mm/yyyy" required readonly>
                    </div>
                    <div class="col-md-4 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary">Filter</button>
                        <a href="download_laporan_mutasi_barang_rusak_toko.php?kd_lokasi=<?php echo urlencode($kd_lokasi); ?>&tanggal_dari=<?php echo urlencode($tanggal_dari); ?>&tanggal_sampai=<?php echo urlencode($tanggal_sampai); ?>" 
                           class="btn btn-success" target="_blank">Download Laporan</a>
                        <a href="laporan.php" class="btn btn-secondary">Kembali</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-card primary">
                    <div class="stat-value"><?php echo number_format($summary['TOTAL_MUTASI'], 0, ',', '.'); ?></div>
                    <div class="stat-label">Total Mutasi</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card info">
                    <div class="stat-value"><?php echo number_format($summary['TOTAL_BARANG'], 0, ',', '.'); ?></div>
                    <div class="stat-label">Total Barang</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card warning">
                    <div class="stat-value">
                        <span class="<?php echo $summary['TOTAL_MUTASI_PIECES'] < 0 ? 'text-danger' : ($summary['TOTAL_MUTASI_PIECES'] > 0 ? 'text-success' : 'text-muted'); ?> fw-bold">
                            <?php echo ($summary['TOTAL_MUTASI_PIECES'] > 0 ? '+' : '') . number_format($summary['TOTAL_MUTASI_PIECES'], 0, ',', '.'); ?>
                        </span>
                    </div>
                    <div class="stat-label">Total Mutasi (Pieces)</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card danger">
                    <div class="stat-value">
                        <span class="<?php echo $summary['TOTAL_NILAI_MUTASI'] < 0 ? 'text-danger' : ($summary['TOTAL_NILAI_MUTASI'] > 0 ? 'text-success' : 'text-muted'); ?> fw-bold">
                            <?php echo formatRupiah($summary['TOTAL_NILAI_MUTASI']); ?>
                        </span>
                    </div>
                    <div class="stat-label">Total Nilai Mutasi</div>
                </div>
            </div>
        </div>

        <!-- Table Laporan Mutasi Barang Rusak -->
        <div class="table-section">
            <div class="table-responsive">
                <table id="tableLaporanMutasiBarangRusak" class="table table-custom table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Tanggal/Waktu</th>
                            <th>ID Mutasi</th>
                            <th>Kode Barang</th>
                            <th>Merek</th>
                            <th>Kategori</th>
                            <th>Nama Barang</th>
                            <th>Berat (gr)</th>
                            <th>Jumlah Mutasi (Pieces)</th>
                            <th>Harga (Rp/Piece)</th>
                            <th>Total Nilai Mutasi</th>
                            <th>User</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result_mutasi && $result_mutasi->num_rows > 0): ?>
                            <?php while ($row = $result_mutasi->fetch_assoc()): ?>
                                <tr>
                                    <td data-order="<?php echo strtotime($row['WAKTU_MUTASI']); ?>"><?php echo formatWaktu($row['WAKTU_MUTASI']); ?></td>
                                    <td><?php echo htmlspecialchars($row['ID_MUTASI_BARANG_RUSAK']); ?></td>
                                    <td><?php echo htmlspecialchars($row['KD_BARANG']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_MEREK']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?></td>
                                    <td><?php echo htmlspecialchars($row['NAMA_BARANG']); ?></td>
                                    <td><?php echo number_format($row['BERAT'], 0, ',', '.'); ?></td>
                                    <td>
                                        <span class="text-danger fw-bold">
                                            <?php echo number_format($row['TOTAL_BARANG_PIECES'], 0, ',', '.'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo formatRupiah($row['HARGA_BARANG_PIECES']); ?></td>
                                    <td>
                                        <span class="text-danger fw-bold">
                                            <?php echo formatRupiah($row['TOTAL_UANG']); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['NAMA_USER'] ?? '-'); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted">Tidak ada data mutasi barang rusak pada periode yang dipilih</td>
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
    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <!-- Sidebar Script -->
    <script src="includes/sidebar.js"></script>
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        $(document).ready(function() {
            // Inisialisasi Flatpickr dengan format dd/mm/yyyy
            flatpickr("#tanggal_dari", {
                dateFormat: "d/m/Y",
                locale: {
                    firstDayOfWeek: 1,
                    weekdays: {
                        shorthand: ["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"],
                        longhand: ["Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"]
                    },
                    months: {
                        shorthand: ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"],
                        longhand: ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"]
                    }
                }
            });
            
            flatpickr("#tanggal_sampai", {
                dateFormat: "d/m/Y",
                locale: {
                    firstDayOfWeek: 1,
                    weekdays: {
                        shorthand: ["Min", "Sen", "Sel", "Rab", "Kam", "Jum", "Sab"],
                        longhand: ["Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"]
                    },
                    months: {
                        shorthand: ["Jan", "Feb", "Mar", "Apr", "Mei", "Jun", "Jul", "Agu", "Sep", "Okt", "Nov", "Des"],
                        longhand: ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"]
                    }
                }
            });
            
            // Konversi dd/mm/yyyy ke yyyy-mm-dd saat submit
            $('form').on('submit', function(e) {
                const tanggalDari = $('#tanggal_dari').val();
                const tanggalSampai = $('#tanggal_sampai').val();
                
                // Konversi format sebelum submit
                if (tanggalDari) {
                    const partsDari = tanggalDari.split('/');
                    if (partsDari.length === 3) {
                        $('#tanggal_dari').val(partsDari[2] + '-' + partsDari[1] + '-' + partsDari[0]);
                    }
                }
                if (tanggalSampai) {
                    const partsSampai = tanggalSampai.split('/');
                    if (partsSampai.length === 3) {
                        $('#tanggal_sampai').val(partsSampai[2] + '-' + partsSampai[1] + '-' + partsSampai[0]);
                    }
                }
            });
            
            // Disable DataTables error reporting
            $.fn.dataTable.ext.errMode = 'none';
            
            $('#tableLaporanMutasiBarangRusak').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data mutasi barang rusak'
                },
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Semua"]],
                order: [[0, 'desc']], // Sort by Tanggal descending
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
    </script>
</body>
</html>

