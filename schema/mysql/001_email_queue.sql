-- Fresh install: apply 001 then 002. Existing deployments apply 002 and 003 instead.
-- Raw AES-GCM bytes require binary columns. Keyed timestamps use UTC; legacy timing retains its prior contract.
CREATE TABLE IF NOT EXISTS cd_email_queue (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(255) NULL,
    entity_id VARCHAR(255) NULL,
    enqueue_receipt_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    recipient_encrypted LONGBLOB NOT NULL,
    recipient_iv VARBINARY(255) NOT NULL,
    recipient_tag VARBINARY(255) NOT NULL,
    recipient_key_id VARCHAR(255) NOT NULL,
    payload_encrypted LONGBLOB NOT NULL,
    payload_iv VARBINARY(255) NOT NULL,
    payload_tag VARBINARY(255) NOT NULL,
    payload_key_id VARCHAR(255) NOT NULL,
    template_key VARCHAR(255) NOT NULL,
    language VARCHAR(255) NOT NULL,
    sender_type INT NOT NULL,
    priority INT NOT NULL DEFAULT 5,
    status VARCHAR(50) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    retry_after DATETIME NULL,
    sent_at DATETIME NULL,
    scheduled_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_email_queue_enqueue_receipt (enqueue_receipt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
