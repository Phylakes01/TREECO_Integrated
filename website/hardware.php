<?php
require_once __DIR__ . '/device_config.php';

// Only the server contacts the ESP32. The browser remains on localhost/treeco.
function treeco_read_sensor(): ?array {
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'timeout' => 1.5, 'follow_location' => 0,
        'header' => "Accept: application/json\r\nConnection: close\r\n"
    ]]);
    $raw = @file_get_contents(TREECO_SENSOR_URL, false, $context, 0, 4097);
    if ($raw === false || strlen($raw) > 4096
        || !preg_match('/^HTTP\/\S+ 200\b/', $http_response_header[0] ?? '')) return null;
    $data = json_decode($raw, true);
    if (!is_array($data) || ($data['sensor_ok'] ?? null) !== true) return null;
    $ranges = ['soilMoisture' => [0, 100], 'soilTemp' => [-40, 80], 'ph' => [0, 14],
               'ec' => [0, 200000], 'n' => [0, 1999], 'p' => [0, 1999], 'k' => [0, 1999]];
    $values = [];
    foreach ($ranges as $name => [$min, $max]) {
        $v = $data[$name] ?? null;
        if ((!is_int($v) && !is_float($v)) || !is_finite((float)$v) || $v < $min || $v > $max
            || (in_array($name, ['ec', 'n', 'p', 'k'], true) && floor($v) != $v)) return null;
        $values[] = $v;
    }
    return $values;
}

function treeco_poll_sensor(PDO $pdo): void {
    // Serialize short polls across browser tabs; no password/IP form is required.
    $lock = @fopen(sys_get_temp_dir() . '/treeco-' . hash('sha256', __DIR__) . '.lock', 'c');
    if (!$lock) throw new RuntimeException('Cannot create TREECO polling lock in PHP temporary directory.');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return; }
    try {
        $state = $pdo->query('SELECT * FROM hardware_state WHERE id = 1')->fetch();
        if ($state && time() - (int)$state['received_at'] < TREECO_POLL_SECONDS) return;
        $values = treeco_read_sensor();
        $pdo->beginTransaction();
        $readingId = null;
        if ($values !== null) {
            $stmt = $pdo->prepare('INSERT INTO sensor_logs (moisture, soil_temp, ph, ec, n, p, k) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute($values);
            $readingId = (int)$pdo->lastInsertId();
        }
        $stmt = $pdo->prepare('REPLACE INTO hardware_state (id, received_at, sensor_ok, latest_reading_id) VALUES (1, ?, ?, ?)');
        $stmt->execute([time(), $values !== null ? 1 : 0, $readingId]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
