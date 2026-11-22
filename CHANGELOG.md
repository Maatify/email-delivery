# Changelog

Changes follow [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). This file's Unreleased section does not
publish a version. See [release preparation](docs/release-preparation.md).
Published dates below use the corresponding GitHub Release publication date in UTC.

## [Unreleased]

### Added
- `EmailQueueWorker` accepts a connection factory, `Closure(): PDO`, as its first argument (#13). It
  opens its connection on first use and replaces one the server closed: before the claim transaction,
  and once around each single status statement after driver error 2006, 2013 or 4031. A `PDO` instance
  is still accepted and cannot reconnect. See [ADR-012](docs/ADR-012-Worker-Connection-Recovery-And-Processing-Lease.md),
  which awaits the owner's acceptance.
- Processing lease (#13): a `processing` row whose `updated_at` is older than the lease is claimed
  again by a later batch, under the attempt cap as a new attempt that is sent again. At the cap it is
  settled `failed` with `processing_lease_expired`, without a send. The claim stamps the lease and
  each row renews it right before its send. Default `EmailQueueWorker::DEFAULT_PROCESSING_LEASE_SECONDS`
  (300 seconds); configurable through the optional `processingLeaseSeconds` argument.
  - The claim reads its candidates without locks (expired leases and due `pending` rows), then locks
    only those rows, by primary key in one statement, re-checking them on their latest version. It never
    locks a `processing` row whose lease has not expired, nor a row that is not due yet, so outcome
    writes on in-flight rows, reschedules and enqueues do not wait for it. Under at-least-once delivery,
    a row whose lease expired while its first send was still running can be locked and claimed again.
  - On a `TIMESTAMP` `updated_at` (some application schemas) the lease is compared as instants and
    stamped exactly, so session zones, daylight saving changes and `ON UPDATE CURRENT_TIMESTAMP` stamps
    do not move it. On MySQL before 8.0.19, use a fixed-offset or UTC session zone. A `DATETIME` column
    keeps each row's own clock.
- Schema `005_email_queue_claim_index.sql` (#13): an online claim index, `idx_email_queue_claim (status,
  priority, id)`, for every installation without an index led by those columns. With it each candidate
  read walks its status in index order and stops at the batch limit, instead of scanning the table.
  On a 100,000-row mixed table a 50-row claim's reads take 7–9 ms with it and 50–56 ms without it; either
  way it locks only its candidates. See the [worker book](docs/book/worker.md#claim-index-and-locks).
- The worker checks that each MySQL/MariaDB connection has autocommit enabled before using it (#13).

### Fixed
- A send that outlasts the server's idle timeout no longer leaves its row `processing` forever, and
  the following batches no longer fail on the closed connection (#13).
- A failed `sent` write no longer reschedules (`pending`) or fails (`failed`, at the last attempt) a
  message that was already sent. It is logged as `Email sent but status write failed` and the row is
  left to its lease. A connection still lost after one reconnect is rethrown and stops the batch;
  any other error continues with the next row.
- Retry/failed writes are fenced by attempt, so a stale attempt never overwrites a newer outcome.
- A retry/failed write that fails with an error other than a lost connection, such as a lock wait, no
  longer stops the whole batch. It is logged as `Email job failure was not recorded`, its row is left
  to its lease, and the next row is processed.
- Before PHP 8.4, the driver's disconnect warning (`Packets out of order`) no longer reaches the
  application during these statements, so a handler that turns warnings into exceptions no longer
  hides the lost connection and blocks the reconnect. A held warning that does not accompany a lost
  connection is never raised again, so the original exception or result reaches the caller unchanged; it
  is logged (`Database driver warning during an email queue statement`) on a best-effort basis.
- The stored error message is cut on a UTF-8 character boundary (at most 128 bytes). A cut inside a
  multibyte character made the retry/failed write fail under strict SQL mode, so the failure was never
  recorded.
- A status write repeated after its acknowledgement was lost (2013) has a defined effect. `sent_at`
  keeps the first recorded send time. On the repeat after a reconnect, a retry/failed write that already
  applied counts as recorded and keeps its first `retry_after`. It must match this attempt's status,
  attempt number and error message, so a row another batch settled at the cap is still reported as not
  recorded by this attempt.

### Compatibility and limits
- Semantic versioning: additive and backward compatible within 1.x (a minor release). The first
  parameter keeps its name and position and widens to `PDO|Closure`; a trailing optional parameter
  and a public constant are added. The class stays `final` and is no longer declared `readonly`.
- `symfony/polyfill-mbstring` (`^1.3`) is now a direct requirement, for `mb_strcut` without the mbstring
  extension. Twig already requires it with the same constraint, so installed packages and platform
  requirements do not change.
- Delivery is at-least-once: a message may be sent twice when its `sent` status cannot be recorded
  even after one reconnect, or when one render and send outlasts the lease.
- One additive index (`005`). Fresh installs run 001, 002, then 005; legacy tables run 002, 003, then 005;
  1.2.0 installs that already applied 002 and 003 run only 005. It is built with `ALGORITHM=INPLACE,
  LOCK=NONE`: reads and writes continue, but it still takes brief metadata locks and waits for open
  transactions, so run it off-peak. The worker works without it, but its candidate reads then scan the
  table (without locks). Rows left `processing` by an earlier version become claimable on the first
  upgraded batch and may be sent again; review them first, and do not run older and newer workers on
  the same table. Each row now costs two more statements (lease renewal and ownership read).
- `PDO` instance users whose connection has autocommit off now get `UnexpectedValueException` before
  any claim. Factory connections are checked the same way, and the worker never changes the setting.
- The worker now writes `updated_at` when it claims a row and before each send. External scripts that
  select stale `processing` rows by `updated_at` therefore measure from the claim or renewal, not from
  enqueue time.

<a id="120"></a>

## [1.2.0] - 2026-11-06

### Added
- Complete consumer reference, canonical usage guide/root examples, contribution/conduct files and llms.txt.
- Separate archive verification and post-publication verification tooling; publication remains owner-controlled.
- Optional encrypted no-send recording (#8), distinct synthetic DTO/ports, independent optional schema 004,
  explicit consumer retention and bounded expired-record cleanup. No real jobs/receipts or timing guarantee.
- Optional explicit namespace/key atomic enqueue, content conflict detection, encrypted logical digest
  and scoped durable receipt lookup (#4). Legacy void enqueue remains one insert per call.
- Package-owned MySQL/MariaDB fresh/upgrade SQL, immutable receipt/job binding, and indefinite receipt
  retention across queue purge/ID reuse. See [adoption instructions](docs/upgrade.md).
- Real-DB transaction/fault/concurrency coverage and required MySQL checks in PHP 8.2/8.3/8.4/8.5 CI.
- Consolidated [keyed API reference](docs/reference/idempotency.md), reusable dependency-injected
  examples, truthful security architecture description, diagrams and next-release preparation guide.

### Fixed
- Keyed worker due/retry comparisons, retry deadlines and sent timestamps use database UTC independent
  of PHP/PDO local zones (F5-01). Legacy NULL-token/pre-003 rows retain their prior clock behavior.

### Compatibility and limits
- PHP 8.5 joins the required CI matrix; the package requirement remains `^8.2` and Composer platform remains `8.2.0`.
- Adopt schema before keyed callers; no synthetic identities/backfill for legacy jobs.
- Queue acceptance is not exactly-once SMTP or mailbox delivery. No automatic interrupted-processing
  recovery or receipt pruner is added. Existing retry delays/cap and SMTP policy remain.
- Package publication and production schema migration are separate adoption steps; see the release preparation guide.

## [1.1.1] - 2026-09-14

### Fixed
- Dynamic Twig subject values are decoded from the HTML-escaped block output before being exposed as the SMTP subject.
- HTML body escaping remains unchanged.
- Added regression coverage for special characters in dynamic subjects.
## [1.1.0] - 2026-05-17

### Added
- `EmailQueuePayloadDTO::replyTo` — optional Reply-To email address stored in the encrypted payload.
- `EmailTransportInterface::send()` — optional `$replyToEmail` parameter for Reply-To header support.
- `SmtpEmailTransport` — sets `Reply-To` header via PHPMailer's `addReplyTo()` when `replyToEmail` is provided.
- `EmailQueueWorker` — extracts `replyTo` from decoded payload and passes it to the transport.

### Changed
- `EmailQueuePayloadDTO::toArray()` now includes `replyTo` key (null when not set).
- Existing payloads without `replyTo` are handled gracefully via `?? null` (backward compatible).

## [1.0.0] - 2026-03-12

### Added
- Queue system using PDO for durable email storage.
- Twig rendering support with automatic language injection.
- SMTP transport implementation backed by PHPMailer.
- Encryption support for queue payloads via `maatify/crypto`.
- Worker retry mechanism with exponential backoff for transient and permanent failures.

[Unreleased]: https://github.com/Maatify/email-delivery/compare/v1.2.0...HEAD
[1.2.0]: https://github.com/Maatify/email-delivery/releases/tag/v1.2.0
[1.1.1]: https://github.com/Maatify/email-delivery/releases/tag/v1.1.1
[1.1.0]: https://github.com/Maatify/email-delivery/releases/tag/v1.1.0
[1.0.0]: https://github.com/Maatify/email-delivery/releases/tag/v1.0.0
