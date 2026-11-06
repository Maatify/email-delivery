<?php

declare(strict_types=1);

namespace Tests\Integration;

use Maatify\EmailDelivery\Exception\EmailEnqueueTransactionUnresolvedException;
use Maatify\EmailDelivery\Exception\EmailEnqueueUnavailableException;
use Maatify\EmailDelivery\Queue\PdoIdempotentEmailQueue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EmailQueueFaultPdo;
use Tests\Support\EmailQueueTestFactory as Factory;
use Tests\Support\IsolatedEmailMysqlTestCase;

final class IdempotentEmailEnqueueFaultMysqlTest extends IsolatedEmailMysqlTestCase
{
    /** @return iterable<string, array{string}> */
    public static function statementFailures(): iterable
    {
        foreach (['receipt', 'queue', 'link'] as $stage) {
            foreach (['false', 'throw'] as $result) {
                yield $stage . '-' . $result => [$stage . '_' . $result];
            }
        }
    }

    #[DataProvider('statementFailures')]
    public function testFailedAtomicWriteLeavesNeitherQueueNorReceiptAndFreshRetrySucceeds(string $mode): void
    {
        $pdo = new EmailQueueFaultPdo();
        $pdo->mode = $mode;
        try {
            Factory::queue($pdo)->enqueueOnce(Factory::request());
            self::fail('A failed statement cannot confirm acceptance.');
        } catch (EmailEnqueueUnavailableException $failure) {
            self::assertNotInstanceOf(EmailEnqueueTransactionUnresolvedException::class, $failure);
            self::assertSame(1, $pdo->executeFaults);
            self::assertFalse($pdo->inTransaction());
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
        }
        Factory::queue(self::connection())->enqueueOnce(Factory::request());
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function commitFailures(): iterable
    {
        yield 'false before commit' => ['commit_false_before', false];
        yield 'throw before commit' => ['commit_throw_before', false];
        yield 'false after actual commit' => ['commit_false_after', true];
        yield 'throw after actual commit' => ['commit_throw_after', true];
        yield 'true but still active' => ['commit_true_active', false];
    }

    #[DataProvider('commitFailures')]
    public function testCommitFaultNeverReturnsReceiptAndSameKeyRecoversOnANewConnection(string $mode, bool $committed): void
    {
        $pdo = new EmailQueueFaultPdo();
        $pdo->mode = $mode;
        try {
            Factory::queue($pdo)->enqueueOnce(Factory::request());
            self::fail('An uncertain commit cannot return acceptance.');
        } catch (EmailEnqueueTransactionUnresolvedException $failure) {
            self::assertSame('commit', $failure->phase);
            self::assertSame(1, $pdo->commitCalls);
            self::assertFalse($pdo->inTransaction());
        }
        self::assertSame($committed ? '1' : '0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame($committed ? '1' : '0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
        $fresh = Factory::queue(self::connection());
        $before = $fresh->find('fixture-consumer', 'request-1');
        $recovered = $fresh->enqueueOnce(Factory::request());
        if ($committed) {
            self::assertNotNull($before);
            self::assertSame($before->receiptId, $recovered->receiptId);
        } else {
            self::assertNull($before);
        }
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function beginFailures(): iterable
    {
        yield 'false inactive' => ['begin_false', false];
        yield 'throw inactive' => ['begin_throw', false];
        yield 'false but active' => ['begin_false_active', true];
        yield 'throw but active' => ['begin_throw_active', true];
    }

    #[DataProvider('beginFailures')]
    public function testBeginFaultDoesNotClaimOwnershipOfAnUnprovedTransaction(string $mode, bool $active): void
    {
        $pdo = new EmailQueueFaultPdo();
        $pdo->mode = $mode;
        try {
            Factory::queue($pdo)->enqueueOnce(Factory::request());
            self::fail('A failed begin must not be accepted.');
        } catch (EmailEnqueueUnavailableException $failure) {
            self::assertSame($active, $failure instanceof EmailEnqueueTransactionUnresolvedException);
            self::assertSame($active, $pdo->inTransaction());
            self::assertSame(0, $pdo->commitCalls);
            self::assertSame(0, $pdo->rollbackCalls);
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
        } finally {
            // Fixture-only cleanup AFTER asserting that the library left the unproved transaction untouched.
            if ($pdo->inTransaction()) {
                $pdo->mode = 'none';
                $pdo->rollBack();
            }
        }
    }

    public function testFalseQueueExecuteCannotReuseAPreviousLastInsertId(): void
    {
        $pdo = new EmailQueueFaultPdo();
        (new \Maatify\EmailDelivery\Queue\PdoEmailQueueWriter($pdo, Factory::crypto(), new Factory()))->enqueue(
            'user',
            'legacy',
            'fixture@example.invalid',
            new \Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO([], 'welcome', 'en'),
            1,
        );
        self::assertSame('1', $pdo->lastInsertId());
        $pdo->mode = 'queue_false';
        try {
            Factory::queue($pdo)->enqueueOnce(Factory::request());
            self::fail('False execute cannot reuse a stale lastInsertId.');
        } catch (EmailEnqueueUnavailableException) {
            self::assertSame(1, $pdo->executeFaults);
            self::assertFalse($pdo->inTransaction());
        }
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }

    public function testFailedRollbackIsUnresolvedAndNoOtherConnectionSeesAcceptance(): void
    {
        $pdo = new EmailQueueFaultPdo();
        $pdo->mode = 'queue_throw';
        $pdo->failRollback = true;
        try {
            Factory::queue($pdo)->enqueueOnce(Factory::request());
            self::fail('Failed cleanup must be fatal for this connection.');
        } catch (EmailEnqueueTransactionUnresolvedException $failure) {
            self::assertSame('rollback', $failure->phase);
            self::assertNotNull($failure->getPrevious());
            self::assertTrue($pdo->inTransaction());
            self::assertSame(1, $pdo->executeFaults);
            self::assertSame(1, $pdo->rollbackCalls);
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
        } finally {
            $pdo->failRollback = false;
            $pdo->mode = 'none';
            $pdo->rollBack();
        }
        Factory::queue(self::connection())->enqueueOnce(Factory::request());
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
    }

    /** @return iterable<string, array{string}> */
    public static function preflightFailures(): iterable
    {
        foreach (['attribute_throw', 'settings_false', 'settings_throw'] as $mode) {
            yield $mode => [$mode];
        }
    }

    #[DataProvider('preflightFailures')]
    public function testPreflightErrorsAreTypedAndDoNotBeginATransaction(string $mode): void
    {
        $pdo = new EmailQueueFaultPdo();
        $pdo->mode = $mode;
        try {
            Factory::queue($pdo)->enqueueOnce(Factory::request());
            self::fail('Preflight must fail closed.');
        } catch (EmailEnqueueUnavailableException $failure) {
            self::assertSame(0, $pdo->beginCalls);
            self::assertSame(0, $pdo->rollbackCalls);
            self::assertFalse($pdo->inTransaction());
            if ($mode !== 'settings_false') {
                self::assertNotNull($failure->getPrevious());
            }
        }
    }

    public function testCryptoFailureIsTypedBeforeAnyTransaction(): void
    {
        $pdo = new EmailQueueFaultPdo();
        $queue = new PdoIdempotentEmailQueue($pdo, Factory::crypto(), new Factory(), 'invalid-context-without-version');
        try {
            $queue->enqueueOnce(Factory::request());
            self::fail('An invalid crypto context cannot enqueue.');
        } catch (EmailEnqueueUnavailableException $failure) {
            self::assertNotNull($failure->getPrevious());
            self::assertSame(0, $pdo->beginCalls);
        }
    }

    public function testAutocommitOffAndCallerTransactionAreRejectedWithoutCommitOrRollback(): void
    {
        $pdo = new EmailQueueFaultPdo();
        $queue = Factory::queue($pdo);
        $pdo->exec('SET SESSION autocommit = 0');
        try {
            $queue->enqueueOnce(Factory::request());
            self::fail('Autocommit disabled must be refused.');
        } catch (EmailEnqueueUnavailableException) {
            self::assertSame(0, $pdo->beginCalls);
            self::assertSame(0, $pdo->commitCalls);
            self::assertSame(0, $pdo->rollbackCalls);
        } finally {
            $pdo->exec('SET SESSION autocommit = 1');
        }
        $pdo->beginTransaction();
        try {
            $queue->find('fixture-consumer', 'request-1');
            self::fail('Reader cannot consume a caller transaction.');
        } catch (EmailEnqueueUnavailableException) {
            self::assertTrue($pdo->inTransaction());
            self::assertSame(0, $pdo->commitCalls);
            self::assertSame(0, $pdo->rollbackCalls);
        } finally {
            $pdo->rollBack();
        }
    }
}
