<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

use function Maatify\EmailDelivery\ReleaseTools\requiredFiles;
use function Maatify\EmailDelivery\ReleaseTools\verifyIdentity;
use function Maatify\EmailDelivery\ReleaseTools\verifyFrozenReport;
use function Maatify\EmailDelivery\ReleaseTools\verifyInstalledMetadata;
use function Maatify\EmailDelivery\ReleaseTools\verifyZip;

require_once dirname(__DIR__, 2) . '/scripts/release/lib.php';

final class ReleaseArtifactVerificationTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/email7-verifier-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0700));
        $root = dirname(__DIR__, 2);
        foreach (requiredFiles($root) as $path) {
            $parent = dirname($this->directory . '/' . $path);
            if (!is_dir($parent)) {
                self::assertTrue(mkdir($parent, 0700, true));
            }
            if (!copy($root . '/' . $path, $this->directory . '/' . $path)) {
                throw new RuntimeException('Cannot copy owned verifier fixture.');
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->directory === '') {
            return;
        }
        self::assertMatchesRegularExpression('~\A' . preg_quote(sys_get_temp_dir(), '~') . '/email7-verifier-[a-f0-9]{12}\z~', $this->directory);
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->directory);
    }

    public function testPreparedSourceAndCompleteArchivePassAsPositiveControls(): void
    {
        verifyIdentity($this->directory, '1.3.0');
        $required = requiredFiles($this->directory);
        $path = $this->archive();
        self::assertCount(count($required), verifyZip($path, $this->directory, $required));
    }

    public function testPublishedTargetAllowsFutureUnreleasedChanges(): void
    {
        $file = $this->directory . '/CHANGELOG.md';
        $content = (string) file_get_contents($file);
        self::assertStringContainsString("## [Unreleased]\n", $content);
        file_put_contents($file, str_replace("## [Unreleased]\n", "## [Unreleased]\n\n### Added\n- Prepare the next release.\n", $content));
        verifyIdentity($this->directory, '1.3.0');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function staleIdentity(): iterable
    {
        yield 'version' => ['VERSION', '1.3.0', '1.2.0'];
        yield 'artifact identity' => ['README.md', 'Release artifact target: **1.3.0**', 'Release artifact target: **1.2.0**'];
        yield 'install target' => ['README.md', 'composer require maatify/email-delivery:1.3.0', 'composer require maatify/email-delivery:1.2.0'];
        yield 'changelog allocation' => ['CHANGELOG.md', '## [1.3.0]', '## [Unreleased legacy allocation]'];
        yield 'published compare base' => ['CHANGELOG.md', '[Unreleased]: https://github.com/Maatify/email-delivery/compare/v1.3.0...HEAD', '[Unreleased]: https://github.com/Maatify/email-delivery/compare/v1.2.0...HEAD'];
        yield 'false release link' => ['CHANGELOG.md', '## [1.3.0]', "## [1.3.0]\n\nhttps://github.com/Maatify/email-delivery/releases/tag/v1.3.0"];
        yield 'security identity' => ['SECURITY.md', '**1.3.0**', '**1.2.0**'];
        yield 'target metadata' => ['release-target.json', '"target": "1.3.0"', '"target": "1.2.0"'];
        yield 'qualification state metadata' => ['release-target.json', '"publication_state_at_qualification": "unpublished"', '"publication_state_at_qualification": "published"'];
    }

    #[DataProvider('staleIdentity')]
    public function testPreviousVersionOrAllocationCannotQualify(string $path, string $before, string $after): void
    {
        $content = file_get_contents($this->directory . '/' . $path);
        self::assertIsString($content);
        self::assertStringContainsString($before, $content, 'Mutation must hit the actual candidate content.');
        file_put_contents($this->directory . '/' . $path, str_replace($before, $after, $content));
        $this->expectException(RuntimeException::class);
        verifyIdentity($this->directory, '1.3.0');
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function unqualifiedPublicationClaims(): iterable
    {
        yield 'README latest release' => ['README.md', 'latest externally published version **at qualification**', 'latest externally published version currently'];
        yield 'security latest release' => ['SECURITY.md', 'At qualification, version **1.2.0** was the latest **published**', 'Version **1.2.0** is the latest **published**'];
        yield 'release notes status' => ['docs/releases/1.3.0-notes.md', 'at qualification, target 1.3.0 was **unpublished**', 'target 1.3.0 is **unpublished**'];
        yield 'prepared source claim' => ['examples/README.md', 'target 1.3.0. Worker reconnect', 'follow the prepared 1.3.0 source. Worker reconnect'];
        yield 'example source publication claim' => ['examples/worker-runner.php', 'This example follows 1.3.0;', 'This example follows the unpublished 1.3.0 candidate;'];
        yield 'SVG candidate label' => ['docs/assets/architecture-diagram.svg', '1.3.0 reclaims expired processing leases.', '1.3.0 candidate reclaims expired leases.'];
    }

    #[DataProvider('unqualifiedPublicationClaims')]
    public function testUnqualifiedMutablePublicationClaimsCannotQualify(string $path, string $before, string $after): void
    {
        $file = $this->directory . '/' . $path;
        $content = (string) file_get_contents($file);
        self::assertStringContainsString($before, $content, 'Mutation must hit the actual qualification snapshot.');
        file_put_contents($file, str_replace($before, $after, $content));
        $this->expectException(RuntimeException::class);
        verifyIdentity($this->directory, '1.3.0');
    }

    public function testHistoricalAndCurrentPublicationClaimsInOneSentenceCannotShareQualifier(): void
    {
        $file = $this->directory . '/README.md';
        $content = (string) file_get_contents($file);
        file_put_contents($file, $content . "\nAt qualification, 1.2.0 was latest published, and the current publication state is published.\n");
        $this->expectException(RuntimeException::class);
        verifyIdentity($this->directory, '1.3.0');
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function unsafeArchives(): iterable
    {
        yield 'missing guide' => ['docs/guides/USAGE_GUIDE.md', null];
        yield 'missing llms' => ['llms.txt', null];
        yield 'wrong source bytes' => ['README.md', 'wrong artifact'];
        yield 'environment file' => ['.env.fixture', 'not-a-real-secret'];
        yield 'development data' => ['.idea/project.xml', 'fixture'];
        yield 'assistant worktree data' => ['.claude/worktrees/fixture/.git', 'fixture'];
        yield 'parent traversal' => ['../escape.txt', 'fixture'];
    }

    #[DataProvider('unsafeArchives')]
    public function testActualArchiveCannotLoseRequiredFilesOrContainUnsafeEntries(string $path, ?string $bytes): void
    {
        $archive = $this->archive();
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive));
        if ($bytes === null) {
            self::assertTrue($zip->deleteName($path));
        } else {
            self::assertTrue($zip->addFromString($path, $bytes));
        }
        $zip->close();
        $this->expectException(RuntimeException::class);
        verifyZip($archive, $this->directory, requiredFiles($this->directory));
    }

    private function archive(): string
    {
        $archive = $this->directory . '/candidate.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive, ZipArchive::CREATE));
        foreach (requiredFiles($this->directory) as $path) {
            if (!$zip->addFile($this->directory . '/' . $path, $path)) {
                throw new RuntimeException('Cannot add owned fixture file.');
            }
        }
        $zip->close();
        return $archive;
    }

    public function testSymlinkArchiveMetadataIsRejectedBeforeExtraction(): void
    {
        $archive = $this->archive();
        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive));
        self::assertTrue($zip->setExternalAttributesName('README.md', ZipArchive::OPSYS_UNIX, 0120777 << 16));
        $zip->close();
        $this->expectException(RuntimeException::class);
        verifyZip($archive, $this->directory, requiredFiles($this->directory));
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function badInstallations(): iterable
    {
        yield 'source fallback' => ['installation-source', 'source'];
        yield 'wrong version' => ['version', '1.1.1'];
        yield 'missing dist ref' => ['dist', ['type' => 'zip', 'url' => 'https://example.invalid/fixture']];
        yield 'wrong dist ref' => ['dist', ['type' => 'zip', 'url' => 'https://example.invalid/fixture', 'reference' => str_repeat('b', 40)]];
        yield 'wrong source ref' => ['source', ['reference' => str_repeat('b', 40)]];
    }

    #[DataProvider('badInstallations')]
    public function testPostPublicationEvidenceRejectsSourceFallbackAndIdentityMismatch(string $field, mixed $value): void
    {
        $valid = ['name' => 'maatify/email-delivery', 'version' => 'v1.2.0', 'installation-source' => 'dist',
            'dist' => ['type' => 'zip', 'url' => 'https://example.invalid/fixture', 'reference' => str_repeat('a', 40)],
            'source' => ['reference' => str_repeat('a', 40)]];
        self::assertSame($valid, verifyInstalledMetadata(['packages' => [$valid]], '1.2.0', str_repeat('a', 40)));
        $valid[$field] = $value;
        $this->expectException(RuntimeException::class);
        verifyInstalledMetadata(['packages' => [$valid]], '1.2.0', str_repeat('a', 40));
    }

    public function testActualPreparedCandidateIdentityIsValid(): void
    {
        verifyIdentity(dirname(__DIR__, 2), '1.3.0');
        $this->addToAssertionCount(1);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidFrozenEvidence(): iterable
    {
        yield 'later live SHA' => ['candidate_sha', str_repeat('b', 40)];
        yield 'wrong version' => ['target', '1.3.1'];
        yield 'rehearsal' => ['mode', 'dirty-worktree-rehearsal'];
        yield 'missing archive check' => ['git_archive_verified', false];
        yield 'failed production autoload' => ['archive_production_autoload', 'FAIL'];
    }

    #[DataProvider('invalidFrozenEvidence')]
    public function testLiveReconciliationCannotReplaceQualifiedReleaseEvidence(string $field, mixed $value): void
    {
        $report = ['target' => '1.3.0', 'candidate_sha' => str_repeat('a', 40), 'outcome' => 'PASS',
            'mode' => 'committed-candidate', 'git_archive_verified' => true, 'archive_production_autoload' => 'PASS'];
        verifyFrozenReport($report, '1.3.0', str_repeat('a', 40));
        $report[$field] = $value;
        $this->expectException(RuntimeException::class);
        verifyFrozenReport($report, '1.3.0', str_repeat('a', 40));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function invalidPublishedIdentity(): iterable
    {
        yield 'unknown state' => ['release-target.json', '"publication_state_in_this_snapshot": "published"', '"publication_state_in_this_snapshot": "unknown"'];
        yield 'invalid actual date' => ['release-target.json', '2026-11-23T20:09:38Z', '2026-02-31T20:09:38Z'];
        yield 'stale live date' => ['CHANGELOG.md', '## [1.3.0] - 2026-11-23', '## [1.3.0] - 2026-11-22'];
        yield 'missing release link' => ['CHANGELOG.md', '[1.3.0]: https://github.com/Maatify/email-delivery/releases/tag/v1.3.0', '[1.3.0]: https://example.invalid'];
        yield 'stale published compare base' => ['CHANGELOG.md', '[Unreleased]: https://github.com/Maatify/email-delivery/compare/v1.3.0...HEAD', '[Unreleased]: https://github.com/Maatify/email-delivery/compare/v1.2.0...HEAD'];
        yield 'stale latest published README baseline' => ['README.md', '1.3.0 became the latest published release', '1.2.0 became the latest published release'];
        yield 'stale README publication date' => ['README.md', 'published 2026-11-23 UTC).', 'published 2026-11-06 UTC).'];
        yield 'stale latest published security baseline' => ['SECURITY.md', 'latest published version within the supported 1.x line is **1.3.0**.', 'latest published version within the supported 1.x line is **1.2.0**.'];
        yield 'missing publication evidence' => ['release-target.json', 'publication-verification.json', 'missing-evidence.json'];
        yield 'missing live README publication update' => ['README.md', '1.3.0 became the latest published release', '1.2.0 became the latest published release'];
    }

    #[DataProvider('invalidPublishedIdentity')]
    public function testPublishedLiveFactsMustMatchEvidenceAndActualDate(string $path, string $before, string $after): void
    {
        foreach (['release-target.json', 'README.md', 'CHANGELOG.md', 'SECURITY.md'] as $file) {
            if (!copy(dirname(__DIR__, 2) . '/' . $file, $this->directory . '/' . $file)) {
                throw new RuntimeException('Cannot restore published fixture.');
            }
        }
        $this->makePublishedFixture();
        verifyIdentity($this->directory, '1.3.0');
        $content = (string) file_get_contents($this->directory . '/' . $path);
        self::assertStringContainsString($before, $content, 'Published mutation must hit the real field.');
        file_put_contents($this->directory . '/' . $path, str_replace($before, $after, $content));
        $this->expectException(RuntimeException::class);
        verifyIdentity($this->directory, '1.3.0');
    }

    private function makePublishedFixture(): void
    {
        $targetPath = $this->directory . '/release-target.json';
        $target = json_decode((string) file_get_contents($targetPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($target);
        $target['publication_state_in_this_snapshot'] = 'published';
        $target['published_at'] = '2026-11-23T20:09:38Z';
        $target['qualified_sha'] = str_repeat('a', 40);
        $target['publication_evidence_url'] = 'https://github.com/Maatify/email-delivery/releases/download/v1.3.0/publication-verification.json';
        file_put_contents($targetPath, json_encode($target, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $readmePath = $this->directory . '/README.md';
        $readme = (string) file_get_contents($readmePath);
        $readme .= "\nAt publication update on 2026-11-23, 1.3.0 became the latest published release (published 2026-11-23 UTC).\n";
        file_put_contents($readmePath, $readme);

        $changelogPath = $this->directory . '/CHANGELOG.md';
        $changelog = (string) file_get_contents($changelogPath);
        $changelog = str_replace('## [1.3.0]', '## [1.3.0] - 2026-11-23', $changelog);
        $changelog = str_replace('[Unreleased]: https://github.com/Maatify/email-delivery/compare/v1.2.0...HEAD', '[Unreleased]: https://github.com/Maatify/email-delivery/compare/v1.3.0...HEAD', $changelog);
        $changelog .= "\n[1.3.0]: https://github.com/Maatify/email-delivery/releases/tag/v1.3.0\n";
        file_put_contents($changelogPath, $changelog);

        $securityPath = $this->directory . '/SECURITY.md';
        $security = (string) file_get_contents($securityPath);
        $security .= "\nAt publication update on 2026-11-23, the latest published version within the supported 1.x line is **1.3.0**.\n";
        file_put_contents($securityPath, $security);
    }

}
