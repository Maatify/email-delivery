<?php

declare(strict_types=1);

namespace Tests\Support;

use PDOStatement;

final class EmailQueueFaultStatement extends PDOStatement
{
    protected function __construct(private EmailQueueFaultPdo $connection)
    {
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
