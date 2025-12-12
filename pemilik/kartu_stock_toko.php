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
$kd_barang = isset($_GET['kd_barang']) ? trim($_GET['kd_barang']) : '';

if (empty($kd_lokasi)) {
    header("Location: laporan.php");
    exit();
}

// Validasi lokasi dan pastikan adalah toko
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

// Query untuk mendapatkan daftar barang di lokasi ini
$query_barang = "SELECT DISTINCT
    mb.KD_BARANG,
    mb.NAMA_BARANG,
    COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
    COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
    s.JUMLAH_BARANG as STOCK_SEKARANG,
    s.SATUAN
FROM STOCK s
INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
WHERE s.KD_LOKASI = ?
ORDER BY mb.NAMA_BARANG ASC";

$stmt_barang = $conn->prepare($query_barang);
$stmt_barang->bind_param("s", $kd_lokasi);
$stmt_barang->execute();
$result_barang = $stmt_barang->get_result();

// Jika kd_barang dipilih, ambil data stock history
$stock_history = [];
$barang_selected = null;
$stock_awal = 0;

if (!empty($kd_barang)) {
    // Validasi bahwa barang ada di lokasi ini
    $query_validate = "SELECT mb.KD_BARANG, mb.NAMA_BARANG, mb.SATUAN_PERDUS, mb.BERAT,
                       COALESCE(mm.NAMA_MEREK, '-') as NAMA_MEREK,
                       COALESCE(mk.NAMA_KATEGORI, '-') as NAMA_KATEGORI,
                       s.JUMLAH_BARANG as STOCK_SEKARANG, s.SATUAN
                       FROM STOCK s
                       INNER JOIN MASTER_BARANG mb ON s.KD_BARANG = mb.KD_BARANG
                       LEFT JOIN MASTER_MEREK mm ON mb.KD_MEREK_BARANG = mm.KD_MEREK_BARANG
                       LEFT JOIN MASTER_KATEGORI_BARANG mk ON mb.KD_KATEGORI_BARANG = mk.KD_KATEGORI_BARANG
                       WHERE s.KD_BARANG = ? AND s.KD_LOKASI = ?";
    $stmt_validate = $conn->prepare($query_validate);
    $stmt_validate->bind_param("ss", $kd_barang, $kd_lokasi);
    $stmt_validate->execute();
    $result_validate = $stmt_validate->get_result();
    
    if ($result_validate->num_rows > 0) {
        $barang_selected = $result_validate->fetch_assoc();
        
        // Get stock awal (sebelum periode filter)
        $query_stock_awal = "SELECT JUMLAH_AKHIR 
                            FROM STOCK_HISTORY 
                            WHERE KD_BARANG = ? AND KD_LOKASI = ? 
                            AND DATE(WAKTU_CHANGE) < ?
                            ORDER BY WAKTU_CHANGE DESC 
                            LIMIT 1";
        $stmt_stock_awal = $conn->prepare($query_stock_awal);
        $stmt_stock_awal->bind_param("sss", $kd_barang, $kd_lokasi, $tanggal_dari);
        $stmt_stock_awal->execute();
        $result_stock_awal = $stmt_stock_awal->get_result();
        
        // Stock Awal Periode = JUMLAH_AWAL dari history pertama dalam periode (data pertama dari filter Tanggal Dari)
        $tanggal_dari_datetime = $tanggal_dari . ' 00:00:00';
        $tanggal_sampai_end = date('Y-m-d', strtotime($tanggal_sampai . ' +1 day'));
        $query_first_in_period = "SELECT JUMLAH_AWAL 
                                FROM STOCK_HISTORY 
                                WHERE KD_BARANG = ? AND KD_LOKASI = ? 
                                AND WAKTU_CHANGE >= ? AND WAKTU_CHANGE < ?
                                ORDER BY WAKTU_CHANGE ASC 
                                LIMIT 1";
        $stmt_first = $conn->prepare($query_first_in_period);
        $stmt_first->bind_param("ssss", $kd_barang, $kd_lokasi, $tanggal_dari_datetime, $tanggal_sampai_end);
        $stmt_first->execute();
        $result_first = $stmt_first->get_result();
        
        if ($result_first->num_rows > 0) {
            // Ada history dalam periode, ambil JUMLAH_AWAL dari history pertama
            $stock_awal = intval($result_first->fetch_assoc()['JUMLAH_AWAL']);
        } else {
            // Tidak ada history dalam periode, ambil dari JUMLAH_AKHIR history terakhir sebelum periode
            if ($result_stock_awal->num_rows > 0) {
                $stock_awal = intval($result_stock_awal->fetch_assoc()['JUMLAH_AKHIR']);
            } else {
                // Tidak ada history sama sekali, gunakan stock saat ini
                $stock_awal = intval($barang_selected['STOCK_SEKARANG']);
            }
        }
        
        // Query untuk mendapatkan stock history dalam periode
        // Gunakan >= dan < untuk memastikan semua history dalam periode terambil termasuk yang di akhir hari
        $tanggal_sampai_end = date('Y-m-d', strtotime($tanggal_sampai . ' +1 day'));
        $query_history = "SELECT 
            sh.ID_HISTORY_STOCK,
            sh.WAKTU_CHANGE,
            sh.TIPE_PERUBAHAN,
            sh.REF,
            sh.JUMLAH_AWAL,
            sh.JUMLAH_PERUBAHAN,
            sh.JUMLAH_AKHIR,
            sh.SATUAN,
            u.NAMA as NAMA_USER
        FROM STOCK_HISTORY sh
        LEFT JOIN USERS u ON sh.UPDATED_BY = u.ID_USERS
        WHERE sh.KD_BARANG = ? AND sh.KD_LOKASI = ?
        AND sh.WAKTU_CHANGE >= ? AND sh.WAKTU_CHANGE < ?
        ORDER BY sh.WAKTU_CHANGE DESC, sh.ID_HISTORY_STOCK DESC";
        
        $stmt_history = $conn->prepare($query_history);
        $tanggal_dari_datetime = $tanggal_dari . ' 00:00:00';
        $stmt_history->bind_param("ssss", $kd_barang, $kd_lokasi, $tanggal_dari_datetime, $tanggal_sampai_end);
        $stmt_history->execute();
        $result_history = $stmt_history->get_result();
        
        while ($row = $result_history->fetch_assoc()) {
            $stock_history[] = $row;
        }
    }
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

// Format tipe perubahan
function formatTipePerubahan($tipe) {
    $labels = [
        'PEMESANAN' => 'Pemesanan',
        'TRANSFER' => 'Transfer',
        'OPNAME' => 'Stock Opname',
        'RUSAK' => 'Mutasi Rusak',
        'PENJUALAN' => 'Penjualan'
    ];
    return $labels[$tipe] ?? $tipe;
}

// Set active page untuk sidebar
$active_page = 'laporan';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pemilik - Kartu Stock Toko</title>
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
            <h1 class="page-title">Pemilik - Kartu Stock Toko</h1>
            <p class="text-muted mb-0"><?php echo htmlspecialchars($lokasi['NAMA_LOKASI']); ?> - <?php echo htmlspecialchars($lokasi['ALAMAT_LOKASI']); ?></p>
        </div>

        <!-- Filter Section -->
        <div class="card mb-4">
            <div class="card-body">
                <h5 class="card-title mb-3">Filter Kartu Stock</h5>
                <form method="GET" action="" class="row g-3">
                    <input type="hidden" name="kd_lokasi" value="<?php echo htmlspecialchars($kd_lokasi); ?>">
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Pilih Barang</label>
                        <select class="form-select" name="kd_barang" id="selectBarang" required>
                            <option value="">-- Pilih Barang --</option>
                            <?php 
                            // Reset result pointer
                            $result_barang->data_seek(0);
                            if ($result_barang && $result_barang->num_rows > 0): ?>
                                <?php while ($row = $result_barang->fetch_assoc()): ?>
                                    <option value="<?php echo htmlspecialchars($row['KD_BARANG']); ?>" 
                                            <?php echo ($kd_barang == $row['KD_BARANG']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($row['NAMA_BARANG']); ?> 
                                        (<?php echo htmlspecialchars($row['KD_BARANG']); ?>)
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Tanggal Dari</label>
                        <input type="text" class="form-control" name="tanggal_dari" id="tanggal_dari" 
                               value="<?php echo !empty($tanggal_dari) ? date('d/m/Y', strtotime($tanggal_dari)) : ''; ?>" 
                               placeholder="dd/mm/yyyy" required readonly>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-bold">Tanggal Sampai</label>
                        <input type="text" class="form-control" name="tanggal_sampai" id="tanggal_sampai" 
                               value="<?php echo !empty($tanggal_sampai) ? date('d/m/Y', strtotime($tanggal_sampai)) : ''; ?>" 
                               placeholder="dd/mm/yyyy" required readonly>
                    </div>
                    <div class="col-md-3 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-primary">Tampilkan</button>
                        <?php if (!empty($kd_barang)): ?>
                            <a href="download_kartu_stock_toko.php?kd_lokasi=<?php echo urlencode($kd_lokasi); ?>&kd_barang=<?php echo urlencode($kd_barang); ?>&tanggal_dari=<?php echo urlencode($tanggal_dari); ?>&tanggal_sampai=<?php echo urlencode($tanggal_sampai); ?>" 
                               class="btn btn-success" target="_blank">Download</a>
                        <?php endif; ?>
                        <a href="laporan.php" class="btn btn-secondary">Kembali</a>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($kd_barang) && $barang_selected): ?>
            <!-- Informasi Barang -->
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">Informasi Barang</h5>
                    <div class="row">
                        <div class="col-md-2">
                            <strong>Kode Barang:</strong><br>
                            <?php echo htmlspecialchars($barang_selected['KD_BARANG']); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Nama Barang:</strong><br>
                            <?php echo htmlspecialchars($barang_selected['NAMA_BARANG']); ?>
                        </div>
                        <div class="col-md-2">
                            <strong>Merek:</strong><br>
                            <?php echo htmlspecialchars($barang_selected['NAMA_MEREK']); ?>
                        </div>
                        <div class="col-md-2">
                            <strong>Kategori:</strong><br>
                            <?php echo htmlspecialchars($barang_selected['NAMA_KATEGORI']); ?>
                        </div>
                        <div class="col-md-2">
                            <strong>Berat (gr):</strong><br>
                            <?php echo number_format($barang_selected['BERAT'], 0, ',', '.'); ?>
                        </div>
                        <div class="col-md-1">
                            <strong>Stock Saat Ini:</strong><br>
                            <span class="badge bg-primary"><?php echo number_format($barang_selected['STOCK_SEKARANG'], 0, ',', '.'); ?> <?php echo htmlspecialchars($barang_selected['SATUAN']); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="stat-card primary">
                        <div class="stat-value"><?php echo number_format($stock_awal, 0, ',', '.'); ?></div>
                        <div class="stat-label">Stock Awal Periode</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card success">
                        <div class="stat-value">
                            <?php 
                            $total_masuk = 0;
                            foreach ($stock_history as $h) {
                                if ($h['JUMLAH_PERUBAHAN'] > 0) {
                                    $total_masuk += $h['JUMLAH_PERUBAHAN'];
                                }
                            }
                            echo number_format($total_masuk, 0, ',', '.');
                            ?>
                        </div>
                        <div class="stat-label">Total Masuk</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card danger">
                        <div class="stat-value">
                            <?php 
                            $total_keluar = 0;
                            foreach ($stock_history as $h) {
                                if ($h['JUMLAH_PERUBAHAN'] < 0) {
                                    $total_keluar += abs($h['JUMLAH_PERUBAHAN']);
                                }
                            }
                            echo number_format($total_keluar, 0, ',', '.');
                            ?>
                        </div>
                        <div class="stat-label">Total Keluar</div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="stat-card info">
                        <div class="stat-value">
                            <?php 
                            // Stock akhir periode = JUMLAH_AKHIR dari data terakhir dalam periode (data dengan waktu terakhir)
                            $stock_akhir_periode = 0;
                            if (count($stock_history) > 0) {
                                // Query sudah di-sort DESC berdasarkan WAKTU_CHANGE, jadi data pertama adalah yang terakhir
                                // Ambil JUMLAH_AKHIR dari data dengan waktu terakhir
                                $stock_akhir_periode = intval($stock_history[0]['JUMLAH_AKHIR']);
                            } else {
                                // Jika tidak ada history dalam periode, gunakan stock awal
                                $stock_akhir_periode = $stock_awal;
                            }
                            
                            echo number_format($stock_akhir_periode, 0, ',', '.');
                            ?>
                        </div>
                        <div class="stat-label">Stock Akhir Periode</div>
                    </div>
                </div>
            </div>

            <!-- Table Kartu Stock -->
            <div class="table-section">
                <div class="table-responsive">
                    <table id="tableKartuStock" class="table table-custom table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Tanggal/Waktu</th>
                                <th>Tipe Perubahan</th>
                                <th>Referensi</th>
                                <th>Jumlah Awal</th>
                                <th>Masuk</th>
                                <th>Keluar</th>
                                <th>Jumlah Akhir</th>
                                <th>Satuan</th>
                                <th>User</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            if (count($stock_history) > 0): 
                            ?>
                                <?php foreach ($stock_history as $h): ?>
                                    <tr>
                            <td data-order="<?php echo strtotime($h['WAKTU_CHANGE']); ?>"><?php echo formatWaktu($h['WAKTU_CHANGE']); ?></td>
                                        <td>
                                            <span class="badge 
                                                <?php 
                                                echo $h['TIPE_PERUBAHAN'] == 'PEMESANAN' ? 'bg-success' : 
                                                    ($h['TIPE_PERUBAHAN'] == 'TRANSFER' ? 'bg-info' : 
                                                    ($h['TIPE_PERUBAHAN'] == 'OPNAME' ? 'bg-warning' : 
                                                    ($h['TIPE_PERUBAHAN'] == 'RUSAK' ? 'bg-danger' : 'bg-primary'))); 
                                                ?>">
                                                <?php echo formatTipePerubahan($h['TIPE_PERUBAHAN']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($h['REF'] ?? '-'); ?></td>
                                        <td><?php echo number_format($h['JUMLAH_AWAL'], 0, ',', '.'); ?></td>
                                        <td class="text-success fw-bold">
                                            <?php echo $h['JUMLAH_PERUBAHAN'] > 0 ? number_format($h['JUMLAH_PERUBAHAN'], 0, ',', '.') : '-'; ?>
                                        </td>
                                        <td class="text-danger fw-bold">
                                            <?php echo $h['JUMLAH_PERUBAHAN'] < 0 ? number_format(abs($h['JUMLAH_PERUBAHAN']), 0, ',', '.') : '-'; ?>
                                        </td>
                                        <td class="fw-bold"><?php echo number_format($h['JUMLAH_AKHIR'], 0, ',', '.'); ?></td>
                                        <td><?php echo htmlspecialchars($h['SATUAN']); ?></td>
                                        <td><?php echo htmlspecialchars($h['NAMA_USER'] ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted">Tidak ada pergerakan stock pada periode yang dipilih</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> Silakan pilih barang untuk menampilkan kartu stock.
            </div>
        <?php endif; ?>
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
            
            $('#tableKartuStock').DataTable({
                language: {
                    url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
                    emptyTable: 'Tidak ada pergerakan stock'
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

