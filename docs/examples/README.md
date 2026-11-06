# Typed examples

[Documentation](../README.md) · [Keyed API contract](../reference/idempotency.md) · [Upgrade](../upgrade.md)

These files define functions with injected dependencies. They contain no top-level PDO connections,
key placeholders, environment loading, SMTP calls or infinite worker loops. Load your Composer
autoloader and deliberately invoke them from your application after configuring real dependencies.
Their namespace is `Maatify\EmailDelivery\Documentation\Examples`.

| Example | Purpose |
| --- | --- |
| [basic-send.php](basic-send.php) | Legacy enqueue with optional Reply-To; historical filename, not direct send |
| [queue-email.php](queue-email.php) | Explicit legacy wall-clock scheduling |
| [idempotent-email.php](idempotent-email.php) | Construct keyed adapter; accept/lookup through typed ports |
| [worker-runner.php](worker-runner.php) | Construct worker and run one explicitly invoked batch |
| [synthetic-email.php](synthetic-email.php) | No-send recorder and separate bounded maintenance port; explicit consumer retention |
| [render-email.php](render-email.php) | Direct renderer use with explicit `lang`, returning subject/HTML only |

Match `welcome`/`en` with the [complete Twig layout/template](../how-to/create-template.md).
Configure crypto and key retention using your application DI. The keyed adapter requires an idle
UTC/autocommit/exception-mode PDO; examples do not silently mutate an injected connection. Errors
propagate to the caller instead of being mislabeled “sent”.

Validate examples with lint/static analysis only; never run them against real SMTP during docs checks.
A scoped receipt and queue `sent` marker do not establish exactly-once SMTP or mailbox arrival.
