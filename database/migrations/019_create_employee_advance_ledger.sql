CREATE TABLE employee_advance_ledger (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,

    type ENUM(
        'issue',
        'apply',
        'reverse_apply',
        'reverse_issue'
    ) NOT NULL,

    amount DECIMAL(14,2) NOT NULL,

    payment_id BIGINT UNSIGNED NULL,
    work_record_id BIGINT UNSIGNED NULL,

    source_issue_id BIGINT UNSIGNED NULL,
    reversal_of_id BIGINT UNSIGNED NULL,

    reason VARCHAR(500) NULL,

    created_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),

    UNIQUE KEY uq_advance_reversal (reversal_of_id),
    UNIQUE KEY uq_advance_payment (payment_id),

    KEY idx_advance_user_created (user_id, created_at, id),
    KEY idx_advance_source_issue (source_issue_id, id),
    KEY idx_advance_work_record (work_record_id, id),
    KEY idx_advance_type (type, user_id),
    KEY idx_advance_created_by (created_by),

    CONSTRAINT chk_advance_amount CHECK (amount > 0),

    CONSTRAINT fk_advance_user
        FOREIGN KEY (user_id) REFERENCES users (id),

    CONSTRAINT fk_advance_created_by
        FOREIGN KEY (created_by) REFERENCES users (id),

    CONSTRAINT fk_advance_payment
        FOREIGN KEY (payment_id) REFERENCES payments (id),

    CONSTRAINT fk_advance_work
        FOREIGN KEY (work_record_id) REFERENCES work_records (id),

    CONSTRAINT fk_advance_source_issue
        FOREIGN KEY (source_issue_id) REFERENCES employee_advance_ledger (id),

    CONSTRAINT fk_advance_reversal
        FOREIGN KEY (reversal_of_id) REFERENCES employee_advance_ledger (id)

) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;
