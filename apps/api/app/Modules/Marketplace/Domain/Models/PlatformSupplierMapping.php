<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Domain\Models;

use App\Modules\Partner\Domain\Partner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $platform_supplier_brand
 * @property string|null $partner_id
 * @property string|null $marketplace_seller_id
 * @property bool $auto_order_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Partner|null $partner
 * @property-read MarketplaceSeller|null $marketplaceSeller
 *
 * @method static Builder<static> forTenant(string $tenantId)
 * @method static Builder<static> forCompany(string $companyId)
 */
class PlatformSupplierMapping extends Model
{
    use HasUuids;

    protected $table = 'platform_supplier_mappings';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'platform_supplier_brand',
        'partner_id',
        'marketplace_seller_id',
        'auto_order_enabled',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'auto_order_enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** @return BelongsTo<MarketplaceSeller, $this> */
    public function marketplaceSeller(): BelongsTo
    {
        return $this->belongsTo(MarketplaceSeller::class, 'marketplace_seller_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
