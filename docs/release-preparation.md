# Release 1.2.0 verification and workflow

[Documentation](README.md) · [Package Reference](reference/PACKAGE_REFERENCE.md) · [Target changelog](../CHANGELOG.md#120)

Version 1.2.0 has been published and externally installed as dist. Its qualified immutable source is
`8e0fc1e2995fb52d1e0e5e299eea2203ccd56c2d`; see the [publication record](releases/1.2.0-publication.md).
The workflow below records the preparation/qualification boundaries and remains the operational guide.
No future release is authorized by the completed 1.2.0 publication. This package already had Stable 1.x;
the first-Stable RC/two-host sequence does not apply retroactively.

## Candidate content

`release-target.json`, VERSION, README artifact identity and exact install command, undated 1.2.0
CHANGELOG allocation and SECURITY lifecycle wording identify the same target. Published1.x support
remains unchanged. The published baseline observed during preparation was v1.1.1; registry badges
show live facts. A future prepared target must not be presented as externally available. The canonical consumer
entry points are the Package Reference, Usage Guide, root examples and llms.txt.

Review [1.2.0 semantic evidence](releases/1.2.0-review.md), [schema adoption](upgrade.md), real receipt/key
retention, legacy/keyed clocks, synthetic isolation/retention and unchanged SMTP/retry/recovery limits.
Do not rewrite historical ADR-008. No production SQL or SMTP is run by preparation tooling.

## Local commands and CI parity

Run from a Git checkout with development dependencies and PHP 8.2+; artifact commands also need
Git, Composer CLI and PHP zip. Contributor environment/setup rules are in [CONTRIBUTING](../CONTRIBUTING.md).

```bash
composer validate --strict
composer test
composer analyse -- --memory-limit=512M
composer check:docs
composer check:examples
composer check:artifact
```

Full Applicable CI runs PHP 8.2/8.3/8.4/8.5, required isolated MySQL integration, no-DB safe skips,
PHPStan including consumer examples/release scripts, documentation/API inventory, safe example smoke,
and syntax. For local SQL gates set an explicitly disposable `_test` DSN and
`EMAIL_DELIVERY_IT_REQUIRED=1`; tests own/drop their package tables. Confirm MariaDB when claiming compatibility.
Without a DSN, skipped integration tests are not a full gate. Never use production credentials or real SMTP.

`check:docs` checks local links/anchors, PHP snippets, SVG XML, llms navigation and actual public
signatures against the canonical reference. `check:examples` exercises safe rendering/in-memory ports;
the DB/worker examples remain explicitly host-dependent functions and receive static/syntax validation.
Inspect the GitHub-rendered README/governance pages during review; syntax checks do not prove appearance.

`check:artifact` requires a clean committed candidate. It checks identity/allocation/support boundaries,
creates **actual Composer and Git archives**, compares all exported bytes and required consumer files,
rejects development/environment content, installs production dependencies from the extracted archive
in an isolated Composer home/cache, and smoke-checks public-type autoload without SMTP/SQL.
`composer check:artifact -- --allow-dirty` is only a dirty-worktree rehearsal: it skips the committed
Git export check and cannot qualify any SHA. Reports explicitly distinguish this state.

Set `EMAIL_RELEASE_EVIDENCE_DIR` to a new/empty absolute directory to choose evidence storage;
otherwise a new owned temporary directory is created. Evidence includes target, SHA/tree, Composer
version, file hashes, archives and logs; failures are nonzero and retain failure evidence when initialized.
The artifact job uploads selected reports/archives. Download important evidence to durable owner-controlled
storage before tag; CI retention is finite. Preserve semantic review and owner approval alongside it.

## Qualification after approved integration

1. Owner reviews/merges the preparation PR to main; no tag or release is created by the workflows.
2. Record the actual main commit as the candidate release SHA for 1.2.0.
3. Require Full Applicable CI **and** Release Artifact Verification from the same workflow SHA. Both
   feed `CI Gate`; PR merge-ref/branch results do not qualify a later main commit.
4. Review semantic claims in the candidate, current distribution state and retained evidence. Automated
   string/structure checks are supplemental, not independent proof that prose guarantees are true.
5. Only after both gates and review pass on that exact main SHA does it become release-qualified.
   Any later commit intended for the same release needs qualification again.
6. Obtain the owner's separate tag/release/publication authorization and use exactly that SHA.
   Do not move an existing tag. Use [curated release notes](releases/1.2.0-notes.md), not a PR-body dump.

## Post-publication verification

After the authorized exact version is externally available, run the maintained local command using
its full release-qualified SHA:

```bash
composer verify:published -- --version=1.2.0 --sha=FULL_40_CHARACTER_QUALIFIED_SHA
```

The equivalent manual workflow is `published-artifact.yml`; its inputs are the exact version and SHA.
It does not publish. The script creates a fresh external Composer project with controlled environment,
isolated home/cache and no custom/path repository, records effective configuration/Composer version,
requires the exact version from the default Packagist channel, and verifies **observed dist mode** in
installed metadata. It rejects source fallback and mismatched source/dist references, verifies identity,
and compares every installed file against an independent Git export of the qualified SHA. Extra/missing
files or bytes fail. No source-only delivery exception is selected for this package.

Retain installed metadata/reference, manifest/file hashes and logs. A local archive install or runtime
harness does not substitute for this post-publication integrity evidence. The 1.2.0 run passed; for any future target PAV remains NOT RUN until its own external verification.
A failure makes integrity NOT PASS; it does not itself erase separately proven external availability.
Conflicting availability evidence requires canonical publication-state re-verification.

Independently refresh live/default-branch publication facts after external availability is established:
record the actual publication date and existing release link in CHANGELOG, update its Unreleased
compare base and any stale current publication text. Dynamic registry badges need no fake static
replacement. Preserve historical qualification snapshots. Never retag/rewrite the immutable artifact
to add later publication facts; any tracked artifact correction needs a new version. Close #7 only
when applicable main-SHA qualification and post-publication evidence/freshness are complete.


## Live checks after publication

When `publication_state_current` is published, `check:artifact` validates live publication identity and
rechecks the **original qualified tag**, using that tag's own archive-verification command in a temporary
owned independent clone. It never qualifies the later live-documentation SHA as a replacement 1.2.0 artifact.
The report separates live SHA from qualified SHA. For another release, choose a new exact target and
return to the unpublished candidate path. The release artifact itself is never rewritten.
