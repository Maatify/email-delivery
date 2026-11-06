<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Queue\Internal;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Maatify\EmailDelivery\Queue\DTO\EmailEnqueueRequestDTO;

/** @internal Version 1: exact types, bytes and array iteration order; no user callbacks. */
final class EmailEnqueueCanonicalizer
{
    public static function identity(string $namespace, string $key): void
    {
        if ($namespace === '' || strlen($namespace) > 128 || $key === '' || strlen($key) > 191) {
            throw new InvalidArgumentException('Enqueue namespace/key lengths are invalid.');
        }
    }

    public static function snapshot(EmailEnqueueRequestDTO $request, DateTimeImmutable $now): EmailEnqueueSnapshot
    {
        self::identity($request->consumerNamespace, $request->requestKey);
        $now = $now->setTimezone(new DateTimeZone('UTC'));
        $message = EmailMessageSnapshotFactory::snapshot(new EmailMessageSnapshot(
            $request->entityType, $request->entityId, $request->recipientEmail, $request->payload,
            $request->senderType, $request->priority, $request->scheduledAt,
        ));
        $scheduled = $message->scheduledAt;
        $copy = new EmailEnqueueRequestDTO(
            $request->consumerNamespace, $request->requestKey, $message->entityType, $message->entityId,
            $message->recipientEmail, $message->payload, $message->senderType, $message->priority, $scheduled,
        );
        $logical = [
            'v1', $copy->consumerNamespace, $copy->requestKey, $copy->entityType, $copy->entityId,
            $copy->recipientEmail, $copy->payload->toArray(), $copy->senderType, $copy->priority,
            $scheduled === null ? ['immediate'] : ['explicit', $scheduled->format('Y-m-d H:i:s')],
        ];
        return new EmailEnqueueSnapshot($copy, hash('sha256', self::encode($logical), true), $scheduled ?? $now, $now);
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
