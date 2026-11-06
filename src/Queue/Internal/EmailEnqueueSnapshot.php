<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Queue\Internal;

use DateTimeImmutable;
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO;

/** @internal A detached request used for BOTH fingerprinting and encryption. */
final readonly class EmailEnqueueSnapshot
{
    public function __construct(
        public EmailEnqueueRequestDTO $request,
        public string $digest,
        public DateTimeImmutable $scheduledAt,
        public DateTimeImmutable $acceptedAt,
    ) {
    }
}
