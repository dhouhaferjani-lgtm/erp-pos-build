Verified. Adding one finding to the record; the verdict is unchanged.

## Addendum to GATE VERDICT: REJECT

**m9 (MINOR, new) — Two dynamic i18n key sources are untyped `string`, defeating compile-time protection**

`apps/web/src/features/treasury/statements/api.ts:85` declares `source_type: string` and `:70,88` declare `reason_code: string | null`, yet both are interpolated straight into translation keys — `ManualMatchSearch.tsx:42` (`statements.workspace.sourceType.${movement.source_type}`) and `SuggestionList.tsx:30` (`statements.workspace.suggestions.reasons.${suggestion.reason_code}`).

This is an asymmetry within the same file: `action_type` *is* a literal union (`StatementActionType`, `api.ts:34,65,104`), and `target_type` at least gets a runtime guard (`LinePanel.tsx:48-50`). These two get neither. Adding a `MovementSourceType` case backend-side, or a new `reasonCode` literal in `StatementSuggestionService.php`, will render a raw key string in the UI with no typecheck failure and no lint failure — the exact silent-fallthrough class of bug the reviewer protocol treats as invisible to tests. *Fix:* tighten both to literal unions in `api.ts` so a new backend value fails the build.

**Confirmation of the i18n "verified clean" line:** an independent exhaustive pass extracted 127 distinct `statements.*` keys — including all dynamic values enumerated from the TS unions and, where the TS type was not literal, from the backend enums (`MovementSourceType`) and `StatementSuggestionService.php` `reasonCode` literals — and found **127 leaf keys in each of en/fr/ar with an empty three-way set difference**: no missing keys, no orphans. The only EN/FR byte-identical value is `statements.workspace.create.notes` (`"Notes"`), an intentional cognate.

The 85-line-per-locale `common.json` removal is also confirmed genuinely dead: the removed top-level `reconciliation` object was consumed solely by `BankReconciliationPage.tsx`, deleted in `1f6f201eb`. Surviving `reconciliation.*` hits belong to the unrelated `counting.reconciliation.*` path in inventory-counting, and `permissions.modules.reconciliation` is an untouched separate leaf.

VERDICT: REJECT

Before the ⑤b exit review, unchanged and blocking: restore a Playwright config carrying `testMatch: /.*\.smoke\.ts$/` so the six orphaned `*.smoke.ts` files and `.github/workflows/smoke-test.yml:53` are runnable again, then re-run the live smoke under that committed config plus `pnpm lint` / `pnpm typecheck` / the targeted statement Vitest with raw output attached — and correct `REPORT.md` to claim only what the suite actually asserts.

One caveat on my own coverage, stated plainly: I could not execute a single guardrail — `typecheck`, `lint`, both audit scripts, and Vitest were all refused by this environment, so M1 stands as an unresolved verification gap rather than a defect I disproved. Everything above is static verification plus git archaeology.
