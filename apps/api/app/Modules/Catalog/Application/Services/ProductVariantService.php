<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Application\Commands\CreateVariantCommand;
use App\Modules\Catalog\Domain\Entities\ProductAttributeValue;
use App\Modules\Catalog\Domain\Entities\ProductVariant;
use App\Modules\Catalog\Domain\Entities\ProductVariantAttributeValue;
use App\Modules\Catalog\Domain\Events\ProductVariantCreated;
use App\Modules\Catalog\Domain\Repositories\AttributeRepository;
use App\Modules\Catalog\Domain\Repositories\AttributeValueRepository;
use App\Modules\Catalog\Domain\Repositories\ProductVariantRepository;
use App\Modules\Catalog\Domain\Support\VariantMatrixLimit;
use App\Modules\Inventory\Application\Services\StockLevelMigrationService;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\Exceptions\DuplicateBarcodeException;
use App\Shared\Domain\Exceptions\MatrixGenerationLimitException;
use App\Shared\Domain\Exceptions\MissingVariantException;
use App\Shared\Domain\Exceptions\VariantRequiredException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ProductVariantService
{
    public function __construct(
        private readonly ProductVariantRepository $variantRepo,
        private readonly AttributeRepository $attributeRepo,
        private readonly AttributeValueRepository $attributeValueRepo,
        private readonly ProductVariantMatrixGenerator $matrixGenerator,
        private readonly StockLevelMigrationService $stockMigrator,
    ) {}

    /**
     * Persist a new product variant together with its attribute-value junction rows,
     * then emit ProductVariantCreated.
     *
     * When this is the first variant for the product (and isDefault is true), a
     * stock-level migration from product-level stock to this default variant should
     * occur — that is deferred to Task 14.
     */
    public function createVariant(CreateVariantCommand $command): ProductVariant
    {
        return DB::transaction(function () use ($command): ProductVariant {
            $variant = new ProductVariant;
            $variant->tenant_id = $command->tenantId;
            $variant->company_id = $command->companyId;
            $variant->product_id = $command->productId;
            $variant->variant_code = $command->variantCode;
            $variant->sku = $command->sku;
            $variant->name_suffix = $command->nameSuffix;
            $variant->is_default = $command->isDefault;
            $variant->barcode = $command->barcode;
            $variant->price_override = $command->priceOverride;
            $variant->cost_override = $command->costOverride;
            $variant->image_url = $command->imageUrl;

            $this->variantRepo->save($variant);

            foreach ($command->attributeValues as $pair) {
                ProductVariantAttributeValue::create([
                    'variant_id' => $variant->id,
                    'attribute_id' => $pair['attributeId'],
                    'attribute_value_id' => $pair['attributeValueId'],
                ]);
            }

            $isFirstVariant = $this->variantRepo->listForProduct($command->productId, onlyActive: false)->count() === 1;

            if ($isFirstVariant && $command->isDefault) {
                $this->stockMigrator->migrateToDefaultVariant(
                    productId: $command->productId,
                    defaultVariantId: $variant->id,
                );
            }

            // Dispatch event only after the outermost transaction commits so that
            // reactors never observe an event whose writes were subsequently rolled back.
            // When called standalone, afterCommit fires at the end of this transaction.
            // When called from generateMatrix's outer transaction (e.g. matrix generation),
            // it fires when that outer transaction commits — correct nesting behaviour.
            DB::afterCommit(function () use ($variant): void {
                event(new ProductVariantCreated(
                    variantId: $variant->id,
                    tenantId: $variant->tenant_id,
                    companyId: $variant->company_id,
                    productId: $variant->product_id,
                    variantCode: $variant->variant_code,
                    sku: $variant->sku,
                    isDefault: $variant->is_default,
                    createdAt: now()->toIso8601String(),
                ));
            });

            return $variant;
        });
    }

    /**
     * Make the given variant the default for its product, clearing any previous default
     * in the same atomic transaction.
     *
     * @throws MissingVariantException when the variant does not exist.
     * @throws VariantRequiredException when there are no variants at all for the product.
     */
    public function setDefault(string $variantId): ProductVariant
    {
        return DB::transaction(function () use ($variantId): ProductVariant {
            $variant = $this->variantRepo->findById($variantId);

            if ($variant === null) {
                throw MissingVariantException::withId($variantId);
            }

            $existing = $this->variantRepo->listForProduct($variant->product_id, onlyActive: false);

            if ($existing->isEmpty()) {
                throw VariantRequiredException::forProduct($variant->product_id);
            }

            // Clear the previous default for this product (the DB partial-unique constraint
            // prevents two concurrent defaults, but we must clear the flag explicitly before
            // setting the new one).
            ProductVariant::where('product_id', $variant->product_id)
                ->where('is_default', true)
                ->where('id', '!=', $variantId)
                ->update(['is_default' => false]);

            $variant->is_default = true;
            $this->variantRepo->save($variant);

            return $variant;
        });
    }

    /**
     * Resolve a variant by barcode within a company scope, or return null.
     */
    public function resolveBarcode(string $barcode, string $companyId): ?ProductVariant
    {
        return $this->variantRepo->findByBarcode($barcode, $companyId);
    }

    /**
     * Resolve a variant by SKU within a company scope, or return null.
     */
    public function resolveSku(string $sku, string $companyId): ?ProductVariant
    {
        return $this->variantRepo->findBySku($sku, $companyId);
    }

    /**
     * Generate and persist the variant matrix for a product from a value-subset
     * selection, idempotently and with restore-on-regenerate.
     *
     * Each axis specifies an attribute and the SUBSET of its value IDs to include
     * (not "all values" — that is the legacy behaviour). The Cartesian product of
     * the selected values is computed; for each combination one of three things
     * happens, keyed by the combination's set of (attribute_id => attribute_value_id)
     * pairs (the junction rows), which is rename-stable because it uses IDs:
     *   - an ACTIVE variant already exists for the combo  → skipped (counted)
     *   - a TRASHED variant exists for the combo          → restored
     *   - no variant exists for the combo                 → created
     *
     * The whole loop runs in a single outer transaction. Under PostgreSQL the
     * nested DB::transaction inside createVariant becomes a savepoint, so nesting
     * is safe and any mid-loop failure rolls everything back atomically.
     *
     * variant_code / SKU use machine-readable value CODEs (uppercased, spaces→'_');
     * name_suffix uses human-readable value LABELs (e.g. "Taille 39 / Noir").
     *
     * is_default rule: the first combo this run becomes default ONLY when the
     * product has no existing (active, non-trashed) default and no default has
     * been assigned yet during this run. Restores never change the default.
     *
     * @param  array<int, array{attributeId: string, valueIds: string[]}>  $axes
     * @return array{created: Collection<int, ProductVariant>, restored: Collection<int, ProductVariant>, skipped_count: int}
     *
     * @throws RuntimeException when the product or an attribute does not exist.
     * @throws MatrixGenerationLimitException when the gross matrix exceeds the cap.
     */
    public function generateMatrix(string $productId, array $axes): array
    {
        /** @var Product|null $product */
        $product = Product::find($productId);

        if ($product === null) {
            throw new RuntimeException("Product [{$productId}] not found.");
        }

        // Build code-space axes (['attrCode' => ['valCode', …]]) AND an ID lookup
        // (['attrCode']['valCode'] => {attributeId, attributeValueId, label}).
        // Values are loaded by ID and filtered to the selected valueIds so the
        // current codes/labels are used (no stale-rename hazard).

        /** @var array<string, string[]> $codeAxes */
        $codeAxes = [];

        /**
         * @var array<string, array<string, array{attributeId: string, attributeValueId: string, label: string}>> $lookup
         */
        $lookup = [];

        foreach ($axes as $axis) {
            $attribute = $this->attributeRepo->findById($axis['attributeId']);

            if ($attribute === null) {
                throw new RuntimeException("Attribute [{$axis['attributeId']}] not found.");
            }

            $values = $this->attributeValueRepo
                ->listForAttribute($attribute->id)
                ->whereIn('id', $axis['valueIds']);

            /** @var array<string, array{attributeId: string, attributeValueId: string, label: string}> $axisLookup */
            $axisLookup = [];

            /** @var array<int, string> $axisCodes */
            $axisCodes = [];

            /** @var ProductAttributeValue $value */
            foreach ($values as $value) {
                $axisCodes[] = $value->code;
                $axisLookup[$value->code] = [
                    'attributeId' => $attribute->id,
                    'attributeValueId' => $value->id,
                    'label' => $value->label,
                ];
            }

            $codeAxes[$attribute->code] = $axisCodes;
            $lookup[$attribute->code] = $axisLookup;
        }

        // Cap guard — fail before any write so the matrix is never partially applied.
        // This MUST stay a pre-write check (do not move it inside the transaction).
        $gross = (int) array_product(array_map('count', $codeAxes));
        if ($gross > VariantMatrixLimit::MAX_VARIANTS_PER_GENERATE) {
            throw MatrixGenerationLimitException::exceeded($gross, VariantMatrixLimit::MAX_VARIANTS_PER_GENERATE);
        }

        // We deliberately do NOT pass an $excluded list to cartesian(): each combo
        // needs a 3-way decision (skip active / restore trashed / create missing)
        // which an exclude-list cannot express.
        $combos = $this->matrixGenerator->cartesian($codeAxes);

        return DB::transaction(function () use (
            $combos,
            $product,
            $productId,
            $lookup
        ): array {
            // Serialize concurrent generate calls for the same product: take a row
            // lock on the product FIRST so the read-then-write below is race-safe.
            // Without this, two concurrent generates can both see a combo as missing
            // (→ one inserts, the other hits an uncaught variant_code unique violation)
            // or both compute is_default=true for an empty product. Mirrors the
            // per-row lockForUpdate already used on the restore path.
            Product::query()->whereKey($productId)->lockForUpdate()->first();

            // Load every existing variant (incl. trashed) WITH its junction rows so we
            // can decide skip/restore/create per combo, keyed by the ID-based combo key.
            // Read inside the lock so the snapshot reflects any concurrent generate
            // that committed before we acquired the lock.
            /** @var Collection<int, ProductVariant> $existing */
            $existing = ProductVariant::withTrashed()
                ->where('product_id', $productId)
                ->with('attributeValues')
                ->get();

            /** @var array<string, ProductVariant> $byComboKey */
            $byComboKey = [];
            foreach ($existing as $variant) {
                $byComboKey[$this->comboKeyFromJunction($variant)] = $variant;
            }

            $hasExistingDefault = $existing
                ->whereNull('deleted_at')
                ->contains('is_default', true);

            $created = collect();
            $restored = collect();
            $skipped = 0;
            $defaultAssigned = false;

            foreach ($combos as $combo) {
                // Translate the code-space combo into ID pairs and an ID-based key.
                $idPairs = [];
                foreach ($combo as $axisCode => $valueCode) {
                    $entry = $lookup[$axisCode][$valueCode];
                    $idPairs[$entry['attributeId']] = $entry['attributeValueId'];
                }
                $key = $this->comboKey($idPairs);

                if (isset($byComboKey[$key])) {
                    $match = $byComboKey[$key];

                    if ($match->deleted_at === null) {
                        $skipped++;

                        continue;
                    }

                    // Restore the trashed combo under a row lock so a concurrent
                    // restore cannot race us; map unique violations to 422 errors.
                    $locked = ProductVariant::withTrashed()->where('id', $match->id)->lockForUpdate()->first();

                    if ($locked === null) {
                        throw new RuntimeException("Variant [{$match->id}] disappeared during restore.");
                    }

                    try {
                        $locked->restore();
                    } catch (QueryException $e) {
                        if (DuplicateBarcodeException::isViolationOf($e, 'product_variants_tenant_barcode_unique')) {
                            throw DuplicateBarcodeException::asValidation('Cannot restore variant: its barcode is now used by another variant.');
                        }
                        if (DuplicateBarcodeException::isViolationOf($e, 'product_variants_tenant_sku_unique')) {
                            throw ValidationException::withMessages(['sku' => ['Cannot restore variant: its SKU is now used by another variant.']]);
                        }
                        throw $e;
                    }
                    $locked->load('attributeValues');
                    $restored->push($locked);

                    continue;
                }

                // Missing combo → create it. The first created combo this run becomes
                // default only when the product has no existing default and none has
                // been assigned yet this run.
                $isDefault = ! $hasExistingDefault && ! $defaultAssigned;
                if ($isDefault) {
                    $defaultAssigned = true;
                }

                $command = $this->commandFor($product, $combo, $lookup, $isDefault);
                $created->push($this->createVariant($command));
            }

            return [
                'created' => $created,
                'restored' => $restored,
                'skipped_count' => $skipped,
            ];
        });
    }

    /**
     * Build a CreateVariantCommand for a single code-space combo.
     *
     * @param  array<string, string>  $combo  attribute_code => value_code
     * @param  array<string, array<string, array{attributeId: string, attributeValueId: string, label: string}>>  $lookup
     */
    private function commandFor(Product $product, array $combo, array $lookup, bool $isDefault): CreateVariantCommand
    {
        // variant_code / SKU: machine-readable value CODEs (uppercased, spaces → '_').
        $codeParts = array_values($combo);
        $suffix = implode('-', array_map(
            fn (string $part): string => strtoupper(str_replace(' ', '_', $part)),
            $codeParts
        ));
        $variantCode = strtoupper($product->sku).'-'.$suffix;

        // name_suffix: human-readable value LABELs (e.g. "Taille 39 / Noir").
        $labelParts = [];
        foreach ($combo as $axisCode => $valueCode) {
            $labelParts[] = $lookup[$axisCode][$valueCode]['label'];
        }
        $nameSuffix = implode(' / ', $labelParts);

        /** @var array<int, array{attributeId: string, attributeValueId: string}> $attributeValues */
        $attributeValues = [];
        foreach ($combo as $axisCode => $valueCode) {
            $attributeValues[] = [
                'attributeId' => $lookup[$axisCode][$valueCode]['attributeId'],
                'attributeValueId' => $lookup[$axisCode][$valueCode]['attributeValueId'],
            ];
        }

        return new CreateVariantCommand(
            tenantId: $product->tenant_id,
            companyId: $product->company_id,
            productId: $product->id,
            variantCode: $variantCode,
            sku: $variantCode,
            nameSuffix: $nameSuffix,
            isDefault: $isDefault,
            attributeValues: $attributeValues,
        );
    }

    /**
     * Order-independent key for a set of (attribute_id => attribute_value_id) pairs.
     *
     * @param  array<string, string>  $attributeIdToValueId
     */
    private function comboKey(array $attributeIdToValueId): string
    {
        ksort($attributeIdToValueId);

        return implode('|', array_map(
            fn (string $a, string $v): string => "$a:$v",
            array_keys($attributeIdToValueId),
            $attributeIdToValueId
        ));
    }

    /**
     * Derive the combo key from a variant's persisted junction rows.
     */
    private function comboKeyFromJunction(ProductVariant $variant): string
    {
        $pairs = [];
        foreach ($variant->attributeValues as $row) {
            $pairs[$row->attribute_id] = $row->attribute_value_id;
        }

        return $this->comboKey($pairs);
    }
}
