<?php

declare(strict_types=1);

namespace Tests\Feature\Product;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Product\Domain\ParapharmacyProductMetadata;
use App\Modules\Product\Domain\Product;
use App\Modules\Product\Domain\Routine;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoutineTest extends TestCase
{
    use RefreshDatabase;

    public function test_routines_relation_returns_pivot_ordered_by_step_order(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Parapharmacy Routines',
            'slug' => 'test-parapharmacy-routines',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Parapharmacy,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX456',
            'country_code' => 'FR',
            'locale' => 'fr_FR',
            'timezone' => 'Europe/Paris',
            'currency' => 'EUR',
            'status' => CompanyStatus::Active,
        ]);

        $product = Product::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
        ]);

        $metadata = ParapharmacyProductMetadata::factory()->create([
            'product_id' => $product->id,
        ]);

        $routine1 = Routine::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'name' => 'Morning Routine',
        ]);

        $routine2 = Routine::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'name' => 'Midday Routine',
        ]);

        $routine3 = Routine::create([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenant->id,
            'name' => 'Night Routine',
        ]);

        // Insert out-of-order to verify sort works
        DB::table('product_routine')->insert([
            [
                'id' => Str::uuid()->toString(),
                'routine_id' => $routine1->id,
                'product_id' => $product->id,
                'step_order' => 30,
                'step_label' => 'Third step',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid()->toString(),
                'routine_id' => $routine2->id,
                'product_id' => $product->id,
                'step_order' => 10,
                'step_label' => 'First step',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => Str::uuid()->toString(),
                'routine_id' => $routine3->id,
                'product_id' => $product->id,
                'step_order' => 20,
                'step_label' => 'Second step',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $routines = $metadata->routines()->get();

        $this->assertCount(3, $routines);

        // Assert ordering by step_order ASC
        $first = $routines->get(0);
        $second = $routines->get(1);
        $third = $routines->get(2);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotNull($third);

        $this->assertSame(10, $first->pivot->step_order);
        $this->assertSame(20, $second->pivot->step_order);
        $this->assertSame(30, $third->pivot->step_order);

        // Assert pivot data is accessible
        $this->assertSame('First step', $first->pivot->step_label);
        $this->assertInstanceOf(Routine::class, $first);
    }
}
