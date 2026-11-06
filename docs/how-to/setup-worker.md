# Compose and run a worker

[Guides](README.md) · [Lifecycle and clocks](../book/worker.md) · [Typed composition](../examples/worker-runner.php)

`EmailQueueWorker` processes one batch when the application calls `processBatch()`. There is no
bundled daemon/bootstrap script. Your DI container supplies PDO, crypto/context provider, renderer,
transport and a PSR-3 logger; Monolog is not required. The [example](../examples/worker-runner.php)
defines dependency-taking functions and does nothing at include time.

## Application bootstrap

1. Apply the correct [fresh/upgrade SQL](../upgrade.md) before keyed callers run.
2. Build a dedicated idle PDO with exception mode. Do not wrap the worker in another transaction.
3. Supply read keys for queued payloads/recipients and stable contexts, plus trusted template root/globals.
4. Construct the transport with [explicit SMTP encryption](configure-smtp.md) and restricted diagnostics.
5. Call `processBatch(50)` from your application's supervised process. Select batch size/polling/supervision
   in the application; those are not new package settings or automatic recovery mechanisms.

Keyed worker comparisons, retry deadlines and sent timestamps are database UTC even when its session
is local. NULL-token/pre-003 legacy work keeps its existing local clock behavior. The worker discovers
the optional column per transaction, does not cache it or change session settings, and fails closed when
metadata reads fail. See the [clock table](../book/worker.md#clock-domains).

## Supervision and errors

Handle escaped batch/database failures in the application. Do not keep using a connection whose
transaction state is unresolved, log raw credentials/payloads, or blindly continue an infinite loop on
a broken connection. A process manager can restart your application entry point; it cannot infer whether
an external SMTP send occurred or reset a queue row left `processing`.

No automatic reclaim/lease/recovery cron for `processing` is implemented. Investigate queue state,
logs and external acceptance before an explicit operator recovery decision. The library's retry tables
apply to handled row failures, not a guarantee that every crash or DB outage is automatically retried.
