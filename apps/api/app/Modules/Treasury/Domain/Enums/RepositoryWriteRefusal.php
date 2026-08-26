<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

use App\Modules\Treasury\Presentation\Controllers\PaymentRepositoryController;

/**
 * Why a repository write is refused by an invariant the DATABASE owns.
 *
 * Not validation: every case here is a constraint that can only be answered by
 * the write itself, so the honest shape is "attempt, catch the SQLSTATE, and
 * translate it into something the caller can act on" — the same idiom
 * `ProductVariantService` uses to turn a `23505` race into a 422 instead of a
 * raw 500. {@see PaymentRepositoryController} is the only consumer today.
 */
enum RepositoryWriteRefusal: string
{
    /**
     * Campaign lane N-12 — `payment_repositories_one_drawer_per_location_type`.
     *
     * A location may own at most ONE active, GL-linked drawer of each type. The
     * resolver cannot distinguish two tills at one location (it breaks the tie
     * on the stable UUID and the loser silently accumulates nothing), so the
     * index states that invariant rather than leaving it to be discovered as a
     * reconciliation gap months later.
     *
     * Reachable from `store()` (a second till for the same location) and from
     * `update()` — including the one mutation the repositories screen actually
     * issues, `PATCH {gl_account_id}`, which moves a previously unlinked drawer
     * INTO the index's predicate beside an already-linked sibling.
     */
    case LocationDrawerAlreadyExists = 'LOCATION_DRAWER_ALREADY_EXISTS';

    /**
     * The PostgreSQL relation whose `23505` maps to this case.
     */
    public function constraintName(): string
    {
        return match ($this) {
            self::LocationDrawerAlreadyExists => 'payment_repositories_one_drawer_per_location_type',
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::LocationDrawerAlreadyExists => 'This location already has an active cash register or safe of this type. '
                .'A location can hold only one of each, because a receipt authored there resolves exactly one drawer.',
        };
    }
}
