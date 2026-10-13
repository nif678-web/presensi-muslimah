<?php
// ============================================
// Smart Attendance System - API Riwayat Absensi
// ============================================
require_once 'koneksi.php';

$userId = (int)($_GET['user_id'] ?? 0);
if (!$userId) {
    sendResponse('error', 'Parameter user_id diperlukan');
}

$conn = getConnection();
$stmt = $conn->prepare("
    SELECT
        a.id,
        a.latitude,
        a.longitude,
        a.foto,
        a.waktu,
        a.status,
        u.nama,
        u.nim
    FROM absensi a
    JOIN users u ON a.user_id = u.id
    WHERE a.user_id = ?
    ORDER BY a.waktu DESC
    LIMIT 30
");
$stmt->bind_param('i', $userId);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}
$conn->close();

sendResponse('success', 'Data riwayat absensi', $data);
?>
