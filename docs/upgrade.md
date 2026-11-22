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
3. [`005_email_queue_claim_index.sql`](../schema/mysql/005_email_queue_claim_index.sql) (Unreleased; the
   worker's claim index, see [claim index](#worker-claim-index-unreleased))

**Do not apply 003 after fresh 001.** Fresh 001 already includes the nullable immutable binding and
unique index. The SQL stores raw cipher/IV/tag in binary columns; do not substitute text columns.

## Existing legacy queue

For an existing compatible `cd_email_queue` without `enqueue_receipt_id`:

1. Back up and inspect the existing engine/columns/indexes. Do not assume `IF NOT EXISTS` proves compatibility.
2. Apply [`002_email_enqueue_receipts.sql`](../schema/mysql/002_email_enqueue_receipts.sql).
3. Apply [`003_email_queue_receipt_binding.sql`](../schema/mysql/003_email_queue_receipt_binding.sql) **once**,
   then [`005_email_queue_claim_index.sql`](../schema/mysql/005_email_queue_claim_index.sql) (Unreleased).
4. Record the migration and verify both tables are InnoDB and the package constraints are present.
5. Complete the keyed-aware update of **every worker instance** before enabling keyed callers; do not leave an old worker consuming new UTC rows.

003 uses portable `ALTER TABLE ADD COLUMN`/`ADD UNIQUE KEY`, not MySQL-incompatible `ADD COLUMN
IF NOT EXISTS`. Reapplying it is an operator migration error, not an idempotent upgrade. If the binding
already exists, determine whether 003 was applied; do not run it blindly or drop data to force success.
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
that drops durable identities, and do not bulk reset `processing` rows. Investigate acceptance/SMTP
ambiguity before an operator acts.

## Worker claim index (Unreleased)

Apply [`005_email_queue_claim_index.sql`](../schema/mysql/005_email_queue_claim_index.sql) **once**, after
the earlier files of your path:

| Installation | Apply |
| --- | --- |
| Fresh | 001, 002, then 005 |
| Legacy table from before the keyed API (no `enqueue_receipt_id`) | 002, 003 (once), then 005 |
| 1.2.0 that already applied 002 and 003 | **only 005**; never re-run 003 |

A table that already has an index led by `(status, priority, id)` does not need 005. Like 003, it is not
idempotent: if `idx_email_queue_claim` already exists, do not run it again.

**What `ALGORITHM=INPLACE, LOCK=NONE` does and does not promise.** Reads and writes on the table
(concurrent DML) continue while the index is built.
- **Metadata locks:** the statement still takes a brief exclusive metadata lock at the start and at the
  end, and waits until transactions that already touched the table have finished.
- **Queued sessions:** while it waits for that lock, other sessions that need the table queue behind it.
  A long-running transaction can therefore make the ALTER, and the sessions behind it, wait.
- **When to run it:** off-peak, with no long transactions open on the table. See MySQL's
  [online DDL limitations](https://dev.mysql.com/doc/refman/8.0/en/innodb-online-ddl-limitations.html).

**Effect on the claim.** The claim locks only its candidates, by primary key, either way. Without such
an index each of its two candidate reads scans the table (without locks); with it, each walks its status
in index order and stops at the batch limit. The worker works either way. Measured figures and what still waits are in the
[worker book](book/worker.md#claim-index-and-locks).

**Application-owned tables.** A table whose `updated_at` is a `TIMESTAMP` (for example with `ON UPDATE
CURRENT_TIMESTAMP`) is supported. The worker detects the type in every batch, compares the lease as
instants and stamps it exactly, including across daylight saving changes; see the
[worker book](book/worker.md#processing-lease). On MySQL older than 8.0.19, use a fixed-offset or UTC
session zone for the worker. The worker tells MySQL from MariaDB by the server version the connection
reports, so a proxy in front of MariaDB must not report a MySQL version.

## Worker processing lease (Unreleased)

No other SQL change is needed. The upgraded worker stamps and renews `updated_at` on `processing` rows and
claims a row again once it is older than the lease (default 300 seconds), as described in the
[processing lease](book/worker.md#processing-lease). Before upgrading:

- Review rows already `processing`. Their `updated_at` is their enqueue time, so the first upgraded
  batch claims the stale ones again: under the attempt cap they are sent again, at the cap they are
  settled `failed` with `processing_lease_expired`.
- Stop every older worker first. Older workers neither stamp nor renew the lease, so an upgraded worker
  may claim a row that an older one is still sending.
- Pass a connection factory instead of a `PDO` instance so the worker can replace a closed connection.
- Give the worker connections with autocommit enabled (the MySQL/MariaDB default). It now checks
  `@@SESSION.autocommit` before its first claim and throws `UnexpectedValueException` when it is off.
- Give the worker connections in exception error mode (`PDO::ERRMODE_EXCEPTION`, PHP's default). A
  `PDO` instance in `ERRMODE_SILENT` or `ERRMODE_WARNING` is now rejected with `UnexpectedValueException`
  before every claim.

Rolling back to an older worker removes reconnects and the lease; rows left `processing` then need an
operator decision again.


## Optional synthetic recording

Apply [`004_email_synthetic_requests.sql`](../schema/mysql/004_email_synthetic_requests.sql) only when
adopting `PdoSyntheticEmailStore`. It is independent of 001/002/003 and creates no real queue or receipt
table. Existing mail users need no migration for this additive feature. `IF NOT EXISTS` is not a schema
compatibility proof: the adapter fails closed if the dedicated package schema is incompatible.

Configure a separate idle exception-mode/autocommit UTC PDO, existing crypto provider/context provider,
and an explicit retention duration. Schedule bounded `purgeExpired()` calls in trusted maintenance.
Grant necessary metadata visibility; no custom triggers/outgoing foreign keys or altered indexes/columns.
Expiry is not automatic deletion. Synthetic cleanup never prunes real receipts. Disable synthetic callers
before any separately approved removal of its optional table. See the [complete contract](reference/synthetic-recording.md).
