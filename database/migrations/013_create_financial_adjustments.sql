-- Корректировки не переписывают выплаты и начисления: amount знаковый
-- (минус — уменьшает задолженность перед исполнителем, плюс — увеличивает).
CREATE TABLE financial_adjustments (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id        BIGINT UNSIGNED NOT NULL,
    type           ENUM('void_paid_work','cancel_payment','payroll_correction','overpayment','manual') NOT NULL,
    amount         DECIMAL(14,2) NOT NULL,
    work_record_id BIGINT UNSIGNED NULL,
    payment_id     BIGINT UNSIGNED NULL,
    payroll_run_id BIGINT UNSIGNED NULL,
    reason         TEXT NOT NULL,
    created_by     BIGINT UNSIGNED NOT NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_adj_user (user_id, created_at),
    CONSTRAINT fk_adj_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_adj_work FOREIGN KEY (work_record_id) REFERENCES work_records (id),
    CONSTRAINT fk_adj_payment FOREIGN KEY (payment_id) REFERENCES payments (id),
    CONSTRAINT fk_adj_run FOREIGN KEY (payroll_run_id) REFERENCES payroll_runs (id),
    CONSTRAINT fk_adj_created_by FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
