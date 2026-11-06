# Schema installation and upgrade

[Documentation](README.md) · [API contract](reference/idempotency.md) · [Release preparation](release-preparation.md)

Apply the package-owned MySQL/MariaDB SQL before adopting keyed enqueue. The package does not
run migrations, silently ALTER incompatible tables, backfill identities or deduplicate old content.
Operators own backups, migration bookkeeping and the deployment approval. These instructions are
preparation guidance, not evidence that a production migration has been applied.

## Fresh installation

On a new empty application schema, apply in order:

1. [`001_email_queue.sql`](../schema/mysql/001_email_queue.sql)
2. [`002_email_enqueue_receipts.sql`](../schema/mysql/002_email_enqueue_receipts.sql)

**Do not apply 003 after fresh 001.** Fresh001 already includes the nullable immutable binding and
unique index. The SQL stores raw cipher/IV/tag in binary columns; do not substitute text columns.

## Existing legacy queue

For an existing compatible `cd_email_queue` without `enqueue_receipt_id`:

1. Back up and inspect the existing engine/columns/indexes. Do not assume `IF NOT EXISTS` proves compatibility.
2. Apply [`002_email_enqueue_receipts.sql`](../schema/mysql/002_email_enqueue_receipts.sql).
3. Apply [`003_email_queue_receipt_binding.sql`](../schema/mysql/003_email_queue_receipt_binding.sql) **once**.
4. Record the migration and verify both tables are InnoDB and the package constraints are present.
5. Complete the keyed-aware update of **every worker instance** before enabling keyed callers; do not leave an old worker consuming new UTC rows.

003 uses portable `ALTER TABLE ADD COLUMN`/`ADD UNIQUE KEY`, not MySQL-incompatible `ADD COLUMN
IF NOT EXISTS`. Reapplying it is an operator migration error, not an idempotent upgrade. If the binding
already exists, determine whether003 was applied; do not run it blindly or drop data to force success.
No legacy rows acquire a synthetic request identity. Their token remains NULL.

## Connection and clock setup

The new enqueue and receipt-reader ports require an idle `ERRMODE_EXCEPTION` PDO, autocommit enabled,
and explicit session `+00:00` or `UTC`. Use a dedicated UTC connection for the keyed writer/reader; do not flip a shared legacy connection to UTC as an implicit migration. The application configures its own connection, including crypto
contexts and key material. No library constructor changes these settings or reads your environment.

Workers support mixed rows and old pre-003 tables. Keyed rows use database UTC; legacy NULL-token
rows retain database-local `NOW()` and PHP-local retry timestamps. Upgrading does not reinterpret
old dates or backfill them to UTC. Coordinate legacy writer, worker DB and PHP zones as before.
The worker discovers the column per batch transaction; metadata failure is not old-schema detection.

## Verify adoption safely

On an isolated disposable `_test` database, verify first accept, same-key replay from a fresh connection,
conflict, namespace isolation, concurrent callers, transaction failures and receipt lookup after queue
purge/ID reuse. Verify worker due/retry boundaries in non-UTC sessions and legacy compatibility.
The repository CI configuration makes real-DB tests required; see [release checks](release-preparation.md).
Do not use a live application schema as the integration-test target: tests own/drop their queue tables.

## Retention and rollback boundaries

Retain `cd_email_enqueue_receipts` indefinitely and keep old fingerprint read keys/context versions.
Deleting queue rows does not authorize deletion of receipts, key reuse or a replacement enqueue.
`QueueMissing` is a status observation, not proof that mail was sent.

A code rollback must not remove receipts or the queue binding. Old legacy inserts omit the nullable
column, but an old worker lacks keyed UTC timing: assess/drain or run it with explicitly consistent UTC
DB/PHP settings before letting it process existing keyed jobs. Do not run an automatic down migration
that drops durable identities, and do not bulk reset `processing` rows: the package has no built-in
reclaim/recovery policy for them. Investigate acceptance/SMTP ambiguity before an operator acts.
