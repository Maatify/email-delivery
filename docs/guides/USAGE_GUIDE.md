# Usage Guide

[Package Reference](../reference/PACKAGE_REFERENCE.md) · [Examples](../../examples/README.md) · [Schema adoption](../upgrade.md)

## Fit, requirements and non-goals

Use this library when your application owns email intent and wants an encrypted SQL queue, explicit
keyed acceptance, Twig HTML rendering, SMTP transport or a separate inert recording operation.
PHP/dependency requirements come from [composer.json](../../composer.json). SQL adapters require
MySQL/MariaDB with PDO MySQL and package schema; applications configure crypto and connections.
The library does not provide HTTP endpoints, business/account checks, a daemon, authorization,
exactly-once SMTP, automatic recovery of interrupted `processing` jobs, or constant-time responses.

## Capability map

| Capability | Walkthrough | Public API example |
| --- | --- | --- |
| Legacy enqueue | [Legacy job](#legacy-job) | [basic-send.php](../../examples/basic-send.php) |
| Explicit legacy scheduling | [Schedule](#schedule) | [queue-email.php](../../examples/queue-email.php) |
| Keyed acceptance and lookup | [Keyed request](#keyed-request) | [idempotent-email.php](../../examples/idempotent-email.php) |
| One worker batch | [Worker](#worker) | [worker-runner.php](../../examples/worker-runner.php) |
| Direct rendering | [Rendering](#rendering) | [render-email.php](../../examples/render-email.php) |
| Direct SMTP transport | [Transport](#transport) | [smtp-transport.php](../../examples/smtp-transport.php) |
| No-send recording and cleanup | [Synthetic](#synthetic) | [synthetic-email.php](../../examples/synthetic-email.php) |

Examples define typed functions; including them does not open a database connection, send SMTP or
start a loop. Load your production Composer autoloader first. DB/SMTP workflows are host-dependent:
configure dependencies/keys and explicitly call them in your application. They are not standalone
service processes. Repository checks smoke-exercise safe rendering and in-memory call boundaries,
and lint/analyze all examples; real SQL behavior is covered by the repository's isolated integration tests.

## Legacy job

Input: configured `EmailQueueWriterInterface`, recipient, entity and template context.
Public call: `enqueueLegacyWelcome()` delegates to `enqueue()`.
Result: `void`, one inserted job per call. Boundary: no identity deduplication or delivery receipt;
this call never sends SMTP. See the [legacy contract](../reference/PACKAGE_REFERENCE.md#legacy-enqueue).
The historical example filename `basic-send.php` does not mean direct send.

## Schedule

Input: the same writer and an explicit `DateTimeInterface`.
Public call: `scheduleLegacyWelcome()` delegates to `enqueue(..., scheduledAt: ...)`.
Result: a job scheduled by the supplied date's wall time. Boundary: align legacy PHP/DB clock
configuration; keyed UTC semantics are separate. See [clock domains](../book/worker.md#clock-domains).

## Keyed request

Input: authenticated namespace, a persisted stable request key, intended content and the keyed adapter.
Public call: `enqueueKeyedWelcome()` calls `enqueueOnce()`; `lookupKeyedWelcome()` calls scoped `find()`.
Result: a confirmed durable receipt or current receipt/null for lookup. Boundary: changed logical
content conflicts; `queue_missing` does not prove delivery and replay never revives a purged job.
Use an idle exception/autocommit UTC connection and apply the [correct schema path](../upgrade.md).
Persist the identity/content before calling; after an unresolved transaction discard the connection
and recover with the same key on a fresh connection. See [the detailed contract](../reference/idempotency.md).

## Worker

Input: a dedicated idle PDO, crypto/context provider, renderer, transport and PSR logger.
Public call: `createWorker()` constructs `EmailQueueWorker`; `runOneWorkerBatch()` calls `processBatch()`.
Result: the worker claims and attempts due jobs, then records success/failure/retry. Boundary: claiming
commits before the external SMTP effect; the host owns supervision and interrupted-job investigation.
Use a fake transport in tests; no repository example automatically sends email. See [worker operation](../how-to/setup-worker.md).

## Rendering

Input: an `EmailRendererInterface`, `welcome` template, language and context.
Public call: `renderWelcome()` calls `render()` with `GenericEmailPayload` and explicit `lang`.
Result: `RenderedEmailDTO` with decoded nonempty subject and HTML body. Boundary: no plain-text part
or SMTP call; application-owned templates/globals must exist. See [complete templates](../how-to/create-template.md).

## Transport

Input: explicit SMTP configuration, recipient, a rendered DTO and optional Reply-To.
Public call: `createSmtpTransport()` creates `SmtpEmailTransport`; `sendRenderedEmail()` invokes the transport port.
Result: `void` on a completed send call. Boundary: this deliberately invoked operation performs SMTP;
it does not enqueue or prove mailbox arrival. Configure encryption explicitly; the DTO does not enforce
mandatory TLS. Do not invoke real SMTP during docs checks. See [transport configuration](../how-to/configure-smtp.md).

## Synthetic

Input: an explicitly synthetic request and store, configured with consumer-chosen retention seconds.
Public call: `recordSyntheticWelcome()` calls `record()`; trusted maintenance uses `purgeSyntheticBatch()`.
Result: a distinct inert record identity/UTC timestamps, or a confirmed bounded cleanup count.
Boundary: separate encrypted table and crypto domains; no real job/key/receipt or worker handoff.
The service owns authentication, quotas, cleanup scheduling and complete-path timing distributions.
Unknown commit may leave an inert record; a retry may append another. See [synthetic contract](../reference/synthetic-recording.md).
