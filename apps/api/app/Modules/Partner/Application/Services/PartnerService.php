<?php

declare(strict_types=1);

namespace App\Modules\Partner\Application\Services;

use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Shared\Contracts\CoalescingAttributeMergerInterface;
use App\Shared\Contracts\PartnerServiceInterface;
use App\Shared\DTOs\PartnerIdentityResolutionData;
use App\Shared\Enums\PartnerIdentityMatch;
use RuntimeException;

/**
 * Application service for partner operations.
 *
 * Exposes partner functionality to other modules through the PartnerServiceInterface.
 */
final class PartnerService implements PartnerServiceInterface
{
    public function __construct(
        private readonly ?CoalescingAttributeMergerInterface $attributeMerger = null,
    ) {}

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
        $partner = $vatNumber !== null
            ? Partner::withTrashed()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->where('vat_number', $vatNumber)
                ->first()
            : Partner::where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->where('name', $name)
                ->first();

        $partner = $this->refuseSoftDeletedVatHolder($partner);

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

        $resolution = $this->resolveIdentity($tenantId, $companyId, $code, $vatNumber, (string) $data['name']);
        $existing = $resolution->partnerId === null
            ? null
            : Partner::query()->where('company_id', $companyId)->find($resolution->partnerId);
        if ($existing !== null) {
            $code = $existing->code;
        }

        // Smart type merging: customer + supplier = both
        $finalType = $newType;
        if ($existing !== null && $existing->type !== $newType) {
            if ($existing->type === PartnerType::Both || $newType === PartnerType::Both) {
                $finalType = PartnerType::Both;
            } else {
                $finalType = PartnerType::Both;
            }
        }

        $attributes = [
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'code' => $code,
            'name' => (string) $data['name'],
            'type' => $finalType->value,
            'email' => self::nullableString($data['email'] ?? null),
            'phone' => self::nullableString($data['phone'] ?? null),
            'vat_number' => $vatNumber,
            'street_address' => self::nullableString($data['street_address'] ?? $data['address'] ?? null),
            'street_address_2' => self::nullableString($data['street_address_2'] ?? null),
            'city' => self::nullableString($data['city'] ?? null),
            'state' => self::nullableString($data['state'] ?? null),
            'postal_code' => self::nullableString($data['postal_code'] ?? null),
            'country' => self::countryCode($data['country'] ?? null),
            'country_code' => self::countryCode($data['country_code'] ?? $data['country'] ?? null),
        ];
        /** @var array<string, bool|int|string|null> $attributes */
        if ($existing !== null) {
            $provided = is_array($data['_provided'] ?? null)
                ? array_values(array_filter($data['_provided'], static fn (mixed $key): bool => is_string($key)))
                : array_keys($attributes);
            $existingAttributes = [];
            foreach (array_keys($attributes) as $field) {
                $value = $existing->getAttribute($field);
                if (is_bool($value) || is_int($value) || is_string($value) || $value === null) {
                    $existingAttributes[$field] = $value;
                }
            }
            /** @var array<string, bool|int|string|null> $attributes */
            $attributes = $this->attributeMerger !== null
                ? $this->attributeMerger->merge($existingAttributes, $attributes, $provided)
                : $this->mergeWithoutBlankOverwrite($existingAttributes, $attributes, $provided);
            $existing->fill($attributes);
            $existing->save();

            return $existing->id;
        }

        $partner = Partner::create($attributes);

        return $partner->id;
    }

    public function resolveIdentity(
        string $tenantId,
        string $companyId,
        ?string $code,
        ?string $vatNumber,
        string $name,
    ): PartnerIdentityResolutionData {
        $query = Partner::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('company_id', $companyId);

        if ($code !== null) {
            $codeMatch = (clone $query)->where('code', $code)->first();
            if ($codeMatch !== null && ! $codeMatch->trashed()) {
                return new PartnerIdentityResolutionData($codeMatch->id, $codeMatch->code, PartnerIdentityMatch::Code);
            }
        }

        if ($vatNumber !== null) {
            $vatMatch = $this->refuseSoftDeletedVatHolder(
                (clone $query)->where('vat_number', $vatNumber)->first()
            );
            if ($vatMatch !== null) {
                return new PartnerIdentityResolutionData($vatMatch->id, $vatMatch->code, PartnerIdentityMatch::VatNumber);
            }
        }

        if (trim($name) !== '') {
            $nameMatch = Partner::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($name))])
                ->orderBy('id')
                ->first();
            if ($nameMatch !== null) {
                return new PartnerIdentityResolutionData($nameMatch->id, $nameMatch->code, PartnerIdentityMatch::Name);
            }
        }

        return new PartnerIdentityResolutionData(null, null, null);
    }

    /**
     * Compatibility for direct construction in the legacy public-service tests;
     * production resolves the constructor-injected shared merger.
     *
     * @param  array<string, bool|int|string|null>  $existing
     * @param  array<string, bool|int|string|null>  $incoming
     * @param  list<string>  $provided
     * @return array<string, bool|int|string|null>
     */
    private function mergeWithoutBlankOverwrite(array $existing, array $incoming, array $provided): array
    {
        foreach ($incoming as $field => $value) {
            if (in_array($field, $provided, true) || ! array_key_exists($field, $existing)) {
                $existing[$field] = $value;
            }
        }

        return $existing;
    }

    private function refuseSoftDeletedVatHolder(?Partner $partner): ?Partner
    {
        if ($partner?->trashed()) {
            throw new RuntimeException(
                "vat_held_by_deleted_partner: VAT {$partner->vat_number} is held by a soft-deleted partner; "
                .'purge the deleted record or choose a different VAT.'
            );
        }

        return $partner;
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
