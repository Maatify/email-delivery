# Package Reference — maatify/email-delivery

[Usage Guide](../guides/USAGE_GUIDE.md) · [Examples](../../examples/README.md) · [Schema adoption](../upgrade.md)

This is the canonical supported public/runtime/behavioral reference and complete Public Runtime API
inventory for this package. It describes the published **1.2.0** contract plus the worker changes marked
**Unreleased**; [publication evidence](../releases/1.2.0-publication.md) is separate from this API reference.
Exact package/dependency/platform/autoload facts remain owned by [composer.json](../../composer.json).
The specialized [keyed reference](idempotency.md) and [synthetic reference](synthetic-recording.md)
are normative constituent chapters of this reference within their named scopes; their detailed rules
are linked rather than redefined here. Guides/examples explain use and do not create extra contracts.

## Runtime requirements and boundaries

PHP ^8.2, JSON and PDO are declared requirements. `maatify/crypto`, Twig, PHPMailer (`^6.9 || ^7.0`), PSR logging and
`symfony/polyfill-mbstring` are production dependencies; inject instances rather than relying on a framework or environment loader.
SQL adapters use MySQL/MariaDB/PDO MySQL and package schema. SQLite appears only in legacy tests.
The application owns email intent, authentication/authorization, PDO/keys, template files, SMTP policy,
process supervision, log redaction, schema migration and synthetic cleanup scheduling.

Queue acceptance does not establish SMTP/mailbox delivery or exactly-once external effects; the worker
retries interrupted `processing` jobs at-least-once (Unreleased). Synthetic recording does not establish
real acceptance, HTTP timing equivalence or complete prevention of account enumeration. There is no HTTP
endpoint or built-in daemon.

## Legacy enqueue

`EmailQueueWriterInterface::enqueue()` and `PdoEmailQueueWriter` encrypt recipient and JSON payload,
then insert a pending job per call and return `void`. There is no request deduplication or receipt.
The adapter does not own a transaction; a caller transaction can contain the insertion. Its explicit
schedule uses the supplied date's wall time; the default schedule uses PHP-local `date()`, while SQL
creation/update values use `NOW()`. Preserve the application's coordinated legacy clock configuration.

`EmailQueuePayloadDTO(context, templateKey, language, replyTo)` serializes to those four named keys.
It stores template context, not rendered HTML. Legacy inputs do not acquire the newer keyed/synthetic
bounded-validation contract by inference. Encryption/JSON/SQL failures surface through the legacy
adapter's `EmailQueueWriteException`, retaining the prior cause. See [queue storage](../book/queue.md).

## Keyed acceptance and observation

`IdempotentEmailQueueWriterInterface::enqueueOnce()` and `EmailEnqueueReceiptReaderInterface::find()`
are implemented by `PdoIdempotentEmailQueue`. The request DTO carries explicit namespace/key,
message data and an immutable optional schedule. The receipt carries string receipt/job IDs, UTC
acceptance time and `EmailQueueState`; its JSON serialization uses `receipt_id`, `job_id`,
`accepted_at`, `queue_state`. States are pending/processing/sent/failed/queue_missing.

The [keyed constituent reference](idempotency.md) owns exact identity/content validation, canonical v1,
owned transaction preconditions, same-key conflict/recovery, immutable job binding, durable retention
and error semantics. A namespace scopes identity; it is not authorization. `queue_missing` does not
prove delivery and never authorizes a replacement job. A fresh connection and the same identity are
required for recovery from an unresolved enqueue transaction; do not generate a new key automatically.

## Synthetic recording

`SyntheticEmailRecorderInterface` and the separate trusted `SyntheticEmailMaintenanceInterface` are
implemented by `PdoSyntheticEmailStore`. The explicit synthetic request has no real request key;
the result has only record ID and UTC recorded/expiry strings. Each call appends inert data to its
independent optional table; there is no worker handoff or exactly-once recording promise.
The [synthetic constituent reference](synthetic-recording.md) owns validation, domain names, mandatory
consumer-selected retention, bounded expiry deletion, schema/transaction guards and error recovery.
Cleanup spans namespaces and belongs only in trusted maintenance. Real receipt retention is independent.

## Rendering and payloads

`EmailPayloadInterface::toArray()` supplies render context; `GenericEmailPayload` returns its provided
array. `EmailRendererInterface::render(templateKey, language, payload)` returns `RenderedEmailDTO`.
`TwigEmailRenderer` resolves `emails/{templateKey}/{language}.twig` under its templates path, defaults
to `getcwd()/templates`, and configures Twig strict variables. Globals are injected by the application.
Cache defaults to `getcwd()/var/cache/twig`; an explicit path overrides it and `false` disables it.
A nonempty subject block is required; its HTML entities are decoded. HTML body escaping follows Twig;
there is no generated plain-text body. Direct render callers provide `lang` themselves; the worker
injects it. Renderer failures are `EmailRenderException` with the underlying cause where present.
See [template contract](../how-to/create-template.md) and [rendering](../book/rendering.md).

## Transport

`EmailTransportInterface::send(recipientEmail, renderedDTO, replyToEmail)` performs an explicit
transport call. `SmtpEmailTransport` configures PHPMailer from `EmailTransportConfigDTO` and sends
HTML subject/body with optional Reply-To. `encryption='tls'` enables STARTTLS, `'ssl'` enables SMTPS;
null/other values disable AutoTLS. The DTO does not validate a provider's TLS policy or enforce
mandatory encryption. The constructor does not send. Transport errors use `EmailTransportException`;
success is not proof of mailbox delivery. See [SMTP configuration](../how-to/configure-smtp.md).

## Worker

`EmailQueueWorker::processBatch(limit=50)` claims due pending jobs ordered by ascending priority/ID,
increments attempts and marks processing under a transaction, commits, then decrypts/renders/sends.
It reads only the real queue. Receipt-bound rows use database UTC; NULL-token legacy and pre-binding
tables retain their local clock behavior. No worker session timezone is silently changed.
SMTP retry delays are 30/60/120 seconds; other failures use 60/300/900; attempt 4 permanently fails.
**Unreleased.** `processBatchByEntityType(entityType, limit=50)` processes one batch of the rows whose
`entity_type` equals the given type. The filter applies to both candidate reads and to the primary-key
locking read that re-checks them; the batch reclaims and settles at the cap only rows of its own type. A
NULL `entity_type` is taken only by `processBatch()`. An empty type throws `InvalidArgumentException`.
Optional schema 006 indexes scoped batches; see [scoped batches](../book/worker.md#scoped-batches-one-entity-type).
The host owns supervision and investigation of ambiguous delivery. Claim or persistence errors can
escape; do not infer that every worker failure is swallowed. See [worker](../book/worker.md)
and [retry behavior](../book/retry-mechanism.md).

**Unreleased.** The first constructor argument is `PDO|Closure(): PDO`. A factory must return a new
exception-mode connection, and a `PDO` instance must be in exception error mode too (checked before every
claim). On MySQL/MariaDB every worker connection must have autocommit enabled (a `PDO` instance is
checked before every claim, a factory connection when created). A connection that fails
either check is rejected with `UnexpectedValueException` before any claim, and the worker never changes
the setting. The factory is called on first use and only again after the server closed the
connection, which the worker replaces before the claim transaction and once around each single status
statement (driver errors 2006, 2013, 4031). The claim transaction is never retried. A `PDO` instance
cannot reconnect. The optional `processingLeaseSeconds` (default `DEFAULT_PROCESSING_LEASE_SECONDS`, 300,
minimum 1) is how long a `processing` row may go without renewal: the claim stamps `updated_at` and each
row renews it before its send. On a `DATETIME` column each row keeps its own clock. On a `TIMESTAMP`
column (detected per batch) the lease is compared as instants and stamped exactly. An expired row under the attempt cap is claimed and
sent again; at the cap it is settled `failed` with `processing_lease_expired`. The claim reads through
`idx_email_queue_claim` from schema 005; without it the claim's candidate reads scan the table. Either way it
locks only its candidates, by primary key. A repeat after a lost
acknowledgement keeps the first `sent_at` and `retry_after`. Retry/failed writes are
fenced by attempt; the `sent` write is not. A failed `sent` write never writes pending/failed: it is
logged (`Email sent but status write failed`) and the row is left to its lease. For every outcome write,
a connection still lost after one reconnect is rethrown and stops the batch; other errors, such as a lock
wait, leave the row to its lease and the batch continues with the next row.
Delivery is at-least-once. See
[ADR-012](../ADR-012-Worker-Connection-Recovery-And-Processing-Lease.md).

## Exceptions

Package exception classes and their exact constructors appear in the inventory below. They extend
`RuntimeException`, directly or through the package unavailable base. Standard inherited exception
methods retain PHP semantics; enum `cases()`, `from()` and `tryFrom()` retain native backed-enum semantics.
`EmailEnqueueConflictException` means the same identity has different intended content.
`EmailEnqueueUnavailableException` and `SyntheticEmailUnavailableException` mean an operation could
not be confirmed; their transaction-unresolved subclasses require discarding the connection.
The enqueue unresolved exception additionally exposes readonly `phase`; the synthetic subclass
includes the phase in its message, not a public phase property. `InvalidArgumentException` handles
bounded keyed/synthetic validation. External throwables may still occur at other supplied boundaries;
exception detail must not be exposed publicly without application redaction.

## Complete Public Runtime API inventory

The following declarations enumerate the supported types, own public constructors/methods, DTO
properties and enum cases. Types under `Queue/Internal` and `Synthetic/Internal`, private helpers,
tests and contributor/release scripts are not supported consumer APIs. Public visibility alone does
not confer a stable consumer contract. Keep this block synchronized with the actual supported types;
`composer check:docs` detects signature/inventory drift without rewriting this document.

<!-- BEGIN PUBLIC API INVENTORY -->

### `Maatify\EmailDelivery\Config\EmailTransportConfigDTO`

```text
readonly class EmailTransportConfigDTO
public readonly string $host;
public readonly int $port;
public readonly string $username;
public readonly string $password;
public readonly string $fromAddress;
public readonly string $fromName;
public readonly ?string $encryption;
public readonly int $timeoutSeconds;
public readonly string $charset;
public readonly int $debugLevel;
public function __construct(
    string $host,
    int $port,
    string $username,
    string $password,
    string $fromAddress,
    string $fromName,
    ?string $encryption = NULL,
    int $timeoutSeconds = 10,
    string $charset = 'UTF-8',
    int $debugLevel = 0,
);
```

### `Maatify\EmailDelivery\DTO\EmailPayloadInterface`

```text
interface EmailPayloadInterface
public function toArray(
): array;
```

### `Maatify\EmailDelivery\DTO\GenericEmailPayload`

```text
readonly class GenericEmailPayload implements \Maatify\EmailDelivery\DTO\EmailPayloadInterface
public readonly array $context;
public function __construct(
    array $context,
);
public function toArray(
): array;
```

### `Maatify\EmailDelivery\DTO\RenderedEmailDTO`

```text
readonly class RenderedEmailDTO
public readonly string $subject;
public readonly string $htmlBody;
public readonly string $templateKey;
public readonly string $language;
public function __construct(
    string $subject,
    string $htmlBody,
    string $templateKey,
    string $language,
);
```

### `Maatify\EmailDelivery\Exception\EmailEnqueueConflictException`

```text
final class EmailEnqueueConflictException extends \Maatify\EmailDelivery\Exception\EmailQueueWriteException implements \Throwable, \Stringable
public function __construct(
);
```

### `Maatify\EmailDelivery\Exception\EmailEnqueueTransactionUnresolvedException`

```text
final class EmailEnqueueTransactionUnresolvedException extends \Maatify\EmailDelivery\Exception\EmailEnqueueUnavailableException implements \Stringable, \Throwable
public readonly string $phase;
public function __construct(
    string $phase,
    ?Throwable $previous = NULL,
);
```

### `Maatify\EmailDelivery\Exception\EmailEnqueueUnavailableException`

```text
class EmailEnqueueUnavailableException extends \Maatify\EmailDelivery\Exception\EmailQueueWriteException implements \Throwable, \Stringable
```

### `Maatify\EmailDelivery\Exception\EmailQueueWriteException`

```text
class EmailQueueWriteException extends \RuntimeException implements \Stringable, \Throwable
```

### `Maatify\EmailDelivery\Exception\EmailRenderException`

```text
class EmailRenderException extends \RuntimeException implements \Stringable, \Throwable
```

### `Maatify\EmailDelivery\Exception\EmailTransportException`

```text
class EmailTransportException extends \RuntimeException implements \Stringable, \Throwable
```

### `Maatify\EmailDelivery\Exception\SyntheticEmailTransactionUnresolvedException`

```text
final class SyntheticEmailTransactionUnresolvedException extends \Maatify\EmailDelivery\Exception\SyntheticEmailUnavailableException implements \Throwable, \Stringable
public function __construct(
    string $phase,
    ?Throwable $previous = NULL,
);
```

### `Maatify\EmailDelivery\Exception\SyntheticEmailUnavailableException`

```text
class SyntheticEmailUnavailableException extends \RuntimeException implements \Stringable, \Throwable
```

### `Maatify\EmailDelivery\Queue\DTO\EmailEnqueueReceiptDTO`

```text
final readonly class EmailEnqueueReceiptDTO implements \JsonSerializable
public readonly string $receiptId;
public readonly string $jobId;
public readonly string $acceptedAt;
public readonly Maatify\EmailDelivery\Queue\Enum\EmailQueueState $queueState;
public function __construct(
    string $receiptId,
    string $jobId,
    string $acceptedAt,
    Maatify\EmailDelivery\Queue\Enum\EmailQueueState $queueState,
);
public function jsonSerialize(
): array;
```

### `Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO`

```text
final readonly class EmailEnqueueRequestDTO
public readonly ?DateTimeImmutable $scheduledAt;
public readonly string $consumerNamespace;
public readonly string $requestKey;
public readonly string $entityType;
public readonly ?string $entityId;
public readonly string $recipientEmail;
public readonly Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO $payload;
public readonly int $senderType;
public readonly int $priority;
public function __construct(
    string $consumerNamespace,
    string $requestKey,
    string $entityType,
    ?string $entityId,
    string $recipientEmail,
    Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO $payload,
    int $senderType,
    int $priority = 5,
    ?DateTimeInterface $scheduledAt = NULL,
);
```

### `Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO`

```text
final readonly class EmailQueuePayloadDTO
public readonly array $context;
public readonly string $templateKey;
public readonly string $language;
public readonly ?string $replyTo;
public function __construct(
    array $context,
    string $templateKey,
    string $language,
    ?string $replyTo = NULL,
);
public function toArray(
): array;
```

### `Maatify\EmailDelivery\Queue\EmailEnqueueReceiptReaderInterface`

```text
interface EmailEnqueueReceiptReaderInterface
public function find(
    string $consumerNamespace,
    string $requestKey,
): ?Maatify\EmailDelivery\Queue\DTO\EmailEnqueueReceiptDTO;
```

### `Maatify\EmailDelivery\Queue\EmailQueueWriterInterface`

```text
interface EmailQueueWriterInterface
public function enqueue(
    string $entityType,
    ?string $entityId,
    string $recipientEmail,
    Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO $payload,
    int $senderType,
    int $priority = 5,
    ?DateTimeInterface $scheduledAt = NULL,
): void;
```

### `Maatify\EmailDelivery\Queue\Enum\EmailQueueState`

```text
enum EmailQueueState: string
case Pending = 'pending';
case Processing = 'processing';
case Sent = 'sent';
case Failed = 'failed';
case QueueMissing = 'queue_missing';
```

### `Maatify\EmailDelivery\Queue\IdempotentEmailQueueWriterInterface`

```text
interface IdempotentEmailQueueWriterInterface
public function enqueueOnce(
    Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO $request,
): Maatify\EmailDelivery\Queue\DTO\EmailEnqueueReceiptDTO;
```

### `Maatify\EmailDelivery\Queue\PdoEmailQueueWriter`

```text
readonly class PdoEmailQueueWriter implements \Maatify\EmailDelivery\Queue\EmailQueueWriterInterface
public function __construct(
    PDO $pdo,
    Maatify\Crypto\DX\CryptoProvider $cryptoProvider,
    Maatify\Crypto\Contract\CryptoContextProviderInterface $cryptoContextProvider,
);
public function enqueue(
    string $entityType,
    ?string $entityId,
    string $recipientEmail,
    Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO $payload,
    int $senderType,
    int $priority = 5,
    ?DateTimeInterface $scheduledAt = NULL,
): void;
```

### `Maatify\EmailDelivery\Queue\PdoIdempotentEmailQueue`

```text
final readonly class PdoIdempotentEmailQueue implements \Maatify\EmailDelivery\Queue\IdempotentEmailQueueWriterInterface, \Maatify\EmailDelivery\Queue\EmailEnqueueReceiptReaderInterface
public function __construct(
    PDO $pdo,
    Maatify\Crypto\DX\CryptoProvider $cryptoProvider,
    Maatify\Crypto\Contract\CryptoContextProviderInterface $cryptoContextProvider,
    string $fingerprintContext,
);
public function enqueueOnce(
    Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO $request,
): Maatify\EmailDelivery\Queue\DTO\EmailEnqueueReceiptDTO;
public function find(
    string $consumerNamespace,
    string $requestKey,
): ?Maatify\EmailDelivery\Queue\DTO\EmailEnqueueReceiptDTO;
```

### `Maatify\EmailDelivery\Renderer\EmailRendererInterface`

```text
interface EmailRendererInterface
public function render(
    string $templateKey,
    string $language,
    Maatify\EmailDelivery\DTO\EmailPayloadInterface $payload,
): Maatify\EmailDelivery\DTO\RenderedEmailDTO;
```

### `Maatify\EmailDelivery\Renderer\TwigEmailRenderer`

```text
class TwigEmailRenderer implements \Maatify\EmailDelivery\Renderer\EmailRendererInterface
public function __construct(
    ?string $templatesPath = NULL,
    array $globals = [],
    string|false|null $cachePath = NULL,
);
public function render(
    string $templateKey,
    string $language,
    Maatify\EmailDelivery\DTO\EmailPayloadInterface $payload,
): Maatify\EmailDelivery\DTO\RenderedEmailDTO;
```

### `Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRecordDTO`

```text
final readonly class SyntheticEmailRecordDTO
public readonly string $recordId;
public readonly string $recordedAtUtc;
public readonly string $expiresAtUtc;
public function __construct(
    string $recordId,
    string $recordedAtUtc,
    string $expiresAtUtc,
);
```

### `Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRequestDTO`

```text
final readonly class SyntheticEmailRequestDTO
public readonly ?DateTimeImmutable $scheduledAt;
public readonly string $consumerNamespace;
public readonly string $entityType;
public readonly ?string $entityId;
public readonly string $recipientEmail;
public readonly Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO $payload;
public readonly int $senderType;
public readonly int $priority;
public function __construct(
    string $consumerNamespace,
    string $entityType,
    ?string $entityId,
    string $recipientEmail,
    Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO $payload,
    int $senderType,
    int $priority = 5,
    ?DateTimeInterface $scheduledAt = NULL,
);
```

### `Maatify\EmailDelivery\Synthetic\PdoSyntheticEmailStore`

```text
final readonly class PdoSyntheticEmailStore implements \Maatify\EmailDelivery\Synthetic\SyntheticEmailRecorderInterface, \Maatify\EmailDelivery\Synthetic\SyntheticEmailMaintenanceInterface
public function __construct(
    PDO $pdo,
    Maatify\Crypto\DX\CryptoProvider $cryptoProvider,
    Maatify\Crypto\Contract\CryptoContextProviderInterface $cryptoContextProvider,
    int $retentionSeconds,
);
public function record(
    Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRequestDTO $request,
): Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRecordDTO;
public function purgeExpired(
    int $limit = 100,
): int;
```

### `Maatify\EmailDelivery\Synthetic\SyntheticEmailMaintenanceInterface`

```text
interface SyntheticEmailMaintenanceInterface
public function purgeExpired(
    int $limit = 100,
): int;
```

### `Maatify\EmailDelivery\Synthetic\SyntheticEmailRecorderInterface`

```text
interface SyntheticEmailRecorderInterface
public function record(
    Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRequestDTO $request,
): Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRecordDTO;
```

### `Maatify\EmailDelivery\Transport\EmailTransportInterface`

```text
interface EmailTransportInterface
public function send(
    string $recipientEmail,
    Maatify\EmailDelivery\DTO\RenderedEmailDTO $email,
    ?string $replyToEmail = NULL,
): void;
```

### `Maatify\EmailDelivery\Transport\SmtpEmailTransport`

```text
class SmtpEmailTransport implements \Maatify\EmailDelivery\Transport\EmailTransportInterface
public function __construct(
    Maatify\EmailDelivery\Config\EmailTransportConfigDTO $config,
);
public function send(
    string $recipientEmail,
    Maatify\EmailDelivery\DTO\RenderedEmailDTO $email,
    ?string $replyToEmail = NULL,
): void;
```

### `Maatify\EmailDelivery\Worker\EmailQueueWorker`

```text
final class EmailQueueWorker
public function __construct(
    PDO|Closure $pdo,
    Maatify\Crypto\DX\CryptoProvider $cryptoProvider,
    Maatify\EmailDelivery\Renderer\EmailRendererInterface $renderer,
    Maatify\EmailDelivery\Transport\EmailTransportInterface $transport,
    Maatify\Crypto\Contract\CryptoContextProviderInterface $cryptoContextProvider,
    Psr\Log\LoggerInterface $logger,
    int $processingLeaseSeconds = 300,
);
public function processBatch(
    int $limit = 50,
): void;
public function processBatchByEntityType(
    string $entityType,
    int $limit = 50,
): void;
```

<!-- END PUBLIC API INVENTORY -->
