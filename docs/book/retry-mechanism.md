# Retry mechanism and limits

[Book](README.md) · [Worker clocks](worker.md#clock-domains)

The processing-lease recovery described below shipped in the [1.3.0 release](../releases/1.3.0-publication.md),
published on 2026-11-23. Version 1.2.0 does not reclaim interrupted `processing` jobs automatically.

The worker increments `attempts` while claiming a pending batch. After a handled row failure it records
a length-limited error code/reason and either schedules another pending attempt or marks it failed.
These are fixed increasing delay tables, not a configurable exponential formula:

| Failed attempt | SMTP transport error | Default: crypto/render/other row error |
| --- | --- | --- |
| 1 | 30 seconds | 60 seconds |
| 2 | 60 seconds | 300 seconds |
| 3 | 120 seconds | 900 seconds |
| 4 or later | Mark `failed` | Mark `failed` |

The current cap is 4. A schedule is eligible when both `scheduled_at` and any `retry_after` are due
(inclusive boundary). Keyed deadlines come from the database UTC clock; legacy deadlines retain
PHP-local calculation. The logger's scheduled retry value matches the value written by that path.

These tables apply inside row processing, not to every possible DB/transaction error. Failure to read
metadata, claim/commit a batch, obtain the keyed retry clock or persist an outcome can escape to the
caller. Re-running a whole batch is not a universal recovery instruction.

SMTP failure may be ambiguous even when the library schedules a retry. Keyed enqueue prevents a
second accepted queue job for the request; it does not deduplicate SMTP attempts. A job left
`processing` after interruption is claimed again once its [processing lease](worker.md#processing-lease)
expires: as a new attempt under the cap, or settled `failed` (`processing_lease_expired`) at the cap.
Do not infer receipt/queue missing as sent, and do not create a new request key to conceal an unknown outcome.
