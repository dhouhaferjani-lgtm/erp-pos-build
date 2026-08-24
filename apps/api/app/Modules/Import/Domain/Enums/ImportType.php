<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Enums;

enum ImportType: string
{
    case Parties = 'parties';
    case Partners = 'partners';
    case Products = 'products';

    /**
     * @deprecated Owner ruling D4 (document-per-action remediation, lane V6).
     *
     * The first import ever implemented. It set an ABSOLUTE stock quantity via
     * `InventoryService::upsertStockLevel` — a bare `StockLevel::updateOrCreate`
     * with no stock movement, no justifying document, no WAC/GL posting, and
     * `reserved` silently reset to 0. It was therefore invisible to every
     * ledger-based detector. Opening stock now flows through the Products
     * import (quantity + purchase_price + location_code), which runs
     * `ProductOpeningStockPhase` → `OpeningBalancePostingService` and posts a
     * real Opening movement with the enter-once guard
     * (`OpeningAlreadyExistsException`) and the reset affordance
     * (`ResetOpeningBalanceService`).
     *
     * The CASE deliberately survives the deprecation: `import_jobs.type` is a
     * plain `string(50)` column (see
     * `2025_11_30_150000_create_import_tables.php`) cast to this enum by
     * `ImportJob::casts()`. Eloquent's enum cast resolves it with
     * `$enumClass::from($value)` (`HasAttributes::getEnumCaseFromValue`), which
     * throws `ValueError` for an unknown backing value — so dropping the case
     * would 500 every read of a tenant's import history that ever contained one
     * of these jobs, not merely the job's own detail page. The type is instead
     * made UNSELECTABLE: see `isDeprecated()` / `selectable()` and their
     * enforcement in `ImportController::store()` / `::execute()`,
     * `ImportService::importRow()` and `MigrationWizardService`.
     */
    case StockLevels = 'stock_levels';

    case OpeningBalances = 'opening_balances';
    case ProductImages = 'product_images';
    case CompositeItems = 'composite_items';

    /**
     * Operator-facing explanation for a retired type — null while the type is
     * still importable. Single source of truth for every refusal message so the
     * upload gate and the execute gate cannot drift apart.
     */
    public function deprecationMessage(): ?string
    {
        return match ($this) {
            self::StockLevels => 'The stock levels import is no longer supported. It set stock quantities directly, with no stock movement and no justifying document. Import your opening stock with the Products import instead: it accepts quantity, purchase_price and location_code, and posts a proper opening stock movement.',
            default => null,
        };
    }

    /**
     * True for types that may no longer be chosen for a NEW import job.
     *
     * The case still exists so historical `import_jobs` rows stay readable
     * (see the note on {@see self::StockLevels}); this flag is what makes it
     * unselectable everywhere a new import can be started.
     */
    public function isDeprecated(): bool
    {
        return $this->deprecationMessage() !== null;
    }

    /**
     * The import types a user may still start.
     *
     * @return list<self>
     */
    public static function selectable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $type): bool => ! $type->isDeprecated(),
        ));
    }

    /**
     * Get the required columns for this import type
     *
     * @return array<string>
     */
    public function getRequiredColumns(): array
    {
        return match ($this) {
            self::Parties => ['name', 'type'],
            self::Partners => ['name', 'type'],
            self::Products => ['name'],
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
            self::Parties => [
                'code',
                'email',
                'phone',
                'tax_id',
                'address_line1',
                'address_city',
                'address_postal_code',
                'address_country',
                'opening_balance',
                'opening_balance_customer',
                'opening_balance_supplier',
                'balance_date',
                'reference',
            ],
            self::Partners => ['email', 'phone', 'vat_number', 'address', 'city', 'country'],
            self::Products => ['sku', 'type', 'description', 'sale_price', 'sale_price_incl_tax', 'sale_price_excl_tax', 'purchase_price', 'margin', 'quantity', 'location_code', 'placement_path', 'barcode', 'category_name', 'brand', 'tax_rate', 'unit', 'is_active'],
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
            self::Parties => [
                'name' => ['required', 'string', 'max:255'],
                'type' => ['required', 'in:customer,supplier,both'],
                'code' => ['nullable', 'string', 'max:100'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'tax_id' => ['nullable', 'string', 'max:50'],
                'opening_balance' => ['nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,3})?$/'],
                'opening_balance_customer' => ['nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,3})?$/'],
                'opening_balance_supplier' => ['nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,3})?$/'],
                'balance_date' => ['nullable', 'date'],
                'reference' => ['nullable', 'string', 'max:100'],
            ],
            self::Partners => [
                'name' => ['required', 'string', 'max:255'],
                'type' => ['required', 'in:customer,supplier,both'],
                'email' => ['nullable', 'email', 'max:255'],
                'phone' => ['nullable', 'string', 'max:50'],
                'vat_number' => ['nullable', 'string', 'max:50'],
            ],
            self::Products => [
                'name' => ['required', 'string', 'max:255'],
                'sku' => ['nullable', 'string', 'max:100'],
                'type' => ['nullable', 'in:part,service,consumable'],
                'sale_price' => ['nullable', 'numeric', 'min:0'],
                'sale_price_incl_tax' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
                'sale_price_excl_tax' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,3})?$/'],
                'purchase_price' => ['nullable', 'numeric', 'min:0'],
                'margin' => ['nullable', 'numeric', 'regex:/^-?\d+(\.\d{1,2})?$/'],
                'quantity' => ['nullable', 'numeric', 'min:0', 'regex:/^\d+(\.\d{1,4})?$/'],
                'location_code' => ['nullable', 'string', 'max:100'],
                'placement_path' => ['nullable', 'string', 'max:1000'],
                'brand' => ['nullable', 'string', 'max:255'],
                // categories.name and categories.slug are varchar(255): an
                // over-long cell must be rejected HERE, with its row number,
                // not mid-import as a raw SQLSTATE (gate r1 finding 2).
                'category_name' => ['nullable', 'string', 'max:255'],
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
                // The 3-decimal money ceiling (CLAUDE.md rule 19) matches the one the
                // opening-batch API enforces on rows.*.debit / rows.*.credit. Without
                // it, extra decimals are silently TRUNCATED by CurrencyScale::
                // bcformatStrict when the row is staged, so the posted GL entry would
                // quietly differ from the file the accountant supplied.
                'debit' => ['required_without:credit', 'nullable', 'numeric', 'min:0', 'regex:/^-?\d+(\.\d{1,3})?$/'],
                'credit' => ['required_without:debit', 'nullable', 'numeric', 'min:0', 'regex:/^-?\d+(\.\d{1,3})?$/'],
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
