<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Database\Seeders\Contracts\ChartOfAccountsSeederContract;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tunisia Chart of Accounts (Plan Comptable Tunisien) Seeder.
 *
 * Seeds a standard Tunisian chart of accounts for a given company.
 * Assigns system_purpose values to key accounts for country-agnostic GL operations.
 *
 * Implements {@see ChartOfAccountsSeederContract} so it can be returned
 * type-safely from {@see \Database\Seeders\DemoPharmacySeeder::localeChartOfAccountsSeeder()}.
 */
final class TunisiaChartOfAccountsSeeder extends Seeder implements ChartOfAccountsSeederContract
{
    /**
     * Run the database seeds.
     *
     * @param  string  $companyId  The company to seed accounts for
     * @param  string|null  $tenantId  The tenant (for backward compatibility)
     */
    public function run(string $companyId, ?string $tenantId = null): void
    {
        $now = now();
        $accounts = $this->getAccountsDefinition();

        // Create parent accounts map for linking
        $accountIdMap = [];

        // First pass: Create all accounts without parent links
        foreach ($accounts as $account) {
            $id = Str::uuid()->toString();
            $accountIdMap[$account['code']] = $id;

            DB::table('accounts')->insert([
                'id' => $id,
                'tenant_id' => $tenantId,
                'company_id' => $companyId,
                'parent_id' => null, // Will be updated in second pass
                'code' => $account['code'],
                'name' => $account['name'],
                'type' => $account['type'],
                'system_purpose' => $account['system_purpose'] ?? null,
                'is_active' => true,
                'is_system' => $account['is_system'] ?? false,
                'balance' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Second pass: Update parent relationships
        foreach ($accounts as $account) {
            if ($account['parent_code'] !== null && isset($accountIdMap[$account['parent_code']])) {
                DB::table('accounts')
                    ->where('id', $accountIdMap[$account['code']])
                    ->update(['parent_id' => $accountIdMap[$account['parent_code']]]);
            }
        }

        if ($this->command !== null) {
            $this->command->info(sprintf(
                'Tunisia chart of accounts seeded successfully for company %s (%d accounts created)',
                $companyId,
                count($accounts)
            ));
        }
    }

    /**
     * Get the country code this seeder is for.
     */
    public static function getCountryCode(): string
    {
        return 'TN';
    }

    /**
     * Tunisia PCN (Plan Comptable National) account definitions with system purposes.
     *
     * @return list<array{code: string, name: string, type: string, parent_code: string|null, system_purpose?: string, is_system?: bool}>
     */
    private function getAccountsDefinition(): array
    {
        return [
            // Class 1: Capitaux (Equity)
            ['code' => '1', 'name' => 'CAPITAUX', 'type' => 'equity', 'parent_code' => null, 'is_system' => true],
            ['code' => '10', 'name' => 'Capital social', 'type' => 'equity', 'parent_code' => '1'],
            ['code' => '11', 'name' => 'Réserves', 'type' => 'equity', 'parent_code' => '1'],
            ['code' => '12', 'name' => 'Report à nouveau', 'type' => 'equity', 'parent_code' => '1',
                'system_purpose' => SystemAccountPurpose::RetainedEarnings->value, 'is_system' => true],
            ['code' => '119', 'name' => 'Solde d\'ouverture', 'type' => 'equity', 'parent_code' => '1',
                'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity->value, 'is_system' => true],
            ['code' => '13', 'name' => 'Résultat de l\'exercice', 'type' => 'equity', 'parent_code' => '1'],
            ['code' => '14', 'name' => 'Subventions d\'investissement', 'type' => 'equity', 'parent_code' => '1'],
            ['code' => '15', 'name' => 'Provisions réglementées', 'type' => 'equity', 'parent_code' => '1'],
            ['code' => '16', 'name' => 'Emprunts et dettes assimilées', 'type' => 'liability', 'parent_code' => '1'],
            ['code' => '17', 'name' => 'Dettes rattachées à des participations', 'type' => 'liability', 'parent_code' => '1'],

            // Class 2: Immobilisations (Fixed Assets)
            ['code' => '2', 'name' => 'IMMOBILISATIONS', 'type' => 'asset', 'parent_code' => null, 'is_system' => true],
            ['code' => '20', 'name' => 'Immobilisations incorporelles', 'type' => 'asset', 'parent_code' => '2'],
            ['code' => '21', 'name' => 'Immobilisations corporelles', 'type' => 'asset', 'parent_code' => '2'],
            ['code' => '211', 'name' => 'Terrains', 'type' => 'asset', 'parent_code' => '21'],
            ['code' => '212', 'name' => 'Agencements et aménagements de terrains', 'type' => 'asset', 'parent_code' => '21'],
            ['code' => '213', 'name' => 'Constructions', 'type' => 'asset', 'parent_code' => '21'],
            ['code' => '215', 'name' => 'Installations techniques', 'type' => 'asset', 'parent_code' => '21'],
            ['code' => '218', 'name' => 'Matériel et outillage', 'type' => 'asset', 'parent_code' => '21'],
            ['code' => '2182', 'name' => 'Matériel et outillage industriel', 'type' => 'asset', 'parent_code' => '218'],
            ['code' => '22', 'name' => 'Immobilisations mises en concession', 'type' => 'asset', 'parent_code' => '2'],
            ['code' => '23', 'name' => 'Immobilisations en cours', 'type' => 'asset', 'parent_code' => '2'],
            ['code' => '24', 'name' => 'Immobilisations financières', 'type' => 'asset', 'parent_code' => '2'],
            ['code' => '28', 'name' => 'Amortissements des immobilisations', 'type' => 'asset', 'parent_code' => '2'],

            // Class 3: Stocks (Inventory)
            ['code' => '3', 'name' => 'STOCKS', 'type' => 'asset', 'parent_code' => null, 'is_system' => true],
            ['code' => '31', 'name' => 'Matières premières et fournitures', 'type' => 'asset', 'parent_code' => '3'],
            ['code' => '32', 'name' => 'Autres approvisionnements', 'type' => 'asset', 'parent_code' => '3'],
            ['code' => '33', 'name' => 'En-cours de production de biens', 'type' => 'asset', 'parent_code' => '3'],
            ['code' => '35', 'name' => 'Stocks de produits', 'type' => 'asset', 'parent_code' => '3'],
            ['code' => '37', 'name' => 'Stocks de marchandises', 'type' => 'asset', 'parent_code' => '3',
                'system_purpose' => SystemAccountPurpose::Inventory->value, 'is_system' => true],
            ['code' => '39', 'name' => 'Dépréciations des stocks', 'type' => 'asset', 'parent_code' => '3'],

            // Class 4: Tiers (Third Parties / Accounts Receivable & Payable)
            ['code' => '4', 'name' => 'TIERS', 'type' => 'asset', 'parent_code' => null, 'is_system' => true],
            ['code' => '40', 'name' => 'Fournisseurs et comptes rattachés', 'type' => 'liability', 'parent_code' => '4', 'is_system' => true],
            ['code' => '401', 'name' => 'Fournisseurs', 'type' => 'liability', 'parent_code' => '40',
                'system_purpose' => SystemAccountPurpose::SupplierPayable->value, 'is_system' => true],
            ['code' => '4011', 'name' => 'Fournisseurs - Achats de biens', 'type' => 'liability', 'parent_code' => '401'],
            ['code' => '4017', 'name' => 'Fournisseurs - Retenues de garantie', 'type' => 'liability', 'parent_code' => '401'],
            ['code' => '408', 'name' => 'Fournisseurs - Factures non parvenues', 'type' => 'liability', 'parent_code' => '40'],
            ['code' => '409', 'name' => 'Fournisseurs débiteurs', 'type' => 'asset', 'parent_code' => '40',
                'system_purpose' => SystemAccountPurpose::SupplierAdvance->value, 'is_system' => true],
            ['code' => '41', 'name' => 'Clients et comptes rattachés', 'type' => 'asset', 'parent_code' => '4', 'is_system' => true],
            ['code' => '411', 'name' => 'Clients', 'type' => 'asset', 'parent_code' => '41',
                'system_purpose' => SystemAccountPurpose::CustomerReceivable->value, 'is_system' => true],
            ['code' => '413', 'name' => 'Clients - Effets à recevoir', 'type' => 'asset', 'parent_code' => '41'],
            ['code' => '416', 'name' => 'Clients douteux', 'type' => 'asset', 'parent_code' => '41'],
            ['code' => '419', 'name' => 'Clients créditeurs', 'type' => 'liability', 'parent_code' => '41',
                'system_purpose' => SystemAccountPurpose::CustomerAdvance->value, 'is_system' => true],
            ['code' => '42', 'name' => 'Personnel et comptes rattachés', 'type' => 'liability', 'parent_code' => '4'],
            ['code' => '421', 'name' => 'Personnel - Rémunérations dues', 'type' => 'liability', 'parent_code' => '42'],
            ['code' => '43', 'name' => 'Organismes sociaux', 'type' => 'liability', 'parent_code' => '4'],
            ['code' => '431', 'name' => 'CNSS', 'type' => 'liability', 'parent_code' => '43'],
            ['code' => '44', 'name' => 'État et collectivités publiques', 'type' => 'liability', 'parent_code' => '4', 'is_system' => true],
            ['code' => '442', 'name' => 'État - Impôts et taxes', 'type' => 'liability', 'parent_code' => '44'],
            ['code' => '4455', 'name' => 'TVA à décaisser', 'type' => 'liability', 'parent_code' => '44'],
            ['code' => '4456', 'name' => 'TVA déductible', 'type' => 'asset', 'parent_code' => '44',
                'system_purpose' => SystemAccountPurpose::VatDeductible->value, 'is_system' => true],
            ['code' => '4457', 'name' => 'TVA collectée', 'type' => 'liability', 'parent_code' => '44',
                'system_purpose' => SystemAccountPurpose::VatCollected->value, 'is_system' => true],
            ['code' => '45', 'name' => 'Groupe et associés', 'type' => 'asset', 'parent_code' => '4'],
            ['code' => '46', 'name' => 'Débiteurs et créditeurs divers', 'type' => 'asset', 'parent_code' => '4'],
            ['code' => '47', 'name' => 'Comptes transitoires ou d\'attente', 'type' => 'asset', 'parent_code' => '4'],
            ['code' => '48', 'name' => 'Comptes de régularisation', 'type' => 'asset', 'parent_code' => '4'],
            ['code' => '49', 'name' => 'Dépréciations des comptes de tiers', 'type' => 'asset', 'parent_code' => '4'],

            // Class 5: Financiers (Financial Accounts / Cash & Bank)
            ['code' => '5', 'name' => 'FINANCIERS', 'type' => 'asset', 'parent_code' => null, 'is_system' => true],
            ['code' => '50', 'name' => 'Valeurs mobilières de placement', 'type' => 'asset', 'parent_code' => '5'],
            ['code' => '51', 'name' => 'Banques et établissements financiers', 'type' => 'asset', 'parent_code' => '5', 'is_system' => true],
            ['code' => '512', 'name' => 'Banques', 'type' => 'asset', 'parent_code' => '51',
                'system_purpose' => SystemAccountPurpose::Bank->value, 'is_system' => true],
            ['code' => '53', 'name' => 'Caisse', 'type' => 'asset', 'parent_code' => '5',
                'system_purpose' => SystemAccountPurpose::Cash->value, 'is_system' => true],
            ['code' => '531', 'name' => 'Caisse siège', 'type' => 'asset', 'parent_code' => '53'],
            ['code' => '54', 'name' => 'Régies d\'avances et accréditifs', 'type' => 'asset', 'parent_code' => '5'],

            // Class 6: Charges (Expenses)
            ['code' => '6', 'name' => 'CHARGES', 'type' => 'expense', 'parent_code' => null, 'is_system' => true],
            ['code' => '60', 'name' => 'Achats', 'type' => 'expense', 'parent_code' => '6', 'is_system' => true],
            ['code' => '601', 'name' => 'Achats stockés - Matières premières', 'type' => 'expense', 'parent_code' => '60'],
            ['code' => '602', 'name' => 'Achats stockés - Autres approvisionnements', 'type' => 'expense', 'parent_code' => '60'],
            ['code' => '603', 'name' => 'Variation des stocks', 'type' => 'expense', 'parent_code' => '60',
                'system_purpose' => SystemAccountPurpose::CostOfGoodsSold->value, 'is_system' => true],
            ['code' => '607', 'name' => 'Achats de marchandises', 'type' => 'expense', 'parent_code' => '60',
                'system_purpose' => SystemAccountPurpose::PurchaseExpenses->value, 'is_system' => true],
            ['code' => '61', 'name' => 'Services extérieurs', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '611', 'name' => 'Sous-traitance générale', 'type' => 'expense', 'parent_code' => '61'],
            ['code' => '613', 'name' => 'Locations', 'type' => 'expense', 'parent_code' => '61'],
            ['code' => '614', 'name' => 'Charges locatives et de copropriété', 'type' => 'expense', 'parent_code' => '61'],
            ['code' => '615', 'name' => 'Entretien et réparations', 'type' => 'expense', 'parent_code' => '61'],
            ['code' => '616', 'name' => 'Primes d\'assurances', 'type' => 'expense', 'parent_code' => '61'],
            ['code' => '62', 'name' => 'Autres services extérieurs', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '621', 'name' => 'Personnel extérieur', 'type' => 'expense', 'parent_code' => '62'],
            ['code' => '622', 'name' => 'Rémunérations d\'intermédiaires et honoraires', 'type' => 'expense', 'parent_code' => '62'],
            ['code' => '623', 'name' => 'Publicité, publications, relations publiques', 'type' => 'expense', 'parent_code' => '62'],
            ['code' => '624', 'name' => 'Transport de biens et transport collectif', 'type' => 'expense', 'parent_code' => '62'],
            ['code' => '625', 'name' => 'Déplacements, missions et réceptions', 'type' => 'expense', 'parent_code' => '62'],
            ['code' => '626', 'name' => 'Frais postaux et frais de télécommunications', 'type' => 'expense', 'parent_code' => '62'],
            ['code' => '627', 'name' => 'Services bancaires et assimilés', 'type' => 'expense', 'parent_code' => '62'],
            ['code' => '628', 'name' => 'Cotisations et divers', 'type' => 'expense', 'parent_code' => '62'],
            ['code' => '63', 'name' => 'Impôts, taxes et versements assimilés', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '64', 'name' => 'Charges de personnel', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '641', 'name' => 'Rémunérations du personnel', 'type' => 'expense', 'parent_code' => '64'],
            ['code' => '645', 'name' => 'Charges de sécurité sociale et de prévoyance', 'type' => 'expense', 'parent_code' => '64'],
            ['code' => '65', 'name' => 'Autres charges de gestion courante', 'type' => 'expense', 'parent_code' => '6',
                'system_purpose' => SystemAccountPurpose::GeneralExpense->value, 'is_system' => true],
            ['code' => '66', 'name' => 'Charges financières', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '661', 'name' => 'Charges d\'intérêts', 'type' => 'expense', 'parent_code' => '66'],
            ['code' => '67', 'name' => 'Charges exceptionnelles', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '68', 'name' => 'Dotations aux amortissements', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '69', 'name' => 'Impôts sur les bénéfices', 'type' => 'expense', 'parent_code' => '6'],

            // Class 7: Produits (Revenue)
            ['code' => '7', 'name' => 'PRODUITS', 'type' => 'revenue', 'parent_code' => null, 'is_system' => true],
            ['code' => '70', 'name' => 'Ventes de produits fabriqués', 'type' => 'revenue', 'parent_code' => '7', 'is_system' => true],
            ['code' => '701', 'name' => 'Ventes de produits finis', 'type' => 'revenue', 'parent_code' => '70'],
            ['code' => '702', 'name' => 'Ventes de produits intermédiaires', 'type' => 'revenue', 'parent_code' => '70'],
            ['code' => '703', 'name' => 'Ventes de produits résiduels', 'type' => 'revenue', 'parent_code' => '70'],
            ['code' => '704', 'name' => 'Travaux', 'type' => 'revenue', 'parent_code' => '70'],
            ['code' => '705', 'name' => 'Études', 'type' => 'revenue', 'parent_code' => '70'],
            ['code' => '706', 'name' => 'Prestations de services', 'type' => 'revenue', 'parent_code' => '70',
                'system_purpose' => SystemAccountPurpose::ServiceRevenue->value, 'is_system' => true],
            ['code' => '707', 'name' => 'Ventes de marchandises', 'type' => 'revenue', 'parent_code' => '70',
                'system_purpose' => SystemAccountPurpose::ProductRevenue->value, 'is_system' => true],
            ['code' => '708', 'name' => 'Produits des activités annexes', 'type' => 'revenue', 'parent_code' => '70'],
            ['code' => '709', 'name' => 'Rabais, remises et ristournes accordés', 'type' => 'revenue', 'parent_code' => '70'],

            // Voucher accounting — EU Directive 2016/1065 MPV layer (non-taxable; Phase 1)
            ['code' => '7091', 'name' => 'Remboursements clients - Virements bons d\'achat', 'type' => 'expense', 'parent_code' => '70',
                'system_purpose' => SystemAccountPurpose::SalesReturnsClearing->value, 'is_system' => true],
            ['code' => '4197', 'name' => 'Clients - Bons d\'achat émis (passif courant)', 'type' => 'liability', 'parent_code' => '41',
                'system_purpose' => SystemAccountPurpose::VoucherLiability->value, 'is_system' => true],
            ['code' => '6238', 'name' => 'Dépenses de bonne volonté commerciale', 'type' => 'expense', 'parent_code' => '62',
                'system_purpose' => SystemAccountPurpose::MarketingGoodwillExpense->value, 'is_system' => true],
            ['code' => '7592', 'name' => 'Produits sur bons d\'achat non utilisés (breakage)', 'type' => 'revenue', 'parent_code' => '75',
                'system_purpose' => SystemAccountPurpose::VoucherBreakageIncome->value, 'is_system' => true],
            ['code' => '6588', 'name' => 'Pertes d\'arrondis sur bons d\'achat', 'type' => 'expense', 'parent_code' => '65',
                'system_purpose' => SystemAccountPurpose::RoundingLossExpense->value, 'is_system' => true],
            ['code' => '5810', 'name' => 'Compte d\'attente règlements TPV (bons d\'achat)', 'type' => 'asset', 'parent_code' => '5',
                'system_purpose' => SystemAccountPurpose::PosTenderClearing->value, 'is_system' => true],

            ['code' => '71', 'name' => 'Production stockée (ou déstockage)', 'type' => 'revenue', 'parent_code' => '7'],
            ['code' => '72', 'name' => 'Production immobilisée', 'type' => 'revenue', 'parent_code' => '7'],
            ['code' => '74', 'name' => 'Subventions d\'exploitation', 'type' => 'revenue', 'parent_code' => '7'],
            ['code' => '75', 'name' => 'Autres produits de gestion courante', 'type' => 'revenue', 'parent_code' => '7'],
            ['code' => '76', 'name' => 'Produits financiers', 'type' => 'revenue', 'parent_code' => '7'],
            ['code' => '77', 'name' => 'Produits exceptionnels', 'type' => 'revenue', 'parent_code' => '7'],
            ['code' => '78', 'name' => 'Reprises sur amortissements et provisions', 'type' => 'revenue', 'parent_code' => '7'],
        ];
    }
}
