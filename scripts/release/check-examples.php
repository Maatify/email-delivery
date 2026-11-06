<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\ReleaseTools;

use Maatify\EmailDelivery\Config\EmailTransportConfigDTO;
use Maatify\EmailDelivery\DTO\RenderedEmailDTO;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\EmailQueueWriterInterface;
use Maatify\EmailDelivery\Renderer\TwigEmailRenderer;
use Maatify\EmailDelivery\Transport\EmailTransportInterface;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/lib.php';
$root = dirname(__DIR__, 2);
foreach (glob($root . '/examples/*.php') ?: [] as $path) {
    require_once $path;
    \PhpToken::tokenize(read($path), TOKEN_PARSE);
}
// No SQL, credentials, service startup or network. Only public example calls with inert ports.
$writer = new class () implements EmailQueueWriterInterface {
    public int $calls = 0;
    public ?string $replyTo = null;
    public function enqueue(string $entityType, ?string $entityId, string $recipientEmail, EmailQueuePayloadDTO $payload, int $senderType, int $priority = 5, ?\DateTimeInterface $scheduledAt = null): void
    {
        ++$this->calls;
        $this->replyTo = $payload->replyTo;
    }
};
\Maatify\EmailDelivery\Documentation\Examples\enqueueLegacyWelcome($writer, 'fixture@example.invalid', '1', 'Fixture', 'reply@example.invalid');
ensure($writer->calls === 1 && $writer->replyTo === 'reply@example.invalid', 'Legacy example forwarding failed.');
\Maatify\EmailDelivery\Documentation\Examples\scheduleLegacyWelcome($writer, 'fixture@example.invalid', '1', 'Fixture', new \DateTimeImmutable('2030-01-01T00:00:00Z'));
ensure($writer->calls === 2, 'Schedule example forwarding failed.');
// Existing complete fixture templates; no consumer is directed to fixtures as integration documentation.
$rendered = \Maatify\EmailDelivery\Documentation\Examples\renderWelcome(new TwigEmailRenderer($root . '/tests/Fixtures/templates', ['global_app_name' => 'Fixture'], false), 'Fixture');
ensure($rendered->subject !== '' && str_contains($rendered->htmlBody, 'Fixture'), 'Rendering example smoke failed.');
$transport = new class () implements EmailTransportInterface {
    public int $calls = 0;
    public function send(string $recipientEmail, RenderedEmailDTO $email, ?string $replyToEmail = null): void
    {
        ++$this->calls;
    }
};
\Maatify\EmailDelivery\Documentation\Examples\sendRenderedEmail($transport, 'fixture@example.invalid', $rendered);
ensure($transport->calls === 1, 'Transport example forwarding failed.');
\Maatify\EmailDelivery\Documentation\Examples\createSmtpTransport(new EmailTransportConfigDTO('fixture.invalid', 587, 'fixture', 'fixture', 'fixture@example.invalid', 'Fixture', 'tls'));
// Old include paths remain safe and do not redeclare functions after canonical includes.
foreach (glob($root . '/docs/examples/*.php') ?: [] as $path) {
    require_once $path;
}
echo "PASS: canonical examples load/lint; legacy forwarding, safe rendering and fake transport smoke; no SMTP/SQL.\n";
