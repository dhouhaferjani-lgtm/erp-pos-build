<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\Identity\Domain\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * POS Z Report Entity
 *
 * Represents an end-of-day closing report.
 * Fiscally critical - hash chained for NF525 compliance.
 * Automatically closes shift and creates GRANDTOTAL_DAILY event.
 *
 * @property string $id
 * @property string $terminal_id
 * @property string $shift_id
 * @property int $z_number Sequential Z report number (never resets)
 * @property string $fiscal_hash SHA-256 hash of this Z report
 * @property string|null $previous_z_hash Hash of previous Z report
 * @property array $report_data JSONB: Complete Z report content
 * @property string $generated_by User who generated the report
 * @property Carbon $generated_at
 * @property-read Terminal $terminal
 * @property-read Shift $shift
 * @property-read User $generatedBy
 *
 * @method static Builder<static> forTerminal(string $terminalId)
 * @method static Builder<static> forShift(string $shiftId)
 * @method static Builder<static> byZNumber(int $zNumber)
 */
class ZReport extends Model
{
    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_z_reports';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'terminal_id',
        'shift_id',
        'z_number',
        'fiscal_hash',
        'previous_z_hash',
        'report_data',
        'generated_by',
        'generated_at',
    ];

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'z_number' => 'integer',
            'report_data' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Terminal, $this>
     */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class, 'terminal_id');
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    /**
     * Check if this is the first Z report for the terminal
     */
    public function isFirstZReport(): bool
    {
        return $this->previous_z_hash === null;
    }

    /**
     * Get formatted Z report number (e.g., Z0001)
     */
    public function getFormattedZNumber(): string
    {
        return sprintf('Z%04d', $this->z_number);
    }

    /**
     * Get sales count from report data
     */
    public function getSalesCount(): int
    {
        return $this->report_data['sales_count'] ?? 0;
    }

    /**
     * Get gross sales from report data
     */
    public function getGrossSales(): string
    {
        return $this->report_data['gross_sales'] ?? '0.00';
    }

    /**
     * Get net sales from report data
     */
    public function getNetSales(): string
    {
        return $this->report_data['net_sales'] ?? '0.00';
    }

    /**
     * Get tax amount from report data
     */
    public function getTaxAmount(): string
    {
        return $this->report_data['tax_amount'] ?? '0.00';
    }

    /**
     * Get opening cash from report data
     */
    public function getOpeningCash(): string
    {
        return $this->report_data['opening_cash'] ?? '0.00';
    }

    /**
     * Get expected cash from report data
     */
    public function getExpectedCash(): string
    {
        return $this->report_data['expected_cash'] ?? '0.00';
    }

    /**
     * Get actual cash from report data
     */
    public function getActualCash(): string
    {
        return $this->report_data['actual_cash'] ?? '0.00';
    }

    /**
     * Get variance from report data
     */
    public function getVariance(): string
    {
        return $this->report_data['variance'] ?? '0.00';
    }

    /**
     * Check if report has variance
     */
    public function hasVariance(int $scale = 3): bool
    {
        $variance = $this->getVariance();

        return bccomp($variance, '0', $scale) !== 0;
    }

    /**
     * Get VAT breakdown from report data
     *
     * @return array<array{rate: string, net: string, vat: string, gross: string}>
     */
    public function getVatBreakdown(): array
    {
        return $this->report_data['vat_breakdown'] ?? [];
    }

    /**
     * Get payment methods breakdown from report data
     *
     * @return array<array{type: string, count: int, amount: string}>
     */
    public function getPaymentMethods(): array
    {
        return $this->report_data['payment_methods'] ?? [];
    }

    /**
     * Scope to filter reports by terminal
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTerminal(Builder $query, string $terminalId): Builder
    {
        return $query->where('terminal_id', $terminalId);
    }

    /**
     * Scope to filter reports by shift
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForShift(Builder $query, string $shiftId): Builder
    {
        return $query->where('shift_id', $shiftId);
    }

    /**
     * Scope to find report by Z number
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeByZNumber(Builder $query, int $zNumber): Builder
    {
        return $query->where('z_number', $zNumber);
    }
}
