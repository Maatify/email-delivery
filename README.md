<div align="center">

# Maatify Email Delivery

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://www.maatify.dev/company-profile/maatify_logo_white.svg">
  <source media="(prefers-color-scheme: light)" srcset="https://www.maatify.dev/company-profile/maatify_logo_dark.svg">
  <img alt="Maatify.dev" src="https://www.maatify.dev/company-profile/maatify_logo_dark.svg">
</picture>

[![Status](https://img.shields.io/badge/Status-Stable-blue)](https://packagist.org/packages/maatify/email-delivery) [![Latest Version](https://img.shields.io/packagist/v/maatify/email-delivery.svg)](https://packagist.org/packages/maatify/email-delivery) [![PHP](https://img.shields.io/badge/PHP-%5E8.2-8892BF)](composer.json) [![License](https://img.shields.io/badge/License-MIT-green)](LICENSE) [![PHPStan](https://img.shields.io/badge/PHPStan-Level%20Max-4E8CAE)](https://github.com/Maatify/email-delivery/actions/workflows/ci.yml) [![CI](https://github.com/Maatify/email-delivery/actions/workflows/ci.yml/badge.svg)](https://github.com/Maatify/email-delivery/actions/workflows/ci.yml)

[![Packagist](https://img.shields.io/badge/Packagist-maatify%2Femail--delivery-F28D1A)](https://packagist.org/packages/maatify/email-delivery) [![Monthly Downloads](https://img.shields.io/packagist/dm/maatify/email-delivery.svg)](https://packagist.org/packages/maatify/email-delivery) [![Total Downloads](https://img.shields.io/packagist/dt/maatify/email-delivery.svg)](https://packagist.org/packages/maatify/email-delivery) [![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet)](https://github.com/Maatify) [![Install](https://img.shields.io/badge/Install-Composer-blue)](https://packagist.org/packages/maatify/email-delivery)

[![Documentation](https://img.shields.io/badge/Documentation-Index-blue)](docs/README.md) [![Usage Guide](https://img.shields.io/badge/Usage-Guide-blue)](docs/guides/USAGE_GUIDE.md) [![Examples](https://img.shields.io/badge/Examples-PHP-blue)](examples/README.md) [![Package Reference](https://img.shields.io/badge/Package-Reference-blue)](docs/reference/PACKAGE_REFERENCE.md) [![Keyed API Reference](https://img.shields.io/badge/Keyed%20API-Reference-blue)](docs/reference/idempotency.md) [![Changelog](https://img.shields.io/badge/Changelog-View-blue)](CHANGELOG.md) [![Security Policy](https://img.shields.io/badge/Security-Policy-important)](SECURITY.md)

A framework-independent PHP library for an encrypted email queue, Twig HTML rendering and SMTP
transport through PHPMailer. Applications enqueue work; a separately run worker attempts delivery.

</div>

---

Release artifact target: **1.2.0**. Publication state: **published**.
[Version 1.2.0](https://github.com/Maatify/email-delivery/releases/tag/v1.2.0) was published on
2026-11-06 UTC and verified as an exact Composer dist installation. Its immutable qualified source is
`8e0fc1e2995fb52d1e0e5e299eea2203ccd56c2d`.

The tagged artifact retains its historically valid pre-publication qualification snapshot. This live
repository records later publication facts; it does not replace the tag or change the distributed artifact.

## Start here

- [Usage Guide](docs/guides/USAGE_GUIDE.md) and [root examples](examples/README.md)
- [Package Reference](docs/reference/PACKAGE_REFERENCE.md) — canonical supported contract and complete API inventory
- [Documentation map](docs/README.md)
- [Idempotency and receipt reference](docs/reference/idempotency.md) — the complete keyed API contract
- [Synthetic no-send recording](docs/reference/synthetic-recording.md) — encrypted inert storage and bounded maintenance
- [Install or upgrade the schema](docs/upgrade.md) — fresh and existing installations differ
- [Prepare the next release](docs/release-preparation.md)
- [Examples](examples/README.md), [how-to guides](docs/how-to/README.md), [architecture book](docs/book/README.md)
- [Security policy](SECURITY.md) and [security architecture description](SECURITY_AUDIT.md)

## Choose the enqueue contract

| Requirement | API | Result |
| --- | --- | --- |
| Each call intentionally creates another job | `EmailQueueWriterInterface::enqueue()` / `PdoEmailQueueWriter` | `void`; no request deduplication |
| Retrying one identified request must not create another job | `IdempotentEmailQueueWriterInterface::enqueueOnce()` / `PdoIdempotentEmailQueue` | A committed receipt; different content on the same key conflicts |
| Record an inert request without any email job | `SyntheticEmailRecorderInterface::record()` / `PdoSyntheticEmailStore` | Distinct synthetic record; never a delivery receipt |
| Observe an accepted keyed request | `EmailEnqueueReceiptReaderInterface::find(namespace, key)` | Scoped receipt or `null`; no recipient/context returned |

Keyed enqueue atomically stores a job and its durable receipt. Repeating the same namespace/key and
logical content returns the same receipt/job identity. It does **not** guarantee exactly-once SMTP,
mailbox arrival, or automatic recovery of a worker interrupted after claiming a job.

## Public Runtime API

| Capability | Supported entry points |
| --- | --- |
| Per-call queue insertion | `EmailQueueWriterInterface`, `PdoEmailQueueWriter` |
| Keyed acceptance and scoped observation | `IdempotentEmailQueueWriterInterface`, `EmailEnqueueReceiptReaderInterface`, `PdoIdempotentEmailQueue` |
| Inert recording and trusted cleanup | `SyntheticEmailRecorderInterface`, `SyntheticEmailMaintenanceInterface`, `PdoSyntheticEmailStore` |
| Rendering | `EmailRendererInterface`, `TwigEmailRenderer`, payload/rendered DTOs |
| Explicit SMTP call | `EmailTransportInterface`, `SmtpEmailTransport`, `EmailTransportConfigDTO` |
| One processing batch | `EmailQueueWorker` |

The [Package Reference](docs/reference/PACKAGE_REFERENCE.md) owns the complete supported inventory,
DTO fields, enum values, exceptions and behavioral boundaries. Internal helpers are not consumer API.

## Installation and dependencies

```bash
composer require maatify/email-delivery:1.2.0
```

This exact version is available through Packagist. To follow the current Stable line, use
`composer require maatify/email-delivery`.

The package requires PHP 8.2+, JSON and PDO, plus `maatify/crypto`, Twig, PHPMailer and PSR-3 logging.
The production keyed and synthetic storage paths use MySQL/MariaDB with `pdo_mysql` and the package-owned
[SQL files](schema/mysql). SQLite is used by legacy tests, not supported as keyed production storage.
Supply your application PDO, crypto provider/context provider, renderer, transport and logger through DI.
Monolog is not a package dependency.

Apply the [schema installation/upgrade](docs/upgrade.md) before adopting the keyed API. Do not run
all numbered SQL files on a fresh installation: the binding upgrade is for an existing legacy table.

Synthetic recording uses an optional independent table and separate crypto domains. Its retention is
explicitly configured by the consumer; cleanup is scheduled externally. It provides no constant-time or
HTTP security guarantee. See the [full contract](docs/reference/synthetic-recording.md).

## Quick Usage

This function defines application wiring; including it does not connect or send mail. Its dependencies
must already be configured. See the [complete examples](examples/README.md) for construction,
explicit scheduling, receipt lookup and worker composition.

```php
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueReceiptDTO;
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\IdempotentEmailQueueWriterInterface;

function queueWelcome(
    IdempotentEmailQueueWriterInterface $queue,
    string $authenticatedNamespace,
    string $stableRequestKey,
    string $recipient,
    string $name,
): EmailEnqueueReceiptDTO {
    return $queue->enqueueOnce(new EmailEnqueueRequestDTO(
        consumerNamespace: $authenticatedNamespace,
        requestKey: $stableRequestKey,
        entityType: 'user',
        entityId: null,
        recipientEmail: $recipient,
        payload: new EmailQueuePayloadDTO(['name' => $name], 'welcome', 'en'),
        senderType: 1,
    ));
}
```

Persist the key and intended content in the caller; do not generate a new key on every retry. Namespace
is a scope, not authentication. Derive it from the authenticated caller and keep PII/secrets out of keys.
The [reference](docs/reference/idempotency.md) defines canonical content, validation, transaction errors,
indefinite receipt/key retention and recovery with the same key on a fresh connection.

## Worker and delivery boundaries

`EmailQueueWorker::processBatch()` claims a bounded pending batch under row locks, commits the claim,
then decrypts/renders and calls the transport outside that transaction. Run it from your application's
supervised process using the [worker guide](docs/how-to/setup-worker.md).

Keyed jobs use database UTC for due/retry selection, retry deadlines and `sent_at`. NULL-token legacy
jobs retain local database `NOW()` and PHP-local retry calculation. The worker supports pre-binding
legacy tables and does not mutate the session timezone. [Clock and failure details](docs/book/worker.md).

![Queue acceptance and delivery architecture](docs/assets/architecture-diagram.svg)

## Development verification

```bash
composer validate --strict
composer test
composer analyse -- --memory-limit=512M
composer check:docs
composer check:examples
composer check:artifact
```

Without DB configuration, real-DB tests skip. CI is configured for PHP 8.2/8.3/8.4/8.5 with required MySQL
integration tests and an additional no-DB skip check. For a disposable dedicated database ending in
`_test`, set `EMAIL_DELIVERY_IT_DSN`, `EMAIL_DELIVERY_IT_USER`, `EMAIL_DELIVERY_IT_PASS` and
`EMAIL_DELIVERY_IT_REQUIRED=1`. Tests own/drop their queue tables; never target an application database.
See the [release checklist](docs/release-preparation.md) for evidence and documentation checks.

## License

This library is licensed under the [MIT License](LICENSE). Vulnerability reports follow [SECURITY.md](SECURITY.md).

## Author

Engineered by **Mohamed Abdulalim** ([@megyptm](https://github.com/megyptm))<br>
Backend Lead & Technical Architect<br>
[https://www.maatify.dev](https://www.maatify.dev)

---

<div align="center">

[Built with ❤️ by Maatify.dev — Unified Ecosystem for Modern PHP Libraries](https://www.maatify.dev)

</div>
