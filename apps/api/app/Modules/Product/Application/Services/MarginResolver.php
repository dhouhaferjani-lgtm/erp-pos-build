<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Application\DTOs\EffectiveMargins;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Enums\MarginSource;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Support\Collection;

/**
 * Resolves effective target/minimum margins for a product via a 3-level hierarchy:
 *
 *   1. Product-level override (highest priority)
 *   2. Category chain — product's own category first, then ancestors nearest-first (root last)
 *   3. Company default
 *   4. Hard-coded fallback (30 % target / 15 % minimum)
 *
 * Each field (target, minimum) is resolved independently.
 * After resolution the minimum is clamped to ≤ target; when clamped, `minimum_clamped` is true.
 */
final class MarginResolver
{
    private const FALLBACK_TARGET = '30';

    private const FALLBACK_MINIMUM = '15';

    private const MARGIN_SCALE = 2;

    /**
     * Resolve effective margins for a single product.
     */
    public function resolve(Product $product): EffectiveMargins
    {
        return $this->buildFrom($product, $this->categoryChain($product));
    }

    /**
     * Resolve effective margins for many products with bounded DB queries (≤ 3).
     *
     * Eager-loads company + category (2 queries max) then fetches all referenced
     * ancestor category rows in a single whereIn query.
     *
     * @param  Collection<int, Product>  $products
     * @return array<string, EffectiveMargins>  keyed by product id
     */
    public function resolveMany(Collection $products): array
    {
        // Eager-load company + category so buildFrom() never lazy-loads per row.
        $products->loadMissing(['company', 'category']);

        // Collect every category id referenced in all product paths (including self)
        // then fetch them all in ONE query — no per-product round-trips.
        $ancestorIds = $products
            ->map(fn (Product $p) => $p->category)
            ->filter()
            ->flatMap(fn (Category $c) => explode('/', $c->path))
            ->filter(fn (string $id) => $id !== '')
            ->unique()
            ->values()
            ->all();

        /** @var Collection<int, Category> $byId */
        $byId = $ancestorIds
            ? Category::query()->whereIn('id', $ancestorIds)->get()->keyBy('id')
            : collect();

        $out = [];
        foreach ($products as $product) {
            $out[$product->id] = $this->buildFromCache($product, $byId);
        }

        return $out;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Core resolution logic: resolve each field independently from $chain, then clamp.
     *
     * @param  list<Category>  $chain  Nearest-first (own category at index 0, root last)
     */
    private function buildFrom(Product $product, array $chain): EffectiveMargins
    {
        $company = $product->company;

        // Cast company defaults to string because Company has no decimal cast on these columns
        // and SQLite may return them as int/float depending on the stored value.
        $targetDefault = $company !== null ? (string) $company->default_target_margin : null;
        $minimumDefault = $company !== null ? (string) $company->default_minimum_margin : null;

        [$target, $tSource, $tCatId] = $this->resolveField(
            $product->target_margin_override,
            $chain,
            'target_margin_override',
            $targetDefault,
            self::FALLBACK_TARGET,
        );

        [$minimum, $mSource, $mCatId] = $this->resolveField(
            $product->minimum_margin_override,
            $chain,
            'minimum_margin_override',
            $minimumDefault,
            self::FALLBACK_MINIMUM,
        );

        $clamped = false;
        if (bccomp($minimum, $target, self::MARGIN_SCALE) > 0) {
            $minimum = $target;
            $clamped = true;
        }

        return new EffectiveMargins(
            CurrencyScale::bcformat($target, self::MARGIN_SCALE),
            CurrencyScale::bcformat($minimum, self::MARGIN_SCALE),
            $tSource,
            $tCatId,
            $mSource,
            $mCatId,
            $clamped,
        );
    }

    /**
     * Variant of buildFrom() that uses an already-fetched category map instead of
     * issuing new queries — used by resolveMany() for the batch path.
     *
     * @param  Collection<int, Category>  $byId  All relevant categories keyed by id
     */
    private function buildFromCache(Product $product, Collection $byId): EffectiveMargins
    {
        $chain = [];

        if ($product->category !== null) {
            // path is "root_id/.../parent_id/self_id" — reverse for nearest-first
            foreach (array_reverse(explode('/', $product->category->path)) as $id) {
                if ($id !== '' && $byId->has((int) $id)) {
                    $chain[] = $byId->get((int) $id);
                }
            }
        }

        return $this->buildFrom($product, $chain);
    }

    /**
     * Build the nearest-first category chain for a product (live-query path).
     *
     * Returns [own category, nearest ancestor, …, root].
     *
     * @return list<Category>
     */
    private function categoryChain(Product $product): array
    {
        $category = $product->category;

        if ($category === null) {
            return [];
        }

        // getAncestors() returns root → … → parent (excludes self); reverse for nearest-first.
        $ancestorsNearestFirst = $category->getAncestors()->reverse()->values()->all();

        return array_merge([$category], $ancestorsNearestFirst);
    }

    /**
     * Resolve a single margin field (target or minimum) through the priority chain.
     *
     * @param  list<Category>  $chain
     * @return array{0: string, 1: MarginSource, 2: ?int}
     */
    private function resolveField(
        ?string $productOverride,
        array $chain,
        string $column,
        ?string $companyDefault,
        string $fallback,
    ): array {
        if ($productOverride !== null) {
            return [(string) $productOverride, MarginSource::Product, null];
        }

        foreach ($chain as $category) {
            if ($category->{$column} !== null) {
                return [(string) $category->{$column}, MarginSource::Category, $category->id];
            }
        }

        if ($companyDefault !== null) {
            return [(string) $companyDefault, MarginSource::Company, null];
        }

        return [$fallback, MarginSource::DefaultFallback, null];
    }
}
