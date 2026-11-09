CREATE TABLE IF NOT EXISTS integration_settings (
 user_id CHAR(36) PRIMARY KEY, settings TEXT NOT NULL, last_sync_at DATETIME NULL, last_error TEXT NULL,
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS integration_tokens (
 id CHAR(36) PRIMARY KEY, user_id CHAR(36) NOT NULL, source VARCHAR(20) NOT NULL, origin VARCHAR(255) NOT NULL,
 token_hash CHAR(64) NOT NULL, created_at DATETIME NOT NULL, expires_at DATETIME NOT NULL,
 last_received_at DATETIME NULL, revoked_at DATETIME NULL, INDEX(user_id),
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS integration_snapshots (
 user_id CHAR(36) NOT NULL, source VARCHAR(120) NOT NULL, snapshot_day DATE NOT NULL, payload MEDIUMTEXT NOT NULL,
 captured_at DATETIME NOT NULL, received_at DATETIME NOT NULL, PRIMARY KEY(user_id,source,snapshot_day),
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS integration_alerts (
 user_id CHAR(36) NOT NULL, fingerprint CHAR(64) NOT NULL, title VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL,
 PRIMARY KEY(user_id,fingerprint), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
