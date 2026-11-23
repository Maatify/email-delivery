-- Claim index for the worker. Apply once, after the earlier files of your path:
--   fresh install: 001, 002, then 005;
--   legacy table from before the keyed API: 002, 003 (once), then 005;
--   existing keyed schema (001+002 or 002+003), including 1.2.0: only 005 if no equivalent index exists.
-- Not needed when the table already has an index led by (status, priority, id). Not idempotent.
-- Without such an index the claim's candidate reads scan the table (without locks); with it each
-- walks its status in (priority, id) order and stops at the batch limit.
-- ALGORITHM=INPLACE, LOCK=NONE lets reads and writes continue while the index is built, but the
-- statement still takes a brief exclusive metadata lock at its start and end and waits for open
-- transactions on the table; sessions queued behind it wait too. Run it off-peak with no long
-- transactions open. See https://dev.mysql.com/doc/refman/8.0/en/innodb-online-ddl-limitations.html
ALTER TABLE cd_email_queue
    ADD INDEX idx_email_queue_claim (status, priority, id),
    ALGORITHM=INPLACE, LOCK=NONE;
