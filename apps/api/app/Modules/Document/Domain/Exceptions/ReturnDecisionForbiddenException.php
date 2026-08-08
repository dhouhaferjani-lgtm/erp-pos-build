<?php

declare(strict_types=1);

namespace App\Modules\Document\Domain\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;

/**
 * The caller may cancel the invoice but may not perform the goods leg.
 *
 * Plan CF CF-D8. The route keeps `can:invoices.cancel`, and the composite ADDITIONALLY
 * authorizes `deliveries.create` before creating the return note and
 * `deliveries.confirm` before confirming it — the exact abilities the standalone
 * `POST /return-notes` and `POST /return-notes/{id}/confirm` routes require.
 *
 * Default roles make this a no-op (an admin's ability set is a superset), so it is
 * easy to dismiss as dead code. It is not: roles are TENANT-EDITABLE, and a composite
 * endpoint silently performing a leg the caller could not perform standalone is a
 * privilege-escalation seam — precisely what the tenancy-authz gate exists to block.
 *
 * Extends `AuthorizationException` so it renders as a 403 (the user's data is fine;
 * their role is not) rather than a 422. It deliberately does NOT extend
 * `PermissionDeniedException`, which is `final`; the shared
 * `AccessDeniedHttpException` renderer in `bootstrap/app.php` recognises this type
 * through `getPrevious()` and emits {@see self::CODE} instead of the generic
 * `FORBIDDEN`, so the modal can say "ask a manager to record the goods return"
 * rather than "you cannot cancel this invoice" — two different remedies.
 */
final class ReturnDecisionForbiddenException extends AuthorizationException
{
    public const CODE = 'RETURN_DECISION_FORBIDDEN';

    public function __construct(public readonly string $ability)
    {
        parent::__construct('This action is unauthorized.');
    }
}
