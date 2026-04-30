<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use Database\Factories\TenantSigningKeyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * Tenant signing key for receipt QR tokens (and future signing purposes).
 *
 * Tenants may have two active keys at any time: "current" (used for signing)
 * and "previous" (still accepted during verification for rotation grace period).
 *
 * SECURITY:
 *   - key_material is encrypted at rest using Laravel's Crypt facade.
 *   - key_material is NOT included in toArray() / toJson() serialisation.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $kid Key identifier — e.g. "current", "previous"
 * @property string $key_material Decrypted raw key bytes (accessor handles decryption)
 * @property string $purpose Signing purpose — default "receipt_qr"
 * @property bool $is_active
 * @property string $algorithm — default "HMAC-SHA256-128"
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $retired_at
 *
 * @method static Builder<static> active()
 * @method static Builder<static> byTenant(string $tenantId, string $purpose)
 */
class TenantSigningKey extends Model
{
    /** @use HasFactory<TenantSigningKeyFactory> */
    use HasFactory;

    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'tenant_signing_keys';

    /**
     * Prevent key_material from leaking via serialisation.
     *
     * @var list<string>
     */
    protected $hidden = ['key_material'];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'kid',
        'key_material',
        'purpose',
        'is_active',
        'algorithm',
        'retired_at',
    ];

    protected static function newFactory(): TenantSigningKeyFactory
    {
        return TenantSigningKeyFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'retired_at' => 'datetime',
        ];
    }

    // -------------------------------------------------------------------------
    // Accessors / Mutators
    // -------------------------------------------------------------------------

    /**
     * Decrypt key_material on get.
     * The raw key is never stored in plaintext in the database.
     */
    public function getKeyMaterialAttribute(string $value): string
    {
        return Crypt::decryptString($value);
    }

    /**
     * Encrypt key_material on set.
     */
    public function setKeyMaterialAttribute(string $value): void
    {
        $this->attributes['key_material'] = Crypt::encryptString($value);
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    /**
     * Scope to keys that are active and not retired.
     *
     * @param  Builder<TenantSigningKey>  $query
     * @return Builder<TenantSigningKey>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('retired_at');
    }

    /**
     * Scope to keys for a specific tenant and purpose.
     *
     * @param  Builder<TenantSigningKey>  $query
     * @return Builder<TenantSigningKey>
     */
    public function scopeByTenant(Builder $query, string $tenantId, string $purpose): Builder
    {
        return $query->where('tenant_id', $tenantId)->where('purpose', $purpose);
    }
}
