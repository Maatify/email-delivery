<?php

declare(strict_types=1);

namespace Tests\Unit;

use Maatify\EmailDelivery\DTO\GenericEmailPayload;
use Maatify\EmailDelivery\Exception\EmailRenderException;
use Maatify\EmailDelivery\Renderer\TwigEmailRenderer;
use PHPUnit\Framework\TestCase;

class TwigEmailRendererTest extends TestCase
{
    private string $fixturesPath;

    protected function setUp(): void
    {
        $this->fixturesPath = realpath(__DIR__ . '/../Fixtures/templates');
    }

    public function testRenderSuccessfulWithGlobals(): void
    {
        $renderer = new TwigEmailRenderer(
            templatesPath: $this->fixturesPath,
            globals: ['global_app_name' => 'MyApp'],
            cachePath: false // disable cache for tests
        );

        $payload = new GenericEmailPayload(['name' => 'John Doe', 'lang' => 'en']);

        $result = $renderer->render('welcome', 'en', $payload);

        $this->assertSame('Welcome John Doe', $result->subject);
        $this->assertStringContainsString('Hello John Doe,', $result->htmlBody);
        $this->assertStringContainsString('Welcome to MyApp. Your language is en.', $result->htmlBody);
        $this->assertSame('welcome', $result->templateKey);
        $this->assertSame('en', $result->language);
    }

    public function testRenderSuccessfulDifferentLanguage(): void
    {
        $renderer = new TwigEmailRenderer(
            templatesPath: $this->fixturesPath,
            globals: ['global_app_name' => 'MyApp'],
            cachePath: false
        );

        $payload = new GenericEmailPayload(['name' => 'Marie']);

        $result = $renderer->render('welcome', 'fr', $payload);

        $this->assertSame('Bienvenue Marie', $result->subject);
        $this->assertStringContainsString('Bonjour Marie,', $result->htmlBody);
        $this->assertStringContainsString('Bienvenue à MyApp.', $result->htmlBody);
    }

    public function testThrowsWhenTemplateMissing(): void
    {
        $renderer = new TwigEmailRenderer(
            templatesPath: $this->fixturesPath,
            cachePath: false
        );

        $payload = new GenericEmailPayload([]);

        $this->expectException(EmailRenderException::class);
        $this->expectExceptionMessage("Failed to render email template 'nonexistent' (en)");

        $renderer->render('nonexistent', 'en', $payload);
    }

    public function testThrowsWhenSubjectBlockMissing(): void
    {
        $renderer = new TwigEmailRenderer(
            templatesPath: $this->fixturesPath,
            cachePath: false
        );

        $payload = new GenericEmailPayload(['name' => 'Bob']);

        $this->expectException(EmailRenderException::class);
        $this->expectExceptionMessage("missing required block 'subject'");

        $renderer->render('missing_subject', 'en', $payload);
    }

    public function testThrowsWhenSubjectEmpty(): void
    {
        $renderer = new TwigEmailRenderer(
            templatesPath: $this->fixturesPath,
            cachePath: false
        );

        $payload = new GenericEmailPayload([]);

        $this->expectException(EmailRenderException::class);
        $this->expectExceptionMessage("Subject block in 'emails/empty_subject/en.twig' rendered empty string.");

        $renderer->render('empty_subject', 'en', $payload);
    }
}
