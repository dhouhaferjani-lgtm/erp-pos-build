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
        // Factory default type is CashRegister; W-5b Option B's type-derived
        // default keeps allow_negative false for it.
        $this->assertFalse($repo->fresh()->allow_negative);
    }

    public function test_plain_create_defaults_allow_negative_true_for_bank_account(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        // allow_negative is fillable but omitted here — exercises the model
        // boot creating() hook's type-derived default (W-5b Option B): a
        // bank_account may run an authorised overdraft.
        $repo = PaymentRepository::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'NEG-BANK',
            'name' => 'Bank overdraft repo',
            'type' => RepositoryType::BankAccount,
        ]);

        $this->assertTrue($repo->fresh()?->allow_negative);
    }

    public function test_plain_create_defaults_allow_negative_false_for_non_bank_types(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        foreach ([RepositoryType::CashRegister, RepositoryType::Safe, RepositoryType::Virtual] as $type) {
            $repo = PaymentRepository::create([
                'tenant_id' => $tenant->id,
                'company_id' => $company->id,
                'code' => 'NEG-'.strtoupper($type->value),
                'name' => "Till repo ({$type->value})",
                'type' => $type,
            ]);

            $this->assertFalse($repo->fresh()?->allow_negative, "type {$type->value} must default allow_negative = false");
        }
    }

    public function test_explicit_allow_negative_is_never_overridden_by_the_type_default(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->tunisia()->create(['tenant_id' => $tenant->id]);

        // A caller explicitly opting a cash_register INTO negative-balance
        // tolerance (or a bank_account OUT of it) must have that choice
        // respected — the type-derived default only fills the gap when the
        // caller hasn't decided.
        $repoOverriddenTrue = PaymentRepository::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'NEG-OVERRIDE-TRUE',
            'name' => 'Explicitly negative-tolerant till',
            'type' => RepositoryType::CashRegister,
            'allow_negative' => true,
        ]);
        $this->assertTrue($repoOverriddenTrue->fresh()?->allow_negative);

        $repoOverriddenFalse = PaymentRepository::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'NEG-OVERRIDE-FALSE',
            'name' => 'Explicitly blocked bank account',
            'type' => RepositoryType::BankAccount,
            'allow_negative' => false,
        ]);
        $this->assertFalse($repoOverriddenFalse->fresh()?->allow_negative);
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
