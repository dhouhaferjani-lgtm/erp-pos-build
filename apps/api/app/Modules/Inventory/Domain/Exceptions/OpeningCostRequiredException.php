<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use DomainException;

/**
 * Thrown at finalize when one or more onboarding first-count lines would post as
 * an opening balance but have no resolvable positive cost (D3, spec §5).
 *
 * The cost gate is PRE-finalize by design: a cost-less opening must be blocked
 * BEFORE the counting transitions to Finalized, because Finalized has no
 * outgoing transition and therefore no re-post path — a cost backfilled after
 * finalize could never post. This exception forces the reviewer to supply (or
 * explicitly zero) each flagged line's cost first.
 *
 * Extends DomainException so it is mapped to a 422 BUSINESS_ERROR by the generic
 * DomainException handler in bootstrap/app.php, matching OverlappingCountingException.
 */
final class OpeningCostRequiredException extends DomainException
{
    /**
     * @param  list<string>  $productLabels  Human-readable "name (sku)" labels of the offending products.
     */
    public function __construct(public readonly array $productLabels)
    {
        $list = implode(', ', $productLabels);

        parent::__construct(
            'Cannot finalize: the following onboarding products need an opening cost '.
            '(entered or explicitly zeroed) before finalizing: '.$list
        );
    }
}
