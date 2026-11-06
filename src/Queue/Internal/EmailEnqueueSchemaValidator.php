<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Queue\Internal;

use Maatify\EmailDelivery\Exception\EmailEnqueueUnavailableException;
use PDO;

/** @internal Validate before writes, after acquiring table metadata locks for this owned transaction. */
final class EmailEnqueueSchemaValidator
{
    public static function validate(PDO $pdo): void
    {
        foreach (['SELECT id FROM cd_email_queue LIMIT 0 FOR UPDATE',
            'SELECT consumer_namespace, request_key FROM cd_email_enqueue_receipts LIMIT 0 FOR UPDATE'] as $sql) {
            $statement = $pdo->query($sql);
            if ($statement === false) {
                throw new EmailEnqueueUnavailableException('Required queue tables are unavailable.');
            }
            $statement->closeCursor();
        }
        $statement = $pdo->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('cd_email_queue', 'cd_email_enqueue_receipts')");
        if ($statement === false) {
            throw new EmailEnqueueUnavailableException('Queue engines could not be inspected.');
        }
        /** @var list<array<string, mixed>> $tables */
        $tables = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($tables) !== 2) {
            throw new EmailEnqueueUnavailableException('Both package queue tables are required.');
        }
        foreach ($tables as $table) {
            if (($table['ENGINE'] ?? null) !== 'InnoDB') {
                throw new EmailEnqueueUnavailableException('Transactional InnoDB queue tables are required.');
            }
        }
        $statement = $pdo->query("SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SUB_PART
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('cd_email_queue', 'cd_email_enqueue_receipts')
            ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX");
        if ($statement === false) {
            throw new EmailEnqueueUnavailableException('Queue uniqueness could not be inspected.');
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        /** @var array<string, list<string>> $indexes */
        $indexes = [];
        /** @var array<string, true> $invalidIndexes */
        $invalidIndexes = [];
        foreach ($rows as $row) {
            if (!is_string($row['TABLE_NAME'] ?? null) || !is_string($row['INDEX_NAME'] ?? null)) {
                throw new EmailEnqueueUnavailableException('Queue index metadata is invalid.');
            }
            $name = $row['TABLE_NAME'] . ':' . $row['INDEX_NAME'];
            if (!in_array($row['NON_UNIQUE'] ?? null, [0, '0'], true) || ($row['SUB_PART'] ?? null) !== null
                || !is_string($row['COLUMN_NAME'] ?? null)) {
                $invalidIndexes[$name] = true;
                unset($indexes[$name]);
            } elseif (!isset($invalidIndexes[$name])) {
                $indexes[$name][] = $row['COLUMN_NAME'];
            }
        }
        if (($indexes['cd_email_queue:PRIMARY'] ?? null) !== ['id']
            || ($indexes['cd_email_enqueue_receipts:PRIMARY'] ?? null) !== ['consumer_namespace', 'request_key']) {
            throw new EmailEnqueueUnavailableException('Full package identity primary keys are required.');
        }
        $receiptUnique = false;
        $bindingUnique = false;
        foreach ($indexes as $name => $columns) {
            if (str_starts_with($name, 'cd_email_queue:') && $columns === ['enqueue_receipt_id']) {
                $bindingUnique = true;
            }
            if (str_starts_with($name, 'cd_email_enqueue_receipts:') && $columns === ['receipt_id']) {
                $receiptUnique = true;
            }
        }
        if (!$receiptUnique || !$bindingUnique) {
            throw new EmailEnqueueUnavailableException('Unique receipt identities are required.');
        }
        $statement = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, EXTRA, COLLATION_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'cd_email_enqueue_receipts'
                AND COLUMN_NAME IN ('consumer_namespace', 'request_key')) OR (TABLE_NAME = 'cd_email_queue' AND COLUMN_NAME IN ('id', 'enqueue_receipt_id')))");
        if ($statement === false) {
            throw new EmailEnqueueUnavailableException('Queue identity types could not be inspected.');
        }
        /** @var list<array<string, mixed>> $columns */
        $columns = $statement->fetchAll(PDO::FETCH_ASSOC);
        if (count($columns) !== 4) {
            throw new EmailEnqueueUnavailableException('Queue identity columns are missing.');
        }
        foreach ($columns as $column) {
            if ($column['TABLE_NAME'] === 'cd_email_queue' && $column['COLUMN_NAME'] === 'id') {
                if ($column['IS_NULLABLE'] !== 'NO' || !is_string($column['EXTRA']) || !str_contains($column['EXTRA'], 'auto_increment')) {
                    throw new EmailEnqueueUnavailableException('An auto-increment queue identity is required.');
                }
            } elseif ($column['TABLE_NAME'] === 'cd_email_queue') {
                if ($column['DATA_TYPE'] !== 'char' || $column['IS_NULLABLE'] !== 'YES'
                    || !in_array($column['CHARACTER_MAXIMUM_LENGTH'], [32, '32'], true) || $column['COLLATION_NAME'] !== 'ascii_bin') {
                    throw new EmailEnqueueUnavailableException('Immutable nullable queue receipt tokens are required.');
                }
            } elseif ($column['DATA_TYPE'] !== 'varbinary' || $column['IS_NULLABLE'] !== 'NO'
                || !in_array($column['CHARACTER_MAXIMUM_LENGTH'], $column['COLUMN_NAME'] === 'consumer_namespace' ? [128, '128'] : [191, '191'], true)) {
                throw new EmailEnqueueUnavailableException('Exact binary namespace/key columns are required.');
            }
        }
    }
}
