<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Exception;

use Throwable;

/** Discard the connection. Reconcile only with the same key on a fresh connection. */
final class EmailEnqueueTransactionUnresolvedException extends EmailEnqueueUnavailableException
{
    public function __construct(public readonly string $phase, ?Throwable $previous = null)
    {
        parent::__construct('Enqueue transaction outcome is unresolved; discard the connection.', 0, $previous);
    }
}
