<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Accounting\Domain\Account;
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
            ImportType::Partners,
            ImportType::Products,
            ImportType::StockLevels,
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
            'name' => ['name', 'customer_name', 'company_name', 'product_name', 'item_name', 'title'],
            'email' => ['email', 'email_address', 'e_mail', 'mail'],
            'phone' => ['phone', 'telephone', 'phone_number', 'mobile', 'tel'],
            'type' => ['type', 'partner_type', 'customer_type', 'product_type', 'category'],
            'sku' => ['sku', 'code', 'product_code', 'item_code', 'part_number'],
            'vat_number' => ['vat', 'vat_number', 'tax_id', 'tax_number'],
            'sale_price' => ['sale_price', 'selling_price', 'price', 'retail_price'],
            'purchase_price' => ['purchase_price', 'cost', 'cost_price', 'buy_price'],
            'quantity' => ['quantity', 'qty', 'stock', 'stock_qty', 'on_hand'],
            'account_code' => ['account_code', 'account', 'code', 'gl_code'],
            'debit' => ['debit', 'dr', 'debit_amount'],
            'credit' => ['credit', 'cr', 'credit_amount'],
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
                ],
                [
                    'name' => 'Oil Change Service',
                    'sku' => 'SVC-OIL',
                    'type' => 'service',
                    'description' => 'Standard oil change with filter',
                    'sale_price' => '45.00',
                    'purchase_price' => '',
                    'barcode' => '',
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
        $stockCount = StockLevel::where('tenant_id', $tenantId)->count();
        $accountCount = Account::where('tenant_id', $tenantId)->count();

        return [
            'partners' => [
                'count' => $partnerCount,
                'has_data' => $partnerCount > 0,
            ],
            'products' => [
                'count' => $productCount,
                'has_data' => $productCount > 0,
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
        };
    }
}
