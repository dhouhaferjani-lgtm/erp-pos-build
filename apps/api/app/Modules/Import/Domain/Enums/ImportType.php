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
            self::Products => ['description', 'sale_price', 'purchase_price', 'barcode'],
            self::StockLevels => ['notes'],
            self::OpeningBalances => ['description', 'reference'],
            self::ProductImages => [], // ZIP-based import, not CSV
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
        };
    }
}
