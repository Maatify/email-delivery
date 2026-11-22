<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Worker;

use Closure;
use InvalidArgumentException;
use JsonException;
use Maatify\Crypto\Contract\CryptoContextProviderInterface;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\Crypto\Reversible\DTO\ReversibleCryptoMetadataDTO;
use Maatify\Crypto\Reversible\Exceptions\CryptoDecryptionFailedException;
use Maatify\Crypto\Reversible\ReversibleCryptoAlgorithmEnum;
use Maatify\EmailDelivery\DTO\GenericEmailPayload;
use Maatify\EmailDelivery\Exception\EmailRenderException;
use Maatify\EmailDelivery\Exception\EmailTransportException;
use Maatify\EmailDelivery\Renderer\EmailRendererInterface;
use Maatify\EmailDelivery\Transport\EmailTransportInterface;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * @phpstan-type EmailQueueRow array{
 *   id: int|string,
 *   status: string,
 *   priority: int|string,
 *   recipient_encrypted: string,
 *   recipient_iv: string,
 *   recipient_tag: string,
 *   recipient_key_id: string,
 *   payload_encrypted: string,
 *   payload_iv: string,
 *   payload_tag: string,
 *   payload_key_id: string,
 *   attempts: int|string,
 *   enqueue_receipt_id?: string|null
 * }
 *
 * @phpstan-type DecodedPayload array{context: array<string, mixed>, templateKey: string, language: string, replyTo?: string|null}
 */
final class EmailQueueWorker
{
    private const TABLE = 'cd_email_queue';

    /**
     * Exponential backoff per error type (seconds per attempt).
     *
     * SMTP errors use shorter backoff — transient server issues resolve faster.
     * Crypto/render errors use longer backoff — need human intervention.
     *
     * attempt 1 → BACKOFF[1], 2 → BACKOFF[2], 3 → BACKOFF[3], 4+ → permanent fail
     */
    private const BACKOFF_DEFAULT = [1 => 60, 2 => 300, 3 => 900];
    private const BACKOFF_SMTP    = [1 => 30, 2 => 60,  3 => 120];

    private const MAX_ATTEMPTS = 4;

    /**
     * Seconds a claimed row may stay `processing` without a renewal before a later batch claims it again.
     * The lease is renewed right before each row is rendered and sent, so it must outlast one render and send.
     */
    public const DEFAULT_PROCESSING_LEASE_SECONDS = 300;

    /**
     * Driver error codes after which the connection is unusable:
     * 2006 server has gone away, 2013 connection lost during a statement,
     * 4031 disconnected by the server for inactivity (how MySQL 8.0.24+ reports an expired wait_timeout).
     */
    private const CONNECTION_LOST_CODES = [2006, 2013, 4031];

    /** @var (Closure(): mixed)|null */
    private readonly ?Closure $connectionFactory;

    /** Replaced only outside a transaction, after the server closed it. */
    private ?PDO $connection;

    /** The PDO instance whose autocommit setting was already confirmed (factory connections are checked when created). */
    private ?PDO $autocommitConfirmed = null;

    private readonly int $processingLeaseSeconds;

    /**
     * @param PDO|(Closure(): PDO) $pdo A factory returning a new exception-mode connection lets the worker replace a
     *                                  connection the server closed. A PDO instance keeps the worker on that one
     *                                  connection, without reconnecting.
     * @param int $processingLeaseSeconds Seconds a `processing` row may go without renewal before it is claimed again.
     */
    public function __construct(
        PDO|Closure $pdo,
        private readonly CryptoProvider                 $cryptoProvider,
        private readonly EmailRendererInterface         $renderer,
        private readonly EmailTransportInterface        $transport,
        private readonly CryptoContextProviderInterface $cryptoContextProvider,
        private readonly LoggerInterface                $logger,
        int $processingLeaseSeconds = self::DEFAULT_PROCESSING_LEASE_SECONDS,
    ) {
        if ($processingLeaseSeconds < 1) {
            throw new InvalidArgumentException('The email processing lease must be at least one second.');
        }
        $this->processingLeaseSeconds = $processingLeaseSeconds;
        $this->connectionFactory = $pdo instanceof Closure ? $pdo : null;
        $this->connection = $pdo instanceof PDO ? $pdo : null;
    }

    public function processBatch(int $limit = 50): void
    {
        // Nothing has run yet, so replacing a closed connection here cannot split any work.
        $pdo = $this->connectionForClaim();
        $pdo->beginTransaction();

        try {
            $table = self::TABLE;
            // Discover inside this transaction: no cached capability and no session timezone mutation.
            $columns = $this->queueColumns($pdo);
            $hasBinding = $columns['binding'];
            $bindingColumn = $hasBinding ? ', enqueue_receipt_id' : '';
            // Each row keeps its own clock for due times.
            $dueClock = $hasBinding
                ? 'CASE WHEN enqueue_receipt_id IS NULL THEN NOW() ELSE UTC_TIMESTAMP() END'
                : 'NOW()';
            // The lease follows the column type. A DATETIME updated_at (the package schema) holds wall-clock values,
            // so it keeps each row's own clock. A TIMESTAMP updated_at (some application schemas) stores instants and
            // converts them by session zone, so it is compared as instants (immune to daylight saving jumps) and
            // stamped with an expression that stores the exact instant even in a repeated daylight saving hour.
            if ($columns['timestampLease']) {
                $leaseStamp = self::instantStamp($pdo);
                $stalePredicate = "status = 'processing' AND UNIX_TIMESTAMP(updated_at) < UNIX_TIMESTAMP() - :lease";
            } else {
                $leaseStamp = $dueClock;
                $stalePredicate = "status = 'processing' AND updated_at < DATE_SUB($dueClock, INTERVAL :lease SECOND)";
            }
            $columnList = "id, status, priority,
                    recipient_encrypted, recipient_iv, recipient_tag, recipient_key_id,
                    payload_encrypted, payload_iv, payload_tag, payload_key_id,
                    attempts$bindingColumn";

            // Expired leases first, without locking any row that is still in flight: an unlocked read of the
            // candidate ids in (priority, id) order, then a locking read of only those rows by primary key that
            // re-checks the predicate against the latest version. A locking scan of the `processing` range would
            // lock every in-flight row (its index entry and primary key) and could deadlock with another worker's
            // outcome write, which locks the primary key first. Taking these locks before the `pending` read also
            // means this claim never waits for a primary key while it holds gap locks in the `pending` range.
            $candidates = $pdo->prepare("SELECT id FROM `$table` WHERE $stalePredicate ORDER BY priority ASC, id ASC LIMIT :limit");
            $candidates->bindValue(':lease', $this->processingLeaseSeconds, PDO::PARAM_INT);
            $candidates->bindValue(':limit', $limit, PDO::PARAM_INT);
            $candidates->execute();
            $staleIds = array_map('intval', $candidates->fetchAll(PDO::FETCH_COLUMN));
            /** @var list<EmailQueueRow> $staleRows */
            $staleRows = [];
            if ($staleIds !== []) {
                $named = [];
                foreach ($staleIds as $index => $staleId) {
                    $named[':id' . $index] = $staleId;
                }
                $lock = $pdo->prepare("SELECT $columnList FROM `$table` WHERE id IN (" . implode(', ', array_keys($named)) . ") AND $stalePredicate FOR UPDATE");
                foreach ($named as $placeholder => $staleId) {
                    $lock->bindValue($placeholder, $staleId, PDO::PARAM_INT);
                }
                $lock->bindValue(':lease', $this->processingLeaseSeconds, PDO::PARAM_INT);
                $lock->execute();
                /** @var list<EmailQueueRow> $staleRows */
                $staleRows = $lock->fetchAll(PDO::FETCH_ASSOC);
            }

            // Then due `pending` rows, in (priority, id) order with the batch limit. With an index led by
            // (status, priority, id) the read walks the `pending` entries in order and stops at the limit.
            $due = $pdo->prepare("
                SELECT $columnList
                FROM `$table`
                WHERE status = 'pending'
                  AND scheduled_at <= $dueClock
                  AND (retry_after IS NULL OR retry_after <= $dueClock)
                ORDER BY priority ASC, id ASC
                LIMIT :limit
                FOR UPDATE
            ");
            $due->bindValue(':limit', $limit, PDO::PARAM_INT);
            $due->execute();
            /** @var list<EmailQueueRow> $rows */
            $rows = $due->fetchAll(PDO::FETCH_ASSOC);

            if ($staleRows !== []) {
                $rows = array_merge($rows, $staleRows);
                usort($rows, static fn (array $a, array $b): int => [(int) $a['priority'], (int) $a['id']] <=> [(int) $b['priority'], (int) $b['id']]);
                $rows = array_slice($rows, 0, max(0, $limit));
            }

            if ($rows === []) {
                $pdo->commit();
                return;
            }

            $claimed = [];
            $expired = [];
            foreach ($rows as $row) {
                if ($row['status'] === 'processing' && (int) $row['attempts'] >= self::MAX_ATTEMPTS) {
                    $expired[] = (int) $row['id'];
                } else {
                    $claimed[] = $row;
                }
            }

            if ($claimed !== []) {
                $ids = array_map(static fn (array $row): int => (int) $row['id'], $claimed);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("
                    UPDATE `$table`
                    SET status     = 'processing',
                        attempts   = attempts + 1,
                        updated_at = $leaseStamp
                    WHERE id IN ($placeholders)
                ")->execute($ids);
            }

            if ($expired !== []) {
                // Every attempt is used: settle the row without another send.
                $placeholders = implode(',', array_fill(0, count($expired), '?'));
                $pdo->prepare("
                    UPDATE `$table`
                    SET status     = 'failed',
                        last_error = 'processing_lease_expired',
                        updated_at = $leaseStamp
                    WHERE id IN ($placeholders)
                ")->execute($expired);
            }

            $pdo->commit();

        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        foreach ($expired as $id) {
            $this->logger->warning('Email job permanently failed', [
                'job_id' => $id,
                'reason' => 'processing_lease_expired',
            ]);
        }

        foreach ($claimed as $row) {
            $this->processRow($row, $columns['timestampLease'] ? $leaseStamp : null);
        }
    }

    /**
     * @param EmailQueueRow $row
     * @param string|null $timestampStamp on a TIMESTAMP lease column, the instant stamp every row is renewed with
     */
    private function processRow(array $row, ?string $timestampStamp): void
    {
        $id      = (int) $row['id'];
        $attempt = (int) $row['attempts'] + 1;
        $keyed   = isset($row['enqueue_receipt_id']);
        $clock   = $keyed ? 'UTC_TIMESTAMP()' : 'NOW()';

        if (!$this->renewLease($id, $attempt, $timestampStamp ?? $clock)) {
            $this->logger->warning('Email job skipped: the row is no longer processing under this attempt', [
                'job_id'  => $id,
                'attempt' => $attempt,
            ]);
            return;
        }

        try {
            $this->deliver($row);
        } catch (Throwable $e) {
            $this->recordFailure($id, $attempt, $keyed, $e);
            return;
        }

        $this->recordSent($id, $attempt, $clock);
    }

    /**
     * Decrypts, renders and sends one claimed row. Every failure is reported by an exception.
     *
     * @param EmailQueueRow $row
     */
    private function deliver(array $row): void
    {
        // ── Decrypt recipient ─────────────────────────────
        try {
            $recipientCrypto = $this->cryptoProvider->context(
                $this->cryptoContextProvider->emailQueueRecipient()
            );

            $recipient = $recipientCrypto->decrypt(
                $row['recipient_encrypted'],
                $row['recipient_key_id'],
                ReversibleCryptoAlgorithmEnum::AES_256_GCM,
                new ReversibleCryptoMetadataDTO($row['recipient_iv'], $row['recipient_tag'])
            );

            // ── Decrypt payload ───────────────────────────
            $payloadCrypto = $this->cryptoProvider->context(
                $this->cryptoContextProvider->emailQueuePayload()
            );

            $payloadJson = $payloadCrypto->decrypt(
                $row['payload_encrypted'],
                $row['payload_key_id'],
                ReversibleCryptoAlgorithmEnum::AES_256_GCM,
                new ReversibleCryptoMetadataDTO($row['payload_iv'], $row['payload_tag'])
            );

        } catch (CryptoDecryptionFailedException $e) {
            throw new \RuntimeException('crypto_decryption_failed', 0, $e);
        }

        // ── Decode payload ────────────────────────────────
        try {
            /** @var DecodedPayload $payloadData */
            $payloadData = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new \RuntimeException('invalid_payload_format', 0, $e);
        }

        // ── Extract Reply-To (optional) ───────────────────
        // Old payloads (pre-1.1.0) won't have this key — use ?? null
        $replyTo = $payloadData['replyTo'] ?? null;

        // ── Render ────────────────────────────────────────
        try {
            // Inject 'lang' automatically so templates can use {{ lang }}
            // without requiring every caller to pass it explicitly.
            $renderContext = array_merge(
                $payloadData['context'],
                ['lang' => $payloadData['language']]
            );

            $renderedEmail = $this->renderer->render(
                $payloadData['templateKey'],
                $payloadData['language'],
                new GenericEmailPayload($renderContext)
            );
        } catch (EmailRenderException $e) {
            throw new \RuntimeException('email_render_failed', 0, $e);
        }

        // ── Send ──────────────────────────────────────────
        try {
            $this->transport->send($recipient, $renderedEmail, $replyTo);
        } catch (EmailTransportException $e) {
            throw new \RuntimeException('smtp_transport_error', 0, $e);
        }
    }

    /**
     * Renews this attempt's lease right before its send and confirms the attempt still owns the row.
     * A row that another batch claimed after the lease expired is neither sent nor written by this attempt.
     */
    private function renewLease(int $id, int $attempt, string $clock): bool
    {
        $table = self::TABLE;

        return $this->withReconnect(static function (PDO $pdo) use ($table, $id, $attempt, $clock): bool {
            $pdo->prepare(
                "UPDATE `$table` SET updated_at = $clock WHERE id = :id AND status = 'processing' AND attempts = :attempt"
            )->execute(['id' => $id, 'attempt' => $attempt]);

            // Ownership is status 'processing' with this attempt number, never a fresh updated_at alone. It is read
            // back instead of using rowCount(): without PDO::MYSQL_ATTR_FOUND_ROWS (CLIENT_FOUND_ROWS), MySQL reports
            // changed rows only, and a renewal within the claim's second changes none, so rowCount() would be 0.
            $owner = $pdo->prepare(
                "SELECT COUNT(*) FROM `$table` WHERE id = :id AND status = 'processing' AND attempts = :attempt"
            );
            $owner->execute(['id' => $id, 'attempt' => $attempt]);
            $owned = $owner->fetchColumn();
            $owner->closeCursor();

            return in_array($owned, [1, '1'], true);
        });
    }

    /**
     * The send has already happened, so a failed `sent` write never writes pending/failed and never counts
     * as a send failure. The row stays `processing` until its lease expires: it is then sent again under
     * the attempt cap, or settled failed/processing_lease_expired at the cap (at-least-once). The error log
     * line is the record of the send.
     *
     * A connection still lost after one reconnect is rethrown, and any other error continues with the
     * next row; see outcomeWriteLostTheConnection().
     */
    private function recordSent(int $id, int $attempt, string $clock): void
    {
        $table = self::TABLE;

        try {
            // Not fenced by attempt: the email went out, so `sent` wins even if another batch reclaimed or settled the row.
            // sent_at keeps the first recorded send: a repeat after a lost acknowledgement, or a second real send
            // after a lease expiry, changes nothing.
            $this->withReconnect(static function (PDO $pdo) use ($table, $id, $clock): void {
                $pdo->prepare(
                    "UPDATE `$table` SET status = 'sent', sent_at = COALESCE(sent_at, $clock) WHERE id = :id"
                )->execute(['id' => $id]);
            });
        } catch (Throwable $failure) {
            $connectionLost = $this->outcomeWriteLostTheConnection($failure);
            $this->logger->error('Email sent but status write failed', [
                'job_id'          => $id,
                'attempt'         => $attempt,
                'connection_lost' => $connectionLost,
                'reason'          => $failure->getMessage(),
            ]);
            if ($connectionLost) {
                throw $failure;
            }
            return;
        }

        $this->logger->info('Email sent', ['job_id' => $id]);
    }

    private function recordFailure(int $id, int $attempts, bool $keyed, Throwable $e): void
    {
        $table = self::TABLE;

        $errorCode = match ($e->getMessage()) {
            'crypto_decryption_failed' => 'crypto_decryption_failed',
            'invalid_payload_format'   => 'invalid_payload_format',
            'email_render_failed'      => 'email_render_failed',
            'smtp_transport_error'     => 'smtp_transport_error',
            default                    => 'unexpected_worker_error',
        };

        $rootCause = $e->getPrevious()?->getMessage() ?? $e->getMessage();
        // At most 128 bytes, cut on a UTF-8 character boundary: a split character would make the write fail
        // under strict SQL mode, and a lost-acknowledgement repeat compares this value exactly.
        $errorMsg  = mb_strcut($errorCode . ': ' . $rootCause, 0, 128, 'UTF-8');

        // ── Log the failure ───────────────────────────────
        $this->logger->error('Email job failed', [
            'job_id'     => $id,
            'attempt'    => $attempts,
            'error_code' => $errorCode,
            'reason'     => $rootCause,
        ]);

        // ── SMTP uses shorter backoff (transient failures) ─
        $backoff = $errorCode === 'smtp_transport_error'
            ? self::BACKOFF_SMTP
            : self::BACKOFF_DEFAULT;

        // Each outcome write is fenced by this attempt, so it never overwrites the outcome of a newer attempt.
        try {
            // ── Exponential backoff or permanent fail ─────────
            if ($attempts >= self::MAX_ATTEMPTS) {
                $recorded = $this->withReconnect(static function (PDO $pdo, bool $repeat) use ($table, $errorMsg, $id, $attempts): bool {
                    $statement = $pdo->prepare(
                        "UPDATE `$table` SET status = 'failed', last_error = :error
                         WHERE id = :id AND status = 'processing' AND attempts = :attempt"
                    );
                    $statement->execute(['error' => $errorMsg, 'id' => $id, 'attempt' => $attempts]);
                    return $statement->rowCount() === 1
                        || ($repeat && self::storedOutcome($pdo, $table, $id, $attempts, 'failed', $errorMsg) !== null);
                });

                if ($recorded) {
                    $this->logger->warning('Email job permanently failed', [
                        'job_id'  => $id,
                        'reason'  => $rootCause,
                    ]);
                }
            } else {
                $delaySecs  = $backoff[$attempts] ?? 3600;
                [$recorded, $retryAfter] = $this->withReconnect(
                    function (PDO $pdo, bool $repeat) use ($table, $errorMsg, $id, $attempts, $keyed, $delaySecs): array {
                        $retryAfter = $keyed
                            ? $this->keyedRetryAfter($pdo, $delaySecs)
                            : date('Y-m-d H:i:s', time() + $delaySecs);

                        $statement = $pdo->prepare("
                            UPDATE `$table`
                            SET status      = 'pending',
                                last_error  = :error,
                                retry_after = :retry_after
                            WHERE id = :id AND status = 'processing' AND attempts = :attempt
                        ");
                        $statement->execute(['error' => $errorMsg, 'retry_after' => $retryAfter, 'id' => $id, 'attempt' => $attempts]);
                        if ($statement->rowCount() === 1) {
                            return [true, $retryAfter];
                        }
                        $stored = $repeat ? self::storedOutcome($pdo, $table, $id, $attempts, 'pending', $errorMsg) : null;
                        // Already recorded by this attempt (a repeat after a lost acknowledgement): report the stored retry time.
                        return $stored === null ? [false, $retryAfter] : [true, $stored['retry_after'] ?? $retryAfter];
                    }
                );

                if ($recorded) {
                    $this->logger->info('Email job scheduled for retry', [
                        'job_id'      => $id,
                        'retry_after' => $retryAfter,
                        'delay_secs'  => $delaySecs,
                    ]);
                }
            }
        } catch (Throwable $failure) {
            // The same rule as for the sent write: only a lost connection stops the batch.
            $connectionLost = $this->outcomeWriteLostTheConnection($failure);
            $this->logger->error('Email job failure was not recorded', [
                'job_id'          => $id,
                'attempt'         => $attempts,
                'connection_lost' => $connectionLost,
                'reason'          => $failure->getMessage(),
            ]);
            if ($connectionLost) {
                throw $failure;
            }
            return;
        }

        if (!$recorded) {
            $this->logger->warning('Email job outcome not recorded: the row is no longer processing under this attempt', [
                'job_id'  => $id,
                'attempt' => $attempts,
            ]);
        }
    }

    /**
     * Runs statements that are safe to repeat: single autocommit writes and reads, never part of a transaction.
     * When the server has closed the connection, it reconnects through the factory and runs them exactly once more.
     *
     * @template T
     * @param Closure(PDO, bool): T $operation receives the connection and whether this is the repeat after a reconnect
     * @return T
     */
    private function withReconnect(Closure $operation): mixed
    {
        try {
            $pdo = $this->connection ?? $this->reconnect();
            return $this->onPossiblyClosedConnection(static fn (): mixed => $operation($pdo, false));
        } catch (PDOException $failure) {
            if ($this->connectionFactory === null || !self::isConnectionLost($failure)) {
                throw $failure;
            }
            $this->logger->warning('Email queue connection was closed by the server; reconnecting once', [
                'error_code' => $failure->errorInfo[1] ?? null,
            ]);
            $fresh = $this->reconnect();
            // The repeat: only here can an earlier execution have applied with its acknowledgement lost.
            return $this->onPossiblyClosedConnection(static fn (): mixed => $operation($fresh, true));
        }
    }

    /**
     * Runs statements on a connection the server may have closed while it idled. Before PHP 8.4, mysqlnd
     * also raises an E_WARNING such as "Packets out of order" when it reads the server's disconnect, and an
     * application error handler that turns warnings into exceptions would hide the PDOException behind it.
     * Such warnings are held back while the statements run. They are dropped when a PDOException reports
     * the lost connection, which the worker logs and handles; otherwise they are logged, and the original
     * exception or result passes through unchanged. Every other error goes to the previously installed
     * handler unchanged.
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    private function onPossiblyClosedConnection(Closure $operation): mixed
    {
        $held = [];
        $previous = null;
        // Registered for every level so the scope stays transparent: only an engine E_WARNING with a
        // disconnect symptom is held; anything else goes to the application's handler as if this one were
        // not there, and only that handler's explicit false hands it on to PHP's standard handler.
        $previous = set_error_handler(
            static function (int $level, string $message, string $file = '', int $line = 0) use (&$held, &$previous): bool {
                if ($level === E_WARNING && self::isDisconnectWarning($message)) {
                    $held[] = $message;
                    return true;
                }
                return is_callable($previous) && $previous($level, $message, $file, $line) !== false;
            }
        );
        try {
            return $operation();
        } catch (PDOException $failure) {
            if (self::isConnectionLost($failure)) {
                $held = [];
            }
            throw $failure;
        } finally {
            restore_error_handler();
            // Never raised again: an application handler that throws on warnings would replace the original
            // exception or result. A held warning is recorded through the worker's logger instead.
            foreach ($held as $message) {
                try {
                    $this->logger->warning('Database driver warning during an email queue statement', ['warning' => $message]);
                } catch (Throwable) {
                    // The original exception or result always wins over a failing logger.
                }
            }
        }
    }

    private static function isDisconnectWarning(string $message): bool
    {
        foreach (['Packets out of order', 'server has gone away', 'Error while sending', 'Error while reading', "Error reading result set's header"] as $symptom) {
            if (str_contains($message, $symptom)) {
                return true;
            }
        }
        return false;
    }

    /** Confirms the connection is open before the claim transaction starts, replacing it when the server closed it. */
    private function connectionForClaim(): PDO
    {
        if ($this->connection === null) {
            return $this->reconnect();
        }
        if ($this->connectionFactory === null) {
            if ($this->autocommitConfirmed !== $this->connection) {
                self::requireAutocommit($this->connection);
                $this->autocommitConfirmed = $this->connection;
            }
            return $this->connection;
        }

        $connection = $this->connection;
        try {
            $probe = $this->onPossiblyClosedConnection(static fn (): ?\PDOStatement => $connection->query('SELECT 1') ?: null);
            $probe?->closeCursor();
            return $connection;
        } catch (PDOException $failure) {
            if (!self::isConnectionLost($failure)) {
                throw $failure;
            }
            $this->logger->warning('Email queue connection was closed by the server; reconnecting before the claim', [
                'error_code' => $failure->errorInfo[1] ?? null,
            ]);
            return $this->reconnect();
        }
    }

    private function reconnect(): PDO
    {
        if ($this->connectionFactory === null) {
            throw new \LogicException('A PDO instance cannot be replaced; pass a connection factory to reconnect.');
        }
        $this->connection = null;
        $connection = ($this->connectionFactory)();
        if (!$connection instanceof PDO) {
            throw new UnexpectedValueException('The email queue connection factory must return a PDO instance.');
        }
        if ($connection->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new UnexpectedValueException('The email queue connection factory must return a PDO in exception error mode.');
        }
        self::requireAutocommit($connection);
        return $this->connection = $connection;
    }

    /**
     * Status writes must commit on their own: with autocommit off, a write after a send could stay uncommitted
     * and be lost with the connection. Checked before the connection claims or writes anything; the worker
     * never changes the setting itself. Production storage is MySQL/MariaDB; other drivers back legacy tests.
     */
    private static function requireAutocommit(PDO $pdo): void
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return;
        }
        $statement = $pdo->query('SELECT @@SESSION.autocommit');
        $autocommit = $statement === false ? null : $statement->fetchColumn();
        if ($statement !== false) {
            $statement->closeCursor();
        }
        if (!in_array($autocommit, [1, '1'], true)) {
            throw new UnexpectedValueException('The email queue worker requires a connection with autocommit enabled (@@SESSION.autocommit = 1).');
        }
    }

    /**
     * The one rule for an outcome write that failed even after its reconnect. A lost connection, or a
     * reconnect that itself failed (no connection is left), stops the batch: no further mail goes out
     * while outcomes cannot be recorded, and the caller handles the dead link. Any other error leaves
     * the row `processing` for its lease and lets the batch go on.
     */
    private function outcomeWriteLostTheConnection(Throwable $failure): bool
    {
        return $this->connection === null
            || ($failure instanceof PDOException && self::isConnectionLost($failure));
    }

    private static function isConnectionLost(PDOException $failure): bool
    {
        return in_array($failure->errorInfo[1] ?? null, self::CONNECTION_LOST_CODES, true);
    }

    /**
     * Consulted only for the repeat after a reconnect, when the fenced write matched no row: the row holds this
     * attempt's own outcome (same status, attempt number and error message) only if the first execution
     * applied and its acknowledgement was lost. A row another batch settled or rescheduled carries its own
     * error message (for example processing_lease_expired at the attempt cap, which keeps the attempt
     * number), so it never counts as this attempt's outcome.
     *
     * @return array{retry_after: string|null}|null
     */
    private static function storedOutcome(PDO $pdo, string $table, int $id, int $attempt, string $status, string $error): ?array
    {
        $statement = $pdo->prepare(
            "SELECT retry_after FROM `$table` WHERE id = :id AND status = :status AND attempts = :attempt AND last_error = :error"
        );
        $statement->execute(['id' => $id, 'status' => $status, 'attempt' => $attempt, 'error' => $error]);
        /** @var array{retry_after: string|null}|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $statement->closeCursor();
        return $row === false ? null : $row;
    }

    /**
     * An expression that stores the current instant exactly in a TIMESTAMP column, in any session zone. A local
     * time is ambiguous in the repeated hour after a daylight saving fall-back: there, MySQL stores a local
     * NOW() as the earlier of the two instants (one hour early), while MariaDB stores a directly assigned
     * NOW() exactly. MySQL 8.0.19+ stores a UTC value with an explicit offset exactly. Read from the
     * connection attributes, without a query.
     */
    private static function instantStamp(PDO $pdo): string
    {
        $version = $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $version = is_string($version) ? $version : '';
        if (!str_contains(strtolower($version), 'mariadb') && version_compare(explode('-', $version)[0], '8.0.19', '>=')) {
            return "CONCAT(UTC_TIMESTAMP(), '+00:00')";
        }
        return 'NOW()';
    }

    /**
     * Column facts the batch depends on, read from result metadata.
     *
     * @return array{binding: bool, timestampLease: bool}
     */
    private function queueColumns(PDO $pdo): array
    {
        $table = self::TABLE;
        $statement = $pdo->query("SELECT * FROM `$table` LIMIT 0");
        if ($statement === false || $statement->columnCount() === 0) {
            throw new \RuntimeException('Email queue column metadata is unavailable.');
        }
        try {
            $hasBinding = false;
            $timestampLease = false;
            for ($index = 0; $index < $statement->columnCount(); ++$index) {
                $metadata = $statement->getColumnMeta($index);
                if ($metadata === false) {
                    throw new \RuntimeException('Email queue column metadata is unavailable.');
                }
                $hasBinding = $hasBinding || $metadata['name'] === 'enqueue_receipt_id';
                $timestampLease = $timestampLease
                    || ($metadata['name'] === 'updated_at' && ($metadata['native_type'] ?? null) === 'TIMESTAMP');
            }
            return ['binding' => $hasBinding, 'timestampLease' => $timestampLease];
        } finally {
            $statement->closeCursor();
        }
    }

    private function keyedRetryAfter(PDO $pdo, int $delaySeconds): string
    {
        $statement = $pdo->prepare("SELECT DATE_FORMAT(DATE_ADD(UTC_TIMESTAMP(), INTERVAL :delay SECOND), '%Y-%m-%d %H:%i:%s')");
        if ($statement === false) {
            throw new \RuntimeException('The keyed email retry clock is unavailable.');
        }
        $statement->bindValue(':delay', $delaySeconds, PDO::PARAM_INT);
        if ($statement->execute() !== true) {
            throw new \RuntimeException('The keyed email retry clock is unavailable.');
        }
        $retryAfter = $statement->fetchColumn();
        $statement->closeCursor();
        if (!is_string($retryAfter) || $retryAfter === '') {
            throw new \RuntimeException('The keyed email retry clock is unavailable.');
        }
        return $retryAfter;
    }

}
