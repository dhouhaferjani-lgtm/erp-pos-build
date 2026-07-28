<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain;

use App\Modules\Company\Domain\Company;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Enums\FeeType;
use App\Modules\Treasury\Domain\Enums\InstrumentKind;
use Database\Factories\PaymentMethodFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Universal payment method configuration.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $code
 * @property string $name
 * @property bool $is_physical
 * @property bool $is_cash_tender
 * @property bool $has_maturity
 * @property InstrumentKind|null $instrument_kind
 * @property bool $requires_third_party
 * @property bool $is_push
 * @property bool $has_deducted_fees
 * @property bool $is_restricted
 * @property FeeType|null $fee_type
 * @property numeric-string $fee_fixed
 * @property numeric-string $fee_percent
 * @property string|null $restriction_type
 * @property string|null $default_journal_id
 * @property string|null $default_account_id
 * @property string|null $fee_account_id
 * @property string|null $default_repository_id
 * @property bool $is_active
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string $company_id
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read PaymentRepository|null $defaultRepository
 */
class PaymentMethod extends Model
{
    /** @use HasFactory<PaymentMethodFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'payment_methods';

    protected static function newFactory(): PaymentMethodFactory
    {
        return PaymentMethodFactory::new();
    }

    protected $fillable = [
        'tenant_id',
        'company_id',
        'code',
        'name',
        'is_physical',
        'is_cash_tender',
        'has_maturity',
        'instrument_kind',
        'requires_third_party',
        'is_push',
        'has_deducted_fees',
        'is_restricted',
        'fee_type',
        'fee_fixed',
        'fee_percent',
        'restriction_type',
        'default_journal_id',
        'default_account_id',
        'fee_account_id',
        'default_repository_id',
        'is_active',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_physical' => 'boolean',
            'is_cash_tender' => 'boolean',
            'has_maturity' => 'boolean',
            'instrument_kind' => InstrumentKind::class,
            'requires_third_party' => 'boolean',
            'is_push' => 'boolean',
            'has_deducted_fees' => 'boolean',
            'is_restricted' => 'boolean',
            'is_active' => 'boolean',
            'fee_type' => FeeType::class,
            'fee_fixed' => 'decimal:3',
            'fee_percent' => 'decimal:2',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<PaymentRepository, $this>
     */
    public function defaultRepository(): BelongsTo
    {
        return $this->belongsTo(PaymentRepository::class, 'default_repository_id');
    }

    /**
     * Calculate fee for a given amount.
     *
     * @param  numeric-string  $amount
     * @return numeric-string
     */
    public function calculateFee(string $amount, int $scale = 3): string
    {
        if ($this->fee_type === null || $this->fee_type === FeeType::None) {
            return '0';
        }

        $fee = '0';

        if ($this->fee_type === FeeType::Fixed || $this->fee_type === FeeType::Mixed) {
            /** @var numeric-string $feeFixed */
            $feeFixed = $this->fee_fixed ?? '0';
            $fee = bcadd($fee, $feeFixed, $scale);
        }

        if ($this->fee_type === FeeType::Percentage || $this->fee_type === FeeType::Mixed) {
            /** @var numeric-string $feePercent */
            $feePercent = $this->fee_percent ?? '0';
            $percentageFee = bcdiv(bcmul($amount, $feePercent, 4), '100', $scale);
            $fee = bcadd($fee, $percentageFee, $scale);
        }

        return $fee;
    }

    /**
     * Calculate net amount after fee deduction.
     *
     * @param  numeric-string  $amount
     * @return numeric-string
     */
    public function calculateNetAmount(string $amount, int $scale = 3): string
    {
        $fee = $this->calculateFee($amount, $scale);

        return bcsub($amount, $fee, $scale);
    }

    /**
     * Scope to filter by tenant.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTenant(Builder $query, string $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    /**
     * Scope to filter active methods only.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter physical methods.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePhysical(Builder $query): Builder
    {
        return $query->where('is_physical', true);
    }

    /**
     * Scope to filter methods with maturity.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithMaturity(Builder $query): Builder
    {
        return $query->where('has_maturity', true);
    }

    /**
     * Scope to filter by company.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
