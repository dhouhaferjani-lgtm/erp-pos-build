<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $health_claim_id
 * @property string $locale
 * @property string $claim
 * @property string|null $disclaimer_text
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class HealthClaimTranslation extends Model
{
    use HasUuids;

    protected $fillable = [
        'health_claim_id',
        'locale',
        'claim',
        'disclaimer_text',
    ];

    /**
     * Get the health claim that owns this translation.
     *
     * @return BelongsTo<HealthClaim, $this>
     */
    public function healthClaim(): BelongsTo
    {
        return $this->belongsTo(HealthClaim::class);
    }
}
