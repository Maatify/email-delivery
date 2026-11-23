# Documentation

This documentation describes the [1.2.0 release](../CHANGELOG.md#120) and the [1.3.0 release target](../CHANGELOG.md#130).
Version-specific behavior is identified by version; publication availability is not implied by this documentation.
See the [1.2.0 publication record](releases/1.2.0-publication.md)
for its qualification and Composer integrity evidence.

## Canonical consumer entry points

- [Package Reference](reference/PACKAGE_REFERENCE.md): supported behavioral contract and complete Public Runtime API.
- [Usage Guide](guides/USAGE_GUIDE.md): integration workflows and capability map.
- [Root examples](../examples/README.md): maintained application-wired examples.

## Adopt the library

1. [Overview and API choice](../README.md)
2. [Install/upgrade and rollback considerations](upgrade.md)
3. [Keyed enqueue and receipt reference](reference/idempotency.md)
4. [Examples with injected dependencies](examples/README.md)
5. [Run the worker](how-to/setup-worker.md)

- [Synthetic recording reference](reference/synthetic-recording.md): no-send storage, expiry and security boundaries

## Understand and operate it

- [Book](book/README.md): architecture, queue, clocks, retries, rendering, transport and encryption
- [How-to guides](how-to/README.md)
- [Release preparation](release-preparation.md)
- [Security architecture description](../SECURITY_AUDIT.md) and [reporting policy](../SECURITY.md)
- [Historical ADR-008](ADR-008-Email-Delivery-Independent-Queue.md): locked independent-channel decision
- [ADR-011](ADR-011-Synthetic-Email-Recording.md): isolated synthetic recording without delivery
- [ADR-010](ADR-010-Idempotent-Email-Enqueue.md): extension published in 1.2.0 for keyed acceptance
- [ADR-012](ADR-012-Worker-Connection-Recovery-And-Processing-Lease.md): 1.3.0 worker reconnect and processing lease; owner acceptance recorded on 2026-11-22

The idempotency reference owns the detailed request/receipt rules. The upgrade guide owns SQL adoption.
Other pages link to those contracts rather than define alternative retry, retention or authentication policy.
