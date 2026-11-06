<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

abstract class IsolatedEmailMysqlTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        if (getenv('EMAIL_DELIVERY_IT_DSN') === false) {
            if (getenv('EMAIL_DELIVERY_IT_REQUIRED') === '1') {
                self::fail('The required email MySQL fixture is not configured.');
            }
            self::markTestSkipped('An isolated _test MySQL fixture is required.');
        }
        $this->pdo = self::connection();
        $this->pdo->exec('DROP TABLE IF EXISTS cd_email_enqueue_receipts');
        $this->pdo->exec('DROP TABLE IF EXISTS cd_email_queue');
        foreach (['001_email_queue.sql', '002_email_enqueue_receipts.sql'] as $file) {
            $sql = file_get_contents(dirname(__DIR__, 2) . '/schema/mysql/' . $file);
            if ($sql === false) {
                throw new RuntimeException('Package schema is missing.');
            }
            $this->pdo->exec($sql);
        }
    }

    public static function connection(): PDO
    {
        $dsn = getenv('EMAIL_DELIVERY_IT_DSN');
        if ($dsn === false || !str_starts_with($dsn, 'mysql:')) {
            throw new RuntimeException('Only an explicit MySQL fixture DSN is supported.');
        }
        $pdo = new PDO($dsn, (string) getenv('EMAIL_DELIVERY_IT_USER'), (string) getenv('EMAIL_DELIVERY_IT_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if (!str_ends_with(self::scalar($pdo, 'SELECT DATABASE()'), '_test')) {
            throw new RuntimeException('Refusing a database without the _test suffix.');
        }
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        return $pdo;
    }

    protected static function scalar(PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Fixture query was not executed.');
        }
        $value = $statement->fetchColumn();
        if (!is_string($value) && !is_int($value)) {
            throw new RuntimeException('Fixture query returned an invalid scalar.');
        }
        return (string) $value;
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->pdo->exec('SET SESSION autocommit = 1');
            $this->pdo->exec('DROP TABLE IF EXISTS cd_email_enqueue_receipts');
            $this->pdo->exec('DROP TABLE IF EXISTS cd_email_queue');
        }
    }
}
