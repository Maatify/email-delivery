<?php

declare(strict_types=1);

namespace Tests\Helpers;

use PDO;
use PDOStatement;

class SqliteTestPdo extends PDO
{
    /**
     * @param string $query
     * @param array<int, mixed> $options
     * @return PDOStatement|false
     */
    #[\ReturnTypeWillChange]
    public function prepare($query, $options = [])
    {
        $query = str_replace('FOR UPDATE', '', $query);
        $query = str_replace('NOW()', "datetime('now')", $query);
        return parent::prepare($query, $options);
    }
}
