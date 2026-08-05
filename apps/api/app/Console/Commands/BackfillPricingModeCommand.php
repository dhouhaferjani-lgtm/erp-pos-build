<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Product\Application\Services\MarginService;
use App\Modules\Product\Domain\Enums\PricingMode;
use App\Modules\Product\Domain\Product;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Illuminate\Console\Command;

/**
 * Idempotent backfill: promotes Manual-priced products to Auto when their
 * current sale_price provably equals the margin-formula result for their
 * effective target margin (company → category chain → product override).
 *
 * Safe to re-run: already-Auto rows are never queried.
 * Eligibility: cost_price > 0 AND sale_price == computeAutoPrice() at money scale.
 *
 * @cross-tenant-by-design NOT cross-tenant in practice: `products` is a TENANT table, so post-2026-05-28
 *   (database-per-tenant) this command mutates ONLY the tenant database bound around it. There is no scheduled
 *   caller and no console loop — the 2026-08-05 cat-(b) re-sweep found the "handled by the caller" claim had no
 *   live invocation behind it. Invoke as `php artisan tenants:run …`; a bare run raises 42P01 on CENTRAL.
 */
class BackfillPricingModeCommand extends Command
{
    protected $signature = 'products:backfill-pricing-mode';

    protected $description = 'Flip proven-auto-priced products from manual to auto (idempotent)';

    public function __construct(
        private readonly MarginService $marginService,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $flipped = 0;
        $skipped = 0;
        $flippedIds = [];

        Product::query()
            ->where('pricing_mode', PricingMode::Manual)
            ->with(['company', 'category'])
            ->chunkById(200, function ($chunk) use (&$flipped, &$skipped, &$flippedIds): void {
                foreach ($chunk as $product) {
                    $currency = $product->company->currency;
                    $moneyScale = $this->scaleResolver->getScaleSafe($currency, 3);

                    $expectedPrice = $this->marginService->computeAutoPrice($product);

                    if ($expectedPrice === null) {
                        // cost_price <= 0 — cannot determine auto price
                        $skipped++;

                        continue;
                    }

                    $salePrice = (string) $product->sale_price;
                    $currentPrice = is_numeric($salePrice) ? $salePrice : '0';

                    if (bccomp($expectedPrice, $currentPrice, $moneyScale) === 0) {
                        $product->pricing_mode = PricingMode::Auto;
                        $product->save();
                        $flipped++;

                        if (count($flippedIds) < 10) {
                            $flippedIds[] = $product->id;
                        }
                    } else {
                        $skipped++;
                    }
                }
            });

        $this->info("Backfill complete: {$flipped} flipped to auto, {$skipped} left as manual.");

        if ($flippedIds !== []) {
            $sample = implode(', ', $flippedIds);
            $this->info("Sample flipped product IDs: {$sample}");
        }

        return self::SUCCESS;
    }
}
