<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use DomainException;

/**
 * Thrown at finalize when the counting still has lines whose resolution method
 * is `pending` (LEDGER C-14(iii)).
 *
 * This refusal used to be a bare `\InvalidArgumentException`. That type has no
 * render handler in `bootstrap/app.php`, so the reviewer got a 500 with no
 * guidance for the single most ordinary pre-finalize mistake — while both
 * sibling pre-finalize refusals in the same method
 * (`OverlappingCountingException`, `OpeningCostRequiredException`) already
 * extend `DomainException` and surface as a typed 422 `BUSINESS_ERROR`. Same
 * shape now, so the FE renders one refusal family instead of branching on a
 * server error.
 *
 * The pending line COUNT is carried as a field as well as being in the message:
 * the reviewer's next action ("go resolve N lines") depends on it.
 */
final class CountingUnresolvedItemsException extends DomainException
{
    /**
     * Translation key for the OPERATOR-facing message (gate r1 IMPORTANT-5).
     *
     * Rendered with `trans_choice` — the sentence is pluralised, and French
     * pluralises differently from English, so a plain `__()` would force the
     * noun agreement into the PHP constructor where no locale is known.
     *
     * `getMessage()` below stays English: it is the developer/log string, the
     * same split `CountingTransitionException::TRANSLATION_KEY` uses.
     */
    public const string TRANSLATION_KEY = 'inventory.counting.unresolved_items';

    public function __construct(public readonly int $unresolvedCount)
    {
        $noun = $unresolvedCount === 1 ? 'item' : 'items';

        parent::__construct(
            "Cannot finalize: {$unresolvedCount} {$noun} still pending resolution."
        );
    }

    /**
     * Placeholders for `trans_choice(self::TRANSLATION_KEY, $n, …)`, resolved at
     * RENDER time (after `SetLocale` has applied Accept-Language).
     *
     * @return array<string, int>
     */
    public function translationReplacements(): array
    {
        return ['count' => $this->unresolvedCount];
    }
}
