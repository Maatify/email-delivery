# Compose and run a worker

[Guides](README.md) · [Lifecycle and clocks](../book/worker.md) · [Typed composition](../examples/worker-runner.php)

This guide describes released version 1.3.0, published on 2026-11-23. Connection recovery,
processing leases and entity-type-scoped batches are not included in the earlier 1.2.0 package.

`EmailQueueWorker` processes one batch when the application calls `processBatch()`. There is no
bundled daemon/bootstrap script. Your DI container supplies a connection factory, crypto/context
provider, renderer, transport and a PSR-3 logger; Monolog is not required. The [example](../examples/worker-runner.php)
defines dependency-taking functions and does nothing at include time.

## Application bootstrap

1. Apply the correct [fresh/upgrade SQL](../upgrade.md) before keyed callers run. For an existing table,
   apply claim index `005` unless an index led by `(status, priority, id)` already exists; without one the
   worker still runs but candidate reads scan the table.
2. Pass a connection factory, a `Closure(): PDO` that opens a new dedicated exception-mode connection
   with autocommit enabled (the MySQL/MariaDB default) and applies your session settings each time it is
   called. The worker rejects a connection without autocommit, or not in exception error mode, before
   claiming anything. The worker calls it on first use and again only after the server closed its
   connection. A `PDO` instance is still accepted, in exception error mode and with autocommit enabled
   (both checked before every claim), but then the worker cannot reconnect. Do not wrap the worker in another transaction.
3. Supply read keys for queued payloads/recipients and stable contexts, plus trusted template root/globals.
4. Construct the transport with [explicit SMTP encryption](configure-smtp.md) and restricted diagnostics.
5. Optionally pass `processingLeaseSeconds` (default 300): how long a claimed row may stay `processing`
   without renewal before a later batch claims it again. Nothing bounds one render and send; size the
   lease as the [worker book](../book/worker.md#processing-lease) describes.
6. Call `processBatch(50)` from your application's supervised process. Select batch size/polling/supervision
   in the application.
7. For a worker dedicated to one entity type in a shared queue, call `processBatchByEntityType('admin', 50)`
   instead (introduced in 1.3.0). Every type, and rows without a type, still need a worker: a scoped batch only
   reclaims leases of its own type, and only `processBatch()` takes rows whose `entity_type` is NULL.
   Behind a large backlog of other types, apply the optional [schema 006](../upgrade.md#scoped-batch-index-006).

Keyed worker comparisons, retry deadlines and sent timestamps are database UTC even when its session
is local. NULL-token/pre-003 legacy work keeps its existing local clock behavior. The worker discovers
the optional column per transaction, does not cache it or change session settings, and fails closed when
metadata reads fail. See the [clock table](../book/worker.md#clock-domains).

## Supervision and errors

Handle escaped batch/database failures in the application. Do not log raw credentials/payloads, and do
not loop forever on a broken connection: with a `PDO` instance the worker keeps the connection it was
given, so rebuild the worker after a connection failure or pass a factory instead. With a factory, the
worker replaces a connection the server closed before the next claim and retries single status writes
once; a database that stays unreachable still makes `processBatch()` throw.

When a `sent` status cannot be recorded, the worker logs `Email sent but status write failed`. Alert on
that message: the row stays `processing` until its lease expires and is then claimed again, so that
message may be sent twice. A retry/failed write that cannot be recorded is logged as `Email job failure
was not recorded`. For both, a connection still lost after one reconnect is rethrown and stops the batch,
and the batch's remaining claimed rows go out after their lease (300 seconds by default). Any other error
leaves that row to its lease and the batch goes on.
Rows interrupted before their send are recovered the same way; no separate recovery script is needed.
See [processing lease](../book/worker.md#processing-lease) and
[ADR-012](../ADR-012-Worker-Connection-Recovery-And-Processing-Lease.md).
