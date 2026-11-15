-- Непересечение интервалов valid_from..valid_to проверяется в приложении
-- транзакционно, с блокировкой пары (user_id, work_type_id). Записи не удаляются.
CREATE TABLE work_rates (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id      BIGINT UNSIGNED NOT NULL,
    work_type_id BIGINT UNSIGNED NOT NULL,
    unit_price   DECIMAL(12,2) NOT NULL,
    valid_from   DATETIME NOT NULL,
    valid_to     DATETIME NULL,
    created_by   BIGINT UNSIGNED NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rates_lookup (user_id, work_type_id, valid_from),
    CONSTRAINT chk_rates_price CHECK (unit_price >= 0),
    CONSTRAINT chk_rates_period CHECK (valid_to IS NULL OR valid_to > valid_from),
    CONSTRAINT fk_rates_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_rates_type FOREIGN KEY (work_type_id) REFERENCES work_types (id),
    CONSTRAINT fk_rates_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
