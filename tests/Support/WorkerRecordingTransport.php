<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Maatify\EmailDelivery\DTO\RenderedEmailDTO;
use Maatify\EmailDelivery\Exception\EmailTransportException;
use Maatify\EmailDelivery\Transport\EmailTransportInterface;

/** External SMTP boundary only; no network connection is ever opened. */
final class WorkerRecordingTransport implements EmailTransportInterface
{
    public int $calls = 0;
    public bool $fail = false;
    public string $failureMessage = 'Fixture SMTP failure; no network';
    /** @var (Closure(int): void)|null Runs inside the send (receives the call number): a slow send or a lost connection. */
    public ?Closure $duringSend = null;

    public function send(string $recipientEmail, RenderedEmailDTO $email, ?string $replyToEmail = null): void
    {
        ++$this->calls;
        if ($this->duringSend !== null) {
            ($this->duringSend)($this->calls);
        }
        if ($this->fail) {
            throw new EmailTransportException($this->failureMessage);
        }
    }
}
