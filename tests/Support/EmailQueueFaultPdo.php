<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/** Real MySQL connection; faults only at the named PDO boundaries, never a business mock. */
final class EmailQueueFaultPdo extends PDO
{
    public string $mode = 'none';
    /** @var (Closure(string): void)|null */
    public ?Closure $beforeExecuteHook = null;
    /** @var (Closure(string): void)|null */
    public ?Closure $afterExecuteHook = null;
    public bool $failRollback = false;
    public int $beginCalls = 0;
    public int $commitCalls = 0;
    public int $rollbackCalls = 0;
    public int $executeFaults = 0;

    public function __construct()
    {
        $dsn = getenv('EMAIL_DELIVERY_IT_DSN');
        if ($dsn === false) {
            throw new RuntimeException('Fault PDO requires the isolated fixture.');
        }
        parent::__construct($dsn, (string) getenv('EMAIL_DELIVERY_IT_USER'), (string) getenv('EMAIL_DELIVERY_IT_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $database = parent::query('SELECT DATABASE()');
        if ($database === false || !str_ends_with((string) $database->fetchColumn(), '_test')) {
            throw new RuntimeException('Fault PDO refuses a non-test database.');
        }
        parent::exec("SET SESSION time_zone = '+00:00'");
        $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, [EmailQueueFaultStatement::class, [$this]]);
    }

    public function getAttribute(int $attribute): mixed
    {
        if ($this->mode === 'attribute_throw') {
            throw new PDOException('Fixture attribute failure');
        }
        return parent::getAttribute($attribute);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_contains($query, '@@SESSION.autocommit') && $this->mode === 'settings_throw') {
            throw new PDOException('Fixture settings failure');
        }
        if (str_contains($query, '@@SESSION.autocommit') && $this->mode === 'settings_false') {
            return false;
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function beginTransaction(): bool
    {
        ++$this->beginCalls;
        if (str_starts_with($this->mode, 'begin_')) {
            if (str_ends_with($this->mode, '_active')) {
                parent::beginTransaction();
            }
            if (str_contains($this->mode, '_throw')) {
                throw new PDOException('Fixture begin failure');
            }
            return false;
        }
        return parent::beginTransaction();
    }

    public function commit(): bool
    {
        ++$this->commitCalls;
        if (str_starts_with($this->mode, 'commit_')) {
            if (str_ends_with($this->mode, '_after')) {
                parent::commit();
            }
            if (str_contains($this->mode, '_throw')) {
                throw new PDOException('Fixture commit failure');
            }
            return $this->mode === 'commit_true_active';
        }
        return parent::commit();
    }

    public function rollBack(): bool
    {
        ++$this->rollbackCalls;
        if ($this->failRollback) {
            return false;
        }
        return parent::rollBack();
    }

    public function beforeExecute(string $sql): ?bool
    {
        ($this->beforeExecuteHook ?? static function (string $sql): void {
        })($sql);
        $stage = str_starts_with($sql, 'INSERT INTO cd_email_enqueue_receipts') ? 'receipt'
            : (str_starts_with($sql, 'INSERT INTO cd_email_queue') ? 'queue'
                : (str_starts_with($sql, 'UPDATE cd_email_enqueue_receipts') ? 'link' : null));
        if ($stage !== null && str_starts_with($this->mode, $stage . '_')) {
            ++$this->executeFaults;
            if (str_ends_with($this->mode, '_throw')) {
                throw new PDOException('Fixture statement failure');
            }
            return false;
        }
        return null;
    }
}
