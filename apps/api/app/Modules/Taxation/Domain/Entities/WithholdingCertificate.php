<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Entities;

use App\Modules\Company\Domain\Company;
use App\Modules\Document\Domain\Document;
use App\Modules\Identity\Domain\User;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Taxation\Domain\Enums\CertificateStatus;
use App\Modules\Taxation\Domain\Enums\WithholdingDirection;
use App\Modules\Tenant\Domain\Tenant;
use App\Modules\Treasury\Domain\Payment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Withholding Certificate
 *
 * Represents a withholding tax certificate for fiscal compliance.
 * Tracks amounts withheld from payments and submission to tax authorities.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $company_id
 * @property string $certificate_number
 * @property int $year
 * @property WithholdingDirection $direction
 * @property string $partner_id
 * @property string|null $document_id
 * @property string|null $payment_id
 * @property string $currency
 * @property numeric-string $gross_amount
 * @property numeric-string $withholding_rate
 * @property numeric-string $withholding_amount
 * @property numeric-string $net_amount
 * @property string|null $withholding_rule_id
 * @property string|null $override_reason
 * @property string|null $tej_reference
 * @property Carbon|null $tej_submitted_at
 * @property string|null $certificate_media_id
 * @property CertificateStatus $status
 * @property string|null $hash
 * @property string|null $previous_hash
 * @property int|null $chain_sequence
 * @property Carbon|null $issued_at
 * @property string|null $issued_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tenant $tenant
 * @property-read Company $company
 * @property-read Partner $partner
 * @property-read Document|null $document
 * @property-read WithholdingTaxRule|null $rule
 * @property-read User|null $issuer
 */
class WithholdingCertificate extends Model
{
    use HasUuids;

    protected $fillable = [
        'tenant_id',
        'company_id',
        'certificate_number',
        'year',
        'direction',
        'partner_id',
        'document_id',
        'payment_id',
        'currency',
        'gross_amount',
        'withholding_rate',
        'withholding_amount',
        'net_amount',
        'withholding_rule_id',
        'override_reason',
        'tej_reference',
        'tej_submitted_at',
        'certificate_media_id',
        'status',
        'hash',
        'previous_hash',
        'chain_sequence',
        'issued_at',
        'issued_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'direction' => WithholdingDirection::class,
            'gross_amount' => 'decimal:3',
            'withholding_rate' => 'decimal:4',
            'withholding_amount' => 'decimal:3',
            'net_amount' => 'decimal:3',
            'status' => CertificateStatus::class,
            'chain_sequence' => 'integer',
            'tej_submitted_at' => 'datetime',
            'issued_at' => 'datetime',
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
     * @return BelongsTo<Partner, $this>
     */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<WithholdingTaxRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(WithholdingTaxRule::class, 'withholding_rule_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    /**
     * Check if certificate can be modified.
     */
    public function canBeModified(): bool
    {
        return $this->status->canBeModified();
    }

    /**
     * Check if certificate can be issued.
     */
    public function canBeIssued(): bool
    {
        return $this->status->canBeIssued();
    }

    /**
     * Check if certificate can be submitted to TEJ.
     */
    public function canBeSubmitted(): bool
    {
        return $this->status->canBeSubmitted();
    }

    /**
     * Check if certificate can be voided.
     */
    public function canBeVoided(): bool
    {
        return $this->status->canBeVoided();
    }

    /**
     * Check if certificate was created from a rule or manually overridden.
     */
    public function isManualOverride(): bool
    {
        return $this->withholding_rule_id === null;
    }

    /**
     * Check if certificate has been submitted to TEJ.
     */
    public function isSubmittedToTEJ(): bool
    {
        return $this->tej_submitted_at !== null;
    }

    /**
     * Get the GL account code for this certificate.
     * Returns base account + rate sub-account (e.g., "42236.10" for 10%).
     */
    public function getGLAccountCode(): string
    {
        $base = $this->direction->glAccountBase();
        $ratePercent = (int) bcmul($this->withholding_rate, '100', 0);

        return sprintf('%s.%02d', $base, $ratePercent);
    }

    /**
     * Get the withholding rate as a percentage (e.g., 0.05 becomes 5).
     */
    public function getRateAsPercentage(): float
    {
        return (float) bcmul($this->withholding_rate, '100', 2);
    }

    /**
     * Get certificate reference for display.
     */
    public function getReference(): string
    {
        return sprintf('%s-%s', $this->certificate_number, $this->year);
    }

    /**
     * Get direction label for display.
     */
    public function getDirectionLabel(): string
    {
        return $this->direction->label();
    }

    /**
     * Get status label for display.
     */
    public function getStatusLabel(): string
    {
        return $this->status->label();
    }

    /**
     * Check if amounts are internally consistent.
     * Validates: gross_amount - withholding_amount = net_amount
     */
    public function validateAmounts(): bool
    {
        $calculated = bcsub($this->gross_amount, $this->withholding_amount, 3);

        return bccomp($calculated, $this->net_amount, 3) === 0;
    }

    /**
     * Check if withholding amount matches rate.
     * Validates: gross_amount * withholding_rate = withholding_amount
     */
    public function validateWithholdingCalculation(): bool
    {
        $calculated = bcmul($this->gross_amount, $this->withholding_rate, 3);

        return bccomp($calculated, $this->withholding_amount, 3) === 0;
    }
}
