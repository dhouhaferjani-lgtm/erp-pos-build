<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

/**
 * The `allow` pre-delivery invoicing policy was resolved, and the system cannot
 * honour it.
 *
 * ── THIS IS A FAIL-CLOSED REFUSAL, NOT A BUG ── (Wave 3 T25a, D-18′)
 * `PreDeliveryInvoicingPolicy::Allow` is admitted by the enum and by the
 * database CHECK so that enabling it later needs no DDL on a live tenant
 * database. It is refused HERE because honouring it would mean posting a goods
 * invoice before delivery, which under NCT 03 is a **liability** (472 / 419) and
 * not revenue — and this codebase has no deferred-revenue machinery to post it
 * with. Permitting it would seal a mis-stated revenue entry into the fiscal hash
 * chain, irreversibly.
 *
 * Reaching this exception therefore means a row was stamped `allow` out of band.
 * The message names both preconditions for lifting the refusal.
 */
final class PreDeliveryInvoicingNotSupportedException extends \DomainException
{
    public static function forSource(string $policySource): self
    {
        return new self(sprintf(
            'The pre-delivery invoicing policy "allow" is not supported (resolved from: %s). '
            .'Enabling it requires (1) the 472/419 deferred-revenue posting machinery, which does not '
            .'exist in this system — a definitive goods invoice issued before delivery is a liability '
            .'under NCT 03, not revenue — and (2) a ruling on batch-tracked products, whose batch '
            .'attribution has no meaning before an exit movement. Until both exist, every country and '
            .'company resolves to "require_delivery_first".',
            $policySource,
        ));
    }
}
