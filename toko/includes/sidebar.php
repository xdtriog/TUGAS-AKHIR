<?php
// Sidebar Component untuk Toko
// Usage: include 'includes/sidebar.php';
// Set $active_page variable before including to set active menu item
// Example: $active_page = 'dashboard'; include 'includes/sidebar.php';

// Default active page jika tidak diset
if (!isset($active_page)) {
    $active_page = '';
}
?>
<!-- Sidebar -->
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <span id="sidebar-title">Sistem Informasi Persediaan</span>
        <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
            <span id="toggleIcon">‹</span>
        </button>
    </div>
    <ul class="sidebar-menu">
        <li><a href="dashboard.php" class="<?php echo $active_page == 'dashboard' ? 'active' : ''; ?>" data-icon="📊"><span>Dashboard</span></a></li>
        <li><a href="barang_masuk.php" class="<?php echo $active_page == 'barang_masuk' ? 'active' : ''; ?>" data-icon="📥"><span>Barang masuk</span></a></li>
        <li><a href="point_of_sale.php" class="<?php echo $active_page == 'point_of_sale' ? 'active' : ''; ?>" data-icon="💰"><span>Point Of Sale</span></a></li>
        <li><a href="mutasi_barang_rusak.php" class="<?php echo $active_page == 'mutasi_barang_rusak' ? 'active' : ''; ?>" data-icon="⚠️"><span>Mutasi Barang Rusak</span></a></li>
        <li><a href="stock_opname.php" class="<?php echo $active_page == 'stock_opname' ? 'active' : ''; ?>" data-icon="📋"><span>Stock opname</span></a></li>
        <li><a href="../logout.php" data-icon="🚪"><span>Logout</span></a></li>
    </ul>
</div>

