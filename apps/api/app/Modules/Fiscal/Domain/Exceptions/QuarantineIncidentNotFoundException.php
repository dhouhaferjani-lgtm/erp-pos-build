<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use RuntimeException;

/**
 * Thrown by `QuarantineIncidentResolutionService::resolve()` when no
 * `fiscal_event_quarantine` row with the given id exists WITHIN THE ACTING
 * USER'S TENANT.
 *
 * ES-17 clause 17-F. The controller already scopes its lookup by
 * `tenant_id`, so in practice this fires only on a race (the row was removed
 * between the controller's read and the service's locked re-read). It exists as
 * a distinct type from `QuarantineAlreadyResolvedException` so that a
 * cross-tenant or missing row can never be reported to the caller as "already
 * adjudicated" — that would tell one tenant something true about another
 * tenant's incident.
 */
final class QuarantineIncidentNotFoundException extends RuntimeException {}
