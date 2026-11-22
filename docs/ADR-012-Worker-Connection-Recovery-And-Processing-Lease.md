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
   - **Retry/failed write:** the fenced repeat matches no row. Only on this repeat after a reconnect does
     the worker read the row back, because only there can a first execution have applied with its
     acknowledgement lost.
     - **Recorded:** the row counts as this attempt's outcome only when it holds the same status, attempt
       number and error message. The write is then logged normally, and the first `retry_after` is kept
       and reported.
     - **Why status and attempt number are not proof:** another batch can settle the row `failed` with
       `processing_lease_expired` at the attempt cap without changing the attempt number. Such a row
       carries its own error message, so it is reported as not recorded by this attempt and left
       unchanged.
     - **First try:** a fenced write that matches no row is always reported that way, without a read.
   - **Keyed retry-clock read:** only a read.
   Before PHP 8.4, mysqlnd also raises a `Packets out of order` warning when it reads that disconnect.
   While these statements run, such warnings are held back so an application handler that converts
   warnings into exceptions cannot mask the `PDOException`. They are dropped once the loss is confirmed,
   because the worker logs that loss itself. Otherwise they are never raised again, so the original
   exception or result reaches the caller unchanged. Each is written to the worker's logger on a
   best-effort basis: if the logger itself throws, that failure is swallowed so that it cannot replace
   the original exception or result. Every other error, at any level, goes to the application's handler
   unchanged.
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
   rows and `processing` rows whose `updated_at` is older than the lease. It never locks a row another
   worker is still sending:
   - **Expired leases:** an unlocked read of the candidate ids in `(priority, id)` order with the batch
     limit, then a locking read of only those rows by primary key that re-checks the lease on their latest
     version.
   - **Due `pending` rows:** a locking read in `(priority, id)` order with the batch limit.
   - **Merge:** the first rows of both are kept.
   - **Why the expired-lease scan does not lock:** a locking scan of the `processing` range would lock
     every in-flight row (index entry and primary key), and another worker's outcome write locks the
     primary key first and the index entry second. The two then deadlock (measured: 1213).
   - **Why the order matters:** taking the primary-key locks before the pending read means the claim
     never waits for a primary key while it holds gap locks in the `pending` range.
   With `idx_email_queue_claim (status, priority, id)` from schema 005, or an existing index led by those
   columns, the reads walk the index in order and stop at the limit.
   The lease clock follows the type of `updated_at`, read from the column metadata in every batch:
   - **`DATETIME`** (the package schema): the row's own clock, `UTC_TIMESTAMP()` for keyed rows and
     `NOW()` for legacy rows.
   - **`TIMESTAMP`** (some application schemas): the lease is compared as instants,
     `UNIX_TIMESTAMP(updated_at) < UNIX_TIMESTAMP() - lease`. A TIMESTAMP holds an instant and converts it
     by session zone, so a wall-clock comparison is off by the zone offset across workers in different
     zones. It is also wrong across daylight saving jumps: just after a spring-forward jump it reclaims a
     row that is only seconds old, while a fall-back only delays the reclaim (measured on both engines).
   - **The `TIMESTAMP` stamp** stores the exact instant even in the repeated hour after a fall-back. On
     MySQL 8.0.19+ it is `CONCAT(UTC_TIMESTAMP(), '+00:00')`, because there MySQL stores any local-time
     expression as the earlier of the two instants. On MariaDB it is a directly assigned `NOW()`, which
     MariaDB stores exactly. Stamps from `ON UPDATE CURRENT_TIMESTAMP` are exact on both.
   - **Older MySQL:** before 8.0.19 the stamp falls back to `NOW()`, so a row stamped in that repeated hour
     can be claimed early in a session zone with daylight saving; use a fixed-offset or UTC session zone
     there.
   - **Legacy `DATETIME` rows** keep their documented local-clock contract.

   The claim stamps `updated_at`, and
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
- The stored error message is at most 128 bytes, cut on a UTF-8 character boundary (`mb_strcut`). A cut
  inside a multibyte character would fail under strict SQL mode, and the read-back compares the message
  exactly. Without the extension, `mb_strcut` comes from Twig's required `symfony/polyfill-mbstring`.
- One additive schema file, `005_email_queue_claim_index.sql`, for every installation that has no index
  led by `(status, priority, id)`. Fresh installs run 001, 002, then 005; legacy tables run 002, 003, then
  005; 1.2.0 installs that already applied 002 and 003 run only 005.
  - **Online, with limits:** it uses `ALGORITHM=INPLACE, LOCK=NONE`, so reads and writes continue while
    the index builds. It still takes a brief exclusive metadata lock at the start and end, waits for open
    transactions on the table, and briefly blocks sessions queued behind it. Run it off-peak; see the
    upgrade guide. Without such an index each of the two claim reads scans and locks the whole
  table, which is twice 1.2.0's single scan.
  - **Measured** on a 100,000-row table (90% `sent`, 8% `failed`, 1.5% `pending`, 0.5% `processing`),
    MySQL 8.0.46 and MariaDB 11.4 under REPEATABLE READ, 50-row batch, on the package schema and on an
    application schema that already has the index.
  - **1.2.0 without the index:** a full scan locking 100,825 rows in 56–68 ms.
  - **This version, with the index:** about 200 rows locked (only the rows it returns) in 5–8 ms.
    - MySQL `data_locks` shows no primary-key lock on any fresh in-flight row.
    - Enqueues at any priority, writes to finished rows and outcome writes on other workers' in-flight
      rows do not wait.
    - Only a write on a row the claim is reclaiming waits.
  - **This version, without the index:** the pending read locks the whole table, as in 1.2.0 (66–86 ms).
  - **Rejected designs:**
    - a single `pending OR processing` read needs a filesort and locked every active row;
    - a locking `processing` read locked every in-flight row and deadlocked with outcome writes.
- For a `processing` row, `updated_at` now holds the worker-maintained lease time.
  It matches each writer's clock: `NOW()` for legacy rows and the UTC acceptance time for keyed rows.
  External scripts that select stale rows by `updated_at` now measure from the claim or renewal.
- Rows left `processing` by an earlier version keep their enqueue-time `updated_at` on a `DATETIME`
  column; a `TIMESTAMP ... ON UPDATE CURRENT_TIMESTAMP` column already holds their claim time. The first
  upgraded batch claims the stale ones again and may send them a second time; review them before upgrading. Do
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
- a last attempt whose send fails after another worker settled its row at the cap, on the first try and
  on the repeat after a reconnect, plus a first-try write that never reads the row back;
- connections without autocommit, from a factory (attribute or init command), on reconnect, or as a
  plain `PDO`;
- the claim reading each status in index order, locking about a batch while finished rows stay writable;
- the claim taking no lock on 40 fresh in-flight rows, and a concurrent outcome write on an in-flight
  row never deadlocking with it (three connections);
- a failure reason cut inside a multibyte character, including on a lost-acknowledgement repeat;
- a `TIMESTAMP` lease across daylight saving spring-forward and fall-back in `America/New_York`, for the
  claim, renewal and `ON UPDATE` stamps;
- a `TIMESTAMP` `updated_at` in `+03:00` and `-05:00` sessions: fresh rows are never reclaimed from another
  zone, the lease boundary holds for keyed and legacy rows, and rows an older worker claimed (stamped
  by `ON UPDATE CURRENT_TIMESTAMP`) are judged by that stamp;
- the lease boundaries for both clocks in a non-UTC session;
- the settlement at the cap;
- the per-row renewal, including a renewal within the claim's second;
- the attempt fence;
- two workers racing on an expired lease (two sends, not three).

`EmailQueueWorkerConnectionContractTest` covers the constructor and factory contract.
