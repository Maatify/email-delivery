<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Documentation\Examples;

// This example follows the unpublished 1.3.0 candidate; its connection recovery and processing lease
// are not available in the published 1.2.0 package.

use Closure;
use Maatify\Crypto\Contract\CryptoContextProviderInterface;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\EmailDelivery\Renderer\EmailRendererInterface;
use Maatify\EmailDelivery\Transport\EmailTransportInterface;
use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Opens a new dedicated exception-mode connection on every call. The worker calls it on first use and
 * again only after the server closed its connection. Apply your own session settings here.
 *
 * @return Closure(): PDO
 */
function workerConnectionFactory(string $dsn, string $user, string $password): Closure
{
    return static fn (): PDO => new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

/**
 * Caller owns secrets, supervision and logging policy. A processing row whose lease expires is claimed
 * again and may be sent a second time (at-least-once); keep the lease above one render and send.
 *
 * @param Closure(): PDO $connect
 */
function createWorker(
    Closure $connect,
    CryptoProvider $crypto,
    CryptoContextProviderInterface $contexts,
    EmailRendererInterface $renderer,
    EmailTransportInterface $transport,
    LoggerInterface $logger,
    int $processingLeaseSeconds = EmailQueueWorker::DEFAULT_PROCESSING_LEASE_SECONDS,
): EmailQueueWorker {
    return new EmailQueueWorker($connect, $crypto, $renderer, $transport, $contexts, $logger, $processingLeaseSeconds);
}

/** Executes only when the application deliberately calls it; no loop is implied. */
function runOneWorkerBatch(EmailQueueWorker $worker, int $limit = 50): void
{
    $worker->processBatch($limit);
}
