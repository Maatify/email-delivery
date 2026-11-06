# Maatify Email Delivery

[![CI](https://github.com/Maatify/email-delivery/actions/workflows/ci.yml/badge.svg)](https://github.com/Maatify/email-delivery/actions/workflows/ci.yml)
[![Packagist](https://img.shields.io/packagist/v/maatify/email-delivery.svg)](https://packagist.org/packages/maatify/email-delivery)
[![License](https://img.shields.io/github/license/Maatify/email-delivery.svg)](LICENSE)

A framework-independent PHP library for an encrypted email queue, Twig HTML rendering and SMTP
transport through PHPMailer. Applications enqueue work; a separately run worker attempts delivery.

These pages describe the development checkout. Features in [Unreleased](CHANGELOG.md#unreleased),
including keyed enqueue, require a release containing them. This documentation update does not
choose a version, publish a package or apply a database migration.

## Start here

- [Documentation map](docs/README.md)
- [Idempotency and receipt reference](docs/reference/idempotency.md) — the complete keyed API contract
- [Install or upgrade the schema](docs/upgrade.md) — fresh and existing installations differ
- [Prepare the next release](docs/release-preparation.md)
- [Examples](docs/examples/README.md), [how-to guides](docs/how-to/README.md), [architecture book](docs/book/README.md)
- [Security policy](SECURITY.md) and [security architecture description](SECURITY_AUDIT.md)

## Choose the enqueue contract

| Requirement | API | Result |
| --- | --- | --- |
| Each call intentionally creates another job | `EmailQueueWriterInterface::enqueue()` / `PdoEmailQueueWriter` | `void`; no request deduplication |
| Retrying one identified request must not create another job | `IdempotentEmailQueueWriterInterface::enqueueOnce()` / `PdoIdempotentEmailQueue` | A committed receipt; different content on the same key conflicts |
| Observe an accepted keyed request | `EmailEnqueueReceiptReaderInterface::find(namespace, key)` | Scoped receipt or `null`; no recipient/context returned |

Keyed enqueue atomically stores a job and its durable receipt. Repeating the same namespace/key and
logical content returns the same receipt/job identity. It does **not** guarantee exactly-once SMTP,
mailbox arrival, or automatic recovery of a worker interrupted after claiming a job.

## Installation and dependencies

```bash
composer require maatify/email-delivery
```

The package requires PHP 8.2+, JSON and PDO, plus `maatify/crypto`, Twig, PHPMailer and PSR-3 logging.
The production keyed storage path uses MySQL/MariaDB with `pdo_mysql` and the package-owned
[SQL files](schema/mysql). SQLite is used by legacy tests, not supported as keyed production storage.
Supply your application PDO, crypto provider/context provider, renderer, transport and logger through DI.
Monolog is not a package dependency.

Apply the [schema installation/upgrade](docs/upgrade.md) before adopting the keyed API. Do not run
all numbered SQL files on a fresh installation: the binding upgrade is for an existing legacy table.

## Small enqueue example

This function defines application wiring; including it does not connect or send mail. Its dependencies
must already be configured. See the [complete examples](docs/examples/README.md) for construction,
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
```

Without DB configuration, real-DB tests skip. CI is configured for PHP 8.2/8.3/8.4 with required MySQL
integration tests and an additional no-DB skip check. For a disposable dedicated database ending in
`_test`, set `EMAIL_DELIVERY_IT_DSN`, `EMAIL_DELIVERY_IT_USER`, `EMAIL_DELIVERY_IT_PASS` and
`EMAIL_DELIVERY_IT_REQUIRED=1`. Tests own/drop their queue tables; never target an application database.
See the [release checklist](docs/release-preparation.md) for evidence and documentation checks.

## License

[MIT](LICENSE). Vulnerability reports follow [SECURITY.md](SECURITY.md).
