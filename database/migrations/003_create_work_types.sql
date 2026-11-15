CREATE TABLE work_types (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                   VARCHAR(190) NOT NULL,
    description            TEXT NULL,
    unit                   VARCHAR(40) NOT NULL DEFAULT 'шт',
    door_required          TINYINT(1) NOT NULL DEFAULT 1,
    allow_multiple_per_door TINYINT(1) NOT NULL DEFAULT 0,
    is_active              TINYINT(1) NOT NULL DEFAULT 1,
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_work_types_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
