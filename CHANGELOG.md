# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Optional atomic idempotent enqueue with explicit consumer namespace/request key, conflict detection,
  encrypted logical fingerprint and scoped durable receipt lookup (#4).
- Package-owned MySQL/MariaDB fresh schema and one-time additive upgrade, immutable job/receipt
  binding and indefinite receipt retention across queue purge/ID reuse.
- Real-DB fault/concurrency coverage and required MySQL checks in the PHP 8.2/8.3/8.4 CI matrix.

### Fixed
- Keyed jobs now use database UTC for worker due/retry selection, retry deadlines and sent timestamps,
  independent of PHP/PDO local zones (F5-01). Legacy rows and pre-003 tables retain their existing
  clock behavior; no SMTP/backoff policy changes.

### Compatibility
- Legacy void enqueue and NULL-token/pre-003 worker timing remain unchanged. Queue acceptance does not promise
  exactly-once SMTP or mailbox delivery. Adopt the schema before the new API; no version bump or release.

## [1.0.0] - 2026-03-11

### Added
- Queue system using PDO for durable email storage.
- Twig rendering support with automatic language injection.
- SMTP transport implementation backed by PHPMailer.
- Encryption support for queue payloads via `maatify/crypto`.
- Worker retry mechanism with exponential backoff for transient and permanent failures.

## [1.1.0] - 2026-05-17

### Added
- `EmailQueuePayloadDTO::replyTo` — optional Reply-To email address stored in the encrypted payload.
- `EmailTransportInterface::send()` — optional `$replyToEmail` parameter for Reply-To header support.
- `SmtpEmailTransport` — sets `Reply-To` header via PHPMailer's `addReplyTo()` when `replyToEmail` is provided.
- `EmailQueueWorker` — extracts `replyTo` from decoded payload and passes it to the transport.

### Changed
- `EmailQueuePayloadDTO::toArray()` now includes `replyTo` key (null when not set).
- Existing payloads without `replyTo` are handled gracefully via `?? null` (backward compatible).

## [1.1.1] - 2026-09-14

### Fixed
- Dynamic Twig subject values are decoded from the HTML-escaped block output before being exposed as the SMTP subject.
- HTML body escaping remains unchanged.
- Added regression coverage for special characters in dynamic subjects.
