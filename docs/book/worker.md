# Worker lifecycle and clocks

[Book](README.md) · [Worker setup](../how-to/setup-worker.md) · [Retry details](retry-mechanism.md)

`EmailQueueWorker::processBatch(int $limit=50): void` processes one batch; it is not a daemon.
The application supplies a connection, real crypto contexts, renderer, transport and PSR-3 logger.
Give the worker a connection factory, a `Closure(): PDO` that returns a new exception-mode connection
with your session settings, so that it can replace a connection the server closed. A `PDO` instance
still works but cannot reconnect. The worker's transaction ownership differs from the guarded
[keyed enqueue ports](../reference/idempotency.md); do not wrap a worker batch in a caller transaction.

1. With a factory, confirm the connection with `SELECT 1` and replace it if the server closed it.
2. Begin a transaction and discover queue columns with `SELECT * ... LIMIT 0` result metadata.
3. Lock, in priority/ID order with `FOR UPDATE` and a batch limit, due `pending` rows and `processing`
   rows whose [processing lease](#processing-lease) has expired.
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

A claimed row stays `processing` while its lease is current. The claim stamps `updated_at` with the
row's clock (database UTC for keyed rows, database-local `NOW()` for legacy rows), and each row renews
it right before its render and send, so the lease has to outlast one render and send, not the whole
batch. The default is `EmailQueueWorker::DEFAULT_PROCESSING_LEASE_SECONDS` (300 seconds); pass
`processingLeaseSeconds` to change it, at least 1. Choose it above your longest expected render and send.

A later claim takes a `processing` row whose `updated_at` is older than the lease. Under the attempt cap,
the row becomes a new attempt and is **sent again**. At the cap it is settled `failed` without a send.
An attempt whose row was claimed again meanwhile neither sends nor writes; its retry/failed writes are
fenced by `status = 'processing'` and its own attempt number, so they never overwrite a newer outcome.

Delivery is **at-least-once**. A message can be sent twice when its `sent` status cannot be recorded
even after one reconnect, or when one render and send outlasts the lease. See
[ADR-012](../ADR-012-Worker-Connection-Recovery-And-Processing-Lease.md).

## Lost connections

With a factory, a closed connection is replaced only where repeating work is safe:

- before the claim transaction begins, where nothing has run yet;
- around single autocommit statements after the claim: the lease renewal and ownership read, the
  `sent` write, and the retry/failed writes with the keyed retry-clock read.

These run exactly once more on a new connection after driver error 2006 (server has gone away), 2013
(lost during a statement) or 4031 (disconnected for inactivity, how MySQL 8.0.24+ reports an expired
`wait_timeout`). Any other error propagates. The claim transaction itself is never retried.

Before PHP 8.4, the MySQL driver also raises a PHP warning such as `Packets out of order` when it reads
the server's disconnect. While these statements run, the worker holds such warnings back, so an
application handler that turns warnings into exceptions cannot hide the lost connection. A held warning
is dropped when the connection loss is confirmed; the worker logs that loss itself. Otherwise the
warning is raised again as `E_USER_WARNING`. Any other warning reaches your error handler unchanged.

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
