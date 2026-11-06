<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTime;
use DateTimeImmutable;
use InvalidArgumentException;
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;
use Maatify\EmailDelivery\Queue\Internal\EmailEnqueueCanonicalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\EmailQueueTestFactory;

final class EmailEnqueueCanonicalizerTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidContexts(): iterable
    {
        yield 'root huge key with scalar' => [[str_repeat('k', 1048577) => null]];
        yield 'root invalid UTF8 key with scalar' => [["\xff" => 1]];
        yield 'nested huge key with scalar' => [['nested' => [str_repeat('k', 1048577) => 1]]];
        yield 'nested invalid UTF8 key with scalar' => [['nested' => ["\xff" => null]]];
        yield 'invalid string' => [['value' => "\xff"]];
        yield 'object' => [['value' => (object) ['x' => 1]]];
        yield 'nonfinite' => [['value' => INF]];
    }

    /** @param array<string, mixed> $context */
    #[DataProvider('invalidContexts')]
    public function testInvalidContextIsRejectedBeforePersistence(array $context): void
    {
        $this->expectException(InvalidArgumentException::class);
        EmailEnqueueCanonicalizer::snapshot(EmailQueueTestFactory::request(context: $context), new DateTimeImmutable('2026-11-06T12:00:00Z'));
    }

    public function testRootIntegerKeyIsRejectedAsValidationRatherThanTypeError(): void
    {
        // Deliberately invalid input outside the legacy array<string, mixed> PHPDoc.
        /** @var array<string, mixed> $context */
        $context = [0 => 'a', 'valid' => 1];
        $this->expectException(InvalidArgumentException::class);
        EmailEnqueueCanonicalizer::snapshot(EmailQueueTestFactory::request(context: $context), new DateTimeImmutable('2026-11-06T12:00:00Z'));
    }

    public function testNestedReferencesAndMutableDateAreDetachedAndImmediateFingerprintIgnoresNow(): void
    {
        $value = 'original';
        $context = ['nested' => ['value' => &$value]];
        $request = EmailQueueTestFactory::request(context: $context);
        $first = EmailEnqueueCanonicalizer::snapshot($request, new DateTimeImmutable('2026-11-06T12:00:00Z'));
        $value = 'changed';
        $nested = $first->request->payload->context['nested'];
        self::assertIsArray($nested);
        self::assertSame('original', $nested['value']);
        $second = EmailEnqueueCanonicalizer::snapshot(EmailQueueTestFactory::request(context: ['nested' => ['value' => 'original']]), new DateTimeImmutable('2026-12-06T12:00:00Z'));
        self::assertSame($first->digest, $second->digest);
        $mutable = new DateTime('2026-11-06T15:00:00+03:00');
        $explicit = new EmailEnqueueRequestDTO('n', 'k', 'user', null, 'fixture@example.invalid', new EmailQueuePayloadDTO([], 'welcome', 'en'), 1, scheduledAt: $mutable);
        $mutable->modify('+1 day');
        $snapshot = EmailEnqueueCanonicalizer::snapshot($explicit, new DateTimeImmutable('2026-11-06T00:00:00Z'));
        self::assertSame('2026-11-06T12:00:00+00:00', $snapshot->scheduledAt->format('c'));
    }

    public function testOrderAndTypesArePartOfLogicalContent(): void
    {
        $now = new DateTimeImmutable('2026-11-06T12:00:00Z');
        $contexts = [['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1], ['a' => 1.0, 'b' => 2], ['a' => '1', 'b' => 2]];
        $digests = [];
        foreach ($contexts as $context) {
            $digests[] = EmailEnqueueCanonicalizer::snapshot(EmailQueueTestFactory::request(context: $context), $now)->digest;
        }
        self::assertCount(4, array_unique($digests));
    }

    public function testRecursiveContextIsBounded(): void
    {
        $cycle = [];
        $cycle['self'] = &$cycle;
        $this->expectException(InvalidArgumentException::class);
        EmailEnqueueCanonicalizer::snapshot(EmailQueueTestFactory::request(context: $cycle), new DateTimeImmutable('2026-11-06T12:00:00Z'));
    }
}
