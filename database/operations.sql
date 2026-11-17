-- Existing accounts retain access; public registrations explicitly start Pending.
ALTER TABLE users ADD COLUMN IF NOT EXISTS status VARCHAR(16) NOT NULL DEFAULT 'Active',
    ADD COLUMN IF NOT EXISTS session_version INT UNSIGNED NOT NULL DEFAULT 1;

CREATE TABLE IF NOT EXISTS rate_limits (
    bucket CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    attempts INT UNSIGNED NOT NULL,
    resets_at DATETIME NOT NULL,
    INDEX idx_rate_expiry (resets_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id INT NULL,
    actor_name VARCHAR(100) NOT NULL,
    action VARCHAR(64) NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    entity_id VARCHAR(64) NOT NULL,
    before_json LONGTEXT NULL,
    after_json LONGTEXT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_activity_time (created_at, id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS telemetry_ranges (
    telemetry_id BIGINT UNSIGNED NOT NULL,
    sensor_key VARCHAR(32) NOT NULL,
    ideal_min DECIMAL(10,2) NOT NULL,
    ideal_max DECIMAL(10,2) NOT NULL,
    outside_range TINYINT(1) NOT NULL,
    PRIMARY KEY (telemetry_id, sensor_key),
    FOREIGN KEY (telemetry_id) REFERENCES telemetry(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS actuator_state_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actuator_id INT NOT NULL,
    reported_on TINYINT(1) NOT NULL,
    mode VARCHAR(8) NOT NULL,
    recorded_at DATETIME NOT NULL,
    INDEX idx_state_time (actuator_id, recorded_at, id),
    FOREIGN KEY (actuator_id) REFERENCES actuators(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_incidents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    incident_key VARCHAR(100) NOT NULL UNIQUE,
    kind VARCHAR(24) NOT NULL,
    device_id INT NOT NULL,
    message VARCHAR(500) NOT NULL,
    started_at DATETIME NOT NULL,
    resolved_at DATETIME NULL,
    send_count INT UNSIGNED NOT NULL DEFAULT 0,
    generation INT UNSIGNED NOT NULL DEFAULT 1,
    last_queued_at DATETIME NULL,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notification_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    incident_id BIGINT UNSIGNED NOT NULL,
    recipient VARCHAR(150) NOT NULL,
    repeat_number INT UNSIGNED NOT NULL,
    generation INT UNSIGNED NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    last_error VARCHAR(255) NULL,
    UNIQUE KEY unique_delivery (incident_id, generation, recipient, repeat_number),
    INDEX idx_delivery_recipient_time (recipient, sent_at),
    FOREIGN KEY (incident_id) REFERENCES notification_incidents(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS maintenance_runs (
    job VARCHAR(32) PRIMARY KEY,
    last_run_at DATETIME NOT NULL,
    status VARCHAR(16) NOT NULL,
    detail VARCHAR(500) NOT NULL
) ENGINE=InnoDB;

ALTER TABLE notification_deliveries ADD INDEX IF NOT EXISTS idx_delivery_recipient_time (recipient, sent_at);
