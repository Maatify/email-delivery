<?php

declare(strict_types=1);

namespace Tests\Unit;

use Maatify\EmailDelivery\Synthetic\Internal\SyntheticEmailContexts;
use PHPUnit\Framework\TestCase;
use Tests\Support\EmailQueueTestFactory;

final class SyntheticEmailContextsTest extends TestCase
{
    public function testDomainsAreBoundedStableAndRoleSeparatedForMaxLengthRealContexts(): void
    {
        $real = str_repeat('x', 252) . ':v1';
        $recipient = SyntheticEmailContexts::recipient($real);
        $payload = SyntheticEmailContexts::payload($real);
        self::assertNotSame($recipient, $payload);
        self::assertNotSame($real, $recipient);
        self::assertLessThanOrEqual(255, strlen($recipient));
        self::assertSame($recipient, SyntheticEmailContexts::recipient(' ' . $real . ' '));
        // Exercise the actual public crypto API, not only string generation.
        $encrypted = EmailQueueTestFactory::crypto()->context($recipient)->encrypt('fixture');
        self::assertNotEmpty($encrypted['result']->cipher);
    }
}
