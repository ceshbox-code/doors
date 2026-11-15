-- Проверки «сумма распределений = сумма выплаты» и «не больше остатка начисления»
-- выполняются в транзакции с блокировкой строк (SELECT ... FOR UPDATE).
CREATE TABLE payment_allocations (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id     BIGINT UNSIGNED NOT NULL,
    work_record_id BIGINT UNSIGNED NOT NULL,
    amount         DECIMAL(14,2) NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_alloc_payment_work (payment_id, work_record_id),
    KEY idx_alloc_work (work_record_id),
    CONSTRAINT chk_alloc_amount CHECK (amount > 0),
    CONSTRAINT fk_alloc_payment FOREIGN KEY (payment_id) REFERENCES payments (id),
    CONSTRAINT fk_alloc_work FOREIGN KEY (work_record_id) REFERENCES work_records (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
