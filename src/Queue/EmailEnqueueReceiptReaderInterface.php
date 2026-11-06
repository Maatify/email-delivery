<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Queue;

use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueReceiptDTO;

interface EmailEnqueueReceiptReaderInterface
{
    /**
     * Scoped current receipt; no recipient/context is returned or decrypted. Owns its transaction.
     *
     * @throws \InvalidArgumentException Invalid namespace/key lengths.
     * @throws \Maatify\EmailDelivery\Exception\EmailEnqueueUnavailableException The scoped read is unavailable.
     * @throws \Maatify\EmailDelivery\Exception\EmailEnqueueTransactionUnresolvedException Discard connection; use a new one.
     */
    public function find(string $consumerNamespace, string $requestKey): ?EmailEnqueueReceiptDTO;
}
