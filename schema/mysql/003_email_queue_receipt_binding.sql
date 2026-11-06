-- ONE-TIME upgrade for an existing pre-idempotency cd_email_queue; do NOT run after fresh 001.
-- Apply 002 and this file before adopting the keyed API. There is no backfill.
-- This immutable package-owned token binds receipts to jobs even if numeric IDs are reused.
-- Legacy inserts omit it (NULL); workers retain local timing for them and use UTC for keyed rows.
ALTER TABLE cd_email_queue
    ADD COLUMN enqueue_receipt_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ADD UNIQUE KEY uq_email_queue_enqueue_receipt (enqueue_receipt_id);
