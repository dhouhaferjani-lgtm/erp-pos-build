<?php

declare(strict_types=1);

namespace App\Shared\Banking\Domain\ValueObjects;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class RibValidationResult extends Data
{
    /**
     * @param  array<int, string>  $errors
     */
    public function __construct(
        public bool $valid,
        public string $normalized,
        public ?string $iban,
        public ?string $bic,
        public ?string $bank_code,
        public ?string $bank_name,
        public array $errors,
    ) {}
}
