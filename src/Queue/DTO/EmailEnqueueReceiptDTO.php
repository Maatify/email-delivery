<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Queue\DTO;

use JsonSerializable;
use Maatify\EmailDelivery\Queue\Enum\EmailQueueState;

final readonly class EmailEnqueueReceiptDTO implements JsonSerializable
{
    public function __construct(
        public string $receiptId,
        public string $jobId,
        public string $acceptedAt,
        public EmailQueueState $queueState,
    ) {
    }

    /** @return array{receipt_id: string, job_id: string, accepted_at: string, queue_state: string} */
    public function jsonSerialize(): array
    {
        return [
            'receipt_id' => $this->receiptId,
            'job_id' => $this->jobId,
            'accepted_at' => $this->acceptedAt,
            'queue_state' => $this->queueState->value,
        ];
    }
}
