# ADR-010: Durable Idempotent Email Enqueue

**Status:** PROPOSED; technically approved for local implementation, not released
**Date:** 2026-11-06
**Related:** [Issue #4](https://github.com/Maatify/email-delivery/issues/4), [ADR-008](ADR-008-Email-Delivery-Independent-Queue.md)

## Context and scope

The existing `EmailQueueWriterInterface::enqueue(): void` intentionally permits multiple identical jobs. It remains unchanged. A caller that loses an enqueue response needs an **explicit** namespace/key contract; automatically deduplicating content would violate existing consumers.

This additive decision extends ADR-008's locked schema through a separately owned receipt table and a nullable immutable queue binding. It does not amend ADR-008, change SMTP/worker policy, or introduce notification/business ownership into the package.

## API and acceptance

`IdempotentEmailQueueWriterInterface::enqueueOnce(EmailEnqueueRequestDTO)` returns a typed receipt only after an owned transaction successfully commits. `EmailEnqueueReceiptReaderInterface::find(namespace, key)` reads only that scope and returns no recipient, context, or error body. Namespace and key are exact bytes (1–128 and 1–191 bytes); callers must not embed secrets/PII. The package separates scopes; authentication and selection of the caller's scope belong to the consuming application.

The receipt contains an opaque receipt ID, original decimal-string job ID, UTC acceptance time, and current queue state. It confirms acceptance, not exactly-once SMTP or mailbox delivery. A missing queue row is explicitly `QueueMissing`, never inferred `Sent`.

## Logical content, snapshot, and privacy

Fingerprint version 1 includes namespace/key, entity identity, recipient, template/language/reply-to, context, sender type, priority, and schedule mode. Strings are exact; no address case folding or trimming occurs. Context preserves array iteration order and scalar types, because a template may iterate a map. Reordering therefore conflicts. Finite floats use their big-endian IEEE754 representation; integers, numeric strings, signed zero, and floats remain distinct.

Root context keys must remain strings; nested integer-key lists are allowed. The new API accepts bounded JSON-like scalar/array context, rejects objects/resources/non-finite values and recursion through a depth limit, and validates UTF-8 keys and values. Limits are depth 32, 10,000 values, and 1 MiB of context key/value string bytes. The legacy API is unaffected.

One detached snapshot supplies both fingerprint and encrypted job data. Dates are copied to `DateTimeImmutable`. Immediate scheduling is a stable logical token; one frozen UTC acceptance instant supplies the actual `DateTime` used for that job. Explicit schedules normalize the instant to UTC whole seconds; fractional seconds are rejected rather than silently truncated. Immediate and explicit are distinct even when their effective times happen to match.

A plaintext SHA256 digest could expose a short-secret guessing oracle. Only an AES-GCM **encrypted digest** is stored using the public `CryptoProvider` and a separately supplied, stable versioned fingerprint context. The package stores its cipher/IV/tag/key ID/algorithm/version, not plaintext canonical material or a plaintext digest. Replays decrypt using the recorded key ID and compare in memory. Retain read keys and context versions while receipts exist. If a key is removed, replay fails closed without enqueue; scoped lookup still returns acceptance without decrypting the fingerprint.

## Atomicity and connection ownership

The new PDO implementation requires idle `ERRMODE_EXCEPTION`, `autocommit=1`, and an explicit UTC session (`+00:00` or `UTC`). It never commits/rolls back a caller transaction or silently changes session settings. It validates transactional engines and full identity uniqueness before writing, while holding table metadata locks for its owned transaction.

A unique receipt reservation, encrypted queue insert, immutable binding, and receipt/job link commit together. Only the exact primary-key winner may replay; different content conflicts without replacement. Other SQL errors, including deadlocks, remain failures rather than duplicate success. All begin/execute/commit results are checked. Before committing a new winner, current receipt ID/job ID and the immutable queue binding must match the actual insertion exactly; non-strict SQL truncation cannot produce a false receipt.

Failed commit is unresolved even when the connection subsequently appears idle: the server may already have committed. Never compensate with a different key or report success from an uncommitted row. Discard an unresolved connection and recover with the **same namespace/key on a new connection**. Failed cleanup or a connection unexpectedly active after a failed begin is also unresolved; transactions whose ownership is unproved are not rolled back.

## Retention and immutable binding

Receipts are retained indefinitely in this initial contract; keys cannot be reused and no receipt pruner is provided. Queue deletion never cascades to a receipt. This costs durable metadata and retained fingerprint read keys. A future finite retention policy requires an explicit guarantee-window contract.

Numeric job IDs alone are not sufficient: deletion/TRUNCATE can allow reuse. New jobs therefore carry package-owned immutable `enqueue_receipt_id = receiptId`, unique when non-NULL. The reader joins both job ID and binding. Legacy inserts leave it NULL. The worker distinguishes keyed UTC timing from legacy local timing by this token; SMTP/backoff policy is unchanged. A reused ID for a legacy or another keyed job cannot make an old receipt appear sent. Replay after queue purge returns the original receipt with `QueueMissing`, and never recreates the job. Consumers must not update the binding or manually delete receipts.

## Package-owned schema adoption

- Fresh install: apply `schema/mysql/001_email_queue.sql`, then `002_email_enqueue_receipts.sql`.
- Existing legacy queue: apply `002_email_enqueue_receipts.sql` and the **one-time** `003_email_queue_receipt_binding.sql` before adopting the keyed API. Do not apply 003 after fresh 001.
- 003 uses portable `ALTER TABLE ADD COLUMN/ADD UNIQUE KEY`, not MySQL-incompatible `ADD COLUMN IF NOT EXISTS`. Deployments own migration bookkeeping; no generic migration runner is introduced.
- No legacy backfill or deduplication occurs. `CREATE TABLE IF NOT EXISTS` is not a compatibility proof: runtime schema validation fails closed on incompatible engines/identity constraints instead of altering data.

## Worker timing compatibility — F5-01

The keyed writer stores UTC datetimes. Workers therefore compare keyed due/retry times and write
keyed `sent_at`/retry deadlines using the database UTC clock, independent of the worker session/PHP
zone. NULL-token rows preserve the original local `NOW()`/PHP `date()` behavior. The worker discovers
the optional column from result metadata inside each batch transaction, retaining old MySQL pre-003
and SQLite legacy compatibility without changing session timezone or caching the schema. Metadata
failure is not treated as column absence. Retry delay tables, attempt caps and SMTP policy do not change.

## Verification and remaining delivery limits

Package-owned MySQL/MariaDB tests cover explicit first/replay/conflict/scopes, transaction faults and concurrency, missing keys, queue purge/ID reuse, schema upgrade, and legacy compatibility; unit tests cover the snapshot/canonical codec. CI runs PHP 8.2/8.3/8.4 and required real-DB tests. These are implementation acceptance requirements; individual results are recorded in the review evidence, not implied by this proposed ADR.

HTTP idempotency headers, authenticated tenant routing, application send intent, and SMTP delivery receipts are separate adoption work. This package does not promise exactly-once external delivery.
