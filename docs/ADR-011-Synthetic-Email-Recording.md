# ADR-011: Isolated synthetic email recording

Status: implemented in development, unreleased. Scope: email-delivery #8; service study channel-delivery #5 remains separate.

## Context and decision

Consumers may need a real database-backed no-send operation when a public flow should not send mail.
The reusable validation/encryption/storage primitive belongs in this library. HTTP routing, account
existence decisions, authentication, rate limits, uniform responses and timing evaluation belong in
services. This decision adds an optional port and table, preserving historical ADR-008 and keyed ADR-010.

Use an explicitly synthetic request/result, append-only storage and a separate maintenance port.
Share bounded detached message validation with keyed acceptance without changing fingerprint v1.
Encrypt recipient/payload through public crypto APIs using role-separated versioned synthetic domains.
Store in an independent InnoDB table which the existing worker never reads. Do not reserve real request
identities, create real receipts/jobs or introduce a pending/dispatch state. Each service installs
this package-owned schema in its own database; the library does not execute migrations automatically.

Require explicit consumer retention and database UTC expiry with bounded external cleanup scheduling.
Own idle-connection transactions and report unknown commit outcomes conservatively; do not promise
synthetic exactly-once recording. Require the package schema without triggers/outgoing foreign keys.

## Alternatives and consequences

- A service-only duplicated implementation loses shared contracts and drift protection.
- Real queue rows with a fake status couple inert data to worker selection and future dispatch behavior.
- No-op/sleep cannot establish real database work or representative end-to-end timing.
- Synthetic idempotency/real key reservation would add misleading acceptance semantics and is unnecessary here.

One inert table adds storage and explicit maintenance work. Database and HTTP work distributions still
differ from actual acceptance. No constant-time or complete anti-enumeration claim follows from this API.
See the [reference](reference/synthetic-recording.md) for exact fields, exceptions, crypto domains and limits.

## Verification

Real MySQL/MariaDB tests exercise encrypted readback and rejection under real crypto domains, the real
worker with a positive fake-transport sensor and restart, queue/receipt isolation, UTC/expiry bounds,
caller transaction preservation, unknown commits, schema rejection and independent-process recording/cleanup.
Golden digests captured from main@e2bfc42 preserve the existing keyed v1 wire contract.
