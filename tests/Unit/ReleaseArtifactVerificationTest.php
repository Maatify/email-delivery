<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

use function Maatify\EmailDelivery\ReleaseTools\requiredFiles;
use function Maatify\EmailDelivery\ReleaseTools\verifyIdentity;
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
        verifyIdentity($this->directory, '1.2.0');
        $required = requiredFiles($this->directory);
        $path = $this->archive();
        self::assertCount(count($required), verifyZip($path, $this->directory, $required));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function staleIdentity(): iterable
    {
        yield 'version' => ['VERSION', '1.2.0', '1.1.1'];
        yield 'artifact identity' => ['README.md', 'Release artifact target: **1.2.0**', 'Release artifact target: **1.1.1**'];
        yield 'install target' => ['README.md', 'composer require maatify/email-delivery:1.2.0', 'composer require maatify/email-delivery:1.1.1'];
        yield 'changelog allocation' => ['CHANGELOG.md', '## [1.2.0]', '## [Unreleased legacy allocation]'];
        yield 'unallocated changes' => ['CHANGELOG.md', '## [Unreleased]', "## [Unreleased]\n\n### Added\nAllocated change left behind."];
        yield 'false release link' => ['CHANGELOG.md', '## [1.2.0]', "## [1.2.0]\n\nhttps://github.com/Maatify/email-delivery/releases/tag/v1.2.0"];
        yield 'security identity' => ['SECURITY.md', '**1.2.0**', '**1.1.1**'];
        yield 'target metadata' => ['release-target.json', '"target": "1.2.0"', '"target": "1.1.1"'];
    }

    #[DataProvider('staleIdentity')]
    public function testPreviousVersionOrAllocationCannotQualify(string $path, string $before, string $after): void
    {
        $content = file_get_contents($this->directory . '/' . $path);
        self::assertIsString($content);
        self::assertStringContainsString($before, $content, 'Mutation must hit the actual candidate content.');
        file_put_contents($this->directory . '/' . $path, str_replace($before, $after, $content));
        $this->expectException(RuntimeException::class);
        verifyIdentity($this->directory, '1.2.0');
    }

    /** @return iterable<string, array{string, string|null}> */
    public static function unsafeArchives(): iterable
    {
        yield 'missing guide' => ['docs/guides/USAGE_GUIDE.md', null];
        yield 'missing llms' => ['llms.txt', null];
        yield 'wrong source bytes' => ['README.md', 'wrong artifact'];
        yield 'environment file' => ['.env.fixture', 'not-a-real-secret'];
        yield 'development data' => ['.idea/project.xml', 'fixture'];
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
}
