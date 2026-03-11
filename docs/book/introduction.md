# Introduction

Welcome to **Maatify Email Delivery**, a robust, secure, and framework-agnostic PHP module designed for rendering, queueing, and sending transactional emails.

## Purpose of the Library

The primary goal of this library is to decouple email rendering and delivery from the main application flow. Sending emails directly during a web request can lead to:

- Slower response times for users.
- Increased risk of failing requests if the SMTP server is down or slow.
- Lost emails if an error occurs during the delivery process.

By utilizing a queue-based architecture, **Maatify Email Delivery** ensures that emails are sent asynchronously in the background. This guarantees that your application remains fast and responsive, while the email delivery process is handled reliably with built-in retry mechanisms and exponential backoff.

## The Email Delivery Pipeline

The library implements a clean and robust pipeline to process emails:

1. **Application:** The entry point. Your application provides the necessary context (e.g., recipient data, order details) to generate an email payload.
2. **Queue Writer:** The application uses a queue writer (`PdoEmailQueueWriter`) to safely store the email payload and recipient information in a database queue. The payload and recipient details are securely encrypted before being saved.
3. **Queue Storage:** The database table (`cd_email_queue`) acting as the reliable storage for pending emails. It holds encrypted payloads, tracking data, and retry counters.
4. **Worker:** A background process (`EmailQueueWorker`) continuously polls the queue storage for pending emails.
5. **Renderer:** The worker decrypts the payload and passes the context to a renderer (like `TwigEmailRenderer`). The renderer uses Twig templates to generate the final HTML/Text email content.
6. **SMTP Transport:** Finally, the rendered email is handed over to the transport layer (`SmtpEmailTransport`), which delivers it to the designated recipient via SMTP.

This pipeline ensures that each step is isolated, secure, and resilient to transient failures.
