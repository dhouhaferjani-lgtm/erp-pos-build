<?php

declare(strict_types=1);

namespace Tests\Feature\SupportAccess;

use App\Modules\SupportAccess\Domain\DTOs\ImpersonationAuditDetailsData;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationGrant;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSession;
use App\Modules\SupportAccess\Domain\Entities\ImpersonationSessionEvent;
use App\Modules\SupportAccess\Domain\Enums\AuditOutcome;
use App\Modules\SupportAccess\Domain\Enums\GrantStatus;
use App\Modules\SupportAccess\Domain\Enums\GrantType;
use App\Modules\SupportAccess\Domain\Enums\SessionAccessLevel;
use App\Modules\SupportAccess\Domain\Enums\SessionEventType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SupportAccessMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_support_access_schema_is_split_between_central_and_tenant_audit_storage(): void
    {
        foreach ([
            'impersonation_grants',
            'impersonation_sessions',
            'impersonation_elevations',
            'impersonation_session_permissions',
            'impersonation_session_events',
        ] as $table) {
            self::assertTrue(Schema::hasTable($table), "Missing central table {$table}");
        }
        self::assertFalse(Schema::hasTable('impersonation_reveal_events'));

        self::assertTrue(Schema::hasColumns('tenants', [
            'is_sensitive',
            'support_access_starts_at',
            'support_access_expires_at',
        ]));
        self::assertTrue(Schema::hasColumns('admin_audit_logs', $this->auditAttributionColumns()));
        self::assertTrue(Schema::hasColumns('audit_events', $this->auditAttributionColumns()));

        $subjectColumn = collect(Schema::getColumns('impersonation_grants'))
            ->firstWhere('name', 'subject_user_id');
        self::assertIsArray($subjectColumn);
        self::assertTrue($subjectColumn['nullable'], 'Pre-granted scope must allow subject selection at session start.');
    }

    public function test_central_models_cast_statuses_and_json_details_to_typed_objects(): void
    {
        $tenantId = Str::uuid()->toString();
        $operatorId = Str::uuid()->toString();
        $subjectId = Str::uuid()->toString();

        $grant = ImpersonationGrant::query()->create([
            'tenant_id' => $tenantId,
            'subject_user_id' => $subjectId,
            'operator_id' => $operatorId,
            'type' => GrantType::PerIncident,
            'status' => GrantStatus::PendingTenantApproval,
            'reason' => 'Investigate invoice rendering',
            'ticket_ref' => 'SUP-1042',
            'requested_at' => now(),
            'starts_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $session = ImpersonationSession::query()->create([
            'grant_id' => $grant->id,
            'operator_id' => $operatorId,
            'subject_user_id' => $subjectId,
            'tenant_id' => $tenantId,
            'access_level' => SessionAccessLevel::ReadOnly,
            'started_at' => now(),
            'expires_at' => now()->addHour(),
            'chain_sequence' => 0,
        ]);

        $event = ImpersonationSessionEvent::query()->create([
            'session_id' => $session->id,
            'sequence' => 1,
            'event_type' => SessionEventType::RequestAuthorized,
            'outcome' => AuditOutcome::Allowed,
            'operator_id' => $operatorId,
            'subject_user_id' => $subjectId,
            'tenant_id' => $tenantId,
            'request_id' => Str::uuid()->toString(),
            'http_method' => 'GET',
            'path' => '/api/v1/auth/me',
            'details' => new ImpersonationAuditDetailsData(
                ticket_ref: 'SUP-1042',
                route_name: 'auth.me',
                response_status: null,
                error_code: null,
                resource_type: null,
                resource_id: null,
            ),
            'previous_hash' => str_repeat('0', 64),
            'hash' => str_repeat('a', 64),
            'occurred_at' => now(),
        ]);

        $freshGrant = $grant->fresh();
        $freshSession = $session->fresh();
        $freshEvent = $event->fresh();

        self::assertInstanceOf(ImpersonationGrant::class, $freshGrant);
        self::assertSame(GrantType::PerIncident, $freshGrant->type);
        self::assertSame(GrantStatus::PendingTenantApproval, $freshGrant->status);
        self::assertInstanceOf(ImpersonationSession::class, $freshSession);
        self::assertSame(SessionAccessLevel::ReadOnly, $freshSession->access_level);
        self::assertInstanceOf(ImpersonationSessionEvent::class, $freshEvent);
        self::assertSame(SessionEventType::RequestAuthorized, $freshEvent->event_type);
        self::assertSame(AuditOutcome::Allowed, $freshEvent->outcome);
        self::assertInstanceOf(ImpersonationAuditDetailsData::class, $freshEvent->details);
        self::assertSame('SUP-1042', $freshEvent->details->ticket_ref);
        self::assertSame(config('tenancy.database.central_connection'), $freshEvent->getConnectionName());
    }

    public function test_session_event_sequence_is_unique_and_rows_are_append_only(): void
    {
        [$sessionId, $eventId] = $this->insertEventFixture();

        try {
            DB::table('impersonation_session_events')->insert([
                'id' => Str::uuid()->toString(),
                'session_id' => $sessionId,
                'sequence' => 1,
                'event_type' => SessionEventType::RequestAuthorized->value,
                'outcome' => AuditOutcome::Allowed->value,
                'operator_id' => Str::uuid()->toString(),
                'subject_user_id' => Str::uuid()->toString(),
                'tenant_id' => Str::uuid()->toString(),
                'http_method' => 'GET',
                'path' => '/duplicate',
                'details' => '{}',
                'previous_hash' => str_repeat('0', 64),
                'hash' => str_repeat('b', 64),
                'occurred_at' => now(),
                'created_at' => now(),
            ]);
            self::fail('Duplicate session sequence must be rejected.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        try {
            DB::table('impersonation_session_events')->where('id', $eventId)->update([
                'path' => '/tampered',
            ]);
            self::fail('Session audit events must reject updates.');
        } catch (QueryException) {
            self::assertTrue(true);
        }

        try {
            DB::table('impersonation_session_events')->where('id', $eventId)->delete();
            self::fail('Session audit events must reject deletes.');
        } catch (QueryException) {
            self::assertTrue(true);
        }
    }

    /** @return list<string> */
    private function auditAttributionColumns(): array
    {
        return [
            'impersonator_id',
            'impersonation_session_id',
            'impersonation_event_id',
            'impersonation_sequence',
            'impersonation_previous_hash',
            'impersonation_hash',
        ];
    }

    /** @return array{0: string, 1: string} */
    private function insertEventFixture(): array
    {
        $grantId = Str::uuid()->toString();
        $sessionId = Str::uuid()->toString();
        $eventId = Str::uuid()->toString();
        $operatorId = Str::uuid()->toString();
        $subjectId = Str::uuid()->toString();
        $tenantId = Str::uuid()->toString();
        $now = now();

        DB::table('impersonation_grants')->insert([
            'id' => $grantId,
            'tenant_id' => $tenantId,
            'subject_user_id' => $subjectId,
            'operator_id' => $operatorId,
            'type' => GrantType::PerIncident->value,
            'status' => GrantStatus::Active->value,
            'reason' => 'Test fixture',
            'ticket_ref' => 'SUP-1',
            'requested_at' => $now,
            'starts_at' => $now,
            'expires_at' => $now->copy()->addHour(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('impersonation_sessions')->insert([
            'id' => $sessionId,
            'grant_id' => $grantId,
            'operator_id' => $operatorId,
            'subject_user_id' => $subjectId,
            'tenant_id' => $tenantId,
            'access_level' => SessionAccessLevel::ReadOnly->value,
            'started_at' => $now,
            'expires_at' => $now->copy()->addHour(),
            'chain_sequence' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('impersonation_session_events')->insert([
            'id' => $eventId,
            'session_id' => $sessionId,
            'sequence' => 1,
            'event_type' => SessionEventType::RequestAuthorized->value,
            'outcome' => AuditOutcome::Allowed->value,
            'operator_id' => $operatorId,
            'subject_user_id' => $subjectId,
            'tenant_id' => $tenantId,
            'http_method' => 'GET',
            'path' => '/api/v1/auth/me',
            'details' => '{}',
            'previous_hash' => str_repeat('0', 64),
            'hash' => str_repeat('a', 64),
            'occurred_at' => $now,
            'created_at' => $now,
        ]);

        return [$sessionId, $eventId];
    }
}
