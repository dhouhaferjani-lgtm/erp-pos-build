<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\FeeType;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @param  Company|null  $company  Optional specific company to seed for (used during registration)
     */
    public function run(?Company $company = null): void
    {
        // If a specific company is provided, seed only for that company
        if ($company !== null) {
            $tenant = Tenant::find($company->tenant_id);
            if ($tenant === null) {
                return;
            }
            $this->seedPaymentMethodsForCompany($company, $tenant);

            return;
        }

        // Otherwise, seed for first/demo company (dev mode)
        $tenant = Tenant::first();
        $firstCompany = Company::first();

        if (! $tenant || ! $firstCompany) {
            return;
        }

        $this->seedPaymentMethodsForCompany($firstCompany, $tenant);
    }

    /**
     * Seed payment methods for a specific company.
     */
    private function seedPaymentMethodsForCompany(Company $company, Tenant $tenant): void
    {
        $methods = $this->getCountryPaymentMethods($company->country_code);

        foreach ($methods as $method) {
            PaymentMethod::updateOrCreate([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'code' => $method['code'],
            ], $method);
        }

    }

    /**
     * Get payment methods based on country code.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getCountryPaymentMethods(string $countryCode): array
    {
        return match (strtoupper($countryCode)) {
            'TN' => $this->getTunisiaPaymentMethods(),
            'FR' => $this->getFrancePaymentMethods(),
            default => $this->getDefaultPaymentMethods(),
        };
    }

    /**
     * Get Tunisia-specific payment methods.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getTunisiaPaymentMethods(): array
    {
        return [
            // Cash - Espèces
            [
                'code' => 'CASH',
                'name' => 'Espèces',
                'is_physical' => true,
                'is_cash_tender' => true,
                'has_maturity' => false,
                'requires_third_party' => false,
                'is_push' => true,
                'has_deducted_fees' => false,
                'is_restricted' => false,
                'fee_type' => FeeType::None,
                'fee_fixed' => '0.00',
                'fee_percent' => '0.00',
                'position' => 1,
                'is_active' => true,
            ],
            // Check - Chèque
            [
                'code' => 'CHECK',
                'name' => 'Chèque',
                'is_physical' => true,
                'has_maturity' => true,
                'instrument_kind' => InstrumentKind::Cheque,
                'requires_third_party' => false,
                'is_push' => true,
                'has_deducted_fees' => false,
                'is_restricted' => false,
                'fee_type' => FeeType::None,
                'fee_fixed' => '0.00',
                'fee_percent' => '0.00',
                'position' => 2,
                'is_active' => true,
            ],
            // Bank Transfer - Virement Bancaire
            // Register G-5: TN was the only country set without one, while
            // virement is routine in Tunisia — every TN tenant had to create it
            // by hand before it could receive a transfer.
            [
                'code' => 'TRANSFER',
                'name' => 'Virement Bancaire',
                'is_physical' => false,
                'has_maturity' => false,
                'requires_third_party' => false,
                'is_push' => true,
                'has_deducted_fees' => false,
                'is_restricted' => false,
                'fee_type' => FeeType::None,
                'fee_fixed' => '0.00',
                'fee_percent' => '0.00',
                'position' => 3,
                'is_active' => true,
            ],
            // Bank Draft / Promissory Note - Traite
            [
                'code' => 'TRAITE',
                'name' => 'Traite',
                'is_physical' => true,
                'has_maturity' => true,
                'instrument_kind' => InstrumentKind::Effet,
                'requires_third_party' => false,
                'is_push' => false,
                'has_deducted_fees' => false,
                'is_restricted' => false,
                'fee_type' => FeeType::None,
                'fee_fixed' => '0.00',
                'fee_percent' => '0.00',
                'position' => 4,
                'is_active' => true,
            ],
            // Credit/Debit Card - Carte Bancaire
            [
                'code' => 'CARD',
                'name' => 'Carte Bancaire',
                'is_physical' => false,
                'has_maturity' => false,
                'requires_third_party' => true,
                'is_push' => true,
                'has_deducted_fees' => true,
                'is_restricted' => false,
                'fee_type' => FeeType::Percentage,
                'fee_fixed' => '0.00',
                'fee_percent' => '1.50',
                'position' => 5,
                'is_active' => true,
            ],
            // Digital Wallet - Portefeuille Digital (D17, Konnect, etc.)
            [
                'code' => 'WALLET',
                'name' => 'Portefeuille Digital',
                'is_physical' => false,
                'has_maturity' => false,
                'requires_third_party' => true,
                'is_push' => true,
                'has_deducted_fees' => true,
                'is_restricted' => false,
                'fee_type' => FeeType::Mixed,
                'fee_fixed' => '0.30',
                'fee_percent' => '1.00',
                'position' => 6,
                'is_active' => true,
            ],
            // Loyalty Points - Points de Fidélité
            [
                'code' => 'LOYALTY',
                'name' => 'Points de Fidélité',
                'is_physical' => false,
                'has_maturity' => false,
                'requires_third_party' => false,
                'is_push' => true,
                'has_deducted_fees' => false,
                'is_restricted' => true,
                'fee_type' => FeeType::None,
                'fee_fixed' => '0.00',
                'fee_percent' => '0.00',
                'position' => 7,
                'is_active' => true,
            ],
        ];
    }

    /**
     * Get France-specific payment methods.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getFrancePaymentMethods(): array
    {
        return [
            // Cash - Espèces
            [
                'code' => 'CASH',
                'name' => 'Espèces',
                'is_physical' => true,
                'is_cash_tender' => true,
                'has_maturity' => false,
                'requires_third_party' => false,
                'is_push' => true,
                'has_deducted_fees' => false,
                'is_restricted' => false,
                'fee_type' => FeeType::None,
                'fee_fixed' => '0.00',
                'fee_percent' => '0.00',
                'position' => 1,
                'is_active' => true,
            ],
            // Check - Chèque
            [
                'code' => 'CHECK',
                'name' => 'Chèque',
                'is_physical' => true,
                'has_maturity' => true,
                'instrument_kind' => InstrumentKind::Cheque,
                'requires_third_party' => false,
                'is_push' => true,
                'has_deducted_fees' => false,
                'is_restricted' => false,
                'fee_type' => FeeType::None,
                'fee_fixed' => '0.00',
                'fee_percent' => '0.00',
                'position' => 2,
                'is_active' => true,
            ],
            // Bank Transfer - Virement Bancaire
            [
                'code' => 'TRANSFER',
                'name' => 'Virement Bancaire',
                'is_physical' => false,
                'has_maturity' => false,
                'requires_third_party' => false,
                'is_push' => true,
                'has_deducted_fees' => false,
                'is_restricted' => false,
                'fee_type' => FeeType::None,
                'fee_fixed' => '0.00',
                'fee_percent' => '0.00',
                'position' => 3,
                'is_active' => true,
            ],
            // Credit/Debit Card - Carte Bancaire
            [
                'code' => 'CARD',
                'name' => 'Carte Bancaire',
                'is_physical' => false,
                'has_maturity' => false,
                'requires_third_party' => true,
                'is_push' => true,
                'has_deducted_fees' => true,
                'is_restricted' => false,
                'fee_type' => FeeType::Percentage,
                'fee_fixed' => '0.00',
                'fee_percent' => '1.50',
                'position' => 4,
                'is_active' => true,
            ],
            // Direct Debit - Prélèvement
            [
                'code' => 'DIRECT_DEBIT',
                'name' => 'Prélèvement',
                'is_physical' => false,
                'has_maturity' => true,
                'instrument_kind' => InstrumentKind::Other,
                'requires_third_party' => false,
                'is_push' => false,
                'has_deducted_fees' => false,
                'is_restricted' => false,
                'fee_type' => FeeType::None,
                'fee_fixed' => '0.00',
                'fee_percent' => '0.00',
                'position' => 5,
                'is_active' => true,
            ],
            // Promissory Note (LCR - Letter de Change Relevé)
            [
                'code' => 'LCR',
                'name' => 'LCR (Lettre de Change Relevé)',
                'is_physical' => true,
                'has_maturity' => true,
                'instrument_kind' => InstrumentKind::Effet,
                'requires_third_party' => false,
                'is_push' => false,
                'has_deducted_fees' => false,
                'is_restricted' => false,
                'fee_type' => FeeType::None,
                'fee_fixed' => '0.00',
                'fee_percent' => '0.00',
                'position' => 6,
                'is_active' => true,
            ],
            // PayPal
            [
                'code' => 'PAYPAL',
                'name' => 'PayPal',
                'is_physical' => false,
                'has_maturity' => false,
                'requires_third_party' => true,
                'is_push' => true,
                'has_deducted_fees' => true,
                'is_restricted' => false,
                'fee_type' => FeeType::Mixed,
                'fee_fixed' => '0.35',
                'fee_percent' => '2.90',
                'position' => 7,
                'is_active' => true,
            ],
            // Meal Voucher (Ticket Restaurant)
            [
                'code' => 'MEAL_VOUCHER',
                'name' => 'Ticket Restaurant',
                'is_physical' => true,
                'has_maturity' => false,
                'requires_third_party' => true,
                'is_push' => true,
                'has_deducted_fees' => true,
                'is_restricted' => true,
                'fee_type' => FeeType::Percentage,
                'fee_fixed' => '0.00',
                'fee_percent' => '1.50',
                'position' => 8,
                'is_active' => true,
            ],
            // Bill of Exchange - Lettre de Change
            [
                'code' => 'BILL_EXCHANGE',
                'name' => 'Lettre de Change',
                'is_physical' => true,
                'has_maturity' => true,
                'instrument_kind' => InstrumentKind::Effet,
                'requires_third_party' => false,
                'is_push' => false,
                'has_deducted_fees' => false,
                'is_restricted' => false,
                'fee_type' => FeeType::None,
                'fee_fixed' => '0.00',
                'fee_percent' => '0.00',
                'position' => 9,
                'is_active' => true,
            ],
        ];
    }

    /**
     * Get default payment methods for countries without specific configuration.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getDefaultPaymentMethods(): array
    {
        return [
            // Cash
            [
                'code' => 'CASH',
                'name' => 'Cash',
                'is_physical' => true,
                'is_cash_tender' => true,
                'has_maturity' => false,
                'requires_third_party' => false,
                'is_push' => true,
                'has_deducted_fees' => false,
                'is_restricted' => false,
                'fee_type' => FeeType::None,
                'fee_fixed' => '0.00',
                'fee_percent' => '0.00',
                'position' => 1,
                'is_active' => true,
            ],
            // Check
            [
                'code' => 'CHECK',
                'name' => 'Check',
                'is_physical' => true,
                'has_maturity' => true,
                'instrument_kind' => InstrumentKind::Cheque,
                'requires_third_party' => false,
                'is_push' => true,
                'has_deducted_fees' => false,
                'is_restricted' => false,
                'fee_type' => FeeType::None,
                'fee_fixed' => '0.00',
                'fee_percent' => '0.00',
                'position' => 2,
                'is_active' => true,
            ],
            // Bank Transfer
            [
                'code' => 'TRANSFER',
                'name' => 'Bank Transfer',
                'is_physical' => false,
                'has_maturity' => false,
                'requires_third_party' => false,
                'is_push' => true,
                'has_deducted_fees' => false,
                'is_restricted' => false,
                'fee_type' => FeeType::None,
                'fee_fixed' => '0.00',
                'fee_percent' => '0.00',
                'position' => 3,
                'is_active' => true,
            ],
            // Credit/Debit Card
            [
                'code' => 'CARD',
                'name' => 'Credit/Debit Card',
                'is_physical' => false,
                'has_maturity' => false,
                'requires_third_party' => true,
                'is_push' => true,
                'has_deducted_fees' => true,
                'is_restricted' => false,
                'fee_type' => FeeType::Percentage,
                'fee_fixed' => '0.00',
                'fee_percent' => '1.50',
                'position' => 4,
                'is_active' => true,
            ],
        ];
    }
}
