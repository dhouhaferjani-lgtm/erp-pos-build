<?php

declare(strict_types=1);

namespace Tests\Feature\Treasury;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Enums\SubscriptionPlan;
use App\Modules\Tenant\Domain\Enums\TenantStatus;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\MovementDirection;
use App\Modules\Treasury\Domain\Enums\MovementReasonCode;
use App\Modules\Treasury\Domain\Enums\MovementSourceType;
use App\Modules\Treasury\Domain\Events\RepositoryMovementRecorded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task 5 (Treasury Money-Movement Spine, Wave A): the
 * RepositoryMovementRecorded event is the only thing standing between a
 * repository_movements row and a durable, actor-attributed audit trail —
 * without this wiring, money movements would leave a ledger row but no
 * compliance record of who/what caused it.
 */
class RepositoryMovementAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_repository_movement_recorded_creates_exactly_one_audit_entry(): void
    {
        $tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant-'.Str::random(8),
            'status' => TenantStatus::Active,
            'plan' => SubscriptionPlan::Professional,
        ]);

        $company = Company::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test Company',
            'country_code' => 'TN',
            'currency' => 'TND',
            'locale' => 'fr_TN',
            'timezone' => 'Africa/Tunis',
        ]);

        $user = User::create([
            'tenant_id' => $tenant->id,
            'name' => 'Test User',
            'email' => 'user-'.Str::random(8).'@example.com',
            'password' => 'password123',
            'status' => UserStatus::Active,
        ]);

        $this->actingAs($user, 'sanctum');

        $movementId = Str::uuid()->toString();
        $repositoryId = Str::uuid()->toString();
        $journalEntryId = Str::uuid()->toString();
        $sourceId = Str::uuid()->toString();
        $reversesMovementId = Str::uuid()->toString();
        $transferGroupId = Str::uuid()->toString();

        event(new RepositoryMovementRecorded(
            movementId: $movementId,
            repositoryId: $repositoryId,
            tenantId: $tenant->id,
            companyId: $company->id,
            direction: MovementDirection::In,
            amount: '250.000',
            balanceAfter: '250.000',
            currency: 'TND',
            sourceType: MovementSourceType::Payment,
            sourceId: $sourceId,
            journalEntryId: $journalEntryId,
            ordinal: 1,
            recordedWhileFrozen: false,
            occurredAt: now()->toIso8601String(),
            createdBy: $user->id,
            reasonCode: MovementReasonCode::Correction,
            reversesMovementId: $reversesMovementId,
            transferGroupId: $transferGroupId,
        ));

        $auditEventCount = AuditEvent::where('aggregate_id', $movementId)
            ->where('event_type', 'treasury.repository.movement_recorded')
            ->count();

        $this->assertSame(1, $auditEventCount);

        $auditEvent = AuditEvent::where('aggregate_id', $movementId)
            ->where('event_type', 'treasury.repository.movement_recorded')
            ->firstOrFail();

        $this->assertEquals($company->id, $auditEvent->company_id);
        $this->assertEquals('RepositoryMovement', $auditEvent->aggregate_type);
        $this->assertEquals($user->id, $auditEvent->user_id);
        $this->assertSame($repositoryId, $auditEvent->payload['repository_id']);
        $this->assertSame('in', $auditEvent->payload['direction']);
        $this->assertSame('250.000', $auditEvent->payload['amount']);
        $this->assertIsString($auditEvent->payload['amount']);
        $this->assertSame('250.000', $auditEvent->payload['balance_after']);
        $this->assertIsString($auditEvent->payload['balance_after']);
        $this->assertSame('TND', $auditEvent->payload['currency']);
        $this->assertSame('payment', $auditEvent->payload['source_type']);
        $this->assertSame($sourceId, $auditEvent->payload['source_id']);
        $this->assertSame($journalEntryId, $auditEvent->payload['journal_entry_id']);
        $this->assertSame(1, $auditEvent->payload['ordinal']);
        $this->assertSame(false, $auditEvent->payload['recorded_while_frozen']);
        $this->assertSame($user->id, $auditEvent->payload['created_by']);
        $this->assertSame('correction', $auditEvent->payload['reason_code']);
        $this->assertSame($reversesMovementId, $auditEvent->payload['reverses_movement_id']);
        $this->assertSame($transferGroupId, $auditEvent->payload['transfer_group_id']);
    }
}
