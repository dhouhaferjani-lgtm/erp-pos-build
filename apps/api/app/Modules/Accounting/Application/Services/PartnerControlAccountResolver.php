<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;

/**
 * Which accounts are PARTNER CONTROL accounts, and what an operator must do
 * instead of stating one in a GL opening (W4-4).
 *
 * Shared by the two sides of the rule so they cannot drift:
 *  - {@see AccountingOpeningService} refuses a GL opening row on a control account
 *    at validation AND again at post (gate r1 I-2 — `postBatch()` reads rows that
 *    are already `Valid`, so a batch validated before this shipped would otherwise
 *    post one after it shipped);
 *  - `Document\Application\Services\ArApOpeningService` refuses an AR/AP open-item
 *    batch when a GL opening has ALREADY stated the control accounts (gate r1 I-3 —
 *    the rule is only meaningful if it holds in both posting orders, and an opening
 *    batch locks with no in-product correction path).
 *
 * WHY CONTROL ACCOUNTS ARE OFF-LIMITS AT OPENING. Their cutover balance is the SUM
 * of the open items, one line per partner. A 411 balance with no partner dimension
 * is not a receivable in any useful sense: it cannot be collected, it never ages, it
 * never reaches a partner page, and `PartnerBalanceService` cannot see it because
 * that service sums posted journal lines by `partner_id` on a purpose-tagged
 * account. Accepting the balance in both places doubles the control account and
 * leaves the sub-ledger permanently disagreeing with the GL.
 */
class PartnerControlAccountResolver
{
    /**
     * Guards against a cycle in `parent_id` (a chart is a tree, but nothing in the
     * schema enforces that) and bounds the walk. Real charts are 4 levels deep.
     */
    private const MAX_ANCESTOR_DEPTH = 12;

    /**
     * The control purposes, mapped to the batch that owns their cutover balance.
     *
     * Keyed on the PURPOSE, never on a code: it is `411`/`401` in Tunisia and
     * France, `4100`/`4010` on the generic chart, and whatever the country template
     * assigned on a provisioned tenant.
     *
     * @var array<string, string>
     */
    private const CONTROL_PURPOSE_BATCHES = [
        SystemAccountPurpose::CustomerReceivable->value => 'AR open items',
        SystemAccountPurpose::SupplierPayable->value => 'AP open items',
    ];

    /**
     * The open-items batch that owns this account's opening balance, or null when
     * the account is not partner-control territory.
     *
     * Gate r1 I-4: the ANCESTOR CHAIN is walked, not just the account itself. Only
     * `401` and `411` carry the purposes in the TN chart; `4011 Fournisseurs -
     * Achats de biens` and `4017 Fournisseurs - Retenues de garantie` are children
     * of `401` and carry none (FR is the same shape with `4011` / `4111`). An
     * operator whose old trial balance is stated at the child level would otherwise
     * pass the guard and restate the payable one account down, where the sub-ledger
     * can never see it.
     *
     * `413 Clients - Effets à recevoir` and `416 Clients douteux` are deliberately
     * NOT caught: they hang off `41`, not off `411`, and they are distinct control
     * accounts with their own semantics — not a restatement of the open items.
     */
    public function batchLabelFor(Account $account): ?string
    {
        $current = $account;

        for ($depth = 0; $depth < self::MAX_ANCESTOR_DEPTH; $depth++) {
            $label = self::CONTROL_PURPOSE_BATCHES[$current->system_purpose?->value] ?? null;

            if ($label !== null) {
                return $label;
            }

            $parentId = $current->parent_id;

            if (! is_string($parentId) || $parentId === '') {
                return null;
            }

            $parent = Account::query()->whereKey($parentId)->first();

            if ($parent === null) {
                return null;
            }

            $current = $parent;
        }

        return null;
    }

    /**
     * Every account id in this company that IS a partner control account or sits
     * under one — the accounts a GL opening may not touch, and the accounts an
     * already-locked GL opening may have touched (I-3).
     *
     * Pass a purpose to narrow to one side.
     *
     * @return list<string>
     */
    public function controlAccountIds(string $companyId, ?SystemAccountPurpose $purpose = null): array
    {
        $purposes = $purpose !== null
            ? [$purpose->value]
            : array_keys(self::CONTROL_PURPOSE_BATCHES);

        $roots = Account::query()
            ->where('company_id', $companyId)
            ->whereIn('system_purpose', $purposes)
            ->pluck('id')
            ->all();

        if ($roots === []) {
            return [];
        }

        /** @var list<string> $ids */
        $ids = array_map(strval(...), $roots);
        $frontier = $ids;

        for ($depth = 0; $depth < self::MAX_ANCESTOR_DEPTH && $frontier !== []; $depth++) {
            $children = Account::query()
                ->where('company_id', $companyId)
                ->whereIn('parent_id', $frontier)
                ->whereNotIn('id', $ids)
                ->pluck('id')
                ->all();

            $frontier = array_map(strval(...), $children);
            $ids = [...$ids, ...$frontier];
        }

        return array_values($ids);
    }

    /**
     * The refusal an operator reads. Written once so the validate-time and the
     * post-time refusal cannot say different things.
     *
     * It names the catch-all-partner escape on purpose (gate r1, judgement-call
     * ruling). A tenant that arrives with a trial balance and no per-partner detail
     * is a real shape, and this refusal blocks their literal file — but it does not
     * block them: one `DIVERS CLIENTS` / `DIVERS FOURNISSEURS` partner with a single
     * open item per side is strictly better than a partnerless 411, because it ages,
     * it is collectible, and the sub-ledger ties. Openings lock with no in-product
     * correction path, so an operator who hits this at cutover and is not told the
     * way out has a support ticket, not a workaround.
     */
    public function refusalMessage(Account $account, string $batchLabel): string
    {
        $catchAll = $batchLabel === 'AR open items' ? 'DIVERS CLIENTS' : 'DIVERS FOURNISSEURS';

        return "Account '{$account->code}' is the partner control account for {$batchLabel}. ".
            "Its opening balance is posted by the {$batchLabel} batch (one entry per open item, ".
            'carrying the partner), not by the GL accounting opening — stating it here would double '.
            'the control account. Remove this line and import the open items instead. If your trial '.
            "balance has no per-partner detail, create a single catch-all partner (e.g. '{$catchAll}') ".
            'and import one open item for the whole balance: it still ages, it is still collectible, '.
            'and the sub-ledger still ties.';
    }
}
