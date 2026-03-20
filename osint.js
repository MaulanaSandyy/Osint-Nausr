// osint.js - OSINT Tracking System
// Mengumpulkan data dari pengunjung dan mengirim ke developer
(function() {
    // Cek apakah file diakses langsung
    if (window.location.protocol === 'file:' || 
        (window.location.href.indexOf('osint.js') > -1 && document.currentScript === null)) {
        window.location.href = 'about:blank';
    }
    
    // Hapus semua console
    const noop = function(){};
    window.console = {
        log: noop, info: noop, warn: noop, error: noop, debug: noop, table: noop
    };
})();

// Inisialisasi OSINT tracker
document.addEventListener('DOMContentLoaded', () => {
    console.log('%c🔍 OSINT TRACKER READY', 'background: #dc3545; color: white; padding: 5px;');
    console.log('%c⚠️ INI ADALAH SISTEM OSINT - DATA PENGUNJUNG AKAN DIKUMPULKAN', 'background: #ffc107; color: #333; padding: 5px;');
    
    window.osintTracker = new OSINTTracker();
});