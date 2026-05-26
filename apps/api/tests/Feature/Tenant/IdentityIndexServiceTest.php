<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Tenant\Application\Services\IdentityIndexService;
use App\Modules\Tenant\Domain\CentralIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdentityIndexServiceTest extends TestCase
{
    use RefreshDatabase;

    private IdentityIndexService $service;

    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(IdentityIndexService::class);
        $this->tenantId = Str::uuid()->toString();
    }

    public function test_record_creates_a_membership_row(): void
    {
        $userId = Str::uuid()->toString();

        $this->service->record('owner@example.com', $this->tenantId, $userId);

        $this->assertDatabaseHas('central_identities', [
            'email' => 'owner@example.com',
            'tenant_id' => $this->tenantId,
            'user_id' => $userId,
        ]);
    }

    public function test_record_is_idempotent_and_updates_user_id(): void
    {
        $this->service->record('owner@example.com', $this->tenantId, null);
        $userId = Str::uuid()->toString();
        $this->service->record('owner@example.com', $this->tenantId, $userId);

        $this->assertSame(1, CentralIdentity::where('email', 'owner@example.com')
            ->where('tenant_id', $this->tenantId)->count());
        $this->assertDatabaseHas('central_identities', [
            'email' => 'owner@example.com',
            'tenant_id' => $this->tenantId,
            'user_id' => $userId,
        ]);
    }

    public function test_record_with_null_email_is_a_no_op(): void
    {
        $this->service->record(null, $this->tenantId, Str::uuid()->toString());

        $this->assertSame(0, CentralIdentity::where('tenant_id', $this->tenantId)->count());
    }

    public function test_sync_email_moves_the_row_from_old_to_new_email(): void
    {
        $userId = Str::uuid()->toString();
        $this->service->record('old@example.com', $this->tenantId, $userId);

        $this->service->syncEmail('old@example.com', 'new@example.com', $this->tenantId, $userId);

        $this->assertDatabaseMissing('central_identities', [
            'email' => 'old@example.com',
            'tenant_id' => $this->tenantId,
        ]);
        $this->assertDatabaseHas('central_identities', [
            'email' => 'new@example.com',
            'tenant_id' => $this->tenantId,
            'user_id' => $userId,
        ]);
    }

    public function test_sync_email_with_unchanged_email_keeps_single_row(): void
    {
        $userId = Str::uuid()->toString();
        $this->service->record('same@example.com', $this->tenantId, $userId);

        $this->service->syncEmail('same@example.com', 'same@example.com', $this->tenantId, $userId);

        $this->assertSame(1, CentralIdentity::where('tenant_id', $this->tenantId)->count());
    }

    public function test_remove_deletes_the_membership(): void
    {
        $this->service->record('gone@example.com', $this->tenantId, Str::uuid()->toString());

        $this->service->remove('gone@example.com', $this->tenantId);

        $this->assertDatabaseMissing('central_identities', [
            'email' => 'gone@example.com',
            'tenant_id' => $this->tenantId,
        ]);
    }

    public function test_remove_with_null_email_is_a_no_op(): void
    {
        $this->service->record('keep@example.com', $this->tenantId, Str::uuid()->toString());

        $this->service->remove(null, $this->tenantId);

        $this->assertSame(1, CentralIdentity::where('tenant_id', $this->tenantId)->count());
    }
}
