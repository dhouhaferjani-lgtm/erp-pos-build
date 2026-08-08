<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

use App\Shared\Exceptions\PermissionDeniedException;

/**
 * Authorize a Gate ability from a Domain service, without a facade call at the
 * call site.
 *
 * Plan CF CF-D8. The composite cancel flow has to check `deliveries.create` and
 * `deliveries.confirm` per leg, and it has to do so INSIDE the service — the route's
 * `can:invoices.cancel` cannot express "and also the goods leg", and putting the
 * checks in the controller would leave the service usable as an escalation path.
 *
 * A contract rather than the `AuthorizesAbility` trait so the dependency is explicit
 * in the constructor (rule 13) and a test can substitute a denying authorizer without
 * building a whole permission fixture.
 */
interface AbilityAuthorizerInterface
{
    /**
     * @throws PermissionDeniedException When the current actor lacks the ability.
     */
    public function authorize(string $ability): void;

    public function allows(string $ability): bool;
}
