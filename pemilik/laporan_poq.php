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

// Query untuk mendapatkan data Interval POQ (hanya satu per barang - yang pertama kali dihitung)
// Interval POQ tidak ikut filter tanggal, muncul semua
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
WHERE interval_poq.KD_LOKASI = ?
ORDER BY mb.NAMA_BARANG ASC";

$stmt_interval_poq = $conn->prepare($query_interval_poq);
$stmt_interval_poq->bind_param("s", $kd_lokasi);
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
    s.JUMLAH_BARANG as STOCK_SAAT_INI,
    COALESCE(pb.ID_PESAN_BARANG, '-') as ID_PESAN_BARANG
FROM PERHITUNGAN_KUANTITAS_POQ poq
INNER JOIN MASTER_BARANG mb ON poq.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
LEFT JOIN STOCK s ON poq.KD_BARANG = s.KD_BARANG AND poq.KD_LOKASI = s.KD_LOKASI
LEFT JOIN PESAN_BARANG pb ON poq.ID_PERHITUNGAN_KUANTITAS_POQ = pb.ID_PERHITUNGAN_KUANTITAS_POQ
WHERE poq.KD_LOKASI = ?";

$query_kuantitas_poq .= " AND DATE(poq.WAKTU_PERHITUNGAN_KUANTITAS_POQ) BETWEEN ? AND ?
ORDER BY poq.WAKTU_PERHITUNGAN_KUANTITAS_POQ DESC, mb.NAMA_BARANG ASC";

$params_kuantitas = [$kd_lokasi, $tanggal_dari, $tanggal_sampai];
$param_types_kuantitas = "sss";

$stmt_kuantitas_poq = $conn->prepare($query_kuantitas_poq);
$stmt_kuantitas_poq->bind_param($param_types_kuantitas, ...$params_kuantitas);
$stmt_kuantitas_poq->execute();
$result_kuantitas_poq = $stmt_kuantitas_poq->get_result();

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
    <title>Pemilik - Laporan POQ <?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?></title>
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
            <h1 class="page-title">Pemilik - Laporan POQ</h1>
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
                        <a href="download_laporan_poq.php?kd_lokasi=<?php echo urlencode($kd_lokasi); ?>&tanggal_dari=<?php echo urlencode($tanggal_dari); ?>&tanggal_sampai=<?php echo urlencode($tanggal_sampai); ?>" 
                           class="btn btn-success" target="_blank">Download Laporan</a>
                        <a href="laporan.php" class="btn btn-secondary">Kembali</a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Table Interval POQ -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Interval POQ (Dihitung Sekali untuk Selamanya)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table id="tableIntervalPOQ" class="table table-custom table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Kode Barang</th>
                                <th>Merek</th>
                                <th>Kategori</th>
                                <th>Nama Barang</th>
                                <th>Berat (gr)</th>
                                <th>Demand Rate (dus/hari)</th>
                                <th>Setup Cost (Rp)</th>
                                <th>Holding Cost (Rp/dus/hari)</th>
                                <th>Interval POQ (hari)</th>
                                <th>Waktu Perhitungan Interval POQ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result_interval_poq && $result_interval_poq->num_rows > 0): ?>
                                <?php while ($row = $result_interval_poq->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['KD_BARANG']); ?></td>
                                        <td><?php echo htmlspecialchars($row['NAMA_MEREK']); ?></td>
                                        <td><?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?></td>
                                        <td><?php echo htmlspecialchars($row['NAMA_BARANG']); ?></td>
                                        <td class="text-center"><?php echo number_format($row['BERAT'], 0, ',', '.'); ?></td>
                                        <td class="text-center"><?php echo number_format($row['DEMAND_RATE'], 2, ',', '.'); ?></td>
                                        <td class="text-end"><?php echo formatRupiah($row['SETUP_COST']); ?></td>
                                        <td class="text-end"><?php echo formatRupiah($row['HOLDING_COST']); ?></td>
                                        <td class="text-center"><strong><?php echo number_format($row['INTERVAL_HARI'], 0, ',', '.'); ?></strong></td>
                                        <td data-order="<?php echo strtotime($row['WAKTU_PERHITUNGAN_INTERVAL_POQ']); ?>"><?php echo formatWaktu($row['WAKTU_PERHITUNGAN_INTERVAL_POQ']); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="11" class="text-center text-muted">Tidak ada data interval POQ</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Table Kuantitas POQ -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Kuantitas POQ (Dapat Berubah Setiap Perhitungan)</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table id="tableKuantitasPOQ" class="table table-custom table-striped table-hover">
                        <thead>
                            <tr>
                                <th>ID Pesan Barang</th>
                                <th>Kode Barang</th>
                                <th>Merek</th>
                                <th>Kategori</th>
                                <th>Nama Barang</th>
                                <th>Berat (gr)</th>
                                <th>Demand Rate (dus/hari)</th>
                                <th>Lead Time (hari)</th>
                                <th>Interval POQ (hari)</th>
                                <th>Stock Saat Perhitungan (dus)</th>
                                <th>Stock Saat Ini (dus)</th>
                                <th>Kuantitas POQ (dus)</th>
                                <th>Waktu Perhitungan Kuantitas POQ</th>
                                <th>Jatuh Tempo POQ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result_kuantitas_poq && $result_kuantitas_poq->num_rows > 0): ?>
                                <?php while ($row = $result_kuantitas_poq->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['ID_PESAN_BARANG']); ?></td>
                                        <td><?php echo htmlspecialchars($row['KD_BARANG']); ?></td>
                                        <td><?php echo htmlspecialchars($row['NAMA_MEREK']); ?></td>
                                        <td><?php echo htmlspecialchars($row['NAMA_KATEGORI']); ?></td>
                                        <td><?php echo htmlspecialchars($row['NAMA_BARANG']); ?></td>
                                        <td class="text-center"><?php echo number_format($row['BERAT'], 0, ',', '.'); ?></td>
                                        <td class="text-center"><?php echo number_format($row['DEMAND_RATE'], 2, ',', '.'); ?></td>
                                        <td class="text-center"><?php echo number_format($row['LEAD_TIME'], 0, ',', '.'); ?></td>
                                        <td class="text-center"><?php echo number_format($row['INTERVAL_HARI'], 0, ',', '.'); ?></td>
                                        <td class="text-center"><?php echo number_format($row['STOCK_SEKARANG'], 0, ',', '.'); ?></td>
                                        <td class="text-center"><?php echo number_format($row['STOCK_SAAT_INI'] ?? 0, 0, ',', '.'); ?></td>
                                        <td class="text-center"><strong><?php echo number_format($row['KUANTITAS_POQ'], 0, ',', '.'); ?></strong></td>
                                        <td data-order="<?php echo strtotime($row['WAKTU_PERHITUNGAN_KUANTITAS_POQ']); ?>"><?php echo formatWaktu($row['WAKTU_PERHITUNGAN_KUANTITAS_POQ']); ?></td>
                                        <td><?php echo formatTanggal($row['JATUH_TEMPO_POQ']); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="14" class="text-center text-muted">Tidak ada data perhitungan kuantitas POQ</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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
    <!-- Flatpickr JS -->
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <!-- Sidebar Script -->
    <script src="includes/sidebar.js"></script>
    
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
            
            $('#tableIntervalPOQ').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data interval POQ'
                },
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Semua"]],
                order: [[3, 'asc']], // Sort by Nama Barang ascending
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

            $('#tableKuantitasPOQ').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada data perhitungan kuantitas POQ'
                },
                pageLength: 25,
                lengthMenu: [[10, 25, 50, 100, -1], [10, 25, 50, 100, "Semua"]],
                order: [[12, 'desc']], // Sort by Waktu Perhitungan descending (now column 12)
                columnDefs: [
                    { orderable: false, targets: [] } // All columns are sortable
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
    </script>
</body>
</html>

