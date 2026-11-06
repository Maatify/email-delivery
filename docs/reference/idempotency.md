# Keyed enqueue and receipt reference

[Documentation](../README.md) · [Upgrade](../upgrade.md) · [Typed examples](../examples/README.md)

This additive API is implemented in the development checkout and documented under
[prepared 1.2.0 target](../../CHANGELOG.md#120). Legacy `enqueue(): void` deliberately continues to insert
on each call. There is no content-only deduplication or backfill of legacy jobs.

## Ports and construction

`Maatify\EmailDelivery\Queue\PdoIdempotentEmailQueue` implements:

- `IdempotentEmailQueueWriterInterface::enqueueOnce(EmailEnqueueRequestDTO $request): EmailEnqueueReceiptDTO`
- `EmailEnqueueReceiptReaderInterface::find(string $consumerNamespace, string $requestKey): ?EmailEnqueueReceiptDTO`

Its constructor takes `PDO`, `CryptoProvider`, `CryptoContextProviderInterface`, and a separate
`string $fingerprintContext`. Configure a stable versioned crypto context such as
`email:queue:enqueue-fingerprint:v1`; keep it and the read keys available across retries and upgrades.
Use an idle InnoDB connection with `PDO::ERRMODE_EXCEPTION`, `autocommit=1`, and session timezone
explicitly `+00:00` or `UTC`. The library does not change session settings or join caller transactions.
The reader has the same connection/schema preconditions even though it does not decrypt a fingerprint.

## Identity and request fields

`EmailEnqueueRequestDTO` is readonly; its constructor accepts:

| Field | Contract |
| --- | --- |
| `consumerNamespace`, `requestKey` | Nonempty exact bytes; at most 128 / 191 bytes respectively; no trim/case folding |
| `entityType`, `entityId` | Nonempty type up to 255 bytes; nullable ID up to 255 bytes |
| `recipientEmail` | Nonempty, at most 320 bytes; validation here is not mailbox verification |
| `payload` | `EmailQueuePayloadDTO(context, templateKey, language, replyTo=null)` |
| `senderType`, `priority` | Integers 0..2147483647; priority defaults 5, lower values run first |
| `scheduledAt` | Optional `DateTimeInterface`; copied to immutable; null means Immediate |

Template key/language must be nonempty and at most 255 bytes; nullable Reply-To is at most 320 bytes.
Keys are stored as binary identities and may differ by case or trailing spaces. Use opaque stable
identifiers rather than addresses, tokens or personal information. The package's scope separation is
**not authorization**: the consuming service must select the authenticated namespace and protect lookup.

## Logical content and snapshot

Version 1 fingerprints namespace/key, entity identity, recipient, template/language/Reply-To, context,
sender type, priority and schedule mode before encryption. Generated timestamps, ciphertext/IVs,
attempts, queue status and error text are not logical content.

Strings keep their exact values. Root context keys must be strings; nested lists/integer keys are
allowed. Array iteration order and scalar types are significant: reordered maps, integer versus float,
numeric strings, signed float zero and changed case/whitespace can conflict. This preserves template
iteration semantics instead of silently sorting content. Finite floats use big-endian IEEE754 encoding.

The keyed API accepts null/bool/int/finite-float/UTF-8-string and nested arrays. It rejects objects (including closures),
resources, non-finite floats, invalid UTF-8 keys/values and excessive structure. Limits are
depth 32, 10,000 visited context values (including nested arrays) and 1 MiB of context key/value string bytes. These are keyed API rules,
not a retrofit of the legacy writer. One detached snapshot supplies both the fingerprint and encrypted
job, so mutable date objects or nested references cannot change the actual job after fingerprinting.

### Schedule identity

- `null` is the stable Immediate mode. The first winner materializes one UTC acceptance instant for
  scheduling; a later retry with null does not use a new time in the fingerprint or reschedule the job.
- Explicit dates normalize to the same UTC instant at whole-second precision. Equivalent offsets replay.
  Nonzero microseconds and years outside 1000..9999 are rejected rather than silently truncated.
- Immediate and explicit remain different modes even if their effective times happen to coincide.

## Atomic acceptance and conflicts

A unique `(consumer_namespace, request_key)` reservation, encrypted job insert, immutable queue token
and receipt/job link commit together. Results from begin/execute/commit and the new link are checked.
A matching existing winner returns its original receipt/job identity without inserting again. Different
logical content raises conflict and never replaces the winner. Unrelated SQL errors, including deadlocks,
are not interpreted as duplicate success.

The schema validator checks transactional engines and full binary identity/unique constraints before
writes while holding table metadata locks. It does not silently repair an incompatible installation.

## Receipt and lookup

`EmailEnqueueReceiptDTO` implements `JsonSerializable` and returns:

| PHP property | JSON field | Meaning |
| --- | --- | --- |
| `receiptId` | `receipt_id` | Opaque immutable receipt identity |
| `jobId` | `job_id` | Original unsigned decimal-string job identity |
| `acceptedAt` | `accepted_at` | Original UTC acceptance time in ISO format |
| `queueState` | `queue_state` | Current enum value: `pending`, `processing`, `sent`, `failed`, `queue_missing` |

No recipient, context or last-error text is exposed. `find()` returns null only when no receipt exists
for that namespace/key; query failures throw instead. Lookup needs no fingerprint decryption key.
`sent` is the current queue marker after transport returned, not proof of mailbox delivery or reading.

## Error handling and unresolved outcomes

| Exception | Caller action |
| --- | --- |
| `InvalidArgumentException` | Fix invalid input; do not silently change content on an already used key |
| `EmailEnqueueConflictException` | Investigate identity/content mismatch; no overwrite or automatic new key |
| `EmailEnqueueUnavailableException` | No acceptance is confirmed; preserve the key when recovering |
| `EmailEnqueueTransactionUnresolvedException` | Discard connection; reconcile the same namespace/key on a fresh connection |

All enqueue exceptions except validation are under `Maatify\EmailDelivery\Exception`; unresolved is
also an unavailable subtype, and conflict extends the legacy write exception. Catch specific classes
before their bases. The reader throws validation/unavailable/unresolved errors as well.

A failed commit may have reached the server even if the client received an error. Neither an apparently
idle connection nor a subsequent rollback proves nonacceptance. The implementation does not roll back
transactions whose ownership is unproved. Do not continue using an unresolved connection, release a
historical identity or compensate with a different key. Fresh-connection replay can return the committed
winner or create the previously unaccepted request under the same identity.

## Privacy, retention and purge

The digest is encrypted with the public `CryptoProvider`, AES-GCM and a separate versioned context;
no plaintext digest/canonical material is stored as a short-secret guessing oracle. Key ID/algorithm/IV/tag
are recorded. New accepts use the active key; replay reads the recorded key. Missing old read keys fail
closed for replay, while lookup can still show acceptance. Context separation need not mean separate
master keys. See [encryption](../book/encryption.md).

Receipts have **indefinite retention** in this initial contract. No automatic receipt pruner exists; do not
delete them or reuse their keys. Retain fingerprint read keys/context versions accordingly. Queue purge
must not cascade into receipts. The package-owned immutable nullable `enqueue_receipt_id` binds each
keyed job to its receipt, in addition to the numeric job ID. Reusing a numeric ID for a legacy/new job
cannot make the original receipt appear sent. After purge, replay returns `queue_missing` without
recreating the job. Missing is never inferred sent or unaccepted.

The guarantee is one accepted/enqueued job for an explicit request identity under this retention
contract. It does not guarantee exactly-once SMTP or repair interrupted `processing` jobs. HTTP
idempotency, caller durable intent and external status receipts require adoption outside this package.
