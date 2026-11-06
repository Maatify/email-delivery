<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Queue\DTO;

use DateTimeImmutable;
use DateTimeInterface;

final readonly class EmailEnqueueRequestDTO
{
    public ?DateTimeImmutable $scheduledAt;

    public function __construct(
        public string $consumerNamespace,
        public string $requestKey,
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
