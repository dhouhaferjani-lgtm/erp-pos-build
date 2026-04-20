<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Domain\Entities;

use App\Modules\Company\Domain\Company;
use App\Modules\Coupon\Domain\Enums\CouponStatus;
use App\Modules\Coupon\Domain\Enums\CouponType;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $name
 * @property string $code
 * @property CouponType $type
 * @property CouponStatus $status
 * @property bool $is_single_use
 * @property int|null $max_uses
 * @property int $use_count
 * @property int|null $max_uses_per_customer
 * @property string $discount_type
 * @property string $discount_value
 * @property string|null $max_discount_amount
 * @property string|null $minimum_order_amount
 * @property array<string>|null $qualifying_product_ids
 * @property array<string>|null $qualifying_category_ids
 * @property bool $is_exclusive
 * @property string $stacking_group
 * @property Carbon|null $starts_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Collection<int, CouponUsage> $usages
 */
class Coupon extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'name',
        'code',
        'type',
        'status',
        'is_single_use',
        'max_uses',
        'use_count',
        'max_uses_per_customer',
        'discount_type',
        'discount_value',
        'max_discount_amount',
        'minimum_order_amount',
        'qualifying_product_ids',
        'qualifying_category_ids',
        'is_exclusive',
        'stacking_group',
        'starts_at',
        'expires_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'active',
        'is_single_use' => false,
        'use_count' => 0,
        'is_exclusive' => false,
        'stacking_group' => 'coupons',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CouponType::class,
            'status' => CouponStatus::class,
            'is_single_use' => 'boolean',
            'max_uses' => 'integer',
            'use_count' => 'integer',
            'max_uses_per_customer' => 'integer',
            'is_exclusive' => 'boolean',
            'qualifying_product_ids' => 'array',
            'qualifying_category_ids' => 'array',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    // -- Relations --

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<CouponUsage, $this>
     */
    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    // -- Domain Logic --

    public function isValid(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        if ($this->status !== CouponStatus::Active) {
            return false;
        }

        $startsAt = $this->resolveDateAttribute('starts_at');
        if ($startsAt !== null && $now->lt($startsAt)) {
            return false;
        }

        $expiresAt = $this->resolveDateAttribute('expires_at');
        if ($expiresAt !== null && $now->gt($expiresAt)) {
            return false;
        }

        if ($this->max_uses !== null && $this->use_count >= $this->max_uses) {
            return false;
        }

        return true;
    }

    public function customerUsageCount(string $partnerId): int
    {
        return $this->usages()->where('partner_id', $partnerId)->count();
    }

    private function resolveDateAttribute(string $key): ?Carbon
    {
        $raw = $this->getAttributes()[$key] ?? null;
        if ($raw === null) {
            return null;
        }
        if ($raw instanceof Carbon) {
            return $raw;
        }

        return Carbon::parse($raw);
    }

    // -- Scopes --

    /**
     * @param  Builder<Coupon>  $query
     * @return Builder<Coupon>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * @param  Builder<Coupon>  $query
     * @return Builder<Coupon>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CouponStatus::Active);
    }

    /**
     * @param  Builder<Coupon>  $query
     * @return Builder<Coupon>
     */
    public function scopeByCode(Builder $query, string $code): Builder
    {
        return $query->where('code', strtoupper($code));
    }
}
