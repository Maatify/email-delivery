<?php

declare(strict_types=1);

namespace Tests\Integration;

use Composer\InstalledVersions;
use Maatify\EmailDelivery\Config\EmailTransportConfigDTO;
use Maatify\EmailDelivery\DTO\RenderedEmailDTO;
use Maatify\EmailDelivery\Exception\EmailTransportException;
use Maatify\EmailDelivery\Transport\SmtpEmailTransport;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use PHPUnit\Framework\TestCase;

/**
 * The real SMTP path: SmtpEmailTransport talks to an isolated test SMTP server (Mailpit) whose HTTP API shows what
 * arrived. It runs on whichever PHPMailer major Composer resolved; CI runs it on PHPMailer 6.9.0 and on 7.x.
 *
 * Configure EMAIL_DELIVERY_SMTP_HOST, EMAIL_DELIVERY_SMTP_PORT and EMAIL_DELIVERY_SMTP_API_URL (the server's HTTP API,
 * for example http://127.0.0.1:8025). EMAIL_DELIVERY_SMTP_REQUIRED=1 turns a missing configuration into a failure.
 */
final class SmtpEmailTransportSmtpServerTest extends TestCase
{
    private string $apiUrl;

    protected function setUp(): void
    {
        $host = getenv('EMAIL_DELIVERY_SMTP_HOST');
        $api = getenv('EMAIL_DELIVERY_SMTP_API_URL');
        if ($host === false || $api === false || getenv('EMAIL_DELIVERY_SMTP_PORT') === false) {
            if (getenv('EMAIL_DELIVERY_SMTP_REQUIRED') === '1') {
                self::fail('The required isolated test SMTP server is not configured.');
            }
            self::markTestSkipped('An isolated test SMTP server is required.');
        }
        $this->apiUrl = rtrim($api, '/');
        $this->api('DELETE', '/api/v1/messages');
    }

    public function testAMessageGoesThroughTheRealSmtpPath(): void
    {
        $subject = 'Fixture delivery ' . bin2hex(random_bytes(4)) . ' — ünïcode';

        (new SmtpEmailTransport($this->config(self::port())))->send(
            'recipient@example.test',
            new RenderedEmailDTO($subject, '<p>Fixture <strong>HTML</strong> body.</p>', 'welcome', 'en'),
            'reply@example.test',
        );

        $summary = $this->onlyMessage();
        self::assertSame($subject, $summary['Subject']);
        self::assertSame(['Name' => 'Fixture Sender', 'Address' => 'sender@example.test'], $summary['From']);
        self::assertSame([['Name' => '', 'Address' => 'recipient@example.test']], $summary['To']);
        self::assertSame([['Name' => '', 'Address' => 'reply@example.test']], $summary['ReplyTo']);

        $message = $this->api('GET', '/api/v1/message/' . $summary['ID']);
        self::assertIsString($message['HTML'] ?? null);
        self::assertStringContainsString('<p>Fixture <strong>HTML</strong> body.</p>', $message['HTML']);

        // The X-Mailer header names the PHPMailer release that sent it: prove which major ran.
        $headers = $this->api('GET', '/api/v1/message/' . $summary['ID'] . '/headers');
        $installed = InstalledVersions::getPrettyVersion('phpmailer/phpmailer');
        self::assertIsString($installed);
        self::assertSame(['PHPMailer ' . ltrim($installed, 'v') . ' (https://github.com/PHPMailer/PHPMailer)'], $headers['X-Mailer'] ?? null);
    }

    public function testAnUnreachableServerIsATransportFailure(): void
    {
        try {
            // Nothing listens on the discard port of the loopback interface.
            (new SmtpEmailTransport($this->config(9, timeoutSeconds: 2)))->send(
                'recipient@example.test',
                new RenderedEmailDTO('Unreachable', '<p>Never sent.</p>', 'welcome', 'en'),
            );
            self::fail('An unreachable SMTP server was not reported.');
        } catch (EmailTransportException $failure) {
            self::assertInstanceOf(PHPMailerException::class, $failure->getPrevious());
            self::assertStringStartsWith('PHPMailer Error: ', $failure->getMessage());
        }
        self::assertSame([], $this->api('GET', '/api/v1/messages')['messages'] ?? null);
    }

    private static function port(): int
    {
        return (int) getenv('EMAIL_DELIVERY_SMTP_PORT');
    }

    private function config(int $port, int $timeoutSeconds = 5): EmailTransportConfigDTO
    {
        $host = getenv('EMAIL_DELIVERY_SMTP_HOST');
        self::assertIsString($host);
        // No TLS on the isolated test server; it accepts any credentials.
        return new EmailTransportConfigDTO($port === self::port() ? $host : '127.0.0.1', $port, 'fixture-user', 'fixture-password', 'sender@example.test', 'Fixture Sender', null, $timeoutSeconds);
    }

    /** @return array{ID: string, Subject: string, From: mixed, To: mixed, ReplyTo: mixed} */
    private function onlyMessage(): array
    {
        $deadline = microtime(true) + 5;
        do {
            $messages = $this->api('GET', '/api/v1/messages')['messages'] ?? [];
            if (is_array($messages) && $messages !== []) {
                self::assertCount(1, $messages);
                $summary = $messages[0];
                self::assertIsArray($summary);
                self::assertIsString($summary['ID'] ?? null);
                self::assertIsString($summary['Subject'] ?? null);
                /** @var array{ID: string, Subject: string, From: mixed, To: mixed, ReplyTo: mixed} $summary */
                return $summary;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);
        self::fail('The test SMTP server received no message.');
    }

    /** @return array<string, mixed> */
    private function api(string $method, string $path): array
    {
        $context = stream_context_create(['http' => ['method' => $method, 'timeout' => 5, 'ignore_errors' => true]]);
        $body = file_get_contents($this->apiUrl . $path, false, $context);
        self::assertIsString($body, "$method $path failed.");
        if ($body === '' || $method === 'DELETE') {
            return [];
        }
        $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
