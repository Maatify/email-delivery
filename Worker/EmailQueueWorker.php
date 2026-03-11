<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Worker;

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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @phpstan-type EmailQueueRow array{
 *   id: int|string,
 *   recipient_encrypted: string,
 *   recipient_iv: string,
 *   recipient_tag: string,
 *   recipient_key_id: string,
 *   payload_encrypted: string,
 *   payload_iv: string,
 *   payload_tag: string,
 *   payload_key_id: string,
 *   attempts: int|string
 * }
 */
final readonly class EmailQueueWorker
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

    public function __construct(
        private PDO                            $pdo,
        private CryptoProvider                 $cryptoProvider,
        private EmailRendererInterface         $renderer,
        private EmailTransportInterface        $transport,
        private CryptoContextProviderInterface $cryptoContextProvider,
        private LoggerInterface                $logger,
    ) {
    }

    public function processBatch(int $limit = 50): void
    {
        $this->pdo->beginTransaction();

        try {
            $table = self::TABLE;

            $stmt = $this->pdo->prepare("
                SELECT
                    id,
                    recipient_encrypted, recipient_iv, recipient_tag, recipient_key_id,
                    payload_encrypted, payload_iv, payload_tag, payload_key_id,
                    attempts
                FROM `$table`
                WHERE status = 'pending'
                  AND scheduled_at <= NOW()
                  AND (retry_after IS NULL OR retry_after <= NOW())
                ORDER BY priority ASC, id ASC
                LIMIT :limit
                FOR UPDATE
            ");

            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            /** @var list<EmailQueueRow> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($rows === []) {
                $this->pdo->commit();
                return;
            }

            $ids          = array_map(static fn (array $row): int => (int) $row['id'], $rows);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));

            $this->pdo->prepare("
                UPDATE `$table`
                SET status   = 'processing',
                    attempts = attempts + 1
                WHERE id IN ($placeholders)
            ")->execute($ids);

            $this->pdo->commit();

        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        foreach ($rows as $row) {
            $this->processRow($row);
        }
    }

    /**
     * @param EmailQueueRow $row
     */
    private function processRow(array $row): void
    {
        $id       = (int) $row['id'];
        $attempts = (int) $row['attempts'] + 1;
        $table    = self::TABLE;

        try {
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
                /** @var array{context: array<string, mixed>, templateKey: string, language: string} $payloadData */
                $payloadData = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new \RuntimeException('invalid_payload_format', 0, $e);
            }

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
                $this->transport->send($recipient, $renderedEmail);
            } catch (EmailTransportException $e) {
                throw new \RuntimeException('smtp_transport_error', 0, $e);
            }

            // ── Mark sent ─────────────────────────────────────
            $this->pdo->prepare(
                "UPDATE `$table` SET status = 'sent', sent_at = NOW() WHERE id = :id"
            )->execute(['id' => $id]);

            $this->logger->info('Email sent', ['job_id' => $id]);

        } catch (Throwable $e) {
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

            // ── Exponential backoff or permanent fail ─────────
            if ($attempts >= self::MAX_ATTEMPTS) {
                $this->pdo->prepare(
                    "UPDATE `$table` SET status = 'failed', last_error = :error WHERE id = :id"
                )->execute(['error' => $errorMsg, 'id' => $id]);

                $this->logger->warning('Email job permanently failed', [
                    'job_id'  => $id,
                    'reason'  => $rootCause,
                ]);
            } else {
                $delaySecs  = $backoff[$attempts] ?? 3600;
                $retryAfter = date('Y-m-d H:i:s', time() + $delaySecs);

                $this->pdo->prepare("
                    UPDATE `$table`
                    SET status      = 'pending',
                        last_error  = :error,
                        retry_after = :retry_after
                    WHERE id = :id
                ")->execute(['error' => $errorMsg, 'retry_after' => $retryAfter, 'id' => $id]);

                $this->logger->info('Email job scheduled for retry', [
                    'job_id'      => $id,
                    'retry_after' => $retryAfter,
                    'delay_secs'  => $delaySecs,
                ]);
            }
        }
    }
}