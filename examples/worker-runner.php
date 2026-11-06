<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Documentation\Examples;

use Maatify\Crypto\Contract\CryptoContextProviderInterface;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\EmailDelivery\Renderer\EmailRendererInterface;
use Maatify\EmailDelivery\Transport\EmailTransportInterface;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use PDO;
use Psr\Log\LoggerInterface;

/** Dedicated idle connection; caller owns secrets, supervision, logging policy and recovery decisions. */
function createWorker(
    PDO $pdo,
    CryptoProvider $crypto,
    CryptoContextProviderInterface $contexts,
    EmailRendererInterface $renderer,
    EmailTransportInterface $transport,
    LoggerInterface $logger,
): EmailQueueWorker {
    return new EmailQueueWorker($pdo, $crypto, $renderer, $transport, $contexts, $logger);
}

/** Executes only when the application deliberately calls it; no loop/recovery is implied. */
function runOneWorkerBatch(EmailQueueWorker $worker, int $limit = 50): void
{
    $worker->processBatch($limit);
}
