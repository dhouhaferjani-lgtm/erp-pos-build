<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

final readonly class UpdateRfqData
{
    /**
     * @param  list<array{id?: string|null, product_id: string, variant_id?: string|null, quantity: string, unit_price?: string|null, description?: string|null}>  $lines
     */
    public function __construct(
        public array $lines,
        public ?string $validityDate,
        public ?string $supplierReference,
        public ?int $leadTimeDays,
    ) {}
}
