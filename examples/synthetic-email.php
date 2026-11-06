<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Documentation\Examples;

use Maatify\Crypto\Contract\CryptoContextProviderInterface;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRequestDTO;
use Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRecordDTO;
use Maatify\EmailDelivery\Synthetic\PdoSyntheticEmailStore;
use Maatify\EmailDelivery\Synthetic\SyntheticEmailRecorderInterface;
use Maatify\EmailDelivery\Synthetic\SyntheticEmailMaintenanceInterface;
use PDO;

/** Apply optional 004 first. Application owns idle exception/autocommit PDO with explicit UTC. */
function createSyntheticStore(PDO $pdo, CryptoProvider $crypto, CryptoContextProviderInterface $contexts, int $retentionSeconds): PdoSyntheticEmailStore
{
    return new PdoSyntheticEmailStore($pdo, $crypto, $contexts, $retentionSeconds);
}

/** Consumer authorizes the namespace and supplies decoy data; this never enqueues/sends email. */
function recordSyntheticWelcome(SyntheticEmailRecorderInterface $recorder, string $authenticatedNamespace, string $decoyRecipient, string $decoyName): SyntheticEmailRecordDTO
{
    return $recorder->record(new SyntheticEmailRequestDTO(
        consumerNamespace: $authenticatedNamespace,
        entityType: 'synthetic',
        entityId: null,
        recipientEmail: $decoyRecipient,
        payload: new EmailQueuePayloadDTO(['name' => $decoyName], 'welcome', 'en'),
        senderType: 1,
    ));
}

/** Trusted scheduled maintenance, all synthetic namespaces, one bounded batch per call. */
function purgeSyntheticBatch(SyntheticEmailMaintenanceInterface $maintenance, int $batchSize = 100): int
{
    return $maintenance->purgeExpired($batchSize);
}
