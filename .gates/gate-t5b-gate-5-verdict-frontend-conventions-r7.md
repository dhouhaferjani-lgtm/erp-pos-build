I have everything I need. One finding proved decisive: the page this wave *replaces* formatted its dates; the replacement does not.

---

GATE VERDICT: REJECT

I could not execute the guardrails. `pnpm --filter @autoerp/web typecheck`, bare `pnpm`, and `npx tsc` were all denied by the sandbox in this session — the same block that r5 hit. Everything below is verified by reading code and git history; the toolchain claims are recorded as unverified, not accepted on trust.

**The two r5 blockers are genuinely fixed.** `mutable` is now threaded into `LinePanel` (`ReconciliationWorkspacePage.tsx:132`) and gates *rendering*, not just `disabled`, for unignore (`LinePanel.tsx:79`), suggestions + manual match (`:80-83`), unallocate (`:85`), the ignore fieldset (`:87`), create-from-line (`:89`), and the dialog (`:94`) — with real coverage at `LinePanel.test.tsx:114-128` and `ReconciliationWorkspacePage.render.test.tsx:65-70`. `playwright.smoke.config.ts` is restored to the staging-targeted, serialized config (the only delta from gate-4 is a removed comment). Profile-list invalidation is wired (`StatementListPage.tsx:116`).

## MAJOR

**1. The legacy cutover regresses date localization across the entire new surface.**
The page being deleted formatted every date: `git show t5b-gate-4:apps/web/src/features/treasury/BankReconciliationPage.tsx:57-59` is `new Date(dateString).toLocaleDateString('fr-TN', {…})`, applied at `:243`, `:422`, `:634`. The replacement renders raw ISO `YYYY-MM-DD` at six sites:

- `StatementListPage.tsx:65` — the period column, the primary identifier of every row
- `ReconciliationWorkspacePage.tsx:115` — page subtitle (`${period_start} → ${period_end}`)
- `ReconciliationWorkspacePage.tsx:129` — every line row in the list
- `LinePanel.tsx:75` — selected-line header
- `StatementUploadWizard.tsx:167` — the preview table's "Value date" column
- `ManualMatchSearch.tsx:42` — `movement.occurred_at.slice(0, 10)`, a raw string slice of a UTC timestamp that also silently drops timezone conversion

`formatDate` exists at `apps/web/src/lib/format.ts:148` with a comment stating it exists specifically so dates "render in the user's locale (e.g. `02/07/2026` on the FR UI) instead of a hardcoded US format"; it resolves `i18n.language` at `:163`. It is used in 36 feature files and **zero** times under `statements/`. For a product whose primary markets are France and Tunisia, a French accountant reconciling a bank statement now sees ISO dates everywhere the prior page showed `31/07/2026`. This was reported as MINOR in r5; establishing it as a regression against the replaced page raises it. Fix: route all six through `formatDate`, and convert `occurred_at` properly rather than slicing it.

**2. The live-smoke evidence is not re-runnable — it permanently consumes a shared fixture.**
`e2e/smoke/treasury-phase5b-reconciliation.smoke.ts:340-366` selects *any* bank repository that is active, GL-linked, and has `last_reconciled_at === null`, then the run permanently stamps it (asserted at `:807-808`). Nothing reopens or resets it. `:364` (`expect(repository, '…exists').toBeTruthy()`) therefore fails for this suite — and any other treasury suite with the same precondition — once the tenant's unreconciled GL-linked bank repositories are exhausted. No teardown exists for the payment method (`:393-410`), POS terminal (`:478-487`), cheque instruments (`:461-462`), or the agio expense (`:744-748`); only the duplicate-primer statement is voided (`:845-849`).

Compounding this, `REPORT.md:40-46` documents three manual, out-of-repo preconditions — a hand-run instrument-purpose account backfill, `QUEUE_CONNECTION=sync` on the API, and a hand-edited Vite proxy "restored after the run" — while the committed config defaults `baseURL` to `https://erp.otospex.dev` (`playwright.smoke.config.ts:17`). `test.skip` at `:32-35` means CI never runs this without `TREASURY_PHASE5B_API_BASE`. And the evidence trail disagrees with itself: this request states 26.2s, `REPORT.md:13` states 28.8s for the same six steps.

**3. No claimed guardrail evidence could be independently reproduced.**
Typecheck pass, `pnpm lint` exit 0, "8 files / 32 tests passed", React Doctor 92/100, and the smoke result all remain unverified assertions. This is environmental, not a code defect — but the persona requires re-running and trusting nothing, and I could not.

## MINOR

**4. Faked pluralization via concatenation.** `LinePanel.tsx:92` renders `{execution.produced_repository_movement_ids.length} {t('statements.workspace.movementsProduced')}`, EN value literally `"ledger movement(s) produced"` (`en/treasury.json:722`). Word order is hardcoded and the Arabic value (`"حركة دفتر منتجة"`, `ar/treasury.json:111`) cannot inflect — AR has six plural categories. Use `t(key, { count })` with plural suffixes.

**5. `ManualMatchSearch` still ships contradictory bounds and an unpropagated `disabled`.** `LinePanel.tsx:82` passes `disabled={pending || new Big(remaining).eq(0)}`, but `ManualMatchSearch.tsx:45` applies it only to the Allocate button — the search `Input` (`:41`), the `Select` (`:42`, disabled only when the list is empty), and the `MoneyInput` (`:44`) stay editable. With no movement selected or nothing remaining, `maxAmount` is `0.000` (`:34`) while `minimumAmount` is one ulp, `0.001` (`:35`), so the `MoneyInput` renders `min > max`.

**6. `MODULE_PERMISSIONS` gains a key that is a permission, not a module.** `usePermissions.ts:272` adds `'bank-statements.view': ['bank-statements.view']` so the sidebar lookup resolves; every other key in that map is a module identifier.

**7. Smoke selectors are coupled to English UI strings.** `smoke.ts:286-288` (`Tier ${tier}`), `:291` (`'Confirm match'`), `:675-678`, `:718`, `:749`, `:752`, `:760`, `:774`. The test never pins a locale, so it depends on the session defaulting to English — it would fail on an FR/AR default environment for reasons unrelated to the feature. `:279` also uses `page.locator('button').filter({ hasText: label }).first()`, tag-and-substring rather than role.

**8. Unchecked casts where the file's own guard pattern exists.** `StatementUploadWizard.tsx:225,226,227` do `event.target.value as StatementParserKey` / `as StatementDirectionConvention` / `as StatementDecimalFormat`, while `LinePanel.tsx:37` models the correct pattern with `isIgnoreReason`.

**9. The `testMatch` rationale comment was deleted.** `playwright.smoke.config.ts` lost the four-line comment explaining that without `testMatch` every `*.smoke.ts` is silently invisible to this config. Functionally inert, but it documents the one line preventing the whole smoke suite from disappearing.

## Verified clean

- **Canonical components**: `PageHeader`, `ListPageLayout`, `DataTable`, `Modal`/`ModalContent`/`ModalFooter`, `Input`/`Select`/`Textarea`/`Checkbox`/`MoneyInput`/`Button`/`StatusBadge`/`FormField`, `OffsetPagination`, `EmptyState`. No raw `<table>`, no raw form controls. RHF + zod with a translated message (`CreateFromLineDialog.tsx:30`).
- **Tokens**: grep for raw palette utilities across `statements/` returns nothing; no interpolated or composed Tailwind classes. Logical properties throughout (`ms-`/`me-`/`ps-`/`start-`/`text-start`/`text-end`).
- **Money**: decimal strings end to end. Only `Big` arithmetic; the sole `toFixed` is `Big.prototype.toFixed` (`status.ts:23`), and the sole `parseInt` is on `header_rows` (`StatementUploadWizard.tsx:110`), not money. The signed-allocation rule (`status.ts:14-18`) matches the backend.
- **Tenant scoping**: all five queries use `tenantScopedKey` (`StatementListPage.tsx:47,52`, `ReconciliationWorkspacePage.tsx:64,80,85`, `StatementReconciliationChips.tsx:26`); `queryScope.ts:19-20` requires `at(-2) === tenantId && at(-1) === companyId`, so `refreshWorkspace` cannot cross company scope. The profile invalidation is key-scoped.
- **Honest status**: `resolved_by_creation` presented as successful (`status.ts:30-32`); ignored lines contribute zero remaining (`status.ts:11`); `canComplete` requires every line resolved (`ReconciliationWorkspacePage.tsx:44`).
- **i18n completeness**: the `statements.*` subtree is structurally identical and genuinely translated across EN/FR/AR — I read all three in full. No untranslated English carry-over. (AR `treasury.json` is much smaller than EN/FR overall, but that gap is pre-existing and outside this diff.) `common:loading` resolves (`en/common.json:441`).
- **Baseline honesty**: exactly one removal, matching the deleted `BankReconciliationPage.tsx`; zero additions. No alias tables, no suppression comments, no renamed-equivalent literals.
- **Cutover**: `routes/index.tsx:1827,1838`, `Sidebar.tsx:276`, `FinanceHubPage.tsx:75-77` all point at `/treasury/statements` and gate on `bank-statements.view`. The only surviving mention of `BankReconciliationPage` is the negative assertion at `routes.test.tsx:69`; no dead imports of `api/reconciliation` or `hooks/useReconciliation` remain.
- **Smoke honesty where it counts**: zero `page.route`/`fulfill`/`abort` — no network mocking; no `waitForTimeout`; no try/catch swallowing. The Tier 3/4 (`:709-736`), balance-semantics (`:794-808`), and rejected-clear-provenance (`:810-843`) assertions genuinely hit the real API and assert on response bodies. No assertion can be silently bypassed once the suite runs.

VERDICT: REJECT

Before the ⑤b exit review: route all six date render sites through `lib/format.ts` `formatDate` (restoring the localization the deleted page had) and give the smoke a reset path for the bank repository it permanently reconciles — then re-run `pnpm lint`, `pnpm typecheck`, and the targeted Vitest in a session where they can actually execute, since none of the claimed evidence could be reproduced here.
