<?php

declare(strict_types=1);

namespace Tests\Support;

use Maatify\EmailDelivery\Worker\EmailQueueWorker;

/**
 * Runs a worker's batch either as processBatch() or, with an entity type, as processBatchByEntityType(), so that the
 * same scenarios prove the same guarantees on both paths.
 */
final class WorkerBatch
{
    public function __construct(
        private readonly EmailQueueWorker $worker,
        private readonly ?string $entityType,
    ) {
    }

    public function processBatch(int $limit = 50): void
    {
        if ($this->entityType === null) {
            $this->worker->processBatch($limit);
            return;
        }
        $this->worker->processBatchByEntityType($this->entityType, $limit);
    }
}
