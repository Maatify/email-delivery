# Encryption

The **Maatify Email Delivery** library utilizes a robust cryptographic mechanism to ensure that sensitive data handled during the email delivery process remains secure, specifically when stored within the queue database. The library seamlessly integrates with the `maatify/crypto` package to encrypt and decrypt the recipient email addresses and the payload data used to render the templates.

## Why Queue Payloads are Encrypted

The queue database (`cd_email_queue`) acts as a persistent buffer for pending emails. Because transactional emails often contain Personally Identifiable Information (PII), such as email addresses, user names, password reset tokens, order details, or financial information, storing this data in plaintext presents a significant security risk.

If the database were compromised due to an injection vulnerability, unauthorized access, or accidental exposure of backups, attackers could extract a wealth of sensitive information from the pending, processing, and failed email queues.

By encrypting the `recipient` and the `payload` fields before they are written to the database, the **Maatify Email Delivery** library ensures that the data is indecipherable without the corresponding cryptographic keys. This implements a crucial layer of defense in depth: even if the storage layer is compromised, the sensitive contents of the queued emails remain protected.

## How It Works

The library utilizes the `maatify/crypto` package for reversible encryption. When an application enqueues an email using the `PdoEmailQueueWriter`, the writer delegates the encryption of the recipient and payload to the provided `CryptoProvider`.

This process generates four pieces of data for both the recipient and the payload:

1.  **Ciphertext (`_encrypted`)**: The actual encrypted data.
2.  **Initialization Vector (`_iv`)**: A random value used to ensure that encrypting the same plaintext twice produces different ciphertexts.
3.  **Authentication Tag (`_tag`)**: A cryptographically generated tag used to verify the integrity and authenticity of the ciphertext (preventing tampering).
4.  **Key ID (`_key_id`)**: An identifier indicating which key was used for encryption, facilitating key rotation.

These values are stored in dedicated columns within the queue database table.

When the `EmailQueueWorker` picks up a pending job, it uses the `CryptoProvider` to reverse this process. It provides the ciphertext, IV, tag, and Key ID to the decryption algorithm. Only if all these components are valid and the correct key is available will the payload be successfully decrypted back into its original JSON format, ready for rendering. This ensures that the data is secure at rest within the queue database and only decrypted in memory just before it is needed to generate and send the email.
