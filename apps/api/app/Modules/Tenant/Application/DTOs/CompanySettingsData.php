<?php

declare(strict_types=1);

namespace App\Modules\Tenant\Application\DTOs;

use App\Modules\Tenant\Domain\Tenant;

class CompanySettingsData
{
    /**
     * @param  array<string, string|null>|null  $address
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $legalName,
        public readonly ?string $taxId,
        public readonly ?string $registrationNumber,
        public readonly ?array $address,
        public readonly ?string $phone,
        public readonly ?string $email,
        public readonly ?string $website,
        public readonly ?string $logoUrl,
        public readonly ?string $primaryColor,
        public readonly ?string $countryCode,
        public readonly ?string $currencyCode,
        public readonly ?string $timezone,
        public readonly ?string $dateFormat,
        public readonly ?string $locale,
    ) {}

    /**
     * Create from a Tenant model.
     *
     * @return array<string, mixed>
     */
    public static function fromTenant(Tenant $tenant): array
    {
        $address = $tenant->address ?? [];

        return [
            'name' => $tenant->name,
            'legal_name' => $tenant->legal_name,
            'tax_id' => $tenant->tax_id,
            'registration_number' => $tenant->registration_number,
            'address' => [
                'street' => $address['street'] ?? null,
                'city' => $address['city'] ?? null,
                'postal_code' => $address['postal_code'] ?? null,
                'country' => $address['country'] ?? $tenant->country_code,
            ],
            'phone' => $tenant->phone,
            'email' => $tenant->email,
            'website' => $tenant->website,
            'logo_url' => $tenant->logo_path ? asset('storage/'.$tenant->logo_path) : null,
            'primary_color' => $tenant->primary_color,
            'country_code' => $tenant->country_code,
            'currency_code' => $tenant->currency_code,
            'timezone' => $tenant->timezone,
            'date_format' => $tenant->date_format,
            'locale' => $tenant->locale,
        ];
    }
}
