<?php

declare(strict_types=1);

namespace Tests\Unit;

use Maatify\EmailDelivery\Config\EmailTransportConfigDTO;
use PHPUnit\Framework\TestCase;

class EmailTransportConfigDTOTest extends TestCase
{
    public function testConstructorAssignmentAndDefaults(): void
    {
        $dto = new EmailTransportConfigDTO(
            host: 'smtp.example.com',
            port: 587,
            username: 'user@example.com',
            password: 'secret_password',
            fromAddress: 'no-reply@example.com',
            fromName: 'System'
        );

        $this->assertSame('smtp.example.com', $dto->host);
        $this->assertSame(587, $dto->port);
        $this->assertSame('user@example.com', $dto->username);
        $this->assertSame('secret_password', $dto->password);
        $this->assertSame('no-reply@example.com', $dto->fromAddress);
        $this->assertSame('System', $dto->fromName);

        // Check defaults
        $this->assertNull($dto->encryption);
        $this->assertSame(10, $dto->timeoutSeconds);
        $this->assertSame('UTF-8', $dto->charset);
        $this->assertSame(0, $dto->debugLevel);
    }

    public function testOverrideDefaults(): void
    {
        $dto = new EmailTransportConfigDTO(
            host: 'smtp.example.com',
            port: 465,
            username: 'user',
            password: 'pwd',
            fromAddress: 'from@domain',
            fromName: 'Name',
            encryption: 'ssl',
            timeoutSeconds: 30,
            charset: 'ISO-8859-1',
            debugLevel: 2
        );

        $this->assertSame('ssl', $dto->encryption);
        $this->assertSame(30, $dto->timeoutSeconds);
        $this->assertSame('ISO-8859-1', $dto->charset);
        $this->assertSame(2, $dto->debugLevel);
    }
}
