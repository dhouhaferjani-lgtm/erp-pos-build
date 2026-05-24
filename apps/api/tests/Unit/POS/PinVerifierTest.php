<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\PinVerifier;
use App\Modules\POS\Domain\Enums\ApprovalScope;
use App\Modules\POS\Domain\Enums\OperatorApprovalDecision;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PinVerifierTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_pin_returns_true(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'pos_pin' => '1234',
        ]);

        $verifier = app(PinVerifier::class);
        $this->assertTrue($verifier->verify($user->id, '1234'));
    }

    public function test_wrong_pin_returns_false(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'pos_pin' => '1234',
        ]);

        $verifier = app(PinVerifier::class);
        $this->assertFalse($verifier->verify($user->id, '9999'));
    }

    public function test_unknown_user_returns_false(): void
    {
        $verifier = app(PinVerifier::class);
        $this->assertFalse($verifier->verify('00000000-0000-0000-0000-000000000000', '1234'));
    }

    public function test_user_without_pin_returns_false(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'pos_pin' => null,
        ]);

        $verifier = app(PinVerifier::class);
        $this->assertFalse($verifier->verify($user->id, '1234'));
    }

    public function test_malformed_user_id_returns_false(): void
    {
        $verifier = app(PinVerifier::class);
        $this->assertFalse($verifier->verify('not-a-uuid', '1234'));
    }

    public function test_scoped_verification_rejects_company_mismatch_before_hash_check(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'pos_pin' => '1234',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        Permission::findOrCreate('pos.close_shift_with_variance', 'sanctum');
        $user->givePermissionTo('pos.close_shift_with_variance');

        Hash::shouldReceive('check')->never();

        $decision = app(PinVerifier::class)->verifyForApproval(
            userId: $user->id,
            pin: '1234',
            tenantId: $tenant->id,
            companyId: '22222222-2222-4222-8222-222222222222',
            approvalScope: ApprovalScope::CloseShiftVariance,
        );

        $this->assertSame(OperatorApprovalDecision::ScopeMismatch, $decision);
    }
}
