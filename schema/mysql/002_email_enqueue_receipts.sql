-- Additive upgrade: apply BEFORE adopting the new keyed API. No legacy rows are backfilled.
-- Never cascade queue deletion to this table or prune receipts: keys are not reusable.
-- Retain fingerprint decryption keys/context versions; missing keys fail closed on replay.
CREATE TABLE IF NOT EXISTS cd_email_enqueue_receipts (
    consumer_namespace VARBINARY(128) NOT NULL,
    request_key VARBINARY(191) NOT NULL,
    receipt_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    job_id BIGINT UNSIGNED NULL,
    accepted_at DATETIME(6) NOT NULL,
    fingerprint_version SMALLINT UNSIGNED NOT NULL,
    fingerprint_encrypted BLOB NOT NULL,
    fingerprint_iv VARBINARY(255) NOT NULL,
    fingerprint_tag VARBINARY(255) NOT NULL,
    fingerprint_key_id VARCHAR(255) NOT NULL,
    fingerprint_algorithm VARCHAR(32) NOT NULL,
    PRIMARY KEY (consumer_namespace, request_key),
    UNIQUE KEY uq_email_enqueue_receipt_id (receipt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
