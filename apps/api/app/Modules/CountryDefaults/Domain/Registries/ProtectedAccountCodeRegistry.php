<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Domain\Registries;

final class ProtectedAccountCodeRegistry
{
    /** @var list<array{code: string, type: string}> */
    private const TN_INSTRUMENTS = [
        ['code' => '5312', 'type' => 'asset'],
        ['code' => '4035', 'type' => 'liability'],
        ['code' => '413', 'type' => 'asset'],
        ['code' => '403', 'type' => 'liability'],
        ['code' => '5313', 'type' => 'asset'],
        ['code' => '5314', 'type' => 'asset'],
        ['code' => '6275', 'type' => 'expense'],
        ['code' => '43666', 'type' => 'asset'],
        ['code' => '416', 'type' => 'asset'],
    ];

    /** @var list<array{code: string, type: string}> */
    private const NON_TN_INSTRUMENTS = [
        ['code' => '5112', 'type' => 'asset'],
        ['code' => '4035', 'type' => 'liability'],
        ['code' => '413', 'type' => 'asset'],
        ['code' => '403', 'type' => 'liability'],
        ['code' => '5113', 'type' => 'asset'],
        ['code' => '5114', 'type' => 'asset'],
        ['code' => '627', 'type' => 'expense'],
        ['code' => '44566', 'type' => 'asset'],
        ['code' => '416', 'type' => 'asset'],
    ];

    /** @var list<string> */
    private const FRENCH_PLAN_DEMO_CODES = ['613', '615', '616', '624', '626', '6061', '6064'];

    /** @var list<string> */
    private const GENERIC_DEMO_CODES = ['6130', '6170', '6250', '6256'];

    /**
     * @return list<array{code: string, expected_type: string, requires_system: bool, protection_source: string, reason: string}>
     */
    public static function forCountry(string $countryCode): array
    {
        $normalized = strtoupper(trim($countryCode));
        $instruments = $normalized === 'TN' ? self::TN_INSTRUMENTS : self::NON_TN_INSTRUMENTS;
        $demoCodes = in_array($normalized, ['TN', 'FR'], true)
            ? self::FRENCH_PLAN_DEMO_CODES
            : self::GENERIC_DEMO_CODES;

        $entries = [];
        foreach ($instruments as $instrument) {
            $entries[] = [
                'code' => $instrument['code'],
                'expected_type' => $instrument['type'],
                'requires_system' => true,
                'protection_source' => 'treasury_instrument_literal',
                'reason' => 'InstrumentAccountResolver resolves this account by literal code and account type.',
            ];
        }
        foreach ($demoCodes as $code) {
            $entries[] = [
                'code' => $code,
                'expected_type' => 'expense',
                'requires_system' => false,
                'protection_source' => 'expense_category_demo_consumer',
                'reason' => 'ExpenseCategorySeeder resolves this demo category by literal account code.',
            ];
        }

        return $entries;
    }
}
