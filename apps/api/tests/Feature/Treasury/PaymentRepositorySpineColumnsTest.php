<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentRepositorySpineColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_repository_has_spine_columns_with_defaults(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create([
            'tenant_id' => $tenant->id,
        ]);

        $repo = PaymentRepository::factory()->for($company)->create([
            'tenant_id' => $tenant->id,
        ]);

        $this->assertSame('TND', $repo->fresh()->currency);
        $this->assertNull($repo->fresh()->frozen_at);
        $this->assertSame(0, $repo->fresh()->next_movement_ordinal);
    }
}
