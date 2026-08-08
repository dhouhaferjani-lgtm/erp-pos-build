<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Enums\Vertical;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Enums\CompanyStatus;
use App\Modules\Fiscal\Domain\Models\FiscalEvent;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Application\Projections\TreasuryReceiptBridge;
use App\Modules\Treasury\Application\Services\TenderRepositoryResolver;
use App\Modules\Treasury\Domain\Enums\RepositoryType;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Domain\PaymentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * DPA lane G3, requirement 4 — drawer→repository resolution.
 *
 * No shift→repository link exists in the schema, so the G3 shift-variance
 * listener resolves the target cash repository the same way the fiscal
 * projection bridge already does: `PaymentMethod::default_repository_id` when it
 * still points at an active, GL-linked, same-tenant+company repository, else the
 * historical deterministic fallback (first GL-linked repository ordered by
 * stable UUID).
 *
 * "The two paths MUST resolve identically — pin by test" is this file's whole
 * job: every case asserts the extracted {@see TenderRepositoryResolver} and
 * `TreasuryReceiptBridge::resolveRepositoryForTender()` return the SAME
 * repository id, so a future edit to one cannot silently drift from the other.
 */
final class TenderRepositoryResolverTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company',
            'legal_name' => 'Test Company LLC',
            'tax_id' => 'TAX123',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $this->tenantId = $tenant->id;
        $this->companyId = $company->id;
    }

    public function test_mapped_repository_wins_and_both_paths_agree(): void
    {
        $fallback = $this->makeRepository('AAA', glLinked: true);
        $mapped = $this->makeRepository('ZZZ', glLinked: true);
        $method = $this->makeMethod($mapped->id);

        $this->assertSame($mapped->id, $this->resolver()->resolve($this->tenantId, $this->companyId, $method)?->id);
        $this->assertNotSame($fallback->id, $mapped->id);
        $this->assertBridgeAgrees($method);
    }

    public function test_inactive_mapped_repository_falls_back_and_both_paths_agree(): void
    {
        $fallback = $this->makeRepository('AAA', glLinked: true);
        $mapped = $this->makeRepository('ZZZ', glLinked: true, active: false);
        $method = $this->makeMethod($mapped->id);

        $this->assertSame($fallback->id, $this->resolver()->resolve($this->tenantId, $this->companyId, $method)?->id);
        $this->assertBridgeAgrees($method);
    }

    public function test_mapped_repository_without_gl_account_falls_back_and_both_paths_agree(): void
    {
        $fallback = $this->makeRepository('AAA', glLinked: true);
        $mapped = $this->makeRepository('ZZZ', glLinked: false);
        $method = $this->makeMethod($mapped->id);

        $this->assertSame($fallback->id, $this->resolver()->resolve($this->tenantId, $this->companyId, $method)?->id);
        $this->assertBridgeAgrees($method);
    }

    public function test_unmapped_method_uses_the_uuid_ordered_fallback_and_both_paths_agree(): void
    {
        $a = $this->makeRepository('AAA', glLinked: true);
        $b = $this->makeRepository('ZZZ', glLinked: true);

        $expected = strcmp($a->id, $b->id) < 0 ? $a->id : $b->id;
        $method = $this->makeMethod(null);

        $this->assertSame($expected, $this->resolver()->resolve($this->tenantId, $this->companyId, $method)?->id);
        $this->assertBridgeAgrees($method);
    }

    public function test_no_gl_linked_repository_resolves_to_null_and_both_paths_agree(): void
    {
        $this->makeRepository('AAA', glLinked: false);
        $method = $this->makeMethod(null);

        $this->assertNull($this->resolver()->resolve($this->tenantId, $this->companyId, $method));
        $this->assertBridgeAgrees($method);
    }

    public function test_a_foreign_company_repository_is_never_resolved(): void
    {
        $otherCompany = Company::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX999',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $foreign = PaymentRepository::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $otherCompany->id,
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $this->makeAccount()->id,
            'is_active' => true,
        ]);
        $method = $this->makeMethod($foreign->id);

        $this->assertNull($this->resolver()->resolve($this->tenantId, $this->companyId, $method));
        $this->assertBridgeAgrees($method);
    }

    /**
     * Gate finding M4 — the bridge's REAL null-method input (a tender the
     * canonical payload never named), which none of the cases above covered.
     */
    public function test_a_null_method_uses_the_fallback_and_both_paths_agree(): void
    {
        $expected = $this->makeRepository('AAA', glLinked: true);

        $this->assertSame($expected->id, $this->resolver()->resolve($this->tenantId, $this->companyId, null)?->id);
        $this->assertBridgeAgrees(null);
    }

    /**
     * Gate finding M4 — the foreign-scope case varied the COMPANY but never the
     * TENANT, so the tenant leg of the scope was unproven.
     */
    public function test_a_foreign_tenant_repository_is_never_resolved(): void
    {
        $otherTenant = Tenant::create([
            'name' => 'Other Tenant',
            'slug' => 'other-tenant-'.uniqid(),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
            'vertical' => Vertical::Mechanic,
        ]);

        $foreign = PaymentRepository::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $this->companyId,
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $this->makeAccount()->id,
            'is_active' => true,
        ]);
        $method = $this->makeMethod($foreign->id);

        $this->assertNull($this->resolver()->resolve($this->tenantId, $this->companyId, $method));
        $this->assertBridgeAgrees($method);
    }

    // ── resolveByMethodId — the entry point the G3 listener actually uses ─────
    // Gate finding I6: all six cases above drive resolve() with a hydrated
    // method, but the listener enters by id. These cover that seam, including
    // the divergence class the reviewer identified.

    public function test_resolve_by_method_id_matches_resolve_for_a_mapped_method(): void
    {
        $this->makeRepository('AAA', glLinked: true);
        $mapped = $this->makeRepository('ZZZ', glLinked: true);
        $method = $this->makeMethod($mapped->id);

        $this->assertSame(
            $this->resolver()->resolve($this->tenantId, $this->companyId, $method)?->id,
            $this->resolver()->resolveByMethodId($this->tenantId, $this->companyId, $method->id)?->id,
        );
        $this->assertBridgeAgrees($method);
    }

    /**
     * Gate finding I6 — a method id that is PRESENT but does not load in scope.
     *
     * The bridge treats this as fatal ("payment_method_id … resolved but not
     * loadable"); the resolver used to degrade to the null-method fallback,
     * silently routing an unknown tender's money to whichever GL-linked
     * repository sorts first by UUID. Both callers must now decline to invent a
     * destination.
     */
    public function test_resolve_by_method_id_refuses_an_id_that_does_not_load(): void
    {
        $fallback = $this->makeRepository('AAA', glLinked: true);

        $this->assertNull(
            $this->resolver()->resolveByMethodId($this->tenantId, $this->companyId, (string) Str::uuid()),
            'An unknown payment method must NOT fall back to an arbitrary repository.',
        );
        $this->assertNotNull($fallback->id);
    }

    public function test_resolve_by_method_id_refuses_a_method_from_another_company(): void
    {
        $this->makeRepository('AAA', glLinked: true);

        $otherCompany = Company::create([
            'tenant_id' => $this->tenantId,
            'name' => 'Other Company',
            'legal_name' => 'Other Company LLC',
            'tax_id' => 'TAX777',
            'country_code' => 'TN',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
            'currency' => 'TND',
            'status' => CompanyStatus::Active,
        ]);

        $foreignMethod = PaymentMethod::create([
            'tenant_id' => $this->tenantId,
            'company_id' => $otherCompany->id,
            'code' => 'CASH-'.Str::random(4),
            'name' => 'Cash',
            'is_physical' => true,
            'is_cash_tender' => true,
            'is_active' => true,
            'default_repository_id' => null,
        ]);

        $this->assertNull($this->resolver()->resolveByMethodId($this->tenantId, $this->companyId, $foreignMethod->id));
    }

    /**
     * A genuinely ABSENT id still uses the fallback — that is the bridge's own
     * behaviour for an unnamed tender, and the case must not be conflated with
     * "named but unknown".
     */
    public function test_resolve_by_method_id_with_no_id_uses_the_fallback(): void
    {
        $expected = $this->makeRepository('AAA', glLinked: true);

        $this->assertSame(
            $expected->id,
            $this->resolver()->resolveByMethodId($this->tenantId, $this->companyId, null)?->id,
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolver(): TenderRepositoryResolver
    {
        return app(TenderRepositoryResolver::class);
    }

    /**
     * Drive the bridge's own resolution seam with the same inputs and assert it
     * lands on the same repository — the anti-drift pin.
     */
    private function assertBridgeAgrees(?PaymentMethod $method): void
    {
        $event = new FiscalEvent;
        $event->tenant_id = $this->tenantId;
        $event->company_id = $this->companyId;

        $reflected = new ReflectionMethod(TreasuryReceiptBridge::class, 'resolveRepositoryForTender');
        /** @var ?PaymentRepository $viaBridge */
        $viaBridge = $reflected->invoke(app(TreasuryReceiptBridge::class), $event, $method);

        $this->assertSame(
            $this->resolver()->resolve($this->tenantId, $this->companyId, $method)?->id,
            $viaBridge?->id,
            'TreasuryReceiptBridge and TenderRepositoryResolver must resolve the same repository.',
        );
    }

    private function makeRepository(string $code, bool $glLinked, bool $active = true): PaymentRepository
    {
        return PaymentRepository::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => $code.'-'.Str::random(4),
            'currency' => 'TND',
            'type' => RepositoryType::CashRegister,
            'gl_account_id' => $glLinked ? $this->makeAccount()->id : null,
            'is_active' => $active,
        ]);
    }

    private function makeAccount(): Account
    {
        return Account::create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => (string) random_int(100000, 999999),
            'name' => 'Cash',
            'type' => 'asset',
        ]);
    }

    private function makeMethod(?string $defaultRepositoryId): PaymentMethod
    {
        return PaymentMethod::create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'code' => 'CASH-'.Str::random(4),
            'name' => 'Cash',
            'is_physical' => true,
            'is_cash_tender' => true,
            'is_active' => true,
            'default_repository_id' => $defaultRepositoryId,
        ]);
    }
}
