<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\DTOs;

readonly class VatDeclarationData
{
    /**
     * @param  array<string, string|int|float>  $fields  Country-specific declaration fields
     * @param  string  $formReference  Reference to the official form (e.g., "CA3", "DGI", "VAT100")
     */
    public function __construct(
        public array $fields,
        public string $formReference,
    ) {}

    /** @return array<string, string|int|float|array<string, string|int|float>> */
    public function toArray(): array
    {
        return [
            'form_reference' => $this->formReference,
            'fields' => $this->fields,
        ];
    }
}
