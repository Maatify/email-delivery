<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Documentation\Examples;

use Maatify\Crypto\Contract\CryptoContextProviderInterface;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueReceiptDTO;
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\EmailEnqueueReceiptReaderInterface;
use Maatify\EmailDelivery\Queue\IdempotentEmailQueueWriterInterface;
use Maatify\EmailDelivery\Queue\PdoIdempotentEmailQueue;
use PDO;

/** Application preconfigures idle exception-mode/autocommit PDO with explicit UTC and applies SQL. */
function createKeyedQueue(
    PDO $pdo,
    CryptoProvider $crypto,
    CryptoContextProviderInterface $contexts,
    string $stableVersionedFingerprintContext,
): PdoIdempotentEmailQueue {
    return new PdoIdempotentEmailQueue($pdo, $crypto, $contexts, $stableVersionedFingerprintContext);
}

/** Caller persists/authenticates the namespace/key and keeps the intended content stable on retry. */
function enqueueKeyedWelcome(
    IdempotentEmailQueueWriterInterface $queue,
    string $authenticatedNamespace,
    string $stableRequestKey,
    string $recipient,
    string $userId,
    string $name,
): EmailEnqueueReceiptDTO {
    return $queue->enqueueOnce(new EmailEnqueueRequestDTO(
        consumerNamespace: $authenticatedNamespace,
        requestKey: $stableRequestKey,
        entityType: 'user',
        entityId: $userId,
        recipientEmail: $recipient,
        payload: new EmailQueuePayloadDTO(['name' => $name], 'welcome', 'en'),
        senderType: 1,
    ));
}

/** Scoped observation, not a mailbox receipt; authorization belongs to the caller. */
function lookupKeyedWelcome(
    EmailEnqueueReceiptReaderInterface $reader,
    string $authenticatedNamespace,
    string $stableRequestKey,
): ?EmailEnqueueReceiptDTO {
    return $reader->find($authenticatedNamespace, $stableRequestKey);
}
