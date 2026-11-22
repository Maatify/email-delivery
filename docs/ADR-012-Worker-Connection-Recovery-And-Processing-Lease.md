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
2. **One reconnect, only around statements that are safe to repeat.** These are autocommit statements
   outside any transaction: the per-row lease renewal and ownership read, the `sent` write, and the
   retry/failed writes with the keyed retry-clock read. On driver code 2006 (server has gone away), 2013
   (lost during a statement) or 4031 (disconnected for inactivity, how MySQL 8.0.24+ reports an expired
   `wait_timeout`; MariaDB reports 2006), the worker reconnects and runs them exactly once more. Any
   other error propagates unchanged. Applied twice, each of these statements leaves the same row state,
   which is why 2013, whose first execution may have applied, is safe here.
3. **Never around a transaction.** The claim is never retried. Before `beginTransaction()` the worker
   checks its connection with `SELECT 1` and replaces it if the server closed it; nothing has run yet.
4. **A send is never followed by a retry write.** After a successful send, a failed `sent` write never
   writes `pending`/`failed` and never counts as a send failure. The worker logs
   `Email sent but status write failed` with the job and attempt; that line is the record of the send.
   The row stays `processing` and the lease decides what happens next: sent again under the cap, or settled
   `failed`/`processing_lease_expired` at it. A connection still lost after one reconnect is rethrown,
   so the batch stops, no further mail goes out on a dead link, and the caller handles it. The batch's
   remaining claimed rows then wait one lease. Any other error continues with the next row.
5. **Processing lease in the claim (owner decision: at-least-once).** The claim selects due `pending`
   rows and `processing` rows whose `updated_at` is older than the lease, compared with the row's own
   clock: `UTC_TIMESTAMP()` for keyed rows, `NOW()` for legacy rows. The claim stamps `updated_at`, and
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
7. **Default lease 300 seconds, configurable (owner decision).** Because the lease is renewed before
   each message, it only has to outlast one render and send. The transport's default 10-second timeout
   per socket operation keeps a hung send well below it. A short lease keeps time-limited messages, such
   as verification codes, useful when they arrive. Consumers pass `processingLeaseSeconds` (minimum 1).

## Consequences

- Delivery is at-least-once. A message can be sent twice when its send succeeds and its `sent` status
  cannot be recorded even after one reconnect, or when one render and send outlasts the lease. Choose
  a lease above the longest expected render and send.
- No schema change. For a `processing` row, `updated_at` now holds the worker-maintained lease time.
  It matches each writer's clock: `NOW()` for legacy rows and the UTC acceptance time for keyed rows.
  External scripts that select stale rows by `updated_at` now measure from the claim or renewal.
- Rows left `processing` by an earlier version keep their enqueue-time `updated_at`. The first upgraded
  batch claims the stale ones again and may send them a second time; review them before upgrading. Do
  not run older and newer workers on the same table: older workers neither stamp nor renew the lease.
- The worker changes no session setting on any connection. Session settings belong to the factory.
- Every row costs two more statements: the renewal and the ownership read.
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
- the lease boundaries for both clocks in a non-UTC session;
- the settlement at the cap;
- the per-row renewal, including a renewal within the claim's second;
- the attempt fence;
- two workers racing on an expired lease (two sends, not three).

`EmailQueueWorkerConnectionContractTest` covers the constructor and factory contract.
