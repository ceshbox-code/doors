-- Журнал попыток входа для ограничения перебора пароля. Без внешних ключей:
-- логин может не существовать. Записи старше суток удаляются приложением.
CREATE TABLE login_attempts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    username   VARCHAR(100) NOT NULL,
    ip         VARCHAR(45) NOT NULL,
    success    TINYINT(1) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_la_username (username, success, created_at),
    KEY idx_la_ip (ip, success, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
