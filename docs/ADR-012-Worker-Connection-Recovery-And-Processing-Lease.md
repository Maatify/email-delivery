# ADR-012: Worker connection recovery and processing lease

**Status:** AWAITING OWNER ACCEPTANCE of this record. Unreleased. Items 5 and 7 are the owner's
decisions, recorded on [issue #13](https://github.com/Maatify/email-delivery/issues/13#issuecomment-7072371032):
at-least-once delivery, and a default lease of 300 seconds.

**Date:** 2026-11-22

**Related:** [Issue #13](https://github.com/Maatify/email-delivery/issues/13),
[historical ADR-008](ADR-008-Email-Delivery-Independent-Queue.md), [ADR-010](ADR-010-Idempotent-Email-Enqueue.md)

## Context

`EmailQueueWorker::processBatch()` commits its claim, then decrypts, renders and sends each row while
its database connection idles. A server can close that connection meanwhile: a short `wait_timeout`,
a restart or a network cut. In 1.2.0 the `sent` write then fails, the failure path's write fails on the
same connection, the row stays `processing` with no automatic recovery, and every later batch fails on
the closed connection. A non-connection failure of the `sent` write was also routed to the retry path,
which scheduled a second send of a message that had already been sent.

## Decision

1. **Connection factory.** The first constructor argument accepts `PDO|Closure(): PDO`. With a factory,
   the worker opens its own connection on first use and replaces it after the server closed it. The
   factory returns a new exception-mode connection with the application's session settings. A `PDO`
   instance keeps the 1.2.0 single-connection behaviour: it cannot reconnect.
   On MySQL/MariaDB every worker connection must have autocommit enabled, because its status writes must
   commit on their own. Every factory connection is checked when it is created, and a `PDO` instance
   once before its first claim, with `SELECT @@SESSION.autocommit`, the same check the keyed and
   synthetic adapters make. A connection with autocommit off is rejected with `UnexpectedValueException`
   before anything is claimed or written. The worker never changes the setting. The session time zone is
   not required, because legacy rows use the database-local `NOW()`.
2. **One reconnect, only around statements that are safe to repeat.** These are autocommit statements
   outside any transaction: the per-row lease renewal and ownership read, the `sent` write, and the
   retry/failed writes with the keyed retry-clock read. On driver code 2006 (server has gone away), 2013
   (lost during a statement) or 4031 (disconnected for inactivity, how MySQL 8.0.24+ reports an expired
   `wait_timeout`; MariaDB reports 2006), the worker reconnects and runs them exactly once more. Any
   other error propagates unchanged. A 2013 can mean the statement ran and only its acknowledgement was
   lost, so each repeat has a defined effect:
   - **Lease renewal:** the repeat moves `updated_at` forward to the repeat's clock. The ownership read
     is only a read.
   - **`sent` write:** the repeat changes nothing. `sent_at = COALESCE(sent_at, clock)` keeps the first
     recorded send time, including after a second real send that followed a lease expiry.
   - **Retry/failed write:** the fenced repeat matches no row. The worker then reads the row back. If the
     row already holds this attempt's outcome (the same status and attempt number), the write counts as
     recorded and is logged normally, and the first `retry_after` is kept and reported. Otherwise the
     outcome is logged as not recorded by this attempt.
   - **Keyed retry-clock read:** only a read.
   Before PHP 8.4, mysqlnd also raises a `Packets out of order` warning when it reads that disconnect.
   While these statements run, such warnings are held back so an application handler that converts
   warnings into exceptions cannot mask the `PDOException`. They are dropped once the loss is confirmed,
   because the worker logs that loss itself. Otherwise they are logged through the worker's logger and
   never raised again, so the original exception or result reaches the caller unchanged. Every other
   error, at any level, goes to the application's handler unchanged.
3. **Never around a transaction.** The claim is never retried. Before `beginTransaction()` the worker
   checks its connection with `SELECT 1` and replaces it if the server closed it; nothing has run yet.
4. **A send is never followed by a retry write.** After a successful send, a failed `sent` write never
   writes `pending`/`failed` and never counts as a send failure. The worker logs
   `Email sent but status write failed` with the job and attempt; that line is the record of the send.
   The row stays `processing` and the lease decides what happens next: sent again under the cap, or settled
   `failed`/`processing_lease_expired` at it.
   **One rule for every outcome write** (`sent`, retry and failed) that still fails after its reconnect:
   - A lost connection, or a reconnect that itself failed, is rethrown. The batch stops, no further mail
     goes out on a dead link, and the caller handles it. The batch's remaining claimed rows then wait one
     lease.
   - Any other error, such as a lock wait or a deadlock, is logged and the batch continues with the next
     row. The row stays `processing` for its lease. A failed retry/failed write is logged as
     `Email job failure was not recorded`.
5. **Processing lease in the claim (owner decision: at-least-once).** The claim selects due `pending`
   rows and `processing` rows whose `updated_at` is older than the lease, compared with the row's own
   clock: `UTC_TIMESTAMP()` for keyed rows, `NOW()` for legacy rows. It reads through the claim index
   `idx_email_queue_claim (status, priority, id)`, added by schema 005. The claim stamps `updated_at`, and
   each row renews it right before it is rendered and sent. A stale row under the attempt cap is claimed
   as a new attempt and sent again. A stale row at the cap is settled `failed` with
   `processing_lease_expired`, without a send.
6. **Attempt fence.** The renewal and the retry/failed writes require `status = 'processing'` and the
   attempt number of this worker's claim. Ownership is read back after the renewal: MySQL reports
   changed rows only, so a renewal within the claim's second would otherwise look unowned. If another
   batch has claimed the row since, this worker neither sends nor writes. The `sent` write is not
   fenced, because a true send always wins. Suppose a newer attempt reclaimed the row and its send then
   failed: recording `sent` leaves two sends, where fencing it would schedule a third. Delivery stays
   at-least-once, with fewer duplicates.
7. **Default lease 300 seconds, configurable (owner decision).** The lease is renewed right before each
   message, so it has to cover one render and send, not a whole batch.
   - **No hard upper bound.** The transport's timeout (10 seconds by default) applies to each socket
     operation. One message needs several: connecting, the SMTP greeting, TLS, authentication, and every
     command with its reply. Rendering and logging add to that.
   - **If a send outlasts the lease,** another batch can claim the row and send it again (at-least-once).
   - **Sizing `processingLeaseSeconds`** (minimum 1): keep it well above the longest render and send you
     observe, counting the configured transport timeout once for every SMTP operation of a message.
     A longer lease makes a duplicate from a slow send less likely. A shorter one recovers a stopped
     worker's rows sooner, which keeps time-limited messages, such as verification codes, useful when they
     arrive.
   - The 300-second default is the owner's choice for that balance.

## Consequences

- Delivery is at-least-once. A message can be sent twice when its send succeeds and its `sent` status
  cannot be recorded even after one reconnect, or when one render and send outlasts the lease. Size the
  lease as item 7 describes.
- One additive schema file, `005_email_queue_claim_index.sql`. It adds the claim index online
  (`ALGORITHM=INPLACE, LOCK=NONE`) and applies to every installation, fresh or existing. The worker works
  without it, but its claim then scans and locks the whole table, as 1.2.0 already does.
  - **Measured** on a 100,000-row table (90% `sent`, 8% `failed`, 1.5% `pending`, 0.5% `processing`),
    MySQL 8.0.46 and MariaDB 11.4 under REPEATABLE READ, 50-row batch:
    - without the index: a full scan with 100,825 rows locked, a claim transaction of about 55–75 ms,
      and every concurrent write waits, enqueue included;
    - with it: a range read locking only the 2,000 pending and processing rows (4,005 locks), a claim
      transaction of about 7–9 ms, and writes to finished rows do not wait.
  - **Still waiting** until the claim commits: a concurrent INSERT, because of gap locks under
    REPEATABLE READ, and writes to other `pending` or `processing` rows.
- For a `processing` row, `updated_at` now holds the worker-maintained lease time.
  It matches each writer's clock: `NOW()` for legacy rows and the UTC acceptance time for keyed rows.
  External scripts that select stale rows by `updated_at` now measure from the claim or renewal.
- Rows left `processing` by an earlier version keep their enqueue-time `updated_at`. The first upgraded
  batch claims the stale ones again and may send them a second time; review them before upgrading. Do
  not run older and newer workers on the same table: older workers neither stamp nor renew the lease.
- The worker changes no session setting on any connection. Session settings belong to the factory.
- Every row costs two more statements: the renewal and the ownership read. A retry/failed write that
  matches no row costs one more read. Each worker connection costs one autocommit read when first used.
- `PDO` instance users whose connection has autocommit off now get `UnexpectedValueException` before
  any claim, where 1.2.0 ran on with uncommitted status writes.
- Semantic versioning: additive and backward compatible within 1.x, so a minor release. The first
  parameter keeps its name and position and widens to a union. One optional trailing parameter and one
  public constant are added. The class stays `final` and is no longer declared `readonly`. The lease,
  the settlement at the cap, the fence and the post-send rule also apply to `PDO` instance users.
- For the worker, this supersedes ADR-010's statement that no interrupted-processing reclaimer exists.
  ADR-010 is not rewritten.

## Alternatives considered

- **Retry the whole batch or the claim transaction:** rejected. Repeating a partly executed transaction
  is unsafe.
- **A separate recovery script:** rejected. Rows stay stuck forever unless something schedules it.
- **Never send a stale row again (at-most-once):** rejected by the owner. A row interrupted before its
  send would never be delivered.
- **A new lease column:** rejected. `updated_at` already exists and the worker never wrote it, so no
  migration is needed.
- **One lease for the whole batch:** rejected. The lease would have to outlast every send in the batch.
- **Fence the `sent` write too:** rejected. A newer attempt that then fails would schedule a third send.

## Verification

The repository test `EmailQueueWorkerReconnectMysqlTest` runs against real MySQL/MariaDB:
- a send that outlasts a short session `wait_timeout`;
- a server-side close during the send;
- a database still unreachable after the reconnect;
- a `sent` write failing on a live connection at the first and the last attempt;
- a statement that ran but whose acknowledgement was lost (2013), for the `sent`, retry, failed and
  renewal writes;
- connections without autocommit, from a factory (attribute or init command), on reconnect, or as a
  plain `PDO`;
- the claim reading through the claim index while finished rows stay writable;
- the lease boundaries for both clocks in a non-UTC session;
- the settlement at the cap;
- the per-row renewal, including a renewal within the claim's second;
- the attempt fence;
- two workers racing on an expired lease (two sends, not three).

`EmailQueueWorkerConnectionContractTest` covers the constructor and factory contract.
