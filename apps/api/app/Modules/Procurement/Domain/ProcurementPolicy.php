<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

use App\Enums\Vertical;
use App\Modules\Company\Domain\Company;
use App\Modules\Procurement\Domain\Enums\BillControlMode;
use App\Modules\Procurement\Domain\Enums\MatchEnforcement;
use App\Modules\Procurement\Domain\Enums\MatchMode;
use App\Modules\Procurement\Domain\Enums\ProcurementPreset;
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
 * @property ProcurementPreset|null $preset
 * @property BillControlMode $bill_control_mode
 * @property MatchMode $match_mode
 * @property MatchEnforcement $match_enforcement
 * @property string $variance_tolerance_percent exact decimal string
 * @property string $variance_tolerance_max_amount exact decimal string
 * @property bool $allow_receipt_first
 * @property bool $allow_invoice_first
 * @property bool $invoice_first_requires_approval
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
        'preset',
        'bill_control_mode',
        'match_mode',
        'match_enforcement',
        'variance_tolerance_percent',
        'variance_tolerance_max_amount',
        'allow_receipt_first',
        'allow_invoice_first',
        'invoice_first_requires_approval',
    ];

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'preset' => ProcurementPreset::class,
            'bill_control_mode' => BillControlMode::class,
            'match_mode' => MatchMode::class,
            'match_enforcement' => MatchEnforcement::class,
            // Stored as exact strings — NEVER cast to float
            'variance_tolerance_percent' => 'string',
            'variance_tolerance_max_amount' => 'string',
            'allow_receipt_first' => 'boolean',
            'allow_invoice_first' => 'boolean',
            'invoice_first_requires_approval' => 'boolean',
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

        $policy->preset = ProcurementPreset::Standard;
        $policy->bill_control_mode = BillControlMode::Received;
        $policy->match_mode = MatchMode::ThreeWay;
        $policy->match_enforcement = MatchEnforcement::Warn;
        $policy->variance_tolerance_percent = '2.00';
        $policy->variance_tolerance_max_amount = '1.000';
        // Entry-point toggles are deliberately CLOSED here even though the row is
        // labeled Standard: the missing-row default must fail closed (spec §3.2);
        // the Standard PRESET only opens receipt-first when explicitly applied.
        $policy->allow_receipt_first = false;
        $policy->allow_invoice_first = false;
        $policy->invoice_first_requires_approval = true;

        return $policy;
    }

    public static function firstOrCreateForCompany(Company $company): self
    {
        $defaultPolicy = self::defaultForVertical($company->tenant->vertical);

        return self::firstOrCreate(
            ['company_id' => $company->id],
            [
                'tenant_id' => $company->tenant_id,
                'preset' => $defaultPolicy->preset?->value,
                'bill_control_mode' => $defaultPolicy->bill_control_mode->value,
                'match_mode' => $defaultPolicy->match_mode->value,
                'match_enforcement' => $defaultPolicy->match_enforcement->value,
                'variance_tolerance_percent' => $defaultPolicy->variance_tolerance_percent,
                'variance_tolerance_max_amount' => $defaultPolicy->variance_tolerance_max_amount,
                'allow_receipt_first' => $defaultPolicy->allowsReceiptFirst(),
                'allow_invoice_first' => $defaultPolicy->allowsInvoiceFirst(),
                'invoice_first_requires_approval' => $defaultPolicy->requiresInvoiceFirstApproval(),
            ]
        );
    }

    public function allowsReceiptFirst(): bool
    {
        return $this->booleanAttributeOrDefault('allow_receipt_first', false);
    }

    public function allowsInvoiceFirst(): bool
    {
        return $this->booleanAttributeOrDefault('allow_invoice_first', false);
    }

    public function requiresInvoiceFirstApproval(): bool
    {
        return $this->booleanAttributeOrDefault('invoice_first_requires_approval', true);
    }

    /**
     * Presets map chain config only (spec §3.3): `invoice_first_requires_approval`
     * is a control knob, NEVER preset-mapped — applying a preset must not loosen
     * or tighten a hand-set approval requirement.
     */
    public function applyPreset(ProcurementPreset $preset): self
    {
        return $this->forceFill(array_merge(
            ['preset' => $preset->value],
            $preset->fields(),
        ));
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

    private function booleanAttributeOrDefault(string $attribute, bool $default): bool
    {
        if (! array_key_exists($attribute, $this->attributes)) {
            return $default;
        }

        $value = $this->getAttribute($attribute);

        if ($value === null) {
            return $default;
        }

        return (bool) $value;
    }
}
