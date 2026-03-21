<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Enums;

enum ImportType: string
{
    case Partners = 'partners';
    case Products = 'products';
    case StockLevels = 'stock_levels';
    case OpeningBalances = 'opening_balances';
    case ProductImages = 'product_images';
    case CompositeItems = 'composite_items';

    /**
     * Get the required columns for this import type
     *
     * @return array<string>
     */
    public function getRequiredColumns(): array
    {
        return match ($this) {
            self::Partners => ['name', 'type'],
            self::Products => ['name', 'sku', 'type'],
            self::StockLevels => ['product_sku', 'location_code', 'quantity'],
            self::OpeningBalances => ['account_code', 'debit', 'credit'],
            self::ProductImages => [], // ZIP-based import, not CSV
            self::CompositeItems => ['code', 'name', 'base_price'],
        };
    }

    /**
     * Get optional columns for this import type
     *
     * @return array<string>
     */
    public function getOptionalColumns(): array
    {
        return match ($this) {
            self::Partners => ['email', 'phone', 'vat_number', 'address', 'city', 'country'],
            self::Products => ['description', 'sale_price', 'purchase_price', 'barcode', 'category_name', 'tax_rate', 'unit', 'is_active'],
            self::StockLevels => ['notes'],
            self::OpeningBalances => ['description', 'reference'],
            self::ProductImages => [], // ZIP-based import, not CSV
            self::CompositeItems => ['vertical_type', 'production_type', 'pricing_mode', 'tax_rate', 'manual_cost', 'category_name', 'is_active', 'description'],
        };
    }

    /**
     * Get validation rules for this import type
     *
     * @return array<string, array<string>>
     */
    public function getValidationRules(): array
    {
        return match ($this) {
            self::Partners => [
                'name' => ['required', 'string', 'max:255'],
                'type' => ['required', 'in:customer,supplier,both'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'vat_number' => ['nullable', 'string', 'max:50'],
            ],
            self::Products => [
                'name' => ['required', 'string', 'max:255'],
                'sku' => ['required', 'string', 'max:100'],
                'type' => ['required', 'in:part,service,consumable'],
                'sale_price' => ['nullable', 'numeric', 'min:0'],
                'purchase_price' => ['nullable', 'numeric', 'min:0'],
                'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'unit' => ['nullable', 'string', 'max:50'],
                'is_active' => ['nullable', 'in:true,false,1,0,yes,no'],
            ],
            self::StockLevels => [
                'product_sku' => ['required', 'string'],
                'location_code' => ['required', 'string'],
                'quantity' => ['required', 'numeric', 'min:0'],
            ],
            self::OpeningBalances => [
                'account_code' => ['required', 'string'],
                'debit' => ['required_without:credit', 'nullable', 'numeric', 'min:0'],
                'credit' => ['required_without:debit', 'nullable', 'numeric', 'min:0'],
            ],
            self::ProductImages => [], // ZIP-based import, validation during extraction
            self::CompositeItems => [
                'code' => ['required', 'string', 'max:100'],
                'name' => ['required', 'string', 'max:255'],
                'base_price' => ['required', 'numeric', 'min:0'],
                'vertical_type' => ['nullable', 'in:fnb,manufacturing,sewing,bakery,generic'],
                'production_type' => ['nullable', 'in:made_to_order,batch,stock'],
                'pricing_mode' => ['nullable', 'in:standard,fixed_bundle'],
                'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
                'manual_cost' => ['nullable', 'numeric', 'min:0'],
                'is_active' => ['nullable', 'in:true,false,1,0,yes,no'],
            ],
        };
    }
}
