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
        // MySQL DATE_SUB(<clock>, INTERVAL :param SECOND) → SQLite datetime(<clock>, '-N seconds').
        $query = preg_replace('/DATE_SUB\((.+?), INTERVAL (:\w+) SECOND\)/', "datetime($1, '-' || $2 || ' seconds')", $query) ?? $query;
        return parent::prepare($query, $options);
    }
}
