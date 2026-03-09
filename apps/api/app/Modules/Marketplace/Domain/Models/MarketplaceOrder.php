<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Domain\Models;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Marketplace\Domain\Enums\MarketplaceOrderStatus;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $seller_id
 * @property string $buyer_tenant_id
 * @property string $buyer_company_id
 * @property string $order_number
 * @property MarketplaceOrderStatus $order_status
 * @property string $country_code
 * @property string $currency
 * @property string $subtotal
 * @property string $commission_amount
 * @property string $commission_rate
 * @property string $total
 * @property string|null $buyer_document_id
 * @property string|null $seller_document_id
 * @property string|null $notes
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $shipped_at
 * @property \Illuminate\Support\Carbon|null $delivered_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read MarketplaceSeller $seller
 * @property-read Tenant $buyerTenant
 * @property-read Company $buyerCompany
 * @property-read Document|null $buyerDocument
 * @property-read Document|null $sellerDocument
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MarketplaceOrderLine> $lines
 */
class MarketplaceOrder extends Model
{
    use HasUuids;

    protected $table = 'marketplace_orders';

    /** @var list<string> */
    protected $fillable = [
        'seller_id',
        'buyer_tenant_id',
        'buyer_company_id',
        'order_number',
        'order_status',
        'country_code',
        'currency',
        'subtotal',
        'commission_amount',
        'commission_rate',
        'total',
        'buyer_document_id',
        'seller_document_id',
        'notes',
        'confirmed_at',
        'shipped_at',
        'delivered_at',
        'cancelled_at',
        'cancellation_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_status' => MarketplaceOrderStatus::class,
            'subtotal' => 'decimal:3',
            'commission_amount' => 'decimal:3',
            'commission_rate' => 'decimal:2',
            'total' => 'decimal:3',
            'confirmed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<MarketplaceSeller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(MarketplaceSeller::class, 'seller_id');
    }

    /** @return BelongsTo<Tenant, $this> */
    public function buyerTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'buyer_tenant_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function buyerCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'buyer_company_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function buyerDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'buyer_document_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function sellerDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'seller_document_id');
    }

    /** @return HasMany<MarketplaceOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(MarketplaceOrderLine::class, 'order_id');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForBuyer(Builder $query, string $tenantId, string $companyId): Builder
    {
        return $query->where('buyer_tenant_id', $tenantId)
            ->where('buyer_company_id', $companyId);
    }
}
