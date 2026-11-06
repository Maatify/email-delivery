<?php

declare(strict_types=1);

namespace Tests\Support;

use PDOStatement;

final class EmailQueueFaultStatement extends PDOStatement
{
    protected function __construct(private EmailQueueFaultPdo $connection)
    {
    }

    /** @return array<string, mixed>|false */
    public function getColumnMeta(int $column): array|false
    {
        if ($this->connection->mode === 'worker_metadata_false'
            && str_starts_with($this->queryString, 'SELECT * FROM `cd_email_queue` LIMIT 0')) {
            ++$this->connection->workerMetadataFaults;
            return false;
        }
        return parent::getColumnMeta($column);
    }

    /** @param array<array-key, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $fault = $this->connection->beforeExecute($this->queryString);
        if ($fault !== null) {
            return $fault;
        }
        $result = parent::execute($params);
        if ($this->connection->afterExecuteHook !== null) {
            ($this->connection->afterExecuteHook)($this->queryString);
        }
        return $result;
    }
}
