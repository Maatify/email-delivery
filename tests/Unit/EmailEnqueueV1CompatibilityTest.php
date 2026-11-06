<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use Maatify\EmailDelivery\Queue\Internal\EmailEnqueueCanonicalizer;
use PHPUnit\Framework\TestCase;
use Tests\Support\EmailQueueTestFactory;

final class EmailEnqueueV1CompatibilityTest extends TestCase
{
    public function testPersistedV1FingerprintsRemainByteExactAfterSharedSnapshotExtraction(): void
    {
        $contexts = [['code' => 'fixture-secret'], ['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1], ['a' => 1.0, 'b' => 2], ['a' => '1', 'b' => 2], ['all' => [null, false, true, 0, -1, 1.5, 'text', [], ['x' => 1]]]];
        $golden = [
            'f2b9a0840f3aa115fc7da327c61294a20ba2f3a6c5cc73c6d14e945fbe05375a',
            '266d5e4aa46f87b193288cf13a60e992fe4ab0fe1154eeda3879ca91a9739db6',
            '85499c322504a61006b420dad3fed71f9ea31590b90fb31e26757f25a455fe20',
            '5e61048b77f751339e9b6773b68aeb063d2a9bbd7f142d2c24a3cd795f6ae349',
            '3069e52edd49458c4f26b10dc1d08aed7e5a1cd58a3e2cbc30747cbafb94ef96',
            '6277f0a0185dd523b037a73bc9c181e54d9326e0a024bed1fad0d3e45e335c74',
        ];
        foreach ($contexts as $index => $context) {
            $snapshot = EmailEnqueueCanonicalizer::snapshot(EmailQueueTestFactory::request(context: $context), new DateTimeImmutable('2026-11-06T12:00:00Z'));
            self::assertSame($golden[$index], bin2hex($snapshot->digest));
        }
    }
}
