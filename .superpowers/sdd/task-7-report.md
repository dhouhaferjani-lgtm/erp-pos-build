# Task 7 — Arabic treasury i18n backfill — Report

**Branch:** `chore/treasury-burndown` · **Worktree:** `../erp.treasury-burndown`
**File touched:** `apps/web/src/locales/ar/treasury.json` (additive only)

## Summary

Backfilled every Arabic key missing from the treasury namespace so that
`ar ⊇ en`, in Modern Standard Arabic financial register consistent with the
existing file (كشف بنكي / مطابقة / مستودع / أداة دفع / سطر / حافظة تحصيل).
Additive only: no existing ar value changed, no existing key reordered, and the
file retains exactly one top-level `repositories` block.

## Worklist derivation

Authoritative worklist = recursive leaf key-set diff `en − ar` for the treasury
namespace (throwaway script, not committed). The brief estimated ~190 keys in
`instruments.*`/`repositories.*`/`statements.*`, but the real gap was broader:
**517 missing leaf keys** across `dashboard`, `instruments`, `paymentMethods`,
`payments`, `remittances`, `repositories`, `smartPayment`, `splitPayment`,
`unifiedPayment`. The `statements.*` namespace was already complete in ar.

## Before / after key counts

| | en | ar (before) | ar (after) |
|---|---|---|---|
| leaf keys | 741 | 240 | 762 |

- Missing (en − ar) before: **517** → after: **0**
- Leaves added: **522** = 517 diffed keys + 5 Arabic-only plural sub-forms
  (`repositories.movements.subtitle_{zero,one,two,few,many}`), injected to
  complete the six-form Arabic plural family per the cashWidget precedent.
- Existing ar leaves preserved: **240 / 240 unchanged (0 changed, 0 removed).**

## Zero-missing proof

```
en leaf keys: 741
ar leaf keys: 762
MISSING in ar (en - ar): 0
EXTRA in ar (ar - en): 21   # legitimate Arabic plural sub-forms only
```

The 21 "extra" ar keys are all correct Arabic plural sub-forms English does not
carry: `cashWidget.types.*_{zero,two,few,many}` (12, pre-existing),
`statements.workspace.movementsProduced_{zero,two,few,many}` (4, pre-existing),
`repositories.movements.subtitle_{zero,one,two,few,many}` (5, added this task).

## Plural handling

- `repositories.movements.subtitle` uses the en base+`_other` pair. Provided the
  full Arabic six-form set (`_zero/_one/_two/_few/_many/_other`) plus the base
  key for i18next fallback, mirroring `cashWidget` / `movementsProduced`.
- ICU placeholders preserved verbatim (`{{count}}`, `{{amount}}`, `{{days}}`,
  `{{date}}`, `{{tier}}`, `{{method}}`, `{{label}}`, `{{invoiceNumber}}`,
  `{{number}}`, `{{percentage}}`, `{{maxAmount}}`, `{{balance}}`). Placeholder
  token-set parity check en↔ar: only the 4 pre-existing `_one` forms omit
  `{{count}}` by design (Arabic "one" category uses واحد/واحدة, not a digit) —
  the new `subtitle_one` follows the same correct pattern.

## Phase ② owed keys (4, instruments/banks area)

Identified and backfilled the instrument bank-info block that Phase ② left owed:
`instruments.bankInfo`, `instruments.bankName`, `instruments.bankBranch`,
`instruments.bankAccount` (backfilled alongside the broader
`instruments.deposit*` / `instruments.statuses.deposited` bank flow).

## Verification

- **JSON valid** — parses clean.
- **Duplicate-key check at every nesting level** — `json.load` with
  `object_pairs_hook` raising on any repeated key: PASS (all three locales).
- **Single top-level `repositories` block** — confirmed (top-level keys:
  payments, instruments, cashWidget, repositories, statements, splitPayment,
  unifiedPayment, smartPayment, dashboard, paymentMethods, remittances).
- **Key-set parity `ar ⊇ en`** — 0 missing (rerun of diff script).
- **Preservation** — 240/240 existing ar leaves unchanged.
- **Focused vitest** — `InstrumentEventLocales.test.ts`,
  `useTransferCash.test.ts`, `usePermissions.treasuryReconciliation.test.ts`:
  3 files / 8 tests PASS.
- **`pnpm typecheck`** — exit 0 (unchanged behavior).

## Concerns

None. Change is a locale-JSON-only, additive backfill; no code, types, or
placeholders altered.
