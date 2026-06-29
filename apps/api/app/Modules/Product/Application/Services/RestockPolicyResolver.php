<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\Services;

use App\Modules\Product\Application\DTOs\EffectiveRestockPolicy;
use App\Modules\Product\Domain\Category;
use App\Modules\Product\Domain\Enums\RestockPolicy;
use App\Modules\Product\Domain\Enums\RestockPolicySource;
use App\Modules\Product\Domain\Product;

final class RestockPolicyResolver
{
    private const FALLBACK = RestockPolicy::DefaultAllow;

    public function resolve(string $productId): EffectiveRestockPolicy
    {
        /** @var Product|null $product */
        $product = Product::query()->with('category')->find($productId);

        if ($product?->restock_policy instanceof RestockPolicy) {
            return new EffectiveRestockPolicy($product->restock_policy, RestockPolicySource::Product, null);
        }

        foreach ($this->categoryChain($product) as $category) {
            if ($category->restock_policy instanceof RestockPolicy) {
                return new EffectiveRestockPolicy($category->restock_policy, RestockPolicySource::Category, $category->id);
            }
        }

        $companyDefault = $product?->company?->reservation_settings['default_restock_policy'] ?? null;
        if (is_string($companyDefault) && ($policy = RestockPolicy::tryFrom($companyDefault)) !== null) {
            return new EffectiveRestockPolicy($policy, RestockPolicySource::Company, null);
        }

        return new EffectiveRestockPolicy(self::FALLBACK, RestockPolicySource::DefaultFallback, null);
    }

    /** @return list<Category> nearest-first (leaf → root) */
    private function categoryChain(?Product $product): array
    {
        $category = $product?->category;
        if ($category === null) {
            return [];
        }

        $chain = [$category];
        $cursor = $category;
        // Walk parent_id to the root (self-referencing tree).
        while (($cursor = $cursor->parent) !== null) {
            $chain[] = $cursor;
        }

        return $chain;
    }
}
