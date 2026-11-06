<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Synthetic;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Maatify\Crypto\Contract\CryptoContextProviderInterface;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\Crypto\Reversible\DTO\ReversibleCryptoEncryptionResultDTO;
use Maatify\Crypto\Reversible\ReversibleCryptoAlgorithmEnum;
use Maatify\EmailDelivery\Exception\SyntheticEmailUnavailableException;
use Maatify\EmailDelivery\Exception\SyntheticEmailTransactionUnresolvedException;
use Maatify\EmailDelivery\Queue\Internal\EmailMessageSnapshot;
use Maatify\EmailDelivery\Queue\Internal\EmailMessageSnapshotFactory;
use Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRequestDTO;
use Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRecordDTO;
use Maatify\EmailDelivery\Synthetic\Internal\SyntheticEmailContexts;
use Maatify\EmailDelivery\Synthetic\Internal\SyntheticEmailSchemaValidator;
use PDO;
use PDOStatement;
use Throwable;

/** Append-only inert storage. No worker, real queue, receipts, HTTP or timing guarantee. */
final readonly class PdoSyntheticEmailStore implements SyntheticEmailRecorderInterface, SyntheticEmailMaintenanceInterface
{
    private string $recipientContext;
    private string $payloadContext;

    public function __construct(
        private PDO $pdo,
        private CryptoProvider $cryptoProvider,
        CryptoContextProviderInterface $cryptoContextProvider,
        private int $retentionSeconds,
    ) {
        if ($retentionSeconds < 1 || $retentionSeconds > 2147483647) {
            throw new InvalidArgumentException('Explicit retention must be between 1 and 2147483647 seconds.');
        }
        $recipient = trim($cryptoContextProvider->emailQueueRecipient());
        $payload = trim($cryptoContextProvider->emailQueuePayload());
        $this->recipientContext = SyntheticEmailContexts::recipient($recipient);
        $this->payloadContext = SyntheticEmailContexts::payload($payload);
        if (in_array($this->recipientContext, [$recipient, $payload], true)
            || in_array($this->payloadContext, [$recipient, $payload], true)) {
            throw new InvalidArgumentException('Synthetic crypto contexts must differ from real email contexts.');
        }
    }

    public function record(SyntheticEmailRequestDTO $request): SyntheticEmailRecordDTO
    {
        if ($request->consumerNamespace === '' || strlen($request->consumerNamespace) > 128) {
            throw new InvalidArgumentException('Synthetic consumer namespace length is invalid.');
        }
        $message = EmailMessageSnapshotFactory::snapshot(new EmailMessageSnapshot(
            $request->entityType, $request->entityId, $request->recipientEmail, $request->payload,
            $request->senderType, $request->priority, $request->scheduledAt,
        ));
        try {
            $recipient = $this->encrypted($message->recipientEmail, $this->recipientContext);
            $payload = $this->encrypted(json_encode($message->payload->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $this->payloadContext);
            $recordId = bin2hex(random_bytes(16));
            return $this->transaction(function () use ($request, $message, $recipient, $payload, $recordId): SyntheticEmailRecordDTO {
                $statement = $this->prepare('INSERT INTO cd_email_synthetic_requests
                    (record_id, consumer_namespace, entity_type, entity_id,
                     recipient_encrypted, recipient_iv, recipient_tag, recipient_key_id,
                     payload_encrypted, payload_iv, payload_tag, payload_key_id,
                     template_key, language, sender_type, priority, requested_scheduled_at, recorded_at, expires_at)
                    VALUES (:record_id, :namespace, :entity_type, :entity_id,
                     :recipient_encrypted, :recipient_iv, :recipient_tag, :recipient_key_id,
                     :payload_encrypted, :payload_iv, :payload_tag, :payload_key_id,
                     :template_key, :language, :sender_type, :priority, :scheduled_at,
                     UTC_TIMESTAMP(6), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL :retention SECOND))');
                $this->execute($statement, [
                    'record_id' => $recordId, 'namespace' => $request->consumerNamespace,
                    'entity_type' => $message->entityType, 'entity_id' => $message->entityId,
                    'recipient_encrypted' => $recipient['cipher'], 'recipient_iv' => $recipient['iv'],
                    'recipient_tag' => $recipient['tag'], 'recipient_key_id' => $recipient['key_id'],
                    'payload_encrypted' => $payload['cipher'], 'payload_iv' => $payload['iv'],
                    'payload_tag' => $payload['tag'], 'payload_key_id' => $payload['key_id'],
                    'template_key' => $message->payload->templateKey, 'language' => $message->payload->language,
                    'sender_type' => $message->senderType, 'priority' => $message->priority,
                    'scheduled_at' => $message->scheduledAt?->format('Y-m-d H:i:s'), 'retention' => $this->retentionSeconds,
                ]);
                if ($statement->rowCount() !== 1) {
                    throw new SyntheticEmailUnavailableException('Synthetic insert did not create one row.');
                }
                $read = $this->prepare('SELECT record_id, recorded_at, expires_at FROM cd_email_synthetic_requests WHERE record_id = :record_id FOR UPDATE');
                $this->execute($read, ['record_id' => $recordId]);
                /** @var array<string, mixed>|false $row */
                $row = $read->fetch(PDO::FETCH_ASSOC);
                if ($row === false || ($row['record_id'] ?? null) !== $recordId) {
                    throw new SyntheticEmailUnavailableException('Synthetic record readback is invalid.');
                }
                $recorded = self::date($row['recorded_at'] ?? null);
                $expires = self::date($row['expires_at'] ?? null);
                if ($expires != $recorded->modify('+' . $this->retentionSeconds . ' seconds')) {
                    throw new SyntheticEmailUnavailableException('Synthetic expiry readback is invalid.');
                }
                return new SyntheticEmailRecordDTO($recordId, $recorded->format('Y-m-d\TH:i:s.u\Z'), $expires->format('Y-m-d\TH:i:s.u\Z'));
            });
        } catch (SyntheticEmailUnavailableException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new SyntheticEmailUnavailableException('Synthetic recording is unavailable.', 0, $failure);
        }
    }

    public function purgeExpired(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Cleanup limit must be between 1 and 1000.');
        }
        try {
            return $this->transaction(function () use ($limit): int {
                // Integer range checked above; no caller SQL text or unbounded deletion.
                $statement = $this->prepare('DELETE FROM cd_email_synthetic_requests WHERE expires_at <= UTC_TIMESTAMP(6) ORDER BY expires_at, id LIMIT ' . $limit);
                $this->execute($statement, []);
                $count = $statement->rowCount();
                if ($count > $limit) {
                    throw new SyntheticEmailUnavailableException('Synthetic cleanup count is invalid.');
                }
                return $count;
            });
        } catch (SyntheticEmailUnavailableException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new SyntheticEmailUnavailableException('Synthetic cleanup is unavailable.', 0, $failure);
        }
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC')) : false;
        if ($date === false || $date->format('Y-m-d H:i:s.u') !== $value) {
            throw new SyntheticEmailUnavailableException('Synthetic UTC time is invalid.');
        }
        return $date;
    }

    /** @return array{cipher: string, iv: string, tag: string, key_id: string, algorithm: string} */
    private function encrypted(string $plain, string $context): array
    {
        /** @var array{result: ReversibleCryptoEncryptionResultDTO, key_id: string, algorithm: ReversibleCryptoAlgorithmEnum} $encrypted */
        $encrypted = $this->cryptoProvider->context($context)->encrypt($plain);
        $result = $encrypted['result'];
        if ($result->iv === null || $result->tag === null || $encrypted['algorithm'] !== ReversibleCryptoAlgorithmEnum::AES_256_GCM) {
            throw new SyntheticEmailUnavailableException('Authenticated encryption metadata is unavailable.');
        }
        return ['cipher' => $result->cipher, 'iv' => $result->iv, 'tag' => $result->tag,
            'key_id' => $encrypted['key_id'], 'algorithm' => $encrypted['algorithm']->value];
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new SyntheticEmailUnavailableException('Synthetic statement was not prepared.');
        }
        return $statement;
    }

    /** @param array<string, int|string|null> $parameters */
    private function execute(PDOStatement $statement, array $parameters): void
    {
        if ($statement->execute($parameters) !== true) {
            throw new SyntheticEmailUnavailableException('Synthetic statement did not execute.');
        }
    }

    /** @phpstan-impure */
    private function active(): bool
    {
        return $this->pdo->inTransaction();
    }

    /**
     * @template T
     * @param Closure(): T $operation
     * @return T
     */
    private function transaction(Closure $operation): mixed
    {
        // These checks precede BEGIN and do not read application tables or alter caller state.
        if ($this->active() || $this->pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new SyntheticEmailUnavailableException('An idle ERRMODE_EXCEPTION connection is required.');
        }
        $settings = $this->pdo->query('SELECT @@SESSION.autocommit AS autocommit, @@SESSION.time_zone AS time_zone');
        if ($settings === false) {
            throw new SyntheticEmailUnavailableException('Connection settings are unavailable.');
        }
        /** @var array<string, mixed>|false $values */
        $values = $settings->fetch(PDO::FETCH_ASSOC);
        if ($values === false || !in_array($values['autocommit'] ?? null, [1, '1'], true)
            || !in_array($values['time_zone'] ?? null, ['+00:00', 'UTC'], true)) {
            throw new SyntheticEmailUnavailableException('Autocommit and an explicit UTC session are required.');
        }
        $owns = false;
        $commitAttempted = false;
        try {
            if ($this->pdo->beginTransaction() !== true) {
                throw new SyntheticEmailUnavailableException('Synthetic transaction did not begin.');
            }
            $owns = true;
            SyntheticEmailSchemaValidator::validate($this->pdo);
            $result = $operation();
            $commitAttempted = true;
            if ($this->pdo->commit() !== true || $this->active()) {
                throw new SyntheticEmailTransactionUnresolvedException('commit');
            }
            return $result;
        } catch (Throwable $failure) {
            try {
                $active = $this->active();
                if ($owns && $active && $this->pdo->rollBack() !== true) {
                    throw new SyntheticEmailTransactionUnresolvedException('rollback', $failure);
                }
                if ($this->active() || (!$owns && $active)) {
                    throw new SyntheticEmailTransactionUnresolvedException('begin_or_cleanup', $failure);
                }
            } catch (SyntheticEmailTransactionUnresolvedException $unresolved) {
                throw $unresolved;
            } catch (Throwable) {
                throw new SyntheticEmailTransactionUnresolvedException('cleanup', $failure);
            }
            if ($commitAttempted) {
                throw new SyntheticEmailTransactionUnresolvedException('commit', $failure);
            }
            if ($failure instanceof SyntheticEmailUnavailableException) {
                throw $failure;
            }
            throw new SyntheticEmailUnavailableException('Synthetic operation is unavailable.', 0, $failure);
        }
    }
}
