# Worker lifecycle and clocks

[Book](README.md) · [Worker setup](../how-to/setup-worker.md) · [Retry details](retry-mechanism.md)

This chapter describes the prepared 1.3.0 worker in the current source. Its connection recovery,
processing lease, and entity-type-scoped batch behavior are not part of published 1.2.0.

`EmailQueueWorker::processBatch(int $limit=50): void` processes one batch; it is not a daemon.
`processBatchByEntityType(string $entityType, int $limit=50): void` processes one batch of a single
entity type; see [scoped batches](#scoped-batches-one-entity-type).
The application supplies a connection, real crypto contexts, renderer, transport and PSR-3 logger.
Give the worker a connection factory, a `Closure(): PDO` that returns a new exception-mode connection
with your session settings, so that it can replace a connection the server closed. A `PDO` instance
still works but cannot reconnect. On MySQL/MariaDB every worker connection needs autocommit enabled
(the server default): its status writes must commit on their own. The worker checks
`@@SESSION.autocommit` on each factory connection when it is created, and on a `PDO` instance before
every claim, because the application owns that connection and can turn autocommit off between batches. It
throws `UnexpectedValueException` before claiming or writing anything when it is off. It never changes the setting. Every worker connection must also report failures by exception
(`PDO::ERRMODE_EXCEPTION`, PHP's default): under `ERRMODE_SILENT` or `ERRMODE_WARNING` a failed status
write or lease renewal only returns `false`, and the worker could report a send it never recorded. A
factory connection in another mode is rejected when it is created, and a `PDO` instance is checked before
every claim, because the application can change its mode; either way `UnexpectedValueException` comes
before anything is claimed. The worker never changes the mode. The worker's transaction ownership differs from the guarded
[keyed enqueue ports](../reference/idempotency.md); do not wrap a worker batch in a caller transaction.

1. Check the error mode and autocommit (see above). With a factory, confirm the connection with `SELECT 1` and replace it
   if the server closed it.
2. Begin a transaction and discover queue columns with `SELECT * ... LIMIT 0` result metadata.
3. Read, without locks, the ids of `processing` rows whose [processing lease](#processing-lease) has
   expired and of due `pending` rows (each in priority/ID order with the batch limit). Lock only those
   rows, by primary key in one statement, re-checking both conditions on their latest version. Keep the
   first of them by priority/ID. See [claim index and locks](#claim-index-and-locks).
4. Set `processing`, increment attempts and stamp the lease. Expired rows that already used every
   attempt are settled `failed` (`processing_lease_expired`) without a send. Commit before external work.
5. Per row: renew the lease and confirm this attempt still owns the row, then decrypt recipient/payload,
   decode JSON, inject `lang` from payload language, render and call transport.
6. On a returned transport call, record `sent`; handled row failures are retried or marked `failed`.

![Worker state boundaries](../assets/worker-lifecycle-diagram.svg)

## Clock domains

| Row/table | Due and retry comparison | `retry_after` calculation | `sent_at` |
| --- | --- | --- | --- |
| Keyed: non-NULL `enqueue_receipt_id` | Database `UTC_TIMESTAMP()` | Database UTC plus the existing delay | Database UTC |
| Legacy: NULL token | Database-local `NOW()` | PHP-local `date()` plus delay | Database-local `NOW()` |
| Legacy table before 003, no token column | Original legacy path | Original legacy path | Original legacy path |

Keyed processing is independent of PHP/worker PDO timezone. The worker never changes the session
zone. It rediscovers metadata within each batch transaction instead of caching an old schema decision;
metadata/query failure is not treated as absence of the column. Existing SQLite legacy fixtures use the
no-column path; production keyed storage is MySQL/MariaDB. Operators must still align legacy writer,
worker DB and PHP timezones as before; the upgrade does not rewrite legacy timestamps.

## Processing lease

A claimed row stays `processing` while its lease is current. The claim stamps `updated_at`, and each
row renews it right before its render and send, so the lease has to outlast one render and send, not the whole
batch. The default is `EmailQueueWorker::DEFAULT_PROCESSING_LEASE_SECONDS` (300 seconds); pass
`processingLeaseSeconds` to change it, at least 1.

The lease clock follows the type of `updated_at`, read from the column metadata in every batch:

- **`DATETIME`** (the package schema): wall-clock values. Each row keeps its own clock: database UTC for
  keyed rows, database-local `NOW()` for legacy rows.
- **`TIMESTAMP`** (some application schemas): instants that the database converts by session zone.
  - **Comparison:** as instants, `UNIX_TIMESTAMP(updated_at) < UNIX_TIMESTAMP() - lease`. That holds in
    any session zone, across workers in different zones, across daylight saving jumps, and against stamps
    from `ON UPDATE CURRENT_TIMESTAMP`, such as a 1.2.0 worker's claim.
  - **Stamp:** an expression that stores the exact instant even in the repeated hour after a daylight
    saving fall-back. On MySQL 8.0.19+ that is a UTC value with an explicit offset, because there MySQL
    stores a local `NOW()` as the earlier of the two instants, one hour early. On MariaDB it is a directly
    assigned `NOW()`, which MariaDB stores exactly.
  - **Older MySQL:** before 8.0.19 the stamp is `NOW()`. In a session zone with daylight saving, a row
    stamped in that repeated hour can then look one hour older and be claimed early; use a fixed-offset
    or UTC session zone there.
  - **Engine detection:** the stamp is chosen from the server version the connection reports, so that
    version must identify the real engine. Behind a proxy that reports a MySQL 8.0.19+ version in front
    of MariaDB, the worker uses the offset form, which MariaDB cannot store. Under strict SQL mode every
    claim of a `TIMESTAMP` table then fails with error 1292; without it, the stamp loses its offset and
    the lease moves by the session zone's offset.
  - **Legacy `DATETIME` rows** keep their documented local-clock contract, so a daylight saving jump in
    the session zone moves their lease exactly as it moves their due times.

Nothing bounds one render and send. The transport's timeout (10 seconds by default) applies to each
socket operation, and one message needs several: connecting, the SMTP greeting, TLS, authentication
and every command with its reply. Rendering and logging add to that. If one render and send outlasts the
lease, another batch can claim the row and send it again. Size the lease well above the longest render
and send you observe, counting the configured transport timeout once for every SMTP operation of a
message. A longer lease makes a duplicate from a slow send less likely, while a shorter one recovers a
stopped worker's rows sooner, which matters for time-limited messages such as verification codes.

A later claim takes a `processing` row whose `updated_at` is older than the lease. Under the attempt cap,
the row becomes a new attempt and is **sent again**. At the cap it is settled `failed` without a send.
An attempt whose row was claimed again meanwhile neither sends nor writes; its retry/failed writes are
fenced by `status = 'processing'` and its own attempt number, so they never overwrite a newer outcome.

Delivery is **at-least-once**. A message can be sent twice when its `sent` status cannot be recorded
even after one reconnect, or when one render and send outlasts the lease. See
[ADR-012](../ADR-012-Worker-Connection-Recovery-And-Processing-Lease.md).

## Claim index and locks

Apply [`005_email_queue_claim_index.sql`](../../schema/mysql/005_email_queue_claim_index.sql), which adds
`idx_email_queue_claim (status, priority, id)` online. An existing index led by `(status, priority, id)`
serves the same purpose, and 005 is then unnecessary.

The claim locks only its candidates: the rows that were `processing` with an expired lease, or `pending`
and due, when it read them without locks. A row that was not a candidate at that read is never locked,
such as a `processing` row whose lease was current or a `pending` row that was not due yet. A candidate
can still change before the lock (see step 2 and [what waits](#what-waits-for-an-open-claim)):
1. **Candidates, without locks.** Two reads return ids only: `processing` rows whose lease has expired,
   and due `pending` rows. Each walks its status in `(priority, id)` order and stops at the batch limit.
2. **One locking read, by primary key.** Only the candidates are locked, in id order, through the
   primary key. The read re-checks "due `pending`, or expired lease" on each row's latest version. A
   candidate that another worker claimed or renewed meanwhile, or that the application rescheduled, is
   locked by this read but neither claimed nor sent.
3. **The first rows by priority/ID,** up to the batch limit.

A locking scan would not work. Under REPEATABLE READ, a locking read locks every index entry it walks
past, and the gaps between them, whether or not the row matches its conditions:
- **On `processing`,** it would lock every in-flight row, and could deadlock with that worker's outcome
  write, which locks the primary key first.
- **On `pending`,** it would lock every row that is not due yet ahead of the due ones, so enqueues and
  reschedules would wait for the claim.

Because every claim locks its candidates in id order, two concurrent claims never lock in opposite
orders. The second waits for the first to commit; its re-check then drops rows the first has taken, so
its batch can be smaller than the limit.

Delivery is at-least-once, so a lease can expire while its first send is still running. The claim can
then lock and reclaim that row, by design; see [processing lease](#processing-lease).

The due-time and lease conditions are checked row by row, because they compare each row with its own
clock. Without the index, each candidate read scans the table, without locks, and the locking read still
goes through the primary key.

These figures were measured on a 100,000-row table: 90% `sent`, 8% `failed`, 1.5% `pending`, 0.5%
`processing`. The engines were MySQL 8.0.46 and MariaDB 11.4 under REPEATABLE READ, and the batch held
50 rows, so each candidate read returned 50 ids.

| Schema and version | Claim reads | Rows locked | Claim reads (median) |
| --- | --- | --- | --- |
| Package schema, 1.2.0 (pending only), no index | full locking scan | 100,825 | 68 ms (MySQL), 56 ms (MariaDB) |
| Package schema, this version, no index | two unlocked scans; candidates by primary key | 100 | 56 ms (MySQL), 50 ms (MariaDB) |
| Package schema, this version, with `005` | two ordered index reads; candidates by primary key | 100 | 9 ms (MySQL), 7 ms (MariaDB) |
| Application schema with its own `(status, priority, id)` index, 1.2.0 | ordered locking index read | 148–150 | 4–4.5 ms |
| Same application schema, this version | two ordered index reads; candidates by primary key | 100–101 | 5–10 ms |

"Rows locked" is the count InnoDB reports. The 101 is one page-boundary lock, described below.

A second distribution puts 1,000 `pending` rows that are not due yet (scheduled or retrying in the
future) at priority 1, ahead of one due row at priority 9. It was measured on both schemas and engines,
with batch limits 1 and 50:

| Claim | Rows locked | Waited for the claim |
| --- | --- | --- |
| A locking `pending` scan (before this change) | 1,008–2,005; 3 where an index led by `(status, retry_after)` served it | reschedules of those rows and enqueues; with that index, every enqueue |
| This version | 1, the due row's primary key | only writes to the due row itself |

### What waits for an open claim

- **Never waits:** writes to rows that were not candidates, including outcome writes on in-flight rows
  with a current lease, rows that are not due yet and finished rows, and enqueues at any priority.
- **Waits until the claim transaction ends:** writes to its candidates. That includes a candidate that
  changed between the unlocked read and the lock and failed the re-check: under REPEATABLE READ (the
  default) InnoDB keeps the lock it took to read the row until the claim transaction ends; under READ
  COMMITTED it releases that lock right after the re-check (measured on MySQL 8.0 and MariaDB 11.4). The
  claim transaction ends right after its status updates, before any render or send.

The locking read locks only the candidate rows, by their primary-key records. When a candidate is the
first record on its page, MySQL can also take a next-key lock on the previous page's boundary, because
the in-order read passes it. That lock covers only the gap between the candidate and the id just before
it, where new auto-increment ids never land. No enqueue, reschedule or outcome write waited in any
measurement. The worker never changes the isolation level.

## Scoped batches: one entity type

`processBatchByEntityType($entityType, $limit)` runs the same batch as `processBatch($limit)`, but only for
rows whose `entity_type` equals `$entityType` exactly, byte for byte. Use it for a worker dedicated to one
kind of mail, such as administrator mail, in a queue that also holds other kinds. `processBatch()` is
unchanged and still takes every row.

- **Where the filter applies:** both unlocked candidate reads (due `pending` rows and expired leases) and
  the primary-key locking read that re-checks them. A scoped batch never takes, claims, reclaims, settles or
  sends a row of another type, and never locks a row that was not a candidate of its own type at the
  unlocked read.
- **A candidate that changed before the lock:** if a candidate's type (or its state) changes between the
  unlocked read and the lock, it fails the re-check and is not taken. The locking read has still read it by
  primary key, so, as with every candidate (see [what waits for an open claim](#what-waits-for-an-open-claim)),
  it stays locked until the claim transaction ends under REPEATABLE READ (the default), and is released right
  after the re-check under READ COMMITTED. A row that moved from `admin` to `user` in that window can
  therefore keep a short lock from the `admin` batch. Measured on MySQL 8.0 and MariaDB 11.4, on the package
  schema and on an application-like table, with and without 006.
- **Exactly means byte for byte:** case, accents and trailing spaces count, whatever the column's collation.
  The package schema compares `entity_type` with `utf8mb4_unicode_ci`, which ignores all three, so each
  statement follows the collation equality (which an index on `entity_type` can serve) with a comparison of
  the UTF-8 bytes. A batch for `admin` never takes `Admin`, `ADMIN`, `admín` or `admin `.
- **Leases:** a scoped batch reclaims, and settles at the attempt cap, only rows of its own type. So every
  type in the queue needs a worker, scoped or generic: an expired lease of a type nobody processes is never
  reclaimed. Rows of other types keep their leases and stay available to their own workers.
- **No type:** a row whose `entity_type` is NULL never matches, so only `processBatch()` takes it. An empty
  `$entityType` is rejected with `InvalidArgumentException` before the worker connects.
- **Limit:** counts rows of the scoped type, taken in priority/ID order.
- **Everything else is shared** with `processBatch()`: the connection checks (error mode, autocommit),
  reconnects, the lease clock (`DATETIME` and `TIMESTAMP`), renewal, fence, lost-acknowledgement
  read-back, retries and the lock scope above. The repository runs its whole reconnect and claim test
  suite a second time through the scoped method.

### Index for scoped batches (optional `006`)

The candidate reads use the claim index from `005`, led by `(status, priority, id)`. Without locks, a
scoped read walks every row of its status that is ahead of its type in that order until it finds `$limit`
rows of its type. That costs time, not locks, and it grows with the backlog of other types. Measured on the
package schema with 005, one scoped due-candidate read (the worker's statement, byte comparison included)
behind due rows of another type. Figures are rounded medians of seven runs; two runs of the same statement
agreed within 5%:

| Other-type due rows ahead | MySQL 8.0.46 | MariaDB 11.4 |
| --- | --- | --- |
| 5,000 | about 4 ms | about 11 ms |
| 20,000 | about 12 ms | about 43 ms |
| 50,000 | about 30 ms | about 105 ms |
| 100,000 | about 60 ms | about 210 ms |

[`006_email_queue_entity_type_claim_index.sql`](../../schema/mysql/006_email_queue_entity_type_claim_index.sql)
adds `idx_email_queue_claim_entity_type (status, entity_type, priority, id)`, so each scoped read walks only
its own type: under 1 ms in every case above. With a case-insensitive collation the index lookup also
visits look-alike types (`Admin` for `admin`), which the byte comparison then drops. Apply it after 005 only
if you run scoped batches behind a large backlog of other types. `processBatch()` keeps using 005
(measured), and every status change then maintains one more index entry. A table that already has an index
led by `(status, entity_type, priority, id)` does not need it.

## Lost connections

With a factory, a closed connection is replaced only where repeating work is safe:

- before the claim transaction begins, where nothing has run yet;
- around single autocommit statements after the claim: the lease renewal and ownership read, the
  `sent` write, and the retry/failed writes with the keyed retry-clock read.

These run exactly once more on a new connection after driver error 2006 (server has gone away), 2013
(lost during a statement) or 4031 (disconnected for inactivity, how MySQL 8.0.24+ reports an expired
`wait_timeout`). Any other error propagates. The claim transaction itself is never retried.

A 2013 can mean the statement ran and only its acknowledgement was lost, so each repeat has a defined
effect:
- **Lease renewal:** the repeat moves `updated_at` forward to the repeat's clock. The ownership read is
  only a read.
- **`sent` write:** the repeat changes nothing. `sent_at` keeps the first recorded send time, including
  when a lease expiry led to a second real send.
- **Retry/failed write:** the fenced repeat matches no row. Only on this repeat after a reconnect does the
  worker read the row back.
  - **Recorded:** the row counts as this attempt's outcome only when it holds the same status, attempt
    number and error message. The write is then logged normally, and the first `retry_after` is kept and
    reported.
  - **Not recorded:** a row another batch settled or rescheduled, such as `processing_lease_expired` at
    the attempt cap, which keeps the attempt number. It is reported as not recorded by this attempt and
    left unchanged.
  - **First try:** a fenced write that matches no row is reported that way without a read.
- **Keyed retry-clock read:** only a read.

Before PHP 8.4, the MySQL driver also raises a PHP warning such as `Packets out of order` when it reads
the server's disconnect. While these statements run, the worker holds such warnings back, so an
application handler that turns warnings into exceptions cannot hide the lost connection. A held warning
is dropped when the connection loss is confirmed; the worker logs that loss itself. Otherwise it is never
raised again, and the original exception or result passes through unchanged. It is written to the
logger as `Database driver warning during an email queue statement` on a best-effort basis: a logger
that throws there is ignored, so it cannot replace the original exception or result. Every other error, at any level, reaches your
error handler unchanged, and PHP's own handler runs only when yours returns `false`.

## Failure and recovery limits

Normal row errors use the [fixed delay tables](retry-mechanism.md). Batch/transaction/query failures
and errors while persisting outcomes can escape; the caller must handle them. Logs can include
underlying dependency messages, so protect/redact them rather than dumping raw exception text.

After a successful send, a failed `sent` write never writes `pending`/`failed` and never counts as a
send failure. The worker logs `Email sent but status write failed` with `job_id`, `attempt` and
`connection_lost`; that line is the record of the send. The row stays `processing` until its lease
expires, then is sent again under the attempt cap or settled `failed` (`processing_lease_expired`) at it.

- A connection still lost after one reconnect, or with no factory to reconnect, is rethrown. The batch
  stops so no further mail goes out while outcomes cannot be recorded; the rest of its claimed rows wait
  for their lease (300 seconds by default) before a later batch sends them.
- Any other error, such as a lock wait, continues with the next row.

A retry/failed write that still fails follows the same rule. It is logged as `Email job failure was not
recorded` with the same fields. A lost connection is rethrown; any other error leaves the row to its
lease and the batch continues.
The worker changes no session setting on any connection; session settings, including `wait_timeout`,
belong to your factory.

A transport may accept a message before a timeout, so an SMTP error can still be ambiguous. The lease
recovers rows a stopped worker left `processing`; it does not make delivery exactly-once.
