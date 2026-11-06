<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Synthetic\Internal;

use Maatify\EmailDelivery\Exception\SyntheticEmailUnavailableException;
use PDO;

/** @internal Fail closed on incompatible schema, while holding its metadata lock in our transaction. */
final class SyntheticEmailSchemaValidator
{
    public static function validate(PDO $pdo): void
    {
        $lock = $pdo->query('SELECT id FROM cd_email_synthetic_requests LIMIT 0 FOR UPDATE');
        if ($lock === false) {
            throw new SyntheticEmailUnavailableException('Synthetic table is unavailable.');
        }
        $lock->closeCursor();
        $tables = self::rows($pdo, "SELECT ENGINE, TABLE_TYPE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cd_email_synthetic_requests'");
        if (count($tables) !== 1 || $tables[0]['ENGINE'] !== 'InnoDB' || $tables[0]['TABLE_TYPE'] !== 'BASE TABLE') {
            throw new SyntheticEmailUnavailableException('A dedicated InnoDB base table is required.');
        }
        // The package contract contains no triggers or outgoing foreign keys into delivery tables.
        if (self::rows($pdo, "SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
                WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'cd_email_synthetic_requests'") !== []
            || self::rows($pdo, "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'cd_email_synthetic_requests'") !== []) {
            throw new SyntheticEmailUnavailableException('Synthetic storage must not have triggers or outgoing foreign keys.');
        }
        $rows = self::rows($pdo, "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA, COLLATION_NAME
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cd_email_synthetic_requests'");
        $expected = [
            'id' => ['bigint unsigned', 'NO'], 'record_id' => ['char(32)', 'NO'],
            'consumer_namespace' => ['varbinary(128)', 'NO'], 'entity_type' => ['varchar(255)', 'NO'],
            'entity_id' => ['varchar(255)', 'YES'], 'recipient_encrypted' => ['longblob', 'NO'],
            'recipient_iv' => ['varbinary(255)', 'NO'], 'recipient_tag' => ['varbinary(255)', 'NO'],
            'recipient_key_id' => ['varchar(255)', 'NO'], 'payload_encrypted' => ['longblob', 'NO'],
            'payload_iv' => ['varbinary(255)', 'NO'], 'payload_tag' => ['varbinary(255)', 'NO'],
            'payload_key_id' => ['varchar(255)', 'NO'], 'template_key' => ['varchar(255)', 'NO'],
            'language' => ['varchar(255)', 'NO'], 'sender_type' => ['int', 'NO'], 'priority' => ['int', 'NO'],
            'requested_scheduled_at' => ['datetime', 'YES'],
            'recorded_at' => ['datetime(6)', 'NO'], 'expires_at' => ['datetime(6)', 'NO'],
        ];
        if (count($rows) !== count($expected)) {
            throw new SyntheticEmailUnavailableException('Synthetic columns do not match the package schema.');
        }
        foreach ($rows as $row) {
            $name = $row['COLUMN_NAME'];
            $type = $row['COLUMN_TYPE'];
            // MariaDB reports integer display widths; they do not change range or storage.
            $type = is_string($type) ? preg_replace('/\b(bigint|int)\(\d+\)/', '$1', $type) : null;
            if (!is_string($name) || !isset($expected[$name]) || [$type, $row['IS_NULLABLE']] !== $expected[$name]
                || ($name === 'record_id' && $row['COLLATION_NAME'] !== 'ascii_bin')
                || ($name === 'id' ? $row['EXTRA'] !== 'auto_increment' : $row['EXTRA'] !== '')) {
                throw new SyntheticEmailUnavailableException('Synthetic column metadata is incompatible.');
            }
        }
        $rows = self::rows($pdo, "SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SUB_PART, INDEX_TYPE
            FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cd_email_synthetic_requests'
            ORDER BY INDEX_NAME, SEQ_IN_INDEX");
        /** @var array<string, list<string>> $indexes */
        $indexes = [];
        foreach ($rows as $row) {
            $name = $row['INDEX_NAME'];
            if (!is_string($name) || !is_string($row['COLUMN_NAME']) || $row['SUB_PART'] !== null
                || $row['INDEX_TYPE'] !== 'BTREE' || !in_array($row['NON_UNIQUE'], $name === 'idx_email_synthetic_expiry' ? [1, '1'] : [0, '0'], true)) {
                throw new SyntheticEmailUnavailableException('Synthetic indexes are incompatible.');
            }
            $indexes[$name][] = $row['COLUMN_NAME'];
        }
        ksort($indexes);
        if ($indexes !== ['PRIMARY' => ['id'], 'idx_email_synthetic_expiry' => ['expires_at', 'id'], 'uq_email_synthetic_record' => ['record_id']]) {
            throw new SyntheticEmailUnavailableException('Synthetic identity and bounded expiry indexes are required.');
        }
    }

    /** @return list<array<string, mixed>> */
    private static function rows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new SyntheticEmailUnavailableException('Synthetic metadata could not be inspected.');
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }
}
