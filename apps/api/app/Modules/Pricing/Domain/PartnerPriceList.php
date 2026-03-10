<?php

declare(strict_types=1);

namespace App\Modules\Pricing\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerPriceList extends Model
{
    use HasUuids;

    protected $fillable = [
        'partner_id',
        'price_list_id',
        'valid_from',
        'valid_until',
        'is_active',
        'priority',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'valid_from' => 'date',
        'valid_until' => 'date',
        'priority' => 'integer',
    ];

    /** @return BelongsTo<\App\Modules\Partner\Domain\Partner, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Partner\Domain\Partner::class);
    }

    /** @return BelongsTo<PriceList, $this> */
    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class);
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
