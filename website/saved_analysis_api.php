<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');
require __DIR__ . '/config.php';

// Auto-create saved_analyses table if not yet present in MySQL
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS saved_analyses (
        id INT AUTO_INCREMENT PRIMARY KEY,
        yield_kg VARCHAR(32) NOT NULL,
        potential_kg VARCHAR(32) NOT NULL,
        n FLOAT NOT NULL,
        p FLOAT NOT NULL,
        k FLOAT NOT NULL,
        ph FLOAT NOT NULL,
        moisture FLOAT NOT NULL,
        soil_temp FLOAT NOT NULL,
        ec INT NOT NULL,
        n_status VARCHAR(64) NULL,
        p_status VARCHAR(64) NULL,
        k_status VARCHAR(64) NULL,
        ph_status VARCHAR(64) NULL,
        compost_stage VARCHAR(128) NULL,
        compost_score INT NULL,
        fert_efficiency INT NULL,
        fertilizer_prescription TEXT NULL,
        compost_guide TEXT NULL,
        limiting_factors TEXT NULL,
        season_name VARCHAR(64) NULL,
        season_month VARCHAR(64) NULL,
        analysis_data LONGTEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// 1. GET: Fetch all saved analyses from database
if ($method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT * FROM saved_analyses ORDER BY id DESC LIMIT 100");
        $rows = $stmt->fetchAll();
        $analyses = [];

        foreach ($rows as $row) {
            $data = json_decode($row['analysis_data'], true);
            if (!is_array($data)) $data = [];

            // Ensure top-level fields are synchronized with DB row
            $data['id'] = (int)$row['id'];
            $data['saved_at'] = date('D, M j, Y, h:i A', strtotime($row['created_at']));
            $data['created_at'] = $row['created_at'];
            $data['yield_kg'] = $row['yield_kg'];
            $data['potential_kg'] = $row['potential_kg'];

            $analyses[] = $data;
        }

        echo json_encode(['ok' => true, 'analyses' => $analyses]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Failed to load saved analyses: ' . $e->getMessage()]);
    }
    exit;
}

// 2. POST actions: save, delete, clear
if ($method === 'POST') {
    $rawInput = file_get_contents('php://input');
    $payload = json_decode($rawInput, true);

    // Check if delete action
    if ($action === 'delete' || ($payload['action'] ?? '') === 'delete') {
        $id = (int)($_GET['id'] ?? ($payload['id'] ?? 0));
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Invalid ID specified for deletion.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM saved_analyses WHERE id = ?");
            $stmt->execute([$id]);
            echo json_encode(['ok' => true, 'message' => 'Analysis successfully deleted from database.']);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Failed to delete record: ' . $e->getMessage()]);
        }
        exit;
    }

    // Check if clear all action
    if ($action === 'clear' || ($payload['action'] ?? '') === 'clear') {
        try {
            $pdo->exec("DELETE FROM saved_analyses");
            echo json_encode(['ok' => true, 'message' => 'All saved analyses deleted from database.']);
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Failed to clear records: ' . $e->getMessage()]);
        }
        exit;
    }

    // Default POST: Save new analysis to database
    if (!is_array($payload)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid JSON payload received.']);
        exit;
    }

    try {
        $yieldVal = (string)($payload['yield_kg'] ?? '—');
        $potVal = (string)($payload['potential_kg'] ?? '—');
        $t = $payload['telemetry'] ?? [];

        $n = (float)($t['n'] ?? 0);
        $p = (float)($t['p'] ?? 0);
        $k = (float)($t['k'] ?? 0);
        $ph = (float)($t['ph'] ?? 0);
        $m = (float)($t['moisture'] ?? 0);
        $temp = (float)($t['soil_temp'] ?? 0);
        $ec = (int)($t['ec'] ?? 0);

        $seasonName = (string)($payload['seasonality']['season'] ?? '');
        $seasonMonth = (string)($payload['seasonality']['month'] ?? '');

        // Extract detailed report fields for direct phpMyAdmin browsing
        $diag = $payload['diagnostics'] ?? [];
        $nStatus = (string)($diag['n'] ?? '');
        $pStatus = (string)($diag['p'] ?? '');
        $kStatus = (string)($diag['k'] ?? '');
        $phStatus = (string)($diag['ph'] ?? '');

        $cm = $payload['compost_maturity'] ?? [];
        $compostStage = (string)($cm['stage'] ?? '');
        $compostScore = (int)($cm['score_pct'] ?? 0);

        $fe = $payload['fertilizer_effectiveness'] ?? [];
        $fertEfficiency = (int)($fe['efficiency_pct'] ?? 0);
        $fertPrescription = (string)($fe['summary'] ?? ($payload['prescriptions']['fertilizer'] ?? ''));
        $compostGuide = (string)($cm['guide'] ?? ($payload['prescriptions']['compost'] ?? ''));

        $pred = $payload['prediction'] ?? [];
        $limitingFactors = is_array($pred['limiting_factors'] ?? null)
            ? implode(', ', $pred['limiting_factors'])
            : (string)($pred['limiting_factors'] ?? '');

        $jsonRaw = json_encode($payload);

        $stmt = $pdo->prepare("INSERT INTO saved_analyses 
            (yield_kg, potential_kg, n, p, k, ph, moisture, soil_temp, ec,
             n_status, p_status, k_status, ph_status, compost_stage, compost_score,
             fert_efficiency, fertilizer_prescription, compost_guide, limiting_factors,
             season_name, season_month, analysis_data)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $yieldVal, $potVal, $n, $p, $k, $ph, $m, $temp, $ec,
            $nStatus, $pStatus, $kStatus, $phStatus, $compostStage, $compostScore,
            $fertEfficiency, $fertPrescription, $compostGuide, $limitingFactors,
            $seasonName, $seasonMonth, $jsonRaw
        ]);

        $newId = (int)$pdo->lastInsertId();

        echo json_encode([
            'ok' => true,
            'id' => $newId,
            'message' => 'Analysis successfully saved to MySQL database!'
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Database save failed: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
