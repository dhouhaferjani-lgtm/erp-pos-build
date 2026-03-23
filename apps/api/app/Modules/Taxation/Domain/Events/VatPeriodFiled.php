<?php

declare(strict_types=1);

namespace App\Modules\Taxation\Domain\Events;

use App\Modules\Taxation\Domain\Entities\VatPeriod;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * VAT Period Filed Event
 *
 * Dispatched when a VAT period is officially filed with the tax authority.
 */
class VatPeriodFiled
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly VatPeriod $period
    ) {}

    /**
     * Get the period ID for event logging.
     */
    public function getPeriodId(): string
    {
        return $this->period->id;
    }

    /**
     * Get event payload for audit log.
     *
     * @return array<string, mixed>
     */
    public function toAuditLog(): array
    {
        return [
            'event' => 'vat_period_filed',
            'period_id' => $this->period->id,
            'company_id' => $this->period->company_id,
            'label' => $this->period->label,
            'period_type' => $this->period->period_type->value,
            'period_start' => $this->period->period_start->toDateString(),
            'period_end' => $this->period->period_end->toDateString(),
            'total_output_vat' => $this->period->total_output_vat,
            'total_input_vat' => $this->period->total_input_vat,
            'net_vat' => $this->period->net_vat,
            'amount_payable' => $this->period->amount_payable,
            'filing_reference' => $this->period->filing_reference,
            'filed_at' => $this->period->filed_at?->toIso8601String(),
            'filed_by' => $this->period->filed_by,
        ];
    }
}
