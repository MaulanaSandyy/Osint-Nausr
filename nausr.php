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

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OSINT Dashboard Premium</title>
</head>
<body>
</body>
</html>