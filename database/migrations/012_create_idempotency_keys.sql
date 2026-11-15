CREATE TABLE idempotency_keys (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       BIGINT UNSIGNED NOT NULL,
    idem_key      VARCHAR(100) NOT NULL,
    operation     VARCHAR(60) NOT NULL,
    request_hash  CHAR(64) NOT NULL,
    entity_id     BIGINT UNSIGNED NULL,
    response_json LONGTEXT NULL,
    status        ENUM('processing','done') NOT NULL DEFAULT 'processing',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at    DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_idem_user_key (user_id, idem_key),
    KEY idx_idem_expires (expires_at),
    CONSTRAINT fk_idem_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
