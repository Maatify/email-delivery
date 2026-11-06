<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Exception;

/** The existing key names different logical content. No payload or identity in the message. */
final class EmailEnqueueConflictException extends EmailQueueWriteException
{
    public function __construct()
    {
        parent::__construct('The enqueue key already identifies different content.');
    }
}
