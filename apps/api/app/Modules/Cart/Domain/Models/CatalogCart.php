<?php

declare(strict_types=1);

namespace App\Modules\Cart\Domain\Models;

use App\Modules\Cart\Domain\Enums\CartStatus;
use App\Modules\Company\Domain\Company;
use App\Modules\Identity\Domain\User;
use Database\Factories\CatalogCartFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $user_id
 * @property string|null $name
 * @property string|null $vehicle_id
 * @property CartStatus $status
 * @property bool $is_shared
 * @property array<int, string>|null $shared_with_user_ids
 * @property string|null $notes
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read Company $company
 * @property-read Collection<int, CatalogCartItem> $items
 *
 * @method static Builder<static> active()
 * @method static Builder<static> forUser(string $userId)
 * @method static Builder<static> shared(string $userId)
 */
class CatalogCart extends Model
{
    /** @use HasFactory<CatalogCartFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'catalog_carts';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'user_id',
        'name',
        'vehicle_id',
        'status',
        'is_shared',
        'shared_with_user_ids',
        'notes',
        'expires_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CartStatus::class,
            'is_shared' => 'boolean',
            'shared_with_user_ids' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CatalogCartFactory
    {
        return CatalogCartFactory::new();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<CatalogCartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CatalogCartItem::class, 'cart_id')->orderBy('sort_order');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', CartStatus::Active);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForUser(Builder $query, string $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to include carts shared with a given user.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeShared(Builder $query, string $userId): Builder
    {
        return $query->where(function (Builder $q) use ($userId): void {
            $q->where('user_id', $userId)
                ->orWhere(function (Builder $q2) use ($userId): void {
                    $q2->where('is_shared', true)
                        ->whereJsonContains('shared_with_user_ids', $userId);
                });
        });
    }
}
