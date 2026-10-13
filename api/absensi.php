<?php
// ============================================
// Smart Attendance System - API Absensi
// ============================================
require_once 'koneksi.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse('error', 'Method tidak diizinkan');
}

$input     = json_decode(file_get_contents('php://input'), true);
$userId    = (int)($input['user_id']   ?? $_POST['user_id']   ?? 0);
$latitude  = (float)($input['latitude']  ?? $_POST['latitude']  ?? 0);
$longitude = (float)($input['longitude'] ?? $_POST['longitude'] ?? 0);
$foto      = $input['foto'] ?? $_POST['foto'] ?? '';

if (!$userId || !$latitude || !$longitude) {
    sendResponse('error', 'Data tidak lengkap (user_id, latitude, longitude wajib)');
}

// ============================================
// Koordinat kampus – diambil dari tabel settings
// ============================================
$connCfg = getConnection();
$cfg     = [];

// Cek apakah tabel settings ada
$tblCheck = $connCfg->query("SHOW TABLES LIKE 'settings'");
if ($tblCheck && $tblCheck->num_rows > 0) {
    $resCfg = $connCfg->query(
        "SELECT setting_key, setting_val FROM settings
         WHERE setting_key IN ('kampus_lat','kampus_lng','max_radius','batas_jam','batas_menit')"
    );
    if ($resCfg) {
        while ($r = $resCfg->fetch_assoc()) {
            $cfg[$r['setting_key']] = $r['setting_val'];
        }
    }
}
$connCfg->close();

// Gunakan nilai dari DB, fallback ke default jika tidak ada
$kampusLat  = isset($cfg['kampus_lat'])  ? (float)$cfg['kampus_lat']  : -6.2088;
$kampusLng  = isset($cfg['kampus_lng'])  ? (float)$cfg['kampus_lng']  : 106.8456;
$maxRadius  = isset($cfg['max_radius'])  ? (int)$cfg['max_radius']    : 100;
$batasJam   = isset($cfg['batas_jam'])   ? (int)$cfg['batas_jam']     : 8;
$batasMenit = isset($cfg['batas_menit']) ? (int)$cfg['batas_menit']   : 0;

/**
 * Hitung jarak dua koordinat menggunakan Haversine Formula
 */
function hitungJarak($lat1, $lng1, $lat2, $lng2) {
    $R    = 6371000; // radius bumi dalam meter
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a    = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);
    $c    = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $R * $c;
}

$jarak = hitungJarak($latitude, $longitude, $kampusLat, $kampusLng);

if ($jarak > $maxRadius) {
    sendResponse('error', 'Lokasi di luar radius kampus. Jarak: ' . round($jarak) . 'm (maks ' . $maxRadius . 'm)');
}

// Tentukan status hadir / terlambat berdasarkan settings
// Hadir  = waktu absen <= batas (jam:menit)
// Terlambat = waktu absen > batas
$jam    = (int)date('H');
$menit  = (int)date('i');

// Konversi ke total menit sejak tengah malam untuk perbandingan mudah
$waktuSekarang = $jam * 60 + $menit;
$waktuBatas    = $batasJam * 60 + $batasMenit;

$status = ($waktuSekarang <= $waktuBatas) ? 'hadir' : 'terlambat';

// Simpan foto Base64 jika ada
$fotoPath = '';
if (!empty($foto)) {
    $fotoData = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $foto));
    if ($fotoData !== false) {
        $fotoName = 'foto_' . $userId . '_' . time() . '.jpg';
        $fotoDir  = __DIR__ . '/../uploads/';
        if (!is_dir($fotoDir)) mkdir($fotoDir, 0755, true);
        file_put_contents($fotoDir . $fotoName, $fotoData);
        $fotoPath = 'uploads/' . $fotoName;
    }
}

$conn = getConnection();

// Cek apakah sudah absen hari ini
$today = date('Y-m-d');
$cek   = $conn->prepare(
    "SELECT id FROM absensi WHERE user_id = ? AND DATE(waktu) = ?"
);
$cek->bind_param('is', $userId, $today);
$cek->execute();
if ($cek->get_result()->num_rows > 0) {
    $conn->close();
    sendResponse('error', 'Anda sudah melakukan absensi hari ini');
}

$stmt = $conn->prepare(
    "INSERT INTO absensi (user_id, latitude, longitude, foto, status) VALUES (?, ?, ?, ?, ?)"
);
$stmt->bind_param('iddss', $userId, $latitude, $longitude, $fotoPath, $status);
$stmt->execute();
$insertId = $conn->insert_id;
$conn->close();

sendResponse('success', 'Absensi berhasil dicatat', [
    'absensi_id' => $insertId,
    'status'     => $status,
    'jarak'      => round($jarak) . 'm',
    'waktu'      => date('Y-m-d H:i:s'),
    'foto'       => $fotoPath
]);
?>
