-- Удаление фотографии «по специальному разрешению» (ТЗ 12.7) — мягкое: строка и файлы остаются,
-- запись скрывается из выдачи, действие фиксируется с причиной и пользователем.
ALTER TABLE photos
    ADD COLUMN deleted_at    DATETIME NULL,
    ADD COLUMN deleted_by    BIGINT UNSIGNED NULL,
    ADD COLUMN delete_reason TEXT NULL,
    ADD CONSTRAINT fk_photos_deleted_by FOREIGN KEY (deleted_by) REFERENCES users (id);
