<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\DTOs;

use Spatie\LaravelData\Data;

final class PartnerBankAccountInputData extends Data
{
    public function __construct(
        public ?string $id,
        public ?string $label,
        public ?string $bank_id,
        public ?string $bank_name,
        public ?string $rib,
        public ?string $iban,
        public ?string $bic,
        public string $currency,
        public bool $is_primary,
    ) {}
}
