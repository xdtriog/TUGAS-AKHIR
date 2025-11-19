// Sidebar Toggle Script
// Include this script in pages that use sidebar.php

document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('sidebarToggle');
    const toggleIcon = document.getElementById('toggleIcon');
    const sidebarTitle = document.getElementById('sidebar-title');
    
    if (!sidebar || !toggleBtn) {
        return; // Sidebar elements not found
    }
    
    // Load state from localStorage
    const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    if (isCollapsed) {
        sidebar.classList.add('collapsed');
        if (toggleIcon) toggleIcon.textContent = '›';
        if (sidebarTitle) sidebarTitle.style.display = 'none';
    }
    
    toggleBtn.addEventListener('click', function() {
        sidebar.classList.toggle('collapsed');
        document.body.classList.toggle('sidebar-collapsed');
        
        if (sidebar.classList.contains('collapsed')) {
            if (toggleIcon) toggleIcon.textContent = '›';
            if (sidebarTitle) sidebarTitle.style.display = 'none';
            localStorage.setItem('sidebarCollapsed', 'true');
        } else {
            if (toggleIcon) toggleIcon.textContent = '‹';
            if (sidebarTitle) sidebarTitle.style.display = 'block';
            localStorage.setItem('sidebarCollapsed', 'false');
        }
    });
    
    // Apply body class on load
    if (isCollapsed) {
        document.body.classList.add('sidebar-collapsed');
    }
});

