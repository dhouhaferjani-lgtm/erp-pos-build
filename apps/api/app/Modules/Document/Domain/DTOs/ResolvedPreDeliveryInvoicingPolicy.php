<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\DTOs;

use App\Modules\Document\Domain\Enums\PreDeliveryInvoicingPolicy;

/**
 * A resolved pre-delivery invoicing policy AND where it came from.
 *
 * The source is not decoration. A refusal that says only *"delivery is
 * required"* leaves the operator with nowhere to go; one that says *"required —
 * from your COMPANY setting"* versus *"from the TN country default"* versus
 * *"from the system fallback because your country has no row"* points at three
 * different fixes. It is also what the T25e audit stamp persists, so a later
 * audit can tell "posted under a country rule" from "posted before the policy
 * existed".
 */
final class ResolvedPreDeliveryInvoicingPolicy
{
    private const SOURCE_COMPANY = 'company';

    private const SOURCE_COUNTRY = 'country';

    private const SOURCE_SYSTEM = 'system';

    /**
     * @param  'company'|'country'|'system'  $source
     */
    private function __construct(
        public readonly PreDeliveryInvoicingPolicy $policy,
        public readonly string $source,
    ) {}

    public static function fromCompany(PreDeliveryInvoicingPolicy $policy): self
    {
        return new self($policy, self::SOURCE_COMPANY);
    }

    public static function fromCountry(PreDeliveryInvoicingPolicy $policy): self
    {
        return new self($policy, self::SOURCE_COUNTRY);
    }

    public static function fromSystemDefault(): self
    {
        return new self(PreDeliveryInvoicingPolicy::systemDefault(), self::SOURCE_SYSTEM);
    }

    public function requiresDeliveryFirst(): bool
    {
        return $this->policy->requiresDeliveryBeforeInvoicing();
    }
}
