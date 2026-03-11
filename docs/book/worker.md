# Worker

The **Maatify Email Delivery** library utilizes a robust worker process to asynchronously process and send transactional emails queued by your application. The `EmailQueueWorker` acts as the reliable engine that translates queued requests into sent messages.

## Worker Responsibilities

The worker's main job is to continuously pull pending jobs from the queue database (`cd_email_queue`) and carefully process them step-by-step. Its responsibilities include:

1.  **Decrypt Payload:** The worker retrieves the encrypted recipient and payload data from the database. It utilizes the `maatify/crypto` integration, providing the necessary cryptographic context, to decrypt the AES-256-GCM encrypted data. This involves verifying the `recipient_encrypted`, `recipient_iv`, `recipient_tag`, and `recipient_key_id` fields (along with their `payload_*` counterparts). If decryption fails (e.g., due to an invalid key or corrupted data), it throws a `RuntimeException`.
2.  **Decode Payload:** Once decrypted, the payload is parsed from its JSON format into an array structure, ready to be injected into the template.
3.  **Render Template:** The worker uses the `TwigEmailRenderer` to combine the context data (including an automatically injected `lang` variable) and the specified `templateKey` to produce the final `GenericEmailPayload` (HTML and plain-text).
4.  **Send Email:** With the rendered content, the worker hands the email over to the configured `EmailTransportInterface` (usually `SmtpEmailTransport`) which connects to the SMTP server and attempts transmission.
5.  **Retry Failures:** If any step fails—from decryption errors to SMTP connection timeouts—the worker gracefully handles the exception. It logs the failure and relies on an exponential backoff strategy to determine when to retry the job. The worker updates the `status`, `attempts`, and `retry_after` fields in the queue table. If the maximum number of attempts (4) is reached, the worker marks the job as `failed` permanently.

This robust isolation ensures that errors during rendering or transmission do not affect the main application flow, providing a highly resilient email delivery system.
