CREATE DATABASE IF NOT EXISTS treeco_db;
USE treeco_db;

CREATE TABLE IF NOT EXISTS sensor_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    moisture FLOAT NOT NULL,
    soil_temp FLOAT NOT NULL,
    ph FLOAT NOT NULL,
    ec INT NOT NULL,
    n INT NOT NULL,
    p INT NOT NULL,
    k INT NOT NULL,
    recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Connection state only; original sensor_logs table and columns are retained.
CREATE TABLE IF NOT EXISTS hardware_state (
    id INT PRIMARY KEY,
    received_at BIGINT NOT NULL,
    sensor_ok TINYINT NOT NULL,
    latest_reading_id INT NULL
);
