<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Application\DTOs;

use App\Modules\Procurement\Domain\ProcurementPolicy;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class ProcurementPolicyData extends Data
{
    public function __construct(
        public readonly ?string $id,
        public readonly string $tenant_id,
        public readonly string $company_id,
        public readonly ?string $preset,
        public readonly string $bill_control_mode,
        public readonly string $match_mode,
        public readonly string $match_enforcement,
        public readonly string $variance_tolerance_percent,
        public readonly string $variance_tolerance_max_amount,
        public readonly bool $allow_receipt_first,
        public readonly bool $allow_invoice_first,
        public readonly bool $invoice_first_requires_approval,
        public readonly ?string $created_at,
        public readonly ?string $updated_at,
    ) {}

    public static function fromModel(ProcurementPolicy $policy): self
    {
        return new self(
            id: $policy->id,
            tenant_id: $policy->tenant_id,
            company_id: $policy->company_id,
            preset: $policy->preset?->value,
            bill_control_mode: $policy->bill_control_mode->value,
            match_mode: $policy->match_mode->value,
            match_enforcement: $policy->match_enforcement->value,
            variance_tolerance_percent: self::formatDecimal((string) $policy->variance_tolerance_percent, 2),
            variance_tolerance_max_amount: self::formatDecimal((string) $policy->variance_tolerance_max_amount, 3),
            allow_receipt_first: $policy->allowsReceiptFirst(),
            allow_invoice_first: $policy->allowsInvoiceFirst(),
            invoice_first_requires_approval: $policy->requiresInvoiceFirstApproval(),
            created_at: $policy->created_at?->toIso8601String(),
            updated_at: $policy->updated_at?->toIso8601String(),
        );
    }

    private static function formatDecimal(string $value, int $scale): string
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return $whole.'.'.str_pad(substr($fraction, 0, $scale), $scale, '0');
    }
}
