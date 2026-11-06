<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Maatify\Crypto\KeyRotation\DTO\CryptoKeyDTO;
use Maatify\Crypto\KeyRotation\KeyStatusEnum;
use Maatify\EmailDelivery\DTO\RenderedEmailDTO;
use Maatify\EmailDelivery\Exception\EmailEnqueueConflictException;
use Maatify\EmailDelivery\Exception\EmailEnqueueUnavailableException;
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO;
use Maatify\EmailDelivery\Queue\Enum\EmailQueueState;
use Maatify\EmailDelivery\Renderer\TwigEmailRenderer;
use Maatify\EmailDelivery\Transport\EmailTransportInterface;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use Psr\Log\NullLogger;
use Tests\Support\EmailQueueTestFactory as Factory;
use Tests\Support\IsolatedEmailMysqlTestCase;

final class IdempotentEmailEnqueueContractMysqlTest extends IsolatedEmailMysqlTestCase
{
    private function scheduled(?DateTimeInterface $time): EmailEnqueueRequestDTO
    {
        $request = Factory::request();
        return new EmailEnqueueRequestDTO(
            $request->consumerNamespace,
            $request->requestKey,
            $request->entityType,
            $request->entityId,
            $request->recipientEmail,
            $request->payload,
            $request->senderType,
            $request->priority,
            $time
        );
    }

    public function testEquivalentExplicitInstantsReplayButImmediateIsDifferent(): void
    {
        $queue = Factory::queue($this->pdo);
        $first = $queue->enqueueOnce($this->scheduled(new DateTimeImmutable('2030-01-01T15:00:00+03:00')));
        $same = $queue->enqueueOnce($this->scheduled(new DateTimeImmutable('2030-01-01T12:00:00Z')));
        self::assertEquals($first, $same);
        self::assertSame('2030-01-01 12:00:00', self::scalar($this->pdo, 'SELECT scheduled_at FROM cd_email_queue'));
        $this->expectException(EmailEnqueueConflictException::class);
        $queue->enqueueOnce($this->scheduled(null));
    }

    public function testImmediateUsesOneUtcSnapshotDespiteProcessTimezoneAndLaterReplay(): void
    {
        $beforeZone = date_default_timezone_get();
        try {
            date_default_timezone_set('Pacific/Auckland');
            $queue = Factory::queue($this->pdo);
            $first = $queue->enqueueOnce(Factory::request());
            $accepted = new DateTimeImmutable($first->acceptedAt);
            self::assertSame($accepted->format('Y-m-d H:i:s'), self::scalar($this->pdo, 'SELECT scheduled_at FROM cd_email_queue'));
            self::assertSame($accepted->format('Y-m-d H:i:s'), self::scalar($this->pdo, 'SELECT created_at FROM cd_email_queue'));
            usleep(1100000);
            $later = $queue->enqueueOnce(Factory::request());
            self::assertEquals($first, $later);
            self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        } finally {
            date_default_timezone_set($beforeZone);
        }
    }

    public function testFractionalExplicitScheduleIsRejectedBeforeWriting(): void
    {
        try {
            Factory::queue($this->pdo)->enqueueOnce($this->scheduled(new DateTimeImmutable('2030-01-01T12:00:00.000001Z')));
            self::fail('Fractional schedules are not silently truncated.');
        } catch (InvalidArgumentException) {
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
        }
    }

    public function testBinaryScopesAndKeysDoNotCollapseCaseOrTrailingSpacesAndReceiptContainsNoPii(): void
    {
        $queue = Factory::queue($this->pdo);
        $ids = [];
        foreach ([['scope', 'key'], ['Scope', 'key'], ['scope ', 'key'], ['scope', 'Key'], ['scope', 'key ']] as [$scope, $key]) {
            $receipt = $queue->enqueueOnce(Factory::request($key, $scope));
            $ids[] = $receipt->receiptId;
            self::assertEquals($receipt, $queue->find($scope, $key));
            self::assertSame(['receipt_id', 'job_id', 'accepted_at', 'queue_state'], array_keys($receipt->jsonSerialize()));
            $json = json_encode($receipt, JSON_THROW_ON_ERROR);
            self::assertStringNotContainsString('fixture@example.invalid', $json);
            self::assertStringNotContainsString('fixture-secret', $json);
        }
        self::assertCount(5, array_unique($ids));
        self::assertSame('5', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertNull($queue->find('SCOPE', 'key'));
    }

    public function testRotationKeepsReplayReadableAndRemovedOldKeyFailsClosedButLookupStillWorks(): void
    {
        $first = Factory::queue($this->pdo)->enqueueOnce(Factory::request());
        $created = new DateTimeImmutable('2026-11-06T00:00:00Z');
        $old = new CryptoKeyDTO('fixture-v1', str_repeat('k', 32), KeyStatusEnum::INACTIVE, $created);
        $active = new CryptoKeyDTO('fixture-v2', str_repeat('z', 32), KeyStatusEnum::ACTIVE, $created);
        $rotated = Factory::queue(self::connection(), Factory::crypto([$old, $active]));
        self::assertEquals($first, $rotated->enqueueOnce(Factory::request()));
        self::assertSame('fixture-v1', self::scalar($this->pdo, 'SELECT fingerprint_key_id FROM cd_email_enqueue_receipts'));
        $next = $rotated->enqueueOnce(Factory::request(key: 'new-key'));
        self::assertNotSame($first->receiptId, $next->receiptId);
        self::assertSame('1', self::scalar($this->pdo, "SELECT COUNT(*) FROM cd_email_enqueue_receipts WHERE fingerprint_key_id='fixture-v2'"));
        $withoutOldKey = Factory::queue(self::connection(), Factory::crypto([$active]));
        self::assertEquals($first, $withoutOldKey->find('fixture-consumer', 'request-1'));
        try {
            $withoutOldKey->enqueueOnce(Factory::request());
            self::fail('Removed read keys must not allow a new job.');
        } catch (EmailEnqueueUnavailableException $failure) {
            self::assertNotNull($failure->getPrevious());
        }
        self::assertSame('2', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
        self::assertSame('2', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
    }

    public function testAKeyedJobRemainsCompatibleWithTheWorkerAndReplayDoesNotQueueAnotherSend(): void
    {
        $queue = Factory::queue($this->pdo);
        $first = $queue->enqueueOnce(Factory::request(context: ['name' => 'Queued']));
        $transport = new class () implements EmailTransportInterface {
            public int $calls = 0;
            public ?string $subject = null;
            public function send(string $recipientEmail, RenderedEmailDTO $email, ?string $replyToEmail = null): void
            {
                ++$this->calls;
                $this->subject = $email->subject;
            }
        };
        $worker = new EmailQueueWorker(
            $this->pdo,
            Factory::crypto(),
            new TwigEmailRenderer(dirname(__DIR__) . '/Fixtures/templates', ['global_app_name' => 'Fixture'], false),
            $transport,
            new Factory(),
            new NullLogger()
        );
        $worker->processBatch();
        self::assertSame(1, $transport->calls);
        self::assertSame('Welcome Queued', $transport->subject);
        $read = $queue->find('fixture-consumer', 'request-1');
        self::assertNotNull($read);
        self::assertSame(EmailQueueState::Sent, $read->queueState);
        self::assertSame($first->receiptId, $read->receiptId);
        $queue->enqueueOnce(Factory::request(context: ['name' => 'Queued']));
        $worker->processBatch();
        self::assertSame(1, $transport->calls);
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
    }

    public function testSnapshotUsedForEncryptionCannotChangeAtTheSqlBoundary(): void
    {
        $name = 'Original';
        $request = Factory::request(context: ['name' => &$name]);
        $pdo = new \Tests\Support\EmailQueueFaultPdo();
        $pdo->beforeExecuteHook = static function (string $sql) use (&$name): void {
            if (str_starts_with($sql, 'INSERT INTO cd_email_enqueue_receipts')) {
                $name = 'Changed';
            }
        };
        $receipt = Factory::queue($pdo)->enqueueOnce($request);
        self::assertSame('Changed', $name, 'The real PDO boundary hook must actually mutate the original reference.');
        $same = Factory::queue(self::connection())->enqueueOnce(Factory::request(context: ['name' => 'Original']));
        self::assertSame($receipt->receiptId, $same->receiptId);
        $statement = $this->pdo->query('SELECT payload_encrypted,payload_iv,payload_tag,payload_key_id FROM cd_email_queue');
        self::assertInstanceOf(\PDOStatement::class, $statement);
        /** @var array{payload_encrypted: string, payload_iv: string, payload_tag: string, payload_key_id: string} $row */
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $plain = Factory::crypto()->context((new Factory())->emailQueuePayload())->decrypt(
            $row['payload_encrypted'],
            $row['payload_key_id'],
            \Maatify\Crypto\Reversible\ReversibleCryptoAlgorithmEnum::AES_256_GCM,
            new \Maatify\Crypto\Reversible\DTO\ReversibleCryptoMetadataDTO($row['payload_iv'], $row['payload_tag']),
        );
        self::assertStringContainsString('Original', $plain);
        self::assertStringNotContainsString('Changed', $plain);
        // The digest is encrypted too, not a plaintext verifier for the context's short secret.
        $stored = self::scalar($this->pdo, 'SELECT fingerprint_encrypted FROM cd_email_enqueue_receipts');
        $digest = \Maatify\EmailDelivery\Queue\Internal\EmailEnqueueCanonicalizer::snapshot(
            Factory::request(context: ['name' => 'Original']),
            new DateTimeImmutable('2026-11-06T12:00:00Z'),
        )->digest;
        self::assertNotSame($digest, $stored);
        self::assertNotSame(bin2hex($digest), $stored);
    }

    public function testMissingBindingUniquenessIsRejectedBeforeWriting(): void
    {
        $this->pdo->exec('ALTER TABLE cd_email_queue DROP INDEX uq_email_queue_enqueue_receipt');
        try {
            Factory::queue($this->pdo)->enqueueOnce(Factory::request());
            self::fail('A binding without full uniqueness must be refused.');
        } catch (EmailEnqueueUnavailableException) {
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
            self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
        }
    }

    public function testTruncatedReceiptJobLinkCannotReturnAcceptance(): void
    {
        $originalMode = self::scalar($this->pdo, 'SELECT @@SESSION.sql_mode');
        try {
            $this->pdo->exec('ALTER TABLE cd_email_enqueue_receipts MODIFY job_id INT UNSIGNED NULL');
            $this->pdo->exec('ALTER TABLE cd_email_queue AUTO_INCREMENT=4294967296');
            $this->pdo->exec("SET SESSION sql_mode = ''");
            try {
                $receipt = Factory::queue($this->pdo)->enqueueOnce(Factory::request());
                self::fail('A truncated link returned job ' . $receipt->jobId . ' while queue job is ' . self::scalar($this->pdo, 'SELECT id FROM cd_email_queue'));
            } catch (EmailEnqueueUnavailableException) {
                self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
                self::assertSame('0', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_enqueue_receipts'));
                self::assertFalse($this->pdo->inTransaction());
            }
        } finally {
            $quoted = $this->pdo->quote($originalMode);
            self::assertIsString($quoted);
            $this->pdo->exec('SET SESSION sql_mode = ' . $quoted);
        }
    }

    public function testAnInvalidCommittedJobIdentityFailsClosedInsteadOfReturningAcceptance(): void
    {
        Factory::queue($this->pdo)->enqueueOnce(Factory::request());
        $this->pdo->exec('UPDATE cd_email_enqueue_receipts SET job_id=NULL');
        try {
            Factory::queue($this->pdo)->find('fixture-consumer', 'request-1');
            self::fail('A malformed committed receipt cannot be success.');
        } catch (EmailEnqueueUnavailableException) {
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('1', self::scalar($this->pdo, 'SELECT COUNT(*) FROM cd_email_queue'));
    }
}
