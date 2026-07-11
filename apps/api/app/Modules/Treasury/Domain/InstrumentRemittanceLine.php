<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Treasury\Domain\Enums\RemittanceLineStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $remittance_id
 * @property string $instrument_id
 * @property numeric-string $amount
 * @property RemittanceLineStatus $line_status
 * @property Carbon|null $cleared_at
 * @property Carbon|null $bounced_at
 * @property-read InstrumentRemittance $remittance
 * @property-read PaymentInstrument $instrument
 */
final class InstrumentRemittanceLine extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'amount' => 'decimal:3',
        'line_status' => RemittanceLineStatus::class,
        'cleared_at' => 'datetime',
        'bounced_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<InstrumentRemittance, $this>
     */
    public function remittance(): BelongsTo
    {
        return $this->belongsTo(InstrumentRemittance::class);
    }

    /**
     * @return BelongsTo<PaymentInstrument, $this>
     */
    public function instrument(): BelongsTo
    {
        return $this->belongsTo(PaymentInstrument::class);
    }
}
