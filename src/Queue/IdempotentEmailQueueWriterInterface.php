<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Queue;

use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueReceiptDTO;
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO;

interface IdempotentEmailQueueWriterInterface
{
    /**
     * Returns only after an owned transaction commits. Never joins a caller transaction.
     * Replay the SAME namespace/key on a NEW connection after an unresolved outcome.
     * This confirms queue acceptance, never exactly-once SMTP or mailbox delivery.
     *
     * @throws \InvalidArgumentException Invalid request/canonical context.
     * @throws \Maatify\EmailDelivery\Exception\EmailEnqueueConflictException Same scope/key, different content.
     * @throws \Maatify\EmailDelivery\Exception\EmailEnqueueUnavailableException No acceptance can be confirmed.
     * @throws \Maatify\EmailDelivery\Exception\EmailEnqueueTransactionUnresolvedException Discard connection; reconcile on a new one.
     */
    public function enqueueOnce(EmailEnqueueRequestDTO $request): EmailEnqueueReceiptDTO;
}
