-- file_name: имя оригинала в storage; optimized_file_name: оптимизированная копия (ТЗ 4.4).
CREATE TABLE photos (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    work_record_id      BIGINT UNSIGNED NOT NULL,
    file_name           VARCHAR(255) NOT NULL,
    optimized_file_name VARCHAR(255) NULL,
    original_name       VARCHAR(255) NOT NULL,
    mime_type           VARCHAR(100) NOT NULL,
    file_size           BIGINT UNSIGNED NOT NULL,
    sha256              CHAR(64) NOT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_photos_file_name (file_name),
    KEY idx_photos_work (work_record_id),
    KEY idx_photos_sha256 (sha256),
    CONSTRAINT fk_photos_work FOREIGN KEY (work_record_id) REFERENCES work_records (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
