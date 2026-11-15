CREATE TABLE payroll_runs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    period_from  DATE NOT NULL,
    period_to    DATE NOT NULL,
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    status       ENUM('draft','confirmed','cancelled') NOT NULL DEFAULT 'draft',
    created_by   BIGINT UNSIGNED NOT NULL,
    confirmed_by BIGINT UNSIGNED NULL,
    confirmed_at DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_payroll_user_period (user_id, period_from, period_to),
    KEY idx_payroll_status (status),
    CONSTRAINT chk_payroll_period CHECK (period_to >= period_from),
    CONSTRAINT fk_payroll_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_payroll_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_payroll_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
