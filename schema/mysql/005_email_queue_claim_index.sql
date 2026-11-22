-- Claim index for the worker. Apply once to every installation, fresh or existing: fresh installs run
-- 001, 002, then this file; existing installs run it once after their earlier upgrades.
-- Without it the claim's locking read scans and locks the whole table; with it the claim reads and locks
-- only the rows that are `pending` or `processing`. Online on MySQL 8.0+ and MariaDB: no table copy,
-- reads and writes continue while the index is built.
ALTER TABLE cd_email_queue
    ADD INDEX idx_email_queue_claim (status, priority, id),
    ALGORITHM=INPLACE, LOCK=NONE;
