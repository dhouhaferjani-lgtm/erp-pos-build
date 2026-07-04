<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Database\Seeders\Contracts\ChartOfAccountsSeederContract;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * France Chart of Accounts (Plan Comptable Général - PCG) Seeder.
 *
 * Seeds a standard French chart of accounts for a given company.
 * Assigns system_purpose values to key accounts for country-agnostic GL operations.
 *
 * Based on PCG 2014 (Plan Comptable Général) as regulated by ANC (Autorité des Normes Comptables).
 */
class FranceChartOfAccountsSeeder extends Seeder implements ChartOfAccountsSeederContract
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
            $existing = DB::table('accounts')
                ->where('company_id', $companyId)
                ->where('code', $account['code'])
                ->first();
            if ($existing !== null) {
                $accountIdMap[$account['code']] = (string) $existing->id;

                continue;
            }

            $purpose = $account['system_purpose'] ?? null;
            if ($purpose !== null) {
                $existingPurpose = DB::table('accounts')
                    ->where('company_id', $companyId)
                    ->where('system_purpose', $purpose)
                    ->first();
                if ($existingPurpose !== null) {
                    $accountIdMap[$account['code']] = (string) $existingPurpose->id;

                    continue;
                }
            }

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
                'France chart of accounts (PCG) seeded successfully for company %s (%d accounts created)',
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
        return 'FR';
    }

    /**
     * France PCG (Plan Comptable Général) account definitions with system purposes.
     *
     * @return list<array{code: string, name: string, type: string, parent_code: string|null, system_purpose?: string, is_system?: bool}>
     */
    private function getAccountsDefinition(): array
    {
        return [
            // Classe 1: Capitaux (Equity)
            ['code' => '1', 'name' => 'CAPITAUX', 'type' => 'equity', 'parent_code' => null, 'is_system' => true],
            ['code' => '10', 'name' => 'Capital et réserves', 'type' => 'equity', 'parent_code' => '1'],
            ['code' => '101', 'name' => 'Capital', 'type' => 'equity', 'parent_code' => '10'],
            ['code' => '11', 'name' => 'Report à nouveau', 'type' => 'equity', 'parent_code' => '1',
                'system_purpose' => SystemAccountPurpose::RetainedEarnings->value, 'is_system' => true],
            ['code' => '110', 'name' => 'Report à nouveau (solde créditeur)', 'type' => 'equity', 'parent_code' => '11'],
            ['code' => '119', 'name' => 'Report à nouveau (solde débiteur)', 'type' => 'equity', 'parent_code' => '11'],
            ['code' => '12', 'name' => 'Résultat de l\'exercice', 'type' => 'equity', 'parent_code' => '1'],
            ['code' => '120', 'name' => 'Résultat de l\'exercice (bénéfice)', 'type' => 'equity', 'parent_code' => '12'],
            ['code' => '129', 'name' => 'Résultat de l\'exercice (perte)', 'type' => 'equity', 'parent_code' => '12'],
            ['code' => '13', 'name' => 'Subventions d\'investissement', 'type' => 'equity', 'parent_code' => '1'],
            ['code' => '14', 'name' => 'Provisions réglementées', 'type' => 'equity', 'parent_code' => '1'],
            ['code' => '891', 'name' => 'Bilan d\'ouverture', 'type' => 'equity', 'parent_code' => '1',
                'system_purpose' => SystemAccountPurpose::OpeningBalanceEquity->value, 'is_system' => true],

            // Classe 2: Immobilisations (Fixed Assets)
            ['code' => '2', 'name' => 'COMPTES D\'IMMOBILISATIONS', 'type' => 'asset', 'parent_code' => null, 'is_system' => true],
            ['code' => '20', 'name' => 'Immobilisations incorporelles', 'type' => 'asset', 'parent_code' => '2'],
            ['code' => '21', 'name' => 'Immobilisations corporelles', 'type' => 'asset', 'parent_code' => '2'],
            ['code' => '2154', 'name' => 'Matériel industriel', 'type' => 'asset', 'parent_code' => '21'],
            ['code' => '2182', 'name' => 'Matériel de transport', 'type' => 'asset', 'parent_code' => '21'],
            ['code' => '2183', 'name' => 'Matériel de bureau et informatique', 'type' => 'asset', 'parent_code' => '21'],
            ['code' => '2184', 'name' => 'Mobilier', 'type' => 'asset', 'parent_code' => '21'],
            ['code' => '28', 'name' => 'Amortissements des immobilisations', 'type' => 'asset', 'parent_code' => '2'],

            // Classe 3: Stocks (Inventory)
            ['code' => '3', 'name' => 'COMPTES DE STOCKS', 'type' => 'asset', 'parent_code' => null, 'is_system' => true],
            ['code' => '31', 'name' => 'Matières premières', 'type' => 'asset', 'parent_code' => '3'],
            ['code' => '32', 'name' => 'Autres approvisionnements', 'type' => 'asset', 'parent_code' => '3'],
            ['code' => '37', 'name' => 'Stocks de marchandises', 'type' => 'asset', 'parent_code' => '3',
                'system_purpose' => SystemAccountPurpose::Inventory->value, 'is_system' => true],

            // Classe 4: Comptes de tiers (Third parties)
            ['code' => '4', 'name' => 'COMPTES DE TIERS', 'type' => 'asset', 'parent_code' => null, 'is_system' => true],

            // Suppliers (Fournisseurs)
            ['code' => '40', 'name' => 'Fournisseurs et comptes rattachés', 'type' => 'liability', 'parent_code' => '4'],
            ['code' => '401', 'name' => 'Fournisseurs', 'type' => 'liability', 'parent_code' => '40',
                'system_purpose' => SystemAccountPurpose::SupplierPayable->value, 'is_system' => true],
            ['code' => '4011', 'name' => 'Fournisseurs - Achats de biens et prestations de services', 'type' => 'liability', 'parent_code' => '401'],
            ['code' => '403', 'name' => 'Fournisseurs - Effets à payer', 'type' => 'liability', 'parent_code' => '40'],
            ['code' => '408', 'name' => 'Fournisseurs - Factures non parvenues', 'type' => 'liability', 'parent_code' => '40',
                'system_purpose' => SystemAccountPurpose::GoodsReceivedNotInvoiced->value, 'is_system' => true],
            ['code' => '409', 'name' => 'Fournisseurs débiteurs', 'type' => 'asset', 'parent_code' => '40'],

            // Customers (Clients)
            ['code' => '41', 'name' => 'Clients et comptes rattachés', 'type' => 'asset', 'parent_code' => '4'],
            ['code' => '411', 'name' => 'Clients', 'type' => 'asset', 'parent_code' => '41',
                'system_purpose' => SystemAccountPurpose::CustomerReceivable->value, 'is_system' => true],
            ['code' => '4111', 'name' => 'Clients - Ventes de biens ou de prestations de services', 'type' => 'asset', 'parent_code' => '411'],
            ['code' => '413', 'name' => 'Clients - Effets à recevoir', 'type' => 'asset', 'parent_code' => '41'],
            ['code' => '416', 'name' => 'Clients douteux ou litigieux', 'type' => 'asset', 'parent_code' => '41'],
            ['code' => '418', 'name' => 'Clients - Produits non encore facturés', 'type' => 'asset', 'parent_code' => '41'],
            ['code' => '419', 'name' => 'Clients créditeurs', 'type' => 'liability', 'parent_code' => '41'],

            // Social security and personnel
            ['code' => '42', 'name' => 'Personnel et comptes rattachés', 'type' => 'liability', 'parent_code' => '4'],
            ['code' => '421', 'name' => 'Personnel - Rémunérations dues', 'type' => 'liability', 'parent_code' => '42'],
            ['code' => '43', 'name' => 'Sécurité sociale et autres organismes sociaux', 'type' => 'liability', 'parent_code' => '4'],
            ['code' => '431', 'name' => 'Sécurité sociale', 'type' => 'liability', 'parent_code' => '43'],

            // State and taxes
            ['code' => '44', 'name' => 'État et autres collectivités publiques', 'type' => 'liability', 'parent_code' => '4'],
            ['code' => '4456', 'name' => 'TVA déductible', 'type' => 'asset', 'parent_code' => '44',
                'system_purpose' => SystemAccountPurpose::VatDeductible->value, 'is_system' => true],
            ['code' => '44566', 'name' => 'TVA déductible sur autres biens et services', 'type' => 'asset', 'parent_code' => '4456'],
            ['code' => '4457', 'name' => 'TVA collectée', 'type' => 'liability', 'parent_code' => '44',
                'system_purpose' => SystemAccountPurpose::VatCollected->value, 'is_system' => true],
            ['code' => '44571', 'name' => 'TVA collectée', 'type' => 'liability', 'parent_code' => '4457'],
            ['code' => '4458', 'name' => 'Taxes sur le chiffre d\'affaires à régulariser', 'type' => 'liability', 'parent_code' => '44'],
            ['code' => '445', 'name' => 'État - Taxes sur le chiffre d\'affaires', 'type' => 'liability', 'parent_code' => '44'],
            ['code' => '447', 'name' => 'Autres impôts, taxes et versements assimilés', 'type' => 'liability', 'parent_code' => '44'],

            // Associates and partners
            ['code' => '45', 'name' => 'Groupe et associés', 'type' => 'asset', 'parent_code' => '4'],
            ['code' => '455', 'name' => 'Associés - Comptes courants', 'type' => 'liability', 'parent_code' => '45'],
            ['code' => '456', 'name' => 'Associés - Opérations sur le capital', 'type' => 'liability', 'parent_code' => '45'],
            ['code' => '457', 'name' => 'Associés - Dividendes à payer', 'type' => 'liability', 'parent_code' => '45'],

            // Debtors and creditors
            ['code' => '46', 'name' => 'Débiteurs divers et créditeurs divers', 'type' => 'asset', 'parent_code' => '4'],
            ['code' => '467', 'name' => 'Autres comptes débiteurs ou créditeurs', 'type' => 'asset', 'parent_code' => '46'],
            ['code' => '47', 'name' => 'Comptes transitoires ou d\'attente', 'type' => 'asset', 'parent_code' => '4'],
            ['code' => '471', 'name' => 'Comptes d\'attente', 'type' => 'asset', 'parent_code' => '47'],

            // Depreciation and provisions
            ['code' => '49', 'name' => 'Dépréciation des comptes de tiers', 'type' => 'asset', 'parent_code' => '4'],
            ['code' => '491', 'name' => 'Dépréciation des comptes clients', 'type' => 'asset', 'parent_code' => '49'],

            // Classe 5: Comptes financiers (Financial accounts)
            ['code' => '5', 'name' => 'COMPTES FINANCIERS', 'type' => 'asset', 'parent_code' => null, 'is_system' => true],
            ['code' => '50', 'name' => 'Valeurs mobilières de placement', 'type' => 'asset', 'parent_code' => '5'],
            ['code' => '51', 'name' => 'Banques, établissements financiers et assimilés', 'type' => 'asset', 'parent_code' => '5'],
            ['code' => '512', 'name' => 'Banques', 'type' => 'asset', 'parent_code' => '51',
                'system_purpose' => SystemAccountPurpose::Bank->value, 'is_system' => true],
            ['code' => '53', 'name' => 'Caisse', 'type' => 'asset', 'parent_code' => '5',
                'system_purpose' => SystemAccountPurpose::Cash->value, 'is_system' => true],
            ['code' => '530', 'name' => 'Caisse', 'type' => 'asset', 'parent_code' => '53'],
            ['code' => '54', 'name' => 'Régies d\'avances et accréditifs', 'type' => 'asset', 'parent_code' => '5'],
            ['code' => '58', 'name' => 'Virements internes', 'type' => 'asset', 'parent_code' => '5'],

            // Classe 6: Charges (Expenses)
            ['code' => '6', 'name' => 'CHARGES', 'type' => 'expense', 'parent_code' => null, 'is_system' => true],
            ['code' => '60', 'name' => 'Achats', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '601', 'name' => 'Achats stockés - Matières premières', 'type' => 'expense', 'parent_code' => '60'],
            ['code' => '602', 'name' => 'Achats stockés - Autres approvisionnements', 'type' => 'expense', 'parent_code' => '60'],
            ['code' => '607', 'name' => 'Achats de marchandises', 'type' => 'expense', 'parent_code' => '60',
                'system_purpose' => SystemAccountPurpose::PurchaseExpenses->value, 'is_system' => true],
            ['code' => '6071', 'name' => 'Achats de marchandises - Matières premières', 'type' => 'expense', 'parent_code' => '607'],
            ['code' => '609', 'name' => 'Rabais, remises et ristournes obtenus sur achats', 'type' => 'expense', 'parent_code' => '60'],
            ['code' => '61', 'name' => 'Services extérieurs', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '611', 'name' => 'Sous-traitance générale', 'type' => 'expense', 'parent_code' => '61'],
            ['code' => '613', 'name' => 'Locations', 'type' => 'expense', 'parent_code' => '61'],
            ['code' => '615', 'name' => 'Entretien et réparations', 'type' => 'expense', 'parent_code' => '61'],
            ['code' => '616', 'name' => 'Primes d\'assurance', 'type' => 'expense', 'parent_code' => '61'],
            ['code' => '62', 'name' => 'Autres services extérieurs', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '622', 'name' => 'Rémunérations d\'intermédiaires et honoraires', 'type' => 'expense', 'parent_code' => '62'],
            ['code' => '623', 'name' => 'Publicité, publications, relations publiques', 'type' => 'expense', 'parent_code' => '62'],
            ['code' => '626', 'name' => 'Frais postaux et de télécommunications', 'type' => 'expense', 'parent_code' => '62'],
            ['code' => '627', 'name' => 'Services bancaires et assimilés', 'type' => 'expense', 'parent_code' => '62'],
            ['code' => '63', 'name' => 'Impôts, taxes et versements assimilés', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '6354', 'name' => 'Droits d\'enregistrement et de timbre', 'type' => 'expense', 'parent_code' => '63',
                'system_purpose' => SystemAccountPurpose::PurchaseStampDuty->value, 'is_system' => true],
            ['code' => '64', 'name' => 'Charges de personnel', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '641', 'name' => 'Rémunérations du personnel', 'type' => 'expense', 'parent_code' => '64'],
            ['code' => '645', 'name' => 'Charges de sécurité sociale et de prévoyance', 'type' => 'expense', 'parent_code' => '64'],
            ['code' => '65', 'name' => 'Autres charges de gestion courante', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '6585', 'name' => 'Écart sur prix d\'achat', 'type' => 'expense', 'parent_code' => '65',
                'system_purpose' => SystemAccountPurpose::PurchasePriceVarianceExpense->value, 'is_system' => true],
            ['code' => '66', 'name' => 'Charges financières', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '661', 'name' => 'Charges d\'intérêts', 'type' => 'expense', 'parent_code' => '66'],
            ['code' => '67', 'name' => 'Charges exceptionnelles', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '68', 'name' => 'Dotations aux amortissements et aux provisions', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '681', 'name' => 'Dotations aux amortissements', 'type' => 'expense', 'parent_code' => '68'],
            ['code' => '69', 'name' => 'Impôts sur les bénéfices', 'type' => 'expense', 'parent_code' => '6'],

            // Classe 7: Produits (Revenue)
            ['code' => '7', 'name' => 'PRODUITS', 'type' => 'revenue', 'parent_code' => null, 'is_system' => true],
            ['code' => '70', 'name' => 'Ventes de produits fabriqués, prestations de services, marchandises', 'type' => 'revenue', 'parent_code' => '7', 'is_system' => true],
            ['code' => '701', 'name' => 'Ventes de produits finis', 'type' => 'revenue', 'parent_code' => '70'],
            ['code' => '706', 'name' => 'Prestations de services', 'type' => 'revenue', 'parent_code' => '70',
                'system_purpose' => SystemAccountPurpose::ServiceRevenue->value, 'is_system' => true],
            ['code' => '7061', 'name' => 'Prestations de services - Main d\'œuvre', 'type' => 'revenue', 'parent_code' => '706'],
            ['code' => '707', 'name' => 'Ventes de marchandises', 'type' => 'revenue', 'parent_code' => '70',
                'system_purpose' => SystemAccountPurpose::ProductRevenue->value, 'is_system' => true],
            ['code' => '7071', 'name' => 'Ventes de marchandises - Pièces automobiles', 'type' => 'revenue', 'parent_code' => '707'],
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
            ['code' => '7585', 'name' => 'Écart sur prix d\'achat', 'type' => 'revenue', 'parent_code' => '75',
                'system_purpose' => SystemAccountPurpose::PurchasePriceVarianceIncome->value, 'is_system' => true],
            ['code' => '6588', 'name' => 'Pertes d\'arrondis sur bons d\'achat', 'type' => 'expense', 'parent_code' => '65',
                'system_purpose' => SystemAccountPurpose::RoundingLossExpense->value, 'is_system' => true],
            ['code' => '5810', 'name' => 'Compte d\'attente règlements TPV (bons d\'achat)', 'type' => 'asset', 'parent_code' => '58',
                'system_purpose' => SystemAccountPurpose::PosTenderClearing->value, 'is_system' => true],

            ['code' => '71', 'name' => 'Production stockée (ou déstockage)', 'type' => 'revenue', 'parent_code' => '7'],
            ['code' => '713', 'name' => 'Variation des stocks (en-cours de production de biens)', 'type' => 'revenue', 'parent_code' => '71'],
            ['code' => '72', 'name' => 'Production immobilisée', 'type' => 'revenue', 'parent_code' => '7'],
            ['code' => '74', 'name' => 'Subventions d\'exploitation', 'type' => 'revenue', 'parent_code' => '7'],
            ['code' => '75', 'name' => 'Autres produits de gestion courante', 'type' => 'revenue', 'parent_code' => '7'],
            ['code' => '76', 'name' => 'Produits financiers', 'type' => 'revenue', 'parent_code' => '7'],
            ['code' => '77', 'name' => 'Produits exceptionnels', 'type' => 'revenue', 'parent_code' => '7'],
            ['code' => '78', 'name' => 'Reprises sur amortissements et provisions', 'type' => 'revenue', 'parent_code' => '7'],
        ];
    }
}
