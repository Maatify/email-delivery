# Synthetic email recording

[Documentation](../README.md) · [Schema adoption](../upgrade.md#optional-synthetic-recording) · [Example](../examples/synthetic-email.php)

Introduced in 1.2.0 for [email-delivery #8](https://github.com/Maatify/email-delivery/issues/8),
the reusable storage slice of [channel-delivery #5](https://github.com/Maatify/channel-delivery/issues/5).
It is available in **v1.2.0**, and was not part of v1.1.1. Service HTTP behavior and timing measurements remain separate work.

## Contract

`SyntheticEmailRecorderInterface::record(SyntheticEmailRequestDTO)` appends one inert, encrypted
record and returns `SyntheticEmailRecordDTO(recordId, recordedAtUtc, expiresAtUtc)` only after a
confirmed database commit. Each call appends a new record. There is no request key, real job ID,
queue status, delivery receipt, lookup, deduplication or replay contract. The result confirms recording
only. An unknown commit followed by a fresh-connection retry can leave two inert records.

`PdoSyntheticEmailStore` implements that port and the separate trusted-maintenance port
`SyntheticEmailMaintenanceInterface::purgeExpired(int $limit = 100): int`.
Its constructor requires `PDO`, `CryptoProvider`, `CryptoContextProviderInterface`, and an explicit
`retentionSeconds` between 1 and 2147483647. There is no default business retention policy.
Inject the recorder port into request handlers; inject maintenance only into trusted operations.
Cleanup covers **all consumer namespaces**, so namespace is metadata, not an authorization boundary.

Request fields are `consumerNamespace`, `entityType`, nullable `entityId`, `recipientEmail`,
`EmailQueuePayloadDTO payload`, `senderType`, `priority` (default 5), and nullable `scheduledAt`.
The date is copied immutable. It is informational `requested_scheduled_at`, never a worker schedule.

The keyed and synthetic paths share detached message validation: nonempty namespace up to 128 bytes;
nonempty entity type up to 255; nullable entity ID up to 255; recipient 1..320; template/language
1..255; nullable Reply-To up to 320; sender/priority integers 0..2147483647. Root context keys must
be strings. Nested JSON-like values are copied, with finite numbers, UTF-8 strings, depth <=32,
<=10000 value nodes and <=1 MiB total context string/key bytes. Objects/resources/cycles exceeding
those limits are rejected. An explicit date must have whole seconds and UTC-normalized SQL year
1000..9999. This is bounded storage validation, not mailbox existence or address-syntax validation.
The existing keyed canonical fingerprint **v1 remains byte-for-byte unchanged**.

## Isolation and encryption

Apply optional [`004_email_synthetic_requests.sql`](../../schema/mysql/004_email_synthetic_requests.sql).
It creates only `cd_email_synthetic_requests`. Real queue/receipt tables are not required by the store.
No foreign keys, triggers, send status, attempts, real request-key reservation or handoff exist.
The unchanged `EmailQueueWorker` reads only `cd_email_queue`; it never scans synthetic storage.
Do not add application code that copies these records into the real queue.

Recipient and JSON payload (including Reply-To/context) are encrypted through the public crypto API
using AES-256-GCM, IV/tag/key ID and new versioned HKDF contexts:

- `email-delivery:synthetic:recipient:<sha256(trim(emailQueueRecipient()))>:v1`
- `email-delivery:synthetic:payload:<sha256(trim(emailQueuePayload()))>:v1`

The angle-bracket portion is replaced by lowercase hex SHA-256 of the configured real context string.
This hashes a **public domain name**, not message content or key material. Role separation and bounded
context names also support real contexts at the crypto library's 255-byte limit. Synthetic contexts
are rejected if they equal either configured real context. Ciphertexts fail authentication under
real queue contexts. This is key-derivation separation, not independent master keys or whole-row AAD.
Protect metadata: consumer/entity identifiers, template/language, sender/priority and dates are plaintext.
Applications may supply nonsensitive decoy message data; the library does not inspect account existence.

## Transactions and failure

Use a dedicated idle PDO, `ERRMODE_EXCEPTION`, autocommit=1, explicit session `+00:00` or `UTC`.
The library does not reconfigure the session or commit/roll back a caller transaction. It owns a
transaction and table metadata lock for recording/cleanup, checks the package InnoDB base-table
schema, exact required columns/indexes and absence of outgoing foreign keys/triggers, and never
silently migrates incompatible storage. Deploy the supplied schema and grant metadata visibility;
these checks are not protection against a privileged DBA or changes to application code.

`InvalidArgumentException` means bounded input/configuration validation failed.
`SyntheticEmailUnavailableException` covers unavailable crypto/database/schema operations.
`SyntheticEmailTransactionUnresolvedException` extends it: discard the connection and treat the
outcome as unknown, even if an exception followed an actual commit. No success or cleanup count is
returned when commit/transaction cleanup is unresolved. A retry on a fresh connection may append
another **inert** record. Do not convert that error into real email enqueue.

## Expiry and operations

Recording and expiry use database `UTC_TIMESTAMP(6)`, with expiry computed from the explicitly
configured retention. Return timestamps are UTC ISO strings with microseconds. `purgeExpired()`
deletes only records with `expires_at <= UTC_TIMESTAMP(6)`, ordered by expiry/ID, in a committed
batch of 1..1000 rows. It returns the confirmed count; unexpired rows and real receipts/jobs are untouched.

The application/service owns cleanup scheduling, quotas, authentication, authorization, access/log
redaction and capacity planning. Without invoking maintenance, expired rows remain stored; expiry
is eligibility for cleanup, **not guaranteed deletion at that instant**. Do not copy this retention
policy onto indefinitely retained real enqueue receipts. Disable synthetic callers before any
explicit operator removal of the optional table; real queue/receipt schema remains unchanged.

## Timing boundary

This primitive performs real validation, serialization, encryption, a database transaction and insertion.
It does not render, send SMTP, sleep, expose an HTTP endpoint, or promise constant-time behavior or
complete account-enumeration prevention. Its one-table append differs from keyed real acceptance.
The service must compare complete real/synthetic HTTP timing **distributions** under representative
load and keep response/auth/rate-limit behavior uniform where its product policy requires it.
The service study #5 remains open; library tests establish isolation and correctness, not timing equivalence.
