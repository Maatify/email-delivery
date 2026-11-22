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
            $hasBinding = $this->hasReceiptBinding($pdo);
            $bindingColumn = $hasBinding ? ', enqueue_receipt_id' : '';
            // Each row keeps its own clock for due times and for its processing lease.
            $dueClock = $hasBinding
                ? 'CASE WHEN enqueue_receipt_id IS NULL THEN NOW() ELSE UTC_TIMESTAMP() END'
                : 'NOW()';

            $stmt = $pdo->prepare("
                SELECT
                    id, status,
                    recipient_encrypted, recipient_iv, recipient_tag, recipient_key_id,
                    payload_encrypted, payload_iv, payload_tag, payload_key_id,
                    attempts$bindingColumn
                FROM `$table`
                WHERE (
                        status = 'pending'
                        AND scheduled_at <= $dueClock
                        AND (retry_after IS NULL OR retry_after <= $dueClock)
                    )
                   OR (
                        status = 'processing'
                        AND updated_at < DATE_SUB($dueClock, INTERVAL :lease SECOND)
                    )
                ORDER BY priority ASC, id ASC
                LIMIT :limit
                FOR UPDATE
            ");

            $stmt->bindValue(':lease', $this->processingLeaseSeconds, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<EmailQueueRow> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                        updated_at = $dueClock
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
                        updated_at = $dueClock
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
            $this->processRow($row);
        }
    }

    /**
     * @param EmailQueueRow $row
     */
    private function processRow(array $row): void
    {
        $id      = (int) $row['id'];
        $attempt = (int) $row['attempts'] + 1;
        $keyed   = isset($row['enqueue_receipt_id']);
        $clock   = $keyed ? 'UTC_TIMESTAMP()' : 'NOW()';

        if (!$this->renewLease($id, $attempt, $clock)) {
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
     * A connection still lost after one reconnect is rethrown: the batch stops, no further mail goes out
     * while outcomes cannot be recorded, and the caller handles the dead link. Any other error continues
     * with the next row.
     */
    private function recordSent(int $id, int $attempt, string $clock): void
    {
        $table = self::TABLE;

        try {
            // Not fenced by attempt: the email went out, so `sent` wins even if another batch reclaimed or settled the row.
            $this->withReconnect(static function (PDO $pdo) use ($table, $id, $clock): void {
                $pdo->prepare(
                    "UPDATE `$table` SET status = 'sent', sent_at = $clock WHERE id = :id"
                )->execute(['id' => $id]);
            });
        } catch (Throwable $failure) {
            // A null connection means the reconnect itself failed.
            $connectionLost = $this->connection === null
                || ($failure instanceof PDOException && self::isConnectionLost($failure));
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
        $errorMsg  = substr($errorCode . ': ' . $rootCause, 0, 128);

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
                $recorded = $this->withReconnect(static function (PDO $pdo) use ($table, $errorMsg, $id, $attempts): bool {
                    $statement = $pdo->prepare(
                        "UPDATE `$table` SET status = 'failed', last_error = :error
                         WHERE id = :id AND status = 'processing' AND attempts = :attempt"
                    );
                    $statement->execute(['error' => $errorMsg, 'id' => $id, 'attempt' => $attempts]);
                    return $statement->rowCount() === 1;
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
                    function (PDO $pdo) use ($table, $errorMsg, $id, $attempts, $keyed, $delaySecs): array {
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
                        return [$statement->rowCount() === 1, $retryAfter];
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
            $this->logger->error('Email job failure was not recorded', [
                'job_id' => $id,
                'reason' => $failure->getMessage(),
            ]);
            throw $failure;
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
     * @param Closure(PDO): T $operation
     * @return T
     */
    private function withReconnect(Closure $operation): mixed
    {
        try {
            $pdo = $this->connection ?? $this->reconnect();
            return self::onPossiblyClosedConnection(static fn (): mixed => $operation($pdo));
        } catch (PDOException $failure) {
            if ($this->connectionFactory === null || !self::isConnectionLost($failure)) {
                throw $failure;
            }
            $this->logger->warning('Email queue connection was closed by the server; reconnecting once', [
                'error_code' => $failure->errorInfo[1] ?? null,
            ]);
            $fresh = $this->reconnect();
            return self::onPossiblyClosedConnection(static fn (): mixed => $operation($fresh));
        }
    }

    /**
     * Runs statements on a connection the server may have closed while it idled. Before PHP 8.4, mysqlnd
     * also raises an E_WARNING such as "Packets out of order" when it reads the server's disconnect, and an
     * application error handler that turns warnings into exceptions would hide the PDOException behind it.
     * Such warnings are held back while the statements run. They are dropped when a PDOException reports
     * the lost connection, which the worker logs and handles, and raised again otherwise. Every other
     * warning goes to the previously installed handler unchanged.
     *
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    private static function onPossiblyClosedConnection(Closure $operation): mixed
    {
        $held = [];
        $previous = null;
        $previous = set_error_handler(
            static function (int $level, string $message, string $file = '', int $line = 0) use (&$held, &$previous): bool {
                if (self::isDisconnectWarning($message)) {
                    $held[] = $message;
                    return true;
                }
                return is_callable($previous) && (bool) $previous($level, $message, $file, $line);
            },
            E_WARNING
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
            foreach ($held as $message) {
                trigger_error($message, E_USER_WARNING);
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
            return $this->connection;
        }

        $connection = $this->connection;
        try {
            $probe = self::onPossiblyClosedConnection(static fn (): ?\PDOStatement => $connection->query('SELECT 1') ?: null);
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
        return $this->connection = $connection;
    }

    private static function isConnectionLost(PDOException $failure): bool
    {
        return in_array($failure->errorInfo[1] ?? null, self::CONNECTION_LOST_CODES, true);
    }

    private function hasReceiptBinding(PDO $pdo): bool
    {
        $table = self::TABLE;
        $statement = $pdo->query("SELECT * FROM `$table` LIMIT 0");
        if ($statement === false || $statement->columnCount() === 0) {
            throw new \RuntimeException('Email queue column metadata is unavailable.');
        }
        try {
            $hasBinding = false;
            for ($index = 0; $index < $statement->columnCount(); ++$index) {
                $metadata = $statement->getColumnMeta($index);
                if ($metadata === false) {
                    throw new \RuntimeException('Email queue column metadata is unavailable.');
                }
                $hasBinding = $hasBinding || $metadata['name'] === 'enqueue_receipt_id';
            }
            return $hasBinding;
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
