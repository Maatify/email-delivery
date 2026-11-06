<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Maatify\EmailDelivery\Exception\EmailEnqueueConflictException;
use Maatify\EmailDelivery\Exception\EmailEnqueueUnavailableException;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\Enum\EmailQueueState;
use Maatify\EmailDelivery\Queue\PdoEmailQueueWriter;
use Tests\Support\EmailQueueTestFactory as Factory;
use Tests\Support\IsolatedEmailMysqlTestCase;

final class IdempotentEmailEnqueueMysqlTest extends IsolatedEmailMysqlTestCase
{
    public function testARepeatedKeyFromANewConnectionReturnsTheCommittedReceiptAndOneQueueJob(): void
    {
        $request = Factory::request();
        $first = Factory::queue($this->pdo)->enqueueOnce($request);
        $other = self::connection();
        self::assertSame(1, (int) self::scalar($other, 'SELECT COUNT(*) FROM cd_email_queue'));
        $second = Factory::queue($other)->enqueueOnce($request);
        self::assertEquals($first, $second);
        self::assertSame(1, (int) self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
        self::assertSame(1, (int) self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame(EmailQueueState::Pending, $first->queueState);
        self::assertArrayNotHasKey('recipient', $first->jsonSerialize());
        self::assertArrayNotHasKey('context', $first->jsonSerialize());
        self::assertNull(Factory::queue($other)->find('other-consumer', $request->requestKey));
        self::assertEquals($first, Factory::queue($other)->find($request->consumerNamespace, $request->requestKey));
    }

    public function testDifferentPayloadConflictsAndNamespacesRemainIndependent(): void
    {
        $first = Factory::queue($this->pdo)->enqueueOnce(Factory::request());
        try {
            Factory::queue($this->pdo)->enqueueOnce(Factory::request(context: ['code' => 'different']));
            self::fail('Different content must conflict.');
        } catch (EmailEnqueueConflictException) {
            self::assertFalse($this->pdo->inTransaction());
        }
        $other = Factory::queue($this->pdo)->enqueueOnce(Factory::request(namespace: 'other-consumer'));
        self::assertNotSame($first->receiptId, $other->receiptId);
        self::assertSame(2, (int) self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
    }

    public function testQueuePurgeDoesNotReviveTheJobOrClaimItWasSent(): void
    {
        $request = Factory::request();
        $queue = Factory::queue($this->pdo);
        $first = $queue->enqueueOnce($request);
        $this->pdo->exec('DELETE FROM cd_email_queue');
        $again = $queue->enqueueOnce($request);
        self::assertSame($first->receiptId, $again->receiptId);
        self::assertSame($first->jobId, $again->jobId);
        self::assertSame(EmailQueueState::QueueMissing, $again->queueState);
        self::assertEquals($again, $queue->find($request->consumerNamespace, $request->requestKey));
        self::assertSame(0, (int) self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
    }

    public function testNonTransactionalQueueIsRejectedBeforeAnyWrite(): void
    {
        $this->pdo->exec('ALTER TABLE cd_email_queue ENGINE=MyISAM');
        $this->expectException(EmailEnqueueUnavailableException::class);
        try {
            Factory::queue($this->pdo)->enqueueOnce(Factory::request());
        } finally {
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
            self::assertFalse($this->pdo->inTransaction());
        }
    }

    public function testMissingIdentityUniquenessIsRejectedBeforeAnyWrite(): void
    {
        $this->pdo->exec('ALTER TABLE cd_email_enqueue_receipts DROP PRIMARY KEY');
        $this->expectException(EmailEnqueueUnavailableException::class);
        try {
            Factory::queue($this->pdo)->enqueueOnce(Factory::request());
        } finally {
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
            self::assertFalse($this->pdo->inTransaction());
        }
    }

    public function testAPurgedJobIdReusedByLegacyQueueCannotClaimTheOldReceiptWasSent(): void
    {
        $queue = Factory::queue($this->pdo);
        $old = $queue->enqueueOnce(Factory::request());
        $this->pdo->exec('TRUNCATE TABLE cd_email_queue');
        (new PdoEmailQueueWriter($this->pdo, Factory::crypto(), new Factory()))->enqueue(
            'user',
            'unrelated',
            'other@example.invalid',
            new EmailQueuePayloadDTO([], 'welcome', 'en'),
            1,
        );
        $this->pdo->exec("UPDATE cd_email_queue SET status = 'sent'");
        self::assertSame($old->jobId, self::scalar($this->pdo, 'SELECT id FROM cd_email_queue'));
        $read = $queue->find('fixture-consumer', 'request-1');
        self::assertNotNull($read);
        self::assertSame(EmailQueueState::QueueMissing, $read->queueState, 'A reused numeric ID is not the accepted job.');
    }

    public function testAReusedIdForANewKeyRetainsTheOldReceiptAsMissing(): void
    {
        $queue = Factory::queue($this->pdo);
        $old = $queue->enqueueOnce(Factory::request());
        $this->pdo->exec('TRUNCATE TABLE cd_email_queue');
        $new = $queue->enqueueOnce(Factory::request(key: 'new-key'));
        self::assertSame($old->jobId, $new->jobId);
        self::assertNotSame($old->receiptId, $new->receiptId);
        $this->pdo->exec("UPDATE cd_email_queue SET status = 'sent'");
        $oldRead = $queue->find('fixture-consumer', 'request-1');
        $newRead = $queue->find('fixture-consumer', 'new-key');
        self::assertNotNull($oldRead);
        self::assertNotNull($newRead);
        self::assertSame(EmailQueueState::QueueMissing, $oldRead->queueState);
        self::assertSame(EmailQueueState::Sent, $newRead->queueState);
        self::assertSame($old->receiptId, $queue->enqueueOnce(Factory::request())->receiptId);
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
    }

    public function testPackageUpgradeAddsBindingWithoutTouchingLegacyRows(): void
    {
        $this->pdo->exec('ALTER TABLE cd_email_queue DROP INDEX uq_email_queue_enqueue_receipt, DROP COLUMN enqueue_receipt_id');
        (new PdoEmailQueueWriter($this->pdo, Factory::crypto(), new Factory()))->enqueue(
            'user',
            'before-upgrade',
            'fixture@example.invalid',
            new EmailQueuePayloadDTO([], 'welcome', 'en'),
            1,
        );
        $upgrade = file_get_contents(dirname(__DIR__, 2) . '/schema/mysql/003_email_queue_receipt_binding.sql');
        self::assertIsString($upgrade);
        $this->pdo->exec($upgrade);
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue WHERE enqueue_receipt_id IS NULL'));
        Factory::queue($this->pdo)->enqueueOnce(Factory::request());
        self::assertSame('2', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
    }

    public function testCallerTransactionIsUntouchedAndLegacyEnqueueStillCreatesTwoJobs(): void
    {
        $writer = new PdoEmailQueueWriter($this->pdo, Factory::crypto(), new Factory());
        $payload = new EmailQueuePayloadDTO([], 'welcome', 'en');
        $scheduled = new DateTimeImmutable('2026-11-06T12:00:00Z');
        $this->pdo->beginTransaction();
        $writer->enqueue('user', '1', 'fixture@example.invalid', $payload, 1, scheduledAt: $scheduled);
        try {
            Factory::queue($this->pdo)->enqueueOnce(Factory::request());
            self::fail('A caller transaction must be refused.');
        } catch (EmailEnqueueUnavailableException) {
            self::assertTrue($this->pdo->inTransaction());
            self::assertSame(0, (int) self::scalar(self::connection(), 'SELECT COUNT(*) FROM cd_email_queue'));
        }
        $this->pdo->rollBack();
        $writer->enqueue('user', '1', 'fixture@example.invalid', $payload, 1, scheduledAt: $scheduled);
        $writer->enqueue('user', '1', 'fixture@example.invalid', $payload, 1, scheduledAt: $scheduled);
        self::assertSame(2, (int) self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('2', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue WHERE enqueue_receipt_id IS NULL'));
        self::assertSame(0, (int) self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }
}
