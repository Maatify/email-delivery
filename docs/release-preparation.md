# Prepare the next release

[Documentation](README.md) · [Upgrade](upgrade.md) · [Changelog](../CHANGELOG.md#unreleased)

This checklist prepares a maintainer decision. It does not publish a version, execute migrations,
start workers or certify security. Version selection, merge, tag and release remain explicit maintainer
steps. Documentation for development features must not imply they already exist in the latest package tag.

## Review the contract

- Review the [keyed API reference](reference/idempotency.md) and [ADR-010](ADR-010-Idempotent-Email-Enqueue.md).
- Confirm legacy enqueue still creates one job per call; keyed acceptance does not promise exactly-once SMTP.
- Confirm receipt/key retention, old crypto read keys, plaintext metadata and authenticated namespace ownership.
- Review [fresh versus upgrade SQL](upgrade.md) with the deployment owner; record migration-before-adoption.
- Verify per-row worker UTC/legacy clocks, unchanged delay tables/cap, and the absence of automatic `processing` recovery.
- Keep [ADR-008](ADR-008-Email-Delivery-Independent-Queue.md) as historical locked architecture; do not rewrite it to imply a release.

## Run package verification on the candidate

```bash
composer validate --strict
composer test
composer analyse -- --memory-limit=512M
```

Run the configured PHP 8.2/8.3/8.4/8.5 matrix with a real MySQL fixture; verify MariaDB behavior when claiming
compatibility. Set `EMAIL_DELIVERY_IT_REQUIRED=1` and an explicitly isolated `_test` DSN/user/password.
The tests own/drop their tables. Also run the default suite without DB configuration to verify safe skips.
Preserve actual logs, candidate SHA and counts; a configured CI job or billing-blocked run is not a pass.
Never send real SMTP or use production credentials for release tests.

## Validate this documentation

- Check all local Markdown links/anchors, including SVG/source/SQL links.
- Lint `docs/examples/*.php` and fenced PHP snippets with `php -l`, without executing them.
- Analyze the typed examples against current dependencies/signatures without connecting to external transports.
- If smoke-testing Twig, use temporary templates and rendering only, never a live transport.
- Parse/render the SVG assets and inspect labels, state transitions and clock/acceptance boundaries.
- Compare constructors, ports, schema paths and error names to the candidate source.
- Verify no unchanged security-support/reporting policy or historical ADR was accidentally rewritten.

## Release readiness tracking and qualification

[Issue #7](https://github.com/Maatify/email-delivery/issues/7) tracks the outstanding release-facing
files, canonical consumer entry points and artifact-verification work found against the
[presentation standard](https://github.com/Maatify/php-engineering-standards/blob/698b0735c58ee388d650542336989b90e06d7003/standards/packages/LIBRARY_PRESENTATION_STANDARD.md).
README visual alignment and passing quality CI do not complete those release-readiness requirements.
Resolve the applicable items before publishing the next version; no exact target has been selected yet.

For an approved exact target, allocate its implemented changes to an undated target section in
CHANGELOG, and align artifact identity/install instructions, SECURITY lifecycle wording, VERSION,
Composer and distributed consumer files without claiming pre-publication availability. After approved
integration, use the actual main SHA for both Full Applicable CI and Release Artifact Verification.
Only that same qualified SHA may receive the separately authorized tag/release. A later candidate
commit needs its own qualification; a passing branch run is not evidence for another SHA.

After exact-version external publication, verify the installed artifact from a clean Composer root
and independently refresh any live repository publication facts that became stale. Artifact-integrity
verification and publication-state freshness are separate checks. Keep existing published release
identities immutable; tracked artifact corrections require a new release identity.

## Maintainer publication handoff

Update the existing Unreleased notes with the final scoped changes and compatibility instructions.
After the owner's release decision, select the version, update version metadata/changelog as required,
run the candidate gates, and perform the separately authorized merge/tag/publication workflow.
Update `VERSION` only as part of an approved release; a documentation change is not proof of publication. HTTP-service adoption
and application durable send intent are follow-up integrations, not capabilities implied by this package.
