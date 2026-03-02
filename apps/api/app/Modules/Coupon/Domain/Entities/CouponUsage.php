<?php

declare(strict_types=1);

namespace App\Modules\Coupon\Domain\Entities;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $coupon_id
 * @property string $receipt_id
 * @property string|null $partner_id
 * @property string $discount_amount
 * @property Carbon $used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Coupon $coupon
 */
class CouponUsage extends Model
{
    use HasUuids;

    protected $fillable = [
        'coupon_id',
        'receipt_id',
        'partner_id',
        'discount_amount',
        'used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Coupon, $this>
     */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
