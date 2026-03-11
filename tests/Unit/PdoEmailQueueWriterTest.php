<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use Maatify\Crypto\Contract\CryptoContextProviderInterface;
use Maatify\Crypto\DX\CryptoContextFactory;
use Maatify\Crypto\DX\CryptoDirectFactory;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\Crypto\KeyRotation\KeyRotationService;
use Maatify\Crypto\KeyRotation\Providers\InMemoryKeyProvider;
use Maatify\Crypto\KeyRotation\Policy\StrictSingleActiveKeyPolicy;
use Maatify\Crypto\KeyRotation\DTO\CryptoKeyDTO;
use Maatify\Crypto\KeyRotation\KeyStatusEnum;
use Maatify\Crypto\Reversible\Algorithms\Aes256GcmAlgorithm;
use Maatify\Crypto\HKDF\HKDFService;
use Maatify\Crypto\Reversible\Registry\ReversibleCryptoAlgorithmRegistry;
use Maatify\EmailDelivery\Exception\EmailQueueWriteException;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\PdoEmailQueueWriter;
use PDO;
use PDOStatement;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PdoEmailQueueWriterTest extends TestCase
{
    private PDO&MockObject $pdo;
    private CryptoProvider $cryptoProvider;
    private CryptoContextProviderInterface&MockObject $cryptoContextProvider;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->cryptoContextProvider = $this->createMock(CryptoContextProviderInterface::class);

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
    }

    public function testEnqueueInsertsCorrectRecord(): void
    {
        $payload = new EmailQueuePayloadDTO(['name' => 'John'], 'welcome', 'en');
        $scheduledAt = new DateTimeImmutable('2025-01-01 12:00:00');

        $this->cryptoContextProvider->expects($this->once())
            ->method('emailQueueRecipient')
            ->willReturn('recipient_context:v1');

        $this->cryptoContextProvider->expects($this->once())
            ->method('emailQueuePayload')
            ->willReturn('payload_context:v1');

        $stmt = $this->createMock(PDOStatement::class);
        $stmt->expects($this->once())
            ->method('execute')
            ->with($this->callback(function (array $params) use ($scheduledAt) {
                return $params['entity_type'] === 'user'
                    && $params['entity_id'] === '123'
                    && !empty($params['recipient_encrypted'])
                    && !empty($params['recipient_iv'])
                    && !empty($params['recipient_tag'])
                    && $params['recipient_key_id'] === 'test_key'
                    && !empty($params['payload_encrypted'])
                    && !empty($params['payload_iv'])
                    && !empty($params['payload_tag'])
                    && $params['payload_key_id'] === 'test_key'
                    && $params['template_key'] === 'welcome'
                    && $params['language'] === 'en'
                    && $params['sender_type'] === 1
                    && $params['priority'] === 10
                    && $params['scheduled_at'] === $scheduledAt->format('Y-m-d H:i:s');
            }))
            ->willReturn(true);

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('INSERT INTO `cd_email_queue`'))
            ->willReturn($stmt);

        $writer = new PdoEmailQueueWriter($this->pdo, $this->cryptoProvider, $this->cryptoContextProvider);

        $writer->enqueue(
            'user',
            '123',
            'john@example.com',
            $payload,
            1,
            10,
            $scheduledAt
        );
    }

    public function testEnqueueThrowsExceptionOnPdoError(): void
    {
        $payload = new EmailQueuePayloadDTO([], 'welcome', 'en');

        $this->cryptoContextProvider->expects($this->once())
            ->method('emailQueueRecipient')
            ->willReturn('recipient_context:v1');

        $this->cryptoContextProvider->expects($this->once())
            ->method('emailQueuePayload')
            ->willReturn('payload_context:v1');

        $this->pdo->expects($this->once())
            ->method('prepare')
            ->willThrowException(new \PDOException('DB Error'));

        $writer = new PdoEmailQueueWriter($this->pdo, $this->cryptoProvider, $this->cryptoContextProvider);

        $this->expectException(EmailQueueWriteException::class);
        $this->expectExceptionMessage('Failed to enqueue email');

        $writer->enqueue('user', '123', 'john@example.com', $payload, 1);
    }
}
