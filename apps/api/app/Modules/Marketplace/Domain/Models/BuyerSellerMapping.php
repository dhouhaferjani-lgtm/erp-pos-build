<?php

declare(strict_types=1);

namespace App\Modules\Marketplace\Domain\Models;

use App\Modules\Company\Domain\Company;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $seller_id
 * @property string $buyer_tenant_id
 * @property string $buyer_company_id
 * @property string|null $buyer_partner_id
 * @property string|null $seller_partner_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read MarketplaceSeller $seller
 * @property-read Tenant $buyerTenant
 * @property-read Company $buyerCompany
 * @property-read Partner|null $buyerPartner
 * @property-read Partner|null $sellerPartner
 */
class BuyerSellerMapping extends Model
{
    use HasUuids;

    protected $table = 'buyer_seller_mappings';

    /** @var list<string> */
    protected $fillable = [
        'seller_id',
        'buyer_tenant_id',
        'buyer_company_id',
        'buyer_partner_id',
        'seller_partner_id',
    ];

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

    /** @return BelongsTo<Partner, $this> */
    public function buyerPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'buyer_partner_id');
    }

    /** @return BelongsTo<Partner, $this> */
    public function sellerPartner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'seller_partner_id');
    }
}
