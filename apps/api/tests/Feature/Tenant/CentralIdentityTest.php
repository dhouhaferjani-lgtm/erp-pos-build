<?php

declare(strict_types=1);

namespace Tests\Feature\Tenant;

use App\Modules\Tenant\Domain\CentralIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_a_central_identity_row(): void
    {
        $tenantId = Str::uuid()->toString();
        $userId = Str::uuid()->toString();

        $identity = CentralIdentity::create([
            'email' => 'owner@example.com',
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ]);

        $this->assertNotNull($identity->id);
        $this->assertDatabaseHas('central_identities', [
            'email' => 'owner@example.com',
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ]);
    }

    public function test_user_id_is_nullable(): void
    {
        $identity = CentralIdentity::create([
            'email' => 'pending@example.com',
            'tenant_id' => Str::uuid()->toString(),
            'user_id' => null,
        ]);

        $this->assertNull($identity->user_id);
    }

    public function test_same_email_can_belong_to_multiple_tenants(): void
    {
        CentralIdentity::create([
            'email' => 'shared@example.com',
            'tenant_id' => Str::uuid()->toString(),
        ]);

        CentralIdentity::create([
            'email' => 'shared@example.com',
            'tenant_id' => Str::uuid()->toString(),
        ]);

        $this->assertSame(2, CentralIdentity::where('email', 'shared@example.com')->count());
    }

    public function test_email_is_unique_within_a_single_tenant(): void
    {
        $tenantId = Str::uuid()->toString();

        CentralIdentity::create([
            'email' => 'dupe@example.com',
            'tenant_id' => $tenantId,
        ]);

        $this->expectException(QueryException::class);

        CentralIdentity::create([
            'email' => 'dupe@example.com',
            'tenant_id' => $tenantId,
        ]);
    }
}
