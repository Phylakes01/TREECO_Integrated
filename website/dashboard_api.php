<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require __DIR__ . '/config.php';
require __DIR__ . '/hardware.php';
require_once __DIR__ . '/calibration_config.php';
require_once __DIR__ . '/treeco_prediction.php';

// Poll hardware sensor (protected by process lock)
treeco_poll_sensor($pdo);

// 1. Check Hardware Connection State
$hardware = $pdo->query('SELECT * FROM hardware_state WHERE id = 1')->fetch();
$stmtLatest = $pdo->query("SELECT * FROM sensor_logs ORDER BY id DESC LIMIT 1");
$latestRaw = $stmtLatest ? $stmtLatest->fetch() : null;

$connected = $hardware && (int)$hardware['sensor_ok'] === 1
    && time() - (int)$hardware['received_at'] < TREECO_OFFLINE_SECONDS
    && $latestRaw && (int)$hardware['latest_reading_id'] === (int)$latestRaw['id'];

if (!$connected) {
    echo json_encode([
        'connected' => false,
        'message' => 'Waiting for hardware connection...',
        'all_logs' => [],
        'chart' => []
    ]);
    exit;
}

// 2. Load Calibration & Realtime Telemetry
$calibConfig = treeco_load_calibration();
$thresholds = $calibConfig['thresholds'] ?? [];

// Realtime Telemetry: Use direct latest calibrated reading from sensor
$latestCalibrated = treeco_apply_calibration_to_reading($latestRaw, $calibConfig);
$latestCalibrated['id'] = $latestRaw['id'];
$latestCalibrated['recorded_at'] = $latestRaw['recorded_at'];

// Direct realtime reading for all downstream agronomic diagnostics
$reading = $latestCalibrated;

// Realtime Yield Prediction via fast Native Random Forest model (< 1ms)
$predictionResult = treeco_predict_yield(
    (float)$reading['n'],
    (float)$reading['p'],
    (float)$reading['k'],
    (float)$reading['ph'],
    (float)$reading['moisture'],
    (float)$reading['soil_temp']
);

$analysisStatus = [
    'mode' => 'Realtime Live Telemetry',
    'timestamp' => $latestRaw['recorded_at'],
    'is_realtime' => true
];

// 4. Fetch History Logs and Line Chart
$allLogsStmt = $pdo->query("SELECT * FROM sensor_logs ORDER BY id DESC LIMIT 50");
$rawLogs = $allLogsStmt ? $allLogsStmt->fetchAll() : [];
$allLogs = [];
foreach ($rawLogs as $log) {
    $allLogs[] = treeco_apply_calibration_to_reading($log, $calibConfig);
}

$chartStmt = $pdo->query("SELECT recorded_at, n, p, k, ph, moisture, soil_temp FROM sensor_logs ORDER BY id DESC LIMIT 15");
$rawChart = $chartStmt ? array_reverse($chartStmt->fetchAll()) : [];
$chartData = [];
foreach ($rawChart as $cRow) {
    $chartData[] = treeco_apply_calibration_to_reading($cRow, $calibConfig);
}

// 5. Soil Nutrient Status (3-Tier Diagnostics based on Configured Parameters)
$diag = [];
$nDef = $thresholds['n']['deficient'] ?? 100;
$nOpt = $thresholds['n']['optimal_max'] ?? 200;
if ($reading['n'] < $nDef) $diag['n'] = 'Deficient';
elseif ($reading['n'] <= $nOpt) $diag['n'] = 'Optimal';
else $diag['n'] = 'Excessive';

$pDef = $thresholds['p']['deficient'] ?? 30;
$pOpt = $thresholds['p']['optimal_max'] ?? 60;
if ($reading['p'] < $pDef) $diag['p'] = 'Deficient';
elseif ($reading['p'] <= $pOpt) $diag['p'] = 'Optimal';
else $diag['p'] = 'Excessive';

$kDef = $thresholds['k']['deficient'] ?? 150;
$kOpt = $thresholds['k']['optimal_max'] ?? 300;
if ($reading['k'] < $kDef) $diag['k'] = 'Deficient';
elseif ($reading['k'] <= $kOpt) $diag['k'] = 'Optimal';
else $diag['k'] = 'Excessive';

$phDef = $thresholds['ph']['deficient'] ?? 5.5;
$phOpt = $thresholds['ph']['optimal_max'] ?? 7.0;
if ($reading['ph'] < $phDef) $diag['ph'] = 'Deficient (Acidic)';
elseif ($reading['ph'] <= $phOpt) $diag['ph'] = 'Optimal';
else $diag['ph'] = 'Excessive (Alkaline)';

// 6. Accurate Compost Maturity Assessment Engine
$cTemp = (float)$reading['soil_temp'];
$cEc = (int)$reading['ec'];
$cPh = (float)$reading['ph'];
$cMoist = (float)$reading['moisture'];

$maxTempMature = (float)($thresholds['compost']['max_temp_mature'] ?? 35.0);
$maxEcMature = (int)($thresholds['compost']['max_ec_mature'] ?? 2500);

// Scientific Maturity Scoring (0 - 100%)
$compScore = 0;

// Temp Factor (35% weight): >45C is thermophilic (immature), 35-45C is mesophilic/curing, <=35C is ambient/mature
if ($cTemp <= $maxTempMature) $compScore += 35;
elseif ($cTemp <= 45.0) $compScore += 18;
else $compScore += 5;

// EC Factor (25% weight): <2000 uS/cm safe, 2000-3000 moderate, >3500 phytotoxic salt risk
if ($cEc <= 2000) $compScore += 25;
elseif ($cEc <= $maxEcMature) $compScore += 16;
elseif ($cEc <= 3500) $compScore += 8;
else $compScore += 2;

// pH Factor (20% weight): 6.5 - 8.0 optimal finished compost
if ($cPh >= 6.5 && $cPh <= 8.0) $compScore += 20;
elseif ($cPh >= 6.0 && $cPh <= 8.4) $compScore += 14;
else $compScore += 5;

// Moisture Factor (20% weight): 40% - 60% optimal finished texture
if ($cMoist >= 40.0 && $cMoist <= 60.0) $compScore += 20;
elseif ($cMoist >= 30.0 && $cMoist <= 70.0) $compScore += 12;
else $compScore += 4;

if ($compScore >= 80) {
    $compostStage = 'Fully Mature (Cured Compost)';
    $compostRisk = 'None — Safe for direct root contact and seedling beds.';
    $compostGuide = "Mature & Safe: 100% ready for application. Incorporate 2–3 kg per sqm as basal organic matter or potting mix.";
} elseif ($compScore >= 50) {
    $compostStage = 'Semi-Mature / Curing Stage';
    $compostRisk = 'Moderate — High microbial activity still present.';
    $compostGuide = "Curing: Allow 2–3 more weeks of curing. Can be incorporated into fallow beds 3 weeks ahead of planting, or used as surface mulch.";
} else {
    $compostStage = 'Immature (Active Decomposition)';
    $compostRisk = 'High Phytotoxicity Risk — Active heat/volatile organic acids will damage roots.';
    $compostGuide = "Immature — Do NOT apply directly to living crops. Turn compost pile regularly to maintain aeration until temperature drops below {$maxTempMature}°C.";
}

// 7. Accurate Fertilizer Effectiveness & Prescription Engine
// Soil Bioavailability & Uptake Efficiency calculation:
// pH factor: At pH 6.0-7.0, 100% bioavailability. At pH < 5.5, P is locked by Fe/Al oxides.
$phUptakeFactor = 1.0;
if ($reading['ph'] < 5.0) $phUptakeFactor = 0.45;
elseif ($reading['ph'] < 5.5) $phUptakeFactor = 0.65;
elseif ($reading['ph'] < 6.0) $phUptakeFactor = 0.85;
elseif ($reading['ph'] > 7.5) $phUptakeFactor = 0.75;

// Moisture dissolution factor: Dry soil (<40%) inhibits dissolution; waterlogged (>80%) leaches N
$moistUptakeFactor = 1.0;
if ($reading['moisture'] < 40.0) $moistUptakeFactor = 0.60;
elseif ($reading['moisture'] > 80.0) $moistUptakeFactor = 0.70;

$fertEfficiencyPct = round($phUptakeFactor * $moistUptakeFactor * 100);

// Prescriptive Fertilizer Formulation
$fertPrescriptions = [];
$limeNeeded = false;

if ($reading['ph'] < 5.8) {
    $limeNeeded = true;
    $fertPrescriptions[] = "Apply Agricultural Lime (Apog / Calcitic Limestone) at 150–200g/m² to correct soil acidity and unlock nutrient bioavailability.";
}

if ($reading['n'] < $nDef) {
    $fertPrescriptions[] = "Apply Urea (46-0-0) at 35–45g/m² or Complete (14-14-14) at 50g/m² split into basal and side-dressings.";
}
if ($reading['p'] < $pDef) {
    $fertPrescriptions[] = "Apply Solophos / Superphosphate (0-20-0) at 30g/m² to boost root branching and blooming.";
}
if ($reading['k'] < $kDef) {
    $fertPrescriptions[] = "Apply Muriate of Potash (0-0-60) at 20–25g/m² for fruit density and drought resistance.";
}

if (empty($fertPrescriptions)) {
    $fertSummary = "Nutrient levels are well balanced. No immediate chemical fertilizer required.";
} else {
    $fertSummary = implode(" ", $fertPrescriptions);
}

$fertDetails = "Uptake Efficiency: {$fertEfficiencyPct}%. " . ($limeNeeded ? "Soil acidity is currently limiting fertilizer effectiveness. Apply lime first." : "Optimal soil bioavailability for nutrient absorption.");

// 8. Philippine Cropping Calendar & Season-Aware Planting & Harvesting Guide
$monthNum = (int)date('n');
$monthName = date('F');

// Climate Seasons in the Philippines (PAGASA Agro-meteorology):
// - Wet Season (Tag-ulan): June (6) to October (10)
// - Cool Dry Season (Tag-lamig): November (11) to February (2)
// - Hot Dry Season (Tag-araw): March (3) to May (5)
if ($monthNum >= 6 && $monthNum <= 10) {
    $currentSeason = 'Wet Season (Tag-ulan)';
    $seasonType = 'Wet';
    $careInstructions = 'Maintain field drainage ditches to prevent waterlogging, soil compaction, and root fungal infections. Provide bamboo trellises for climbing vegetables.';
} elseif ($monthNum >= 11 || $monthNum <= 2) {
    $currentSeason = 'Cool Dry Season (Tag-lamig)';
    $seasonType = 'CoolDry';
    $careInstructions = 'Ideal season for lowland and highland vegetables. Apply rice straw mulch to conserve moisture and regulate nighttime soil temperature.';
} else {
    $currentSeason = 'Hot Dry Season (Tag-araw)';
    $seasonType = 'HotDry';
    $careInstructions = 'Maintain consistent drip or furrow irrigation early morning or late afternoon. Apply thick organic mulch to minimize soil evaporation.';
}

// Comprehensive Philippine Cropping Guide with both PLANTING (Itanim) and HARVESTING (Anihin)
$plantingGuide = [
    'Wet' => [
        ['name' => 'Eggplant (Talong)', 'days' => '80–100 days', 'method' => 'Transplant seedlings', 'min_ph' => 5.5, 'max_ph' => 6.8, 'min_m' => 60, 'max_m' => 80, 'min_n' => 100, 'plot' => 'Plot A (Bed 1)'],
        ['name' => 'Yardlong Bean (Sitao)', 'days' => '55–65 days', 'method' => 'Direct seeding on trellis', 'min_ph' => 5.5, 'max_ph' => 6.8, 'min_m' => 60, 'max_m' => 75, 'min_n' => 70, 'plot' => 'Plot A (Bed 2)'],
        ['name' => 'Bitter Gourd (Ampalaya)', 'days' => '60–75 days', 'method' => 'Seeding on raised trellis', 'min_ph' => 6.0, 'max_ph' => 6.8, 'min_m' => 60, 'max_m' => 75, 'min_n' => 90, 'plot' => 'Plot B (Trellis)'],
        ['name' => 'Water Spinach (Kangkong)', 'days' => '25–35 days', 'method' => 'Direct sow / cuttings', 'min_ph' => 5.0, 'max_ph' => 7.0, 'min_m' => 70, 'max_m' => 85, 'min_n' => 100, 'plot' => 'Plot B (Lowland)'],
        ['name' => 'Okra', 'days' => '50–60 days', 'method' => 'Direct seeding', 'min_ph' => 6.0, 'max_ph' => 6.8, 'min_m' => 60, 'max_m' => 75, 'min_n' => 80, 'plot' => 'Plot C (Open Bed)'],
        ['name' => 'Squash (Kalabasa)', 'days' => '90–110 days', 'method' => 'Direct seeding on mounds', 'min_ph' => 5.5, 'max_ph' => 6.8, 'min_m' => 60, 'max_m' => 75, 'min_n' => 95, 'plot' => 'Plot C (Mound)']
    ],
    'CoolDry' => [
        ['name' => 'Tomato (Kamatis)', 'days' => '70–85 days', 'method' => 'Transplant seedlings', 'min_ph' => 6.0, 'max_ph' => 7.0, 'min_m' => 45, 'max_m' => 65, 'min_n' => 110, 'plot' => 'Plot A (Bed 1)'],
        ['name' => 'Pechay (Brassica)', 'days' => '30–40 days', 'method' => 'Transplant / sow', 'min_ph' => 5.5, 'max_ph' => 6.8, 'min_m' => 50, 'max_m' => 65, 'min_n' => 90, 'plot' => 'Plot A (Bed 2)'],
        ['name' => 'Cabbage (Repolyo)', 'days' => '75–90 days', 'method' => 'Transplant seedlings', 'min_ph' => 6.0, 'max_ph' => 6.8, 'min_m' => 50, 'max_m' => 65, 'min_n' => 120, 'plot' => 'Plot B (Raised Bed)'],
        ['name' => 'Bell Pepper (Lara)', 'days' => '75–90 days', 'method' => 'Transplant seedlings', 'min_ph' => 6.0, 'max_ph' => 6.8, 'min_m' => 50, 'max_m' => 65, 'min_n' => 100, 'plot' => 'Plot B (Sheltered)'],
        ['name' => 'Lettuce', 'days' => '35–45 days', 'method' => 'Transplant seedlings', 'min_ph' => 6.0, 'max_ph' => 7.0, 'min_m' => 50, 'max_m' => 65, 'min_n' => 85, 'plot' => 'Plot C (Shaded Bed)'],
        ['name' => 'Radish (Labanos)', 'days' => '45–55 days', 'method' => 'Direct seeding in loose soil', 'min_ph' => 6.0, 'max_ph' => 6.8, 'min_m' => 45, 'max_m' => 60, 'min_n' => 75, 'plot' => 'Plot C (Root Bed)']
    ],
    'HotDry' => [
        ['name' => 'Mungbean (Mongo)', 'days' => '60–70 days', 'method' => 'Direct sow (drought tolerant)', 'min_ph' => 5.5, 'max_ph' => 7.0, 'min_m' => 35, 'max_m' => 55, 'min_n' => 60, 'plot' => 'Plot A (Open Field)'],
        ['name' => 'Watermelon (Pakwan)', 'days' => '75–85 days', 'method' => 'Direct seeding on hills', 'min_ph' => 5.8, 'max_ph' => 6.8, 'min_m' => 40, 'max_m' => 60, 'min_n' => 100, 'plot' => 'Plot B (Sandy Loam)'],
        ['name' => 'Peanut (Mani)', 'days' => '90–110 days', 'method' => 'Direct seeding in loose beds', 'min_ph' => 5.5, 'max_ph' => 6.8, 'min_m' => 40, 'max_m' => 55, 'min_n' => 50, 'plot' => 'Plot B (Dry Bed)'],
        ['name' => 'Sweet Potato (Kamote)', 'days' => '90–120 days', 'method' => 'Vine cuttings on ridges', 'min_ph' => 5.5, 'max_ph' => 6.5, 'min_m' => 40, 'max_m' => 60, 'min_n' => 70, 'plot' => 'Plot C (Mounds)'],
        ['name' => 'Eggplant (Talong)', 'days' => '85–105 days', 'method' => 'Transplant with mulch', 'min_ph' => 5.5, 'max_ph' => 6.8, 'min_m' => 45, 'max_m' => 60, 'min_n' => 110, 'plot' => 'Plot C (Mulched Bed)']
    ]
];

$harvestingGuide = [
    'Wet' => [
        ['name' => 'Rice (Wet Season Palay)', 'indicators' => '85–90% of grains in panicles have turned golden yellow. Harvest during dry window.'],
        ['name' => 'Water Spinach (Kangkong)', 'indicators' => 'Stems are 20–25cm long, crisp, succulent. Cut 5cm from base to allow regrowth.'],
        ['name' => 'Early Sitao & Okra', 'indicators' => 'Pods are tender, pliable, tips snap easily. Harvest every 2–3 days to encourage continuous blooming.'],
        ['name' => 'Kalabasa (Squash)', 'indicators' => 'Rind is hard and deep tan/orange, stem dries and turns corky.']
    ],
    'CoolDry' => [
        ['name' => 'Pechay & Lettuce', 'indicators' => 'Heads and outer leaves are full, crisp, and dense before flower stalks begin to elongate.'],
        ['name' => 'Tomato (Kamatis)', 'indicators' => 'Breaker stage to light red. Pick early morning to preserve fruit firmness.'],
        ['name' => 'Radish (Labanos)', 'indicators' => 'Roots reach 2.5–3.5cm diameter and feel solid, not spongy.'],
        ['name' => 'Ampalaya (Bitter Gourd)', 'indicators' => 'Ridges are rounded, fruit color is light green, before yellowing at tips.']
    ],
    'HotDry' => [
        ['name' => 'Mungbean (Mongo)', 'indicators' => '80% of pods turn black or dark brown and brittle. Pick in morning to avoid pod shattering.'],
        ['name' => 'Watermelon (Pakwan)', 'indicators' => 'Tendril closest to fruit dries brown, underside ground-spot turns creamy yellow.'],
        ['name' => 'Peanut (Mani)', 'indicators' => 'Foliage turns yellowish, inner pod shells show dark brown veining.'],
        ['name' => 'Sweet Potato (Kamote)', 'indicators' => 'Leaves turn yellow and dry; tuber roots are firm and swollen.']
    ]
];

$activePlanting = $plantingGuide[$seasonType] ?? $plantingGuide['Wet'];
$activeHarvesting = $harvestingGuide[$seasonType] ?? $harvestingGuide['Wet'];

$matchedPlots = [];
$plantCropsList = [];

foreach ($activePlanting as $crop) {
    $plantCropsList[] = $crop['name'];

    // Compatibility check with current realtime soil metrics
    $phOk = ($reading['ph'] >= $crop['min_ph'] && $reading['ph'] <= $crop['max_ph']);
    $mOk = ($reading['moisture'] >= $crop['min_m'] && $reading['moisture'] <= $crop['max_m']);
    $nOk = ($reading['n'] >= $crop['min_n']);

    $matchScore = 0;
    if ($phOk) $matchScore += 40;
    if ($mOk) $matchScore += 40;
    if ($nOk) $matchScore += 20;

    $matchedPlots[] = [
        'crop' => $crop['name'],
        'plot' => $crop['plot'],
        'days' => $crop['days'],
        'method' => $crop['method'],
        'match_score' => $matchScore,
        'status' => ($matchScore >= 80)
            ? "Optimal Match ({$matchScore}%) — pH {$reading['ph']}, Moisture {$reading['moisture']}%"
            : "Requires Adjustment ({$matchScore}%) — Target: pH {$crop['min_ph']}-{$crop['max_ph']}, Moisture {$crop['min_m']}-{$crop['max_m']}%"
    ];
}

// 9. Output Combined JSON Response
echo json_encode([
    'connected' => true,
    'telemetry' => $reading,             // Realtime live sensor reading
    'telemetry_raw' => $latestCalibrated, // Instantaneous reading
    'analysis_status' => $analysisStatus, // Realtime analysis status
    'chart' => $chartData,
    'all_logs' => $allLogs,
    'diagnostics' => $diag,
    'compost_maturity' => [
        'stage' => $compostStage,
        'score_pct' => $compScore,
        'risk' => $compostRisk,
        'guide' => $compostGuide,
        'factors' => [
            'temp' => ['val' => $cTemp, 'status' => $cTemp <= $maxTempMature ? 'Mature / Stabilized' : ($cTemp <= 45 ? 'Curing' : 'Thermophilic / Immature')],
            'ec' => ['val' => $cEc, 'status' => $cEc <= 2000 ? 'Optimal Salinity' : ($cEc <= $maxEcMature ? 'Moderate' : 'Phytotoxic Risk')],
            'ph' => ['val' => $cPh, 'status' => ($cPh >= 6.5 && $cPh <= 8.0) ? 'Optimal Neutral' : 'Sub-optimal'],
            'moisture' => ['val' => $cMoist, 'status' => ($cMoist >= 40 && $cMoist <= 60) ? 'Optimal Humus Texture' : 'Check Moisture']
        ]
    ],
    'fertilizer_effectiveness' => [
        'efficiency_pct' => $fertEfficiencyPct,
        'summary' => $fertSummary,
        'details' => $fertDetails,
        'lime_needed' => $limeNeeded
    ],
    'prescriptions' => [
        'fertilizer' => $fertSummary,
        'compost' => $compostGuide
    ],
    'seasonality' => [
        'month' => $monthName,
        'season' => $currentSeason,
        'recommended_crops' => implode(', ', $plantCropsList),
        'care_instructions' => $careInstructions,
        'crops_to_plant' => $activePlanting,
        'crops_to_harvest' => $activeHarvesting
    ],
    'recommended_plots' => $matchedPlots,
    'prediction' => $predictionResult,
    'prediction_kg' => $predictionResult['prediction_kg'],
    'potential_kg' => $predictionResult['potential_kg'],
    'limiting_factors' => $predictionResult['limiting_factors']
]);
