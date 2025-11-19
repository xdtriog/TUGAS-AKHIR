<?php
// Sidebar Component
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
        <li><a href="stock.php" class="<?php echo $active_page == 'stock' ? 'active' : ''; ?>" data-icon="📦"><span>Stock</span></a></li>
        <li><a href="master_barang.php" class="<?php echo $active_page == 'master_barang' ? 'active' : ''; ?>" data-icon="📋"><span>Master Barang</span></a></li>
        <li><a href="master_merek.php" class="<?php echo $active_page == 'master_merek' ? 'active' : ''; ?>" data-icon="🏷️"><span>Master Merek</span></a></li>
        <li><a href="master_kategori.php" class="<?php echo $active_page == 'master_kategori' ? 'active' : ''; ?>" data-icon="📁"><span>Master Kategori</span></a></li>
        <li><a href="master_supplier.php" class="<?php echo $active_page == 'master_supplier' ? 'active' : ''; ?>" data-icon="🏢"><span>Master Supplier</span></a></li>
        <li><a href="master_lokasi.php" class="<?php echo $active_page == 'master_lokasi' ? 'active' : ''; ?>" data-icon="📍"><span>Master Lokasi</span></a></li>
        <li><a href="master_user.php" class="<?php echo $active_page == 'master_user' ? 'active' : ''; ?>" data-icon="👥"><span>Master User</span></a></li>
        <li><a href="laporan.php" class="<?php echo $active_page == 'laporan' ? 'active' : ''; ?>" data-icon="📊"><span>Laporan</span></a></li>
        <li><a href="../logout.php" data-icon="🚪"><span>Logout</span></a></li>
    </ul>
</div>

