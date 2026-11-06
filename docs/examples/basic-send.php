<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Documentation\Examples;

use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\EmailQueueWriterInterface;

/** Legacy enqueue: each explicit call inserts another job; this function does not send SMTP. */
function enqueueLegacyWelcome(
    EmailQueueWriterInterface $writer,
    string $recipient,
    string $userId,
    string $name,
    ?string $replyTo = null,
): void {
    $writer->enqueue(
        entityType: 'user',
        entityId: $userId,
        recipientEmail: $recipient,
        payload: new EmailQueuePayloadDTO(['name' => $name], 'welcome', 'en', $replyTo),
        senderType: 1,
    );
}
