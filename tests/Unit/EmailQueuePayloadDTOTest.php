<?php

declare(strict_types=1);

namespace Tests\Unit;

use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use PHPUnit\Framework\TestCase;

class EmailQueuePayloadDTOTest extends TestCase
{
    public function testCreationAndProperties(): void
    {
        $context     = ['name' => 'Alice', 'role' => 'admin'];
        $templateKey = 'welcome_email';
        $language    = 'en';

        $dto = new EmailQueuePayloadDTO($context, $templateKey, $language);

        $this->assertSame($context, $dto->context);
        $this->assertSame($templateKey, $dto->templateKey);
        $this->assertSame($language, $dto->language);
    }

    public function testToArraySerialization(): void
    {
        $context     = ['id' => 123, 'status' => 'active'];
        $templateKey = 'status_update';
        $language    = 'fr';

        $dto = new EmailQueuePayloadDTO($context, $templateKey, $language);

        $expected = [
            'context'     => $context,
            'templateKey' => $templateKey,
            'language'    => $language,
        ];

        $this->assertSame($expected, $dto->toArray());
    }
}
