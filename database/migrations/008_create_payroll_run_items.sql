-- MariaDB не поддерживает частичные UNIQUE, поэтому is_active (1 у действующих расчётов,
-- 0 после отмены) + генерируемый active_work_record_id: одна работа — не более
-- чем в одном действующем расчёте. При отмене расчёта приложение ставит is_active = 0.
CREATE TABLE payroll_run_items (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payroll_run_id        BIGINT UNSIGNED NOT NULL,
    work_record_id        BIGINT UNSIGNED NOT NULL,
    work_type_name        VARCHAR(190) NOT NULL,
    door_number           VARCHAR(20) NULL,
    quantity              DECIMAL(12,3) NOT NULL,
    unit                  VARCHAR(40) NOT NULL,
    unit_price            DECIMAL(12,2) NOT NULL,
    total_amount          DECIMAL(14,2) NOT NULL,
    is_active             TINYINT(1) NOT NULL DEFAULT 1,
    active_work_record_id BIGINT UNSIGNED GENERATED ALWAYS AS (
        CASE WHEN is_active = 1 THEN work_record_id ELSE NULL END
    ) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_active_work_record (active_work_record_id),
    KEY idx_pri_run (payroll_run_id),
    KEY idx_pri_work (work_record_id),
    CONSTRAINT fk_pri_run FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs (id),
    CONSTRAINT fk_pri_work FOREIGN KEY (work_record_id) REFERENCES work_records (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
