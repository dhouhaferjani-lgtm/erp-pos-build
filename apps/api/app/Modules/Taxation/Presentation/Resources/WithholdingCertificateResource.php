<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Presentation\Resources;

use App\Modules\Taxation\Domain\Entities\WithholdingCertificate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin WithholdingCertificate
 */
class WithholdingCertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'certificate_number' => $this->certificate_number,
            'year' => $this->year,
            'reference' => $this->getReference(),
            'direction' => $this->direction->value,
            'direction_label' => $this->direction->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),

            // Partner info
            'partner_id' => $this->partner_id,
            'partner' => $this->whenLoaded('partner', fn () => [
                'id' => $this->partner->id,
                'name' => $this->partner->name,
                'vat_number' => $this->partner->vat_number,
            ]),

            // Source documents
            'document_id' => $this->document_id,
            'payment_id' => $this->payment_id,

            // Amounts
            'currency' => $this->currency,
            'gross_amount' => $this->gross_amount,
            'withholding_rate' => $this->withholding_rate,
            'withholding_amount' => $this->withholding_amount,
            'net_amount' => $this->net_amount,
            'rate_percentage' => $this->getRateAsPercentage(),

            // Rule info
            'withholding_rule_id' => $this->withholding_rule_id,
            'rule' => $this->whenLoaded('rule', fn () => [
                'id' => $this->rule->id,
                'code' => $this->rule->code,
                'name' => $this->rule->name,
            ]),
            'override_reason' => $this->override_reason,
            'is_manual_override' => $this->isManualOverride(),

            // TEJ submission
            'tej_reference' => $this->tej_reference,
            'tej_submitted_at' => $this->tej_submitted_at?->toIso8601String(),
            'is_submitted_to_tej' => $this->isSubmittedToTEJ(),

            // Certificate file
            'certificate_media_id' => $this->certificate_media_id,

            // Fiscal chain
            'hash' => $this->hash,
            'previous_hash' => $this->previous_hash,
            'chain_sequence' => $this->chain_sequence,

            // Metadata
            'issued_at' => $this->issued_at?->toIso8601String(),
            'issued_by' => $this->issued_by,
            'issuer' => $this->whenLoaded('issuer', fn () => [
                'id' => $this->issuer->id,
                'name' => $this->issuer->name,
            ]),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),

            // Permissions/actions
            'can_be_modified' => $this->canBeModified(),
            'can_be_issued' => $this->canBeIssued(),
            'can_be_submitted' => $this->canBeSubmitted(),
            'can_be_voided' => $this->canBeVoided(),

            // GL account
            'gl_account_code' => $this->getGLAccountCode(),
        ];
    }
}
