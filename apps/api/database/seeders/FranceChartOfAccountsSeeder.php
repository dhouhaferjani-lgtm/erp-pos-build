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
 *
 * @deprecated compatibility artifact; frozen at 7d85232cc
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

                // Re-run flag promotion: the seeder never rewrites existing rows, so a
                // chart seeded BEFORE an account definition became is_system would keep
                // the row unprotected forever. Promote ONLY the is_system flag (never
                // name/type/purpose — user edits stay untouched) when the definition
                // says system but the stored row is not.
                if (($account['is_system'] ?? false) && ! (bool) $existing->is_system) {
                    DB::table('accounts')
                        ->where('id', $existing->id)
                        ->update(['is_system' => true, 'updated_at' => $now]);
                }

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
            ['code' => '403', 'name' => 'Fournisseurs - Effets à payer', 'type' => 'liability', 'parent_code' => '40', 'is_system' => true],
            ['code' => '4035', 'name' => 'Fournisseurs - Chèques à payer', 'type' => 'liability', 'parent_code' => '40', 'is_system' => true],
            ['code' => '408', 'name' => 'Fournisseurs - Factures non parvenues', 'type' => 'liability', 'parent_code' => '40',
                'system_purpose' => SystemAccountPurpose::GoodsReceivedNotInvoiced->value, 'is_system' => true],
            // R2 E-1 / register H-5 + DPA-REV2-A A2 (both lanes converged here):
            // 409 IS the PCG supplier-advance account ("avances et acomptes versés
            // sur commandes") — the PCG counterpart of the TN chart's own 409 →
            // SupplierAdvance mapping. `SupplierAdvance` is in
            // SystemAccountPurpose::requiredPurposes(), so before this line a
            // French chart FAILED ChartOfAccountsService::validateCompanyAccounts()
            // and GeneralLedgerService::createSupplierAdvance* threw. Metadata only —
            // `asset` already matches expectedAccountType().
            ['code' => '409', 'name' => 'Fournisseurs débiteurs', 'type' => 'asset', 'parent_code' => '40',
                'system_purpose' => SystemAccountPurpose::SupplierAdvance->value, 'is_system' => true],

            // Customers (Clients)
            ['code' => '41', 'name' => 'Clients et comptes rattachés', 'type' => 'asset', 'parent_code' => '4'],
            ['code' => '411', 'name' => 'Clients', 'type' => 'asset', 'parent_code' => '41',
                'system_purpose' => SystemAccountPurpose::CustomerReceivable->value, 'is_system' => true],
            ['code' => '4111', 'name' => 'Clients - Ventes de biens ou de prestations de services', 'type' => 'asset', 'parent_code' => '411'],
            ['code' => '413', 'name' => 'Clients - Effets à recevoir', 'type' => 'asset', 'parent_code' => '41', 'is_system' => true],
            ['code' => '416', 'name' => 'Clients douteux ou litigieux', 'type' => 'asset', 'parent_code' => '41', 'is_system' => true],
            // Register G-4 — UninvoicedDeliveryNoteService resolves this purpose
            // (the delivery-note accrual, PCG 418 by name already).
            ['code' => '418', 'name' => 'Clients - Produits non encore facturés', 'type' => 'asset', 'parent_code' => '41',
                'system_purpose' => SystemAccountPurpose::UninvoicedRevenue->value, 'is_system' => true],
            // R2 E-1 / register H-5 + DPA-REV2-A A2 (both lanes converged here):
            // 419 IS the PCG customer-advance account ("clients créditeurs, avances
            // et acomptes reçus") — PCG counterpart of the TN chart's 419 →
            // CustomerAdvance mapping; also a requiredPurposes() member. Without it
            // a French company hard-fails createCustomerAdvanceJournalEntry() at
            // GeneralLedgerService:417 on any over-payment. Metadata only —
            // `liability` already matches expectedAccountType().
            ['code' => '419', 'name' => 'Clients créditeurs', 'type' => 'liability', 'parent_code' => '41',
                'system_purpose' => SystemAccountPurpose::CustomerAdvance->value, 'is_system' => true],

            // Social security and personnel
            ['code' => '42', 'name' => 'Personnel et comptes rattachés', 'type' => 'liability', 'parent_code' => '4'],
            ['code' => '421', 'name' => 'Personnel - Rémunérations dues', 'type' => 'liability', 'parent_code' => '42'],
            ['code' => '43', 'name' => 'Sécurité sociale et autres organismes sociaux', 'type' => 'liability', 'parent_code' => '4'],
            ['code' => '431', 'name' => 'Sécurité sociale', 'type' => 'liability', 'parent_code' => '43'],

            // State and taxes
            ['code' => '44', 'name' => 'État et autres collectivités publiques', 'type' => 'liability', 'parent_code' => '4'],
            ['code' => '4456', 'name' => 'TVA déductible', 'type' => 'asset', 'parent_code' => '44',
                'system_purpose' => SystemAccountPurpose::VatDeductible->value, 'is_system' => true],
            ['code' => '44566', 'name' => 'TVA déductible sur autres biens et services', 'type' => 'asset', 'parent_code' => '4456', 'is_system' => true],
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
            ['code' => '511', 'name' => 'Valeurs à l’encaissement', 'type' => 'asset', 'parent_code' => '51'],
            ['code' => '5112', 'name' => 'Chèques à encaisser', 'type' => 'asset', 'parent_code' => '511', 'is_system' => true],
            ['code' => '5113', 'name' => 'Effets à l’encaissement', 'type' => 'asset', 'parent_code' => '511', 'is_system' => true],
            ['code' => '5114', 'name' => 'Effets à l’escompte', 'type' => 'asset', 'parent_code' => '511', 'is_system' => true],
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
            // R2 E-1 (register H-5) — France booked ZERO cost of goods sold: no
            // account carried CostOfGoodsSold, and PostCOGSOnInvoice swallows the
            // miss, so a French tenant's P&L showed revenue with no cost, forever.
            //
            // PCG 603 "Variation des stocks (approvisionnements et marchandises)"
            // is the charge account of the perpetual-inventory (inventaire
            // permanent) destocking entry — debit 603x / credit 37 — which is
            // exactly what PostCOGSOnInvoice books (debit CostOfGoodsSold, credit
            // Inventory=37). It mirrors the Tunisian chart's own 603 mapping and
            // the generic chart's 6030.
            // Deliberately NOT 607 "Achats de marchandises": 607 already carries
            // PurchaseExpenses in this same chart, it is the PERIODIC-inventory
            // cost account, and reusing it would double-count purchases (and
            // collide with accounts_company_purpose_unique).
            // The finer PCG grain is 6037 "Variation des stocks de marchandises";
            // the purpose sits on the 603 family node because the purpose is not
            // product-class aware. Resolution is purpose-first, so an operator (or
            // the super-admin COA template) may move it to 6037 without code change.
            // EXPERT-COMPTABLE CONFIRMATION OWED (SEEDS gate finding I-3): because
            // the ERP is perpetual and NEVER debits 607 on the normal purchase flow
            // (purchases capitalise to Inventory via GR-IR,
            // GeneralLedgerService::…GoodsReceivedNotInvoiced; the only 607
            // resolution is the bonus-stock return path), the whole cost of sales
            // lands in "variation des stocks" and the compte de résultat / liasse
            // 2052 line "Achats de marchandises" stays structurally 0.00. The P&L
            // TOTAL is correct; the FS/FT split is not the conventional PCG
            // presentation. Not a regression (TN behaves the same) and not a
            // blocker — but the expert must rule on the presentation before the
            // first liasse, not at filing. Register H-5 carries the full list.
            ['code' => '603', 'name' => 'Variation des stocks (approvisionnements et marchandises)', 'type' => 'expense', 'parent_code' => '60',
                'system_purpose' => SystemAccountPurpose::CostOfGoodsSold->value, 'is_system' => true],
            // Register G-4 parity — the PCG homes for the four expense purposes
            // that only the generic chart carried (6130/6170/6250/6256 there).
            ['code' => '606', 'name' => 'Achats non stockés de matières et fournitures', 'type' => 'expense', 'parent_code' => '60'],
            ['code' => '6061', 'name' => 'Fournitures non stockables (eau, énergie)', 'type' => 'expense', 'parent_code' => '606',
                'system_purpose' => SystemAccountPurpose::UtilitiesExpense->value, 'is_system' => true],
            ['code' => '6064', 'name' => 'Fournitures administratives', 'type' => 'expense', 'parent_code' => '606',
                'system_purpose' => SystemAccountPurpose::OfficeExpense->value, 'is_system' => true],
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
            // Register G-3 — the default expense categories book transport here
            // on both French-plan charts; the TN chart already carried 624.
            ['code' => '624', 'name' => 'Transports de biens et transports collectifs du personnel', 'type' => 'expense', 'parent_code' => '62'],
            ['code' => '625', 'name' => 'Déplacements, missions et réceptions', 'type' => 'expense', 'parent_code' => '62'],
            ['code' => '6251', 'name' => 'Voyages et déplacements', 'type' => 'expense', 'parent_code' => '625',
                'system_purpose' => SystemAccountPurpose::TravelExpense->value, 'is_system' => true],
            ['code' => '6257', 'name' => 'Réceptions', 'type' => 'expense', 'parent_code' => '625',
                'system_purpose' => SystemAccountPurpose::MealsExpense->value, 'is_system' => true],
            ['code' => '627', 'name' => 'Services bancaires et assimilés', 'type' => 'expense', 'parent_code' => '62', 'is_system' => true],
            // R2 E-1 (register H-5) — France had no GeneralExpense either, so the
            // expense-document lane (GeneralLedgerService::…, expense categories'
            // fallback account) had nothing to resolve. PCG 628 "Divers" is the
            // conventional catch-all for unclassified external charges and is the
            // same code family as the generic chart's 6280 "General Expenses".
            // Deliberately NOT 65 (the TN chart's choice): in the PCG chart 65 is
            // a pure header parenting five system accounts (6580/6581/6585/6588/6590).
            ['code' => '628', 'name' => 'Divers', 'type' => 'expense', 'parent_code' => '62',
                'system_purpose' => SystemAccountPurpose::GeneralExpense->value, 'is_system' => true],
            ['code' => '63', 'name' => 'Impôts, taxes et versements assimilés', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '6354', 'name' => 'Droits d\'enregistrement et de timbre', 'type' => 'expense', 'parent_code' => '63',
                'system_purpose' => SystemAccountPurpose::PurchaseStampDuty->value, 'is_system' => true],
            ['code' => '64', 'name' => 'Charges de personnel', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '641', 'name' => 'Rémunérations du personnel', 'type' => 'expense', 'parent_code' => '64'],
            ['code' => '645', 'name' => 'Charges de sécurité sociale et de prévoyance', 'type' => 'expense', 'parent_code' => '64'],
            ['code' => '65', 'name' => 'Autres charges de gestion courante', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '6580', 'name' => 'Écart de règlement (charges)', 'type' => 'expense', 'parent_code' => '65',
                'system_purpose' => SystemAccountPurpose::PaymentToleranceExpense->value, 'is_system' => true],
            // v3-refund-chain-integration spec §5.3 — invalid_refund
            // write-off (genuine loss booking, distinct from SalesReturn's
            // valid_unbooked reversal shape).
            ['code' => '6590', 'name' => 'Perte sur remboursement (write-off)', 'type' => 'expense', 'parent_code' => '65',
                'system_purpose' => SystemAccountPurpose::RefundWriteOff->value, 'is_system' => true],
            ['code' => '6585', 'name' => 'Écart sur prix d\'achat', 'type' => 'expense', 'parent_code' => '65',
                'system_purpose' => SystemAccountPurpose::PurchasePriceVarianceExpense->value, 'is_system' => true],
            // W-6 D1a — sales tax-rounding difference. PCG 658 "Charges diverses de
            // gestion courante" is the conventional home for an écart d'arrondi on
            // billing; 7581 below is its income counterpart. A sales document debits
            // AR with the header total and credits the lines' revenue + a recomputed
            // per-line VAT; per-line truncation can leave the header up to one unit
            // of the last place per line above the GL credits. The Tunisian chart
            // absorbs that in 4375 alongside the timbre — the PCG has no timbre, so
            // without this pair a two-line French invoice cannot balance.
            ['code' => '6581', 'name' => 'Écart d\'arrondi sur facturation (charges)', 'type' => 'expense', 'parent_code' => '65',
                'system_purpose' => SystemAccountPurpose::SalesRoundingDifferenceExpense->value, 'is_system' => true],
            ['code' => '66', 'name' => 'Charges financières', 'type' => 'expense', 'parent_code' => '6'],
            ['code' => '661', 'name' => 'Charges d\'intérêts', 'type' => 'expense', 'parent_code' => '66'],
            // Register G-4 parity — FX pair, generic-chart-only until now.
            ['code' => '666', 'name' => 'Pertes de change', 'type' => 'expense', 'parent_code' => '66',
                'system_purpose' => SystemAccountPurpose::RealizedFxLoss->value, 'is_system' => true],
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
            // v3-refund-chain-integration spec §5.3 (errata T1/4.2): gains
            // `system_purpose` (previously absent — the account existed but
            // resolved nowhere via hasAccountForPurpose(SalesReturn), a 500
            // for any FR tenant's valid_unbooked write-off class) AND its
            // `type` is aligned from 'revenue' to 'expense', matching this
            // same file's own 7091/SalesReturnsClearing precedent two lines
            // below — SystemAccountPurpose::expectedAccountType() groups
            // SalesReturn in its Expense arm.
            ['code' => '709', 'name' => 'Rabais, remises et ristournes accordés', 'type' => 'expense', 'parent_code' => '70',
                'system_purpose' => SystemAccountPurpose::SalesReturn->value, 'is_system' => true],
            // Register G-4 parity — commercial discount granted on a sale
            // (GeneralLedgerService::createPOSChargeEntry debits this purpose with
            // the transaction discount). PCG 709 subdivides by revenue family;
            // 7097 is the marchandises leg, and 709 itself already carries
            // SalesReturn while 7091 carries the voucher clearing account.
            ['code' => '7097', 'name' => 'Rabais, remises et ristournes accordés sur ventes de marchandises', 'type' => 'revenue', 'parent_code' => '70',
                'system_purpose' => SystemAccountPurpose::SalesDiscount->value, 'is_system' => true],
            ['code' => '7580', 'name' => 'Écart de règlement (produits)', 'type' => 'revenue', 'parent_code' => '75',
                'system_purpose' => SystemAccountPurpose::PaymentToleranceIncome->value, 'is_system' => true],
            // W-6 D1a — see 6581 above. PCG 758 "Produits divers de gestion courante";
            // this is the leg a sales INVOICE credits when its header total exceeds
            // revenue + recomputed line VAT by a tax-truncation residual.
            ['code' => '7581', 'name' => 'Écart d\'arrondi sur facturation (produits)', 'type' => 'revenue', 'parent_code' => '75',
                'system_purpose' => SystemAccountPurpose::SalesRoundingDifferenceIncome->value, 'is_system' => true],

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
            // Register G-4 parity — see 666 above.
            ['code' => '766', 'name' => 'Gains de change', 'type' => 'revenue', 'parent_code' => '76',
                'system_purpose' => SystemAccountPurpose::RealizedFxGain->value, 'is_system' => true],
            ['code' => '77', 'name' => 'Produits exceptionnels', 'type' => 'revenue', 'parent_code' => '7'],
            ['code' => '78', 'name' => 'Reprises sur amortissements et provisions', 'type' => 'revenue', 'parent_code' => '7'],
        ];
    }
}
