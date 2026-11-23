# Introduction

[Book](README.md) · [Documentation](../README.md)

Maatify Email Delivery is a channel-owned queue/render/SMTP library. It does not decide why to notify,
authenticate tenants, read application notification tables or supply an HTTP server.

Applications provide a payload and recipient through a configured writer. The legacy writer inserts
per call; the [keyed API](../reference/idempotency.md) adds explicit request identity and committed
receipt lookup. Both encrypt recipient and template context for the queue.

An application-run worker claims due jobs, commits `processing`, decrypts/renders HTML and calls its
transport. Normal row failures use fixed backoff tables; transaction/database failures may escape to
the application supervisor. In 1.3.0, expired `processing` leases
are claimed again (at-least-once); published 1.2.0 does not recover those rows automatically. No built-in
daemon, HTTP endpoint or receipt-retention pruner is supplied. Queue acceptance and SMTP/mailbox delivery
are different facts.

Start with [schema adoption](../upgrade.md), then [enqueue](../how-to/send-email.md) and
[worker composition](../how-to/setup-worker.md). Additions are listed under [1.2.0](../../CHANGELOG.md#120)
and [1.3.0](../../CHANGELOG.md#130). The [1.2.0 publication evidence](../releases/1.2.0-publication.md) is recorded separately.
