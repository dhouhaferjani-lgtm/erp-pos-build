<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\Treasury\Domain\PaymentMethod;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-tender cash count row for an end-of-shift Z report.
 *
 * @property string $id
 * @property string $z_report_id
 * @property string $payment_method_id
 * @property string $currency_code
 * @property string $expected_amount
 * @property string $actual_amount
 * @property string $variance_amount
 * @property string $variance_direction
 * @property int $transaction_count
 * @property Carbon $created_at
 * @property-read ZReport $zReport
 * @property-read PaymentMethod $paymentMethod
 */
final class ZReportCount extends Model
{
    use HasUuids;

    protected $table = 'pos_z_report_counts';

    public $timestamps = false;

    protected $fillable = [
        'z_report_id',
        'payment_method_id',
        'currency_code',
        'expected_amount',
        'actual_amount',
        'variance_amount',
        'variance_direction',
        'transaction_count',
    ];

    protected function casts(): array
    {
        return [
            'expected_amount' => 'decimal:4',
            'actual_amount' => 'decimal:4',
            'variance_amount' => 'decimal:4',
            'transaction_count' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<ZReport, $this> */
    public function zReport(): BelongsTo
    {
        return $this->belongsTo(ZReport::class);
    }

    /** @return BelongsTo<PaymentMethod, $this> */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
