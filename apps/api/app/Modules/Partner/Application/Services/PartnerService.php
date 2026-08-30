<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\Services;

use App\Modules\Partner\Domain\Partner;
use App\Shared\Contracts\PartnerServiceInterface;

/**
 * Application service for partner operations.
 *
 * Exposes partner functionality to other modules through the PartnerServiceInterface.
 */
final class PartnerService implements PartnerServiceInterface
{
    public function resolveScopedPartnerId(
        string $tenantId,
        string $companyId,
        string $partnerId,
    ): ?string {
        $id = Partner::query()
            ->withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->whereKey($partnerId)
            ->value('id');

        return is_string($id) ? $id : null;
    }

    /**
     * Find a partner by VAT number or name.
     *
     * @return array{id: string, type: string}|null Partner info or null if not found
     */
    public function findByVatOrName(
        string $tenantId,
        string $companyId,
        ?string $vatNumber,
        string $name
    ): ?array {
        $partner = Partner::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->when($vatNumber !== null, fn ($q) => $q->where('vat_number', $vatNumber))
            ->when($vatNumber === null, fn ($q) => $q->where('name', $name))
            ->first();

        if ($partner === null) {
            return null;
        }

        return [
            'id' => $partner->id,
            'type' => $partner->type->value,
        ];
    }

    /**
     * Create or update a partner with smart type merging.
     *
     * If a partner exists with a different type, the result will be 'both'.
     * Matching precedence is company-scoped code, then VAT number, then name.
     *
     * @param  array<string, mixed>  $data  Partner data
     * @return string The partner ID
     */
    public function upsertWithTypeMerge(
        string $tenantId,
        string $companyId,
        array $data
    ): string {
        $code = isset($data['code']) ? trim((string) $data['code']) : null;
        $code = $code === '' ? null : $code;
        $vatNumber = ! empty($data['vat_number']) ? $data['vat_number'] : null;
        $newType = PartnerType::from($data['type']);

        // Find existing partner
        $existing = Partner::where('tenant_id', $tenantId)
            ->where('company_id', $companyId)
            ->when($code !== null, fn ($q) => $q->where('code', $code))
            ->when($code === null && $vatNumber !== null, fn ($q) => $q->where('vat_number', $vatNumber))
            ->when($code === null && $vatNumber === null, fn ($q) => $q->where('name', $data['name']))
            ->first();

        // Smart type merging: customer + supplier = both
        $finalType = $newType;
        if ($existing !== null && $existing->type !== $newType) {
            if ($existing->type === PartnerType::Both || $newType === PartnerType::Both) {
                $finalType = PartnerType::Both;
            } else {
                $finalType = PartnerType::Both;
            }
        }

        $searchCriteria = match (true) {
            $code !== null => ['tenant_id' => $tenantId, 'company_id' => $companyId, 'code' => $code],
            $vatNumber !== null => ['tenant_id' => $tenantId, 'company_id' => $companyId, 'vat_number' => $vatNumber],
            default => ['tenant_id' => $tenantId, 'company_id' => $companyId, 'name' => $data['name']],
        };

        $partner = Partner::updateOrCreate(
            $searchCriteria,
            [
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'code' => $code,
                'name' => $data['name'],
                'type' => $finalType,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'vat_number' => $vatNumber,
                'street_address' => self::nullableString($data['street_address'] ?? $data['address'] ?? null),
                'street_address_2' => self::nullableString($data['street_address_2'] ?? null),
                'city' => self::nullableString($data['city'] ?? null),
                'state' => self::nullableString($data['state'] ?? null),
                'postal_code' => self::nullableString($data['postal_code'] ?? null),
                'country' => self::countryCode($data['country'] ?? null),
                'country_code' => self::countryCode($data['country_code'] ?? $data['country'] ?? null),
            ]
        );

        return $partner->id;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Country names → ISO 3166-1 alpha-2 codes for CSV import resolution.
     *
     * The partners.country / country_code columns are CHAR(2). CSV imports carry
     * human-readable names ("Tunisie", "United Kingdom") — inserting those raw
     * fails the whole row with a 22001 truncation error. Covers the product's
     * primary markets in French and English; keys are mb_strtoupper'd.
     *
     * @var array<string, string>
     */
    private const COUNTRY_NAME_TO_CODE = [
        'TUNISIE' => 'TN',
        'TUNISIA' => 'TN',
        'FRANCE' => 'FR',
        'MAROC' => 'MA',
        'MOROCCO' => 'MA',
        'ALGERIE' => 'DZ',
        'ALGÉRIE' => 'DZ',
        'ALGERIA' => 'DZ',
        'LIBYE' => 'LY',
        'LIBYA' => 'LY',
        'EGYPTE' => 'EG',
        'ÉGYPTE' => 'EG',
        'EGYPT' => 'EG',
        'ITALIE' => 'IT',
        'ITALY' => 'IT',
        'ESPAGNE' => 'ES',
        'SPAIN' => 'ES',
        'PORTUGAL' => 'PT',
        'ALLEMAGNE' => 'DE',
        'GERMANY' => 'DE',
        'BELGIQUE' => 'BE',
        'BELGIUM' => 'BE',
        'SUISSE' => 'CH',
        'SWITZERLAND' => 'CH',
        'ROYAUME-UNI' => 'GB',
        'UNITED KINGDOM' => 'GB',
        'CANADA' => 'CA',
        'ETATS-UNIS' => 'US',
        'ÉTATS-UNIS' => 'US',
        'UNITED STATES' => 'US',
        'TURQUIE' => 'TR',
        'TURKEY' => 'TR',
    ];

    /**
     * Resolve a country value to an ISO 3166-1 alpha-2 code.
     *
     * Accepts 2-letter codes as-is (uppercased); resolves known French/English
     * country names; otherwise returns null so the row still imports
     * (address/city persist, country stays empty).
     */
    private static function countryCode(mixed $value): ?string
    {
        $value = self::nullableString($value);
        if ($value === null) {
            return null;
        }

        if (preg_match('/^[A-Za-z]{2}$/', $value) === 1) {
            return strtoupper($value);
        }

        return self::COUNTRY_NAME_TO_CODE[mb_strtoupper($value)] ?? null;
    }
}
