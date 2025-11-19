// DataTables Initialization Script
// Usage: Include this script after DataTables JS files
// Make sure to set table ID and options

// Disable DataTables error reporting globally
$.fn.dataTable.ext.errMode = 'none';

/**
 * Initialize DataTable with default settings
 * @param {string} tableId - ID of the table element
 * @param {object} options - Additional DataTables options
 */
function initDataTable(tableId, options = {}) {
    const defaultOptions = {
        language: {
            url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/id.json',
            emptyTable: 'Tidak ada data'
        },
        pageLength: 10,
        lengthMenu: [[10, 25, 50, -1], [10, 25, 50, "Semua"]],
        order: [[0, 'asc']],
        responsive: true,
        dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>rt<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        drawCallback: function(settings) {
            // Suppress any errors
            if (settings.aoData.length === 0) {
                return;
            }
        },
        ...options
    };
    
    return $(tableId).DataTable(defaultOptions).on('error.dt', function(e, settings, techNote, message) {
        // Suppress error messages
        console.log('DataTables error suppressed:', message);
        return false;
    });
}

// Auto-initialize tables with class 'datatable' on page load
$(document).ready(function() {
    $('.datatable').each(function() {
        const table = $(this);
        const tableId = '#' + table.attr('id');
        
        if (tableId !== '#undefined') {
            initDataTable(tableId);
        }
    });
});

