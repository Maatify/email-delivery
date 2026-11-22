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
use Tests\Support\WorkerRecordingTransport;
use UnexpectedValueException;

final class EmailQueueWorkerConnectionContractTest extends TestCase
{
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

    public function testADriverDisconnectWarningIsDroppedWhenTheLostConnectionIsReported(): void
    {
        $outer = $this->outerWarnings(function (): void {
            try {
                self::onPossiblyClosedConnection(static function (): never {
                    self::raiseWarning('Packets out of order');
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
    }

    public function testADriverDisconnectWarningWithoutALostConnectionIsRaisedAgain(): void
    {
        $outer = $this->outerWarnings(static function (): void {
            self::assertSame('done', self::onPossiblyClosedConnection(static function (): string {
                self::raiseWarning('Packets out of order');
                return 'done';
            }));
        });

        self::assertCount(1, $outer);
        self::assertSame(E_USER_WARNING, $outer[0][0]);
        self::assertStringContainsString('Packets out of order', $outer[0][1]);
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

    /** @param \Closure(): mixed $operation */
    private static function onPossiblyClosedConnection(\Closure $operation): mixed
    {
        return (new \ReflectionMethod(EmailQueueWorker::class, 'onPossiblyClosedConnection'))->invoke(null, $operation);
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
