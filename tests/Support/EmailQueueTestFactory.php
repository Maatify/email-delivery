<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use Maatify\Crypto\Contract\CryptoContextProviderInterface;
use Maatify\Crypto\DX\CryptoContextFactory;
use Maatify\Crypto\DX\CryptoDirectFactory;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\Crypto\HKDF\HKDFService;
use Maatify\Crypto\KeyRotation\DTO\CryptoKeyDTO;
use Maatify\Crypto\KeyRotation\KeyRotationService;
use Maatify\Crypto\KeyRotation\KeyStatusEnum;
use Maatify\Crypto\KeyRotation\Policy\StrictSingleActiveKeyPolicy;
use Maatify\Crypto\KeyRotation\Providers\InMemoryKeyProvider;
use Maatify\Crypto\Reversible\Algorithms\Aes256GcmAlgorithm;
use Maatify\Crypto\Reversible\Registry\ReversibleCryptoAlgorithmRegistry;
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\PdoIdempotentEmailQueue;
use PDO;

/** Only fixture keys and an explicitly supplied isolated test connection; no .env loading. */
final class EmailQueueTestFactory implements CryptoContextProviderInterface
{
    public const FINGERPRINT_CONTEXT = 'fixture:email:enqueue:fingerprint:v1';

    /** @param list<CryptoKeyDTO> $keys */
    public static function crypto(array $keys = []): CryptoProvider
    {
        $registry = new ReversibleCryptoAlgorithmRegistry();
        $registry->register(new Aes256GcmAlgorithm());
        $rotation = new KeyRotationService(new InMemoryKeyProvider($keys ?: [
            new CryptoKeyDTO('fixture-v1', str_repeat('k', 32), KeyStatusEnum::ACTIVE, new DateTimeImmutable('2026-11-06T00:00:00Z')),
        ]), new StrictSingleActiveKeyPolicy());
        return new CryptoProvider(new CryptoContextFactory($rotation, new HKDFService(), $registry), new CryptoDirectFactory($rotation, $registry));
    }

    public static function queue(PDO $pdo, ?CryptoProvider $crypto = null): PdoIdempotentEmailQueue
    {
        return new PdoIdempotentEmailQueue($pdo, $crypto ?? self::crypto(), new self(), self::FINGERPRINT_CONTEXT);
    }

    /** @param array<string, mixed> $context */
    public static function request(string $key = 'request-1', string $namespace = 'fixture-consumer', array $context = ['code' => 'fixture-secret']): EmailEnqueueRequestDTO
    {
        return new EmailEnqueueRequestDTO(
            $namespace,
            $key,
            'user',
            '1',
            'fixture@example.invalid',
            new EmailQueuePayloadDTO($context, 'welcome', 'en'),
            1
        );
    }

    public function identifierEmail(): string
    {
        return 'fixture:identifier:email:v1';
    }
    public function identifierPhone(): string
    {
        return 'fixture:identifier:phone:v1';
    }
    public function notificationEmailRecipient(): string
    {
        return 'fixture:notification:recipient:v1';
    }
    public function notificationEmailPayload(): string
    {
        return 'fixture:notification:payload:v1';
    }
    public function emailQueueRecipient(): string
    {
        return 'fixture:queue:recipient:v1';
    }
    public function emailQueuePayload(): string
    {
        return 'fixture:queue:payload:v1';
    }
    public function totpSeed(): string
    {
        return 'fixture:totp:v1';
    }
    public function systemSecret(): string
    {
        return 'fixture:system:v1';
    }
    public function abuseProtection(): string
    {
        return 'fixture:abuse:v1';
    }
}
