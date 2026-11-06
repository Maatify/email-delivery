# Encryption and key retention

[Book](README.md) · [Security architecture](../../SECURITY_AUDIT.md) · [Keyed retention](../reference/idempotency.md#privacy-retention-and-purge)

Both writers encrypt recipient bytes and JSON template payload through the supplied
`maatify/crypto` `CryptoProvider`, using contexts from `emailQueueRecipient()` and `emailQueuePayload()`.
AES-256-GCM cipher/IV/tag/key ID are stored in binary-compatible columns. The worker decrypts those
fields before rendering. This authenticates encrypted material, not every plaintext row/routing field.
The library does not claim that it binds all metadata as AEAD associated data.

Crypto contexts provide domain separation; deployments may derive them from the same managed root
keys. They are not proof of independent master-key stores. Applications supply/configure the provider,
context policy and key rotation; the package does not read keys from environment files itself.

The keyed API stores an **encrypted digest** of logical request content in a separately supplied,
stable versioned context. A plaintext digest could expose short-secret guesses, so digest comparisons
happen in memory after public-API decryption. Replay uses the stored key ID, independent of fresh IVs
or the current active key. Keep old read keys/context versions for indefinitely retained receipts.
If a key is unavailable, replay fails closed rather than making another job; scoped lookup does not
decrypt the fingerprint and can still show acceptance.

Routing/entity IDs, template/language, timestamps/states, request namespace/key and receipt/job IDs are
visible metadata. Never place secrets/PII in request identities, and protect plaintext last-error/log data.
Field encryption does not replace database access controls, namespace authorization or log redaction.
