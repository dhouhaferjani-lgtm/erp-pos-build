<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application;

final readonly class CreateRfqData
{
    /**
     * @param  list<string>  $partnerIds
     * @param  list<array{product_id: string, variant_id?: string|null, quantity: string, unit_price?: string|null, description?: string|null}>  $lines
     */
    public function __construct(
        public array $partnerIds,
        public array $lines,
        public ?string $validityDate,
        public ?string $notes,
    ) {}
}
