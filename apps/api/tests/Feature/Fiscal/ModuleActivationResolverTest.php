<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Shared\Contracts\Fiscal\ModuleActivationResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 17 — `ModuleActivationResolver` interface + `DefaultModuleActivationResolver`.
 *
 * The per-`(tenant, company)` module-activation seam — spec v7 §7.3 + SoT §13.6/D16.
 *
 * The contract pins **canonical PascalCase tokens** (`'Treasury'`, never
 * `'treasury'`): `CompanyConfig::hasModule()` does an `in_array(..., true)`
 * strict-compare against `Vertical::defaultModules()` (which uses PascalCase),
 * so a lowercase token would always resolve false and the Treasury bridge
 * (Task 22) would never run. §17.3 of the spec test-locks this — those locks
 * are these tests.
 */
final class ModuleActivationResolverTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // Vertical::Retail is the simplest standard vertical — its
        // defaultModules() includes 'Treasury' but NOT 'Workshop'. That gives
        // us one assertTrue and one assertFalse without a second tenant.
        $this->tenant = Tenant::factory()->create([
            'vertical' => Vertical::Retail,
        ]);
        $this->company = Company::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);
    }

    private function resolver(): ModuleActivationResolver
    {
        return $this->app->make(ModuleActivationResolver::class);
    }

    public function test_treasury_active_for_standard_seeded_tenant(): void
    {
        // §7.3 test-lock: a standard tenant whose vertical defaults include
        // 'Treasury' must report Treasury as active. Every current
        // Vertical::defaultModules() includes 'Treasury', so this is the
        // floor of the contract.
        $this->assertTrue(
            $this->resolver()->isActive('Treasury', $this->tenant->id, $this->company->id),
            'Vertical::Retail defaults include Treasury; resolver must report active.',
        );
    }

    public function test_token_is_pascalcase_strict_compared(): void
    {
        // §7.3 P1 lock — `CompanyConfig::hasModule()` strict-compares; a
        // lowercase token must NEVER resolve true. This is the canonical
        // failure mode the Phase 1 spec v4 P1 closed.
        $this->assertFalse(
            $this->resolver()->isActive('treasury', $this->tenant->id, $this->company->id),
            'Lowercase token must fail strict-compare — Vertical::defaultModules() emits PascalCase.',
        );
    }

    public function test_module_not_in_vertical_defaults_returns_false(): void
    {
        // Retail vertical does NOT include Workshop. The resolver should
        // honor the underlying allEnabledModules surface — no fallback.
        $this->assertFalse(
            $this->resolver()->isActive('Workshop', $this->tenant->id, $this->company->id),
            'Workshop is not in Retail defaults; resolver must report inactive.',
        );
    }

    public function test_returns_false_for_missing_tenant(): void
    {
        // Defensive: an unknown tenantId must not throw; it must return
        // false so the caller (FiscalEventProjectionRegistry, Task 18)
        // simply excludes the bridge rather than crashing the ingest path.
        $unknownTenantId = Str::uuid()->toString();
        $this->assertFalse(
            $this->resolver()->isActive('Treasury', $unknownTenantId, $this->company->id),
            'Unknown tenantId must resolve to inactive, not throw.',
        );
    }

    public function test_company_id_parameter_is_accepted_in_phase_1_tenant_level_surface(): void
    {
        // §18 caveat: Phase 1 underlying surface is tenant-level cached, so
        // any well-formed companyId argument yields the same result as long
        // as the tenantId is consistent. This pins the contract shape so
        // Task 18 / Task 22 can call `isActive($module, $tenantId, $companyId)`
        // and rely on the (tenant, company) seam being real — even though
        // Phase 1 does not yet branch on companyId per-company.
        $resolver = $this->resolver();
        $otherCompanyId = Str::uuid()->toString();
        $this->assertSame(
            $resolver->isActive('Treasury', $this->tenant->id, $this->company->id),
            $resolver->isActive('Treasury', $this->tenant->id, $otherCompanyId),
            'Phase 1 resolver is tenant-level; companyId argument must not flip the result for the same tenant.',
        );
    }

    public function test_resolver_token_handed_through_without_transformation(): void
    {
        // The resolver passes the token through to `CompanyConfig::hasModule()`
        // verbatim — no case folding, no aliasing, no trimming. A randomly-
        // cased OR whitespace-padded token is rejected by the strict-compare.
        // (Opus P3-7 round-2 added the whitespace-padded case as defense
        // against a future config-file / URL-fragment-derived token wired in
        // poorly.)
        $resolver = $this->resolver();
        $this->assertFalse($resolver->isActive('TREASURY', $this->tenant->id, $this->company->id));
        $this->assertFalse($resolver->isActive('Treas', $this->tenant->id, $this->company->id));
        $this->assertFalse($resolver->isActive('', $this->tenant->id, $this->company->id));
        $this->assertFalse($resolver->isActive(' Treasury ', $this->tenant->id, $this->company->id));
        $this->assertFalse($resolver->isActive("Treasury\n", $this->tenant->id, $this->company->id));
    }

    public function test_malformed_tenant_id_does_not_throw(): void
    {
        // Opus P3-3 round-2 — the contract docblock says "missing tenant
        // resolves false, never throws". On PostgreSQL the `tenants.id`
        // column is `uuid`-typed; a malformed string like `'not-a-uuid'`
        // would otherwise surface as a `QueryException` from the driver.
        // The impl wraps `Tenant::find()` in a `try/catch (QueryException)`
        // to genuinely honor the contract.
        //
        // SQLite (local test runner) doesn't enforce uuid format, so this
        // test exercises the unknown-uuid-shape branch on SQLite and the
        // QueryException-swallow branch on PG. Either way the contract is
        // upheld.
        $this->assertFalse(
            $this->resolver()->isActive('Treasury', 'not-a-uuid', $this->company->id),
        );
    }
}
