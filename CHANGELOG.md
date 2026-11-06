# Changelog

Changes follow [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and
[Semantic Versioning](https://semver.org/spec/v2.0.0.html). This file's Unreleased section does not
publish a version. See [release preparation](docs/release-preparation.md).
Published dates below use the corresponding GitHub Release publication date in UTC.

## [Unreleased]

<a id="120"></a>

## [1.2.0] - 2026-11-06

### Added
- Complete consumer reference, canonical usage guide/root examples, contribution/conduct files and llms.txt.
- Separate archive verification and post-publication verification tooling; publication remains owner-controlled.
- Optional encrypted no-send recording (#8), distinct synthetic DTO/ports, independent optional schema004,
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
