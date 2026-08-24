<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * What resolving a free-text `category_name` to a local `categories` row did.
 *
 * W2-3: the products import used to LOOK UP the name and silently drop it on a
 * miss. Callers now get an explicit outcome so a state change (a brand-new
 * category conjured from a spreadsheet cell, or a soft-deleted one brought back)
 * can be reported to the operator instead of happening behind their back.
 */
enum CategoryResolutionOutcome: string
{
    /** An existing, live category already carried this name (or its slug). */
    case Matched = 'matched';

    /** No category carried this name — one was created. */
    case Created = 'created';

    /** A soft-deleted category held the slug and was restored to take the link. */
    case Restored = 'restored';

    /**
     * True when the resolution changed master data and the operator should be told.
     */
    public function isStateChange(): bool
    {
        return $this !== self::Matched;
    }
}
