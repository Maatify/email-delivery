<?php

declare(strict_types=1);

namespace Maatify\EmailDelivery\Synthetic;

use Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRequestDTO;
use Maatify\EmailDelivery\Synthetic\DTO\SyntheticEmailRecordDTO;

interface SyntheticEmailRecorderInterface
{
    public function record(SyntheticEmailRequestDTO $request): SyntheticEmailRecordDTO;
}
