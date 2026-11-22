<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Maatify\EmailDelivery\Renderer\TwigEmailRenderer;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Helpers\SqliteTestPdo;
use Tests\Support\EmailQueueTestFactory as Factory;
use Tests\Support\WorkerRecordingLogger;
use Tests\Support\WorkerRecordingTransport;
use UnexpectedValueException;

final class EmailQueueWorkerConnectionContractTest extends TestCase
{
    private const DRIVER_WARNING = 'Database driver warning during an email queue statement';
    private static WorkerRecordingLogger $driverLog;

    protected function setUp(): void
    {
        self::$driverLog = new WorkerRecordingLogger();
    }

    /** @return iterable<string, array{int}> */
    public static function invalidLeases(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-1];
    }

    #[DataProvider('invalidLeases')]
    public function testTheProcessingLeaseMustBeAtLeastOneSecond(int $seconds): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->worker(new PDO('sqlite::memory:'), $seconds);
    }

    public function testAOneSecondLeaseIsAccepted(): void
    {
        $this->worker(new PDO('sqlite::memory:'), 1);
        $this->addToAssertionCount(1);
    }

    public function testTheFactoryIsNotCalledUntilTheFirstBatch(): void
    {
        $calls = 0;
        $worker = $this->worker(static function () use (&$calls): PDO {
            ++$calls;
            $pdo = new SqliteTestPdo('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $schema = file_get_contents(dirname(__DIR__) . '/Fixtures/schema.sql');
            self::assertIsString($schema);
            $pdo->exec($schema);
            return $pdo;
        });
        self::assertSame(0, $calls);

        $worker->processBatch();
        $worker->processBatch();

        self::assertSame(1, $calls, 'A live connection is reused between batches.');
    }

    public function testAFactoryMustReturnAPdo(): void
    {
        /** @phpstan-ignore argument.type (the runtime contract is what is under test) */
        $worker = $this->worker(static fn (): string => 'not a connection');

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('must return a PDO instance');
        $worker->processBatch();
    }

    public function testAFactoryMustReturnAnExceptionModeConnection(): void
    {
        $worker = $this->worker(static function (): PDO {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
            return $pdo;
        });

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('exception error mode');
        $worker->processBatch();
    }

    /** @return iterable<string, array{string}> mysqlnd warnings that accompany a closed connection */
    public static function disconnectSymptoms(): iterable
    {
        yield 'out-of-order packet' => ['Packets out of order. Expected 1 received 0'];
        yield 'gone away' => ['MySQL server has gone away'];
        yield 'send failure' => ['Error while sending QUERY packet'];
        yield 'read failure' => ['Error while reading greeting packet'];
        yield 'result header' => ["Error reading result set's header"];
    }

    #[DataProvider('disconnectSymptoms')]
    public function testADriverDisconnectWarningIsDroppedWhenTheLostConnectionIsReported(string $symptom): void
    {
        $outer = $this->outerWarnings(function () use ($symptom): void {
            try {
                self::onPossiblyClosedConnection(static function () use ($symptom): never {
                    self::raiseWarning($symptom);
                    $lost = new PDOException('Fixture lost connection');
                    $lost->errorInfo = ['HY000', 4031, 'Fixture'];
                    throw $lost;
                });
                self::fail('The lost connection must propagate.');
            } catch (PDOException $failure) {
                self::assertSame('Fixture lost connection', $failure->getMessage());
            }
        });

        self::assertSame([], $outer, 'The application handler never sees the disconnect symptom.');
        self::assertSame([], self::$driverLog->contexts('warning', self::DRIVER_WARNING), 'A confirmed loss is logged by the worker itself.');
    }

    public function testADriverDisconnectWarningOnASuccessfulStatementIsLoggedAndTheResultReturned(): void
    {
        $result = self::underThrowingHandler(static fn (): mixed => self::onPossiblyClosedConnection(static function (): string {
            self::raiseWarning('Packets out of order');
            return 'done';
        }));

        self::assertSame('done', $result, 'An application handler that throws on warnings never sees the held warning.');
        $logged = self::$driverLog->contexts('warning', self::DRIVER_WARNING);
        self::assertCount(1, $logged);
        self::assertIsString($logged[0]['warning']);
        self::assertStringContainsString('Packets out of order', $logged[0]['warning']);
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function nonConnectionFailures(): iterable
    {
        $lockWait = new PDOException('Fixture lock wait');
        $lockWait->errorInfo = ['HY000', 1205, 'Fixture'];
        yield 'database error that is not a lost connection' => [$lockWait];
        yield 'any other error' => [new \RuntimeException('Fixture failure')];
    }

    #[DataProvider('nonConnectionFailures')]
    public function testADriverDisconnectWarningBeforeAnotherErrorKeepsTheOriginalException(\Throwable $original): void
    {
        try {
            self::underThrowingHandler(static fn (): mixed => self::onPossiblyClosedConnection(static function () use ($original): never {
                self::raiseWarning('Packets out of order');
                throw $original;
            }));
            self::fail('The original error must propagate.');
        } catch (\Throwable $caught) {
            self::assertSame($original, $caught, 'The caller receives the original exception, not an ErrorException.');
        }
        $logged = self::$driverLog->contexts('warning', self::DRIVER_WARNING);
        self::assertCount(1, $logged);
        self::assertIsString($logged[0]['warning']);
        self::assertStringContainsString('Packets out of order', $logged[0]['warning']);
    }

    public function testAnUnrelatedWarningReachesTheApplicationHandlerUnchanged(): void
    {
        $outer = $this->outerWarnings(static function (): void {
            self::onPossiblyClosedConnection(static function (): void {
                self::raiseWarning('unrelated-fixture');
            });
        });

        self::assertCount(1, $outer);
        self::assertSame(E_WARNING, $outer[0][0]);
        self::assertStringContainsString('unrelated-fixture', $outer[0][1]);
    }

    public function testAnApplicationHandlerThatReturnsNothingHandlesAnUnrelatedWarningOnlyOnce(): void
    {
        $received = [];
        error_clear_last();
        // A handler that returns nothing is common in applications; PHP treats only an explicit false as unhandled.
        /** @phpstan-ignore argument.type */
        set_error_handler(static function (int $level, string $message) use (&$received): void {
            $received[] = [$level, $message];
        });
        try {
            self::onPossiblyClosedConnection(static function (): void {
                self::raiseWarning('unrelated-fixture');
            });
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $received);
        self::assertSame(E_WARNING, $received[0][0]);
        self::assertNull(error_get_last(), "PHP's standard handler must not report a warning the application handled.");
    }

    public function testOtherErrorLevelsRaisedDuringTheStatementsReachTheApplicationHandler(): void
    {
        $outer = $this->outerWarnings(static function (): void {
            self::onPossiblyClosedConnection(static function (): void {
                trigger_error('fixture notice', E_USER_NOTICE);
            });
        });

        self::assertSame([[E_USER_NOTICE, 'fixture notice']], $outer);
    }

    public function testOnlyAnEngineWarningIsHeldBack(): void
    {
        $outer = $this->outerWarnings(function (): void {
            try {
                self::onPossiblyClosedConnection(static function (): never {
                    // Same wording as a driver symptom, but raised by application code: never held or dropped.
                    trigger_error('fixture: Packets out of order', E_USER_WARNING);
                    $lost = new PDOException('Fixture lost connection');
                    $lost->errorInfo = ['HY000', 2006, 'Fixture'];
                    throw $lost;
                });
            } catch (PDOException) {
            }
        });

        self::assertSame([[E_USER_WARNING, 'fixture: Packets out of order']], $outer);
    }

    /** @return iterable<string, array{string, string}> */
    public static function reportedServerVersions(): iterable
    {
        $offset = "CONCAT(UTC_TIMESTAMP(), '+00:00')";
        // MariaDB rejects a value with an offset (1292) under strict SQL mode and drops the offset without it,
        // so every MariaDB version string must select a directly assigned NOW().
        yield 'MariaDB 11.4' => ['11.4.13-MariaDB-ubu2404', 'NOW()'];
        yield 'MariaDB 10.11' => ['10.11.6-MariaDB-0+deb12u1', 'NOW()'];
        yield 'MariaDB with the replication prefix' => ['5.5.5-10.6.16-MariaDB', 'NOW()'];
        yield 'MariaDB in lower case' => ['11.4.13-mariadb', 'NOW()'];
        yield 'MySQL 8.0.46' => ['8.0.46', $offset];
        yield 'MySQL 8.0.19, the first with offsets' => ['8.0.19', $offset];
        yield 'MySQL 8.0.18' => ['8.0.18', 'NOW()'];
        yield 'MySQL 8.4 LTS' => ['8.4.3', $offset];
        yield 'MySQL 9' => ['9.1.0', $offset];
        yield 'MySQL-compatible build suffix' => ['8.0.36-28', $offset];
        yield 'MySQL 5.7 with a suffix' => ['5.7.44-log', 'NOW()'];
        yield 'no version reported' => ['', 'NOW()'];
    }

    /**
     * The TIMESTAMP lease stamp is chosen from the server version the connection reports. A proxy that reports
     * a MySQL 8.0.19+ version in front of MariaDB would select the offset form, which MariaDB cannot store.
     */
    #[DataProvider('reportedServerVersions')]
    public function testTheTimestampLeaseStampIsChosenFromTheReportedServerVersion(string $serverVersion, string $stamp): void
    {
        $pdo = new class ($serverVersion) extends PDO {
            public function __construct(private readonly string $serverVersion)
            {
                parent::__construct('sqlite::memory:');
            }

            public function getAttribute(int $attribute): mixed
            {
                return $attribute === PDO::ATTR_SERVER_VERSION ? $this->serverVersion : parent::getAttribute($attribute);
            }
        };

        self::assertSame($stamp, (new \ReflectionMethod(EmailQueueWorker::class, 'instantStamp'))->invoke(null, $pdo));
    }

    /** @param \Closure(): mixed $operation */
    private static function onPossiblyClosedConnection(\Closure $operation): mixed
    {
        $worker = new EmailQueueWorker(
            new PDO('sqlite::memory:'),
            Factory::crypto(),
            new TwigEmailRenderer(dirname(__DIR__) . '/Fixtures/templates', [], false),
            new WorkerRecordingTransport(),
            new Factory(),
            self::$driverLog,
        );
        return (new \ReflectionMethod(EmailQueueWorker::class, 'onPossiblyClosedConnection'))->invoke($worker, $operation);
    }

    /**
     * Runs $scenario under an application handler that turns every warning into an exception.
     *
     * @param \Closure(): mixed $scenario
     */
    private static function underThrowingHandler(\Closure $scenario): mixed
    {
        set_error_handler(static function (int $level, string $message, string $file, int $line): never {
            throw new \ErrorException($message, 0, $level, $file, $line);
        });
        try {
            return $scenario();
        } finally {
            restore_error_handler();
        }
    }

    /** A real engine E_WARNING whose text contains $marker. */
    private static function raiseWarning(string $marker): void
    {
        file_get_contents(sys_get_temp_dir() . '/email-delivery-missing-' . bin2hex(random_bytes(4)) . '/' . $marker);
    }

    /**
     * @param \Closure(): void $scenario
     * @return list<array{int, string}> warnings the application's own handler received
     */
    private function outerWarnings(\Closure $scenario): array
    {
        $received = [];
        set_error_handler(static function (int $level, string $message) use (&$received): bool {
            $received[] = [$level, $message];
            return true;
        });
        try {
            $scenario();
        } finally {
            restore_error_handler();
        }
        return $received;
    }

    /** @param PDO|\Closure(): PDO $connection */
    private function worker(PDO|\Closure $connection, ?int $leaseSeconds = null): EmailQueueWorker
    {
        $renderer = new TwigEmailRenderer(dirname(__DIR__) . '/Fixtures/templates', [], false);
        $arguments = [$connection, Factory::crypto(), $renderer, new WorkerRecordingTransport(), new Factory(), new NullLogger()];
        return $leaseSeconds === null ? new EmailQueueWorker(...$arguments) : new EmailQueueWorker(...[...$arguments, $leaseSeconds]);
    }
}
