<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #15: a parent test polls for a file its child process writes and reads it as soon as it exists. The child
 * must therefore never expose the file before its content is complete.
 */
final class ChildProcessFileTest extends TestCase
{
    /**
     * The child: writes 72 KiB through ChildProcessFile with a barrier inside the write. After the temporary file is
     * created and before its content is written, it creates `waiting` and blocks until the parent creates `go`.
     * Arguments: autoloader, final path, waiting marker, go marker.
     */
    private const CHILD = <<<'PHP'
        require $argv[1];
        Tests\Support\ChildProcessFile::write($argv[2], str_repeat('complete ', 8192), static function () use ($argv): void {
            touch($argv[3]);
            $deadline = microtime(true) + 30;
            while (!is_file($argv[4])) {
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('The parent never released the child.');
                }
                usleep(1000);
            }
        });
        PHP;

    private string $dir = '';
    /** @var resource|null */
    private $child = null;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/email-child-file-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0700));
        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        if (is_resource($this->child)) {
            touch($this->dir . '/go');
            if (proc_get_status($this->child)['running']) {
                proc_terminate($this->child);
            }
            proc_close($this->child);
        }
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** @return iterable<string, array{int}> */
    public static function parentDelays(): iterable
    {
        yield 'parent polls at once' => [0];
        yield 'parent descheduled 90 ms as the write opens' => [90];
        yield 'parent descheduled 140 ms as the write opens' => [140];
        yield 'parent descheduled 320 ms as the write opens' => [320];
    }

    /**
     * The child holds its write open, between creating the temporary file and writing the content, until the parent
     * releases it. Nothing here depends on timing: however late the parent looks (data sets: it loses the CPU right
     * as the write opens), the final name does not exist while the write is open, and holds the whole content once
     * it exists.
     */
    #[DataProvider('parentDelays')]
    public function testAParentNeverSeesAChildFileBeforeItsContentIsComplete(int $parentDelayMs): void
    {
        $path = $this->dir . '/result';
        $waiting = $this->dir . '/waiting';
        $go = $this->dir . '/go';
        $child = proc_open(
            [PHP_BINARY, '-r', self::CHILD, '--', dirname(__DIR__, 2) . '/vendor/autoload.php', $path, $waiting, $go],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->dir . '/stdout', 'w'], 2 => ['file', $this->dir . '/stderr', 'w']],
            $pipes,
        );
        self::assertIsResource($child);
        $this->child = $child;
        if ($parentDelayMs > 0) {
            // A parent scheduled late: it loses the CPU right as the child opens its write (the temporary file appears).
            $deadline = microtime(true) + 30;
            while ((glob($path . '.*.tmp') ?: []) === [] && !is_file($path) && microtime(true) < $deadline) {
                usleep(200);
            }
            usleep($parentDelayMs * 1000);
        }

        // The child is inside the write: the temporary file exists and its content is not written yet.
        // Before PHP 8.3 only the first status call after an exit reports the exit code, so every status is kept.
        $status = proc_get_status($child);
        $deadline = microtime(true) + 30;
        while (!is_file($waiting) && $status['running'] && microtime(true) < $deadline) {
            usleep(1_000);
            $status = proc_get_status($child);
        }
        clearstatcache();
        self::assertFileExists($waiting, 'The child reached the barrier inside the write. Child stderr: ' . file_get_contents($this->dir . '/stderr'));
        self::assertFileDoesNotExist($path, 'The final name does not exist while the write is open.');
        $temporaries = glob($path . '.*.tmp') ?: [];
        self::assertCount(1, $temporaries, 'The temporary file exists while the write is open.');
        self::assertSame(0, filesize($temporaries[0]), 'Its content is not written yet.');

        // Released, the child writes the content and renames the file into place.
        touch($go);
        $deadline = microtime(true) + 30;
        while ($status['running'] && microtime(true) < $deadline) {
            usleep(1_000);
            $status = proc_get_status($child);
        }
        proc_close($child);
        $this->child = null;

        self::assertFalse($status['running'], 'The child finished after the release.');
        self::assertSame(0, $status['exitcode'], 'Child stderr: ' . file_get_contents($this->dir . '/stderr'));
        self::assertSame('', file_get_contents($this->dir . '/stderr'));
        self::assertSame(str_repeat('complete ', 8192), file_get_contents($path), 'The final name holds the whole content.');
        self::assertSame(['go', 'result', 'stderr', 'stdout', 'waiting'], array_values(array_diff(scandir($this->dir) ?: [], ['.', '..'])), 'No temporary file is left behind.');
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
