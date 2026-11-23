# Release 1.3.0 preparation and verification

[Documentation](README.md) · [Package Reference](reference/PACKAGE_REFERENCE.md) · [Target changelog](../CHANGELOG.md#130)

Version 1.3.0 is the prepared Stable 1.x target and is **not published**. Version 1.2.0 remains the latest
published release; its immutable artifact and [publication record](releases/1.2.0-publication.md) are
historical evidence, not a qualification for 1.3.0. This guide records preparation and qualification
requirements. It does not authorize a tag, GitHub Release, Packagist publication, or production migration.

## Candidate content

`release-target.json`, VERSION, README identity/install boundary, undated 1.3.0 CHANGELOG allocation and
SECURITY lifecycle wording must identify the same target. The observed published baseline is 1.2.0.
The prepared candidate's main changes are worker connection recovery and processing leases, entity-type
scoped batches, PHPMailer 7 compatibility, and the associated schema/index, test, and documentation work.
Review the [1.3.0 semantic review](releases/1.3.0-review.md), [curated candidate notes](releases/1.3.0-notes.md),
[schema adoption](upgrade.md), real receipt/key retention, legacy/keyed clocks, synthetic isolation/retention,
and delivery/retry limits. The 1.2.0 publication record and release notes remain unchanged. No production
SQL or SMTP is run by preparation tooling.

Candidate-only facts must not be attributed to the installed 1.2.0 package. In particular, PHPMailer 7,
the direct `symfony/polyfill-mbstring` dependency, worker reconnect/recovery, scoped batches, and the
processing lease are prepared for 1.3.0 and remain unavailable from a published package until release.

## Local commands and CI parity

Run from a Git checkout with development dependencies and PHP 8.2+; artifact commands also need Git,
Composer CLI and PHP zip. Contributor environment/setup rules are in [CONTRIBUTING](../CONTRIBUTING.md).

```bash
composer validate --strict
composer test
composer analyse -- --memory-limit=512M
composer check:docs
composer check:examples
composer check:artifact -- --allow-dirty
```

The dirty artifact command is a rehearsal only. A qualifying artifact run requires a clean committed
candidate and must use `composer check:artifact` without `--allow-dirty`.

Full Applicable CI runs PHP 8.2/8.3/8.4/8.5, required isolated MySQL integration, no-DB safe skips,
PHPStan including consumer examples/release scripts, documentation/API inventory, safe example smoke,
and syntax. For local SQL gates set an explicitly disposable `_test` DSN and
`EMAIL_DELIVERY_IT_REQUIRED=1`; tests own/drop their queue tables. The SMTP integration test requires
an isolated SMTP test service. Confirm MariaDB separately when claiming MariaDB compatibility. Without
the required services, skipped integration tests are not a full gate. Never use production credentials
or real SMTP.

`check:docs` checks local links/anchors, PHP snippets, SVG XML, llms navigation and actual public
signatures against the canonical reference. `check:examples` exercises safe rendering/in-memory ports;
DB/worker examples remain explicitly host-dependent functions and receive static/syntax validation.
Inspect the GitHub-rendered README/governance pages during review; syntax checks do not prove appearance.

`check:artifact` checks target identity/allocation/support boundaries, creates **actual Composer and Git
archives**, compares exported bytes and required consumer files, rejects development/environment content,
installs production dependencies from the extracted archive in an isolated Composer home/cache, and
smoke-checks public-type autoload without SMTP/SQL. Reports explicitly distinguish rehearsals from a
committed candidate. Set `EMAIL_RELEASE_EVIDENCE_DIR` to a new/empty absolute directory to choose evidence
storage; otherwise a new owned temporary directory is created. Evidence includes target, SHA/tree,
Composer version, file hashes, archives and logs; failures are nonzero and retain failure evidence when
initialized. The CI artifact job uploads selected reports/archives. Download important evidence to durable
owner-controlled storage before tag; CI retention is finite. Preserve semantic review and owner approval
alongside it.

## Qualification after approved integration

1. Keep all release preparation work on a topic branch and submit it through a PR. Do not commit
   release-preparation changes directly on `main`.
2. The owner reviews and merges the preparation PR; workflows do not create a tag or release.
3. Record the actual approved main commit as the candidate release SHA for 1.3.0.
4. Require Full Applicable CI **and** Release Artifact Verification from the same workflow SHA. Both feed
   `CI Gate`; PR merge-ref/branch results do not qualify a later main commit.
5. Review semantic claims, current distribution state and retained evidence. Automated string/structure
   checks are supplemental, not independent proof that prose guarantees are true.
6. Only after both gates and review pass on that exact main SHA does it become release-qualified. Any later
   commit intended for the same release needs qualification again.
7. Obtain separate owner authorization for tag/release/publication and use exactly that SHA. Do not move
   an existing tag. Use [curated 1.3.0 notes](releases/1.3.0-notes.md), not a PR-body dump.

## Post-publication verification

After an independently authorized 1.3.0 version becomes externally available, run the maintained local
command using its full release-qualified SHA:

```bash
composer verify:published -- --version=1.3.0 --sha=FULL_40_CHARACTER_QUALIFIED_SHA
```

The equivalent manual workflow is `published-artifact.yml`; its inputs are the exact version and SHA.
It does not publish. The script creates a fresh external Composer project with controlled environment,
isolated home/cache and no custom/path repository, records effective configuration/Composer version,
requires the exact version from the default Packagist channel, and verifies **observed dist mode** in
installed metadata. It rejects source fallback and mismatched source/dist references, verifies identity,
and compares every installed file against an independent Git export of the qualified SHA. Extra/missing
files or bytes fail. No source-only delivery exception is selected for this package.

Until that work is authorized and performed, 1.3.0 publication and post-publication verification are
**NOT RUN**. A local archive install or runtime harness does not substitute for this external integrity
evidence. After publication, retain installed metadata/reference, manifest/file hashes and logs; refresh
the live publication date/link and Unreleased compare base without rewriting the immutable artifact.
Any tracked correction to a published artifact needs a new version.
