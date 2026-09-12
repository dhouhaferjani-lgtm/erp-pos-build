<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

/**
 * The five — and only five — states a route may be in (spec 4.4.1 E-1):
 * every route is gated or public, with three named, mechanically-constrained
 * exceptions, and anything else is a gap.
 */
enum RouteCoverage: string
{
    case Gated = 'gated';

    /** Declared in the public allow-list. Named `Open` because `Public` is a reserved word. */
    case Open = 'public';

    case SelfService = 'self_service';

    /** An authenticated route returning an unconditional 410 with no mutation. */
    case Tombstone = 'tombstone';

    case Uncovered = 'uncovered';
}
