<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\PdoEmailQueueWriter;
use Maatify\EmailDelivery\Renderer\TwigEmailRenderer;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Stringable;
use Tests\Support\EmailQueueFaultPdo;
use Tests\Support\EmailQueueTestFactory as Factory;
use Tests\Support\IsolatedEmailMysqlTestCase;
use Tests\Support\WorkerRecordingTransport;

final class EmailQueueWorkerUtcMysqlTest extends IsolatedEmailMysqlTestCase
{
    private const NOW = '2030-01-01T12:00:00Z';
    private WorkerRecordingTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = new WorkerRecordingTransport();
    }

    private function epoch(): int
    {
        return (new DateTimeImmutable(self::NOW))->getTimestamp();
    }

    private function freeze(int $epoch, string $zone = '+02:00'): void
    {
        $this->pdo->exec('SET timestamp=' . $epoch);
        $quoted = $this->pdo->quote($zone);
        self::assertIsString($quoted);
        $this->pdo->exec('SET SESSION time_zone=' . $quoted);
    }

    private function keyed(int $epoch, string $template = 'welcome'): void
    {
        Factory::queue($this->pdo)->enqueueOnce(new EmailEnqueueRequestDTO(
            'fixture-review',
            'key-1',
            'user',
            '1',
            'fixture@example.invalid',
            new EmailQueuePayloadDTO(['name' => 'Fixture'], $template, 'en'),
            1,
            scheduledAt: new DateTimeImmutable('@' . $epoch),
        ));
    }

    private function legacy(string $wallTime): void
    {
        (new PdoEmailQueueWriter($this->pdo, Factory::crypto(), new Factory()))->enqueue(
            'user',
            'legacy',
            'fixture@example.invalid',
            new EmailQueuePayloadDTO(['name' => 'Fixture'], 'welcome', 'en'),
            1,
            scheduledAt: new DateTimeImmutable($wallTime, new DateTimeZone('UTC')),
        );
    }

    private function worker(?PDO $pdo = null, ?LoggerInterface $logger = null): EmailQueueWorker
    {
        return new EmailQueueWorker(
            $pdo ?? $this->pdo,
            Factory::crypto(),
            new TwigEmailRenderer(dirname(__DIR__) . '/Fixtures/templates', ['global_app_name' => 'Fixture'], false),
            $this->transport,
            new Factory(),
            $logger ?? new NullLogger()
        );
    }

    public function testKeyedFutureUtcJobWaitsUntilTheExactUtcBoundaryInALocalSession(): void
    {
        $epoch = $this->epoch();
        $this->keyed($epoch + 1800);
        $worker = $this->worker();
        foreach ([$epoch, $epoch + 1799] as $beforeDue) {
            $this->freeze($beforeDue);
            $worker->processBatch();
            self::assertSame(0, $this->transport->calls);
            self::assertSame('pending', self::scalar($this->pdo, 'SELECT status FROM cd_email_queue'));
            self::assertSame('0', self::scalar($this->pdo, 'SELECT attempts FROM cd_email_queue'));
            self::assertSame('+02:00', self::scalar($this->pdo, 'SELECT @@SESSION.time_zone'));
        }
        $this->freeze($epoch + 1800);
        $worker->processBatch();
        self::assertSame(1, $this->transport->calls);
        self::assertSame('2030-01-01 12:30:00', self::scalar($this->pdo, 'SELECT sent_at FROM cd_email_queue'));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT attempts FROM cd_email_queue'));
        $worker->processBatch();
        self::assertSame(1, $this->transport->calls);
    }

    public function testKeyedRetryUsesDbUtcAndWaitsUntilItsOwnBoundaryDespitePhpZone(): void
    {
        $oldZone = date_default_timezone_get();
        try {
            date_default_timezone_set('Etc/GMT-2');
            $epoch = $this->epoch();
            $this->keyed($epoch - 60);
            $this->freeze($epoch);
            $logger = new class () extends AbstractLogger {
                /** @var list<array<string, mixed>> */
                public array $retry = [];
                /** @param array<string, mixed> $context */
                public function log(mixed $level, string|Stringable $message, array $context = []): void
                {
                    if ((string) $message === 'Email job scheduled for retry') {
                        $this->retry[] = $context;
                    }
                }
            };
            $worker = $this->worker(logger: $logger);
            $this->transport->fail = true;
            $worker->processBatch();
            self::assertSame(1, $this->transport->calls);
            self::assertSame('2030-01-01 12:00:30', self::scalar($this->pdo, 'SELECT retry_after FROM cd_email_queue'));
            self::assertCount(1, $logger->retry);
            self::assertSame('2030-01-01 12:00:30', $logger->retry[0]['retry_after']);
            self::assertSame(30, $logger->retry[0]['delay_secs']);
            $this->transport->fail = false;
            $this->freeze($epoch + 29);
            $worker->processBatch();
            self::assertSame(1, $this->transport->calls);
            self::assertSame('1', self::scalar($this->pdo, 'SELECT attempts FROM cd_email_queue'));
            $this->freeze($epoch + 30);
            $worker->processBatch();
            self::assertSame(2, $this->transport->calls);
            self::assertSame('2', self::scalar($this->pdo, 'SELECT attempts FROM cd_email_queue'));
            self::assertSame('2030-01-01 12:00:30', self::scalar($this->pdo, 'SELECT sent_at FROM cd_email_queue'));
            self::assertSame('+02:00', self::scalar($this->pdo, 'SELECT @@SESSION.time_zone'));
        } finally {
            date_default_timezone_set($oldZone);
        }
    }

    public function testKeyedDefaultFailureRetainsSixtySecondBackoffOnDbUtc(): void
    {
        $this->keyed($this->epoch() - 60, 'fixture_missing_template');
        $this->freeze($this->epoch());
        $this->worker()->processBatch();
        self::assertSame(0, $this->transport->calls);
        self::assertSame('2030-01-01 12:01:00', self::scalar($this->pdo, 'SELECT retry_after FROM cd_email_queue'));
        self::assertSame('pending', self::scalar($this->pdo, 'SELECT status FROM cd_email_queue'));
    }

    public function testMixedLegacyAndKeyedRowsKeepTheirOwnClockSemantics(): void
    {
        $this->keyed($this->epoch() + 3600);
        $this->legacy('2030-01-01 13:00:00');
        $this->freeze($this->epoch());
        $worker = $this->worker();
        $worker->processBatch();
        self::assertSame(1, $this->transport->calls);
        self::assertSame('2030-01-01 14:00:00', self::scalar($this->pdo, 'SELECT sent_at FROM cd_email_queue WHERE enqueue_receipt_id IS NULL'));
        self::assertSame('pending', self::scalar($this->pdo, 'SELECT status FROM cd_email_queue WHERE enqueue_receipt_id IS NOT NULL'));
        $this->freeze($this->epoch() + 3600);
        $worker->processBatch();
        self::assertSame(2, $this->transport->calls);
        self::assertSame('2030-01-01 13:00:00', self::scalar($this->pdo, 'SELECT sent_at FROM cd_email_queue WHERE enqueue_receipt_id IS NOT NULL'));
    }

    public function testLegacyRowsRetainPhpLocalRetryCalculation(): void
    {
        $oldZone = date_default_timezone_get();
        try {
            date_default_timezone_set('Etc/GMT-2');
            $this->legacy('2000-01-01 00:00:00');
            $this->freeze($this->epoch());
            $before = time();
            $this->transport->fail = true;
            $this->worker()->processBatch();
            $after = time();
            $retry = self::scalar($this->pdo, 'SELECT retry_after FROM cd_email_queue');
            $storedEpoch = (new DateTimeImmutable($retry, new DateTimeZone('Etc/GMT-2')))->getTimestamp();
            self::assertGreaterThanOrEqual($before + 30, $storedEpoch);
            self::assertLessThanOrEqual($after + 30, $storedEpoch);
            self::assertSame(1, $this->transport->calls);
        } finally {
            date_default_timezone_set($oldZone);
        }
    }

    public function testPre003TableAndTheSameWorkerAfterUpgradeAreBothSupported(): void
    {
        $this->pdo->exec('ALTER TABLE cd_email_queue DROP INDEX uq_email_queue_enqueue_receipt, DROP COLUMN enqueue_receipt_id');
        $this->legacy('2030-01-01 13:00:00');
        $this->freeze($this->epoch());
        $worker = $this->worker();
        $worker->processBatch();
        self::assertSame(1, $this->transport->calls);
        self::assertSame('2030-01-01 14:00:00', self::scalar($this->pdo, 'SELECT sent_at FROM cd_email_queue'));
        $sql = file_get_contents(dirname(__DIR__, 2) . '/schema/mysql/003_email_queue_receipt_binding.sql');
        self::assertIsString($sql);
        $this->pdo->exec($sql);
        $this->freeze($this->epoch(), '+00:00');
        $this->keyed($this->epoch() + 3600);
        $this->freeze($this->epoch());
        $worker->processBatch();
        self::assertSame(1, $this->transport->calls, 'The worker must rediscover the newly added binding column.');
        $this->freeze($this->epoch() + 3600);
        $worker->processBatch();
        self::assertSame(2, $this->transport->calls);
        self::assertSame('2030-01-01 13:00:00', self::scalar($this->pdo, 'SELECT sent_at FROM cd_email_queue WHERE enqueue_receipt_id IS NOT NULL'));
    }

    /** @return iterable<string, array{string}> */
    public static function metadataFailures(): iterable
    {
        yield 'query returns false' => ['worker_metadata_query_false'];
        yield 'query throws' => ['worker_metadata_query_throw'];
        yield 'column metadata returns false' => ['worker_metadata_false'];
    }

    #[DataProvider('metadataFailures')]
    public function testMetadataFailureCannotMasqueradeAsAnOldSchemaOrHandoff(string $mode): void
    {
        $this->keyed($this->epoch() - 60);
        $pdo = new EmailQueueFaultPdo();
        $pdo->exec('SET timestamp=' . $this->epoch());
        $pdo->exec("SET SESSION time_zone='+02:00'");
        $pdo->mode = $mode;
        try {
            $this->worker($pdo)->processBatch();
            self::fail('Metadata failure must not be interpreted as column absence.');
        } catch (RuntimeException $failure) {
            self::assertSame(1, $pdo->workerMetadataFaults);
            if ($mode === 'worker_metadata_query_throw') {
                self::assertInstanceOf(PDOException::class, $failure);
            }
            self::assertFalse($pdo->inTransaction());
            self::assertSame(1, $pdo->rollbackCalls);
            self::assertSame(0, $this->transport->calls);
            self::assertSame('0', self::scalar($this->pdo, 'SELECT attempts FROM cd_email_queue'));
            self::assertSame('pending', self::scalar($this->pdo, 'SELECT status FROM cd_email_queue'));
        }
        // Positive sensor: the SAME due job and connection really hand off after the metadata fault is removed.
        $pdo->mode = 'none';
        $this->worker($pdo)->processBatch();
        self::assertSame(1, $this->transport->calls);
        self::assertSame('1', self::scalar($this->pdo, 'SELECT attempts FROM cd_email_queue'));
        self::assertSame('2030-01-01 12:00:00', self::scalar($this->pdo, 'SELECT sent_at FROM cd_email_queue'));
    }
}
