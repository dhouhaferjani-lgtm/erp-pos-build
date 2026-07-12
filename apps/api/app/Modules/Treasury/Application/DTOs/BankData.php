<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Application\DTOs;

use App\Modules\Treasury\Domain\Bank;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class BankData extends Data
{
    public function __construct(
        public string $id,
        public string $country_code,
        public string $name,
        public ?string $short_name,
        public ?string $bic,
        public ?string $rib_bank_code,
        public ?string $city,
        public bool $is_custom,
    ) {}

    public static function fromModel(Bank $bank): self
    {
        return new self(
            id: $bank->id,
            country_code: $bank->country_code,
            name: $bank->name,
            short_name: $bank->short_name,
            bic: $bank->bic,
            rib_bank_code: $bank->rib_bank_code,
            city: $bank->city,
            is_custom: $bank->is_custom,
        );
    }
}
