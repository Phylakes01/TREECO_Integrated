<?php
// Optional diagnostic endpoint. dashboard_api.php already polls automatically.
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['status' => 'error', 'message' => 'Use GET; this setup reads the ESP32 hotspot.']);
    exit;
}
require __DIR__ . '/config.php';
require __DIR__ . '/hardware.php';
try {
    treeco_poll_sensor($pdo);
    $state = $pdo->query('SELECT * FROM hardware_state WHERE id = 1')->fetch();
    $ok = $state && (int)$state['sensor_ok'] === 1
        && time() - (int)$state['received_at'] < TREECO_OFFLINE_SECONDS;
    echo json_encode(['status' => $ok ? 'success' : 'offline', 'connected' => (bool)$ok]);
} catch (Throwable $e) {
    error_log('TREECO hotspot: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Check MySQL and database.sql installation.']);
}
