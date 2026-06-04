<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class AuditEventSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->company = Company::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);

        UserCompanyMembership::create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'role' => 'admin',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        Permission::findOrCreate('pos.audit_sync', 'sanctum');
        $this->user->givePermissionTo('pos.audit_sync');

        Sanctum::actingAs($this->user);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(array $overrides = []): array
    {
        return array_merge([
            'event_id' => (string) Str::uuid(),
            'tenant_id' => $this->tenant->id,
            'company_id' => $this->company->id,
            'operator_id' => $this->user->id,
            'event_type' => 'pos.login',
            'aggregate_type' => 'PosSession',
            'aggregate_id' => 'device-1',
            'payload' => ['multi_tenant' => true],
            'metadata' => ['device_id' => 'd1'],
            'occurred_at' => '2026-06-04T10:00:00.000000+00:00',
        ], $overrides);
    }

    public function test_sync_inserts_batch_and_preserves_client_envelope(): void
    {
        $eventA = $this->event(['aggregate_id' => 'device-A']);
        $eventB = $this->event(['aggregate_id' => 'device-B']);

        $response = $this->postJson('/api/v1/pos/audit-events/sync', [
            'events' => [$eventA, $eventB],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.created', 2);
        $response->assertJsonPath('data.duplicates', 0);

        $this->assertSame(2, AuditEvent::query()->count());

        $rowA = AuditEvent::query()->whereKey($eventA['event_id'])->firstOrFail();
        $this->assertSame($eventA['event_id'], $rowA->id);
        $this->assertTrue(Carbon::parse($eventA['occurred_at'])->equalTo($rowA->occurred_at));
        $this->assertArrayHasKey('ingested_at', $rowA->metadata);
        $this->assertArrayHasKey('client_clock_skew_ms', $rowA->metadata);
        $this->assertSame('d1', $rowA->metadata['device_id']);
    }

    public function test_reposting_same_batch_is_idempotent(): void
    {
        $events = [$this->event(['aggregate_id' => 'device-A']), $this->event(['aggregate_id' => 'device-B'])];

        $this->postJson('/api/v1/pos/audit-events/sync', ['events' => $events])->assertOk();

        $response = $this->postJson('/api/v1/pos/audit-events/sync', ['events' => $events]);

        $response->assertOk();
        $response->assertJsonPath('data.created', 0);
        $response->assertJsonPath('data.duplicates', 2);

        $this->assertSame(2, AuditEvent::query()->count());
    }

    public function test_foreign_tenant_event_is_rejected_with_no_writes(): void
    {
        $foreignTenant = Tenant::factory()->create();

        $response = $this->postJson('/api/v1/pos/audit-events/sync', [
            'events' => [$this->event(['tenant_id' => $foreignTenant->id])],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_batch_over_one_hundred_is_rejected(): void
    {
        $events = [];
        for ($i = 0; $i < 101; $i++) {
            $events[] = $this->event(['aggregate_id' => "device-{$i}"]);
        }

        $response = $this->postJson('/api/v1/pos/audit-events/sync', ['events' => $events]);

        $response->assertStatus(422);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_missing_required_field_is_rejected(): void
    {
        $event = $this->event();
        unset($event['event_type']);

        $response = $this->postJson('/api/v1/pos/audit-events/sync', ['events' => [$event]]);

        $response->assertStatus(422);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_oversized_payload_is_rejected(): void
    {
        $bigPayload = ['blob' => str_repeat('x', 9000)];

        $response = $this->postJson('/api/v1/pos/audit-events/sync', [
            'events' => [$this->event(['payload' => $bigPayload])],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_requires_audit_sync_ability(): void
    {
        $this->user->revokePermissionTo('pos.audit_sync');

        $response = $this->postJson('/api/v1/pos/audit-events/sync', [
            'events' => [$this->event()],
        ]);

        $response->assertStatus(403);
    }

    public function test_write_lands_in_tenant_database_in_db_per_tenant_mode(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Database-per-tenant routing is PostgreSQL-only.');
        }

        config(['tenancy_resolver.db_per_tenant' => true]);

        $event = $this->event(['aggregate_id' => 'device-tenant']);

        $response = $this->postJson('/api/v1/pos/audit-events/sync', [
            'events' => [$event],
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.created', 1);

        $this->assertTrue(
            AuditEvent::query()->whereKey($event['event_id'])->exists(),
            'The audit event must be written to the active tenant database.',
        );
    }
}
