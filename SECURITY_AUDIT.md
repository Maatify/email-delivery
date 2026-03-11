# Security Audit

This document outlines the security architecture and safeguards implemented in the **Maatify Email Delivery** library.

## Encryption Model

All sensitive data queued for later delivery is protected at rest using robust cryptography.

- **Algorithm:** AES-256-GCM (Authenticated Encryption with Associated Data).
- **Library:** `maatify/crypto` implementation.
- **Fields Encrypted:** Both the recipient email address and the payload (template variables).
- **Integrity Check:** The `_tag` field ensures that if data in the queue is tampered with, the worker will fail to decrypt it rather than parsing malicious input.

The `CryptoProvider` uses isolated keys for the recipient (`emailQueueRecipient()`) and the payload (`emailQueuePayload()`), following the principle of least privilege.

## Queue Security

- The database connection (`PdoEmailQueueWriter`) strictly uses prepared statements, mitigating SQL injection risks during payload insertion and status updates.
- The worker uses row-level locking (`FOR UPDATE`) when fetching pending emails. This prevents race conditions and ensures that an email cannot be processed simultaneously by multiple workers or leaked accidentally.

## Transport Safeguards

- The `SmtpEmailTransport` leverages `PHPMailer`, which inherently sanitizes and escapes email headers and body content before transmission.
- TLS/SSL encryption is fully supported and recommended. Configuration is strictly defined through the `EmailTransportConfigDTO` to prevent runtime modifications or unencrypted downgrades.
