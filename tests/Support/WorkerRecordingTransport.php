<?php

declare(strict_types=1);

namespace Tests\Support;

use Maatify\EmailDelivery\DTO\RenderedEmailDTO;
use Maatify\EmailDelivery\Exception\EmailTransportException;
use Maatify\EmailDelivery\Transport\EmailTransportInterface;

/** External SMTP boundary only; no network connection is ever opened. */
final class WorkerRecordingTransport implements EmailTransportInterface
{
    public int $calls = 0;
    public bool $fail = false;

    public function send(string $recipientEmail, RenderedEmailDTO $email, ?string $replyToEmail = null): void
    {
        ++$this->calls;
        if ($this->fail) {
            throw new EmailTransportException('Fixture SMTP failure; no network');
        }
    }
}
