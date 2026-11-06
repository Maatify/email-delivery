<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Exception;

use Throwable;

/** Discard the connection. A retry may append a second inert record; it cannot send mail. */
final class SyntheticEmailTransactionUnresolvedException extends SyntheticEmailUnavailableException
{
    public function __construct(string $phase, ?Throwable $previous = null)
    {
        parent::__construct('Synthetic transaction outcome is unresolved: ' . $phase, 0, $previous);
    }
}
