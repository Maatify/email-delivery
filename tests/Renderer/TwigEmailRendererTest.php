<?php

declare(strict_types=1);

namespace Tests\Renderer;

use Maatify\EmailDelivery\DTO\GenericEmailPayload;
use Maatify\EmailDelivery\Renderer\TwigEmailRenderer;
use PHPUnit\Framework\TestCase;

class TwigEmailRendererTest extends TestCase
{
    public function testRendererInstanceCanBeCreated(): void
    {
        $renderer = new TwigEmailRenderer(
            templatesPath: __DIR__, // أي path مؤقت
            globals: [],
            cachePath: false
        );

        $this->assertInstanceOf(TwigEmailRenderer::class, $renderer);
    }

    public function testPayloadDTOWorks(): void
    {
        $payload = new GenericEmailPayload([
            'name' => 'John'
        ]);

        $this->assertEquals('John', $payload->toArray()['name']);
    }
}
