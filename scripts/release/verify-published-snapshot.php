<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\ReleaseTools;

/** Recheck the frozen release and live identity; never qualify a later SHA for the same version. */
function verifyPublishedSnapshot(string $root, string $directory, string $version, string $liveSha, bool $dirty): void
{
    $metadata = jsonFile($root . '/release-target.json');
    $qualified = $metadata['qualified_sha'] ?? null;
    ensure(is_string($qualified) && preg_match('/\A[a-f0-9]{40}\z/', $qualified) === 1, 'Qualified release SHA required.');
    ensure(trim(run(['git', 'rev-parse', 'refs/tags/v' . $version . '^{}'], $root)) === $qualified, 'Immutable tag moved or is missing. Fetch full tag/history first.');
    $source = $directory . '/qualified-source';
    $proof = $directory . '/qualified-artifact';
    $environment = controlledEnvironment($directory);
    $environment['EMAIL_RELEASE_EVIDENCE_DIR'] = $proof;
    // A real clone keeps .git a directory; Composer archives of linked worktrees can leak the .git pointer file.
    run(['git', 'clone', '--no-hardlinks', '--no-checkout', $root, $source], $root);
    try {
        run(['git', 'checkout', '--detach', $qualified], $source);
        $output = run([PHP_BINARY, $source . '/scripts/release/verify-artifact.php'], $source, $environment);
        file_put_contents($directory . '/qualified-artifact.log', $output);
        $report = jsonFile($proof . '/artifact-verification.json');
        verifyFrozenReport($report, $version, $qualified);
        ensure(trim(run(['git', 'rev-parse', 'refs/tags/v' . $version . '^{}'], $root)) === $qualified, 'Immutable tag changed during verification.');
        $result = ['gate' => 'Release Artifact Verification', 'outcome' => 'PASS', 'target' => $version,
            'mode' => $dirty ? 'dirty-live-state-rehearsal' : 'published-frozen-artifact-and-live-state',
            'live_source_sha' => $liveSha, 'qualified_sha' => $qualified, 'frozen_tree' => $report['candidate_tree'] ?? null,
            'live_publication_identity' => 'PASS', 'frozen_archive_verification' => 'PASS',
            'publication_evidence_url' => $metadata['publication_evidence_url'] ?? null,
            'boundary' => 'The live reconciliation SHA is not a new release-qualified SHA for this already published version. The tag is unchanged.'];
        file_put_contents($directory . '/artifact-verification.json', json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
        echo 'PASS: live publication identity and frozen ', $version, ' artifact ', $qualified, '. Evidence: ', $directory, "\n";
    } finally {
        // Remove only the newly created clone, never following links or pruning shared Git metadata.
        ensure(realpath($source) === $directory . '/qualified-source' && !is_link($source), 'Refusing cleanup outside the owned clone.');
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($source);
    }
}
