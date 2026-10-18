<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/calibration_config.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $calib = treeco_load_calibration();
    echo json_encode([
        'ok' => true,
        'calibration' => $calib
    ]);
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid JSON input.']);
        exit;
    }

    if (($input['action'] ?? '') === 'reset') {
        $defaults = treeco_get_default_calibration();
        treeco_save_calibration($defaults);
        echo json_encode([
            'ok' => true,
            'message' => 'Calibration factors and parameters successfully reset to factory defaults.',
            'calibration' => $defaults
        ]);
        exit;
    }

    $current = treeco_load_calibration();

    if (isset($input['sensors']) && is_array($input['sensors'])) {
        foreach ($input['sensors'] as $sensor => $params) {
            if (isset($current['sensors'][$sensor])) {
                if (isset($params['scale']) && is_numeric($params['scale'])) {
                    $current['sensors'][$sensor]['scale'] = (float)$params['scale'];
                }
                if (isset($params['offset']) && is_numeric($params['offset'])) {
                    $current['sensors'][$sensor]['offset'] = (float)$params['offset'];
                }
            }
        }
    }

    if (isset($input['thresholds']) && is_array($input['thresholds'])) {
        $current['thresholds'] = array_replace_recursive($current['thresholds'], $input['thresholds']);
    }

    $current['last_updated'] = date('Y-m-d H:i:s');
    $saved = treeco_save_calibration($current);

    if ($saved) {
        echo json_encode([
            'ok' => true,
            'message' => 'Calibration and parameters successfully saved.',
            'calibration' => $current
        ]);
    } else {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Failed to write calibration settings.']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
