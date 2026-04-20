<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\MembershipStatus;
use App\Modules\Company\Domain\Location;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Entities\WithholdingTaxRule;
use App\Modules\Taxation\Domain\Enums\PartnerTaxStatus;
use App\Modules\Taxation\Domain\Enums\TransactionType;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Seeds a Tunisian Parapharmacy company to an existing tenant.
 *
 * This seeder adds:
 * - Tunisian parapharmacy company
 * - Chart of accounts for Tunisia
 * - Payment methods and repositories
 * - Tunisian withholding tax rules
 * - Parapharmacy products (supplements, vitamins, cosmetics)
 * - Tunisian partners (customers and suppliers)
 * - Stock levels
 * - User access to the new company
 */
class TunisianParapharmacySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('==============================================');
        $this->command->info('TUNISIAN PARAPHARMACY SEEDER');
        $this->command->info('==============================================');

        // Find the first active tenant (or create one if none exists)
        $tenant = Tenant::where('status', 'active')->first();

        if (! $tenant) {
            $this->command->error('No active tenant found. Please run DatabaseSeeder first.');

            return;
        }

        $this->command->info("Adding Tunisian parapharmacy to tenant: {$tenant->name}");

        // Create Tunisian parapharmacy company
        $this->command->info('Creating Tunisian parapharmacy company...');
        $company = $this->createParapharmacyCompany($tenant);

        // Create chart of accounts (if not already exists)
        $accountsExist = Account::where('company_id', $company->id)->exists();

        if (! $accountsExist) {
            $this->command->info('Creating Tunisian chart of accounts...');
            $tunisiaSeeder = new TunisiaChartOfAccountsSeeder;
            $tunisiaSeeder->setCommand($this->command);
            $tunisiaSeeder->run($company->id, $tenant->id);
        } else {
            $this->command->info('Chart of accounts already exists - skipping');
        }

        // Create payment methods (if not already exists)
        $paymentMethodsExist = PaymentMethod::where('company_id', $company->id)->exists();

        if (! $paymentMethodsExist) {
            $this->command->info('Creating payment methods...');
            $this->call(PaymentMethodSeeder::class, false, ['company' => $company]);
        } else {
            $this->command->info('Payment methods already exist - skipping');
        }

        // Create payment repositories (if not already exists)
        $repositoriesExist = PaymentRepository::where('company_id', $company->id)->exists();

        if (! $repositoriesExist) {
            $this->command->info('Creating payment repositories...');
            $this->call(PaymentRepositorySeeder::class, false, ['company' => $company]);
        } else {
            $this->command->info('Payment repositories already exist - skipping');
        }

        // Create Tunisia tax configuration
        $this->command->info('Creating Tunisia tax configuration...');
        $this->call(TunisiaTaxConfigurationSeeder::class);

        // Create Tunisian withholding rules
        $this->command->info('Creating Tunisian withholding tax rules...');
        $this->createWithholdingRules($company);

        // Create parapharmacy-specific partners (if not already exists)
        $partnersExist = Partner::where('company_id', $company->id)->exists();

        if (! $partnersExist) {
            $this->command->info('Creating Tunisian partners...');
            $this->createParapharmacyPartners($tenant, $company);
        } else {
            $this->command->info('Partners already exist - skipping');
        }

        // Create parapharmacy products (if not already exists)
        $productsExist = Product::where('company_id', $company->id)->exists();

        if (! $productsExist) {
            $this->command->info('Creating parapharmacy products...');
            $this->createParapharmacyProducts($company);
        } else {
            $this->command->info('Products already exist - skipping');
        }

        // Create stock levels
        $this->command->info('Creating stock levels...');
        $this->call(StockLevelSeeder::class, false, ['company' => $company]);

        // Add existing users to the new company
        $this->command->info('Granting existing users access to new company...');
        $this->addUsersToCompany($tenant, $company);

        $this->command->info('==============================================');
        $this->command->info('✓ Tunisian parapharmacy seeded successfully!');
        $this->command->info("  Company: {$company->name}");
        $this->command->info("  Tax ID: {$company->tax_id}");
        $this->command->info('  Currency: TND');
        $this->command->info('  Switch to this company in the UI to test withholding!');
        $this->command->info('==============================================');
    }

    private function createParapharmacyCompany(Tenant $tenant): Company
    {
        // Check if company already exists
        $company = Company::where('tenant_id', $tenant->id)
            ->where('tax_id', 'TN1234567ABC')
            ->first();

        if ($company) {
            $this->command->info("Company already exists: {$company->name} - skipping creation");

            return $company;
        }

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Pharmacie Centrale Tunis',
            'legal_name' => 'Pharmacie Centrale Tunis SARL',
            'country_code' => 'TN',
            'tax_id' => 'TN1234567ABC',
            'currency' => 'TND',
            'locale' => 'fr',
            'timezone' => 'Africa/Tunis',
            'date_format' => 'd/m/Y',
            'fiscal_year_start_month' => 1,
            'status' => CompanyStatus::Active,
            'is_headquarters' => true,

            // Address in Tunis
            'address_street' => 'Avenue Habib Bourguiba',
            'address_city' => 'Tunis',
            'address_postal_code' => '1000',
            'address_state' => null,
            'phone' => '+216 71 234 567',
            'email' => 'contact@pharmaciecentrale.tn',
        ]);

        // Create default location if it doesn't exist
        $locationExists = Location::where('company_id', $company->id)
            ->where('code', 'MAIN')
            ->exists();

        if (! $locationExists) {
            Location::create([
                'id' => Str::uuid()->toString(),
                'company_id' => $company->id,
                'name' => 'Magasin Principal',
                'code' => 'MAIN',
                'type' => 'shop',
                'is_default' => true,
                'is_active' => true,
                'pos_enabled' => true,
                'address_street' => $company->address_street,
                'address_city' => $company->address_city,
                'address_postal_code' => $company->address_postal_code,
                'address_country' => $company->country_code,
                'phone' => $company->phone,
                'email' => $company->email,
            ]);
            $this->command->info('Created location: Magasin Principal');
        }

        $this->command->info("Using company: {$company->name}");

        return $company;
    }

    private function createWithholdingRules(Company $company): void
    {
        $rules = [
            // Professional services (VAT registered) - 10%
            [
                'code' => 'TN_SERV_REG_10',
                'name' => 'Retenue services professionnels - 10%',
                'description' => 'Retenue à la source pour prestations de services (assujettis)',
                'country_code' => 'TN',
                'transaction_type' => TransactionType::SERVICES,
                'partner_tax_status' => PartnerTaxStatus::REGISTERED,
                'rate' => '0.100', // 10%
                'min_amount' => '1000.000',
                'is_active' => true,
            ],
            // Professional services (Non-VAT registered) - 15%
            [
                'code' => 'TN_SERV_NONREG_15',
                'name' => 'Retenue services non-assujettis - 15%',
                'description' => 'Retenue à la source pour prestations de services (non-assujettis)',
                'country_code' => 'TN',
                'transaction_type' => TransactionType::SERVICES,
                'partner_tax_status' => PartnerTaxStatus::NON_REGISTERED,
                'rate' => '0.150', // 15%
                'min_amount' => '500.000',
                'is_active' => true,
            ],
            // Export services - 10%
            [
                'code' => 'TN_EXPORT_10',
                'name' => 'Retenue services export - 10%',
                'description' => 'Retenue à la source pour services liés à l\'export',
                'country_code' => 'TN',
                'transaction_type' => TransactionType::EXPORT_SERVICES,
                'partner_tax_status' => null,
                'rate' => '0.100', // 10%
                'min_amount' => '0.000',
                'is_active' => true,
            ],
            // Rental income - 10%
            [
                'code' => 'TN_RENT_10',
                'name' => 'Retenue location - 10%',
                'description' => 'Retenue à la source sur les loyers',
                'country_code' => 'TN',
                'transaction_type' => TransactionType::RENTAL,
                'partner_tax_status' => null, // Applies to all partner types
                'rate' => '0.100', // 10%
                'min_amount' => '0.000',
                'is_active' => true,
            ],
            // Commission - 5%
            [
                'code' => 'TN_COMM_5',
                'name' => 'Retenue commission - 5%',
                'description' => 'Retenue à la source sur les commissions',
                'country_code' => 'TN',
                'transaction_type' => TransactionType::COMMISSION,
                'partner_tax_status' => null,
                'rate' => '0.050', // 5%
                'min_amount' => '0.000',
                'is_active' => true,
            ],
        ];

        $createdCount = 0;
        foreach ($rules as $ruleData) {
            // Check if rule already exists
            $exists = WithholdingTaxRule::where('company_id', $company->id)
                ->where('code', $ruleData['code'])
                ->exists();

            if (! $exists) {
                WithholdingTaxRule::create([
                    'id' => Str::uuid()->toString(),
                    'company_id' => $company->id,
                    'effective_from' => now()->startOfYear(), // Start of current year
                    ...$ruleData,
                ]);
                $createdCount++;
            }
        }

        if ($createdCount > 0) {
            $this->command->info("Created {$createdCount} withholding tax rules for Tunisia");
        } else {
            $this->command->info('Withholding tax rules already exist - skipping');
        }
    }

    private function createParapharmacyPartners(Tenant $tenant, Company $company): void
    {
        // Tunisian customers with different tax statuses
        $customers = [
            ['name' => 'Pharmacie Lafayette', 'tax_status' => PartnerTaxStatus::REGISTERED],
            ['name' => 'Parapharmacie Carthage', 'tax_status' => PartnerTaxStatus::REGISTERED],
            ['name' => 'Cabinet Dr. Ben Ali', 'tax_status' => PartnerTaxStatus::NON_REGISTERED],
            ['name' => 'Clinique Internationale', 'tax_status' => PartnerTaxStatus::REGISTERED],
            ['name' => 'Spa Wellness Tunis', 'tax_status' => PartnerTaxStatus::REGISTERED],
        ];

        foreach ($customers as $customerData) {
            Partner::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'code' => 'CUST-'.strtoupper(substr($customerData['name'], 0, 3)).rand(100, 999),
                'name' => $customerData['name'],
                'type' => PartnerType::Customer,
                'country_code' => 'TN',
                'vat_number' => 'TN'.rand(10000000, 99999999).'ABC',
                'tax_status' => $customerData['tax_status'],
                'email' => strtolower(str_replace(' ', '', $customerData['name'])).'@example.tn',
                'phone' => '+216 71 '.rand(100, 999).' '.rand(100, 999),
                'is_active' => true,
                'withholding_exempt' => false,
            ]);
        }

        // Tunisian suppliers
        $suppliers = [
            ['name' => 'Laboratoire Teriak', 'tax_status' => PartnerTaxStatus::REGISTERED],
            ['name' => 'Unimed Pharmaceuticals', 'tax_status' => PartnerTaxStatus::REGISTERED],
            ['name' => 'SIPHAT Distribution', 'tax_status' => PartnerTaxStatus::REGISTERED],
            ['name' => 'Consultants Pharma SARL', 'tax_status' => PartnerTaxStatus::NON_REGISTERED],
        ];

        foreach ($suppliers as $supplierData) {
            Partner::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'code' => 'SUPP-'.strtoupper(substr($supplierData['name'], 0, 3)).rand(100, 999),
                'name' => $supplierData['name'],
                'type' => PartnerType::Supplier,
                'country_code' => 'TN',
                'vat_number' => 'TN'.rand(10000000, 99999999).'XYZ',
                'tax_status' => $supplierData['tax_status'],
                'email' => strtolower(str_replace(' ', '', $supplierData['name'])).'@example.tn',
                'phone' => '+216 71 '.rand(100, 999).' '.rand(100, 999),
                'is_active' => true,
                'withholding_exempt' => false,
            ]);
        }

        // Add one non-resident supplier (different country)
        Partner::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'SUPP-FOR001',
            'name' => 'French Lab International',
            'type' => PartnerType::Supplier,
            'country_code' => 'FR',
            'vat_number' => 'FR12345678901',
            'tax_status' => PartnerTaxStatus::REGISTERED, // Foreign company  (still has VAT number)
            'email' => 'contact@frenchlab.fr',
            'phone' => '+33 1 42 86 82 00',
            'is_active' => true,
            'withholding_exempt' => false,
        ]);

        // Add one exempt partner (no withholding)
        Partner::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'SUPP-EXE001',
            'name' => 'Ministère de la Santé',
            'type' => PartnerType::Both,
            'country_code' => 'TN',
            'vat_number' => 'TN99999999GOV',
            'tax_status' => PartnerTaxStatus::EXEMPT,
            'email' => 'contact@sante.gov.tn',
            'phone' => '+216 71 567 890',
            'is_active' => true,
            'withholding_exempt' => true,
            'tax_exemption_reason' => 'Organisme gouvernemental',
        ]);

        // Create additional random partners using factory
        Partner::factory()
            ->count(30)
            ->customer()
            ->tunisia()
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);

        Partner::factory()
            ->count(20)
            ->supplier()
            ->tunisia()
            ->create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
            ]);

        $this->command->info('Created partners: 35 customers, 24 suppliers (with various tax statuses)');
    }

    private function createParapharmacyProducts(Company $company): void
    {
        $parapharmacyProducts = [
            // Vitamins & Supplements
            ['name' => 'Vitamine C 1000mg', 'category' => 'Vitamines', 'price' => 12.500],
            ['name' => 'Vitamine D3 2000 UI', 'category' => 'Vitamines', 'price' => 15.750],
            ['name' => 'Oméga 3 Fish Oil', 'category' => 'Suppléments', 'price' => 28.900],
            ['name' => 'Magnésium 300mg', 'category' => 'Minéraux', 'price' => 9.500],
            ['name' => 'Zinc 15mg', 'category' => 'Minéraux', 'price' => 8.250],
            ['name' => 'Probiotiques Multi-souches', 'category' => 'Suppléments', 'price' => 35.000],
            ['name' => 'Collagène Marin', 'category' => 'Suppléments', 'price' => 42.500],

            // Skincare
            ['name' => 'Crème Hydratante Visage', 'category' => 'Soins Visage', 'price' => 24.900],
            ['name' => 'Sérum Anti-âge', 'category' => 'Soins Visage', 'price' => 38.500],
            ['name' => 'Crème Solaire SPF 50+', 'category' => 'Protection Solaire', 'price' => 18.750],
            ['name' => 'Gel Nettoyant Doux', 'category' => 'Nettoyants', 'price' => 12.300],
            ['name' => 'Masque Purifiant', 'category' => 'Soins Visage', 'price' => 16.500],

            // Hair Care
            ['name' => 'Shampooing Fortifiant', 'category' => 'Soins Cheveux', 'price' => 14.200],
            ['name' => 'Après-shampooing Réparateur', 'category' => 'Soins Cheveux', 'price' => 15.800],
            ['name' => 'Huile Argan Bio', 'category' => 'Soins Cheveux', 'price' => 22.500],

            // Baby Care
            ['name' => 'Lingettes Bébé', 'category' => 'Bébé', 'price' => 5.500],
            ['name' => 'Crème Change Bébé', 'category' => 'Bébé', 'price' => 8.900],
            ['name' => 'Shampooing Doux Bébé', 'category' => 'Bébé', 'price' => 7.250],

            // Medical Devices
            ['name' => 'Thermomètre Digital', 'category' => 'Dispositifs Médicaux', 'price' => 12.000],
            ['name' => 'Tensiomètre Automatique', 'category' => 'Dispositifs Médicaux', 'price' => 85.000],
            ['name' => 'Masques Chirurgicaux (Boîte 50)', 'category' => 'Dispositifs Médicaux', 'price' => 6.500],
        ];

        foreach ($parapharmacyProducts as $productData) {
            Product::create([
                'id' => Str::uuid()->toString(),
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'sku' => 'PARA-'.strtoupper(Str::ascii(mb_substr(str_replace(' ', '', $productData['name']), 0, 8))).rand(10, 99),
                'name' => $productData['name'],
                'description' => 'Produit parapharmaceutique de qualité - '.$productData['name'],
                'is_physical' => true,
                'unit' => 'pièce',
                'sale_price' => $productData['price'],
                'purchase_price' => $productData['price'] * 0.6, // 40% margin
                'tax_rate' => '19.00', // 19% VAT in Tunisia
                'is_active' => true,
                'is_physical' => true,
            ]);
        }

        // Add some generic products using factory
        Product::factory()
            ->count(100)
            ->goods()
            ->create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
            ]);

        $totalProducts = count($parapharmacyProducts) + 100;
        $this->command->info("Created {$totalProducts} parapharmacy products");
    }

    private function addUsersToCompany(Tenant $tenant, Company $company): void
    {
        $users = User::where('tenant_id', $tenant->id)->get();

        foreach ($users as $user) {
            // Check if user already has membership
            $existingMembership = UserCompanyMembership::where('user_id', $user->id)
                ->where('company_id', $company->id)
                ->exists();

            if (! $existingMembership) {
                UserCompanyMembership::create([
                    'user_id' => $user->id,
                    'company_id' => $company->id,
                    'role' => MembershipRole::Manager,
                    'is_primary' => false, // Don't override primary company
                    'status' => MembershipStatus::Active,
                    'accepted_at' => now(),
                ]);

                $this->command->info("Granted access to {$user->email}");
            }
        }

        $this->command->info("Added {$users->count()} users to the new company");
    }
}
