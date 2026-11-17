CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(40) NOT NULL DEFAULT 'ESP32',
    api_key_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,
    last_seen_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO devices (id, name) VALUES (1, 'Greenhouse A controller');

CREATE TABLE IF NOT EXISTS telemetry (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    temperature DECIMAL(7,2) NOT NULL,
    humidity DECIMAL(7,2) NOT NULL,
    ph DECIMAL(7,2) NOT NULL,
    tds DECIMAL(9,2) NOT NULL,
    recorded_at DATETIME NOT NULL,
    INDEX idx_telemetry_device_time (device_id, recorded_at, id),
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS alerts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    sensor_key VARCHAR(32) NOT NULL,
    level VARCHAR(1) NOT NULL DEFAULT 'w',
    message VARCHAR(255) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'Active',
    updated_by INT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_alert_device_status (device_id, sensor_key, status),
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS actuators (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    mode VARCHAR(8) NOT NULL DEFAULT 'auto',
    reported_on TINYINT(1) NULL,
    reported_at DATETIME NULL,
    max_seconds SMALLINT UNSIGNED NOT NULL,
    cooldown_seconds SMALLINT UNSIGNED NOT NULL,
    cooldown_until DATETIME NULL,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO actuators (id, device_id, name, max_seconds, cooldown_seconds)
VALUES (1, 1, 'Cooling fan', 120, 15), (2, 1, 'Water pump', 60, 30);

CREATE TABLE IF NOT EXISTS actuator_commands (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actuator_id INT NOT NULL,
    requested_by INT NULL,
    mode VARCHAR(8) NOT NULL,
    desired_on TINYINT(1) NOT NULL,
    duration_seconds SMALLINT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    acknowledged_at DATETIME NULL,
    INDEX idx_command_pending (actuator_id, status, expires_at),
    FOREIGN KEY (actuator_id) REFERENCES actuators(id) ON DELETE CASCADE,
    FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
