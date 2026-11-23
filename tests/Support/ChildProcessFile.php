<?php

declare(strict_types=1);

namespace Tests\Support;

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
     * @param int $delayMilliseconds test-only pause between creating the temporary file and writing its content.
     *                               It widens the window in which a non-atomic write would expose an empty file.
     */
    public static function write(string $path, string $content, int $delayMilliseconds = 0): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $handle = fopen($temporary, 'xb');
        if ($handle === false) {
            throw new RuntimeException('The temporary child file was not created.');
        }
        try {
            if ($delayMilliseconds > 0) {
                usleep($delayMilliseconds * 1000);
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
