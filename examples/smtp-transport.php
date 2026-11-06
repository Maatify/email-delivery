<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Documentation\Examples;

use Maatify\EmailDelivery\Config\EmailTransportConfigDTO;
use Maatify\EmailDelivery\DTO\RenderedEmailDTO;
use Maatify\EmailDelivery\Transport\EmailTransportInterface;
use Maatify\EmailDelivery\Transport\SmtpEmailTransport;

/** No connection until an explicit send. Caller owns SMTP policy, secrets and network access. */
function createSmtpTransport(EmailTransportConfigDTO $config): SmtpEmailTransport
{
    return new SmtpEmailTransport($config);
}

/** This function deliberately performs transport; never call with a real transport in docs checks. */
function sendRenderedEmail(EmailTransportInterface $transport, string $recipient, RenderedEmailDTO $email, ?string $replyTo = null): void
{
    $transport->send($recipient, $email, $replyTo);
}
