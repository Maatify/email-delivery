-- Optional inert recording storage. Apply independently of 001/002/003; never read by the worker.
-- Retention is an explicit consumer choice. Schedule bounded purgeExpired() externally.
CREATE TABLE IF NOT EXISTS cd_email_synthetic_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    record_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    consumer_namespace VARBINARY(128) NOT NULL,
    entity_type VARCHAR(255) NOT NULL,
    entity_id VARCHAR(255) NULL,
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
    priority INT NOT NULL,
    requested_scheduled_at DATETIME NULL,
    recorded_at DATETIME(6) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    UNIQUE KEY uq_email_synthetic_record (record_id),
    KEY idx_email_synthetic_expiry (expires_at, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
