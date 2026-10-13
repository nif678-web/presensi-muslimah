<?php
// ============================================
// Smart Attendance System - Database Config
// ============================================

// Mendukung Environment Variables Railway, dengan fallback ke localhost untuk development lokal
define('DB_HOST', getenv('MYSQLHOST') ?: (getenv('DB_HOST') ?: 'localhost'));
define('DB_USER', getenv('MYSQLUSER') ?: (getenv('DB_USER') ?: 'root'));
define('DB_PASS', getenv('MYSQLPASSWORD') ?: (getenv('DB_PASS') ?: ''));
define('DB_NAME', getenv('MYSQLDATABASE') ?: (getenv('DB_NAME') ?: 'absensi_db'));
define('DB_PORT', getenv('MYSQLPORT') ?: (getenv('DB_PORT') ?: '3306'));

// Timezone WIB – wajib diset agar date() menggunakan waktu Indonesia
date_default_timezone_set('Asia/Jakarta');

// Header CORS untuk akses dari mobile app / web frontend
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight request untuk CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function getConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, (int)DB_PORT);
    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode([
            'status'  => 'error',
            'message' => 'Koneksi database gagal: ' . $conn->connect_error
        ]);
        exit();
    }
    $conn->set_charset('utf8mb4');
    return $conn;
}

function sendResponse($status, $message, $data = null) {
    $response = ['status' => $status, 'message' => $message];
    if ($data !== null) $response['data'] = $data;
    echo json_encode($response);
    exit();
}
?>