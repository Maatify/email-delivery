<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Synthetic;

/** Inject only into trusted maintenance code; cleanup spans consumer namespaces. */
interface SyntheticEmailMaintenanceInterface
{
    /** Delete at most $limit expired inert records; never unexpired records. */
    public function purgeExpired(int $limit = 100): int;
}
