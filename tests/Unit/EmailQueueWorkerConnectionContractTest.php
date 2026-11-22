<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Maatify\EmailDelivery\Renderer\TwigEmailRenderer;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use PDO;
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

    /** @param PDO|\Closure(): PDO $connection */
    private function worker(PDO|\Closure $connection, ?int $leaseSeconds = null): EmailQueueWorker
    {
        $renderer = new TwigEmailRenderer(dirname(__DIR__) . '/Fixtures/templates', [], false);
        $arguments = [$connection, Factory::crypto(), $renderer, new WorkerRecordingTransport(), new Factory(), new NullLogger()];
        return $leaseSeconds === null ? new EmailQueueWorker(...$arguments) : new EmailQueueWorker(...[...$arguments, $leaseSeconds]);
    }
}
