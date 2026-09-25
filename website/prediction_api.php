<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require __DIR__ . '/config.php';
require __DIR__ . '/device_config.php';
function prediction_reply(array $body, int $status = 200): void {
    http_response_code($status); echo json_encode($body); exit;
}
function prediction_error(string $code, string $message): array {
    return ['ok' => false, 'code' => $code, 'message' => $message, 'prediction_kg' => null];
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') prediction_reply(prediction_error('method', 'Use GET.'), 405);
$state = $pdo->query('SELECT * FROM hardware_state WHERE id = 1')->fetch();
if (!$state || !(int)$state['sensor_ok'] || time() - (int)$state['received_at'] >= TREECO_OFFLINE_SECONDS) {
    prediction_reply(prediction_error('offline', 'Waiting for live sensor readings.'), 409);
}
$stmt = $pdo->prepare('SELECT * FROM sensor_logs WHERE id = ?');
$stmt->execute([$state['latest_reading_id']]); $sample = $stmt->fetch();
if (!$sample) prediction_reply(prediction_error('no_sample', 'Waiting for live sensor readings.'), 409);
$input = [];
foreach (['n', 'p', 'k', 'ph', 'moisture', 'soil_temp'] as $field) {
    if (!isset($sample[$field]) || !is_numeric($sample[$field]) || !is_finite((float)$sample[$field])) {
        prediction_reply(prediction_error('invalid_sample', 'Sensor values are invalid.'), 422);
    }
    $input[] = (string)(float)$sample[$field];
}
$script = __DIR__ . '/predict.py'; $model = __DIR__ . '/treeco_rf_model.pkl';
if (!is_file($script) || !is_file($model)) {
    prediction_reply(prediction_error('model_missing', 'Missing predict.py or treeco_rf_model.pkl in the website folder.'), 503);
}
if (!function_exists('proc_open')) {
    prediction_reply(prediction_error('process_disabled', 'PHP proc_open is disabled. Enable it in php.ini, then restart Apache.'), 503);
}
$base = sys_get_temp_dir() . '/treeco-prediction-' . hash('sha256', __DIR__ . TREECO_PYTHON);
$signature = hash('sha256', json_encode($input) . filemtime($script) . filemtime($model));
$lock = @fopen($base . '.lock', 'c');
if (!$lock) prediction_reply(prediction_error('cache_unwritable', 'PHP temporary folder is not writable.'), 503);
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock); prediction_reply(prediction_error('busy', 'Calculating yield prediction…'), 202);
}
$result = null;
try {
    $cached = is_file($base . '.json') ? json_decode((string)@file_get_contents($base . '.json'), true) : null;
    if (is_array($cached) && time() - ($cached['at'] ?? 0) < 20 && ($cached['signature'] ?? '') === $signature) {
        $result = $cached['result'];
    } else {
        // An absolute TREECO_PYTHON path takes precedence; no shell interpolation.
        $configured = trim(TREECO_PYTHON);
        $commands = $configured !== '' ? [[$configured]] : [];
        if (PHP_OS_FAMILY === 'Windows') $commands[] = ['py', '-3'];
        $commands[] = ['python']; $commands[] = ['python3'];
        $commands = array_values(array_unique($commands, SORT_REGULAR));
        $result = prediction_error('python_missing', 'Python could not run. Install Python or set its full executable path in device_config.php, then restart Apache.');
        $deadline = microtime(true) + 15;
        foreach ($commands as $command) {
            if (microtime(true) >= $deadline) break;
            $out = tempnam(sys_get_temp_dir(), 'treeco-out-');
            $err = tempnam(sys_get_temp_dir(), 'treeco-err-');
            if ($out === false || $err === false) {
                if ($out !== false) @unlink($out); if ($err !== false) @unlink($err);
                $result = prediction_error('temp_unwritable', 'PHP temporary folder is not writable.'); break;
            }
            $process = null; $stdout = ''; $stderr = ''; $timedOut = false; $exitCode = -1;
            try {
                // File descriptors avoid blocking Windows pipe reads.
                $process = @proc_open(array_merge($command, [$script], $input),
                    [0 => ['pipe', 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
                    $pipes, __DIR__, null, ['bypass_shell' => true, 'suppress_errors' => true]);
                if (!is_resource($process)) continue;
                fclose($pipes[0]);
                do {
                    $status = proc_get_status($process);
                    if (!$status['running']) { $exitCode = $status['exitcode']; break; }
                    clearstatcache(true, $out); clearstatcache(true, $err);
                    if (microtime(true) >= $deadline || filesize($out) > 65536 || filesize($err) > 65536) {
                        $timedOut = true; proc_terminate($process, 9); break;
                    }
                    usleep(50000);
                } while (true);
                proc_close($process); $process = null;
                $stdout = trim((string)file_get_contents($out, false, null, 0, 65536));
                $stderr = (string)file_get_contents($err, false, null, 0, 65536);
            } finally {
                if (is_resource($process)) { proc_terminate($process, 9); proc_close($process); }
                @unlink($out); @unlink($err);
            }
            if ($timedOut) { $result = prediction_error('timeout', 'Prediction timed out. Check Python and the model on this computer.'); break; }
            if ($exitCode === 0 && is_numeric($stdout) && is_finite((float)$stdout) && (float)$stdout >= 0) {
                $result = ['ok' => true, 'prediction_kg' => round((float)$stdout, 2), 'sample_id' => (int)$sample['id'], 'calculated_at' => time(), 'message' => 'Prediction updated.'];
                break;
            }
            $details = $stdout . "\n" . $stderr;
            if (stripos($details, 'No module named') !== false || stripos($details, 'ModuleNotFoundError') !== false) {
                $result = prediction_error('dependencies', 'Python packages are missing. Install scikit-learn, pandas, numpy and joblib in the Python used by Apache.');
            } elseif (stripos($details, 'Error:') !== false || stripos($details, 'Traceback') !== false) {
                $result = prediction_error('model_error', 'The model could not load or predict. Run predict.py in the website folder; retrain with train_model.py if the scikit-learn version is incompatible.');
            }
            // Try another installed Python: it may contain the required packages.
        }
        @file_put_contents($base . '.json', json_encode(['at' => time(), 'signature' => $signature, 'result' => $result]), LOCK_EX);
    }
} finally { flock($lock, LOCK_UN); fclose($lock); }
prediction_reply($result, $result['ok'] ? 200 : 503);
