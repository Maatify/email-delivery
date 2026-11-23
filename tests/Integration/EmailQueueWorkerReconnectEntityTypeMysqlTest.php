<?php

declare(strict_types=1);

namespace Tests\Integration;

/**
 * Every reconnect, lease, fence, lost-acknowledgement, autocommit, error-mode, TIMESTAMP-clock and claim-lock
 * scenario again, through processBatchByEntityType() scoped to the fixture rows' entity type. The scoped batch
 * shares the claim with processBatch(); this proves it keeps every one of those guarantees.
 */
final class EmailQueueWorkerReconnectEntityTypeMysqlTest extends EmailQueueWorkerReconnectMysqlTest
{
    protected function batchEntityType(): string
    {
        return 'user';
    }
}
