-- Optional index for scoped batches, EmailQueueWorker::processBatchByEntityType(). Apply once, after 005, only
-- when a worker processes one entity type behind a large backlog of other types. processBatch() does not need it.
-- Without it a scoped candidate read walks, without locks, every row of its status that is ahead of its type in
-- (priority, id) order; measured behind 100,000 due rows of another type: about 60 ms on MySQL 8.0 and about
-- 210 ms on MariaDB 11.4 per read, growing with the backlog. With it: under 1 ms. It does not change what the
-- claim locks, and every status change maintains one more index entry. Not needed when the table already has an
-- index led by (status, entity_type, priority, id). Not idempotent.
-- ALGORITHM=INPLACE, LOCK=NONE lets reads and writes continue while the index is built, but the
-- statement still takes a brief exclusive metadata lock at its start and end and waits for open
-- transactions on the table; sessions queued behind it wait too. Run it off-peak with no long
-- transactions open. See https://dev.mysql.com/doc/refman/8.0/en/innodb-online-ddl-limitations.html
ALTER TABLE cd_email_queue
    ADD INDEX idx_email_queue_claim_entity_type (status, entity_type, priority, id),
    ALGORITHM=INPLACE, LOCK=NONE;
