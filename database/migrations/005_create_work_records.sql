-- allow_unique_per_door: снимок настройки вида работы (1 = неповторяемая) на момент регистрации.
-- active_unique_door_id + UNIQUE: одна действующая запись такого вида на дверь;
-- после аннулирования (voided_at) ключ становится NULL и запись можно создать заново.
CREATE TABLE work_records (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_type_id          BIGINT UNSIGNED NOT NULL,
    door_id               BIGINT UNSIGNED NULL,
    user_id               BIGINT UNSIGNED NOT NULL,
    rate_id               BIGINT UNSIGNED NOT NULL,
    quantity              DECIMAL(12,3) NOT NULL,
    unit                  VARCHAR(40) NOT NULL,
    unit_price            DECIMAL(12,2) NOT NULL,
    total_amount          DECIMAL(14,2) NOT NULL,
    allow_unique_per_door TINYINT(1) NOT NULL DEFAULT 0,
    completed_at          DATETIME NOT NULL,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by            BIGINT UNSIGNED NOT NULL,
    comment               TEXT NULL,
    voided_at             DATETIME NULL,
    voided_by             BIGINT UNSIGNED NULL,
    void_reason           TEXT NULL,
    active_unique_door_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE
            WHEN voided_at IS NULL AND allow_unique_per_door = 1 AND door_id IS NOT NULL
            THEN door_id
            ELSE NULL
        END
    ) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_work_type_active_door (work_type_id, active_unique_door_id),
    KEY idx_wr_user_completed (user_id, completed_at),
    KEY idx_wr_completed (completed_at),
    KEY idx_wr_door (door_id),
    KEY idx_wr_type_completed (work_type_id, completed_at),
    CONSTRAINT chk_wr_quantity CHECK (quantity > 0),
    CONSTRAINT chk_wr_price CHECK (unit_price >= 0),
    CONSTRAINT chk_wr_total CHECK (total_amount >= 0),
    CONSTRAINT fk_wr_type FOREIGN KEY (work_type_id) REFERENCES work_types (id),
    CONSTRAINT fk_wr_door FOREIGN KEY (door_id) REFERENCES doors (id),
    CONSTRAINT fk_wr_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_wr_rate FOREIGN KEY (rate_id) REFERENCES work_rates (id),
    CONSTRAINT fk_wr_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_wr_voided_by FOREIGN KEY (voided_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
