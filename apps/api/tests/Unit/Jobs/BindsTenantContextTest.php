<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\Concerns\BindsTenantContext;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * M7 (2026-08-05 cat-(b) wave-1 adversarial review).
 *
 * `tenants.id` is a PostgreSQL `uuid` column, so `Tenant::find($this->tenantId)`
 * with a malformed anchor raised a `QueryException` (22P02) rather than
 * returning null. A `QueryException` is a transient-looking fault, so the queue
 * worker retried the job its full budget against a value that can never become
 * valid — a retry storm instead of one clean discard. Only a buggy or
 * compromised producer can reach it (every dispatch path is signature-gated or
 * internal), which is precisely why it must fail deterministically and say so.
 */
final class BindsTenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_malformed_tenant_anchor_fails_deterministically_not_as_a_query_fault(): void
    {
        $job = new BindsTenantContextProbeJob('not-a-uuid');

        try {
            $job->run(static fn (): string => 'ran');
            $this->fail('A malformed anchor must not silently bind anything.');
        } catch (QueryException $e) {
            $this->fail('A malformed anchor must not reach the uuid-typed primary key: '.$e->getMessage());
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('not a UUID', $e->getMessage());
            $this->assertStringContainsString('retrying cannot help', $e->getMessage());
        }
    }

    public function test_a_missing_tenant_still_fails_loudly_with_its_own_message(): void
    {
        $job = new BindsTenantContextProbeJob('11111111-1111-4111-8111-111111111111');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not found — cannot rebind queue worker context');

        $job->run(static fn (): string => 'ran');
    }

    public function test_a_resolvable_tenant_runs_the_closure(): void
    {
        $tenant = Tenant::create([
            'name' => 'Anchor Tenant',
            'slug' => 'anchor-tenant',
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $this->assertSame('ran', (new BindsTenantContextProbeJob($tenant->id))->run(static fn (): string => 'ran'));
    }
}

final class BindsTenantContextProbeJob
{
    use BindsTenantContext;

    public function __construct(public ?string $tenantId) {}

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public function run(callable $fn): mixed
    {
        return $this->withTenantContext($fn);
    }
}
