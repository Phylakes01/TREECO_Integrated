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

-- Saved Soil Analysis Reports (stored permanently in MySQL)
CREATE TABLE IF NOT EXISTS saved_analyses (
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
);

