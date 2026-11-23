<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Issue #15: a parent test polls for a file its child process writes and reads it as soon as it exists. The child
 * must therefore never expose the file before its content is complete.
 */
final class ChildProcessFileTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/email-child-file-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0700));
        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * A real child process pauses 300 ms between creating the file and writing its content. The parent polls every
     * millisecond meanwhile, as the concurrency tests do: it may see no file, but never an empty or partial one.
     * Seeing the temporary file for most of the pause proves the parent was polling while the write was open.
     */
    public function testAParentPollingForAChildFileNeverReadsItBeforeItsContentIsComplete(): void
    {
        $path = $this->dir . '/result';
        $expected = str_repeat('complete ', 8192);
        $child = proc_open([
            PHP_BINARY, '-r',
            'require $argv[1]; Tests\Support\ChildProcessFile::write($argv[2], str_repeat("complete ", 8192), 300);',
            '--', dirname(__DIR__, 2) . '/vendor/autoload.php', $path,
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->dir . '/stdout', 'w'], 2 => ['file', $this->dir . '/stderr', 'w']], $pipes);
        self::assertIsResource($child);

        $reads = [];
        $temporarySeenAt = null;
        $finalSeenAt = null;
        $deadline = microtime(true) + 10;
        do {
            $running = proc_get_status($child)['running'];
            clearstatcache();
            if ($temporarySeenAt === null && (glob($path . '.*.tmp') ?: []) !== []) {
                $temporarySeenAt = microtime(true);
            }
            if (is_file($path)) {
                $finalSeenAt ??= microtime(true);
                $reads[] = (string) file_get_contents($path);
            }
            usleep(1_000);
        } while ($running && microtime(true) < $deadline);
        $exit = proc_close($child);

        self::assertSame(0, $exit, 'Child stderr: ' . file_get_contents($this->dir . '/stderr'));
        self::assertSame('', file_get_contents($this->dir . '/stderr'));
        self::assertNotNull($finalSeenAt, 'The parent saw the finished file.');
        self::assertSame([strlen($expected)], array_values(array_unique(array_map('strlen', $reads))), 'Every read of the file returned its whole content.');
        self::assertSame($expected, $reads[array_key_last($reads)]);
        self::assertNotNull($temporarySeenAt, 'The parent polled while the child was writing: the temporary file existed.');
        self::assertGreaterThanOrEqual(0.25, $finalSeenAt - $temporarySeenAt, 'The final name appeared only after the pause.');
        self::assertSame(['result', 'stderr', 'stdout'], array_values(array_diff(scandir($this->dir) ?: [], ['.', '..'])), 'No temporary file is left behind.');
    }

    /**
     * Every file a child script writes whose content its parent reads goes through ChildProcessFile::write(). A plain
     * file_put_contents() creates the file before writing it, so it is kept for presence-only markers: files the
     * parent waits for but never reads.
     */
    public function testChildScriptsUsePlainFileWritesOnlyForPresenceOnlyMarkers(): void
    {
        $scripts = glob(dirname(__DIR__) . '/Support/*-*.php') ?: [];
        self::assertSame(['email-enqueue-worker.php', 'email-outcome-writer.php', 'synthetic-email-process.php'], array_map('basename', $scripts));
        $plainWrites = [];
        foreach ($scripts as $script) {
            preg_match_all('/\bfile_put_contents\s*\(\s*([^,]+),/', (string) file_get_contents($script), $calls);
            foreach ($calls[1] as $target) {
                $plainWrites[] = basename($script) . ': ' . trim($target);
            }
        }

        self::assertSame([
            "email-enqueue-worker.php: \$base . '.before'",
            "email-enqueue-worker.php: \$base . '.inserted'",
        ], $plainWrites, 'Only presence-only markers are written with a plain file_put_contents().');
    }
}
