<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use RuntimeException;

/**
 * Test-only: how a child process hands a value to its parent through a file. Parents poll for the file and read
 * it as soon as it exists, so it must never be visible before its content is complete. It is written under a
 * temporary name in the same directory and then renamed into place: rename() within one directory replaces the
 * name atomically, so the parent sees either no file or the whole content.
 */
final class ChildProcessFile
{
    /**
     * @param (Closure(): void)|null $beforeContent test-only: runs after the temporary file is created and before
     *                                              its content is written, the window a non-atomic write would expose.
     *                                              A test holds the window open with it (a barrier or a pause).
     */
    public static function write(string $path, string $content, ?Closure $beforeContent = null): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('The temporary child file was not created.');
        }
        try {
            if ($beforeContent !== null) {
                $beforeContent();
            }
            if (fwrite($handle, $content) !== strlen($content)) {
                throw new RuntimeException('The child file was not written completely.');
            }
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $path)) {
            throw new RuntimeException('The child file was not renamed into place.');
        }
    }
}
