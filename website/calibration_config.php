<?php
// TREECO Sensor Calibration & Analysis Parameters

const TREECO_CALIBRATION_FILE = __DIR__ . '/calibration.json';

// Default calibration parameters: scale (multiplier) and offset (shift)
// Formula: Calibrated_Value = (Raw_Value * scale) + offset
function treeco_get_default_calibration(): array {
    return [
        'mode' => 'realtime',
        'sensors' => [
            'moisture' => ['scale' => 1.0, 'offset' => 0.0, 'unit' => '%', 'min' => 0.0, 'max' => 100.0, 'name' => 'Soil Moisture'],
            'soil_temp' => ['scale' => 1.0, 'offset' => 0.0, 'unit' => '°C', 'min' => -40.0, 'max' => 80.0, 'name' => 'Soil Temperature'],
            'ph' => ['scale' => 1.0, 'offset' => 0.0, 'unit' => 'pH', 'min' => 0.0, 'max' => 14.0, 'name' => 'Soil pH'],
            'ec' => ['scale' => 1.0, 'offset' => 0.0, 'unit' => 'µS/cm', 'min' => 0, 'max' => 20000, 'name' => 'Electrical Conductivity'],
            'n' => ['scale' => 1.0, 'offset' => 0.0, 'unit' => 'mg/kg', 'min' => 0, 'max' => 1999, 'name' => 'Nitrogen'],
            'p' => ['scale' => 1.0, 'offset' => 0.0, 'unit' => 'mg/kg', 'min' => 0, 'max' => 1999, 'name' => 'Phosphorus'],
            'k' => ['scale' => 1.0, 'offset' => 0.0, 'unit' => 'mg/kg', 'min' => 0, 'max' => 1999, 'name' => 'Potassium']
        ],
        'thresholds' => [
            'n' => ['deficient' => 100, 'optimal_max' => 200],
            'p' => ['deficient' => 30, 'optimal_max' => 60],
            'k' => ['deficient' => 150, 'optimal_max' => 300],
            'ph' => ['deficient' => 5.5, 'optimal_max' => 7.0],
            'moisture' => ['dry' => 45, 'optimal_min' => 50, 'optimal_max' => 75],
            'compost' => [
                'max_temp_mature' => 35.0, // °C - above this is active thermophilic breakdown
                'max_ec_mature' => 2500,   // µS/cm - salinity threshold for sensitive roots
                'optimal_ph_min' => 6.5,
                'optimal_ph_max' => 8.2,
                'optimal_moisture_min' => 40.0,
                'optimal_moisture_max' => 60.0
            ]
        ],
        'last_updated' => date('Y-m-d H:i:s')
    ];
}

function treeco_load_calibration(): array {
    $defaults = treeco_get_default_calibration();
    if (!is_file(TREECO_CALIBRATION_FILE)) {
        return $defaults;
    }
    $raw = @file_get_contents(TREECO_CALIBRATION_FILE);
    if ($raw === false) return $defaults;
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['sensors'])) {
        return $defaults;
    }
    return array_replace_recursive($defaults, $data);
}

function treeco_save_calibration(array $config): bool {
    return (bool)@file_put_contents(
        TREECO_CALIBRATION_FILE,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
}

function treeco_apply_calibration_to_reading(array $reading, ?array $calib = null): array {
    if ($calib === null) {
        $calib = treeco_load_calibration();
    }
    $sensors = $calib['sensors'] ?? [];
    $calibrated = $reading;

    foreach (['moisture', 'soil_temp', 'ph', 'ec', 'n', 'p', 'k'] as $key) {
        if (!isset($reading[$key])) continue;
        $val = (float)$reading[$key];
        $scale = (float)($sensors[$key]['scale'] ?? 1.0);
        $offset = (float)($sensors[$key]['offset'] ?? 0.0);
        $min = (float)($sensors[$key]['min'] ?? 0.0);
        $max = (float)($sensors[$key]['max'] ?? 9999.0);

        $adjusted = ($val * $scale) + $offset;
        $adjusted = max($min, min($max, $adjusted));

        if (in_array($key, ['ec', 'n', 'p', 'k'], true)) {
            $calibrated[$key] = (int)round($adjusted);
        } else {
            $calibrated[$key] = round($adjusted, 1);
        }
    }

    return $calibrated;
}
