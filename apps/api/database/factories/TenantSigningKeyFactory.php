<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\POS\Domain\TenantSigningKey;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantSigningKey>
 */
class TenantSigningKeyFactory extends Factory
{
    protected $model = TenantSigningKey::class;

    public function definition(): array
    {
        // Generate a cryptographically random 32-byte key (256 bits).
        $rawKey = random_bytes(32);

        return [
            'tenant_id' => Tenant::factory(),
            'kid' => 'current',
            'key_material' => $rawKey, // setter encrypts via Crypt::encryptString
            'purpose' => 'receipt_qr',
            'is_active' => true,
            'algorithm' => 'HMAC-SHA256-128',
            'retired_at' => null,
        ];
    }

    /**
     * Key with kid = "previous" (still active, used for rotation verification).
     */
    public function previous(): static
    {
        return $this->state(fn () => [
            'kid' => 'previous',
        ]);
    }

    /**
     * Key that has been retired and should no longer be used for signing.
     */
    public function retired(): static
    {
        return $this->state(fn () => [
            'is_active' => false,
            'retired_at' => now()->subHour(),
        ]);
    }

    /**
     * Key for a specific tenant.
     */
    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => [
            'tenant_id' => $tenant->id,
        ]);
    }

    /**
     * Key with a specific kid.
     */
    public function withKid(string $kid): static
    {
        return $this->state(fn () => [
            'kid' => $kid,
        ]);
    }
}
