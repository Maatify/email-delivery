# Documentation

This documentation describes the current development implementation; [Unreleased](../CHANGELOG.md#unreleased)
features are not a claim that a package version has been published.

## Adopt the library

1. [Overview and API choice](../README.md)
2. [Install/upgrade and rollback considerations](upgrade.md)
3. [Keyed enqueue and receipt reference](reference/idempotency.md)
4. [Examples with injected dependencies](examples/README.md)
5. [Run the worker](how-to/setup-worker.md)

## Understand and operate it

- [Book](book/README.md): architecture, queue, clocks, retries, rendering, transport and encryption
- [How-to guides](how-to/README.md)
- [Release preparation](release-preparation.md)
- [Security architecture description](../SECURITY_AUDIT.md) and [reporting policy](../SECURITY.md)
- [Historical ADR-008](ADR-008-Email-Delivery-Independent-Queue.md): locked independent-channel decision
- [ADR-010](ADR-010-Idempotent-Email-Enqueue.md): implemented development extension for keyed acceptance

The idempotency reference owns the detailed request/receipt rules. The upgrade guide owns SQL adoption.
Other pages link to those contracts rather than define alternative retry, retention or authentication policy.
