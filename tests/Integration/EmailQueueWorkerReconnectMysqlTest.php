<?php

declare(strict_types=1);

namespace Tests\Integration;

use Closure;
use DateTimeImmutable;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\PdoEmailQueueWriter;
use Maatify\EmailDelivery\Renderer\TwigEmailRenderer;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;
use Tests\Support\EmailQueueFaultPdo;
use Tests\Support\EmailQueueTestFactory as Factory;
use Tests\Support\IsolatedEmailMysqlTestCase;
use Tests\Support\WorkerRecordingLogger;
use Tests\Support\WorkerRecordingTransport;

/**
 * The worker's connection idles while it renders and sends. These tests close it the way a real server
 * does (a short session wait_timeout, or a server-side KILL) and check what the worker records afterwards.
 */
final class EmailQueueWorkerReconnectMysqlTest extends IsolatedEmailMysqlTestCase
{
    private const IDLE_TIMEOUT_SECONDS = 2;
    private const NOW = '2030-01-01T12:00:00Z';
    private const SENT_UNRECORDED = 'Email sent but status write failed';
    private const NOT_OWNED = 'the row is no longer processing under this attempt';

    private WorkerRecordingTransport $transport;
    private WorkerRecordingLogger $logger;
    /** @var list<PDO> connections the factory handed to the worker, in order */
    private array $connections = [];
    /** @var list<int> server-side ids of those connections */
    private array $connectionIds = [];
    /** Connections handed out from this index on are already closed by the server (a database still unreachable). */
    private int $closedFrom = PHP_INT_MAX;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new WorkerRecordingTransport();
        $this->logger = new WorkerRecordingLogger();
    }

    public function testASendThatOutlivesTheIdleTimeoutIsRecordedSentOnceAndTheNextBatchStillRuns(): void
    {
        $first = $this->enqueue('key-1');
        $worker = $this->worker($this->factory(self::IDLE_TIMEOUT_SECONDS));
        $this->transport->duringSend = static function (int $call): void {
            if ($call === 1) {
                sleep(self::IDLE_TIMEOUT_SECONDS + 2);
            }
        };

        $observed = ['first_batch_error' => $this->errorOf(static fn () => $worker->processBatch())];
        $observed += $this->prefixedRow($first, 'first');
        // The reconnected session idles out too before the next batch: the claim must not start on it.
        $second = $this->enqueue('key-2');
        sleep(self::IDLE_TIMEOUT_SECONDS + 2);
        $observed['next_batch_error'] = $this->errorOf(static fn () => $worker->processBatch());
        $observed += $this->prefixedRow($second, 'next');
        $observed['transport_calls'] = $this->transport->calls;

        self::assertSame([
            'first_batch_error' => null,
            'first_status' => 'sent',
            'first_attempts' => '1',
            'first_last_error' => '',
            'first_sent' => true,
            'next_batch_error' => null,
            'next_status' => 'sent',
            'next_attempts' => '1',
            'next_last_error' => '',
            'next_sent' => true,
            'transport_calls' => 2,
        ], $observed);
        self::assertCount(3, $this->connections, 'One reconnect after the send and one before the next claim.');
        self::assertSame([], $this->logger->contexts('error', self::SENT_UNRECORDED));
    }

    public function testATransportFailureIsRecordedForRetryOnAFreshConnectionAfterTheServerClosedTheOldOne(): void
    {
        $job = $this->enqueue('key-1');
        $worker = $this->worker($this->factory());
        $this->transport->fail = true;
        $this->transport->duringSend = fn (int $call) => $this->closeWorkerConnection();

        $worker->processBatch();

        $row = $this->row($job);
        self::assertSame('pending', $row['status']);
        self::assertSame('1', $row['attempts']);
        self::assertStringStartsWith('smtp_transport_error:', $row['last_error']);
        self::assertNotNull($this->scalarOrNull("SELECT retry_after FROM cd_email_queue WHERE id = $job"));
        self::assertCount(2, $this->connections);
        self::assertSame(1, $this->transport->calls);
    }

    public function testAFinalTransportFailureIsRecordedFailedOnAFreshConnectionAfterTheServerClosedTheOldOne(): void
    {
        $job = $this->enqueue('key-1');
        $this->pdo->exec("UPDATE cd_email_queue SET attempts = 3 WHERE id = $job");
        $worker = $this->worker($this->factory());
        $this->transport->fail = true;
        $this->transport->duringSend = fn (int $call) => $this->closeWorkerConnection();

        $worker->processBatch();

        $row = $this->row($job);
        self::assertSame('failed', $row['status']);
        self::assertSame('4', $row['attempts']);
        self::assertStringStartsWith('smtp_transport_error:', $row['last_error']);
        self::assertCount(2, $this->connections);
    }

    public function testASentStatusThatCannotBeRecordedEvenAfterOneReconnectStopsTheBatchAndIsLeftToTheLease(): void
    {
        $first = $this->enqueue('key-1');
        $second = $this->enqueue('key-2');
        $worker = $this->worker($this->factory());
        $this->closedFrom = 1;
        $this->transport->duringSend = fn (int $call) => $this->closeWorkerConnection();

        $error = $this->errorOf(static fn () => $worker->processBatch());

        self::assertNotNull($error);
        self::assertStringContainsString('2006', $error);
        self::assertSame(1, $this->transport->calls, 'No further mail may be sent while outcomes cannot be recorded.');
        self::assertCount(2, $this->connections, 'Exactly one reconnect attempt.');
        $sentRow = $this->row($first);
        self::assertSame(['processing', '1', ''], [$sentRow['status'], $sentRow['attempts'], $sentRow['last_error']]);
        self::assertNull($this->scalarOrNull("SELECT retry_after FROM cd_email_queue WHERE id = $first"), 'A sent email is never scheduled for a retry.');
        self::assertSame('processing', $this->row($second)['status']);
        $unrecorded = $this->logger->contexts('error', self::SENT_UNRECORDED);
        self::assertCount(1, $unrecorded);
        self::assertSame([(int) $first, 1, true], [$unrecorded[0]['job_id'], $unrecorded[0]['attempt'], $unrecorded[0]['connection_lost']]);

        // Documented at-least-once trade-off: once the lease expires, both rows are claimed again.
        $this->pdo->exec('UPDATE cd_email_queue SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL '
            . (EmailQueueWorker::DEFAULT_PROCESSING_LEASE_SECONDS + 1) . ' SECOND)');
        $this->closedFrom = PHP_INT_MAX;
        $this->transport->duringSend = null;
        $this->worker($this->factory())->processBatch();
        self::assertSame(3, $this->transport->calls);
        self::assertSame(['sent', '2'], [$this->row($first)['status'], $this->row($first)['attempts']]);
        self::assertSame(['sent', '2'], [$this->row($second)['status'], $this->row($second)['attempts']]);
    }

    /** @return iterable<string, array{int|null, bool}> */
    public static function writeFailures(): iterable
    {
        yield 'server has gone away' => [2006, true];
        yield 'lost connection during the statement' => [2013, true];
        yield 'disconnected for inactivity' => [4031, true];
        yield 'lock wait timeout' => [1205, false];
        yield 'no driver code' => [null, false];
    }

    #[DataProvider('writeFailures')]
    public function testOnlyALostConnectionMakesTheSentWriteReconnectAndRunOnceMore(?int $driverCode, bool $retried): void
    {
        $job = $this->enqueue('key-1');
        $faults = 0;
        $worker = $this->worker(function () use ($driverCode, &$faults): PDO {
            $pdo = $this->sentWriteFaults($driverCode, $faults);
            $this->connections[] = $pdo;
            return $pdo;
        });

        $error = $this->errorOf(static fn () => $worker->processBatch());

        self::assertNull($error);
        self::assertSame(1, $this->transport->calls);
        self::assertSame(1, $faults);
        self::assertCount($retried ? 2 : 1, $this->connections);
        self::assertSame($retried ? 'sent' : 'processing', $this->row($job)['status']);
    }

    /** @return iterable<string, array{int}> */
    public static function sentWriteAttempts(): iterable
    {
        yield 'first attempt (main reschedules it as pending)' => [0];
        yield 'last attempt (main marks it failed)' => [3];
    }

    #[DataProvider('sentWriteAttempts')]
    public function testASentWriteThatFailsOnALiveConnectionLeavesTheRowToTheLeaseAndTheBatchContinues(int $priorAttempts): void
    {
        $first = $this->enqueue('key-1');
        $second = $this->enqueue('key-2');
        $this->pdo->exec("UPDATE cd_email_queue SET attempts = $priorAttempts WHERE id = $first");
        $faults = 0;
        $connection = $this->sentWriteFaults(1205, $faults);

        $error = $this->errorOf(fn () => $this->worker($connection)->processBatch());

        self::assertSame([
            'batch_error' => null,
            'status' => 'processing',
            'attempts' => (string) ($priorAttempts + 1),
            'last_error' => '',
            'retry_after' => null,
            'next_row' => 'sent',
            'sends' => 2,
        ], [
            'batch_error' => $error,
            'status' => $this->row($first)['status'],
            'attempts' => $this->row($first)['attempts'],
            'last_error' => $this->row($first)['last_error'],
            'retry_after' => $this->scalarOrNull("SELECT retry_after FROM cd_email_queue WHERE id = $first"),
            'next_row' => $this->row($second)['status'],
            'sends' => $this->transport->calls,
        ], 'A sent email is never written pending/failed and the batch goes on.');
        $unrecorded = $this->logger->contexts('error', self::SENT_UNRECORDED);
        self::assertCount(1, $unrecorded);
        self::assertSame([(int) $first, $priorAttempts + 1, false], [$unrecorded[0]['job_id'], $unrecorded[0]['attempt'], $unrecorded[0]['connection_lost']]);
        self::assertSame([], $this->logger->contexts('error', 'Email job failed'), 'A failed status write is not a send failure.');
    }

    public function testWithoutAFactoryALostConnectionOnTheSentWriteStopsTheBatch(): void
    {
        $first = $this->enqueue('key-1');
        $second = $this->enqueue('key-2');
        $faults = 0;
        $connection = $this->sentWriteFaults(2006, $faults);

        $error = $this->errorOf(fn () => $this->worker($connection)->processBatch());

        self::assertSame(PDOException::class . ': Fixture sent-status write failure', $error);
        self::assertSame(1, $this->transport->calls, 'No further mail may be sent on a lost connection.');
        self::assertSame(['processing', 'processing'], [$this->row($first)['status'], $this->row($second)['status']]);
        self::assertSame(true, $this->logger->contexts('error', self::SENT_UNRECORDED)[0]['connection_lost'] ?? null);
    }

    public function testAProcessingRowIsReclaimedOnlyAfterItsLeaseAndAnExhaustedOneIsSettledFailedWithoutASend(): void
    {
        $epoch = (new DateTimeImmutable(self::NOW))->getTimestamp();
        $utc = static fn (int $secondsAgo): string => gmdate('Y-m-d H:i:s', $epoch - $secondsAgo);
        $local = static fn (int $secondsAgo): string => gmdate('Y-m-d H:i:s', $epoch + 7200 - $secondsAgo);
        $rows = [
            'expired' => $this->stale($this->enqueue('key-1'), 1, $utc(61)),
            'at_lease' => $this->stale($this->enqueue('key-2'), 1, $utc(60)),
            'exhausted_expired' => $this->stale($this->enqueue('key-3'), 4, $utc(61)),
            'exhausted_at_lease' => $this->stale($this->enqueue('key-4'), 4, $utc(60)),
            'legacy_expired' => $this->stale($this->legacy(), 2, $local(61)),
            'legacy_at_lease' => $this->stale($this->legacy(), 2, $local(60)),
        ];
        $this->freeze($this->pdo, $epoch, '+02:00');

        $this->worker($this->pdo, 60)->processBatch();

        $observed = [];
        foreach ($rows as $name => $id) {
            $row = $this->row($id);
            $observed[$name] = [$row['status'], $row['attempts'], $row['last_error']];
        }
        self::assertSame([
            'expired' => ['sent', '2', ''],
            'at_lease' => ['processing', '1', ''],
            'exhausted_expired' => ['failed', '4', 'processing_lease_expired'],
            'exhausted_at_lease' => ['processing', '4', ''],
            'legacy_expired' => ['sent', '3', ''],
            'legacy_at_lease' => ['processing', '2', ''],
        ], $observed);
        self::assertSame(2, $this->transport->calls);
        self::assertCount(1, $this->logger->contexts('warning', 'Email job permanently failed'));
    }

    public function testTheDefaultLeaseIsFiveMinutes(): void
    {
        $epoch = (new DateTimeImmutable(self::NOW))->getTimestamp();
        $expired = $this->stale($this->enqueue('key-1'), 1, gmdate('Y-m-d H:i:s', $epoch - 301));
        $atLease = $this->stale($this->enqueue('key-2'), 1, gmdate('Y-m-d H:i:s', $epoch - 300));
        $this->freeze($this->pdo, $epoch, '+00:00');

        $this->worker($this->pdo)->processBatch();

        self::assertSame(300, EmailQueueWorker::DEFAULT_PROCESSING_LEASE_SECONDS);
        self::assertSame('sent', $this->row($expired)['status']);
        self::assertSame('processing', $this->row($atLease)['status']);
    }

    public function testEachRowRenewsItsLeaseRightBeforeItsSendSoTheLeaseCoversOneSendNotTheBatch(): void
    {
        $epoch = (new DateTimeImmutable(self::NOW))->getTimestamp();
        $first = $this->enqueue('key-1');
        $second = $this->enqueue('key-2');
        $this->freeze($this->pdo, $epoch, '+00:00');
        $claimedAt = null;
        $this->transport->duringSend = function (int $call) use ($second, $epoch, &$claimedAt): void {
            if ($call === 1) {
                $claimedAt = $this->scalarOrNull("SELECT updated_at FROM cd_email_queue WHERE id = $second");
                // A slow first send: the worker's clock moves on before the second row is sent.
                $this->pdo->exec('SET timestamp=' . ($epoch + 500));
            }
        };

        $this->worker($this->pdo, 60)->processBatch();

        self::assertSame('2030-01-01 12:00:00', $claimedAt, 'The claim stamps the lease of every claimed row.');
        self::assertSame('2030-01-01 12:08:20', $this->scalarOrNull("SELECT updated_at FROM cd_email_queue WHERE id = $second"));
        self::assertSame(['sent', 'sent'], [$this->row($first)['status'], $this->row($second)['status']]);
    }

    public function testARowReclaimedByAnotherWorkerAfterItsLeaseIsNotSentByTheStaleWorker(): void
    {
        $first = $this->enqueue('key-1');
        $second = $this->enqueue('key-2');
        $reclaimed = null;
        $this->transport->duringSend = function (int $call) use ($second, &$reclaimed): void {
            if ($call === 1) {
                // Another worker reclaims the second row of this batch while the first send runs.
                $this->pdo->exec("UPDATE cd_email_queue SET attempts = attempts + 1,
                    updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 SECOND) WHERE id = $second");
                $reclaimed = $this->snapshot($second);
            }
        };

        $this->worker($this->pdo)->processBatch();

        self::assertSame(1, $this->transport->calls, 'No send for a row this attempt no longer owns.');
        self::assertSame('sent', $this->row($first)['status']);
        self::assertSame($reclaimed, $this->snapshot($second), 'No write for a row this attempt no longer owns.');
        self::assertSame('2', $this->row($second)['attempts']);
        self::assertCount(1, $this->logger->contexts('warning', 'Email job skipped: ' . self::NOT_OWNED));
        self::assertSame([], $this->logger->contexts('error', 'Email job failed'));
    }

    /** @return iterable<string, array{int}> */
    public static function priorAttempts(): iterable
    {
        yield 'retry write' => [0];
        yield 'final failure write' => [3];
    }

    #[DataProvider('priorAttempts')]
    public function testAStaleAttemptCannotOverwriteTheOutcomeOfTheAttemptThatReclaimedItsRow(int $priorAttempts): void
    {
        $job = $this->enqueue('key-1');
        $this->pdo->exec("UPDATE cd_email_queue SET attempts = $priorAttempts WHERE id = $job");
        $this->transport->fail = true;
        $this->transport->duringSend = function () use ($job): void {
            // Another worker reclaimed the row after the lease and already sent it.
            $this->pdo->exec("UPDATE cd_email_queue SET attempts = attempts + 1, status = 'sent', sent_at = UTC_TIMESTAMP() WHERE id = $job");
        };

        $this->worker($this->pdo)->processBatch();

        self::assertSame('sent', $this->row($job)['status']);
        self::assertSame('', $this->row($job)['last_error']);
        self::assertCount(1, $this->logger->contexts('warning', 'Email job outcome not recorded: ' . self::NOT_OWNED));
    }

    public function testAStaleAttemptThatSentAfterItsRowWasReclaimedRecordsSentSoTheFailedNewerAttemptCausesNoThirdSend(): void
    {
        $job = $this->enqueue('key-1');
        $other = $this->worker(self::connection());
        $this->transport->duringSend = function (int $call) use ($job, $other): void {
            if ($call === 1) {
                // This send outlasts the lease: another worker reclaims the row, and its own send fails.
                $this->expireLease($job);
                $this->transport->fail = true;
                $other->processBatch();
                $this->transport->fail = false;
            }
        };

        $this->worker($this->pdo)->processBatch();
        // Make any retry due at once: a row left pending would be sent a third time now.
        $this->pdo->exec('UPDATE cd_email_queue SET retry_after = NULL');
        $this->worker(self::connection())->processBatch();

        self::assertSame(['sent', true], [$this->row($job)['status'], $this->row($job)['sent']]);
        self::assertSame(2, $this->transport->calls, 'Two sends, not three.');
    }

    public function testAStaleAttemptThatSentAfterItsRowWasSettledAtTheLimitRecordsSent(): void
    {
        $job = $this->enqueue('key-1');
        $this->pdo->exec("UPDATE cd_email_queue SET attempts = 3 WHERE id = $job");
        $other = $this->worker(self::connection());
        $this->transport->duringSend = function (int $call) use ($job, $other): void {
            if ($call === 1) {
                // This last attempt outlasts the lease: another worker settles the row failed without a send.
                $this->expireLease($job);
                $other->processBatch();
            }
        };

        $this->worker($this->pdo)->processBatch();

        self::assertCount(1, $this->logger->contexts('warning', 'Email job permanently failed'), 'The other worker settled it.');
        self::assertSame(['sent', '4', true], [$this->row($job)['status'], $this->row($job)['attempts'], $this->row($job)['sent']]);
        self::assertSame(1, $this->transport->calls);
    }

    public function testARenewalInTheClaimSecondChangesNoRowYetTheOwningAttemptSendsExactlyOnce(): void
    {
        $job = $this->enqueue('key-1');
        $this->freeze($this->pdo, (new DateTimeImmutable(self::NOW))->getTimestamp(), '+00:00');
        $changed = null;
        $this->transport->duringSend = function () use ($job, &$changed): void {
            // The renewal statement once more, on the worker's own connection within the same frozen second.
            $renewal = $this->pdo->prepare("UPDATE `cd_email_queue` SET updated_at = UTC_TIMESTAMP() WHERE id = ? AND status = 'processing' AND attempts = 1");
            $renewal->execute([$job]);
            $changed = $renewal->rowCount();
        };

        $this->worker($this->pdo)->processBatch();

        self::assertSame(0, $changed, 'MySQL reports changed rows only: rowCount() cannot prove ownership.');
        self::assertSame(1, $this->transport->calls);
        self::assertSame('sent', $this->row($job)['status']);
    }

    // ── fixture helpers ───────────────────────────────────────────

    /** A real connection whose first `sent` write fails with the given driver code. */
    private function sentWriteFaults(?int $driverCode, int &$faults): EmailQueueFaultPdo
    {
        $pdo = new EmailQueueFaultPdo();
        $pdo->beforeExecuteHook = static function (string $sql) use ($driverCode, &$faults): void {
            if (str_contains($sql, "SET status = 'sent'") && $faults === 0) {
                ++$faults;
                $failure = new PDOException('Fixture sent-status write failure');
                $failure->errorInfo = $driverCode === null ? null : ['HY000', $driverCode, 'Fixture'];
                throw $failure;
            }
        };
        return $pdo;
    }

    private function expireLease(int $id): void
    {
        $this->pdo->exec('UPDATE cd_email_queue SET updated_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL '
            . (EmailQueueWorker::DEFAULT_PROCESSING_LEASE_SECONDS + 1) . " SECOND) WHERE id = $id");
    }

    /** @return array<string, mixed> every column a worker write could touch */
    private function snapshot(int $id): array
    {
        $statement = $this->pdo->query("SELECT status, attempts, updated_at, last_error, retry_after, sent_at FROM cd_email_queue WHERE id = $id");
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        /** @var array<string, mixed> $row */
        return $row;
    }

    /** @return Closure(): PDO */
    private function factory(?int $idleTimeoutSeconds = null): Closure
    {
        return function () use ($idleTimeoutSeconds): PDO {
            $pdo = self::connection();
            if ($idleTimeoutSeconds !== null) {
                $pdo->exec('SET SESSION wait_timeout = ' . $idleTimeoutSeconds);
            }
            $id = (int) self::scalar($pdo, 'SELECT CONNECTION_ID()');
            $this->connections[] = $pdo;
            $this->connectionIds[] = $id;
            if (count($this->connections) > $this->closedFrom) {
                $this->closeConnection($id);
            }
            return $pdo;
        };
    }

    private function closeWorkerConnection(): void
    {
        $this->closeConnection($this->connectionIds[array_key_last($this->connectionIds)]);
    }

    /** A real server-side close of a connection, as on a restart or a network cut. */
    private function closeConnection(int $id): void
    {
        $this->pdo->exec('KILL CONNECTION ' . $id);
        for ($i = 0; $i < 250; ++$i) {
            if (self::scalar($this->pdo, 'SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE ID = ' . $id) === '0') {
                return;
            }
            usleep(20_000);
        }
        self::fail('The fixture connection is still open after KILL.');
    }

    /** @param PDO|Closure(): PDO $connection */
    private function worker(PDO|Closure $connection, ?int $leaseSeconds = null): EmailQueueWorker
    {
        $renderer = new TwigEmailRenderer(dirname(__DIR__) . '/Fixtures/templates', ['global_app_name' => 'Fixture'], false);
        return $leaseSeconds === null
            ? new EmailQueueWorker($connection, Factory::crypto(), $renderer, $this->transport, new Factory(), $this->logger)
            : new EmailQueueWorker($connection, Factory::crypto(), $renderer, $this->transport, new Factory(), $this->logger, $leaseSeconds);
    }

    private function enqueue(string $key): int
    {
        return (int) Factory::queue($this->pdo)->enqueueOnce(Factory::request($key, context: ['name' => 'Fixture']))->jobId;
    }

    private function legacy(): int
    {
        (new PdoEmailQueueWriter($this->pdo, Factory::crypto(), new Factory()))->enqueue(
            'user',
            'legacy',
            'fixture@example.invalid',
            new EmailQueuePayloadDTO(['name' => 'Fixture'], 'welcome', 'en'),
            1,
            scheduledAt: new DateTimeImmutable('2000-01-01 00:00:00'),
        );
        return (int) $this->pdo->lastInsertId();
    }

    /** A row left `processing` by a worker that stopped before recording an outcome. */
    private function stale(int $id, int $attempts, string $updatedAt): int
    {
        $statement = $this->pdo->prepare("UPDATE cd_email_queue SET status = 'processing', attempts = ?, updated_at = ? WHERE id = ?");
        $statement->execute([$attempts, $updatedAt, $id]);
        return $id;
    }

    private function freeze(PDO $pdo, int $epoch, string $zone): void
    {
        $pdo->exec('SET timestamp=' . $epoch);
        $quoted = $pdo->quote($zone);
        self::assertIsString($quoted);
        $pdo->exec('SET SESSION time_zone=' . $quoted);
    }

    /** @return array{status: string, attempts: string, last_error: string, sent: bool} */
    private function row(int $id): array
    {
        $statement = $this->pdo->query("SELECT status, attempts, last_error, sent_at FROM cd_email_queue WHERE id = $id");
        self::assertNotFalse($statement);
        /** @var array{status: string, attempts: int|string, last_error: string|null, sent_at: string|null}|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        return [
            'status' => $row['status'],
            'attempts' => (string) $row['attempts'],
            'last_error' => (string) $row['last_error'],
            'sent' => $row['sent_at'] !== null,
        ];
    }

    /** @return array<string, string|bool> */
    private function prefixedRow(int $id, string $prefix): array
    {
        $prefixed = [];
        foreach ($this->row($id) as $column => $value) {
            $prefixed[$prefix . '_' . $column] = $value;
        }
        return $prefixed;
    }

    private function scalarOrNull(string $sql): ?string
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);
        $value = $statement->fetchColumn();
        return $value === null || $value === false ? null : (string) $value;
    }

    private function errorOf(Closure $operation): ?string
    {
        try {
            $operation();
            return null;
        } catch (Throwable $error) {
            return $error::class . ': ' . $error->getMessage();
        }
    }
}
