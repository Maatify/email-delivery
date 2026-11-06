# Security architecture description

This is a source-based description of boundaries and safeguards, **not an independent security audit,
penetration-test report or certification**. The historical filename is retained so existing links work.
Vulnerability reporting and supported versions remain governed by [SECURITY.md](SECURITY.md).

## Protected material and visible metadata

`maatify/crypto` protects recipient and template payload bytes using AES-256-GCM with IV/tag/key ID.
The keyed API additionally encrypts its logical digest using a separate versioned context. This avoids
leaving a plaintext short-secret guessing oracle in the receipt table.

Context separation derives different encryption domains; it does not establish independent master keys.
Authentication of ciphertext is not a promise that all row routing metadata is authenticated as AAD.
Do not infer row-level authorization or tamper-proof plaintext metadata from field encryption alone.

Entity/routing IDs, template/language, queue state/timing, namespace/request keys, receipt identity and
job identity are visible metadata. Keep PII/secrets out of those fields. `last_error` and logger reasons
can contain dependency error text; protect/redact logs and database access rather than assuming they
are encrypted or free of addresses. Do not log raw payloads, crypto material or SMTP credentials.

## Identity, transactions and retention

Keyed acceptance uses full binary namespace/key uniqueness, an owned transaction and an immutable
receipt/job binding. Lookup is scoped and exposes no recipient/context, but is **not authentication**;
the application must select and authorize the caller's namespace. Statement parameterization does not
replace authorization or trusted schema administration.

Retain receipts and fingerprint read keys/context versions for the indefinite-retention contract.
Missing keys fail closed for replay; lookup can still show acceptance. Queue purge must not erase durable
identities or make another job with a reused ID appear sent. See the [API reference](docs/reference/idempotency.md).

## Delivery and transport limits

Row locks serialize claiming pending jobs; the worker commits `processing` before SMTP. Locks do not
provide exactly-once external delivery. No automatic recovery/reclaim of interrupted `processing` jobs
is implemented. SMTP timeout can be ambiguous, and retrying a transport may send again.

`SmtpEmailTransport` configures PHPMailer with HTML content and optional Reply-To. It does not escape
arbitrary HTML body data itself or add a plain-text alternative. Use trusted templates and appropriate
Twig escaping. Explicit `tls` selects STARTTLS and `ssl` selects SMTPS; the configuration default is null,
and other values disable AutoTLS. Choose transport encryption explicitly; the DTO does not enforce a
mandatory-encryption policy. Keep debug output restricted because SMTP diagnostics may expose data.

[Documentation](docs/README.md) · [Encryption](docs/book/encryption.md) · [Transport](docs/book/transport.md)
