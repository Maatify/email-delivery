# ADR-010: Durable Idempotent Email Enqueue

**Status:** IMPLEMENTED IN DEVELOPMENT / UNRELEASED; technical implementation approved, release separate

**Date:** 2026-11-06

**Related:** [Issue #4](https://github.com/Maatify/email-delivery/issues/4), [locked historical ADR-008](ADR-008-Email-Delivery-Independent-Queue.md)

## Decision and boundaries

Add an explicit scoped-key enqueue and receipt capability without changing legacy `enqueue(): void`.
The independent email-channel ownership of ADR-008 remains. This ADR extends its historical locked
schema through a receipt table and nullable immutable queue binding; it does not rewrite ADR-008 or
introduce application notification orchestration, HTTP routing or SMTP exactly-once semantics.

## Implemented design

- Namespace/key uniqueness and an owned InnoDB transaction atomically commit receipt, encrypted job
  and binding. Matching replay returns the original identities; different logical content conflicts.
- A detached, type/order-sensitive snapshot feeds both encryption and fingerprinting. An encrypted
  digest avoids a plaintext short-secret guessing oracle; old read keys/context versions remain required.
- Caller transactions/autocommit-off are refused; failed commit or cleanup can be unresolved. Discard
  that connection and reconcile the same key on a fresh connection, not a compensating identity.
- Receipts are retained indefinitely and survive queue purge. A token plus numeric job ID prevents
  false association after ID reuse. `QueueMissing` neither means sent nor recreates a purged job.
- Keyed worker due/retry/sent clocks are database UTC. Legacy NULL-token and pre-003 paths retain
  their prior database-local/PHP-local timing. Per-batch metadata discovery changes no session setting.
- Backoff durations, attempt cap and SMTP policy remain; no interrupted-processing reclaimer exists.

## Normative reference and adoption

The [API reference](reference/idempotency.md) owns exact validation, canonicalization, exception,
receipt and retention rules. The [upgrade guide](upgrade.md) owns fresh 001+002 versus one-time
legacy 002 + 003 adoption, compatibility and rollback boundaries. No backfill or automatic schema repair.

## Consequences and status

Durable metadata and indefinite fingerprint read-key retention are explicit costs. Scoped lookup is
not authentication. External transport ambiguity and jobs left `processing` need application/operator
policy; this package does not close those gaps with a new key, automated reset or mailbox guarantee.

[Release preparation](release-preparation.md) requires candidate verification and an explicit maintainer
version/publication decision. Implemented/unreleased is not an independent audit, CI certificate or
statement that a published package already contains this work.
