<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Documentation\Examples;

use DateTimeInterface;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\EmailQueueWriterInterface;

/** Legacy writer formats this date's local wall time; align it with the legacy worker DB zone. */
function scheduleLegacyWelcome(
    EmailQueueWriterInterface $writer,
    string $recipient,
    string $userId,
    string $name,
    DateTimeInterface $localSchedule,
): void {
    $writer->enqueue(
        entityType: 'user',
        entityId: $userId,
        recipientEmail: $recipient,
        payload: new EmailQueuePayloadDTO(['name' => $name], 'welcome', 'en'),
        senderType: 1,
        scheduledAt: $localSchedule,
    );
}
