<?php

declare(strict_types=1);

namespace Tests\Unit\Compliance;

use App\Modules\Company\Domain\Company;
use App\Modules\Compliance\Domain\AuditEvent;
use App\Modules\Identity\Domain\User;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuditEventFromClientEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create(['tenant_id' => $tenant->id]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->tenantId = $tenant->id;
        $this->companyId = $company->id;
        $this->userId = $user->id;
    }

    public function test_from_client_envelope_preserves_id_and_occurred_at_and_hashes_over_client_time(): void
    {
        $eventId = (string) Str::uuid();
        $occurred = '2026-06-04T10:00:00.000000+00:00';

        $event = AuditEvent::fromClientEnvelope([
            'event_id' => $eventId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'operator_id' => $this->userId,
            'event_type' => 'pos.login',
            'aggregate_type' => 'PosSession',
            'aggregate_id' => 'device-1',
            'payload' => ['multi_tenant' => true],
            'metadata' => ['device_id' => 'd1'],
            'occurred_at' => $occurred,
        ]);

        $event->saveOrFail();

        // PK preserved from the client envelope.
        $this->assertSame($eventId, $event->id);

        // occurred_at preserved == client value (NOT now()).
        $this->assertTrue(
            Carbon::parse($occurred)->equalTo($event->occurred_at),
            'occurred_at must equal the client-supplied timestamp.',
        );

        // event_hash recomputed over the CLIENT occurred_at equals the stored hash.
        $expectedHash = hash('sha256', json_encode([
            'company_id' => $this->companyId,
            'user_id' => $this->userId,
            'event_type' => 'pos.login',
            'aggregate_type' => 'PosSession',
            'aggregate_id' => 'device-1',
            'payload' => ['multi_tenant' => true],
            'occurred_at' => Carbon::parse($occurred)->format('Y-m-d H:i:s.u'),
        ], JSON_THROW_ON_ERROR));

        $this->assertNotEmpty($event->event_hash);
        $this->assertSame($expectedHash, $event->event_hash);

        // Persisted row reflects the preserved values.
        $fresh = AuditEvent::query()->findOrFail($eventId);
        $this->assertSame($eventId, $fresh->id);
        $this->assertSame($expectedHash, $fresh->event_hash);
        $this->assertTrue(Carbon::parse($occurred)->equalTo($fresh->occurred_at));
    }

    public function test_duplicate_event_id_raises_unique_violation(): void
    {
        $eventId = (string) Str::uuid();
        $envelope = [
            'event_id' => $eventId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'operator_id' => $this->userId,
            'event_type' => 'pos.login',
            'aggregate_type' => 'PosSession',
            'aggregate_id' => 'device-1',
            'payload' => [],
            'metadata' => [],
            'occurred_at' => '2026-06-04T10:00:00.000000+00:00',
        ];

        AuditEvent::fromClientEnvelope($envelope)->saveOrFail();

        $this->expectException(UniqueConstraintViolationException::class);

        AuditEvent::fromClientEnvelope($envelope)->saveOrFail();
    }
}
