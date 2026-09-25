<?php
header('Content-Type: application/json');
require __DIR__ . '/config.php';
require __DIR__ . '/hardware.php';
header('Cache-Control: no-store');
treeco_poll_sensor($pdo);

// 1. Fetch Latest Telemetry Logged by Hardware
$stmt = $pdo->query("SELECT * FROM sensor_logs ORDER BY id DESC LIMIT 1");
$latest = $stmt->fetch();

// Fetch Logs for History Table and Line Chart
$allLogsStmt = $pdo->query("SELECT * FROM sensor_logs ORDER BY id DESC LIMIT 50");
$allLogs = $allLogsStmt ? $allLogsStmt->fetchAll() : [];

$chartStmt = $pdo->query("SELECT recorded_at, n, p, k, ph, moisture, soil_temp FROM sensor_logs ORDER BY id DESC LIMIT 10");$chartData = $chartStmt ? array_reverse($chartStmt->fetchAll()) : [];

$hardware = $pdo->query('SELECT * FROM hardware_state WHERE id = 1')->fetch();
$connected = $hardware && (int)$hardware['sensor_ok'] === 1
    && time() - (int)$hardware['received_at'] < TREECO_OFFLINE_SECONDS
    && $latest && (int)$hardware['latest_reading_id'] === (int)$latest['id'];
if (!$connected) {
    echo json_encode([
        'connected' => false,
        'message' => 'Waiting for hardware connection...',
        'all_logs' => []
    ]);
    exit;
}

// 2. 3-Tier Diagnostics Logic
$status = [];
$urea = 0;

if ($latest['n'] < 100) {
    $status['n'] = 'Deficient';
    $urea += 50;
} elseif ($latest['n'] <= 200) {
    $status['n'] = 'Optimal';
} else {
    $status['n'] = 'Excessive';
}

if ($latest['p'] < 30) {
    $status['p'] = 'Deficient';
} elseif ($latest['p'] <= 60) {
    $status['p'] = 'Optimal';
} else {
    $status['p'] = 'Excessive';
}

if ($latest['k'] < 150) {
    $status['k'] = 'Deficient';
} elseif ($latest['k'] <= 300) {
    $status['k'] = 'Optimal';
} else {
    $status['k'] = 'Excessive';
}

if ($latest['ph'] < 5.5) {
    $status['ph'] = 'Deficient';
} elseif ($latest['ph'] <= 7.0) {
    $status['ph'] = 'Optimal';
} else {
    $status['ph'] = 'Excessive';
}

$fertilizer = $urea > 0 ? "Apply {$urea}g of Urea 46-0-0 per sqm." : "No chemical fertilizer needed.";
$compost = (isset($latest['ec']) && $latest['ec'] > 2000 && $latest['soil_temp'] > 35)
    ? "Immature - Do not apply"
    : "Mature - Safe to apply";

// 3. Multi-Crop & Seasonal Plot Allocation Engine
$month = (int)date('n');
$isWet = ($month >= 5 && $month <= 10);
$season = $isWet ? 'Wet Season' : 'Dry Season';

$allCrops = [
    // Wet Season Crop Suite
    ['name' => 'Eggplant (Talong)', 'season' => 'Wet', 'min_ph' => 5.5, 'max_ph' => 6.8, 'min_m' => 60, 'max_m' => 80, 'min_n' => 100, 'plot' => 'Plot A (Bed 1)'],
    ['name' => 'Sitao (Yardlong Bean)', 'season' => 'Wet', 'min_ph' => 5.5, 'max_ph' => 6.5, 'min_m' => 60, 'max_m' => 75, 'min_n' => 70, 'plot' => 'Plot A (Bed 2)'],
    ['name' => 'Ampalaya (Bitter Gourd)', 'season' => 'Wet', 'min_ph' => 6.0, 'max_ph' => 6.8, 'min_m' => 60, 'max_m' => 75, 'min_n' => 90, 'plot' => 'Plot B (Trellis)'],
    ['name' => 'Kangkong (Water Spinach)', 'season' => 'Wet', 'min_ph' => 5.0, 'max_ph' => 7.0, 'min_m' => 70, 'max_m' => 85, 'min_n' => 110, 'plot' => 'Plot B (Lowland)'],
    ['name' => 'Okra', 'season' => 'Wet', 'min_ph' => 6.0, 'max_ph' => 6.8, 'min_m' => 60, 'max_m' => 75, 'min_n' => 85, 'plot' => 'Plot C (Open Bed)'],
    ['name' => 'Kalabasa (Squash)', 'season' => 'Wet', 'min_ph' => 5.5, 'max_ph' => 6.8, 'min_m' => 60, 'max_m' => 75, 'min_n' => 100, 'plot' => 'Plot C (Mound)'],

    // Dry Season Crop Suite
    ['name' => 'Tomato (Kamatis)', 'season' => 'Dry', 'min_ph' => 6.0, 'max_ph' => 7.0, 'min_m' => 40, 'max_m' => 60, 'min_n' => 120, 'plot' => 'Plot A (Bed 1)'],
    ['name' => 'Pechay', 'season' => 'Dry', 'min_ph' => 5.5, 'max_ph' => 6.5, 'min_m' => 50, 'max_m' => 65, 'min_n' => 100, 'plot' => 'Plot A (Bed 2)'],
    ['name' => 'Cabbage (Repolyo)', 'season' => 'Dry', 'min_ph' => 6.0, 'max_ph' => 6.8, 'min_m' => 50, 'max_m' => 65, 'min_n' => 130, 'plot' => 'Plot B (Raised Bed)'],
    ['name' => 'Bell Pepper (Lara)', 'season' => 'Dry', 'min_ph' => 6.0, 'max_ph' => 6.8, 'min_m' => 50, 'max_m' => 65, 'min_n' => 110, 'plot' => 'Plot B (Sheltered)'],
    ['name' => 'Lettuce', 'season' => 'Dry', 'min_ph' => 6.0, 'max_ph' => 7.0, 'min_m' => 50, 'max_m' => 65, 'min_n' => 90, 'plot' => 'Plot C (Shaded Bed)'],
    ['name' => 'Labanos (Radish)', 'season' => 'Dry', 'min_ph' => 6.0, 'max_ph' => 6.8, 'min_m' => 45, 'max_m' => 60, 'min_n' => 75, 'plot' => 'Plot C (Root Bed)']
];

$matchedPlots = [];
$recommendedCropsList = [];

foreach ($allCrops as $crop) {
    // Check if crop matches season and live telemetry limits
    if ($crop['season'] === ($isWet ? 'Wet' : 'Dry')) {
        $recommendedCropsList[] = $crop['name'];

        if (
            $latest['ph'] >= $crop['min_ph'] &&
            $latest['ph'] <= $crop['max_ph'] &&
            $latest['moisture'] >= $crop['min_m'] &&
            $latest['moisture'] <= $crop['max_m'] &&
            $latest['n'] >= $crop['min_n']
        ) {
            $matchedPlots[] = [
                'crop' => $crop['name'],
                'plot' => $crop['plot'],
                'status' => 'Optimal pH (' . $latest['ph'] . ') & Moisture (' . $latest['moisture'] . '%) Match'
            ];
        }
    }
}

if (empty($matchedPlots)) {
    $matchedPlots[] = [
        'crop' => 'Soil Adjustment Required',
        'plot' => 'All Plots',
        'status' => 'Soil metrics outside target ranges for current season crops.'
    ];
}

$careInstructions = $isWet
    ? 'Ensure field drainage channels are open to prevent waterlogging and root rot.'
    : 'Maintain drip irrigation and apply organic mulch to retain soil moisture.';

// Prediction runs separately so a Python error cannot delay live readings.

// 5. Output Combined Payload
echo json_encode([
    'connected' => true,
    'telemetry' => $latest,
    'chart' => $chartData,
    'all_logs' => $allLogs,
    'diagnostics' => $status,
    'prescriptions' => [
        'fertilizer' => $fertilizer,
        'compost' => $compost
    ],
    'seasonality' => [
        'season' => $season,
        'recommended_crops' => implode(', ', $recommendedCropsList),
        'care_instructions' => $careInstructions
    ],
    'recommended_plots' => $matchedPlots,
    'prediction_kg' => null
]);
