<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_plain_create_defaults_currency_from_owning_company(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        // A plain create() (not the factory, which sets its own currency) exercises the
        // model-boot creating hook: currency is port-managed / not fillable, so it starts
        // null and is defaulted from the OWNING company's currency.
        $repo = PaymentRepository::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CUR-DEFAULT',
            'name' => 'Currency default repo',
            'type' => RepositoryType::CashRegister,
        ]);

        $this->assertSame($company->currency, $repo->fresh()?->currency);
    }

    public function test_plain_create_with_unresolvable_company_fails_loudly(): void
    {
        $tenant = Tenant::factory()->create();

        // No TND fallback: an unresolvable company must throw rather than silently mint a
        // wrong-currency repository that would corrupt every downstream movement.
        $this->expectException(\DomainException::class);

        PaymentRepository::create([
            'tenant_id' => $tenant->id,
            'company_id' => (string) Str::uuid(),
            'code' => 'NO-COMPANY',
            'name' => 'Orphan repo',
            'type' => RepositoryType::CashRegister,
        ]);
    }
}
