<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Queue\Internal;

use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;

use DateTimeImmutable;
use DateTimeInterface;

/** @internal Detached message data shared by the keyed and synthetic paths. */
final readonly class EmailMessageSnapshot
{
    public ?DateTimeImmutable $scheduledAt;

    public function __construct(
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
