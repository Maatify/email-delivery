<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Queue\Internal;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO;
use Maatify\EmailDelivery\Queue\DTO\EmailQueuePayloadDTO;

/** @internal Version 1: exact types, bytes and array iteration order; no user callbacks. */
final class EmailEnqueueCanonicalizer
{
    private const MAX_DEPTH = 32;
    private const MAX_NODES = 10000;
    private const MAX_BYTES = 1048576;

    public static function identity(string $namespace, string $key): void
    {
        if ($namespace === '' || strlen($namespace) > 128 || $key === '' || strlen($key) > 191) {
            throw new InvalidArgumentException('Enqueue namespace/key lengths are invalid.');
        }
    }

    public static function snapshot(EmailEnqueueRequestDTO $request, DateTimeImmutable $now): EmailEnqueueSnapshot
    {
        self::identity($request->consumerNamespace, $request->requestKey);
        if ($request->entityType === '' || strlen($request->entityType) > 255
            || ($request->entityId !== null && strlen($request->entityId) > 255)
            || $request->recipientEmail === '' || strlen($request->recipientEmail) > 320
            || $request->payload->templateKey === '' || strlen($request->payload->templateKey) > 255
            || $request->payload->language === '' || strlen($request->payload->language) > 255
            || ($request->payload->replyTo !== null && strlen($request->payload->replyTo) > 320)
            || $request->senderType < 0 || $request->senderType > 2147483647
            || $request->priority < 0 || $request->priority > 2147483647) {
            throw new InvalidArgumentException('Enqueue request fields are invalid.');
        }
        $utc = new DateTimeZone('UTC');
        $now = $now->setTimezone($utc);
        $scheduled = $request->scheduledAt?->setTimezone($utc);
        if ($scheduled !== null && $scheduled->format('u') !== '000000') {
            throw new InvalidArgumentException('Explicit schedules require whole seconds.');
        }
        if ($scheduled !== null && ((int) $scheduled->format('Y') < 1000 || (int) $scheduled->format('Y') > 9999)) {
            throw new InvalidArgumentException('Explicit schedule is outside the SQL date range.');
        }
        $nodes = 0;
        $bytes = 0;
        $context = [];
        // Rebuild values: assignment of the original array alone would preserve nested references.
        /** @var array<array-key, mixed> $inputContext */
        $inputContext = $request->payload->context;
        foreach ($inputContext as $key => $value) {
            if (!is_string($key)) {
                throw new InvalidArgumentException('Root context keys must be strings; nested lists are supported.');
            }
            self::consume($key, $bytes);
            $context[$key] = self::copy($value, 1, $nodes, $bytes);
        }
        $copy = new EmailEnqueueRequestDTO(
            $request->consumerNamespace,
            $request->requestKey,
            $request->entityType,
            $request->entityId,
            $request->recipientEmail,
            new EmailQueuePayloadDTO($context, $request->payload->templateKey, $request->payload->language, $request->payload->replyTo),
            $request->senderType,
            $request->priority,
            $scheduled,
        );
        $logical = [
            'v1', $copy->consumerNamespace, $copy->requestKey, $copy->entityType, $copy->entityId,
            $copy->recipientEmail, $copy->payload->toArray(), $copy->senderType, $copy->priority,
            $scheduled === null ? ['immediate'] : ['explicit', $scheduled->format('Y-m-d H:i:s')],
        ];
        return new EmailEnqueueSnapshot($copy, hash('sha256', self::encode($logical), true), $scheduled ?? $now, $now);
    }

    private static function copy(mixed $value, int $depth, int &$nodes, int &$bytes): mixed
    {
        if ($depth > self::MAX_DEPTH || ++$nodes > self::MAX_NODES) {
            throw new InvalidArgumentException('Enqueue context exceeds structural limits.');
        }
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    self::consume($key, $bytes);
                }
                $copy[$key] = self::copy($item, $depth + 1, $nodes, $bytes);
            }
            return $copy;
        }
        if (is_string($value)) {
            self::consume($value, $bytes);
            return $value;
        }
        if ($value === null || is_int($value) || is_bool($value) || (is_float($value) && is_finite($value))) {
            return $value;
        }
        throw new InvalidArgumentException('Enqueue context accepts only finite JSON-like values, not objects/resources.');
    }

    private static function consume(string $value, int &$bytes): void
    {
        $bytes += strlen($value);
        if ($bytes > self::MAX_BYTES || preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException('Enqueue context strings exceed limits or are not UTF-8.');
        }
    }

    private static function encode(mixed $value): string
    {
        if ($value === null) {
            return 'n';
        }
        if (is_bool($value)) {
            return $value ? 'b1' : 'b0';
        }
        if (is_int($value)) {
            return 'i' . $value . ';';
        }
        if (is_float($value)) {
            return 'f' . bin2hex(pack('E', $value));
        }
        if (is_string($value)) {
            return 's' . strlen($value) . ':' . $value;
        }
        if (is_array($value)) {
            $out = (array_is_list($value) ? 'l' : 'a') . count($value) . ':';
            foreach ($value as $key => $item) {
                $out .= self::encode($key) . self::encode($item);
            }
            return $out;
        }
        throw new InvalidArgumentException('Unsupported canonical value.');
    }
}
