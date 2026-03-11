# Queue

The **Maatify Email Delivery** library utilizes a durable, queue-based mechanism to ensure reliable email delivery. Instead of immediately dispatching emails upon request, your application safely stores the intended message, recipient information, and metadata into a persistent queue storage.

## Queue Storage

The core component is the database table (`cd_email_queue`) which acts as the persistent storage layer. Using `PdoEmailQueueWriter`, your application enqueues email payloads. The queue writer creates a record with several important fields:

-   `recipient_encrypted`: The encrypted email address of the recipient.
-   `payload_encrypted`: The encrypted context data for rendering the email.
-   `status`: The current state of the message (e.g., `pending`, `processing`, `sent`, `failed`).
-   `priority`: The order of processing importance (lower values process first).
-   `scheduled_at`: The earliest time the worker should attempt to process the message.
-   `retry_after`: When the worker should next attempt a retry.
-   `attempts`: The number of times the worker has attempted to send the message.

This database-backed approach provides robust tracking and fault tolerance.

## Encrypted Payload

Security is paramount in transactional emails, especially when dealing with personally identifiable information (PII). Both the recipient's email address and the payload (the context variables used to render the template) are encrypted before they are stored in the queue.

When you enqueue an email, the library utilizes `maatify/crypto` (a reversible cryptography implementation). The payload undergoes AES-256-GCM encryption, producing a ciphertext (`_encrypted`), initialization vector (`_iv`), an authentication tag (`_tag`), and the ID of the key used (`_key_id`). This mechanism ensures that if your database is ever compromised, the sensitive contents of your queue remain unreadable without the corresponding cryptographic keys.

## Retry Logic

Because email delivery is an inherently unreliable process (SMTP servers can go offline, DNS lookups can fail, etc.), the queue worker employs a sophisticated retry mechanism.

If the worker encounters a transient error (e.g., an `EmailTransportException` due to an SMTP timeout), it does not discard the email. Instead, it catches the error, increments the `attempts` counter, and schedules the email for a future retry by updating the `retry_after` field in the database.

The retry logic is designed using an exponential backoff strategy:

1.  Transient network errors (like SMTP failures) trigger short, back-to-back retries, assuming the disruption is brief.
2.  More persistent errors (like encryption issues or template rendering failures) trigger longer delays between retries, giving administrators time to correct the underlying problem without overwhelming the system.

After a defined maximum number of attempts (defaulting to 4), the worker marks the email as permanently `failed`, recording the `last_error` for later inspection.
