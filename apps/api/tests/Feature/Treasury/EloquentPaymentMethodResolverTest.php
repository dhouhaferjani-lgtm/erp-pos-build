<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\PaymentMethod;
use App\Modules\Treasury\Infrastructure\EloquentPaymentMethodResolver;
use App\Shared\Contracts\Fiscal\PaymentMethodResolver;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `EloquentPaymentMethodResolver` — Pass 2A.PHP.2 R3 (synthesis v5 §8.B +
 * dispatch §0 Gap A; Codex R2 BLOCKER-3 / N-07 closure).
 *
 * The interface docblock at `App\Shared\Contracts\Fiscal\PaymentMethodResolver`
 * locks the null contract: `null` ONLY means "no `payment_methods` row exists
 * in the given tenant/company with the given code." Transient infrastructure failures
 * (DB timeout, deadlock, connection loss, malformed query) MUST propagate as
 * exceptions so the caller's wrapping transaction rolls back and Horizon
 * retries via the Task 23 R2 `catch (Throwable)` contract on
 * `ApplyFiscalEventProjectionJob`.
 *
 * R2 wrapped the Eloquent call in `catch (QueryException) { return null; }`
 * — directly contradicting the very docblock it added. R3 removed the catch;
 * this test pins the propagation contract end-to-end so any future regression
 * that re-introduces the swallow surfaces immediately.
 */
final class EloquentPaymentMethodResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolver_returns_matched_uuid_for_known_code_in_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $method = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'code' => 'CASH',
            'name' => 'Cash',
        ]);

        $resolver = $this->app->make(PaymentMethodResolver::class);

        $this->assertInstanceOf(EloquentPaymentMethodResolver::class, $resolver);
        $this->assertSame($method->id, $resolver->resolveByCode($tenant->id, $company->id, 'CASH'));
    }

    public function test_resolver_selects_the_method_for_the_requested_company_when_codes_repeat_in_a_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignCompany = Company::factory()->create(['tenant_id' => $tenant->id]);
        $requestedCompany = Company::factory()->create(['tenant_id' => $tenant->id]);

        PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $foreignCompany->id,
            'code' => 'CARD',
            'name' => 'Foreign company card',
        ]);
        $requestedMethod = PaymentMethod::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $requestedCompany->id,
            'code' => 'CARD',
            'name' => 'Requested company card',
        ]);

        $resolver = $this->app->make(PaymentMethodResolver::class);

        $this->assertSame(
            $requestedMethod->id,
            $resolver->resolveByCode($tenant->id, $requestedCompany->id, 'CARD'),
        );
    }

    public function test_resolver_returns_null_for_code_absent_in_tenant(): void
    {
        // Canonical null case: NO row exists for (tenant_id, company_id, code). This is
        // the ONLY case in which the resolver is permitted to return null —
        // the caller treats null as a fail-closed "method not found in tenant"
        // configuration error and rolls back the projection transaction.
        $tenant = Tenant::factory()->create();

        $resolver = $this->app->make(PaymentMethodResolver::class);

        $company = Company::factory()->create(['tenant_id' => $tenant->id]);

        $this->assertNull($resolver->resolveByCode($tenant->id, $company->id, 'CODE_THAT_NEVER_EXISTED'));
    }

    public function test_resolver_returns_null_for_cross_tenant_code(): void
    {
        // Cross-tenant null case: the code exists, but in a FOREIGN tenant.
        // The company-scoped uniqueness partitions by tenant and company —
        // the resolver MUST NOT bind a foreign row into the requesting
        // tenant's projection. This is the security stance Task 21 R2 Opus
        // F3 pinned for payment_method_id, now re-expressed via the
        // Shared/Contracts seam in Pass 2A.PHP.2.
        $foreignTenant = Tenant::factory()->create();
        $foreignCompany = Company::factory()->create(['tenant_id' => $foreignTenant->id]);
        PaymentMethod::factory()->create([
            'tenant_id' => $foreignTenant->id,
            'company_id' => $foreignCompany->id,
            'code' => 'CASH_FX_X',
            'name' => 'Foreign Cash',
        ]);

        $localTenant = Tenant::factory()->create();
        $localCompany = Company::factory()->create(['tenant_id' => $localTenant->id]);

        $resolver = $this->app->make(PaymentMethodResolver::class);

        $this->assertNull($resolver->resolveByCode($localTenant->id, $localCompany->id, 'CASH_FX_X'));
    }

    public function test_resolver_propagates_query_exception_on_transient_db_failure(): void
    {
        // Pass 2A.PHP.2 R3 — Codex BLOCKER-3 closure (N-07). The R2
        // interface docblock locked the null contract: transient failures
        // (DB timeout, deadlock, connection loss, missing table) MUST
        // propagate as exceptions so the caller's wrapping transaction
        // rolls back atomically and the projection job retries via the
        // Task 23 R2 catch-Throwable contract. R2 silently swallowed
        // `QueryException` and returned null — that mislabelled
        // infrastructure failures as "method not found" and broke the
        // retry/forensic chain. R3 removed the swallow; this test pins
        // the propagation behaviour.
        //
        // We provoke a real QueryException by dropping the underlying
        // `payment_methods` table — the next query against it surfaces a
        // driver-level error. RefreshDatabase rebuilds the schema after
        // the test, so no other test is affected.
        $tenant = Tenant::factory()->create();

        // Confirm baseline: known-absent code returns null cleanly when the
        // table exists.
        $resolver = $this->app->make(PaymentMethodResolver::class);
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $this->assertNull($resolver->resolveByCode($tenant->id, $company->id, 'NONE'));

        // Now drop the table — the schema is rebuilt at teardown via
        // RefreshDatabase, so this does not leak into sibling tests.
        //
        // Driver-gated: `payment_methods` has 6 dependent FKs (payment_instruments,
        // expense_metadata, pos_receipt_payments, payments, pos_z_report_counts,
        // income_metadata). On real Postgres a plain `DROP TABLE` throws `2BP01`
        // ("cannot drop table because other objects depend on it") BEFORE the
        // test's own `expectException(QueryException::class)` is registered,
        // so PHPUnit reports an unhandled error instead of exercising the
        // resolver's propagation behaviour. `CASCADE` drops the dependent FKs
        // along with the table so the *next* query (the resolver's own SELECT)
        // is what surfaces the QueryException this test is pinning. SQLite has
        // no `CASCADE` keyword on `DROP TABLE` and tolerates FK-less drops via
        // the framework helper, so keep the pre-existing `Schema::drop()` path
        // there.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TABLE payment_methods CASCADE');
        } else {
            Schema::drop('payment_methods');
        }

        $this->expectException(QueryException::class);

        // The resolver MUST propagate the QueryException. If a regression
        // re-introduces the `catch (QueryException) { return null; }`
        // swallow, this call would return null and the expectException
        // assertion would fail the test loudly.
        $resolver->resolveByCode($tenant->id, $company->id, 'CASH');
    }
}
