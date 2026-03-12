<?php

declare(strict_types=1);

namespace Tests\Unit;

use Maatify\EmailDelivery\Worker\EmailQueueWorker;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RetryMechanismTest extends TestCase
{
    public function testRetryDelayCalculationAndMaxAttemptsBehavior(): void
    {
        $reflection = new ReflectionClass(EmailQueueWorker::class);

        $backoffDefault = $reflection->getConstant('BACKOFF_DEFAULT');
        $backoffSmtp = $reflection->getConstant('BACKOFF_SMTP');
        $maxAttempts = $reflection->getConstant('MAX_ATTEMPTS');

        $this->assertSame([1 => 60, 2 => 300, 3 => 900], $backoffDefault);
        $this->assertSame([1 => 30, 2 => 60, 3 => 120], $backoffSmtp);
        $this->assertSame(4, $maxAttempts);
    }
}
