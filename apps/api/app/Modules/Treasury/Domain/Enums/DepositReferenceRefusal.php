<?php

declare(strict_types=1);

namespace App\Modules\Treasury\Domain\Enums;

use App\Modules\Treasury\Application\Projections\TreasuryDepositBridge;
use App\Modules\Treasury\Application\Services\DepositReferenceResolutionService;

/**
 * Why a back-office deposit's Treasury references cannot be projected.
 *
 * One case per invariant `TreasuryDepositBridge` (or the movement port it calls)
 * enforces AFTER the `DEPOSIT_RECEIPT` is sealed. Resolving these BEFORE the
 * seal is the whole point of W-5c D1 — a sealed receipt is immutable and has no
 * delete route, so every invariant that can only be discovered post-seal mints a
 * permanent orphan that over-states the customer's deposit history.
 *
 * **EVERY case here reads mutable state, so NO case closes its race.** Each is a
 * point-in-time read that the projection re-performs later, so checking it
 * pre-seal NARROWS the window to the width of the sealing transaction rather
 * than eliminating it — the projection runs after that transaction commits.
 * Orphans remain possible until the recoverability lane (R2-K-rec) lands a
 * disposition. (An earlier revision singled out three cases as "TOCTOU-shaped,
 * not input validation"; that distinction was wrong — a payment method or
 * repository deactivated between dialog-open and submit is just as much a
 * time-of-check/time-of-use vector, and is exactly what the round-1
 * `is_active` regression tests cover.)
 *
 * What DOES separate them is blame, not mechanism: for
 * {@see self::RepositoryFrozen}, {@see self::ActorNotActiveCompanyMember} and
 * {@see self::RepositoryBehindCheckpoint} the caller did nothing wrong and
 * cannot fix the request — only ops can (reopen the drawer, restore the
 * membership, reopen the statement).
 *
 * **Five cases are PATH-CONDITIONAL, and a deposit takes exactly one path.** The
 * three movement-port-derived cases ({@see self::RepositoryCurrencyMismatch},
 * {@see self::RepositoryFrozen}, {@see self::RepositoryBehindCheckpoint}) never
 * apply to a maturity tender, because the bridge returns before calling the port
 * for a cheque/effet. The two maturity-path cases
 * ({@see self::MissingInstrumentPortfolioAccount},
 * {@see self::InstrumentCurrencyMismatchesCompany}) apply ONLY to a cheque/effet,
 * because nothing else registers an instrument. Neither tail is empty. See the
 * parity table on {@see DepositReferenceResolutionService}.
 *
 * @see DepositReferenceResolutionService
 * @see TreasuryDepositBridge
 */
enum DepositReferenceRefusal: string
{
    /** No active payment method with this code — bridge `resolvePaymentMethod()`. */
    case PaymentMethodNotFound = 'payment_method_not_found';

    /** No active payment repository with this id — bridge `resolveRepository()`. */
    case RepositoryNotFound = 'payment_repository_not_found';

    /** Repository carries neither `gl_account_id` nor the legacy `account_id`. */
    case RepositoryMissingGlAccount = 'payment_repository_missing_gl_account';

    /** The repository's cash GL account is absent or deactivated. */
    case RepositoryGlAccountInactive = 'payment_repository_gl_account_inactive';

    /**
     * The tender currency is not the repository's currency — the movement port's
     * `CurrencyMismatchException` guard, which fires post-seal.
     */
    case RepositoryCurrencyMismatch = 'payment_repository_currency_mismatch';

    /**
     * The receiving repository is frozen (`payment_repositories.frozen_at`).
     *
     * R2-K-prev V1. `TreasuryDepositBridge::apply()` passes
     * `allowWhileFrozen: false` for a server-only DEPOSIT_RECEIPT, so
     * `TreasuryMovementService::record()` raises `RepositoryFrozenException`
     * post-seal. Routine ops reach this: a cash count freezes the drawer and a
     * back-office deposit submitted inside the freeze/reopen window cannot
     * project. TOCTOU — see the class docblock.
     */
    case RepositoryFrozen = 'payment_repository_frozen';

    /**
     * The recorded actor is not an ACTIVE member of the deposit's company.
     *
     * R2-K-prev V2, mirroring `TreasuryDepositBridge::resolveActorUserId()`,
     * which fails loud post-seal. The actor must resolve to a `User` or the
     * shared `PaymentAllocationService` skips the customer-advance journal
     * entry entirely, so tolerating a null actor is not an option. TOCTOU — see
     * the class docblock.
     */
    case ActorNotActiveCompanyMember = 'actor_not_active_company_member';

    /**
     * The receiving repository is reconciled through today or later
     * (`payment_repositories.last_reconciled_at`), so the movement would land
     * inside a closed period.
     *
     * R2-K-prev, authz gate I-2. `TreasuryDepositBridge.php:273` passes
     * `allowBehindCheckpoint = ! isServerOnly()`, constant FALSE for a
     * DEPOSIT_RECEIPT, so `TreasuryMovementService::checkpointDisposition()`
     * raises `RepositoryCheckpointException` post-seal. Reachable with no
     * privilege at all: `StatementCompletionService` stamps the checkpoint at
     * end-of-day of the statement's `period_end` and nothing bounds `period_end`
     * above, so confirming a statement through today orphans every subsequent
     * same-day cash deposit. TOCTOU — see the class docblock.
     */
    case RepositoryBehindCheckpoint = 'payment_repository_behind_checkpoint';

    /**
     * The company has no active portfolio account for this instrument kind, so
     * the cheque/effet has nowhere to post.
     *
     * MATURITY-PATH invariant (R2-K-prev round-2 gate).
     * `HandlesMaturityTenderLeg::portfolioAccountId()` calls
     * `InstrumentAccountResolver::resolveOrFail()`
     * (`InstrumentAccountResolver.php:35-39`), which raises
     * `MissingInstrumentAccountException` post-seal. Ops-realistic: a company
     * whose chart of accounts never got `5312`/`5112` (checks to collect) or
     * `413` (effects receivable) accepts cheques from the UI and orphans every
     * one of them.
     */
    case MissingInstrumentPortfolioAccount = 'missing_instrument_portfolio_account';

    /**
     * The tender currency is not the COMPANY currency.
     *
     * MATURITY-PATH invariant (R2-K-prev round-2 gate), mirroring
     * `InstrumentLifecycleService::receive()`
     * (`InstrumentLifecycleService.php:74-80`), which throws post-seal.
     *
     * **The operand differs from {@see self::RepositoryCurrencyMismatch}** —
     * that one compares against the RECEIVING REPOSITORY's currency, this one
     * against the COMPANY's. They are not interchangeable and neither implies
     * the other, which is why the maturity path needs its own case rather than
     * reusing the movement-path one.
     */
    case InstrumentCurrencyMismatchesCompany = 'instrument_currency_mismatches_company';

    public function message(): string
    {
        return match ($this) {
            self::PaymentMethodNotFound => 'The selected payment method does not exist or is no longer active.',
            self::RepositoryNotFound => 'The selected payment repository does not exist or is no longer active.',
            self::RepositoryMissingGlAccount => 'The selected payment repository has no cash GL account configured, so the deposit cannot be posted to the ledger.',
            self::RepositoryGlAccountInactive => "The selected payment repository's cash GL account is missing or inactive, so the deposit cannot be posted to the ledger.",
            self::RepositoryCurrencyMismatch => 'The deposit currency does not match the currency held by the selected payment repository.',
            self::RepositoryFrozen => 'The selected payment repository is frozen (a cash count or audit is in progress), so it cannot receive a deposit until it is reopened.',
            self::ActorNotActiveCompanyMember => 'The recording user is not an active member of this company, so the deposit cannot be posted to the ledger.',
            self::RepositoryBehindCheckpoint => 'The selected payment repository is reconciled through today, so a deposit dated today would fall inside a closed period. Reopen the latest statement first.',
            self::MissingInstrumentPortfolioAccount => 'This company has no active portfolio account configured for cheques or bills of exchange, so the instrument cannot be registered.',
            self::InstrumentCurrencyMismatchesCompany => 'A cheque or bill of exchange must be denominated in the company currency.',
        };
    }
}
