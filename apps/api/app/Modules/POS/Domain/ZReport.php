<?php

declare(strict_types=1);

namespace App\Modules\POS\Domain;

use App\Modules\Identity\Domain\User;
use App\Shared\Domain\ByteaBinding;
use App\Shared\Domain\Concerns\BindsBinaryColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property array<string, mixed> $report_data JSONB: Complete Z report content
 * @property array<string, mixed>|null $receipt_snapshots JSONB: Receipt snapshots at Z time
 * @property array<string, mixed>|null $grand_totals JSONB: Cumulative lifetime counters
 * @property string|null $canonical_bytes Canonical Z_REPORT fiscal event bytes
 * @property string|null $canonical_bytes_hash SHA-256 of canonical_bytes
 * @property string|null $fiscal_event_id UUID FK → fiscal_events.id for device-authored Z_REPORT rows
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
    /**
     * `canonical_bytes` mirrors the Z_REPORT fiscal event's canonical encoding
     * into a `bytea` column — bind it as `PDO::PARAM_LOB`, never as a string.
     */
    use BindsBinaryColumns;

    use HasUuids;

    /**
     * @var string
     */
    protected $table = 'pos_z_reports';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'terminal_id',
        'shift_id',
        'z_number',
        'fiscal_hash',
        'previous_z_hash',
        'report_data',
        'receipt_snapshots',
        'grand_totals',
        'canonical_bytes',
        'canonical_bytes_hash',
        'fiscal_event_id',
        'generated_by',
        'generated_at',
    ];

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * `canonical_bytes` is a BYTEA column; pdo_pgsql hydrates BYTEA as a PHP
     * stream resource (SQLite returns a string), so every reader on
     * production PostgreSQL would receive a stream without this accessor.
     * Mirrors FiscalEvent::getCanonicalBytesAttribute — the standalone
     * readers (ZReportHashService / ReceiptHashService / Nf525DataProvider
     * stringify helpers) already normalise raw query-builder rows; this
     * covers the Eloquent path.
     */
    /**
     * Nullable on this table (a legacy v2 row has no canonical bytes until the
     * one-time projection upgrade), so the null is handled here — ByteaBinding
     * fails loud on anything that is neither a string nor a stream.
     */
    public function getCanonicalBytesAttribute(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return ByteaBinding::read($value);
    }

    /**
     * @return list<string>
     */
    protected function binaryColumns(): array
    {
        return ['canonical_bytes'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'z_number' => 'integer',
            'report_data' => 'array',
            'receipt_snapshots' => 'array',
            'grand_totals' => 'array',
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
     * @return HasMany<ZReportCount, $this>
     */
    public function counts(): HasMany
    {
        return $this->hasMany(ZReportCount::class, 'z_report_id');
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
        /** @var numeric-string $variance */
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
