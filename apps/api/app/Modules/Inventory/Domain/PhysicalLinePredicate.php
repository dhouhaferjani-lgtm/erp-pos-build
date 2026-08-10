<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Product\Domain\Product;
use Illuminate\Database\Eloquent\Builder;

/**
 * THE physical-line predicate — "does this document line move stock?" — asked in
 * exactly one place (DPA Wave 3, D-19 / T4).
 *
 * ## Why this class exists
 *
 * Before it, the codebase answered the question two incompatible ways:
 *
 *  1. `$line->product === null || ($line->product->is_service ?? false)` —
 *     `DeliveryNoteService:243`, `ReturnNoteService:647`, `SalesOrderService:118`.
 *     **`is_service` is a phantom** (plan §0.15): `grep -rn is_service database/`
 *     returns zero, it is absent from `Product::$fillable` / `$attributes` /
 *     `casts()`, and there is no accessor. All reads are `?? false`, so the guard
 *     collapsed to `product === null` — a **non-physical PRODUCT line moved
 *     stock**, and post-Wave-3 would have booked COGS for it.
 *  2. `$product->is_physical` — the REAL column (migration
 *     `2025_12_12_100000`) — `DocumentPostingService:605`,
 *     `InvoiceController:892`.
 *
 * Two answers to one question is precisely what this program's document-per-action
 * remediation exists to remove, and the exit seam cannot be built on a predicate
 * that disagrees with the gate that decides whether the invoice may post at all.
 *
 * ## The three populations
 *
 * | line shape | physical? |
 * |---|---|
 * | `product_id` set, product exists, `is_physical = true` | **yes** |
 * | `product_id` set, product exists, `is_physical = false` | no |
 * | `product_id` NULL (pure `service_id` line), or the product row is gone | no |
 *
 * `document_lines` carries `product_id` + nullable `service_id` with **no XOR
 * CHECK** (§0.15), so "no product" and "a service" are the same answer here.
 *
 * ## Two forms, one arithmetic
 *
 * `forLine()` / `physicalProductFor()` answer for a loaded row; `physical()`
 * answers for a query. They are kept in agreement by
 * `PhysicalLinePredicateTest::test_the_query_scope_selects_exactly_the_lines_the_row_predicate_accepts`.
 *
 * ## Tenant scoping
 *
 * `physicalProductFor()` accepts an optional `(tenantId, companyId)` pair. When
 * given, the product is resolved with that scope instead of through the relation
 * — preserving `DocumentPostingService`'s api.document.010 guard, which exists so
 * a forged cross-tenant `line.product_id` cannot resolve to a foreign product.
 * Call sites that never scoped keep the relation form; making them scoped is a
 * separate, deliberate change and is NOT smuggled in here.
 *
 * NOT adopted at `Document.php:926` (plan T4 risk note) — that helper answers a
 * different question and touching it widens the blast radius.
 */
final class PhysicalLinePredicate
{
    /**
     * Does this line move stock?
     */
    public static function forLine(DocumentLine $line, ?string $tenantId = null, ?string $companyId = null): bool
    {
        return self::physicalProductFor($line, $tenantId, $companyId) !== null;
    }

    /**
     * The line's product when the line moves stock, null otherwise.
     *
     * Returning the product (rather than a bare bool) is deliberate: every
     * adopting site needs the object immediately afterwards, and a separate
     * fetch is how the two predicates drifted apart in the first place.
     */
    public static function physicalProductFor(DocumentLine $line, ?string $tenantId = null, ?string $companyId = null): ?Product
    {
        if ($line->product_id === null) {
            return null;
        }

        $product = $tenantId !== null && $companyId !== null
            ? Product::query()
                ->where('tenant_id', $tenantId)
                ->where('company_id', $companyId)
                ->find($line->product_id)
            : $line->product;

        if ($product === null || ! $product->isPhysical()) {
            return null;
        }

        return $product;
    }

    /**
     * Restrict a `document_lines` query to the lines that move stock.
     *
     * @param  Builder<DocumentLine>  $query
     * @return Builder<DocumentLine>
     */
    public static function physical(Builder $query): Builder
    {
        return $query
            ->whereNotNull('product_id')
            ->whereHas('product', static function (Builder $product): void {
                /** @var Builder<Product> $product */
                $product->where('is_physical', true);
            });
    }
}
