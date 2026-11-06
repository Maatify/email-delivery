# Queue storage and acceptance

[Book](README.md) · [Keyed API reference](../reference/idempotency.md) · [Install/upgrade](../upgrade.md)

`cd_email_queue` stores encrypted recipient and template payload, their IV/tag/key IDs, plaintext
routing/template metadata, priority, due/retry timestamps, attempts and state. It stores template
context, **not pre-rendered HTML**. Rendering happens later in the worker.

| Path | Identity and transaction behavior |
| --- | --- |
| Legacy `PdoEmailQueueWriter::enqueue()` | Inserts each call, returns void, no idempotency key/receipt; does not own a transaction |
| Keyed `PdoIdempotentEmailQueue::enqueueOnce()` | Scoped explicit key, detached logical snapshot, atomic job/receipt, owned transaction |
| Scoped `find()` | Observes acceptance/current queue state without returning/decrypting recipient/context |

The [reference](../reference/idempotency.md) is the source for exact request fields, canonicalization,
validation, exception handling and retention rules. Do not infer a keyed receipt for a legacy job.

Keyed jobs carry immutable `enqueue_receipt_id`; legacy inserts leave it NULL. The token plus numeric
job ID prevents false association after purge/ID reuse. Receipts survive queue deletion and return
`queue_missing`; replay never recreates the purged job. There is no automatic receipt expiry/pruner.

Keyed schedule/timestamps are UTC. Legacy schedules retain their previous wall-clock behavior.
The worker's [per-row clock table](worker.md#clock-domains) explains mixed-table processing.
Lower numeric priority sorts first, then ID; a batch limit bounds selection, not external delivery time.

The worker only selects `pending` jobs whose schedule and optional retry deadline are due. Its state
transition and operational failure boundaries are documented in [worker](worker.md); a receipt is not
an SMTP delivery receipt or a mechanism to revive `processing` jobs.
