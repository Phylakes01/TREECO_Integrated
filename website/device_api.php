<?php
header('Content-Type: application/json'); header('Cache-Control: no-store');
require __DIR__ . '/device_config.php';
function device_reply(int $status, array $body): void { http_response_code($status); echo json_encode($body); exit; }
session_name('treeco_device');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf']; session_write_close();
$method = $_SERVER['REQUEST_METHOD']; $payload = null;
if ($method === 'POST') {
    if (!hash_equals($csrf, $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) device_reply(403, ['ok' => false, 'message' => 'Refresh the page and try again.']);
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') device_reply(415, ['ok' => false, 'message' => 'JSON required.']);
    $raw = file_get_contents('php://input', false, null, 0, 2049);
    if (strlen($raw) > 2048) device_reply(413, ['ok' => false, 'message' => 'Request too large.']);
    $payload = json_decode($raw, true);
    if (!is_array($payload) || array_diff(array_keys($payload), ['old_password','new_password','confirm_password'])) device_reply(422, ['ok' => false, 'message' => 'Only the password can be changed. SSID is read-only.']);
    foreach (['old_password','new_password','confirm_password'] as $field) {
        if (!isset($payload[$field]) || !is_string($payload[$field]) || !preg_match('/\A[\x20-\x7E]{8,63}\z/', $payload[$field])) device_reply(422, ['ok' => false, 'message' => 'Passwords must contain 8–63 printable ASCII characters.']);
    }
    if ($payload['new_password'] !== $payload['confirm_password']) device_reply(422, ['ok' => false, 'message' => 'New Password and Confirm Password do not match.']);
    if ($payload['new_password'] === $payload['old_password']) device_reply(422, ['ok' => false, 'message' => 'Choose a different new password.']);
} elseif ($method !== 'GET') device_reply(405, ['ok' => false, 'message' => 'Use GET or POST.']);
// Configured ESP32 origin only; the browser cannot supply an alternate host or SSID.
$parts = parse_url(TREECO_SENSOR_URL);
$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
$options = ['method' => $method, 'timeout' => 3, 'follow_location' => 0, 'ignore_errors' => true,
    'header' => "Accept: application/json\r\nConnection: close\r\n"];
if ($payload !== null) { $options['header'] .= "Content-Type: application/json\r\n"; $options['content'] = json_encode($payload); }
$body = @file_get_contents($origin . ($method === 'GET' ? '/status' : '/password'), false, stream_context_create(['http' => $options]), 0, 4097);
$code = 0; if (preg_match('/^HTTP\/\S+ (\d{3})\b/', $http_response_header[0] ?? '', $match)) $code = (int)$match[1];
$data = $body !== false && strlen($body) <= 4096 ? json_decode($body, true) : null;
if (!is_array($data)) device_reply(503, ['ok' => false, 'message' => $method === 'POST'
    ? 'No confirmation received. The password may have changed; try reconnecting with the new password before retrying.'
    : 'ESP32 unreachable. Connect this computer to its hotspot.']);
if ($method === 'GET' && $code === 200 && isset($data['ap_ssid'])) {
    device_reply(200, ['ok' => true, 'ssid' => (string)$data['ap_ssid'], 'can_change_password' => ($data['password_change_supported'] ?? false) === true, 'csrf' => $csrf]);
}
if ($method === 'POST' && $code === 202 && ($data['ok'] ?? false) === true) {
    device_reply(202, ['ok' => true, 'message' => 'Password saved. Reconnect to the same ESP32 Wi-Fi using your new password, then refresh this page.']);
}
$messages = [401 => 'Old Password is incorrect.', 404 => 'Upload the updated TREECO.ino first.',
    409 => 'A password change is already in progress.', 422 => 'Invalid password fields. SSID cannot be changed.',
    429 => 'Too many incorrect attempts. Wait one minute.', 500 => 'ESP32 could not save the password.'];
device_reply(isset($messages[$code]) ? $code : 502, ['ok' => false, 'message' => $messages[$code] ?? 'Unexpected device response. Please reconnect and check the ESP32.']);
