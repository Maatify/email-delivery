<?php

declare(strict_types=1);

use Maatify\EmailDelivery\Exception\EmailEnqueueConflictException;
use Tests\Support\ChildProcessFile;
use Tests\Support\EmailQueueFaultPdo;
use Tests\Support\EmailQueueTestFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var array{dir: string, name: string, key: string, namespace: string, context: array<string, mixed>, hold: bool, write_delay_ms: int} $spec */
$spec = json_decode((string) file_get_contents($argv[1] ?? ''), true, 512, JSON_THROW_ON_ERROR);
$base = $spec['dir'] . '/' . $spec['name'];
$pdo = new EmailQueueFaultPdo();
$connection = $pdo->query('SELECT CONNECTION_ID()');
if ($connection === false) {
    throw new RuntimeException('Worker connection identity is unavailable.');
}
// The parent reads the content of .connection and .result: they are written atomically (see ChildProcessFile).
// .before and .inserted are presence-only markers: the parent waits for them to exist and never reads them.
// Test-only: a pause inside each atomic write, after the temporary file is created and before its content.
$pause = $spec['write_delay_ms'] > 0 ? static function () use ($spec): void {
    usleep($spec['write_delay_ms'] * 1000);
} : null;
ChildProcessFile::write($base . '.connection', (string) $connection->fetchColumn(), $pause);
$pdo->beforeExecuteHook = static function (string $sql) use ($base): void {
    if (str_starts_with($sql, 'INSERT INTO cd_email_enqueue_receipts')) {
        file_put_contents($base . '.before', '1');
    }
};
$pdo->afterExecuteHook = static function (string $sql) use ($spec, $base): void {
    if ($spec['hold'] && str_starts_with($sql, 'INSERT INTO cd_email_enqueue_receipts')) {
        file_put_contents($base . '.inserted', '1');
        $deadline = microtime(true) + 15;
        while (!is_file($spec['dir'] . '/release')) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Owned winner barrier was not released.');
            }
            usleep(10000);
        }
    }
};
$result = ['pid' => getmypid(), 'receipt' => null, 'error' => null, 'driver' => null, 'sqlstate' => null];
try {
    $result['receipt'] = EmailQueueTestFactory::queue($pdo)->enqueueOnce(
        EmailQueueTestFactory::request($spec['key'], $spec['namespace'], $spec['context']),
    )->jsonSerialize();
} catch (Throwable $failure) {
    $result['error'] = $failure instanceof EmailEnqueueConflictException ? 'conflict' : $failure::class;
    for ($cause = $failure; $cause !== null; $cause = $cause->getPrevious()) {
        if ($cause instanceof PDOException) {
            $result['sqlstate'] = $cause->getCode();
            $result['driver'] = $cause->errorInfo[1] ?? null;
        }
    }
}
ChildProcessFile::write($base . '.result', json_encode($result, JSON_THROW_ON_ERROR), $pause);
