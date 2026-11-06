<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Synthetic\DTO;

use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;

use DateTimeImmutable;
use DateTimeInterface;

/** An inert recording request. It never reserves a real email request key. */
final readonly class SyntheticEmailRequestDTO
{
    public ?DateTimeImmutable $scheduledAt;

    public function __construct(
        public string $consumerNamespace,
        public string $entityType,
        public ?string $entityId,
        public string $recipientEmail,
        public EmailQueuePayloadDTO $payload,
        public int $senderType,
        public int $priority = 5,
        ?DateTimeInterface $scheduledAt = null,
    ) {
        $this->scheduledAt = $scheduledAt === null ? null : DateTimeImmutable::createFromInterface($scheduledAt);
    }
}
