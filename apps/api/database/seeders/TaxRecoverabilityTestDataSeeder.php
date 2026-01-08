<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\LocationType;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Product;
use App\Modules\Taxation\Domain\Enums\CompanyTaxStatus;
use Illuminate\Database\Seeder;

/**
 * Seeds test data for tax recoverability scenarios
 *
 * Creates:
 * 1. Two Tunisian companies (VAT registered vs non-registered)
 * 2. Test products
 * 3. Test suppliers
 * 4. Locations for each company
 */
class TaxRecoverabilityTestDataSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Setting up tax recoverability test data...');

        // Use existing user (assume admin user exists)
        $user = User::first();
        if (! $user) {
            $this->command->error('No user found. Please run DatabaseSeeder first.');

            return;
        }

        $this->command->info("Using existing user: {$user->email} (Tenant: {$user->tenant_id})");

        // Company 1: VAT Registered (Assujetti)
        $vatCompany = Company::firstOrCreate(
            [
                'tenant_id' => $user->tenant_id,
                'name' => 'Garage Assujetti SARL',
            ],
            [
                'legal_name' => 'Garage Assujetti SARL',
                'country_code' => 'TN',
                'currency' => 'TND',
                'locale' => 'fr',
                'timezone' => 'Africa/Tunis',
                'tax_status' => CompanyTaxStatus::REGISTERED,
                'vat_number' => 'TN1234567M',
                'default_tax_rate' => '19.00',
                'address_city' => 'Tunis',
            ]
        );

        $this->command->info("✓ Created VAT Registered Company: {$vatCompany->name} (ID: {$vatCompany->id})");
        $this->command->info("  Tax Status: REGISTERED | VAT#: {$vatCompany->vat_number}");
        $this->command->info("  Impact: VAT is RECOVERABLE (doesn't add to cost)");

        // Company 2: Non-VAT Registered
        $nonVatCompany = Company::firstOrCreate(
            [
                'tenant_id' => $user->tenant_id,
                'name' => 'Garage Non-Assujetti',
            ],
            [
                'legal_name' => 'Garage Non-Assujetti',
                'country_code' => 'TN',
                'currency' => 'TND',
                'locale' => 'fr',
                'timezone' => 'Africa/Tunis',
                'tax_status' => CompanyTaxStatus::NON_REGISTERED,
                'vat_number' => null,
                'default_tax_rate' => '0.00',
                'address_city' => 'Sfax',
            ]
        );

        $this->command->info("✓ Created Non-VAT Registered Company: {$nonVatCompany->name} (ID: {$nonVatCompany->id})");
        $this->command->info('  Tax Status: NON_REGISTERED | No VAT#');
        $this->command->info('  Impact: VAT is NON-RECOVERABLE (adds to cost)');

        // Create locations
        $vatLocation = Location::firstOrCreate(
            [
                'company_id' => $vatCompany->id,
                'code' => 'WH-VAT-001',
            ],
            [
                'name' => 'Main Warehouse - Tunis',
                'type' => LocationType::Warehouse,
                'is_default' => true,
                'address_city' => 'Tunis',
                'address_country' => 'TN',
            ]
        );

        $nonVatLocation = Location::firstOrCreate(
            [
                'company_id' => $nonVatCompany->id,
                'code' => 'WH-NONVAT-001',
            ],
            [
                'name' => 'Main Warehouse - Sfax',
                'type' => LocationType::Warehouse,
                'is_default' => true,
                'address_city' => 'Sfax',
                'address_country' => 'TN',
            ]
        );

        $this->command->info('✓ Created locations for both companies');

        // Create category
        $category = Category::firstOrCreate(
            [
                'company_id' => $vatCompany->id,
                'name' => 'Auto Parts',
            ],
            [
                'description' => 'Automotive parts and accessories',
            ]
        );

        // Create suppliers
        $supplier1 = Partner::firstOrCreate(
            [
                'company_id' => $vatCompany->id,
                'email' => 'supplier-vat@example.tn',
            ],
            [
                'tenant_id' => $vatCompany->tenant_id,
                'type' => 'supplier',
                'name' => 'Fournisseur Auto Tunis',
                'phone' => '+216 71 123 456',
                'tax_status' => 'REGISTERED',
                'vat_number' => 'TN9876543N',
            ]
        );

        $supplier2 = Partner::firstOrCreate(
            [
                'company_id' => $nonVatCompany->id,
                'email' => 'supplier-nonvat@example.tn',
            ],
            [
                'tenant_id' => $nonVatCompany->tenant_id,
                'type' => 'supplier',
                'name' => 'Fournisseur Auto Sfax',
                'phone' => '+216 74 123 456',
                'tax_status' => 'REGISTERED',
                'vat_number' => 'TN5555555P',
            ]
        );

        $this->command->info('✓ Created suppliers for both companies');

        // Create products for VAT company
        $product1 = Product::firstOrCreate(
            [
                'company_id' => $vatCompany->id,
                'sku' => 'BRAKE-PAD-001',
            ],
            [
                'tenant_id' => $vatCompany->tenant_id,
                'name' => 'Brake Pads Set - Front',
                'type' => 'part',
                'description' => 'High performance ceramic brake pads',
                'unit' => 'SET',
                'category_id' => $category->id,
                'purchase_price' => '50.000',
                'sale_price' => '100.000',
                'tax_rate' => '19.00',
                'is_active' => true,
            ]
        );

        $product2 = Product::firstOrCreate(
            [
                'company_id' => $vatCompany->id,
                'sku' => 'OIL-FILTER-001',
            ],
            [
                'tenant_id' => $vatCompany->tenant_id,
                'name' => 'Oil Filter',
                'type' => 'part',
                'description' => 'Engine oil filter standard',
                'unit' => 'PIECE',
                'category_id' => $category->id,
                'purchase_price' => '10.000',
                'sale_price' => '25.000',
                'tax_rate' => '19.00',
                'is_active' => true,
            ]
        );

        // Create same products for non-VAT company
        $product3 = Product::firstOrCreate(
            [
                'company_id' => $nonVatCompany->id,
                'sku' => 'BRAKE-PAD-002',
            ],
            [
                'tenant_id' => $nonVatCompany->tenant_id,
                'name' => 'Brake Pads Set - Front',
                'type' => 'part',
                'description' => 'High performance ceramic brake pads',
                'unit' => 'SET',
                'category_id' => null,
                'purchase_price' => '50.000',
                'sale_price' => '100.000',
                'tax_rate' => '19.00',
                'is_active' => true,
            ]
        );

        $product4 = Product::firstOrCreate(
            [
                'company_id' => $nonVatCompany->id,
                'sku' => 'OIL-FILTER-002',
            ],
            [
                'tenant_id' => $nonVatCompany->tenant_id,
                'name' => 'Oil Filter',
                'type' => 'part',
                'description' => 'Engine oil filter standard',
                'unit' => 'PIECE',
                'category_id' => null,
                'purchase_price' => '10.000',
                'sale_price' => '25.000',
                'tax_rate' => '19.00',
                'is_active' => true,
            ]
        );

        $this->command->info('✓ Created test products for both companies');

        $this->command->newLine();
        $this->command->info('═══════════════════════════════════════════════════════════');
        $this->command->info('TEST DATA SUMMARY');
        $this->command->info('═══════════════════════════════════════════════════════════');
        $this->command->newLine();

        $this->command->info('📋 SCENARIO 1: VAT Registered Company (Assujetti)');
        $this->command->info('   Company: Garage Assujetti SARL');
        $this->command->info('   Location: Main Warehouse - Tunis');
        $this->command->info('   Supplier: Fournisseur Auto Tunis');
        $this->command->info('   Products:');
        $this->command->info("     - {$product1->reference}: {$product1->name} @ {$product1->purchase_price} TND");
        $this->command->info("     - {$product2->reference}: {$product2->name} @ {$product2->purchase_price} TND");
        $this->command->newLine();
        $this->command->info('   Expected Calculation:');
        $this->command->info('   Purchase 10 units @ 50.000 TND each = 500.000 TND');
        $this->command->info('   + TVA 19% = 95.000 TND (RECOVERABLE - NOT added to cost)');
        $this->command->info('   + Stamp Duty = 1.000 TND (NON-RECOVERABLE - ADDED to cost)');
        $this->command->info('   = Total Invoice: 596.000 TND');
        $this->command->info('   = Product Cost: 50.100 TND/unit (500 + 1 stamp / 10)');
        $this->command->info('   = Stock Value: 501.000 TND');
        $this->command->newLine();

        $this->command->info('📋 SCENARIO 2: Non-VAT Registered Company');
        $this->command->info('   Company: Garage Non-Assujetti');
        $this->command->info('   Location: Main Warehouse - Sfax');
        $this->command->info('   Supplier: Fournisseur Auto Sfax');
        $this->command->info('   Products:');
        $this->command->info("     - {$product3->reference}: {$product3->name} @ {$product3->purchase_price} TND");
        $this->command->info("     - {$product4->reference}: {$product4->name} @ {$product4->purchase_price} TND");
        $this->command->newLine();
        $this->command->info('   Expected Calculation:');
        $this->command->info('   Purchase 20 units @ 10.000 TND each = 200.000 TND');
        $this->command->info('   + TVA 19% = 38.000 TND (NON-RECOVERABLE - ADDED to cost)');
        $this->command->info('   + Stamp Duty = 1.000 TND (NON-RECOVERABLE - ADDED to cost)');
        $this->command->info('   = Total Invoice: 239.000 TND');
        $this->command->info('   = Product Cost: 11.950 TND/unit (200 + 38 VAT + 1 stamp / 20)');
        $this->command->info('   = Stock Value: 239.000 TND');
        $this->command->newLine();

        $this->command->info('═══════════════════════════════════════════════════════════');
        $this->command->info('✅ Test data ready! Login with: test@autoerp.tn / password');
        $this->command->info('═══════════════════════════════════════════════════════════');
    }
}
