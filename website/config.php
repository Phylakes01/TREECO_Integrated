<?php
$host = 'localhost';
$db   = 'treeco_db';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];
try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    error_log('TREECO database: ' . $e->getMessage());
    http_response_code(503);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Database unavailable. Check MySQL/config.php.']);
    exit;
}
