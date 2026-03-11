# Architecture

The **Maatify Email Delivery** library implements a decoupled, queue-based architecture for sending transactional emails asynchronously. This structure ensures your application remains responsive and resilient to transient delivery failures.

## The Full Pipeline

The entire email delivery process is broken down into several stages, moving from the initial request to the final transmission via SMTP.

### 1. Application

The process begins in your application. When an event occurs that requires an email (e.g., a user registration or an order confirmation), your application prepares the necessary data. This data includes the recipient's email address and any contextual variables required to render the email template (like the user's name or a password reset token).

### 2. Queue Writer

Your application interacts with a `Queue Writer` implementation (such as `PdoEmailQueueWriter`). Instead of sending the email immediately, the writer takes the recipient data and template context, encrypts them securely (using `maatify/crypto`), and creates a payload to be stored for later processing.

### 3. Queue Storage

The encrypted payload and metadata are inserted into the **Queue Storage**, typically a database table (`cd_email_queue`). This storage holds all pending emails. It tracks metadata like scheduled time, processing status (`pending`, `processing`, `sent`, `failed`), retry attempts, and priority. The storage acts as a reliable buffer, ensuring emails are not lost if the application crashes or the SMTP server is temporarily unavailable.

### 4. Worker

A background process, the **Worker** (`EmailQueueWorker`), continuously polls the queue storage for pending emails that are ready to be sent. When it finds pending records, it locks them for processing to prevent duplicate processing by other worker instances. The worker is responsible for decrypting the recipient and payload data.

### 5. Renderer

Once the payload is decrypted, the worker passes the context data and template key to the **Renderer** (`TwigEmailRenderer`). The renderer uses the Twig templating engine to generate the final HTML and plain-text versions of the email based on predefined templates.

### 6. SMTP Transport

Finally, the fully rendered email content is passed to the **SMTP Transport** (`SmtpEmailTransport`). The transport layer connects to the configured SMTP server (using PHPMailer under the hood) and transmits the email to the recipient. If the transmission is successful, the worker marks the queue record as `sent`. If it fails, the worker applies a retry logic with exponential backoff.
