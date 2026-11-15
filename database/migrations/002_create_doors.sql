-- Атомарный счётчик номеров дверей (D-000001 ...). Не зависит от числа строк в doors.
-- Выдача: INSERT INTO door_sequences (name, last_value) VALUES ('door', 1)
--         ON DUPLICATE KEY UPDATE last_value = LAST_INSERT_ID(last_value + 1);
CREATE TABLE door_sequences (
    name       VARCHAR(40) NOT NULL,
    last_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE doors (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    number     VARCHAR(20) NOT NULL,
    status     VARCHAR(30) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_by BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_doors_number (number),
    KEY idx_doors_created_at (created_at),
    CONSTRAINT fk_doors_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
