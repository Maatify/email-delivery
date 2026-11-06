# Contributing to maatify/email-delivery

Thank you for contributing to Maatify Email Delivery. Read the [Code of Conduct](CODE_OF_CONDUCT.md)
and [Package Reference](docs/reference/PACKAGE_REFERENCE.md) before changing a consumer contract.

## Scope and changes

This library owns email queue storage, rendering, transport and isolated synthetic recording.
Applications/services own HTTP, authorization, business intent, supervision and cleanup scheduling.
Discuss architectural or public API changes with maintainers before implementation. An issue is useful
for a substantial change; one is not required for every small correction. Security reports go privately
to **security@maatify.dev** following [SECURITY.md](SECURITY.md), not public issues.

## Local development

Run contributor/release commands from a Git checkout; distributed archives omit tests and release scripts.
Use PHP 8.2+ with JSON/PDO and Composer. The CI matrix covers PHP 8.2, 8.3, 8.4 and 8.5.
Tests also need PDO SQLite/MySQL, mbstring, OpenSSL and PHPUnit's XML/DOM extensions; artifact checks
need Git, Composer CLI and PHP zip. Install development dependencies with `composer install`.
This is a library: do not commit `composer.lock`, vendor, local environments, real keys or credentials.

```bash
composer validate --strict
composer test
composer analyse -- --memory-limit=512M
composer check:docs
composer check:examples
composer check:artifact
```

Without an integration DSN, database tests skip deliberately; this is not a full gate. To run the full
suite, supply an explicitly disposable MySQL/MariaDB database whose name ends in `_test`, via
`EMAIL_DELIVERY_IT_DSN`, `EMAIL_DELIVERY_IT_USER`, `EMAIL_DELIVERY_IT_PASS` and
`EMAIL_DELIVERY_IT_REQUIRED=1`. Tests own/drop package tables. Never point them at an application schema.
The examples checker uses only rendering/fake in-memory boundaries and no real SMTP/database.
The root examples are application-wired functions, not standalone service runners; DB/worker calls
require host-owned configured dependencies and supervision. They receive syntax/static validation too.

`check:artifact` builds and inspects actual Git/Composer archives. It requires a clean committed tree;
`composer check:artifact -- --allow-dirty` is a local rehearsal only and cannot qualify a release SHA.
Retain evidence outside the checkout using `EMAIL_RELEASE_EVIDENCE_DIR`. See the
[release runbook](docs/release-preparation.md) for candidate/main-SHA and publication verification.

## Pull requests

Explain the problem, resulting behavior, compatibility/migration effects and actual validation.
Keep runtime contract changes explicit, preserve the legacy enqueue path, and update the canonical
reference/guide/examples when a supported contract changes. Add meaningful regression coverage for
behavior changes. A scoped documentation change should not rewrite historical ADRs or unrelated APIs.
Record skipped or unavailable gates as such. Owner review, merge and publication are separate actions;
a branch pass, version file or generated archive does not authorize tagging or publishing.
