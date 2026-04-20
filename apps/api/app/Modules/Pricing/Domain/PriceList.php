<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PriceList extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'code',
        'name',
        'description',
        'currency',
        'is_active',
        'is_default',
        'valid_from',
        'valid_until',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'valid_from' => 'date',
        'valid_until' => 'date',
    ];

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return HasMany<PriceListItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    /** @return HasMany<PartnerPriceList, $this> */
    public function partnerPriceLists(): HasMany
    {
        return $this->hasMany(PartnerPriceList::class);
    }

    public function isValidForDate(\DateTimeInterface $date): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->valid_from && $date < $this->valid_from) {
            return false;
        }

        if ($this->valid_until && $date > $this->valid_until) {
            return false;
        }

        return true;
    }

    public function isCurrentlyValid(): bool
    {
        return $this->isValidForDate(now());
    }
}
