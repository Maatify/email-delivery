# Maatify Email Delivery

[![Latest Version](https://img.shields.io/packagist/v/maatify/email-delivery.svg?style=for-the-badge)](https://packagist.org/packages/maatify/email-delivery)
[![PHP Version](https://img.shields.io/packagist/php-v/maatify/email-delivery.svg?style=for-the-badge)](https://packagist.org/packages/maatify/email-delivery)
[![License](https://img.shields.io/github/license/Maatify/email-delivery?style=for-the-badge)](LICENSE)

![PHPStan](https://img.shields.io/badge/PHPStan-Level%20Max-4E8CAE)

[![Changelog](https://img.shields.io/badge/Changelog-View-blue)](CHANGELOG.md)
[![Security](https://img.shields.io/badge/Security-Policy-important)](SECURITY.md)

![Monthly Downloads](https://img.shields.io/packagist/dm/maatify/email-delivery?label=Monthly%20Downloads&color=00A8E8)
![Total Downloads](https://img.shields.io/packagist/dt/maatify/email-delivery?label=Total%20Downloads&color=2AA9E0)

[![Security Audit](https://img.shields.io/badge/Security-Audited-green?style=for-the-badge)](SECURITY_AUDIT.md)

![Async Email](https://img.shields.io/badge/Email-Async%20Delivery-darkgreen?style=for-the-badge)
![SMTP](https://img.shields.io/badge/Transport-SMTP-orange?style=for-the-badge)
![Twig Templates](https://img.shields.io/badge/Templates-Twig-yellow?style=for-the-badge)
![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet?style=for-the-badge)

[![Install](https://img.shields.io/badge/Install-composer%20require-blue?style=for-the-badge)](https://packagist.org/packages/maatify/email-delivery)

![CI](https://github.com/Maatify/email-delivery/actions/workflows/ci.yml/badge.svg)

---

## Overview

**Maatify Email Delivery** is a standalone module for rendering, queueing, and sending transactional emails.

It provides:
- Async transactional email delivery
- Twig email rendering
- Queue-based delivery
- SMTP transport via PHPMailer
- Background worker processing
- Encrypted payload storage via `maatify/crypto`
- Framework-agnostic architecture

## Why This Library

This library solves several common problems in web applications:
- Synchronous email sending blocking requests and slowing down user responses.
- Unreliable delivery in high-volume systems when SMTP servers drop connections.
- Lack of templating systems for clean, maintainable transactional email layouts.
- Difficulty scaling email infrastructure.

By decoupling the process, the library introduces an robust **async email pipeline**.

## Features

- Async email queue
- Twig template rendering
- SMTP transport (PHPMailer)
- Background worker processing
- Encrypted queue payloads
- Retry mechanism for failed emails
- Framework-agnostic design
- Designed for transactional email systems

## Quick Example

```php
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\PdoEmailQueueWriter;

// 1. Initialize Queue Writer
$queueWriter = new PdoEmailQueueWriter($pdo, $cryptoProvider, $cryptoContext);

// 2. Create Payload
$payload = new EmailQueuePayloadDTO(
    templateKey: 'welcome',
    language: 'en',
    context: ['name' => 'John Doe']
);

$email = 'john.doe@example.com';

// 3. Enqueue the email
$queueWriter->enqueue(
    entityType: 'user',
    entityId: '123',
    recipientEmail: $email,
    payload: $payload,
    senderType: 1,
    priority: 10
);
```

## Architecture Overview

The email delivery pipeline relies on four main components:
- **Renderer:** Compiles data and Twig templates into HTML/Text content.
- **Queue Writer:** Securely encrypts and stores the payload in the database queue.
- **Worker:** A background process that decrypts, renders, and attempts delivery.
- **Transport:** The SMTP layer that physically sends the email.

```text
Application
      ↓
Queue Writer
      ↓
Database Queue
      ↓
Email Worker
      ↓
SMTP Transport
```

## Email Delivery Pipeline

```text
Request → Queue → Worker → SMTP → Recipient
```

This system intentionally decouples email sending from application requests. Your application immediately responds to the user while the background worker handles the potentially slow and error-prone process of rendering and SMTP transmission.

## System Diagrams

### Architecture
![Architecture](docs/assets/architecture-diagram.svg)

### Email Flow
![Email Flow](docs/assets/email-flow-diagram.svg)

### Worker Lifecycle
![Worker Lifecycle](docs/assets/worker-lifecycle-diagram.svg)

## Installation

```bash
composer require maatify/email-delivery
```

## Documentation

Book:
- [docs/book/README.md](docs/book/README.md)

Guides:
- [docs/how-to/README.md](docs/how-to/README.md)

Examples:
- [docs/examples/README.md](docs/examples/README.md)

## Ecosystem

This package is part of the **Maatify Ecosystem**.

It relies on the `maatify/crypto` dependency to provide robust encryption. Encryption is used specifically for queue payload security, ensuring that sensitive transactional data (like password reset tokens or PII) remains unreadable if the queue database is ever compromised.

## License

MIT License
