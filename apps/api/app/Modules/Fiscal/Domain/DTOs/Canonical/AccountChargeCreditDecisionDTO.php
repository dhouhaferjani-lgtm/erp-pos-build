<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\DTOs\Canonical;

use App\Modules\Fiscal\Domain\DTOs\FiscalPayloadArrayGuards;

final readonly class AccountChargeCreditDecisionDTO
{
    /**
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?string $creditAvailableAfter,
        public ?string $creditAvailableBefore,
        public ?string $creditLimit,
        public string $decision,
        public bool $limitExceeded,
        public bool $mirrorStaleAtAuthoring,
        public string $policyVersion,
        public string $stalePolicyAction,
        public array $warnings,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $warnings = FiscalPayloadArrayGuards::requireArray($data, 'warnings');
        /** @var list<string> $warnings */

        return new self(
            creditAvailableAfter: FiscalPayloadArrayGuards::optionalString($data, 'credit_available_after'),
            creditAvailableBefore: FiscalPayloadArrayGuards::optionalString($data, 'credit_available_before'),
            creditLimit: FiscalPayloadArrayGuards::optionalString($data, 'credit_limit'),
            decision: FiscalPayloadArrayGuards::requireString($data, 'decision'),
            limitExceeded: FiscalPayloadArrayGuards::requireBool($data, 'limit_exceeded'),
            mirrorStaleAtAuthoring: FiscalPayloadArrayGuards::requireBool($data, 'mirror_stale_at_authoring'),
            policyVersion: FiscalPayloadArrayGuards::requireString($data, 'policy_version'),
            stalePolicyAction: FiscalPayloadArrayGuards::requireString($data, 'stale_policy_action'),
            warnings: $warnings,
        );
    }
}
