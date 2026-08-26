<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use App\Modules\Inventory\Domain\Enums\CountingStatus;
use DomainException;

/**
 * Thrown when an inventory-counting status transition is refused — either the
 * edge does not exist in `CountingStatus::allowedTransitions()`, or a locked
 * re-read found the counting somewhere other than the status the caller
 * observed before it took the lock.
 *
 * Replaces the bare `\InvalidArgumentException` that `InventoryCounting::
 * transitionTo()` used to throw. That type has no render handler in
 * `bootstrap/app.php`, so a lost finalize race or a late count submission
 * surfaced to the counter as a 500. Extending `DomainException` keeps it in the
 * module's existing shape (`TransferStateException`,
 * `OverlappingCountingException`) and `WorkOrderTransitionException` in
 * Workshop.
 *
 * Gate r1 MINOR-5: it carries its own render handler, registered ABOVE the
 * generic `DomainException` one, so the frontend can branch on WHY. The two
 * cases need different UI — "someone else already finalized this count, reload"
 * is a recoverable refresh, while "this phase has moved on" sends the counter
 * back to the worklist — and both are indistinguishable under a shared
 * `BUSINESS_ERROR`. `currentStatus`/`attemptedStatus` are surfaced as fields
 * rather than buried in prose, and the message no longer leads with a UUID.
 */
class CountingTransitionException extends DomainException
{
    /**
     * Stable, machine-readable refusal code. One code with status fields, not a
     * code per status pair: the pair IS the discriminator and the FE reads it
     * from the fields.
     */
    public const CODE = 'COUNTING_TRANSITION_REFUSED';

    /**
     * Translation key for the OPERATOR-facing message (LEDGER C-14(iv)).
     *
     * `getMessage()` below is the DEVELOPER/log string and stays English by
     * design — it is what lands in logs and in `expectExceptionMessage` pins.
     * The operator text is built by the render handler in `bootstrap/app.php`
     * from this key + `translationReplacements()`, so the 422 body is rendered
     * in the requesting tenant's locale (house rule 11). Same shape as
     * `InsufficientStockForFulfilmentException::TRANSLATION_KEY`.
     */
    public const string TRANSLATION_KEY = 'inventory.counting.transition_refused';

    public function __construct(
        public readonly string $countingId,
        public readonly CountingStatus $currentStatus,
        public readonly CountingStatus $attemptedStatus,
    ) {
        parent::__construct(
            "This counting is {$currentStatus->value} and cannot move to {$attemptedStatus->value}."
        );
    }

    /**
     * Placeholders for `__(self::TRANSLATION_KEY, …)`, resolved at RENDER time
     * (i.e. after `SetLocale` has applied the request's Accept-Language), never
     * at construction time.
     *
     * Both statuses are rendered from the `inventory.counting.status.*` labels —
     * the same values `apps/web/src/locales/<locale>/inventory.json` uses — and
     * fall back to the raw enum value if a locale has no catalogue entry, so a
     * missing translation degrades to today's behaviour instead of printing a
     * key.
     *
     * @return array<string, string>
     */
    public function translationReplacements(): array
    {
        return [
            'current' => self::statusLabel($this->currentStatus),
            'attempted' => self::statusLabel($this->attemptedStatus),
        ];
    }

    private static function statusLabel(CountingStatus $status): string
    {
        $translated = trans('inventory.counting.status.'.$status->value);

        return is_string($translated) && $translated !== 'inventory.counting.status.'.$status->value
            ? $translated
            : $status->value;
    }
}
