<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-company procurement AP policy — one row per company.
 *
 * Controls:
 *   - bill_control_mode:            when a supplier bill may be posted (received vs ordered)
 *   - match_mode:                   two-way vs three-way matching
 *   - match_enforcement:            warn or block on tolerance breach
 *   - variance_tolerance_percent:   allowed variance as a percentage of PO amount
 *   - variance_tolerance_max_amount: hard cap on the absolute variance amount (scale 3 = TND)
 *
 * Phase 1 only supports bill_control_mode=received. The resolver raises a
 * DomainException if a persisted policy carries the reserved `ordered` mode.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property BillControlMode $bill_control_mode
 * @property MatchMode $match_mode
 * @property MatchEnforcement $match_enforcement
 * @property string $variance_tolerance_percent exact decimal string
 * @property string $variance_tolerance_max_amount exact decimal string
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant  $tenant
 * @property-read Company $company
 */
final class ProcurementPolicy extends Model
{
    use HasUuids;

    /** @var string */
    protected $table = 'procurement_policies';

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'company_id',
        'bill_control_mode',
        'match_mode',
        'match_enforcement',
        'variance_tolerance_percent',
        'variance_tolerance_max_amount',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'bill_control_mode' => BillControlMode::class,
            'match_mode' => MatchMode::class,
            'match_enforcement' => MatchEnforcement::class,
            // Stored as exact strings — NEVER cast to float
            'variance_tolerance_percent' => 'string',
            'variance_tolerance_max_amount' => 'string',
        ];
    }

    /**
     * Return an in-memory (non-persisted) default policy for the given vertical.
     *
     * Phase 1 maps all verticals to the same received/three_way/warn policy;
     * the per-vertical seam exists for future divergence (e.g. service-only
     * verticals that warrant two-way matching).
     *
     * Mirrors PosStockPolicy::defaultForVertical().
     */
    public static function defaultForVertical(Vertical $vertical): self
    {
        $policy = new self;

        $policy->bill_control_mode = BillControlMode::Received;
        $policy->match_mode = MatchMode::ThreeWay;
        $policy->match_enforcement = MatchEnforcement::Warn;
        $policy->variance_tolerance_percent = '2.00';
        $policy->variance_tolerance_max_amount = '1.000';

        return $policy;
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
