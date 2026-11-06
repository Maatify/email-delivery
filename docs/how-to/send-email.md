# Enqueue an email

[Guides](README.md) · [API contract](../reference/idempotency.md) · [Schema adoption](../upgrade.md)

First choose the identity contract: the legacy writer inserts every call; keyed enqueue requires a
stable caller-owned namespace/key and returns a committed receipt. Neither synchronously sends SMTP.
Apply the schema and configure real DI dependencies before calling either path.

## Keyed request

Use [idempotent-email.php](../examples/idempotent-email.php) to construct a keyed queue, accept a
welcome request and look up its receipt through typed ports. Persist the request key and intended
content in the caller. After a lost reply, reuse both; changed context, order/types or schedule mode can
conflict. Do not derive namespaces from an unauthenticated body or put PII in keys.

For unavailable/unresolved outcomes, follow the [error table](../reference/idempotency.md#error-handling-and-unresolved-outcomes).
Discard unresolved connections and reconcile the same key on a fresh one. Do not suppress exceptions
with a generic “sent” message or compensate with another key.

## Legacy request and Reply-To

[basic-send.php](../examples/basic-send.php) intentionally demonstrates legacy enqueue, despite the
historical filename. [queue-email.php](../examples/queue-email.php) demonstrates explicit legacy scheduling.
A nullable Reply-To belongs in `EmailQueuePayloadDTO`; the worker forwards it to the transport. It is
not a caller authorization or a delivery guarantee.

A successful enqueue means queue storage/acceptance. A separately [run worker](setup-worker.md)
attempts delivery. Inspect scoped receipt state for keyed work, respecting `queue_missing` and the
absence of automatic interrupted-processing recovery; do not present acceptance as mailbox delivery.
