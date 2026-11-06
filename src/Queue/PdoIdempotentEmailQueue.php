<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Queue;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Maatify\Crypto\Contract\CryptoContextProviderInterface;
use Maatify\Crypto\DX\CryptoProvider;
use Maatify\Crypto\Reversible\DTO\ReversibleCryptoEncryptionResultDTO;
use Maatify\Crypto\Reversible\DTO\ReversibleCryptoMetadataDTO;
use Maatify\Crypto\Reversible\ReversibleCryptoAlgorithmEnum;
use Maatify\EmailDelivery\Exception\EmailEnqueueConflictException;
use Maatify\EmailDelivery\Exception\EmailEnqueueTransactionUnresolvedException;
use Maatify\EmailDelivery\Exception\EmailEnqueueUnavailableException;
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueReceiptDTO;
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO;
use Maatify\EmailDelivery\Queue\Enum\EmailQueueState;
use Maatify\EmailDelivery\Queue\Internal\EmailEnqueueCanonicalizer;
use Maatify\EmailDelivery\Queue\Internal\EmailEnqueueSnapshot;
use Maatify\EmailDelivery\Queue\Internal\EmailEnqueueSchemaValidator;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * Additive keyed enqueue. Receipt identity survives queue deletion indefinitely.
 * Requires InnoDB, ERRMODE_EXCEPTION, autocommit=1 and session time_zone UTC.
 * Owns every transaction; never commits or rolls back a caller transaction.
 */
final readonly class PdoIdempotentEmailQueue implements IdempotentEmailQueueWriterInterface, EmailEnqueueReceiptReaderInterface
{
    public function __construct(
        private PDO $pdo,
        private CryptoProvider $cryptoProvider,
        private CryptoContextProviderInterface $cryptoContextProvider,
        private string $fingerprintContext,
    ) {
        if ($fingerprintContext === '') {
            throw new \InvalidArgumentException('A versioned fingerprint crypto context is required.');
        }
    }

    public function enqueueOnce(EmailEnqueueRequestDTO $request): EmailEnqueueReceiptDTO
    {
        $snapshot = EmailEnqueueCanonicalizer::snapshot($request, new DateTimeImmutable('now', new DateTimeZone('UTC')));
        // Encrypt only a digest, not the logical payload; no offline plaintext OTP verifier at rest.
        try {
            $fingerprint = $this->encrypted($snapshot->digest, $this->fingerprintContext);
            return $this->transaction(function () use ($snapshot, $fingerprint): EmailEnqueueReceiptDTO {
                $request = $snapshot->request;
                $new = true;
                $newJobId = null;
                $receiptId = bin2hex(random_bytes(16));
                try {
                    $statement = $this->prepare('INSERT INTO cd_email_enqueue_receipts
                    (consumer_namespace, request_key, receipt_id, job_id, accepted_at, fingerprint_version,
                     fingerprint_encrypted, fingerprint_iv, fingerprint_tag, fingerprint_key_id, fingerprint_algorithm)
                    VALUES (:namespace, :request_key, :receipt_id, NULL, :accepted_at, 1,
                            :cipher, :iv, :tag, :key_id, :algorithm)');
                    $this->execute($statement, [
                        'namespace' => $request->consumerNamespace, 'request_key' => $request->requestKey,
                        'receipt_id' => $receiptId, 'accepted_at' => $snapshot->acceptedAt->format('Y-m-d H:i:s.u'),
                        'cipher' => $fingerprint['cipher'], 'iv' => $fingerprint['iv'], 'tag' => $fingerprint['tag'],
                        'key_id' => $fingerprint['key_id'], 'algorithm' => $fingerprint['algorithm'],
                    ]);
                    if ($statement->rowCount() !== 1) {
                        throw new EmailEnqueueUnavailableException('Receipt insert did not create one row.');
                    }
                } catch (PDOException $failure) {
                    if ($failure->getCode() !== '23000' || ($failure->errorInfo[1] ?? null) !== 1062) {
                        throw $failure;
                    }
                    // A duplicate is a replay ONLY if this exact namespace/key has a current winner.
                    $new = false;
                }
                if ($new) {
                    $jobId = $this->insertQueue($snapshot, $receiptId);
                    $newJobId = $jobId;
                    $link = $this->prepare('UPDATE cd_email_enqueue_receipts SET job_id = :job_id
                    WHERE consumer_namespace = :namespace AND request_key = :request_key AND job_id IS NULL');
                    $this->execute($link, ['job_id' => $jobId, 'namespace' => $request->consumerNamespace, 'request_key' => $request->requestKey]);
                    if ($link->rowCount() !== 1) {
                        throw new EmailEnqueueUnavailableException('Receipt could not be linked to its queue job.');
                    }
                }
                $row = $this->current($request->consumerNamespace, $request->requestKey);
                if ($row === null) {
                    throw new EmailEnqueueUnavailableException('No current receipt winner exists.');
                }
                if ($new && (self::field($row, 'receipt_id') !== $receiptId
                    || self::field($row, 'job_id') !== $newJobId || ($row['queue_status'] ?? null) === null)) {
                    throw new EmailEnqueueUnavailableException('New receipt does not identify its inserted queue job.');
                }
                $this->verifyFingerprint($row, $snapshot->digest);
                return $this->receipt($row);
            });
        } catch (EmailEnqueueConflictException|EmailEnqueueUnavailableException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new EmailEnqueueUnavailableException('Enqueue operation is unavailable.', 0, $failure);
        }
    }

    public function find(string $consumerNamespace, string $requestKey): ?EmailEnqueueReceiptDTO
    {
        EmailEnqueueCanonicalizer::identity($consumerNamespace, $requestKey);
        try {
            return $this->transaction(function () use ($consumerNamespace, $requestKey): ?EmailEnqueueReceiptDTO {
                $row = $this->current($consumerNamespace, $requestKey);
                return $row === null ? null : $this->receipt($row);
            });
        } catch (EmailEnqueueUnavailableException $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new EmailEnqueueUnavailableException('Receipt query is unavailable.', 0, $failure);
        }
    }

    /** @return array{cipher: string, iv: string, tag: string, key_id: string, algorithm: string} */
    private function encrypted(string $plain, string $context): array
    {
        /** @var array{result: ReversibleCryptoEncryptionResultDTO, key_id: string, algorithm: ReversibleCryptoAlgorithmEnum} $encrypted */
        $encrypted = $this->cryptoProvider->context($context)->encrypt($plain);
        $result = $encrypted['result'];
        if ($result->iv === null || $result->tag === null || $encrypted['algorithm'] !== ReversibleCryptoAlgorithmEnum::AES_256_GCM) {
            throw new EmailEnqueueUnavailableException('Authenticated encryption metadata is unavailable.');
        }
        return ['cipher' => $result->cipher, 'iv' => $result->iv, 'tag' => $result->tag,
            'key_id' => $encrypted['key_id'], 'algorithm' => $encrypted['algorithm']->value];
    }

    private function insertQueue(EmailEnqueueSnapshot $snapshot, string $receiptId): string
    {
        $request = $snapshot->request;
        $recipient = $this->encrypted($request->recipientEmail, $this->cryptoContextProvider->emailQueueRecipient());
        $payload = $this->encrypted(json_encode($request->payload->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $this->cryptoContextProvider->emailQueuePayload());
        $statement = $this->prepare('INSERT INTO cd_email_queue
            (entity_type, entity_id, enqueue_receipt_id, recipient_encrypted, recipient_iv, recipient_tag, recipient_key_id,
             payload_encrypted, payload_iv, payload_tag, payload_key_id, template_key, language, sender_type,
             priority, status, attempts, last_error, scheduled_at, created_at, updated_at)
            VALUES (:entity_type, :entity_id, :enqueue_receipt_id, :recipient_encrypted, :recipient_iv, :recipient_tag, :recipient_key_id,
                    :payload_encrypted, :payload_iv, :payload_tag, :payload_key_id, :template_key, :language,
                    :sender_type, :priority, \'pending\', 0, \'\', :scheduled_at, :created_at, :updated_at)');
        $this->execute($statement, [
            'entity_type' => $request->entityType, 'entity_id' => $request->entityId, 'enqueue_receipt_id' => $receiptId,
            'recipient_encrypted' => $recipient['cipher'], 'recipient_iv' => $recipient['iv'],
            'recipient_tag' => $recipient['tag'], 'recipient_key_id' => $recipient['key_id'],
            'payload_encrypted' => $payload['cipher'], 'payload_iv' => $payload['iv'],
            'payload_tag' => $payload['tag'], 'payload_key_id' => $payload['key_id'],
            'template_key' => $request->payload->templateKey, 'language' => $request->payload->language,
            'sender_type' => $request->senderType, 'priority' => $request->priority,
            'scheduled_at' => $snapshot->scheduledAt->format('Y-m-d H:i:s'),
            'created_at' => $snapshot->acceptedAt->format('Y-m-d H:i:s'),
            'updated_at' => $snapshot->acceptedAt->format('Y-m-d H:i:s'),
        ]);
        if ($statement->rowCount() !== 1) {
            throw new EmailEnqueueUnavailableException('Queue insert did not create one row.');
        }
        $id = $this->pdo->lastInsertId();
        if (!is_string($id) || !self::validJobId($id)) {
            throw new EmailEnqueueUnavailableException('Queue insert returned an invalid job identity.');
        }
        return $id;
    }

    /** @return array<string, mixed>|null */
    private function current(string $namespace, string $key): ?array
    {
        $statement = $this->prepare('SELECT r.*, q.status AS queue_status FROM cd_email_enqueue_receipts r
            LEFT JOIN cd_email_queue q ON q.id = r.job_id AND q.enqueue_receipt_id = r.receipt_id
            WHERE r.consumer_namespace = :namespace AND r.request_key = :request_key FOR UPDATE');
        $this->execute($statement, ['namespace' => $namespace, 'request_key' => $key]);
        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $row */
    private function verifyFingerprint(array $row, string $digest): void
    {
        if (!in_array($row['fingerprint_version'] ?? null, [1, '1'], true)
            || self::field($row, 'fingerprint_algorithm') !== ReversibleCryptoAlgorithmEnum::AES_256_GCM->value) {
            throw new EmailEnqueueUnavailableException('Unsupported stored fingerprint version.');
        }
        $stored = $this->cryptoProvider->context($this->fingerprintContext)->decrypt(
            self::field($row, 'fingerprint_encrypted'),
            self::field($row, 'fingerprint_key_id'),
            ReversibleCryptoAlgorithmEnum::AES_256_GCM,
            new ReversibleCryptoMetadataDTO(self::field($row, 'fingerprint_iv'), self::field($row, 'fingerprint_tag')),
        );
        if (strlen($stored) !== 32) {
            throw new EmailEnqueueUnavailableException('Stored fingerprint is invalid.');
        }
        if (!hash_equals($stored, $digest)) {
            throw new EmailEnqueueConflictException();
        }
    }

    /** @param array<string, mixed> $row */
    private function receipt(array $row): EmailEnqueueReceiptDTO
    {
        $receiptId = self::field($row, 'receipt_id');
        $jobId = self::field($row, 'job_id');
        if (preg_match('/\A[a-f0-9]{32}\z/', $receiptId) !== 1 || !self::validJobId($jobId)) {
            throw new EmailEnqueueUnavailableException('Stored receipt identity is invalid.');
        }
        $accepted = self::field($row, 'accepted_at');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $accepted, new DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s.u') !== $accepted) {
            throw new EmailEnqueueUnavailableException('Stored acceptance time is invalid.');
        }
        $status = $row['queue_status'] ?? null;
        $state = $status === null ? EmailQueueState::QueueMissing : (is_string($status) ? EmailQueueState::tryFrom($status) : null);
        if ($state === null || ($status !== null && $state === EmailQueueState::QueueMissing)) {
            throw new EmailEnqueueUnavailableException('Stored queue state is invalid.');
        }
        return new EmailEnqueueReceiptDTO($receiptId, $jobId, $date->format('Y-m-d\TH:i:s.u\Z'), $state);
    }

    /** @param array<string, mixed> $row */
    private static function field(array $row, string $name): string
    {
        $value = $row[$name] ?? null;
        if (!is_string($value) && !is_int($value)) {
            throw new EmailEnqueueUnavailableException('Required receipt field is invalid.');
        }
        return (string) $value;
    }

    private static function validJobId(string $id): bool
    {
        return preg_match('/\A[1-9][0-9]{0,19}\z/', $id) === 1
            && (strlen($id) < 20 || strcmp($id, '18446744073709551615') <= 0);
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new EmailEnqueueUnavailableException('Enqueue statement was not prepared.');
        }
        return $statement;
    }

    /** @param array<string, int|string|null> $parameters */
    private function execute(PDOStatement $statement, array $parameters): void
    {
        if ($statement->execute($parameters) !== true) {
            throw new EmailEnqueueUnavailableException('Enqueue statement did not execute.');
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
            throw new EmailEnqueueUnavailableException('An idle ERRMODE_EXCEPTION connection is required.');
        }
        $settings = $this->pdo->query('SELECT @@SESSION.autocommit AS autocommit, @@SESSION.time_zone AS time_zone');
        if ($settings === false) {
            throw new EmailEnqueueUnavailableException('Connection settings are unavailable.');
        }
        /** @var array<string, mixed>|false $values */
        $values = $settings->fetch(PDO::FETCH_ASSOC);
        if ($values === false || !in_array($values['autocommit'] ?? null, [1, '1'], true)
            || !in_array($values['time_zone'] ?? null, ['+00:00', 'UTC'], true)) {
            throw new EmailEnqueueUnavailableException('Autocommit and an explicit UTC session are required.');
        }
        $owns = false;
        $commitAttempted = false;
        try {
            if ($this->pdo->beginTransaction() !== true) {
                throw new EmailEnqueueUnavailableException('Enqueue transaction did not begin.');
            }
            $owns = true;
            EmailEnqueueSchemaValidator::validate($this->pdo);
            $result = $operation();
            $commitAttempted = true;
            if ($this->pdo->commit() !== true || $this->active()) {
                throw new EmailEnqueueTransactionUnresolvedException('commit');
            }
            return $result;
        } catch (Throwable $failure) {
            try {
                $active = $this->active();
                if ($owns && $active && $this->pdo->rollBack() !== true) {
                    throw new EmailEnqueueTransactionUnresolvedException('rollback', $failure);
                }
                if ($this->active() || (!$owns && $active)) {
                    throw new EmailEnqueueTransactionUnresolvedException('begin_or_cleanup', $failure);
                }
            } catch (EmailEnqueueTransactionUnresolvedException $unresolved) {
                throw $unresolved;
            } catch (Throwable) {
                throw new EmailEnqueueTransactionUnresolvedException('cleanup', $failure);
            }
            if ($commitAttempted) {
                throw new EmailEnqueueTransactionUnresolvedException('commit', $failure);
            }
            if ($failure instanceof EmailEnqueueConflictException || $failure instanceof EmailEnqueueUnavailableException) {
                throw $failure;
            }
            throw new EmailEnqueueUnavailableException('Enqueue operation is unavailable.', 0, $failure);
        }
    }
}
