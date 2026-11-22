<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/** Records worker log calls so tests can assert on operator-visible outcomes. */
final class WorkerRecordingLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => is_string($level) ? $level : 'unknown', 'message' => (string) $message, 'context' => $context];
    }

    /** @return list<array<string, mixed>> contexts of the records with this level and message */
    public function contexts(string $level, string $message): array
    {
        $found = [];
        foreach ($this->records as $record) {
            if ($record['level'] === $level && $record['message'] === $message) {
                $found[] = $record['context'];
            }
        }
        return $found;
    }
}
