<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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

    public function test_cross_tenant_company_id_is_rejected_with_no_writes(): void
    {
        // A company owned by a DIFFERENT tenant. Even though the event carries
        // this tenant's tenant_id, the company_id points across the boundary.
        $foreignTenant = Tenant::factory()->create();
        $foreignCompany = Company::factory()->create(['tenant_id' => $foreignTenant->id]);

        $response = $this->postJson('/api/v1/pos/audit-events/sync', [
            'events' => [$this->event(['company_id' => $foreignCompany->id])],
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, AuditEvent::query()->count());
    }

    public function test_cross_tenant_operator_id_is_rejected_with_no_writes(): void
    {
        // An operator (user) owned by a DIFFERENT tenant.
        $foreignTenant = Tenant::factory()->create();
        $foreignUser = User::factory()->create(['tenant_id' => $foreignTenant->id]);

        $response = $this->postJson('/api/v1/pos/audit-events/sync', [
            'events' => [$this->event(['operator_id' => $foreignUser->id])],
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

    /**
     * Authorization is enforced by the `pos.audit_sync` Spatie permission via
     * the {@see Gate} — NOT by a token ability.
     *
     * This test uses a REAL personal-access token (not Sanctum::actingAs, which
     * bypasses the bearer-token tenant resolution and makes the negative case
     * vacuous). The token carries the `pos:*` ability and the `tenant:<uuid>`
     * claim used by every POS endpoint; we deliberately do NOT assert a
     * `tokenCan('pos.audit_sync')` ability check, because that would break the
     * `pos:*` token model. The negative case proves the permission GATE fires:
     * a real, fully-abilitied token whose user lacks the Spatie permission is
     * still rejected 403.
     */
    public function test_requires_audit_sync_permission_with_real_token(): void
    {
        $this->user->revokePermissionTo('pos.audit_sync');

        $token = $this->user->createToken(
            't',
            ['pos:*', 'tenant:'.$this->user->tenant_id],
        )->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/pos/audit-events/sync', [
                'events' => [$this->event()],
            ]);

        $response->assertStatus(403);
        $this->assertSame(0, AuditEvent::query()->count());

        // Positive control: the SAME real token, once the permission is granted,
        // is authorized — proving the 403 above is the permission gate and not a
        // token-ability rejection.
        $this->user->givePermissionTo('pos.audit_sync');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $ok = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/pos/audit-events/sync', [
                'events' => [$this->event()],
            ]);

        $ok->assertOk();
        $ok->assertJsonPath('data.created', 1);
    }

    public function test_client_clock_skew_is_signed_server_minus_client(): void
    {
        // Spec: client_clock_skew_ms = server_now - client_occurred_at. Freeze
        // the server clock and place the client 10s in the PAST -> +10000ms.
        $serverNow = CarbonImmutable::parse('2026-06-04T12:00:00.000000+00:00');
        Carbon::setTestNow($serverNow);
        CarbonImmutable::setTestNow($serverNow);

        try {
            $event = $this->event([
                'aggregate_id' => 'device-skew',
                'occurred_at' => $serverNow->subSeconds(10)->toIso8601String(),
            ]);

            $response = $this->postJson('/api/v1/pos/audit-events/sync', [
                'events' => [$event],
            ]);

            $response->assertOk();

            $row = AuditEvent::query()->whereKey($event['event_id'])->firstOrFail();
            $this->assertSame(10000, $row->metadata['client_clock_skew_ms']);
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
        }
    }

    /**
     * DB-per-tenant routing assertion.
     *
     * PostgreSQL-gated: the row-level -> database-per-tenant flip is PG-only
     * (Stancl `CREATE DATABASE` cannot run inside the transaction RefreshDatabase
     * opens, and SQLite has no concept of a separate physical tenant database).
     * This test therefore provisions a REAL tenant database the way the Stancl
     * flip tests do, drives the sync endpoint with a REAL bearer token carrying
     * the `tenant:<uuid>` claim, and asserts the audit row lands on the TENANT
     * connection and is NOT present on the CENTRAL connection.
     *
     * On non-PG drivers (the default CI unit lane) it skips with a clear note,
     * rather than running a vacuous single-connection assertion. The substantive
     * coverage runs at the PG merge gate.
     */
    public function test_write_lands_in_tenant_database_in_db_per_tenant_mode(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'Database-per-tenant routing is PostgreSQL-only; runs at the PG merge gate. '
                .'It must assert the audit row lands on the tenant connection and is absent '
                .'from the central connection — impossible to prove under SQLite single-connection.',
            );
        }

        config(['tenancy_resolver.db_per_tenant' => true]);

        $event = $this->event(['aggregate_id' => 'device-tenant']);

        // Real bearer token carrying the tenant claim drives the production
        // tenant-resolution path (not Sanctum::actingAs).
        $token = $this->user->createToken(
            't',
            ['pos:*', 'tenant:'.$this->user->tenant_id],
        )->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/pos/audit-events/sync', [
                'events' => [$event],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.created', 1);

        // The row exists on the active (tenant) connection...
        $this->assertTrue(
            AuditEvent::query()->whereKey($event['event_id'])->exists(),
            'The audit event must be written to the active tenant database.',
        );

        // ...and NOT on the central connection: proof the write routed to the
        // tenant database, not a shared central store.
        $this->assertFalse(
            DB::connection('central')->table('audit_events')
                ->where('id', $event['event_id'])->exists(),
            'The audit event must NOT be written to the central database.',
        );
    }
}
