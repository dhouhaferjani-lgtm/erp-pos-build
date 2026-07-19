GATE VERDICT: APPROVE

## Fable escalation adjudication — gate t5a-gate-4, frontend conventions

**Scope:** `67fc81173..3b45df13e` (HEAD) across the full listed surface, both prior Opus verdicts, and the backend sources they cite. **Evidence caveat, stated plainly:** every attempt to execute Vitest in this session was denied by the sandbox (three forms, including an unsandboxed retry), and the verdict-file write was blocked — the same execution constraint both Opus rounds hit. The reported 16/16 Vitest / lint 0 / typecheck / 9/71 backend counts are therefore **not independently reproduced**. This adjudication is static and code-grounded; the final commit is two production lines plus three test lines, small enough that static verification is conclusive for the gate items.

### Gate-item verification

**1. Cash mode shows non-paper methods, excludes cheque/effet — VERIFIED.** `PayExpenseDialog.tsx:80-82` filters cash mode to `instrument_kind === null || instrument_kind === 'other'`, exactly mirroring the backend non-paper predicate at `PaymentInstrumentController.php:172`. The fixture carries all four kinds including `{ id: 'method-other', name: 'Direct Debit', instrument_kind: 'other' }` (`PayExpenseDialog.test.tsx:26-31`); `:97` pins Direct Debit **present in cash**, `:98-99` pin Cheque/Effet absent, `:141` pins Direct Debit **absent in instrument mode**. The `=== null` identity check is sound because `formatMethod` always emits the key (`PaymentMethodController.php:236`). Seeded FR `DIRECT_DEBIT` (`PaymentMethodSeeder.php:260-264`) is payable again — R2's MAJOR is closed.

**2. Paper mode narrows to the selected kind; stale state cleared — VERIFIED.** Instrument filter at `PayExpenseDialog.tsx:81`; bank-account-only repositories `:77-79`; mode switch clears repository+method `:91-94`; kind switch clears method+maturity `:96-101`; domain backstop rejects kind-mismatched methods (`ExpenseService.php:649-650`).

**3. Effet → cheque phantom maturity — VERIFIED, four layers.** State clear (`PayExpenseDialog.tsx:99`), payload guard `kind === 'effet' ? … : null` (`:114-116`), backend `required_if:instrument.kind,effet` (`PayExpenseRequest.php:80-84`), domain guard before mutation (`ExpenseService.php:637-639`). The regression test drives the full effet→enter-date→cheque→submit switch and asserts nested `maturity_date === null` (`PayExpenseDialog.test.tsx:190-210`); backend 422-before-mutation counterpart at `ExpensePayByInstrumentTest.php:180-209`. Explicit cheque maturity via raw API remains accepted by design (the backend suite itself sends one at `:364`; treasury-approved post-dated-cheque semantics) — that is not the phantom-carryover defect, and the UI can no longer produce it.

**4. BankPicker fallback — VERIFIED, backward compatible.** `allowFallback = true` default (`BankPicker.tsx:19,33`) gates the sole fallback entry point (`:218-231`); the dialog opts out with `allowFallback={false}` + pinned `isFallback={false}` (`PayExpenseDialog.tsx:275-279`), making the unsinkable free-text branch unreachable. Repo-wide grep confirms the dialog is the only `false` site; the three legacy callers keep the default and persist the typed name. The dialog test renders the **real** picker with only `useBanks` mocked (`PayExpenseDialog.test.tsx:8,40`), drives the genuine combobox (`:146-148`), and proves `bank.notListed` never renders (`:212-219`); `BankPicker.test.tsx:87-92` pins the prop.

**5. Contracts and hygiene — VERIFIED.** Cash payload is the exact legacy shape, no `mode`/`instrument`/amount (`PayExpenseDialog.tsx:120-124`, pinned with `.not.toHaveProperty('amount')` at test `:115-124`); instrument payload is the nested strings/nulls union (`types/index.ts:199-217` ↔ `PayExpenseRequest.php:48-86`); zero numeric coercion in the touched files; `git diff 67fc81173..HEAD` over `apps/web/tools/`, `eslint.config.js`, `designTokens.ts` is empty (no absorbed debt); tenant-scoped keys on both queries (`InstrumentListPage.tsx:126,144`); no locale files touched since `67fc81173`, so R1's EN/FR/AR key-by-key verification stands.

**6. Direction-grouped échéancier — VERIFIED.** Counts filter `row.direction === maturityDirection && row.bucket === bucket` over the unpaginated payload; totals read from server `meta.buckets[…][total_in|total_out]` / `meta.grand_total` — display-only (`InstrumentListPage.tsx:316-357`), covered by the filters test (direction fixtures, bucket badges, both schedule regions).

### Findings

- **MINOR (carried, acknowledged R1+R2):** `useExpenses.ts:249-254` — bare `['instruments']`/`['maturing-instruments']` invalidation prefixes over-invalidate across tenant-suffixed keys; wasted refetch, not a leak; consistent with the adjacent pre-existing pattern.
- **MINOR (carried):** `InstrumentListPage.tsx:324` — redundant `role="region"` on a labelled `<section>`.

No BLOCKER. No MAJOR. Every finding from both REJECT rounds is conclusively fixed in code with regression tests that would fail on reintroduction.

VERDICT: APPROVE

Before the ⑤a exit review: reproduce the four focused Vitest suites and scoped lint in an execution-permitted environment to convert this static approval's test evidence from reported to reproduced (the two carried MINORs may ride along or be swept there).
