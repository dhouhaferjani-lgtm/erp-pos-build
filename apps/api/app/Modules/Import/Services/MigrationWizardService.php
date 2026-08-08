<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Catalog\Domain\Entities\CompositeItem;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Import\Domain\Enums\ImportType;
use App\Modules\Inventory\Domain\StockLevel;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Product\Domain\Product;

final class MigrationWizardService
{
    /**
     * Get the recommended order for importing data
     *
     * @return array<ImportType>
     */
    public function getRecommendedImportOrder(): array
    {
        return [
            ImportType::Parties,
            ImportType::Partners,
            ImportType::Products,
            ImportType::CompositeItems,
            // ImportType::StockLevels retired by owner ruling D4 — opening stock
            // now rides the Products import (quantity + purchase_price +
            // location_code), which posts a real Opening movement.
            ImportType::OpeningBalances,
        ];
    }

    /**
     * Check if dependencies are met for an import type
     *
     * @return array{can_import: bool, missing_dependencies: array<string>, warnings: array<string>}
     */
    public function checkDependencies(string $tenantId, ImportType $type): array
    {
        $missing = [];
        $warnings = [];

        switch ($type) {
            case ImportType::Parties:
                // No dependencies
                break;

            case ImportType::Partners:
                // No dependencies
                break;

            case ImportType::Products:
                // No hard dependencies, but warn if no partners exist
                if (Partner::where('tenant_id', $tenantId)->count() === 0) {
                    $warnings[] = 'No partners exist. Consider importing partners first for supplier references.';
                }
                break;

            case ImportType::StockLevels:
                if (Product::where('tenant_id', $tenantId)->count() === 0) {
                    $missing[] = 'products';
                }
                // Check locations through company (new architecture)
                $locationCount = Location::whereIn(
                    'company_id',
                    Company::where('tenant_id', $tenantId)->pluck('id')
                )->count();
                if ($locationCount === 0) {
                    $missing[] = 'locations';
                }
                break;

            case ImportType::CompositeItems:
                // No hard dependencies, but warn if no products exist (for future recipe linking)
                if (Product::where('tenant_id', $tenantId)->count() === 0) {
                    $warnings[] = 'No products exist. Consider importing products first for recipe ingredients.';
                }
                break;

            case ImportType::OpeningBalances:
                if (Account::where('tenant_id', $tenantId)->count() === 0) {
                    $missing[] = 'accounts';
                }
                break;
        }

        return [
            'can_import' => empty($missing),
            'missing_dependencies' => $missing,
            'warnings' => $warnings,
        ];
    }

    /**
     * Suggest column mappings based on source headers
     *
     * @param  array<string>  $sourceHeaders
     * @return array<string, string|null>
     */
    public function suggestColumnMapping(ImportType $type, array $sourceHeaders): array
    {
        $targetColumns = array_merge(
            $type->getRequiredColumns(),
            $type->getOptionalColumns()
        );

        $suggestions = [];

        foreach ($targetColumns as $target) {
            $suggestions[$target] = $this->findBestMatch($target, $sourceHeaders);
        }

        return $suggestions;
    }

    /**
     * Find the best matching source column for a target column
     *
     * @param  array<string>  $sourceHeaders
     */
    private function findBestMatch(string $target, array $sourceHeaders): ?string
    {
        $normalizedTarget = strtolower(str_replace(['_', '-'], '', $target));

        // Direct match
        foreach ($sourceHeaders as $source) {
            $normalizedSource = strtolower(str_replace(['_', '-'], '', $source));
            if ($normalizedSource === $normalizedTarget) {
                return $source;
            }
        }

        // Partial match or common aliases
        $aliases = $this->getColumnAliases();
        $targetAliases = $aliases[$target] ?? [$target];

        foreach ($sourceHeaders as $source) {
            $normalizedSource = strtolower(str_replace(['_', '-'], '', $source));

            foreach ($targetAliases as $alias) {
                $normalizedAlias = strtolower(str_replace(['_', '-'], '', $alias));
                if (str_contains($normalizedSource, $normalizedAlias)) {
                    return $source;
                }
            }
        }

        return null;
    }

    /**
     * Get common column name aliases
     *
     * @return array<string, array<string>>
     */
    private function getColumnAliases(): array
    {
        return [
            'name' => ['name', 'customer_name', 'company_name', 'product_name', 'item_name', 'title', 'nom'],
            'email' => ['email', 'email_address', 'e_mail', 'mail'],
            'phone' => ['phone', 'telephone', 'phone_number', 'mobile', 'tel', 'téléphone'],
            'type' => ['type', 'partner_type', 'customer_type', 'product_type', 'category'],
            'sku' => ['sku', 'code', 'product_code', 'item_code', 'part_number'],
            'code' => ['code', 'partner_code', 'customer_code', 'supplier_code'],
            'vat_number' => ['vat', 'vat_number', 'tax_id', 'tax_number'],
            'tax_id' => ['vat', 'vat_number', 'tax_id', 'tax_number', 'matricule_fiscal'],
            'address_line1' => ['address', 'address_line1', 'street', 'street_address', 'adresse'],
            'address_city' => ['city', 'address_city', 'ville'],
            'address_postal_code' => ['postal_code', 'postcode', 'zip', 'address_postal_code', 'code_postal'],
            'address_country' => ['country', 'address_country', 'pays'],
            'opening_balance' => ['opening_balance', 'balance', 'solde'],
            'opening_balance_customer' => ['opening_balance_customer', 'customer_balance', 'solde_client'],
            'opening_balance_supplier' => ['opening_balance_supplier', 'supplier_balance', 'solde_fournisseur'],
            'balance_date' => ['balance_date', 'opening_date', 'date_solde'],
            'reference' => ['reference', 'ref', 'external_reference'],
            'sale_price' => ['sale_price', 'selling_price', 'price', 'retail_price'],
            'purchase_price' => ['purchase_price', 'cost', 'cost_price', 'buy_price'],
            'quantity' => ['quantity', 'qty', 'stock', 'stock_qty', 'on_hand'],
            'account_code' => ['account_code', 'account', 'code', 'gl_code'],
            'debit' => ['debit', 'dr', 'debit_amount'],
            'credit' => ['credit', 'cr', 'credit_amount'],
            'base_price' => ['base_price', 'price', 'unit_price', 'selling_price'],
            'vertical_type' => ['vertical_type', 'vertical', 'category_type'],
            'production_type' => ['production_type', 'production', 'prep_type'],
            'pricing_mode' => ['pricing_mode', 'pricing'],
            'category_name' => ['category_name', 'category', 'group'],
        ];
    }

    /**
     * Generate a CSV template for an import type
     */
    public function generateTemplate(ImportType $type): string
    {
        $columns = array_merge(
            $type->getRequiredColumns(),
            $type->getOptionalColumns()
        );

        $header = implode(',', $columns);
        $exampleRows = $this->generateExampleRows($type, $columns);

        return $header."\n".implode("\n", $exampleRows);
    }

    /**
     * Generate example data rows for a template
     *
     * @param  array<string>  $columns
     * @return array<string>
     */
    private function generateExampleRows(ImportType $type, array $columns): array
    {
        $allExamples = match ($type) {
            ImportType::Parties => [
                [
                    'name' => 'Acme Corporation',
                    'type' => 'customer',
                    'code' => 'CUST-001',
                    'email' => 'contact@acme.com',
                    'phone' => '+1234567890',
                    'tax_id' => 'FR12345678901',
                    'address_line1' => '123 Main Street',
                    'address_city' => 'Paris',
                    'address_postal_code' => '75001',
                    'address_country' => 'France',
                    'opening_balance' => '100.000',
                    'opening_balance_customer' => '',
                    'opening_balance_supplier' => '',
                    'balance_date' => '2026-01-01',
                    'reference' => 'OB-CUST-001',
                ],
                [
                    'name' => 'Global Supplies Ltd',
                    'type' => 'supplier',
                    'code' => 'SUP-001',
                    'email' => 'sales@global-supplies.com',
                    'phone' => '+0987654321',
                    'tax_id' => 'DE987654321',
                    'address_line1' => '456 Industrial Ave',
                    'address_city' => 'Berlin',
                    'address_postal_code' => '10115',
                    'address_country' => 'Germany',
                    'opening_balance' => '-50.000',
                    'opening_balance_customer' => '',
                    'opening_balance_supplier' => '',
                    'balance_date' => '2026-01-01',
                    'reference' => 'OB-SUP-001',
                ],
            ],
            ImportType::Partners => [
                // Customer example
                [
                    'name' => 'Acme Corporation',
                    'type' => 'customer',
                    'email' => 'contact@acme.com',
                    'phone' => '+1234567890',
                    'vat_number' => 'FR12345678901',
                    'address' => '123 Main Street',
                    'city' => 'Paris',
                    'country' => 'France',
                ],
                // Supplier example
                [
                    'name' => 'Global Supplies Ltd',
                    'type' => 'supplier',
                    'email' => 'sales@global-supplies.com',
                    'phone' => '+0987654321',
                    'vat_number' => 'DE987654321',
                    'address' => '456 Industrial Ave',
                    'city' => 'Berlin',
                    'country' => 'Germany',
                ],
                // Both (customer AND supplier) example
                [
                    'name' => 'Parts & Service Co',
                    'type' => 'both',
                    'email' => 'info@parts-service.com',
                    'phone' => '+1122334455',
                    'vat_number' => 'GB123456789',
                    'address' => '789 Trade Center',
                    'city' => 'London',
                    'country' => 'United Kingdom',
                ],
            ],
            ImportType::Products => [
                [
                    'name' => 'Brake Pad Set',
                    'sku' => 'BP-001',
                    'type' => 'part',
                    'description' => 'Front brake pads for sedan',
                    'sale_price' => '29.99',
                    'purchase_price' => '15.00',
                    'barcode' => '1234567890123',
                    'category_name' => 'Brake Parts',
                    'tax_rate' => '19',
                    'unit' => 'piece',
                    'is_active' => 'true',
                ],
                [
                    'name' => 'Oil Change Service',
                    'sku' => 'SVC-OIL',
                    'type' => 'service',
                    'description' => 'Standard oil change with filter',
                    'sale_price' => '45.00',
                    'purchase_price' => '',
                    'barcode' => '',
                    'category_name' => 'Services',
                    'tax_rate' => '19',
                    'unit' => '',
                    'is_active' => 'true',
                ],
            ],
            ImportType::StockLevels => [
                [
                    'product_sku' => 'BP-001',
                    'location_code' => 'WH-MAIN',
                    'quantity' => '100',
                    'notes' => 'Initial stock',
                ],
                [
                    'product_sku' => 'BP-002',
                    'location_code' => 'WH-MAIN',
                    'quantity' => '50',
                    'notes' => 'Rear brake pads',
                ],
            ],
            ImportType::OpeningBalances => [
                [
                    'account_code' => '1000',
                    'debit' => '5000.00',
                    'credit' => '0.00',
                    'description' => 'Opening balance - Cash',
                    'reference' => 'OB-2025',
                ],
                [
                    'account_code' => '2000',
                    'debit' => '0.00',
                    'credit' => '3000.00',
                    'description' => 'Opening balance - Accounts Payable',
                    'reference' => 'OB-2025',
                ],
            ],
            ImportType::CompositeItems => [
                [
                    'code' => 'ESPRESSO',
                    'name' => 'Espresso',
                    'base_price' => '2.50',
                    'vertical_type' => 'fnb',
                    'production_type' => 'made_to_order',
                    'pricing_mode' => 'standard',
                    'tax_rate' => '19',
                    'manual_cost' => '1.20',
                    'category_name' => 'Hot Drinks',
                    'is_active' => 'true',
                    'description' => 'Single shot espresso',
                ],
                [
                    'code' => 'CLASSIC-BURGER',
                    'name' => 'Classic Burger',
                    'base_price' => '8.90',
                    'vertical_type' => 'fnb',
                    'production_type' => 'made_to_order',
                    'pricing_mode' => 'standard',
                    'tax_rate' => '19',
                    'manual_cost' => '3.50',
                    'category_name' => 'Food',
                    'is_active' => 'true',
                    'description' => 'Beef burger with lettuce, tomato, and cheese',
                ],
            ],
            ImportType::ProductImages => [],
        };

        $rows = [];
        foreach ($allExamples as $example) {
            $values = [];
            foreach ($columns as $column) {
                $value = $example[$column] ?? '';
                // Escape commas and quotes in CSV
                if (str_contains($value, ',') || str_contains($value, '"')) {
                    $value = '"'.str_replace('"', '""', $value).'"';
                }
                $values[] = $value;
            }
            $rows[] = implode(',', $values);
        }

        return $rows;
    }

    /**
     * Get current migration status for a tenant
     *
     * @return array<string, array{count: int, has_data: bool}>
     */
    public function getMigrationStatus(string $tenantId): array
    {
        $partnerCount = Partner::where('tenant_id', $tenantId)->count();
        $productCount = Product::where('tenant_id', $tenantId)->count();
        $compositeItemCount = CompositeItem::where('tenant_id', $tenantId)->count();
        $stockCount = StockLevel::where('tenant_id', $tenantId)->count();
        $accountCount = Account::where('tenant_id', $tenantId)->count();

        return [
            'parties' => [
                'count' => $partnerCount,
                'has_data' => $partnerCount > 0,
            ],
            'partners' => [
                'count' => $partnerCount,
                'has_data' => $partnerCount > 0,
            ],
            'products' => [
                'count' => $productCount,
                'has_data' => $productCount > 0,
            ],
            'composite_items' => [
                'count' => $compositeItemCount,
                'has_data' => $compositeItemCount > 0,
            ],
            'stock_levels' => [
                'count' => $stockCount,
                'has_data' => $stockCount > 0,
            ],
            'accounts' => [
                'count' => $accountCount,
                'has_data' => $accountCount > 0,
            ],
        ];
    }

    /**
     * Get import type metadata
     *
     * @return array<string, string>
     */
    public function getImportTypeMetadata(ImportType $type): array
    {
        return match ($type) {
            ImportType::Parties => [
                'type' => $type->value,
                'label' => 'Business Partners',
                'description' => 'Import customers and suppliers with optional opening balances.',
            ],
            ImportType::Partners => [
                'type' => $type->value,
                'label' => 'Partners (Customers & Suppliers)',
                'description' => 'Import your customer and supplier records. Should be imported first.',
            ],
            ImportType::Products => [
                'type' => $type->value,
                'label' => 'Products & Services',
                'description' => 'Import your product catalog including parts, services, and consumables.',
            ],
            ImportType::StockLevels => [
                'type' => $type->value,
                'label' => 'Stock Levels',
                'description' => 'Import current stock quantities. Requires products and locations to exist.',
            ],
            ImportType::OpeningBalances => [
                'type' => $type->value,
                'label' => 'Opening Balances',
                'description' => 'Import accounting opening balances. Requires chart of accounts to exist.',
            ],
            ImportType::CompositeItems => [
                'type' => $type->value,
                'label' => 'Composite Items (Menu Items)',
                'description' => 'Import composite items like menu items, kits, and bundles.',
            ],
            ImportType::ProductImages => [
                'type' => $type->value,
                'label' => 'Product Images',
                'description' => 'Import product images via ZIP file. Requires products to exist.',
            ],
        };
    }
}
