-- Сохраняем фактическое время выполнения отдельно от времени приёма записи сервером.
ALTER TABLE work_records
    ADD COLUMN received_at DATETIME NULL AFTER completed_at,
    ADD COLUMN completed_at_source VARCHAR(16) NOT NULL DEFAULT 'server' AFTER received_at;

UPDATE work_records SET received_at = created_at WHERE received_at IS NULL;

ALTER TABLE work_records
    MODIFY COLUMN received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;
