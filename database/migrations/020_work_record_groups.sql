-- Пакетная регистрация нескольких видов работ для одной двери.
-- Старые записи получают group_id = NULL и продолжают работать без изменений.
CREATE TABLE work_record_groups (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    created_by          BIGINT UNSIGNED NOT NULL,
    user_id             BIGINT UNSIGNED NOT NULL,
    door_id             BIGINT UNSIGNED NULL,
    completed_at        DATETIME NOT NULL,
    received_at         DATETIME NOT NULL,
    completed_at_source VARCHAR(16) NOT NULL DEFAULT 'server',
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    comment             TEXT NULL,
    PRIMARY KEY (id),
    KEY idx_wrg_user_completed (user_id, completed_at),
    KEY idx_wrg_door (door_id),
    CONSTRAINT fk_wrg_created_by FOREIGN KEY (created_by) REFERENCES users (id),
    CONSTRAINT fk_wrg_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_wrg_door FOREIGN KEY (door_id) REFERENCES doors (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE work_records
    ADD COLUMN group_id BIGINT UNSIGNED NULL AFTER id,
    ADD KEY idx_wr_group (group_id),
    ADD CONSTRAINT fk_wr_group FOREIGN KEY (group_id) REFERENCES work_record_groups (id);
