<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Maatify\Crypto\Contract\CryptoContextProviderInterface;
use Maatify\Crypto\DX\CryptoContextFactory;
use Maatify\Crypto\DX\CryptoDirectFactory;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\Crypto\KeyRotation\DTO\CryptoKeyDTO;
use Maatify\Crypto\KeyRotation\KeyRotationService;
use Maatify\Crypto\KeyRotation\KeyStatusEnum;
use Maatify\Crypto\KeyRotation\Policy\StrictSingleActiveKeyPolicy;
use Maatify\Crypto\KeyRotation\Providers\InMemoryKeyProvider;
use Maatify\Crypto\Reversible\Algorithms\Aes256GcmAlgorithm;
use Maatify\Crypto\HKDF\HKDFService;
use Maatify\Crypto\Reversible\Registry\ReversibleCryptoAlgorithmRegistry;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\PdoEmailQueueWriter;
use Maatify\EmailDelivery\Renderer\TwigEmailRenderer;
use Maatify\EmailDelivery\Transport\EmailTransportInterface;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tests\Helpers\SqliteTestPdo;
use PDO;

class EmailQueueWorkerIntegrationTest extends TestCase
{
    private SqliteTestPdo $pdo;
    private CryptoProvider $cryptoProvider;
    private CryptoContextProviderInterface&MockObject $cryptoContextProvider;
    private EmailTransportInterface&MockObject $transport;
    private LoggerInterface&MockObject $logger;
    private TwigEmailRenderer $renderer;
    private PdoEmailQueueWriter $writer;
    private EmailQueueWorker $worker;

    protected function setUp(): void
    {
        $this->pdo = new SqliteTestPdo('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = file_get_contents(__DIR__ . '/../Fixtures/schema.sql');
        if (!is_string($schema)) { throw new \RuntimeException('Schema file not found'); }
        $this->pdo->exec($schema);

        $registry = new ReversibleCryptoAlgorithmRegistry();
        $registry->register(new Aes256GcmAlgorithm());
        $keyMaterial = str_repeat('k', 32);
        $key = new CryptoKeyDTO('test_key', $keyMaterial, KeyStatusEnum::ACTIVE, new DateTimeImmutable());
        $keyProvider = new InMemoryKeyProvider([$key]);
        $rotationService = new KeyRotationService($keyProvider, new StrictSingleActiveKeyPolicy());
        $hkdfService = new HKDFService();
        $contextFactory = new CryptoContextFactory($rotationService, $hkdfService, $registry);
        $directFactory = new CryptoDirectFactory($rotationService, $registry);
        $this->cryptoProvider = new CryptoProvider($contextFactory, $directFactory);

        $this->cryptoContextProvider = $this->createMock(CryptoContextProviderInterface::class);
        $this->cryptoContextProvider->method('emailQueueRecipient')->willReturn('recipient_context:v1');
        $this->cryptoContextProvider->method('emailQueuePayload')->willReturn('payload_context:v1');

        $fixturesPath = realpath(__DIR__ . '/../Fixtures/templates');
        if (!is_string($fixturesPath)) { throw new \RuntimeException('Templates not found'); }
        $this->renderer = new TwigEmailRenderer($fixturesPath, ['global_app_name' => 'TestApp'], false);

        $this->transport = $this->createMock(EmailTransportInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->writer = new PdoEmailQueueWriter($this->pdo, $this->cryptoProvider, $this->cryptoContextProvider);
        $this->worker = new EmailQueueWorker(
            $this->pdo,
            $this->cryptoProvider,
            $this->renderer,
            $this->transport,
            $this->cryptoContextProvider,
            $this->logger
        );
    }

    public function testWorkerProcessesSuccessfulEmail(): void
    {
        $payload = new EmailQueuePayloadDTO(['name' => 'Alice'], 'welcome', 'en');
        $this->writer->enqueue('user', '1', 'alice@example.com', $payload, 1);

        $stmt = $this->pdo->query("SELECT * FROM cd_email_queue WHERE status = 'pending'");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $this->assertCount(1, $stmt->fetchAll(PDO::FETCH_ASSOC));

        $this->transport->expects($this->once())
            ->method('send')
            ->with('alice@example.com', $this->callback(function ($renderedEmail) {
                return $renderedEmail->subject === 'Welcome Alice' &&
                       str_contains($renderedEmail->htmlBody, 'Welcome to TestApp');
            }));

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Email sent', $this->arrayHasKey('job_id'));

        $this->worker->processBatch(10);

        $stmt = $this->pdo->query("SELECT * FROM cd_email_queue WHERE status = 'sent'");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertSame(1, (int)$rows[0]['attempts']);
        $this->assertNotNull($rows[0]['sent_at']);
    }

    public function testWorkerRetriesAfterSmtpFailure(): void
    {
        $payload = new EmailQueuePayloadDTO(['name' => 'Bob'], 'welcome', 'en');
        $this->writer->enqueue('user', '2', 'bob@example.com', $payload, 1);

        $this->transport->expects($this->once())
            ->method('send')
            ->willThrowException(new \Maatify\EmailDelivery\Exception\EmailTransportException('SMTP Error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Email job failed');
        $this->logger->expects($this->once())
            ->method('info')
            ->with('Email job scheduled for retry');

        $this->worker->processBatch(10);

        $stmt = $this->pdo->query("SELECT * FROM cd_email_queue");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame(1, (int)$rows[0]['attempts']);
        $this->assertNotNull($rows[0]['retry_after']);
        $this->assertIsString($rows[0]['last_error']);
        $this->assertStringContainsString('smtp_transport_error', $rows[0]['last_error']);
    }

    public function testDecryptionFailure(): void
    {
        $payload = new EmailQueuePayloadDTO(['name' => 'Charlie'], 'welcome', 'en');
        $this->writer->enqueue('user', '3', 'charlie@example.com', $payload, 1);

        $this->pdo->exec("UPDATE cd_email_queue SET recipient_tag = 'corrupted_tag'");

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Email job failed', $this->callback(function ($context) {
                return $context['error_code'] === 'crypto_decryption_failed';
            }));

        $this->worker->processBatch(10);

        $stmt = $this->pdo->query("SELECT * FROM cd_email_queue");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame(1, (int)$rows[0]['attempts']);
        $this->assertIsString($rows[0]['last_error']);
        $this->assertStringContainsString('crypto_decryption_failed', $rows[0]['last_error']);
    }

    public function testRendererThrowsWhenTemplateMissing(): void
    {
        $payload = new EmailQueuePayloadDTO(['name' => 'Dave'], 'missing_template', 'en');
        $this->writer->enqueue('user', '4', 'dave@example.com', $payload, 1);

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Email job failed', $this->callback(function ($context) {
                return $context['error_code'] === 'email_render_failed';
            }));

        $this->worker->processBatch(10);

        $stmt = $this->pdo->query("SELECT * FROM cd_email_queue");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame(1, (int)$rows[0]['attempts']);
        $this->assertIsString($rows[0]['last_error']);
        $this->assertStringContainsString('email_render_failed', $rows[0]['last_error']);
    }

    public function testMaxAttemptsReached(): void
    {
        $payload = new EmailQueuePayloadDTO(['name' => 'Eve'], 'welcome', 'en');
        $this->writer->enqueue('user', '5', 'eve@example.com', $payload, 1);

        $this->transport->expects($this->exactly(4))
            ->method('send')
            ->willThrowException(new \Maatify\EmailDelivery\Exception\EmailTransportException('SMTP Error'));

        $this->logger->expects($this->exactly(4))
            ->method('error')
            ->with('Email job failed');
        $this->logger->expects($this->exactly(3))
            ->method('info')
            ->with('Email job scheduled for retry');
        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Email job permanently failed');

        for ($i = 0; $i < 4; $i++) {
            $this->pdo->exec("UPDATE cd_email_queue SET retry_after = NULL WHERE status = 'pending'");
            $this->worker->processBatch(10);
        }

        $stmt = $this->pdo->query("SELECT * FROM cd_email_queue");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame('failed', $rows[0]['status']);
        $this->assertSame(4, (int)$rows[0]['attempts']);
    }

    public function testWorkerBatchProcessing(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $payload = new EmailQueuePayloadDTO(['name' => "User$i"], 'welcome', 'en');
            $this->writer->enqueue('user', (string)$i, "user$i@example.com", $payload, 1);
        }

        $this->transport->expects($this->exactly(3))->method('send');
        $this->worker->processBatch(3);

        $stmt = $this->pdo->query("SELECT status, count(*) as count FROM cd_email_queue GROUP BY status");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame(3, (int)$counts['sent']);
        $this->assertSame(2, (int)$counts['pending']);
    }

    public function testPartialBatchSuccess(): void
    {
        $payload1 = new EmailQueuePayloadDTO(['name' => 'User1'], 'welcome', 'en');
        $this->writer->enqueue('user', '1', 'user1@example.com', $payload1, 1);

        $payload2 = new EmailQueuePayloadDTO(['name' => 'User2'], 'welcome', 'en');
        $this->writer->enqueue('user', '2', 'fail@example.com', $payload2, 1);

        $payload3 = new EmailQueuePayloadDTO(['name' => 'User3'], 'welcome', 'en');
        $this->writer->enqueue('user', '3', 'user3@example.com', $payload3, 1);

        $this->transport->expects($this->exactly(3))
            ->method('send')
            ->willReturnCallback(function (string $recipient, $email) {
                if ($recipient === 'fail@example.com') {
                    throw new \Maatify\EmailDelivery\Exception\EmailTransportException('Simulated Failure');
                }
            });

        $this->worker->processBatch(10);

        $stmt = $this->pdo->query("SELECT status, count(*) as count FROM cd_email_queue GROUP BY status");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $counts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        $this->assertSame(2, (int)$counts['sent']);
        $this->assertSame(1, (int)$counts['pending']);

        $stmt = $this->pdo->query("SELECT last_error FROM cd_email_queue WHERE status = 'pending'");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $failedRow = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($failedRow);
        $this->assertIsString($failedRow['last_error']);
        $this->assertStringContainsString('smtp_transport_error', $failedRow['last_error']);
    }

    public function testScheduledJobsNotProcessed(): void
    {
        $payload = new EmailQueuePayloadDTO(['name' => 'Future'], 'welcome', 'en');
        $scheduledAt = (new DateTimeImmutable())->modify('+1 hour');

        $this->writer->enqueue('user', '6', 'future@example.com', $payload, 1, 5, $scheduledAt);

        $this->transport->expects($this->never())->method('send');
        $this->logger->expects($this->never())->method('info');

        $this->worker->processBatch(10);

        $stmt = $this->pdo->query("SELECT * FROM cd_email_queue");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame(0, (int)$rows[0]['attempts']);
    }

    public function testEmptyQueueScenario(): void
    {
        $this->transport->expects($this->never())->method('send');

        $this->worker->processBatch(10);

        $stmt = $this->pdo->query("SELECT * FROM cd_email_queue");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(0, $rows);
    }

    public function testEncryptionRoundtripValidation(): void
    {
        $payload = new EmailQueuePayloadDTO(['name' => 'TopSecret', 'account_balance' => 1000000], 'welcome', 'en');
        $this->writer->enqueue('user', '99', 'secure@example.com', $payload, 1);

        $stmt = $this->pdo->query("SELECT * FROM cd_email_queue LIMIT 1");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        $this->assertIsString($row['payload_encrypted']);
        $this->assertStringNotContainsString('TopSecret', $row['payload_encrypted']);
        $this->assertIsString($row['recipient_encrypted']);
        $this->assertStringNotContainsString('secure@example.com', $row['recipient_encrypted']);
        $this->assertNotEmpty($row['payload_iv']);
        $this->assertNotEmpty($row['payload_tag']);

        $this->transport->expects($this->once())
            ->method('send')
            ->with('secure@example.com', $this->callback(function ($renderedEmail) {
                return $renderedEmail->subject === 'Welcome TopSecret' &&
                       str_contains($renderedEmail->htmlBody, 'Hello TopSecret');
            }));

        $this->worker->processBatch(10);

        $stmt = $this->pdo->query("SELECT status FROM cd_email_queue LIMIT 1");
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $this->assertSame('sent', $stmt->fetchColumn());
    }


    public function testQueueLockingSafety(): void
    {
        $realPdoMock = $this->createMock(PDO::class);
        $realPdoMock->expects($this->once())
            ->method('beginTransaction');

        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->method('fetchAll')->willReturn([]);

        $realPdoMock->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('FOR UPDATE'))
            ->willReturn($stmtMock);

        $realPdoMock->expects($this->once())
            ->method('commit');

        $worker = new EmailQueueWorker(
            $realPdoMock,
            $this->cryptoProvider,
            $this->renderer,
            $this->transport,
            $this->cryptoContextProvider,
            $this->logger
        );

        $worker->processBatch(10);
    }

}
