<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use App\Modules\Company\Domain\Location;
use App\Modules\Inventory\Application\Services\ReplenishmentSuggestionService;
use App\Modules\Product\Domain\Product;
use Database\Seeders\DemoPharmacySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Tunisia parapharmacy demo must ship a deterministic, non-floor
 * replenishment suggestion so the per-unit display-precision feature is
 * demonstrable end-to-end: a pinned grain (PB-BAB-0060 @ STORE-SOU) with
 * fixed quantity/min/max yields suggested_qty '7' — whole-number formatted
 * (BabyCare is a pieces unit, decimal_places 0), not the '1' floor and not
 * the scale-4 '7.0000'.
 */
final class DemoPharmacyReplenishmentSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pinned_grain_yields_whole_number_suggestion(): void
    {
        $this->seed(DemoPharmacySeeder::class);

        $product = Product::query()
            ->where('sku', 'PB-BAB-0060')
            ->firstOrFail();

        $sousse = Location::query()
            ->where('code', 'STORE-SOU')
            ->firstOrFail();

        $service = app(ReplenishmentSuggestionService::class);

        $result = $service->suggestionsForLocation(
            $product->tenant_id,
            $product->company_id,
            $sousse->id,
            [['product_id' => $product->id, 'variant_id' => null]],
        );

        self::assertSame('7', $result[$product->id.'|']);
    }
}
