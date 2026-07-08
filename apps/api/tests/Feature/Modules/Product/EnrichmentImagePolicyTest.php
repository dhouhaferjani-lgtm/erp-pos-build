<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Product;

use App\Modules\Media\Domain\Enums\MediaRole;
use App\Modules\Product\Application\Services\EnrichmentImagePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EnrichmentImagePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_when_no_existing_primary_and_run_has_none(): void
    {
        $policy = app(EnrichmentImagePolicy::class);
        $productId = (string) Str::uuid();
        $tenantId = (string) Str::uuid();

        $this->assertSame(MediaRole::Primary, $policy->roleFor($productId, $tenantId, false));
    }

    public function test_gallery_when_run_already_assigned_primary(): void
    {
        $policy = app(EnrichmentImagePolicy::class);
        $productId = (string) Str::uuid();
        $tenantId = (string) Str::uuid();

        $this->assertSame(MediaRole::Gallery, $policy->roleFor($productId, $tenantId, true));
    }
}
