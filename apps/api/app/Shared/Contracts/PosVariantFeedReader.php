<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\DTOs\PosVariantFeedPageDTO;
use Carbon\CarbonImmutable;

interface PosVariantFeedReader
{
    public function read(
        string $tenantId,
        string $companyId,
        ?CarbonImmutable $updatedSince,
        int $page,
        int $perPage,
    ): PosVariantFeedPageDTO;
}
