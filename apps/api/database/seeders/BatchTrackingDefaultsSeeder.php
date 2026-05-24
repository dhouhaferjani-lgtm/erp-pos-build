<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Vertical;
use App\Modules\Product\Domain\Product;
use App\Modules\Tenant\Domain\Tenant;
use App\Services\VerticalConfigService;
use Illuminate\Database\Seeder;

final class BatchTrackingDefaultsSeeder extends Seeder
{
    public function __construct(
        private readonly VerticalConfigService $verticalConfigService,
    ) {}

    public function run(): void
    {
        $verticalsRequiringBatchTracking = collect(Vertical::cases())
            ->filter(fn (Vertical $vertical): bool => $this->verticalConfigService
                ->getProductDefaults($vertical)['requires_batch_tracking'])
            ->map(fn (Vertical $vertical): string => $vertical->value)
            ->all();

        if ($verticalsRequiringBatchTracking === []) {
            return;
        }

        $tenantIds = Tenant::query()
            ->whereIn('vertical', $verticalsRequiringBatchTracking)
            ->pluck('id')
            ->all();

        if ($tenantIds === []) {
            return;
        }

        Product::query()
            ->whereIn('tenant_id', $tenantIds)
            ->where('is_physical', true)
            ->where('requires_batch_tracking', false)
            ->update(['requires_batch_tracking' => true]);
    }
}
