<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Synthetic\DTO;

/** Confirms an inert database recording, never queue acceptance or email delivery. */
final readonly class SyntheticEmailRecordDTO
{
    public function __construct(
        public string $recordId,
        public string $recordedAtUtc,
        public string $expiresAtUtc,
    ) {
    }
}
