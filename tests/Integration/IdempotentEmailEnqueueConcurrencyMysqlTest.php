<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\IsolatedEmailMysqlTestCase;

final class IdempotentEmailEnqueueConcurrencyMysqlTest extends IsolatedEmailMysqlTestCase
{
    private string $dir = '';
    /** @var array<string, resource> */
    private array $children = [];

    protected function setUp(): void
    {
        parent::setUp();
        $dir = sys_get_temp_dir() . '/email4-concurrency-' . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0700)) {
            throw new \RuntimeException('Cannot create an owned concurrency fixture directory.');
        }
        $this->dir = $dir;
    }

    protected function tearDown(): void
    {
        // Parent setUp may skip before a directory (or even a PDO) has been assigned.
        if ($this->dir === '') {
            parent::tearDown();
            return;
        }
        $pattern = '~\A' . preg_quote(sys_get_temp_dir() . '/email4-concurrency-', '~') . '[a-f0-9]{12}\z~';
        if (preg_match($pattern, $this->dir) !== 1 || !is_dir($this->dir) || is_link($this->dir)) {
            throw new \RuntimeException('Refusing cleanup outside the owned concurrency directory.');
        }
        try {
            file_put_contents($this->dir . '/release', '1');
            foreach ($this->children as $process) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                proc_close($process);
            }
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                if (is_file($file) || is_link($file)) {
                    unlink($file);
                }
            }
            rmdir($this->dir);
        } finally {
            parent::tearDown();
        }
    }

    /** @param array<string, mixed> $context */
    private function start(string $name, bool $hold = false, string $namespace = 'scope', array $context = ['name' => 'fixture']): void
    {
        $spec = $this->dir . '/' . $name . '.spec';
        file_put_contents($spec, json_encode(['dir' => $this->dir, 'name' => $name, 'namespace' => $namespace,
            'key' => 'same-key', 'context' => $context, 'hold' => $hold], JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/email-enqueue-worker.php', $spec], [
            0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->dir . '/' . $name . '.stdout', 'w'],
            2 => ['file', $this->dir . '/' . $name . '.stderr', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        $this->children[$name] = $process;
    }

    private function waitFile(string $name, string $suffix): string
    {
        $path = $this->dir . '/' . $name . '.' . $suffix;
        $deadline = microtime(true) + 10;
        while (!is_file($path) && microtime(true) < $deadline) {
            if (!proc_get_status($this->children[$name])['running']) {
                break;
            }
            usleep(10000);
        }
        self::assertFileExists($path, 'Owned child stderr: ' . file_get_contents($this->dir . '/' . $name . '.stderr'));
        return (string) file_get_contents($path);
    }

    /** @return array{pid: int, receipt: array<string, mixed>|null, error: string|null, driver: int|null, sqlstate: string|null} */
    private function childResult(string $name): array
    {
        $data = $this->waitFile($name, 'result');
        self::assertSame('', file_get_contents($this->dir . '/' . $name . '.stderr'));
        /** @var array{pid: int, receipt: array<string, mixed>|null, error: string|null, driver: int|null, sqlstate: string|null} $result */
        $result = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        $evidence = getenv('EMAIL_DELIVERY_IT_EVIDENCE_DIR');
        if ($evidence !== false && is_dir($evidence)) {
            file_put_contents($evidence . '/concurrency.jsonl', json_encode([
                'scenario' => $this->nameWithDataSet(), 'child' => $name,
                'pid' => $result['pid'], 'error' => $result['error'], 'driver' => $result['driver'], 'sqlstate' => $result['sqlstate'],
            ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        }
        return $result;
    }

    /** @param list<string> $names */
    private function assertLockWaits(array $names): void
    {
        $ids = [];
        foreach ($names as $name) {
            $this->waitFile($name, 'before');
            $ids[] = (int) $this->waitFile($name, 'connection');
        }
        $sql = 'SELECT COUNT(*) FROM information_schema.INNODB_TRX WHERE trx_state=\'LOCK WAIT\' AND trx_mysql_thread_id IN (' . implode(',', $ids) . ')';
        $deadline = microtime(true) + 10;
        do {
            $waiting = (int) self::scalar($this->pdo, $sql);
            if ($waiting === count($ids)) {
                break;
            }
            // InnoDB transaction metadata needs >100ms idle between observations to refresh.
            usleep(200000);
        } while (microtime(true) < $deadline);
        self::assertSame(count($ids), $waiting, 'All named competitor connections must really wait on the uncommitted winner.');
    }

    /** @return iterable<string, array{int}> */
    public static function competitors(): iterable
    {
        yield 'two processes' => [1];
        yield 'four processes' => [3];
    }

    #[DataProvider('competitors')]
    public function testConcurrentSameKeyHasOneCommittedWinnerAndFreshProcessReplay(int $count): void
    {
        $this->start('winner', true);
        $this->waitFile('winner', 'inserted');
        $names = [];
        for ($i = 0; $i < $count; ++$i) {
            $names[] = 'peer' . $i;
            $this->start('peer' . $i);
        }
        $this->assertLockWaits($names);
        file_put_contents($this->dir . '/release', '1');
        $winner = $this->childResult('winner');
        self::assertNull($winner['error']);
        foreach ($names as $name) {
            $peer = $this->childResult($name);
            if ($peer['error'] !== null) {
                self::assertSame('40001', $peer['sqlstate']);
                self::assertSame(1213, $peer['driver']);
                // Deadlock is NOT a duplicate win. Recovery is explicit, same key, fresh process.
                $this->start($name . '-recovery');
                $peer = $this->childResult($name . '-recovery');
            }
            self::assertNull($peer['error']);
            self::assertSame($winner['receipt'], $peer['receipt']);
        }
        $this->start('restart');
        $restart = $this->childResult('restart');
        self::assertNotSame($winner['pid'], $restart['pid']);
        self::assertNull($restart['error']);
        self::assertSame($winner['receipt'], $restart['receipt']);
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }

    public function testConcurrentDifferentContentCannotReplaceTheWinner(): void
    {
        $this->start('winner', true);
        $this->waitFile('winner', 'inserted');
        $this->start('different', context: ['name' => 'different']);
        $this->assertLockWaits(['different']);
        file_put_contents($this->dir . '/release', '1');
        $winner = $this->childResult('winner');
        self::assertNull($winner['error']);
        $other = $this->childResult('different');
        self::assertSame('conflict', $other['error']);
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        $this->start('restart');
        self::assertSame($winner['receipt'], $this->childResult('restart')['receipt']);
    }

    public function testOtherNamespaceCanCommitWhileTheFirstNamespaceWinnerIsStillUncommitted(): void
    {
        $this->start('winner', true);
        $this->waitFile('winner', 'inserted');
        $this->start('other', namespace: 'another-scope');
        $other = $this->childResult('other');
        self::assertNull($other['error']);
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        file_put_contents($this->dir . '/release', '1');
        $winner = $this->childResult('winner');
        self::assertNull($winner['error']);
        self::assertNotSame($winner['receipt'], $other['receipt']);
        self::assertSame('2', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('2', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }
}
