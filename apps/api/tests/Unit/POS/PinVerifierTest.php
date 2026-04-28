<?php

declare(strict_types=1);

namespace Tests\Unit\POS;

use App\Modules\Identity\Domain\User;
use App\Modules\POS\Application\Services\PinVerifier;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
