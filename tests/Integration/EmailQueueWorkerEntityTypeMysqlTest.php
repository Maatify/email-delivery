<?php

declare(strict_types=1);

namespace Tests\Integration;

use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Renderer\TwigEmailRenderer;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use PDO;
use PDOException;
use Tests\Support\EmailQueueFaultPdo;
use Tests\Support\EmailQueueTestFactory as Factory;
use Tests\Support\IsolatedEmailMysqlTestCase;
use Tests\Support\WorkerRecordingLogger;
use Tests\Support\WorkerRecordingTransport;

/**
 * A worker dedicated to one entity type in a queue shared with other types: processBatchByEntityType() takes,
 * reclaims and processes only rows of its type, and locks only candidates of its type (a candidate whose type
 * changed before the lock is not taken, and is locked only until the claim ends); every other row stays
 * unlocked for its own worker.
 */
final class EmailQueueWorkerEntityTypeMysqlTest extends IsolatedEmailMysqlTestCase
{
    private WorkerRecordingTransport $transport;
    private WorkerRecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec('SET SESSION lock_wait_timeout = 30');
        $this->transport = new WorkerRecordingTransport();
        $this->logger = new WorkerRecordingLogger();
    }

    protected function tearDown(): void
    {
        gc_collect_cycles();
        if (isset($this->pdo)) {
            $leftover = $this->pdo->query('SELECT ID FROM information_schema.PROCESSLIST WHERE DB = DATABASE() AND ID <> CONNECTION_ID()');
            foreach (self::ints($leftover === false ? [] : $leftover->fetchAll(PDO::FETCH_COLUMN)) as $thread) {
                try {
                    $this->pdo->exec('KILL CONNECTION ' . $thread);
                } catch (PDOException) {
                    // Already closed.
                }
            }
        }
        parent::tearDown();
    }

    public function testAScopedBatchSelectsLocksAndProcessesOnlyItsEntityType(): void
    {
        // The other type is ahead in (priority, id) order: a scoped batch must look past it, not take it.
        $users = [$this->enqueue('user-1', 'user', 1), $this->enqueue('user-2', 'user', 1), $this->enqueue('user-3', 'user', 2)];
        // An expired lease of the other type is ahead too: neither candidate read may pick it up.
        $userExpired = $this->stale($this->enqueue('user-expired', 'user', 1), 1, gmdate('Y-m-d H:i:s', time() - 3600));
        $admins = [$this->enqueue('admin-1', 'admin', 3), $this->enqueue('admin-2', 'admin', 3), $this->enqueue('admin-3', 'admin', 4)];
        $connection = new EmailQueueFaultPdo();
        $workerThread = (int) self::scalar($connection, 'SELECT CONNECTION_ID()');
        $mysql = self::isMysql($connection);
        $observed = ['locking_reads' => 0, 'waited' => [], 'rows_locked' => null, 'locked_ids' => null, 'other_locks' => null];
        $connection->afterExecuteHook = function (string $sql) use (&$observed, $workerThread, $mysql, $users, $userExpired): void {
            if (!str_contains($sql, 'FOR UPDATE')) {
                return;
            }
            // The claim transaction is open, right after its locking read.
            ++$observed['locking_reads'];
            $other = self::connection();
            $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
            usleep(150_000); // information_schema.INNODB_TRX is refreshed at most every 0.1 s
            $observed['rows_locked'] = (int) self::scalar($other, "SELECT trx_rows_locked FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = $workerThread");
            if ($mysql) {
                $locks = ($other->query("
                    SELECT l.INDEX_NAME, l.LOCK_MODE, l.LOCK_DATA FROM performance_schema.data_locks l
                    JOIN performance_schema.threads t ON t.THREAD_ID = l.THREAD_ID
                    WHERE t.PROCESSLIST_ID = $workerThread AND l.LOCK_TYPE = 'RECORD'") ?: throw new \LogicException('data_locks'))->fetchAll(PDO::FETCH_NUM);
                $observed['locked_ids'] = [];
                foreach ($locks as $lock) {
                    self::assertIsArray($lock);
                    [$index, $mode, $data] = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $lock);
                    if ($data !== 'supremum pseudo-record') {
                        $observed['locked_ids'][] = "$index $mode $data";
                    }
                }
                sort($observed['locked_ids']);
            }
            // The other type's worker writes its own rows meanwhile.
            foreach ([...$users, $userExpired] as $id) {
                $other->beginTransaction();
                try {
                    $other->exec("UPDATE cd_email_queue SET last_error = 'other worker' WHERE id = $id");
                } catch (PDOException $failure) {
                    $observed['waited'][] = $id . ': ' . self::driverCode($failure);
                } finally {
                    $other->rollBack();
                }
            }
        };

        $this->worker($connection)->processBatchByEntityType('admin');

        self::assertSame(1, $observed['locking_reads']);
        self::assertSame([], $observed['waited'], 'No row of the other type is locked by the scoped claim.');
        self::assertGreaterThanOrEqual(3, $observed['rows_locked']);
        self::assertLessThanOrEqual(6, $observed['rows_locked'], 'Only its candidates, at most one page boundary each.');
        if ($mysql) {
            self::assertSame(array_map(static fn (int $id): string => "PRIMARY X,REC_NOT_GAP $id", $admins), $observed['locked_ids'], 'Only its own rows are locked (MySQL data_locks).');
        }
        self::assertSame(3, $this->transport->calls);
        foreach ($admins as $id) {
            self::assertSame(['sent', 1], $this->state($id));
        }
        foreach ($users as $id) {
            self::assertSame(['pending', 0], $this->state($id), 'The other type stays pending and untouched.');
        }
        self::assertSame(['processing', 1], $this->state($userExpired), 'The other type\'s expired lease is not reclaimed.');

        // ...and is still available to its own worker.
        $this->worker($this->pdo)->processBatchByEntityType('user');
        self::assertSame(7, $this->transport->calls);
        foreach ($users as $id) {
            self::assertSame(['sent', 1], $this->state($id));
        }
        self::assertSame(['sent', 2], $this->state($userExpired));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function tableShapes(): iterable
    {
        foreach (['package schema' => 'package', 'application-like table (utf8mb4_unicode_ci VARCHAR(32) NOT NULL, TIMESTAMP lease, entity index)' => 'application'] as $name => $shape) {
            yield $name => [$shape, false];
            yield $name . ' + 006' => [$shape, true];
        }
    }

    /**
     * The column compares with utf8mb4_unicode_ci: case- and accent-insensitive, trailing spaces ignored. A scoped
     * batch must still take only its exact type, byte for byte, and leave every look-alike type unlocked.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('tableShapes')]
    public function testAScopedBatchTakesOnlyItsExactEntityTypeByteForByte(string $shape, bool $with006): void
    {
        $this->shapeTable($shape, $with006);
        $lookAlikes = [];
        foreach (['Admin', 'ADMIN', 'admín', 'admin '] as $index => $type) {
            $lookAlikes[$type] = $this->enqueue('look-alike-' . $index, $type, 1);
        }
        // A look-alike type's expired lease too: neither candidate read may take it.
        $lookAlikeExpired = $this->stale($this->enqueue('look-alike-expired', 'ADMIN', 1), 1, gmdate('Y-m-d H:i:s', time() - 3600));
        $exact = [$this->enqueue('admin-1', 'admin', 2), $this->enqueue('admin-2', 'admin', 2)];
        $connection = new EmailQueueFaultPdo();
        $workerThread = (int) self::scalar($connection, 'SELECT CONNECTION_ID()');
        $mysql = self::isMysql($connection);
        $observed = ['waited' => [], 'locked_ids' => null];
        $connection->afterExecuteHook = function (string $sql) use (&$observed, $workerThread, $mysql, $lookAlikes, $lookAlikeExpired): void {
            if (!str_contains($sql, 'FOR UPDATE')) {
                return;
            }
            $other = self::connection();
            $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
            if ($mysql) {
                $locks = ($other->query("
                    SELECT l.LOCK_DATA FROM performance_schema.data_locks l
                    JOIN performance_schema.threads t ON t.THREAD_ID = l.THREAD_ID
                    WHERE t.PROCESSLIST_ID = $workerThread AND l.LOCK_TYPE = 'RECORD' AND l.LOCK_DATA <> 'supremum pseudo-record'") ?: throw new \LogicException('data_locks'))->fetchAll(PDO::FETCH_COLUMN);
                $observed['locked_ids'] = array_map(static fn (mixed $id): int => is_scalar($id) ? (int) $id : 0, $locks);
                sort($observed['locked_ids']);
            }
            foreach ([...$lookAlikes, 'ADMIN (expired lease)' => $lookAlikeExpired] as $type => $id) {
                $other->beginTransaction();
                try {
                    $other->exec("UPDATE cd_email_queue SET last_error = 'its own worker' WHERE id = $id");
                } catch (PDOException $failure) {
                    $observed['waited'][] = "$type: " . self::driverCode($failure);
                } finally {
                    $other->rollBack();
                }
            }
        };

        $this->worker($connection)->processBatchByEntityType('admin');

        self::assertSame([], $observed['waited'], 'No look-alike type is locked.');
        if ($mysql) {
            self::assertSame($exact, $observed['locked_ids'], 'Only the exact type\'s primary keys are locked (MySQL data_locks).');
        }
        self::assertSame(2, $this->transport->calls, 'Only the exact type is sent.');
        foreach ($exact as $id) {
            self::assertSame(['sent', 1], $this->state($id));
        }
        foreach ($lookAlikes as $type => $id) {
            self::assertSame(['pending', 0], $this->state($id), "'$type' is another type.");
        }
        self::assertSame(['processing', 1], $this->state($lookAlikeExpired), 'Another type\'s expired lease is not reclaimed.');

        // Each look-alike type is exact for its own worker.
        foreach ($lookAlikes as $type => $id) {
            $before = $this->transport->calls;
            $this->worker($this->pdo)->processBatchByEntityType($type);
            self::assertSame($before + ($type === 'ADMIN' ? 2 : 1), $this->transport->calls, "Exactly the rows of type '$type'.");
            self::assertSame(['sent', 1], $this->state($id));
        }
    }

    public function testTheLimitAppliesWithinTheEntityType(): void
    {
        $this->enqueue('user-1', 'user', 1);
        $low = $this->enqueue('admin-low', 'admin', 9);
        $first = $this->enqueue('admin-first', 'admin', 2);
        $second = $this->enqueue('admin-second', 'admin', 2);

        $this->worker($this->pdo)->processBatchByEntityType('admin', 2);

        self::assertSame(2, $this->transport->calls);
        self::assertSame(['sent', 1], $this->state($first));
        self::assertSame(['sent', 1], $this->state($second));
        self::assertSame(['pending', 0], $this->state($low), 'The limit counts rows of its type, in priority/ID order.');
    }

    public function testAScopedBatchWithNoRowsOfItsTypeDoesNothing(): void
    {
        $user = $this->enqueue('user-1', 'user', 1);
        $plain = self::connection();

        $this->worker($plain)->processBatchByEntityType('admin');

        self::assertSame(0, $this->transport->calls);
        self::assertSame(['pending', 0], $this->state($user));
        self::assertFalse($plain->inTransaction(), 'The empty claim committed.');
        self::assertSame([], $this->logger->contexts('warning', 'Email job permanently failed'));
    }

    public function testAScopedBatchReclaimsAndSettlesOnlyExpiredLeasesOfItsType(): void
    {
        $hourAgo = gmdate('Y-m-d H:i:s', time() - 3600);
        $adminRetry = $this->stale($this->enqueue('admin-retry', 'admin', 1), 1, $hourAgo);
        $adminAtCap = $this->stale($this->enqueue('admin-cap', 'admin', 1), 4, $hourAgo);
        $userRetry = $this->stale($this->enqueue('user-retry', 'user', 1), 1, $hourAgo);
        $userAtCap = $this->stale($this->enqueue('user-cap', 'user', 1), 4, $hourAgo);

        $this->worker($this->pdo)->processBatchByEntityType('admin');

        self::assertSame(['sent', 2], $this->state($adminRetry), 'Its expired lease is claimed and sent again.');
        self::assertSame(['failed', 4], $this->state($adminAtCap), 'At the cap its row is settled without a send.');
        self::assertSame('processing_lease_expired', $this->column($adminAtCap, 'last_error'));
        self::assertSame(1, $this->transport->calls);
        self::assertSame(['processing', 1], $this->state($userRetry), 'Another type\'s expired lease is left to its own worker.');
        self::assertSame(['processing', 4], $this->state($userAtCap), 'Another type\'s row at the cap is not settled.');

        $this->worker($this->pdo)->processBatch();
        self::assertSame(['sent', 2], $this->state($userRetry));
        self::assertSame(['failed', 4], $this->state($userAtCap));
    }

    public function testARowWithoutAnEntityTypeIsOnlyTakenByTheGenericBatch(): void
    {
        $untyped = $this->enqueue('untyped', 'user', 1);
        $this->pdo->exec("UPDATE cd_email_queue SET entity_type = NULL WHERE id = $untyped");

        $this->worker($this->pdo)->processBatchByEntityType('user');
        self::assertSame(['pending', 0], $this->state($untyped), 'NULL never equals a type.');
        self::assertSame(0, $this->transport->calls);

        $this->worker($this->pdo)->processBatch();
        self::assertSame(['sent', 1], $this->state($untyped));
    }

    /** @return iterable<string, array{string}> */
    public static function changedTypes(): iterable
    {
        yield 'another type' => ['user'];
        yield 'a type differing in case' => ['Admin'];
        yield 'a type with a trailing space' => ['admin '];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('changedTypes')]
    public function testACandidateWhoseEntityTypeChangedBeforeTheLockIsNotClaimed(string $changedTo): void
    {
        $job = $this->enqueue('admin-1', 'admin', 1);
        $connection = new EmailQueueFaultPdo();
        $changed = false;
        $connection->afterExecuteHook = function (string $sql) use ($job, $changedTo, &$changed): void {
            if (str_starts_with(ltrim($sql), 'SELECT id FROM') && str_contains($sql, "status = 'pending'") && !$changed) {
                // Between the unlocked candidate read and the lock, the row moves to another type.
                $changed = true;
                $statement = $this->pdo->prepare('UPDATE cd_email_queue SET entity_type = ? WHERE id = ?');
                $statement->execute([$changedTo, $job]);
            }
        };

        $this->worker($connection)->processBatchByEntityType('admin');

        self::assertTrue($changed);
        self::assertSame(0, $this->transport->calls, 'The exact entity type is re-checked under the lock.');
        self::assertSame(['pending', 0], $this->state($job));
    }

    /** @return iterable<string, array{string, bool, string}> */
    public static function shapesAndIsolationLevels(): iterable
    {
        foreach (self::tableShapes() as $name => [$shape, $with006]) {
            foreach (['REPEATABLE READ', 'READ COMMITTED'] as $isolation) {
                yield "$name, $isolation" => [$shape, $with006, $isolation];
            }
        }
    }

    /**
     * Review D1: a candidate of the batch's type whose entity_type changes between the unlocked read and the lock
     * fails the re-check, so it is neither claimed nor sent. The locking read still reads it by primary key: under
     * REPEATABLE READ InnoDB keeps that lock until the claim transaction ends, under READ COMMITTED it releases it
     * right after the re-check. A row that was never a candidate of the batch's type is never locked.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('shapesAndIsolationLevels')]
    public function testACandidateWhoseEntityTypeChangedBeforeTheLockIsLockedOnlyUntilTheClaimEnds(string $shape, bool $with006, string $isolation): void
    {
        $this->shapeTable($shape, $with006);
        $changes = $this->enqueue('admin-changes', 'admin', 1);
        $kept = $this->enqueue('admin-kept', 'admin', 2);
        // Never candidates of the batch: a row of another type, and a row of its type that is not due yet.
        $user = $this->enqueue('user-1', 'user', 1);
        $later = $this->enqueue('admin-later', 'admin', 1);
        $this->pdo->exec("UPDATE cd_email_queue SET scheduled_at = UTC_TIMESTAMP() + INTERVAL 1 DAY WHERE id = $later");
        $connection = new EmailQueueFaultPdo();
        $connection->exec("SET SESSION TRANSACTION ISOLATION LEVEL $isolation");
        $workerThread = (int) self::scalar($connection, 'SELECT CONNECTION_ID()');
        $mysql = self::isMysql($connection);
        $observed = ['changed' => false, 'isolation' => null, 'rows_locked' => null, 'locked_ids' => null, 'waited' => []];
        $connection->afterExecuteHook = function (string $sql) use (&$observed, $workerThread, $mysql, $changes, $user, $later): void {
            if (str_starts_with(ltrim($sql), 'SELECT id FROM') && str_contains($sql, "status = 'pending'") && !$observed['changed']) {
                // Between the unlocked candidate read and the lock, another connection moves the candidate to
                // another type and commits.
                $observed['changed'] = true;
                $this->pdo->exec("UPDATE cd_email_queue SET entity_type = 'user' WHERE id = $changes");
                return;
            }
            if (!str_contains($sql, 'FOR UPDATE')) {
                return;
            }
            // The claim transaction is still open, right after its locking read.
            $other = self::connection();
            $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
            usleep(150_000); // information_schema.INNODB_TRX is refreshed at most every 0.1 s
            $observed['isolation'] = self::scalar($other, "SELECT trx_isolation_level FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = $workerThread");
            $observed['rows_locked'] = (int) self::scalar($other, "SELECT trx_rows_locked FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = $workerThread");
            if ($mysql) {
                $locks = ($other->query("
                    SELECT l.LOCK_DATA FROM performance_schema.data_locks l
                    JOIN performance_schema.threads t ON t.THREAD_ID = l.THREAD_ID
                    WHERE t.PROCESSLIST_ID = $workerThread AND l.LOCK_TYPE = 'RECORD' AND l.LOCK_DATA <> 'supremum pseudo-record'") ?: throw new \LogicException('data_locks'))->fetchAll(PDO::FETCH_COLUMN);
                $observed['locked_ids'] = array_map(static fn (mixed $id): int => is_scalar($id) ? (int) $id : 0, $locks);
                sort($observed['locked_ids']);
            }
            foreach (['the candidate that changed type' => $changes, 'a row of another type' => $user, 'a row of its type that is not due' => $later] as $what => $id) {
                $other->beginTransaction();
                try {
                    $other->exec("UPDATE cd_email_queue SET last_error = 'probe' WHERE id = $id");
                } catch (PDOException $failure) {
                    $observed['waited'][] = "$what: " . self::driverCode($failure);
                } finally {
                    $other->rollBack();
                }
            }
        };

        $this->worker($connection)->processBatchByEntityType('admin');

        $repeatableRead = $isolation === 'REPEATABLE READ';
        self::assertTrue($observed['changed']);
        self::assertSame($isolation, $observed['isolation'], 'The claim ran at the isolation level under test.');
        self::assertSame(1, $this->transport->calls, 'Only the candidate that kept its type is sent.');
        self::assertSame(['sent', 1], $this->state($kept));
        self::assertSame(['pending', 0], $this->state($changes), 'The candidate that changed type is neither claimed nor sent.');
        self::assertSame(['pending', 0], $this->state($later));
        self::assertSame(
            $repeatableRead ? ['the candidate that changed type: 1205'] : [],
            $observed['waited'],
            $repeatableRead
                ? 'Under REPEATABLE READ the changed candidate stays locked while the claim transaction is open; rows that were never candidates are not locked.'
                : 'Under READ COMMITTED the lock on the changed candidate is released right after the re-check; rows that were never candidates are not locked.',
        );
        if ($mysql) {
            self::assertSame($repeatableRead ? [$changes, $kept] : [$kept], $observed['locked_ids'], 'The claim\'s record locks (MySQL data_locks).');
        } else {
            self::assertSame($repeatableRead ? 2 : 1, $observed['rows_locked'], 'The claim\'s locked rows (MariaDB INNODB_TRX).');
        }

        // The lock ends with the claim transaction, and the row is its new type's to process.
        $after = self::connection();
        $after->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $after->exec("UPDATE cd_email_queue SET last_error = 'write after the claim' WHERE id = $changes");
        self::assertSame('write after the claim', $this->column($changes, 'last_error'));
        $this->worker($this->pdo)->processBatchByEntityType('user');
        self::assertSame(['sent', 1], $this->state($changes));
        self::assertSame(['sent', 1], $this->state($user));
    }

    /** @return iterable<string, array{string}> */
    public static function shapesFor006(): iterable
    {
        yield 'package schema + 006' => ['package'];
        yield 'application-like table + 006' => ['application'];
    }

    /**
     * The optional schema 006: with it, each scoped candidate read walks only rows of its type in (priority, id)
     * order instead of every row of its status ahead of them, also with the exact byte comparison and look-alike
     * types in the backlog; the generic batch keeps using 005.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('shapesFor006')]
    public function testWithSchema006TheScopedCandidateReadsWalkOnlyTheirType(string $shape): void
    {
        $this->shapeTable($shape, true);
        // A backlog of the other type ahead in both statuses: due rows, and in-flight rows with a current lease.
        $this->seedDueRows('user', 600, 1);
        $this->pdo->exec("UPDATE cd_email_queue SET status = 'processing', attempts = 1, updated_at = UTC_TIMESTAMP() WHERE entity_type = 'user' ORDER BY id LIMIT 300");
        // Look-alike types share the collation-equal index entries but are other types.
        $lookAlikes = [$this->enqueue('look-alike-1', 'Admin', 9), $this->enqueue('look-alike-2', 'admin ', 9)];
        $admins = [$this->enqueue('admin-1', 'admin', 9), $this->enqueue('admin-2', 'admin', 9)];
        $adminExpired = $this->stale($this->enqueue('admin-expired', 'admin', 9), 1, gmdate('Y-m-d H:i:s', time() - 3600));
        ($this->pdo->query('ANALYZE TABLE cd_email_queue') ?: throw new \LogicException('ANALYZE'))->fetchAll();

        $scoped = $this->candidateReadPlans(static fn (EmailQueueWorker $worker) => $worker->processBatchByEntityType('admin'));

        self::assertCount(2, $scoped, 'Both candidate reads, expired leases and due rows.');
        foreach ($scoped as $sql => [$key, $type, $extra, $rows]) {
            self::assertSame('idx_email_queue_claim_entity_type', $key, $sql);
            self::assertContains($type, ['ref', 'range'], 'A walk of one status and one type: ' . $sql);
            self::assertLessThan(100, $rows, 'It reads its own type, not the 600-row backlog of another: ' . $sql);
            self::assertStringNotContainsString('filesort', $extra, 'In (priority, id) order, stopping at the limit.');
        }
        self::assertSame(3, $this->transport->calls);
        foreach ([...$admins, $adminExpired] as $id) {
            self::assertSame('sent', $this->state($id)[0]);
        }
        foreach ($lookAlikes as $id) {
            self::assertSame(['pending', 0], $this->state($id));
        }

        $generic = $this->candidateReadPlans(static fn (EmailQueueWorker $worker) => $worker->processBatch(1));
        foreach ($generic as $sql => [$key]) {
            self::assertSame('idx_email_queue_claim', $key, 'The generic batch keeps the 005 index: ' . $sql);
        }
    }

    public function testTheGenericBatchStillTakesEveryEntityType(): void
    {
        $admin = $this->enqueue('admin-1', 'admin', 1);
        $user = $this->enqueue('user-1', 'user', 1);

        $this->worker($this->pdo)->processBatch();

        self::assertSame(['sent', 1], $this->state($admin));
        self::assertSame(['sent', 1], $this->state($user));
        self::assertSame(2, $this->transport->calls);
    }

    private function worker(PDO $connection): EmailQueueWorker
    {
        $renderer = new TwigEmailRenderer(dirname(__DIR__) . '/Fixtures/templates', ['global_app_name' => 'Fixture'], false);
        return new EmailQueueWorker($connection, Factory::crypto(), $renderer, $this->transport, new Factory(), $this->logger);
    }

    private function enqueue(string $key, string $entityType, int $priority): int
    {
        $request = new EmailEnqueueRequestDTO('fixture-consumer', $key, $entityType, '1', 'fixture@example.invalid', new EmailQueuePayloadDTO(['name' => 'Fixture'], 'welcome', 'en'), 1, $priority);
        return (int) Factory::queue($this->pdo)->enqueueOnce($request)->jobId;
    }

    /**
     * The package schema as installed (001, 002, 005), or reshaped like an application-owned table: entity_type a
     * utf8mb4_unicode_ci VARCHAR(32) NOT NULL with its own (entity_type, entity_id) index, and a TIMESTAMP lease.
     * Optionally with schema 006.
     */
    private function shapeTable(string $shape, bool $with006): void
    {
        if ($shape === 'application') {
            $this->pdo->exec('ALTER TABLE cd_email_queue
                MODIFY entity_type VARCHAR(32) COLLATE utf8mb4_unicode_ci NOT NULL,
                MODIFY updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                ADD INDEX idx_application_entity (entity_type, entity_id)');
        }
        if ($with006) {
            $sql = file_get_contents(dirname(__DIR__, 2) . '/schema/mysql/006_email_queue_entity_type_claim_index.sql');
            self::assertIsString($sql);
            $this->pdo->exec($sql);
        }
    }

    /**
     * Runs one batch and returns the EXPLAIN of each unlocked candidate read it made.
     *
     * @param \Closure(EmailQueueWorker): void $batch
     * @return array<string, array{string, string, string, int}> key, access type, Extra and estimated rows, by statement
     */
    private function candidateReadPlans(\Closure $batch): array
    {
        $connection = new EmailQueueFaultPdo();
        $reads = [];
        $connection->afterExecuteHook = static function (string $sql) use (&$reads): void {
            if (str_starts_with(ltrim($sql), 'SELECT id FROM')) {
                $reads[] = $sql;
            }
        };
        $batch($this->worker($connection));
        $plans = [];
        foreach ($reads as $sql) {
            $plan = $this->pdo->prepare('EXPLAIN ' . $sql);
            $plan->bindValue(':limit', 50, PDO::PARAM_INT);
            if (str_contains($sql, ':lease')) {
                $plan->bindValue(':lease', EmailQueueWorker::DEFAULT_PROCESSING_LEASE_SECONDS, PDO::PARAM_INT);
            }
            if (str_contains($sql, ':entity_type')) {
                $plan->bindValue(':entity_type', 'admin');
                $plan->bindValue(':entity_type_exact', 'admin');
            }
            $plan->execute();
            $row = $plan->fetch(PDO::FETCH_ASSOC);
            self::assertIsArray($row);
            $plans[$sql] = [is_string($row['key'] ?? null) ? $row['key'] : '', is_string($row['type'] ?? null) ? $row['type'] : '', is_string($row['Extra'] ?? null) ? $row['Extra'] : '', is_numeric($row['rows'] ?? null) ? (int) $row['rows'] : PHP_INT_MAX];
        }
        return $plans;
    }

    /** $count due copies of one enqueued row of $entityType at $priority (legacy copies, no receipt). */
    private function seedDueRows(string $entityType, int $count, int $priority): void
    {
        $seed = $this->enqueue($entityType . '-seed', $entityType, $priority);
        $set = ['enqueue_receipt_id'];
        $all = ($this->pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cd_email_queue' AND COLUMN_NAME <> 'id' ORDER BY ORDINAL_POSITION") ?: throw new \LogicException('columns'))->fetchAll(PDO::FETCH_COLUMN);
        $copied = array_values(array_diff(array_map(static fn (mixed $column): string => is_string($column) ? $column : '', $all), $set));
        $digits = '(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        $this->pdo->exec('INSERT INTO cd_email_queue (' . implode(', ', [...$copied, ...$set]) . ')
            SELECT ' . implode(', ', array_map(static fn (string $column): string => 's.' . $column, $copied)) . ", NULL
            FROM cd_email_queue s, $digits a, $digits b, $digits c WHERE s.id = $seed LIMIT " . ($count - 1));
    }

    private function stale(int $id, int $attempts, string $updatedAt): int
    {
        $statement = $this->pdo->prepare("UPDATE cd_email_queue SET status = 'processing', attempts = ?, updated_at = ? WHERE id = ?");
        $statement->execute([$attempts, $updatedAt, $id]);
        return $id;
    }

    /** @return array{string, int} */
    private function state(int $id): array
    {
        $row = ($this->pdo->query("SELECT status, attempts FROM cd_email_queue WHERE id = $id") ?: throw new \LogicException('row'))->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertIsString($row['status']);
        self::assertTrue(is_int($row['attempts']) || is_string($row['attempts']));
        return [$row['status'], (int) $row['attempts']];
    }

    private function column(int $id, string $column): string
    {
        return self::scalar($this->pdo, "SELECT $column FROM cd_email_queue WHERE id = $id");
    }

    private static function isMysql(PDO $pdo): bool
    {
        $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        return is_string($version) && !str_contains(strtolower($version), 'mariadb');
    }
}
