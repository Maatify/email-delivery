<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Queue\Enum;

/** Processing state observed now, not a mailbox receipt. Missing NEVER means sent. */
enum EmailQueueState: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Sent = 'sent';
    case Failed = 'failed';
    case QueueMissing = 'queue_missing';
}
