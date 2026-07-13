<?php

declare(strict_types=1);

namespace App\Modules\Expense\Application\DTOs;

use Spatie\LaravelData\Data;

final class AnalyticsFilters extends Data
{
    public function __construct(
        public string $date_from,
        public string $date_to,
        public ?string $category_id,
        public string $status,
    ) {}
}
