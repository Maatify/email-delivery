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
    /** @var list<int> server thread ids of every connection a test opened besides the fixture connection */
    private array $openedThreads = [];
    /** Connections handed out from this index on are already closed by the server (a database still unreachable). */
    private int $closedFrom = PHP_INT_MAX;

    protected function setUp(): void
    {
        parent::setUp();
        // A failing scenario may leave a worker connection holding locks; never wait on it forever.
        $this->pdo->exec('SET SESSION lock_wait_timeout = 30');
        $this->transport = new WorkerRecordingTransport();
        $this->logger = new WorkerRecordingLogger();
    }

    protected function tearDown(): void
    {
        // A failed scenario can keep a worker connection alive (for example through an exception trace) with
        // uncommitted work. Close it on the server so its locks never block the fixture's DROP TABLE.
        if (isset($this->pdo)) {
            foreach ($this->openedThreads as $thread) {
                try {
                    $this->pdo->exec('KILL CONNECTION ' . $thread);
                } catch (PDOException) {
                    // Already closed.
                }
            }
        }
        $this->connections = [];
        parent::tearDown();
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

    public function testAnApplicationThatTurnsWarningsIntoExceptionsStillRecoversFromAnIdleTimeout(): void
    {
        $job = $this->enqueue('key-1');
        $worker = $this->worker($this->factory(self::IDLE_TIMEOUT_SECONDS));
        $this->transport->duringSend = static function (int $call): void {
            if ($call === 1) {
                sleep(self::IDLE_TIMEOUT_SECONDS + 2);
            }
        };
        // A common framework setting. Before PHP 8.4 the driver warns when it reads an idle-timeout disconnect.
        set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $level, $file, $line);
        });
        try {
            // The disconnect is read first by the sent write, then by the check before the next claim.
            $error = $this->errorOf(static fn () => $worker->processBatch());
            $next = $this->enqueue('key-2');
            sleep(self::IDLE_TIMEOUT_SECONDS + 2);
            $nextError = $this->errorOf(static fn () => $worker->processBatch());
        } finally {
            restore_error_handler();
        }

        self::assertSame([null, null], [$error, $nextError]);
        self::assertSame(['sent', '1'], [$this->row($job)['status'], $this->row($job)['attempts']]);
        self::assertSame('sent', $this->row($next)['status']);
        self::assertSame(2, $this->transport->calls);
        self::assertCount(3, $this->connections);
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

    public function testASentWriteWhoseReconnectFailsStopsTheBatch(): void
    {
        $first = $this->enqueue('key-1');
        $second = $this->enqueue('key-2');
        $factory = $this->factory();
        $worker = $this->worker(function () use ($factory): PDO {
            if ($this->connections === []) {
                return $factory();
            }
            // The database is still unreachable: a real refused connection, not a fixture exception.
            return new PDO('mysql:host=127.0.0.1;port=1;dbname=unreachable_test', 'fixture', 'fixture', [PDO::ATTR_TIMEOUT => 2]);
        });
        $this->transport->duringSend = fn (int $call) => $this->closeWorkerConnection();

        $error = $this->errorOf(static fn () => $worker->processBatch());

        self::assertNotNull($error);
        self::assertStringStartsWith(PDOException::class . ': SQLSTATE[HY000] [2002]', $error);
        self::assertSame(1, $this->transport->calls, 'No further mail may be sent while the database is unreachable.');
        self::assertSame(['processing', 'processing'], [$this->row($first)['status'], $this->row($second)['status']]);
        self::assertSame(true, $this->logger->contexts('error', self::SENT_UNRECORDED)[0]['connection_lost'] ?? null);
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

    /** @return iterable<string, array{int, string}> */
    public static function priorAttempts(): iterable
    {
        yield 'retry write, newer attempt sent' => [0, "status = 'sent', sent_at = UTC_TIMESTAMP()"];
        yield 'final failure write, newer attempt sent' => [3, "status = 'sent', sent_at = UTC_TIMESTAMP()"];
        // The newer attempt already holds the same status this stale attempt writes, under another attempt number.
        yield 'retry write, newer attempt rescheduled' => [0, "status = 'pending', retry_after = UTC_TIMESTAMP(), last_error = 'newer'"];
        yield 'final failure write, newer attempt failed' => [3, "status = 'failed', last_error = 'newer'"];
    }

    #[DataProvider('priorAttempts')]
    public function testAStaleAttemptCannotOverwriteTheOutcomeOfTheAttemptThatReclaimedItsRow(int $priorAttempts, string $newerOutcome): void
    {
        $job = $this->enqueue('key-1');
        $this->pdo->exec("UPDATE cd_email_queue SET attempts = $priorAttempts WHERE id = $job");
        $this->transport->fail = true;
        $this->transport->duringSend = function () use ($job, $newerOutcome): void {
            // Another worker reclaimed the row after the lease and already recorded its own outcome.
            $this->pdo->exec("UPDATE cd_email_queue SET attempts = attempts + 1, $newerOutcome WHERE id = $job");
        };

        $this->worker($this->pdo)->processBatch();

        self::assertSame(1, preg_match("/status = '([a-z]+)'/", $newerOutcome, $status));
        $row = $this->row($job);
        self::assertSame([$status[1] ?? '', (string) ($priorAttempts + 2)], [$row['status'], $row['attempts']], 'The newer attempt\'s outcome stands.');
        self::assertStringNotContainsString('smtp_transport_error', $row['last_error'], 'The stale failure never overwrote it.');
        // The same status under another attempt number is not this attempt's outcome.
        self::assertSame([], $this->logger->contexts('info', 'Email job scheduled for retry'));
        self::assertSame([], $this->logger->contexts('warning', 'Email job permanently failed'));
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

    public function testALastAttemptWhoseSendFailsAfterItsRowWasSettledAtTheCapReportsLostOwnership(): void
    {
        $job = $this->enqueue('key-1');
        $this->pdo->exec("UPDATE cd_email_queue SET attempts = 3 WHERE id = $job");
        $other = $this->worker(self::connection());
        $this->transport->duringSend = function (int $call) use ($job, $other): void {
            if ($call === 1) {
                // This last attempt outlasts the lease: another worker settles the row at the cap (attempts stay 4).
                $this->expireLease($job);
                $other->processBatch();
                // Then this attempt's own send fails.
                $this->transport->fail = true;
            }
        };

        $this->worker($this->pdo)->processBatch();

        $this->assertSettledAtTheCapAndOwnershipLost($job);
    }

    public function testALastAttemptWhoseFailureWriteIsRepeatedAfterItsRowWasSettledAtTheCapReportsLostOwnership(): void
    {
        $job = $this->enqueue('key-1');
        $this->pdo->exec("UPDATE cd_email_queue SET attempts = 3 WHERE id = $job");
        $other = $this->worker(self::connection());
        $this->transport->duringSend = function (int $call) use ($job, $other): void {
            if ($call === 1) {
                $this->expireLease($job);
                $other->processBatch();
                $this->transport->fail = true;
            }
        };
        $faults = 0;
        // The failure write loses its connection before it runs, so the repeat after the reconnect reads the row back.
        $worker = $this->worker(function () use (&$faults): PDO {
            $pdo = $this->statementFaults("SET status = 'failed', last_error = :error", 2006, $faults);
            $this->connections[] = $pdo;
            return $pdo;
        });

        $worker->processBatch();

        self::assertSame(1, $faults);
        self::assertCount(2, $this->connections, 'The repeat ran on a new connection.');
        $this->assertSettledAtTheCapAndOwnershipLost($job);
    }

    /** @return iterable<string, array{int, string, string, string}> */
    public static function firstTryWrites(): iterable
    {
        yield 'retry write' => [0, 'pending', 'info', 'Email job scheduled for retry'];
        yield 'final failure write' => [3, 'failed', 'warning', 'Email job permanently failed'];
    }

    #[DataProvider('firstTryWrites')]
    public function testAFirstTryFailureWriteThatMatchesNoRowNeverReadsTheRowBack(int $priorAttempts, string $status, string $level, string $recordedMessage): void
    {
        $job = $this->enqueue('key-1');
        $this->pdo->exec("UPDATE cd_email_queue SET attempts = $priorAttempts WHERE id = $job");
        $this->transport->fail = true;
        $this->transport->duringSend = function () use ($job, $status): void {
            // Something else already holds exactly the outcome this attempt is about to write.
            $this->pdo->exec("UPDATE cd_email_queue SET status = '$status',
                last_error = 'smtp_transport_error: Fixture SMTP failure; no network' WHERE id = $job");
        };

        $this->worker($this->pdo)->processBatch();

        self::assertSame([], $this->logger->contexts($level, $recordedMessage), 'Without a reconnect there is no lost acknowledgement to recover.');
        self::assertCount(1, $this->logger->contexts('warning', 'Email job outcome not recorded: ' . self::NOT_OWNED));
        self::assertNull($this->scalarOrNull("SELECT retry_after FROM cd_email_queue WHERE id = $job"));
    }

    public function testARepeatedRetryWriteNeverTakesANewerAttemptsIdenticalOutcomeAsItsOwn(): void
    {
        $job = $this->enqueue('key-1');
        $this->transport->fail = true;
        $this->transport->duringSend = function () use ($job): void {
            // A newer attempt reclaimed the row, failed the same way and rescheduled it: same status and
            // error message, another attempt number.
            $this->pdo->exec("UPDATE cd_email_queue SET attempts = attempts + 1, status = 'pending',
                last_error = 'smtp_transport_error: Fixture SMTP failure; no network',
                retry_after = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 SECOND) WHERE id = $job");
        };
        $faults = 0;
        // This attempt's retry write loses its connection before it runs, so the repeat reads the row back.
        $worker = $this->worker(function () use (&$faults): PDO {
            $pdo = $this->statementFaults("status      = 'pending'", 2006, $faults);
            $this->connections[] = $pdo;
            return $pdo;
        });

        $worker->processBatch();

        self::assertSame(1, $faults);
        self::assertCount(2, $this->connections);
        self::assertSame(['pending', '2'], [$this->row($job)['status'], $this->row($job)['attempts']]);
        self::assertSame([], $this->logger->contexts('info', 'Email job scheduled for retry'), 'The newer attempt\'s outcome is not this attempt\'s.');
        self::assertCount(1, $this->logger->contexts('warning', 'Email job outcome not recorded: ' . self::NOT_OWNED));
    }

    public function testTheSentWriteIsRepeatedExactlyOnceWhenTheNewConnectionIsLostToo(): void
    {
        $first = $this->enqueue('key-1');
        $second = $this->enqueue('key-2');
        $worker = $this->worker(function (): PDO {
            // The first two connections lose their link on the sent write; a third one would succeed.
            $faults = count($this->connections) < 2 ? 0 : 1;
            $pdo = $this->statementFaults("SET status = 'sent'", 2006, $faults);
            $this->connections[] = $pdo;
            return $pdo;
        });

        $error = $this->errorOf(static fn () => $worker->processBatch());

        self::assertNotNull($error);
        self::assertCount(2, $this->connections, 'One reconnect, one repeat.');
        self::assertSame(1, $this->transport->calls);
        self::assertSame(['processing', 'processing'], [$this->row($first)['status'], $this->row($second)['status']]);
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

    public function testAnApplicationThatTurnsWarningsIntoExceptionsStillRecoversFromAServerSideClose(): void
    {
        $job = $this->enqueue('key-1');
        $worker = $this->worker($this->factory());
        $this->transport->duringSend = fn (int $call) => $this->closeWorkerConnection();
        set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $level, $file, $line);
        });
        try {
            $error = $this->errorOf(static fn () => $worker->processBatch());
        } finally {
            restore_error_handler();
        }

        self::assertNull($error);
        self::assertSame(['sent', '1'], [$this->row($job)['status'], $this->row($job)['attempts']]);
        self::assertSame(1, $this->transport->calls);
        self::assertCount(2, $this->connections);
    }

    /** @return iterable<string, array{int, string}> */
    public static function failureWrites(): iterable
    {
        yield 'retry write' => [0, "status      = 'pending'"];
        yield 'final failure write' => [3, "SET status = 'failed', last_error = :error"];
    }

    #[DataProvider('failureWrites')]
    public function testAFailureWriteThatFailsOnALiveConnectionLeavesTheRowToTheLeaseAndTheBatchContinues(int $priorAttempts, string $write): void
    {
        $first = $this->enqueue('key-1');
        $second = $this->enqueue('key-2');
        $this->pdo->exec("UPDATE cd_email_queue SET attempts = $priorAttempts WHERE id = $first");
        $faults = 0;
        $connection = $this->statementFaults($write, 1205, $faults);
        $this->transport->duringSend = function (int $call): void {
            $this->transport->fail = $call === 1;
        };

        $error = $this->errorOf(fn () => $this->worker($connection)->processBatch());

        self::assertSame([
            'batch_error' => null,
            'status' => 'processing',
            'attempts' => (string) ($priorAttempts + 1),
            'last_error' => '',
            'next_row' => 'sent',
            'sends' => 2,
        ], [
            'batch_error' => $error,
            'status' => $this->row($first)['status'],
            'attempts' => $this->row($first)['attempts'],
            'last_error' => $this->row($first)['last_error'],
            'next_row' => $this->row($second)['status'],
            'sends' => $this->transport->calls,
        ], 'A lock wait on one failure write does not stop the batch.');
        self::assertSame(1, $faults);
        $unrecorded = $this->logger->contexts('error', 'Email job failure was not recorded');
        self::assertCount(1, $unrecorded);
        self::assertSame([(int) $first, $priorAttempts + 1, false], [$unrecorded[0]['job_id'], $unrecorded[0]['attempt'], $unrecorded[0]['connection_lost']]);
    }

    public function testAFailureWriteStillUnrecordedAfterOneReconnectStopsTheBatch(): void
    {
        $first = $this->enqueue('key-1');
        $second = $this->enqueue('key-2');
        $worker = $this->worker($this->factory());
        $this->closedFrom = 1;
        $this->transport->fail = true;
        $this->transport->duringSend = fn (int $call) => $this->closeWorkerConnection();

        $error = $this->errorOf(static fn () => $worker->processBatch());

        self::assertNotNull($error);
        self::assertStringContainsString('2006', $error);
        self::assertSame(1, $this->transport->calls, 'No further mail may be sent while outcomes cannot be recorded.');
        self::assertCount(2, $this->connections);
        self::assertSame(['processing', 'processing'], [$this->row($first)['status'], $this->row($second)['status']]);
        self::assertSame(true, $this->logger->contexts('error', 'Email job failure was not recorded')[0]['connection_lost'] ?? null);
    }

    // ── connection requirements (review point 1) ──────────────────

    /** @return iterable<string, array{array<int, mixed>}> */
    public static function autocommitOffOptions(): iterable
    {
        yield 'PDO autocommit attribute' => [[PDO::ATTR_AUTOCOMMIT => false]];
        // Pdo\Mysql::ATTR_INIT_COMMAND on PHP 8.4+, the same value as PDO::MYSQL_ATTR_INIT_COMMAND before it.
        $initCommand = defined('Pdo\\Mysql::ATTR_INIT_COMMAND') ? constant('Pdo\\Mysql::ATTR_INIT_COMMAND') : constant('PDO::MYSQL_ATTR_INIT_COMMAND');
        self::assertIsInt($initCommand);
        yield 'session init command' => [[$initCommand => 'SET SESSION autocommit = 0']];
    }

    /** @param array<int, mixed> $options */
    #[DataProvider('autocommitOffOptions')]
    public function testAFactoryConnectionWithoutAutocommitIsRejectedBeforeAnyClaim(array $options): void
    {
        $job = $this->enqueue('key-1');
        $worker = $this->worker(function () use ($options): PDO {
            $pdo = $this->rawConnection($options);
            $this->connections[] = $pdo;
            return $pdo;
        });

        $error = $this->errorOf(static fn () => $worker->processBatch());

        self::assertNotNull($error);
        self::assertStringStartsWith(\UnexpectedValueException::class . ':', $error);
        self::assertStringContainsString('autocommit', $error);
        self::assertSame(['pending', '0'], [$this->row($job)['status'], $this->row($job)['attempts']]);
        self::assertSame(0, $this->transport->calls);
    }

    public function testAPlainConnectionWithoutAutocommitIsRejectedBeforeAnyClaim(): void
    {
        $job = $this->enqueue('key-1');
        $connection = $this->rawConnection([]);
        $connection->exec('SET SESSION autocommit = 0');

        $error = $this->errorOf(fn () => $this->worker($connection)->processBatch());

        self::assertNotNull($error);
        self::assertStringStartsWith(\UnexpectedValueException::class . ':', $error);
        self::assertSame(['pending', '0'], [$this->row($job)['status'], $this->row($job)['attempts']]);
        self::assertSame(0, $this->transport->calls);
        self::assertFalse($connection->inTransaction());
    }

    public function testAReconnectThatReturnsAConnectionWithoutAutocommitStopsTheBatchWithoutUsingIt(): void
    {
        $first = $this->enqueue('key-1');
        $second = $this->enqueue('key-2');
        $healthy = $this->factory();
        $worker = $this->worker(function () use ($healthy): PDO {
            if ($this->connections === []) {
                return $healthy();
            }
            $pdo = $this->rawConnection([PDO::ATTR_AUTOCOMMIT => false]);
            $this->connections[] = $pdo;
            return $pdo;
        });
        $this->transport->duringSend = fn (int $call) => $this->closeWorkerConnection();

        $error = $this->errorOf(static fn () => $worker->processBatch());

        self::assertNotNull($error);
        self::assertStringContainsString('autocommit', $error);
        self::assertSame(1, $this->transport->calls);
        self::assertSame(['processing', 'processing'], [$this->row($first)['status'], $this->row($second)['status']]);
    }

    // ── claim plan and locks (review point 2) ─────────────────────

    public function testTheClaimReadsInIndexOrderLocksAboutABatchAndLeavesFinishedRowsWritable(): void
    {
        $this->seedFinishedRows();
        $this->seedDuePendingRows(300);
        $due = $this->enqueue('key-1');
        $finished = (int) self::scalar($this->pdo, "SELECT MAX(id) FROM cd_email_queue WHERE status = 'sent'");
        $analyze = $this->pdo->query('ANALYZE TABLE cd_email_queue');
        self::assertNotFalse($analyze);
        $analyze->fetchAll();
        $connection = new EmailQueueFaultPdo();
        $workerThread = (int) self::scalar($connection, 'SELECT CONNECTION_ID()');
        $claimReads = [];
        $observed = [];
        $connection->afterExecuteHook = function (string $sql) use (&$claimReads, &$observed, $workerThread, $finished): void {
            if (!str_contains($sql, 'ORDER BY priority')) {
                return;
            }
            $claimReads[] = $sql;
            // Inside the claim transaction, after each ordered read (the last measurement covers them all).
            // information_schema.INNODB_TRX is refreshed at most every 0.1 s: wait for a fresh reading.
            usleep(150_000);
            $other = self::connection();
            $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
            $observed['rows_locked'] = (int) self::scalar($other, "SELECT trx_rows_locked FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = $workerThread");
            $other->beginTransaction();
            try {
                $other->exec("UPDATE cd_email_queue SET updated_at = updated_at WHERE id = $finished");
                $observed['finished_row_write'] = 'no wait';
            } catch (PDOException $failure) {
                $observed['finished_row_write'] = 'waited: ' . ($failure->errorInfo[1] ?? '?');
            } finally {
                $other->rollBack();
            }
        };

        $this->worker($connection)->processBatch(10);

        self::assertSame('sent', $this->row($due)['status'], 'The highest-priority row was claimed first.');
        self::assertSame('no wait', $observed['finished_row_write'] ?? null, 'A finished row stays writable while the claim runs.');
        self::assertLessThan(100, $observed['rows_locked'] ?? PHP_INT_MAX, 'With 301 due rows, the claim locks about a batch, not every due row.');
        foreach ($claimReads as $sql) {
            // Both unlocked candidate reads, expired leases and due pending rows, walk the claim index in order.
            $plan = $this->pdo->prepare('EXPLAIN ' . str_replace('FOR UPDATE', '', $sql));
            $plan->bindValue(':limit', 10, PDO::PARAM_INT);
            if (str_contains($sql, ':lease')) {
                $plan->bindValue(':lease', EmailQueueWorker::DEFAULT_PROCESSING_LEASE_SECONDS, PDO::PARAM_INT);
            }
            $plan->execute();
            /** @var array{type: string, key: string|null, Extra: string|null} $row */
            $row = $plan->fetch(PDO::FETCH_ASSOC);
            self::assertSame('idx_email_queue_claim', $row['key'], $sql);
            // An equality walk of one status, not a scan of the whole index (an index not led by status).
            self::assertContains($row['type'], ['ref', 'range'], 'Only the rows of one status are read: ' . $sql);
            self::assertStringNotContainsString('filesort', (string) $row['Extra'], 'Read in index order, stopping at the limit.');
        }
        self::assertCount(2, $claimReads, 'One ordered candidate read per status.');
    }

    public function testTheBatchLimitAndOrderSpanBothClaimReads(): void
    {
        $epoch = (new DateTimeImmutable(self::NOW))->getTimestamp();
        $pending = $this->enqueue('key-1');
        $expired = $this->stale($this->enqueue('key-2'), 1, gmdate('Y-m-d H:i:s', $epoch - 3600));
        // The expired row comes from the second read but sorts first.
        $this->pdo->exec("UPDATE cd_email_queue SET priority = 1 WHERE id = $expired");
        $this->freeze($this->pdo, $epoch, '+00:00');

        $this->worker($this->pdo)->processBatch(1);

        self::assertSame(['sent', '2'], [$this->row($expired)['status'], $this->row($expired)['attempts']]);
        self::assertSame(['pending', '0'], [$this->row($pending)['status'], $this->row($pending)['attempts']], 'Only one row per batch of one.');
        self::assertSame(1, $this->transport->calls);
    }

    // ── lost acknowledgement after a 2013 (review point 3) ────────

    public function testASentWriteWhoseAcknowledgementIsLostKeepsTheFirstSentTime(): void
    {
        $job = $this->enqueue('key-1');
        $faults = 0;
        $worker = $this->worker($this->lostAcknowledgementFactory("SET status = 'sent'", $faults));

        $error = $this->errorOf(static fn () => $worker->processBatch());

        self::assertNull($error);
        self::assertSame(1, $faults, 'The sent write ran on the server before its acknowledgement was lost.');
        self::assertCount(2, $this->connections);
        self::assertSame(1, $this->transport->calls);
        self::assertSame('sent', $this->row($job)['status']);
        self::assertSame('2030-01-01 12:00:00', $this->scalarOrNull("SELECT sent_at FROM cd_email_queue WHERE id = $job"), 'The repeat must not move sent_at.');
        self::assertSame([], $this->logger->contexts('error', self::SENT_UNRECORDED));
    }

    /** @return iterable<string, array{string}> */
    public static function failureReasons(): iterable
    {
        yield 'ASCII reason' => ['Fixture SMTP failure; no network'];
        // Cut inside a two-byte character at byte 128: the stored value must match on the repeat.
        yield 'reason cut at a multibyte character' => [str_repeat('a', 105) . 'é and more'];
    }

    #[DataProvider('failureReasons')]
    public function testARetryWriteWhoseAcknowledgementIsLostCountsAsRecordedAndKeepsTheFirstRetryTime(string $reason): void
    {
        $job = $this->enqueue('key-1');
        $faults = 0;
        $worker = $this->worker($this->lostAcknowledgementFactory("status      = 'pending'", $faults));
        $this->transport->fail = true;
        $this->transport->failureMessage = $reason;

        $error = $this->errorOf(static fn () => $worker->processBatch());

        self::assertNull($error);
        self::assertSame(1, $faults);
        self::assertSame(['pending', '1'], [$this->row($job)['status'], $this->row($job)['attempts']]);
        self::assertSame('2030-01-01 12:00:30', $this->scalarOrNull("SELECT retry_after FROM cd_email_queue WHERE id = $job"));
        $scheduled = $this->logger->contexts('info', 'Email job scheduled for retry');
        self::assertCount(1, $scheduled);
        self::assertSame('2030-01-01 12:00:30', $scheduled[0]['retry_after'], 'The log reports the stored retry time.');
        self::assertSame([], $this->logger->contexts('warning', 'Email job outcome not recorded: ' . self::NOT_OWNED));
    }

    public function testAFinalFailureWriteWhoseAcknowledgementIsLostCountsAsRecorded(): void
    {
        $job = $this->enqueue('key-1');
        $this->pdo->exec("UPDATE cd_email_queue SET attempts = 3 WHERE id = $job");
        $faults = 0;
        $worker = $this->worker($this->lostAcknowledgementFactory("SET status = 'failed', last_error = :error", $faults));
        $this->transport->fail = true;

        $error = $this->errorOf(static fn () => $worker->processBatch());

        self::assertNull($error);
        self::assertSame(1, $faults);
        self::assertSame(['failed', '4'], [$this->row($job)['status'], $this->row($job)['attempts']]);
        self::assertCount(1, $this->logger->contexts('warning', 'Email job permanently failed'));
        self::assertSame([], $this->logger->contexts('warning', 'Email job outcome not recorded: ' . self::NOT_OWNED));
    }

    public function testALeaseRenewalWhoseAcknowledgementIsLostOnlyMovesTheLeaseForwardAndSendsOnce(): void
    {
        $job = $this->enqueue('key-1');
        $faults = 0;
        $worker = $this->worker($this->lostAcknowledgementFactory('SET updated_at = ', $faults));

        $error = $this->errorOf(static fn () => $worker->processBatch());

        self::assertNull($error);
        self::assertSame(1, $faults);
        self::assertSame(1, $this->transport->calls);
        self::assertSame('sent', $this->row($job)['status']);
        self::assertSame('2030-01-01 12:01:40', $this->scalarOrNull("SELECT updated_at FROM cd_email_queue WHERE id = $job"), 'The repeated renewal moved the lease forward.');
    }

    // ── a TIMESTAMP updated_at (application-owned schemas) ────────

    /** @return iterable<string, array{string, string}> */
    public static function sessionZonePairs(): iterable
    {
        yield 'both +03:00' => ['+03:00', '+03:00'];
        yield 'both -05:00' => ['-05:00', '-05:00'];
        yield 'claimed in +03:00, checked in -05:00' => ['+03:00', '-05:00'];
        yield 'claimed in -05:00, checked in +03:00' => ['-05:00', '+03:00'];
    }

    #[DataProvider('sessionZonePairs')]
    public function testOnATimestampLeaseColumnAFreshlyClaimedRowIsNeverReclaimedFromAnotherSessionZone(string $claimZone, string $otherZone): void
    {
        $this->useTimestampLeaseColumn();
        $rows = [$this->enqueue('key-1'), $this->enqueue('key-2'), $this->legacy()];
        $other = $this->worker($this->zoned($otherZone));
        $this->transport->duringSend = static function (int $call) use ($other): void {
            if ($call === 1) {
                // The first row was just renewed; the others were only stamped by the claim.
                $other->processBatch();
            }
        };

        $this->worker($this->zoned($claimZone))->processBatch();

        self::assertSame(3, $this->transport->calls, 'Only the claiming worker sends.');
        foreach ($rows as $id) {
            self::assertSame(['sent', '1'], [$this->row($id)['status'], $this->row($id)['attempts']]);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function sessionZones(): iterable
    {
        yield '+03:00' => ['+03:00'];
        yield '-05:00' => ['-05:00'];
        yield '+00:00' => ['+00:00'];
    }

    #[DataProvider('sessionZones')]
    public function testOnATimestampLeaseColumnALeaseExpiresExactlyAtItsBoundaryInAnySessionZone(string $zone): void
    {
        $this->useTimestampLeaseColumn();
        $epoch = (new DateTimeImmutable(self::NOW))->getTimestamp();
        // Written through the +00:00 fixture connection, so each value is the instant it names.
        $instant = static fn (int $secondsAgo): string => gmdate('Y-m-d H:i:s', $epoch - $secondsAgo);
        $rows = [
            'keyed_expired' => $this->stale($this->enqueue('key-1'), 1, $instant(61)),
            'keyed_at_lease' => $this->stale($this->enqueue('key-2'), 1, $instant(60)),
            'legacy_expired' => $this->stale($this->legacy(), 1, $instant(61)),
            'legacy_at_lease' => $this->stale($this->legacy(), 1, $instant(60)),
        ];

        $this->worker($this->zoned($zone, $epoch), 60)->processBatch();

        $observed = [];
        foreach ($rows as $name => $id) {
            $observed[$name] = [$this->row($id)['status'], $this->row($id)['attempts']];
        }
        self::assertSame([
            'keyed_expired' => ['sent', '2'],
            'keyed_at_lease' => ['processing', '1'],
            'legacy_expired' => ['sent', '2'],
            'legacy_at_lease' => ['processing', '1'],
        ], $observed);
    }

    #[DataProvider('sessionZones')]
    public function testOnATimestampLeaseColumnARowClaimedByAnOlderWorkerIsJudgedByItsDatabaseStamp(string $zone): void
    {
        $this->useTimestampLeaseColumn();
        $epoch = (new DateTimeImmutable(self::NOW))->getTimestamp();
        $fresh = $this->enqueue('key-1');
        $old = $this->enqueue('key-2');
        // A 1.2.0 worker's claim sets only status and attempts; ON UPDATE CURRENT_TIMESTAMP stamps the instant.
        $older = $this->zoned('+03:00', $epoch - 10);
        $older->exec("UPDATE cd_email_queue SET status = 'processing', attempts = attempts + 1 WHERE id = $fresh");
        $older->exec('SET timestamp=' . ($epoch - 400));
        $older->exec("UPDATE cd_email_queue SET status = 'processing', attempts = attempts + 1 WHERE id = $old");

        $this->worker($this->zoned($zone, $epoch))->processBatch();

        self::assertSame(['processing', '1'], [$this->row($fresh)['status'], $this->row($fresh)['attempts']], 'Claimed 10 seconds ago: still leased.');
        self::assertSame(['sent', '2'], [$this->row($old)['status'], $this->row($old)['attempts']], 'Claimed 400 seconds ago: reclaimed.');
        self::assertSame(1, $this->transport->calls);
    }

    // ── lead review L1: the claim never locks an in-flight row ────

    public function testTheClaimLocksNoFreshProcessingRow(): void
    {
        $due = $this->enqueue('key-1');
        $fresh = $this->seedInFlightRows(40);
        $connection = new EmailQueueFaultPdo();
        $workerThread = (int) self::scalar($connection, 'SELECT CONNECTION_ID()');
        $version = $connection->getAttribute(PDO::ATTR_SERVER_VERSION);
        $mysql = is_string($version) && !str_contains(strtolower($version), 'mariadb');
        $observed = ['locking_reads' => 0, 'blocked' => [], 'fresh_primary_locks' => 0];
        $connection->afterExecuteHook = function (string $sql) use (&$observed, $workerThread, $fresh, $mysql): void {
            if (!str_contains($sql, 'FOR UPDATE')) {
                return;
            }
            // Inside the claim transaction, after each locking read.
            ++$observed['locking_reads'];
            $other = self::connection();
            if ($mysql) {
                // performance_schema.data_locks is live (information_schema.INNODB_TRX is cached for 0.1 s).
                $observed['fresh_primary_locks'] = max($observed['fresh_primary_locks'], (int) self::scalar($other, "
                    SELECT COUNT(*) FROM performance_schema.data_locks l
                    JOIN performance_schema.threads t ON t.THREAD_ID = l.THREAD_ID
                    WHERE t.PROCESSLIST_ID = $workerThread AND l.INDEX_NAME = 'PRIMARY' AND l.LOCK_TYPE = 'RECORD'
                      AND l.LOCK_DATA IN (" . implode(',', $fresh) . ')'));
            }
            // Every other worker records the outcome of its own in-flight row meanwhile.
            $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
            foreach ($fresh as $id) {
                $other->beginTransaction();
                try {
                    $other->exec("UPDATE cd_email_queue SET status = 'sent', sent_at = UTC_TIMESTAMP() WHERE id = $id");
                } catch (PDOException $failure) {
                    $observed['blocked'][] = $id . ': ' . ($failure->errorInfo[1] ?? '?');
                    break;
                } finally {
                    $other->rollBack();
                }
            }
        };

        $this->worker($connection)->processBatch();

        self::assertSame('sent', $this->row($due)['status']);
        self::assertGreaterThan(0, $observed['locking_reads']);
        self::assertSame(0, $observed['fresh_primary_locks'], 'No fresh processing row is locked by primary key (MySQL data_locks).');
        self::assertSame([], $observed['blocked'], 'Every in-flight row stays writable while the claim runs.');
    }

    public function testAnOutcomeWriteOnAnInFlightRowNeverDeadlocksWithAConcurrentClaim(): void
    {
        $due = $this->enqueue('key-1');
        $inFlight = $this->seedInFlightRows(1)[0];
        $this->pdo->exec("UPDATE cd_email_queue SET priority = 1 WHERE id = $inFlight");
        $directory = sys_get_temp_dir() . '/email-claim-race-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory, 0700));
        $child = proc_open([PHP_BINARY, dirname(__DIR__) . '/Support/email-outcome-writer.php', $directory, (string) $inFlight], [
            0 => ['file', '/dev/null', 'r'], 1 => ['file', "$directory/stdout", 'w'], 2 => ['file', "$directory/stderr", 'w'],
        ], $pipes);
        self::assertIsResource($child);
        try {
            self::assertTrue($this->waitForFile("$directory/locked"), 'Child stderr: ' . @file_get_contents("$directory/stderr"));
            $connection = new EmailQueueFaultPdo();
            $connection->afterExecuteHook = static function (string $sql) use ($directory): void {
                // The other worker records its outcome while this claim is running.
                if (str_contains($sql, 'ORDER BY priority') && !is_file("$directory/go")) {
                    touch("$directory/go");
                }
            };

            $claimError = $this->errorOf(fn () => $this->worker($connection)->processBatch());

            self::assertTrue($this->waitForFile("$directory/result"), 'Child stderr: ' . @file_get_contents("$directory/stderr"));
            self::assertSame(['claim' => null, 'other worker' => 'ok'], ['claim' => $claimError, 'other worker' => (string) file_get_contents("$directory/result")], 'No deadlock (1213) on either side.');
            self::assertSame('sent', $this->row($inFlight)['status']);
            self::assertSame('sent', $this->row($due)['status']);
        } finally {
            proc_close($child);
            array_map('unlink', glob("$directory/*") ?: []);
            rmdir($directory);
        }
    }

    public function testTwoClaimsNeverReclaimTheSameExpiredRow(): void
    {
        // Real clocks here: an hour old is far past the default lease.
        $job = $this->stale($this->enqueue('key-1'), 1, gmdate('Y-m-d H:i:s', time() - 3600));
        $other = $this->rawConnection([]);
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $otherError = null;
        $connection = new EmailQueueFaultPdo();
        $connection->afterExecuteHook = function (string $sql) use ($other, &$otherError): void {
            if (str_contains($sql, 'WHERE id IN (') && $otherError === null) {
                // Right after this claim locked the expired row, another worker runs its own claim.
                $otherError = $this->errorOf(fn () => $this->worker($other)->processBatch()) ?? 'none';
            }
        };

        $this->worker($connection)->processBatch();

        self::assertStringContainsString('1205', (string) $otherError, 'The other claim waited for the locked row instead of taking it.');
        self::assertSame(1, $this->transport->calls, 'Sent once, by one claim.');
        self::assertSame(['sent', '2'], [$this->row($job)['status'], $this->row($job)['attempts']]);
    }

    public function testACandidateRenewedBeforeItIsLockedIsNotReclaimed(): void
    {
        $job = $this->stale($this->enqueue('key-1'), 1, gmdate('Y-m-d H:i:s', time() - 3600));
        $connection = new EmailQueueFaultPdo();
        $renewed = false;
        $connection->afterExecuteHook = function (string $sql) use ($job, &$renewed): void {
            if (str_starts_with(ltrim($sql), 'SELECT id FROM') && !$renewed) {
                // Between the unlocked candidate read and the lock, the owner renews its lease.
                $renewed = true;
                $this->pdo->exec("UPDATE cd_email_queue SET updated_at = UTC_TIMESTAMP() WHERE id = $job");
            }
        };

        $this->worker($connection)->processBatch();

        self::assertTrue($renewed);
        self::assertSame(0, $this->transport->calls, 'The lease is re-checked on the latest version before the row is taken.');
        self::assertSame(['processing', '1'], [$this->row($job)['status'], $this->row($job)['attempts']]);
    }

    public function testADueCandidateRescheduledBeforeItIsLockedIsNotClaimed(): void
    {
        $job = $this->enqueue('key-1');
        $connection = new EmailQueueFaultPdo();
        $rescheduled = false;
        $connection->afterExecuteHook = function (string $sql) use ($job, &$rescheduled): void {
            if (str_starts_with(ltrim($sql), 'SELECT id FROM') && str_contains($sql, "status = 'pending'") && !$rescheduled) {
                // Between the unlocked candidate read and the lock, the application moves the message to tomorrow.
                $rescheduled = true;
                $this->pdo->exec("UPDATE cd_email_queue SET scheduled_at = UTC_TIMESTAMP() + INTERVAL 1 DAY WHERE id = $job");
            }
        };

        $this->worker($connection)->processBatch();

        self::assertTrue($rescheduled);
        self::assertSame(0, $this->transport->calls, 'The due predicate is re-checked on the latest version before the row is taken.');
        self::assertSame(['pending', '0'], [$this->row($job)['status'], $this->row($job)['attempts']]);
    }

    // ── owner review R1: rows that are not due yet are never locked ──

    /** @return iterable<string, array{string, int}> */
    public static function notDueRowsAheadOfADueOne(): iterable
    {
        foreach (['scheduled_at' => 'scheduled in the future', 'retry_after' => 'retrying in the future'] as $column => $name) {
            foreach ([1, 50] as $limit) {
                yield "$name, batch limit $limit" => [$column, $limit];
            }
        }
    }

    /**
     * 1,000 priority-1 rows that are not due yet sit ahead of one due priority-9 row in (priority, id) order. A
     * locking scan would lock every one of them, and the gaps between them, until the claim commits.
     */
    #[DataProvider('notDueRowsAheadOfADueOne')]
    public function testAClaimLocksNoPendingRowThatIsNotDueYet(string $notDue, int $limit): void
    {
        $notDueIds = $this->seedNotDuePendingRows(1000, $notDue);
        $due = $this->enqueue('key-due');
        $this->pdo->exec("UPDATE cd_email_queue SET priority = 9 WHERE id = $due");
        $connection = new EmailQueueFaultPdo();
        $workerThread = (int) self::scalar($connection, 'SELECT CONNECTION_ID()');
        $mysql = self::isMysql($connection);
        $observed = ['locking_reads' => 0, 'waited' => [], 'rows_locked' => null, 'record_locks' => null];
        $connection->afterExecuteHook = function (string $sql) use (&$observed, $workerThread, $mysql, $notDueIds): void {
            if (!str_contains($sql, 'FOR UPDATE')) {
                return;
            }
            // Inside the claim transaction, right after its locking read.
            ++$observed['locking_reads'];
            $other = self::connection();
            $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
            usleep(150_000); // information_schema.INNODB_TRX is refreshed at most every 0.1 s
            $observed['rows_locked'] = (int) self::scalar($other, "SELECT trx_rows_locked FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = $workerThread");
            if ($mysql) {
                $observed['record_locks'] = self::recordLocks($other, $workerThread);
            }
            $probes = [
                'reschedule a row that is not due' => "UPDATE cd_email_queue SET scheduled_at = scheduled_at + INTERVAL 1 MINUTE WHERE id = {$notDueIds[499]}",
                'write the first row that is not due' => "UPDATE cd_email_queue SET last_error = 'probe' WHERE id = {$notDueIds[0]}",
                'enqueue at priority 1' => self::enqueueStatement(1),
                'enqueue at priority 5' => self::enqueueStatement(5),
                'enqueue at priority 9' => self::enqueueStatement(9),
            ];
            foreach ($probes as $what => $probe) {
                $other->beginTransaction();
                try {
                    $other->exec($probe);
                } catch (PDOException $failure) {
                    $observed['waited'][] = $what . ': ' . ($failure->errorInfo[1] ?? '?');
                } finally {
                    $other->rollBack();
                }
            }
        };

        $this->worker($connection)->processBatch($limit);

        self::assertSame(1, $observed['locking_reads']);
        self::assertSame([], $observed['waited'], 'Writes to rows that are not due, and enqueues at any priority, never wait for the claim.');
        self::assertCandidateLocksOnly([$due], $observed['rows_locked'], $observed['record_locks']);
        self::assertSame('sent', $this->row($due)['status']);
        self::assertSame(1000, (int) self::scalar($this->pdo, "SELECT COUNT(*) FROM cd_email_queue WHERE status = 'pending' AND attempts = 0 AND priority = 1"));
    }

    // ── owner review R2: expired, fresh and pending rows together ──

    public function testAClaimOfExpiredLeasesAmongFreshOnesLocksOnlyItsCandidatesByPrimaryKey(): void
    {
        $expired = [];
        for ($i = 0; $i < 3; ++$i) {
            $expired[] = $this->stale($this->enqueue('expired-' . $i), 1, gmdate('Y-m-d H:i:s', time() - 3600));
        }
        $fresh = $this->seedInFlightRows(40);
        // Fresh rows before, between and after the expired ones in the (status, priority, id) index.
        $this->pdo->exec('UPDATE cd_email_queue SET priority = 1 WHERE id IN (' . implode(',', array_slice($fresh, 0, 15)) . ')');
        $this->pdo->exec('UPDATE cd_email_queue SET priority = 5 WHERE id IN (' . implode(',', [...array_slice($fresh, 15, 15), ...$expired]) . ')');
        $pending = [$this->enqueue('due-1'), $this->enqueue('due-2')];
        $candidates = [...$expired, ...$pending];
        sort($candidates);
        $connection = new EmailQueueFaultPdo();
        $workerThread = (int) self::scalar($connection, 'SELECT CONNECTION_ID()');
        $mysql = self::isMysql($connection);
        $observed = ['locking_reads' => 0, 'plan' => [], 'blocked' => [], 'rows_locked' => null, 'record_locks' => null];
        $connection->afterExecuteHook = function (string $sql) use (&$observed, $workerThread, $mysql, $fresh, $candidates): void {
            if (!str_contains($sql, 'FOR UPDATE')) {
                return;
            }
            // Inside the claim transaction, right after its locking read.
            ++$observed['locking_reads'];
            $other = self::connection();
            $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
            $explained = preg_replace('/\bFOR UPDATE\b/', '', str_replace(':lease', '300', (string) preg_replace('/\(:id\d+(?:, :id\d+)*\)/', '(' . implode(', ', $candidates) . ')', $sql)));
            foreach (($other->query('EXPLAIN ' . $explained) ?: throw new \LogicException('EXPLAIN failed'))->fetchAll(PDO::FETCH_ASSOC) as $step) {
                $observed['plan'][] = [(string) ($step['key'] ?? ''), (string) ($step['type'] ?? '')];
            }
            usleep(150_000); // information_schema.INNODB_TRX is refreshed at most every 0.1 s
            $observed['rows_locked'] = (int) self::scalar($other, "SELECT trx_rows_locked FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id = $workerThread");
            if ($mysql) {
                $observed['record_locks'] = self::recordLocks($other, $workerThread);
            }
            // Every other worker records the outcome of its own in-flight row meanwhile.
            foreach ($fresh as $id) {
                $other->beginTransaction();
                try {
                    $other->exec("UPDATE cd_email_queue SET status = 'sent', sent_at = UTC_TIMESTAMP() WHERE id = $id");
                } catch (PDOException $failure) {
                    $observed['blocked'][] = $id . ': ' . ($failure->errorInfo[1] ?? '?');
                } finally {
                    $other->rollBack();
                }
            }
        };

        $this->worker($connection)->processBatch();

        self::assertSame(1, $observed['locking_reads']);
        self::assertSame([['PRIMARY', 'range']], $observed['plan'], 'The locking read looks the candidates up by primary key.');
        self::assertSame([], $observed['blocked'], 'No outcome write on a fresh in-flight row waits for the claim.');
        self::assertCandidateLocksOnly($candidates, $observed['rows_locked'], $observed['record_locks']);
        foreach ($expired as $id) {
            self::assertSame(['sent', '2'], [$this->row($id)['status'], $this->row($id)['attempts']], 'Each expired lease is claimed and sent again.');
        }
        foreach ($pending as $id) {
            self::assertSame(['sent', '1'], [$this->row($id)['status'], $this->row($id)['attempts']]);
        }
        foreach ($fresh as $id) {
            self::assertSame(['processing', '1'], [$this->row($id)['status'], $this->row($id)['attempts']]);
        }
        self::assertSame(5, $this->transport->calls);
    }

    // ── lead review L2: a failure reason is cut on a character boundary ──

    public function testAFailureReasonCutInsideAMultibyteCharacterIsStillRecorded(): void
    {
        $job = $this->enqueue('key-1');
        $this->transport->fail = true;
        // "smtp_transport_error: " is 22 bytes, so the two-byte "é" occupies bytes 127 and 128.
        $this->transport->failureMessage = str_repeat('a', 105) . 'é and more';

        $this->worker($this->pdo)->processBatch();

        $row = $this->row($job);
        self::assertSame('pending', $row['status'], 'The retry was recorded.');
        self::assertSame('smtp_transport_error: ' . str_repeat('a', 105), $row['last_error'], 'Cut before the character, never inside it.');
        self::assertCount(1, $this->logger->contexts('info', 'Email job scheduled for retry'));
    }

    // ── lead review L3: daylight saving time on a TIMESTAMP lease ──

    /** @return iterable<string, array{string, int, ?int, int, bool}> */
    public static function daylightSavingCases(): iterable
    {
        $spring = gmmktime(7, 0, 0, 3, 10, 2030); // America/New_York: 02:00 EST jumps to 03:00 EDT
        $fall = gmmktime(6, 0, 0, 11, 3, 2030);   // America/New_York: 02:00 EDT falls back to 01:00 EST
        // [stamp, stamped at, renewed at (null: no renewal), checked at, expected to be claimed again]; lease 300 s.
        yield 'spring forward, claimed 120 s before the check' => ['claim', $spring - 60, null, $spring + 60, false];
        yield 'spring forward, claimed 460 s before the check' => ['claim', $spring - 400, null, $spring + 60, true];
        yield 'spring forward, renewal 120 s before the check' => ['claim', $spring - 400, $spring - 60, $spring + 60, false];
        yield 'fall back, claimed in the repeated hour 120 s before' => ['claim', $fall + 100, null, $fall + 220, false];
        yield 'fall back, claimed in the repeated hour 310 s before' => ['claim', $fall + 100, null, $fall + 410, true];
        yield 'fall back, claimed before the hour, 370 s before' => ['claim', $fall - 60, null, $fall + 310, true];
        yield 'fall back, renewal in the repeated hour 120 s before' => ['claim', $fall - 400, $fall + 100, $fall + 220, false];
        yield 'spring forward, ON UPDATE stamp 120 s before' => ['on update', $spring - 60, null, $spring + 60, false];
        yield 'fall back, ON UPDATE stamp in the repeated hour 120 s before' => ['on update', $fall + 100, null, $fall + 220, false];
        yield 'fall back, ON UPDATE stamp in the repeated hour 310 s before' => ['on update', $fall + 100, null, $fall + 410, true];
    }

    #[DataProvider('daylightSavingCases')]
    public function testOnATimestampLeaseColumnDaylightSavingNeverReclaimsEarlyOrMissesTheLease(string $stamp, int $stampedAt, ?int $renewedAt, int $checkedAt, bool $reclaimed): void
    {
        $this->useTimestampLeaseColumn();
        $job = $this->enqueue('key-1');
        $checker = $this->worker($this->dstSession($checkedAt), EmailQueueWorker::DEFAULT_PROCESSING_LEASE_SECONDS);
        if ($stamp === 'on update') {
            // An older worker's claim: status and attempts only; ON UPDATE CURRENT_TIMESTAMP stamps the instant.
            $this->dstSession($stampedAt)->exec("UPDATE cd_email_queue SET status = 'processing', attempts = attempts + 1 WHERE id = $job");
            $checker->processBatch();
        } else {
            $claimer = new EmailQueueFaultPdo();
            $this->inDstZone($claimer, $stampedAt);
            if ($renewedAt !== null) {
                // The claim stamps at $stampedAt; the renewal right after it stamps at $renewedAt.
                $claimer->afterExecuteHook = static function (string $sql) use ($claimer, $renewedAt): void {
                    if (str_contains($sql, "SET status     = 'processing'")) {
                        $claimer->exec('SET timestamp = ' . $renewedAt);
                    }
                };
            }
            $this->transport->duringSend = static function (int $call) use ($checker): void {
                if ($call === 1) {
                    $checker->processBatch();
                }
            };
            $this->worker($claimer)->processBatch();
        }

        $expectedAttempts = $reclaimed ? '2' : '1';
        self::assertSame($expectedAttempts, $this->row($job)['attempts'], $reclaimed ? 'Claimed again after its lease.' : 'Still leased: never claimed again early.');
    }

    // ── fixture helpers ───────────────────────────────────────────

    private function assertSettledAtTheCapAndOwnershipLost(int $job): void
    {
        $row = $this->row($job);
        self::assertSame(['failed', '4', 'processing_lease_expired'], [$row['status'], $row['attempts'], $row['last_error']], 'The settlement stands.');
        $failed = $this->logger->contexts('warning', 'Email job permanently failed');
        self::assertSame(['processing_lease_expired'], array_column($failed, 'reason'), 'Only the settlement reports a permanent failure.');
        self::assertCount(1, $this->logger->contexts('warning', 'Email job outcome not recorded: ' . self::NOT_OWNED));
    }

    /** A real connection whose first `sent` write fails with the given driver code. */
    private function sentWriteFaults(?int $driverCode, int &$faults): EmailQueueFaultPdo
    {
        return $this->statementFaults("SET status = 'sent'", $driverCode, $faults, 'Fixture sent-status write failure');
    }

    /** A real connection whose first statement containing $needle fails with the given driver code. */
    private function statementFaults(string $needle, ?int $driverCode, int &$faults, string $message = 'Fixture status write failure'): EmailQueueFaultPdo
    {
        $pdo = new EmailQueueFaultPdo();
        $pdo->beforeExecuteHook = static function (string $sql) use ($needle, $driverCode, $message, &$faults): void {
            if (str_contains($sql, $needle) && $faults === 0) {
                ++$faults;
                $failure = new PDOException($message);
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

    /** @return list<int> ids of rows other workers are sending right now (fresh leases), at the lowest priority */
    private function seedInFlightRows(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $ids[] = $id = $this->enqueue('in-flight-' . $i);
            $this->pdo->exec("UPDATE cd_email_queue SET status = 'processing', attempts = 1, priority = 9, updated_at = UTC_TIMESTAMP() WHERE id = $id");
        }
        return $ids;
    }

    /**
     * $count pending rows at priority 1 that are not due yet: scheduled for tomorrow, or retrying tomorrow.
     *
     * @return list<int>
     */
    private function seedNotDuePendingRows(int $count, string $notDue): array
    {
        $seed = $this->enqueue('not-due-seed');
        [$scheduled, $retry] = $notDue === 'scheduled_at'
            ? ['UTC_TIMESTAMP() + INTERVAL 1 DAY', 'NULL']
            : ['UTC_TIMESTAMP() - INTERVAL 1 DAY', 'UTC_TIMESTAMP() + INTERVAL 1 DAY'];
        $set = ['enqueue_receipt_id', 'priority', 'status', 'attempts', 'scheduled_at', 'retry_after'];
        /** @var list<string> $all */
        $all = ($this->pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cd_email_queue' AND COLUMN_NAME <> 'id' ORDER BY ORDINAL_POSITION") ?: throw new \LogicException('No columns'))->fetchAll(PDO::FETCH_COLUMN);
        $copied = array_values(array_diff($all, $set));
        $digits = '(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        // Legacy copies of the seed (no receipt), so the unique receipt key allows them.
        $this->pdo->exec('INSERT INTO cd_email_queue (' . implode(', ', [...$copied, ...$set]) . ')
            SELECT ' . implode(', ', array_map(static fn (string $column): string => 's.' . $column, $copied)) . ", NULL, 1, 'pending', 0, $scheduled, $retry
            FROM cd_email_queue s, $digits a, $digits b, $digits c WHERE s.id = $seed LIMIT " . ($count - 1));
        $this->pdo->exec("UPDATE cd_email_queue SET priority = 1, scheduled_at = $scheduled, retry_after = $retry WHERE id = $seed");
        /** @var list<int> $ids */
        $ids = array_map('intval', ($this->pdo->query("SELECT id FROM cd_email_queue WHERE status = 'pending' AND priority = 1 ORDER BY id") ?: throw new \LogicException('No rows'))->fetchAll(PDO::FETCH_COLUMN));
        self::assertCount($count, $ids);
        return $ids;
    }

    /** A plain enqueue INSERT of one due pending row at $priority (the legacy writer's shape). */
    private static function enqueueStatement(int $priority): string
    {
        return "INSERT INTO cd_email_queue (entity_type, entity_id, recipient_encrypted, recipient_iv, recipient_tag, recipient_key_id,
                payload_encrypted, payload_iv, payload_tag, payload_key_id, template_key, language, sender_type, priority, status,
                attempts, scheduled_at, created_at, updated_at)
            VALUES ('user', 'probe', 'x', 'iv', 'tag', 'k', 'x', 'iv', 'tag', 'k', 'welcome', 'en', 1, $priority, 'pending',
                0, NOW(), NOW(), NOW())";
    }

    /**
     * The claim's locks are its candidates' primary-key records, nothing else. One addition is allowed: when a
     * candidate is the first record on its page, InnoDB's in-order primary-key read passes the previous page's
     * supremum and takes a next-key lock on it. That covers only the gap between the candidate and the id
     * before it, so at most one such lock per candidate, on PRIMARY.
     *
     * @param list<int> $candidates
     * @param list<array{string, string, string}>|null $recordLocks MySQL data_locks, null on MariaDB
     */
    private static function assertCandidateLocksOnly(array $candidates, ?int $rowsLocked, ?array $recordLocks): void
    {
        sort($candidates);
        if ($recordLocks === null) {
            // MariaDB has no data_locks; INNODB_TRX counts the same locks.
            self::assertNotNull($rowsLocked);
            self::assertGreaterThanOrEqual(count($candidates), $rowsLocked, 'Every candidate is locked (INNODB_TRX).');
            self::assertLessThanOrEqual(2 * count($candidates), $rowsLocked, 'Nothing but the candidates, and at most one page boundary each, is locked (INNODB_TRX).');
            return;
        }
        $supremum = array_values(array_filter($recordLocks, static fn (array $lock): bool => $lock[2] === 'supremum pseudo-record'));
        $records = array_values(array_filter($recordLocks, static fn (array $lock): bool => $lock[2] !== 'supremum pseudo-record'));
        self::assertSame(
            array_map(static fn (int $id): array => ['PRIMARY', 'X,REC_NOT_GAP', (string) $id], $candidates),
            $records,
            'Only the candidates\' primary-key records are locked: no other row, no index entry, no gap before a record (MySQL data_locks).',
        );
        self::assertLessThanOrEqual(count($candidates), count($supremum));
        foreach ($supremum as $lock) {
            self::assertSame(['PRIMARY', 'X', 'supremum pseudo-record'], $lock, 'The only other lock is a primary-key page boundary before a candidate.');
        }
        self::assertSame(count($records) + count($supremum), $rowsLocked, 'INNODB_TRX counts exactly these locks.');
    }

    private static function isMysql(PDO $pdo): bool
    {
        $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        return is_string($version) && !str_contains(strtolower($version), 'mariadb');
    }

    /**
     * The record locks a connection's transaction holds right now (MySQL performance_schema.data_locks is live).
     *
     * @return list<array{string, string, string}> index, lock mode and the locked row's id, ordered by id
     */
    private static function recordLocks(PDO $observer, int $thread): array
    {
        $statement = $observer->query("
            SELECT l.INDEX_NAME, l.LOCK_MODE, l.LOCK_DATA
            FROM performance_schema.data_locks l
            JOIN performance_schema.threads t ON t.THREAD_ID = l.THREAD_ID
            WHERE t.PROCESSLIST_ID = $thread AND l.LOCK_TYPE = 'RECORD'");
        $locks = [];
        foreach (($statement ?: throw new \LogicException('data_locks query failed'))->fetchAll(PDO::FETCH_NUM) as [$index, $mode, $data]) {
            // A secondary index entry lists its key columns and then the primary key: the id is the last value.
            $parts = explode(',', (string) $data);
            $locks[] = [(string) $index, (string) $mode, trim((string) end($parts))];
        }
        usort($locks, static fn (array $a, array $b): int => [(int) $a[2], $a[0]] <=> [(int) $b[2], $b[0]]);
        return $locks;
    }

    private function waitForFile(string $path): bool
    {
        $deadline = microtime(true) + 20;
        while (!is_file($path) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        return is_file($path);
    }

    /** A connection in America/New_York (a zone with daylight saving) frozen at $epoch. */
    private function dstSession(int $epoch): PDO
    {
        $pdo = $this->rawConnection([]);
        $this->inDstZone($pdo, $epoch);
        return $pdo;
    }

    private function inDstZone(PDO $pdo, int $epoch): void
    {
        try {
            $pdo->exec("SET SESSION time_zone = 'America/New_York'");
        } catch (PDOException $missing) {
            self::markTestSkipped('The server has no time zone tables loaded: ' . $missing->getMessage());
        }
        $pdo->exec('SET timestamp = ' . $epoch);
    }

    /** The column type some application schemas use: TIMESTAMP stores instants and converts by session zone. */
    private function useTimestampLeaseColumn(): void
    {
        $this->pdo->exec('ALTER TABLE cd_email_queue MODIFY updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }

    /** A connection in the given session zone, optionally with its clock frozen. */
    private function zoned(string $zone, ?int $epoch = null): PDO
    {
        $pdo = $this->rawConnection([]);
        $this->freeze($pdo, $epoch ?? time(), $zone);
        if ($epoch === null) {
            $pdo->exec('SET timestamp = DEFAULT');
        }
        return $pdo;
    }

    /** @param array<int, mixed> $options */
    private function rawConnection(array $options): PDO
    {
        $dsn = getenv('EMAIL_DELIVERY_IT_DSN');
        self::assertIsString($dsn);
        $pdo = new PDO($dsn, (string) getenv('EMAIL_DELIVERY_IT_USER'), (string) getenv('EMAIL_DELIVERY_IT_PASS'), $options + [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $this->openedThreads[] = (int) self::scalar($pdo, 'SELECT CONNECTION_ID()');
        return $pdo;
    }

    /**
     * Connections whose clocks are frozen 100 seconds apart (12:00:00, 12:01:40, ...). On the first one, the
     * first statement containing $needle really runs on the server; then its acknowledgement is lost (2013).
     *
     * @return Closure(): PDO
     */
    private function lostAcknowledgementFactory(string $needle, int &$faults): Closure
    {
        $epoch = (new DateTimeImmutable(self::NOW))->getTimestamp();
        return function () use ($needle, &$faults, $epoch): PDO {
            $pdo = new EmailQueueFaultPdo();
            $this->openedThreads[] = (int) self::scalar($pdo, 'SELECT CONNECTION_ID()');
            $pdo->exec('SET timestamp=' . ($epoch + 100 * count($this->connections)));
            $pdo->afterExecuteHook = static function (string $sql) use ($needle, &$faults): void {
                if (str_contains($sql, $needle) && $faults === 0) {
                    ++$faults;
                    $lost = new PDOException('Fixture: the statement ran, then its acknowledgement was lost');
                    $lost->errorInfo = ['HY000', 2013, 'Lost connection to MySQL server during query'];
                    throw $lost;
                }
            };
            $this->connections[] = $pdo;
            return $pdo;
        };
    }

    /** Due legacy rows at the lowest priority (9), so a priority-5 row sorts before them. */
    private function seedDuePendingRows(int $count): void
    {
        $digits = '(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4'
            . ' UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        $this->pdo->exec("
            INSERT INTO cd_email_queue (recipient_encrypted, recipient_iv, recipient_tag, recipient_key_id, payload_encrypted,
                payload_iv, payload_tag, payload_key_id, template_key, language, sender_type, priority, status, attempts,
                last_error, scheduled_at, created_at, updated_at)
            SELECT 'x', 'iv', 'tag', 'k', 'x', 'iv', 'tag', 'k', 'welcome', 'en', 1, 9, 'pending', 0, '',
                '2000-01-01 00:00:00', UTC_TIMESTAMP(), UTC_TIMESTAMP()
            FROM (SELECT a.d + 10 * b.d + 100 * c.d AS n FROM $digits a, $digits b, $digits c) seq
            WHERE n < $count
        ");
    }

    /** 9,000 sent and 1,000 failed rows, as in a long-running queue. */
    private function seedFinishedRows(): void
    {
        $digits = '(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4'
            . ' UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        $this->pdo->exec("
            INSERT INTO cd_email_queue (recipient_encrypted, recipient_iv, recipient_tag, recipient_key_id, payload_encrypted,
                payload_iv, payload_tag, payload_key_id, template_key, language, sender_type, priority, status, attempts,
                last_error, sent_at, scheduled_at, created_at, updated_at)
            SELECT 'x', 'iv', 'tag', 'k', 'x', 'iv', 'tag', 'k', 'welcome', 'en', 1, 1 + (n % 9),
                IF(n % 10 = 0, 'failed', 'sent'), 1, '', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()
            FROM (SELECT a.d + 10 * b.d + 100 * c.d + 1000 * e.d AS n FROM $digits a, $digits b, $digits c, $digits e) seq
        ");
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
            $this->openedThreads[] = $id;
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
