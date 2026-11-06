<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Synthetic\Internal;

use InvalidArgumentException;

/** @internal SHA-256 names a public HKDF domain; it is not encryption or a secret digest. */
final class SyntheticEmailContexts
{
    public static function recipient(string $realContext): string
    {
        return self::domain('recipient', $realContext);
    }

    public static function payload(string $realContext): string
    {
        return self::domain('payload', $realContext);
    }

    private static function domain(string $role, string $realContext): string
    {
        $realContext = trim($realContext);
        if ($realContext === '' || strlen($realContext) > 255 || !str_contains($realContext, ':v')) {
            throw new InvalidArgumentException('A valid real email crypto context is required.');
        }
        return 'email-delivery:synthetic:' . $role . ':' . hash('sha256', trim($realContext)) . ':v1';
    }
}
