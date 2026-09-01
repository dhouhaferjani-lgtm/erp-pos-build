# Lane L-2 — adversarial code review, round 1

**Reviewer:** frontend-conventions-reviewer (Opus)
**Date:** 2026-09-01
**Subject:** `fix/l2-fe-hygiene` in `.worktrees/l2-fe`, **uncommitted working tree** on base `d1a056465` (local `dev`)
**Brief:** `docs/sessions/session-L-wave2-po-2026-09-01/LANE-L2-FE-HYGIENE-BRIEF.md` (r2, ACCEPT-WITH-CONDITIONS)
**Lane summary:** `docs/sessions/session-L-wave2-po-2026-09-01/lane-l2-summary.md`

## Verdict

**MERGE** — with 7 MINOR findings, none blocking. One out-of-scope defect (F-W2-41) is confirmed still live and is the immediate follow-up.

Everything the r2 brief gated is implemented as written. I reproduced the lane's green evidence, and — independently — its **RED** evidence by replaying the three new test files against the base tree extracted with `git archive d1a056465` (see "Commands I ran"). The TDD claim is honest: **8 failures on base, 0 after the fix**, matching the summary's claimed 4 / 2 / 2 split exactly. No baseline was rewritten; no detector was evaded.

---

## 1. Evidence replay (I ran it; counts are verbatim)

| Check | Claimed in summary | Measured by me | Verdict |
|---|---|---|---|
| Vitest, three named files | — (summary ran the 3 *directories*) | **3 files / 42 tests passed** | ✅ |
| Vitest, summary's path set | 7 files / **80 passed** | 7 files / **80 passed** | ✅ claim true |
| RED on base (new tests vs. base production code) | 4 + 2 + 2 = 8 red | **8 red**, same three files, same messages | ✅ TDD real |
| `pnpm typecheck` | exit 0 | **exit 0** | ✅ |
| scoped `eslint` (7 changed files) | 0 errors / 76 warnings (directory scope) | **0 errors / 26 warnings** (file scope) | ✅ 0 errors |
| `node tools/audit-tanstack-keys.mjs` | 0 / 0 / 0 | **`Gate C … : 0` — `0 acknowledged, 0 new, 0 stale`** | ✅ |
| `node tools/audit-design-system.mjs` | fails on 13 new + 11 stale, **all `ImportWizardPage.tsx`** | **identical: 809 violations, 796 acknowledged, 13 new, 11 stale — every line `src/features/import/pages/ImportWizardPage.tsx`** | ✅ claim true |
| React Doctor `--scope changed` | Score 92/100, 1 warning (`ConfirmDialog.tsx:56` `prefer-html-dialog`) | share URL emitted `…&s=92&w=1&f=1` → **score 92, 1 warning** | ✅ corroborated |

### Baseline honesty (protocol step 2) — CLEAN
`git diff --stat -- apps/web/tools/audit-design-system-baseline.json apps/web/src/features/import/pages/ImportWizardPage.tsx` is **empty**. The design-system ratchet failure is inherited from base `dev`; the lane neither caused it nor absorbed anything with `--write-baseline`. The two content-keyed `Dashboard.tsx` baseline entries (the onboarding-banner buttons) are the only Dashboard rows in the baseline and are byte-untouched — the audit reports **0 new and 0 stale for `Dashboard.tsx`**, so the card-wrapping edit is ratchet-safe as the brief predicted.

### Mechanism audit (protocol step 3) — CLEAN
No alias table, no suppression comment carrying a detector keyword, no renamed-but-equivalent literal. The dedupe is a plain `Map` fold over already-typed data; the two new attributes are `data-*` (invisible to the design-system, i18n and quantity detectors, correctly so). No query key changed (`audit:keys` 0/0/0 confirms).

---

## 2. Brief conformance, per task

### Task 1 — F-W2-37 dashboard gating: CONFORMANT
- `Dashboard.tsx:143` `… && hasPermission('documents.view')`; `:152` `… && hasPermission('payments.view')` — exact keys, exact shape as the already-gated onboarding query at `:111`.
- Cards **hidden, not empty**: `Dashboard.tsx:348` and `:406` wrap each card in the same predicate. No "no payments" panel for an unpermitted user.
- `/dashboard/stats` left **ungated on the FE** with the backend-debt comment at `Dashboard.tsx:131-133` naming `L-2-FU-dashboard-stats-ungated`, owner lane L-14. It does **not** say "no gap". Conformant to L2-G1-04.
- Audit table re-verified by me against the live route files: `app/Modules/Dashboard/routes.php:20-21` carries **no** `can:`; `Document/Presentation/routes.php:80-82` carries `can:documents.view`; `Treasury/Presentation/routes.php:181-183` carries `can:payments.view`. All five rows of the summary's reproduced table are true.
- Fixture repaired, **not** the gate: `dashboard.test.tsx:104` `seedAuth({ roles: ['admin'] })`. `admin` legitimately holds both permissions (`permissionsMap.generated.ts:64`, `:157`). The predicates were not weakened.
- New arms assert data meaning (mock call list + DOM absence + no lingering skeleton), not status codes.

### Task 2 — F-W2-38 ConfirmDialog a11y: CONFORMANT
- `ConfirmDialog.tsx:46-47` `useId()` × 2 sit **above** the `if (!isOpen) return null` early return at `:49` — no `rules-of-hooks` exposure.
- `role="dialog"` + `aria-labelledby` + `aria-describedby` on the **inner panel** at `:55-59`, ids bound to the existing `<h3>` (`:85`) and `<p>` (`:86`). Backdrop untouched.
- **No `aria-modal`**, and the omission is pinned by a negative test with the ticket name in a comment: `ConfirmDialog.test.tsx:63-69`. Correct per the owner-adjacent principle that UI must not overstate a guarantee it does not enforce — there is no focus trap, no Escape handler, no background `inert`.
- `apps/web/e2e/**` edits are **exactly** the two authorised lines: `e2e/composite-item-delete.spec.ts:98` and `:164`, both narrowed to `page.getByRole('dialog')`, nothing else in the file. Verified by `git diff` hunk headers (`@@ -95,7 +95,7 @@`, `@@ -161,7 +161,7 @@`).
- `SalesOrderDetailPage.tenantScope.test.tsx:474` did **not** need the pre-authorised `within(...)`: I re-ran the file, 9/9 green, and the file is unmodified.

### Task 3 — F-W2-39 match table: CONFORMANT, and data-meaning-correct
- Both pre-fix rows' values are recorded in the summary (table at `lane-l2-summary.md:152-155`): both rows `11.0000 / 7.0000 / 6.0000 / 3.0000`, byte-identical aggregates, variance `false` then `true`. That measurement legitimately selects the default collapse and rules out both the composite key and the escalation.
- I independently verified the payload's grain at `apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:718-725`: `ordered`/`received`/`invoiced`/`matchable` **all** read `$poLine` (`:720-723`); only `price_variance` (`:715-716`) is per invoice line. Collapsing loses nothing.
- Implementation `SupplierInvoiceDetailPage.tsx:167-177`: a plain `const` **below** the early return at `:114` — the brief's authorised non-hook alternative, so no `rules-of-hooks` error. `Map` insertion order preserves first appearance; `price_variance` is OR-ed at `:173`.
- `keyExtractor` (`:615`) and the row `data-testid` (`:184`) are **unchanged**; `SupplierInvoiceDetailPage.test.tsx:409`'s `getByTestId('match-row-po-line-1')` passes unmodified. **No index keys anywhere.**
- The authorised markup addition is exactly two attributes on the two icons — now at `:213-215` (AlertTriangle) and `:219-221` (CheckCircle2); the brief's `:202`/`:204` shifted because the dedupe block was inserted above. Same elements, no third site.

---

## 3. Conventions (rules 3 / 11 / 14 / 18 / 19)

- **Rule 3** — no new `any`. `typecheck` exit 0. `seedAuth`'s `permissions?: string[]` is typed, and the `exactOptionalPropertyTypes` fix at `seedAuth.ts:41` (`...(permissions === undefined ? {} : { permissions })`) is the correct spread-omit, not a cast.
- **Rule 11** — zero new user-facing strings; the only additions are `data-*` attributes.
- **Rule 14 / tenant keys** — no query key touched; `audit:keys` 0/0/0.
- **Rule 19** — no `parseFloat`/`Number()` introduced; quantities still render through `formatQuantity` (`SupplierInvoiceDetailPage.tsx:185,193,199,205`). `String(row.price_variance)` is a boolean, not money.
- **Rule 18** — see L2-C1-04 (MINOR).
- **Owner principles** — "one main element per screen": the change *removes* competing panels for limited principals rather than adding accents. "Dead controls hidden until real": cards are hidden, not disabled-with-a-toast. No brand string introduced. No refund/sales blending. No gate that fails open (the new predicates fail **closed** on the FE while the backend `can:` stays authoritative).
- **Convention 09 (second-of-everything)** — correctly declared not applicable: no catalogue entity, no migration, no unique key, no importer, no seeder. The three synthetic principals are the meaningful "second" for a presentation lane.
- **Convention 11 (one surface per concept)** — no new noun, no new FE type; `PerLineMatch` is reused, and the pre-existing hand-rolled-type debt is ticketed, not widened.

---

## 4. Blast radius (protocol step 4)

I did **not** trust the summary's consumer list. I re-grepped and ran the intersections myself:

- **12 files** from the summary's own list: `pnpm vitest run` → **12 files / 95 tests passed**, including `SalesOrderDetailPage.tenantScope.test.tsx` (9/9) and `PartnerPicker.test.tsx` (13/13).
- **8 files the summary's grep missed** (see L2-C1-01): **7 passed**, 1 pre-existing red unrelated to this lane (L2-C1-08).
- No production code queries `[role="dialog"]` anywhere in `src` — the new role cannot break a runtime selector.
- `Modal.tsx:141` remains the only `aria-modal` surface; a `ConfirmDialog` nested in a `Modal` would now yield two `dialog` roles, but every current spec that could hit that state passed.

---

## 5. Findings

### `L2-C1-01` [MINOR] — the mandated consumer grep was narrower than the risk
`lane-l2-summary.md:256-276` lists only files matching `getByRole('dialog')` / `[role="dialog"]`. RTL's `findByRole` and `queryByRole` are equally strict-mode sensitive, and eight call sites were therefore never enumerated — two of them on `LocationsPage`, a genuine `ConfirmDialog` consumer:
`src/features/settings/LocationsPage.primitives.test.tsx:141`, `src/features/settings/__tests__/LocationOnboarding.test.tsx:192`, `src/features/settings/RolesPage.primitives.test.tsx:116`, `src/features/replenishment/__tests__/AddToPoDialog.test.tsx:103`, `.../RejectDialog.test.tsx:48`, `.../CreateTransferDialog.test.tsx:110`, `src/features/stock-transfers/__tests__/CreateStockTransferPage.lineEntry.test.tsx:503`, `src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx:339`.
**I closed this gap: all eight run green** (the one failure is L2-C1-08, unrelated). The brief's own grep instruction (`LANE-L2-FE-HYGIENE-BRIEF.md:141`) under-specified the query forms, so this is a brief defect the lane inherited, not an invention.
**Fix directive:** append the eight files to the summary's checked list with the note "verified green by the r1 code review", and widen the grep recipe in the brief template to `(get|find|query)(All)?ByRole\('dialog'`.

### `L2-C1-02` [MINOR] — four committed e2e specs now have a second `dialog` element and carry no per-file verdict
`e2e/money-campaign/documents-lifecycle.spec.ts:332`, `e2e/money-campaign/documents-credit-notes.spec.ts:202`, `e2e/money-campaign/w1b-support.ts:292`, `e2e/session-h/m1-data-shape-drift.spec.ts:113` all use bare `page.getByRole('dialog')` on pages that mount `ConfirmDialog` (invoice / credit-note / quote detail). They are listed in the summary as "checked" but the summary records a verdict only for the sales-order Vitest file. Risk is low (each helper `page.goto()`s first, and `ConfirmDialog` returns `null` while closed — `ConfirmDialog.tsx:49`) and none is CI-gated (`.github/workflows/smoke-test.yml:53` runs `e2e/smoke` only), but the claim is currently unbacked.
**Fix directive:** state in the summary that these four are `goto`-then-open flows and therefore expected-unambiguous, and add them to the orchestrator's browser checklist alongside the two narrowed `composite-item-delete` lines.

### `L2-C1-03` [MINOR] — two new ESLint warnings from unchecked type assertions in the new tests
`src/components/ui/ConfirmDialog.test.tsx:51` and `:60` — `document.getElementById(headingId as string)` → `@typescript-eslint/no-unsafe-type-assertion`. These are the **only** new warnings the diff adds (the other 24 in scope are pre-existing).
**Fix directive:** replace `expect(x).not.toBeNull()` + `x as string` with `expect(typeof headingId).toBe('string')` then `document.getElementById(String(headingId))`, or narrow with an `if (headingId === null) throw new Error(...)` guard.

### `L2-C1-04` [MINOR] — rule 18: literal `bg-white` survives on two touched lines
`src/features/dashboard/Dashboard.tsx:349` and `:407` keep `bg-white` while the same className already interpolates `colorTokens.border.subtle`. Both lines are inside the diff (re-indented by the conditional wrapping), so rule 18's "lines you are already touching" clause applies. Not a ratchet regression (the C1-C6 audit does not cover raw color literals and reports 0 new for this file), and five sibling cards at `:250,:283,:306,:326` use the same literal — migrating only two would be locally inconsistent.
**Fix directive:** either swap both to `${colorTokens.surface.base}` (matching `ConfirmDialog.tsx:59`) or record the deliberate deferral in the summary; do not leave it silent.

### `L2-C1-05` [MINOR] — `seedAuth` docstring not updated for the new override
`src/test/seedAuth.ts:4-18` documents the fixture's contract; the new `permissions?: string[]` override at `:25` is undocumented, and its interaction is subtle (calling `seedAuth({ permissions: [...] })` inside a test **replaces** the whole `user` object, resetting `roles` to `[]` — which is precisely what makes the two new dashboard arms red-then-green).
**Fix directive:** add one sentence to the docstring: "`permissions` seeds the server-authoritative list; every call replaces `user` wholesale, so `roles` resets to `[]` unless passed together."

### `L2-C1-06` [MINOR] — wrapped cards' children were not re-indented
`Dashboard.tsx:350-403` and `:408-449` sit one level shallower than their new parent, which reads as a JSX nesting error at a glance.
**Fix directive:** re-indent both blocks by two spaces in a follow-up formatting-only commit **after** confirming `audit:design-system` still shows 0 new/0 stale for `Dashboard.tsx` (the baseline is content-keyed and the two banner buttons at `:199-216` must stay byte-identical).

### `L2-C1-07` [MINOR] — the FE gate can still open for a seeded role whose server permission list omits the key
`usePermissions.ts:128-146`: `hasPermission` returns `true` from `serverPermissions`, and otherwise **falls back to the role→roles map** unless the key is in `SERVER_AUTHORITATIVE_PERMISSIONS` (`:10-18`). `documents.view` and `payments.view` are not, and both map to `['accountant','admin','cashier','manager','operator','viewer']` (`permissionsMap.generated.ts:64`, `:157`). So a principal carrying one of those role names but lacking the backend permission still fires the query and still logs `Access denied:`.
This does **not** threaten the lane's acceptance criterion: the harness principal is a **custom** role `wave2-receiver` (`e2e-local/wave2-shared.ts:631`, permissions `purchase-orders.receive|view, documents.view, inventory.view`), which is absent from every role list, so the payments query is genuinely suppressed and the documents card genuinely survives — exactly the expected row in the brief. The lane correctly mirrored the existing `:111` pattern rather than inventing a stricter one.
**Fix directive:** no code change in L-2. Record it on the orchestrator's tolerance-removal step: the `/^Access denied:/` clause in `wave2-support.ts:84` is a **catch-all**, and if a *different* seeded role (e.g. `cashier`) ever lands on `/dashboard` in a future leg, a re-appearing 403 is this fallback, not an L-2 regression. Consider a follow-up ticket to add `documents.view` / `payments.view` to `SERVER_AUTHORITATIVE_PERMISSIONS` once the backend permission payload is trusted.

### `L2-C1-08` [MINOR — inherited, NOT this lane] — one FE test is already red on base `dev`
`src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx:400` fails with a partner field-name drift (`address`→`street_address`, `country`→`country_code`, `tax_id`→`vat_number`). **I reproduced it on the base tree extracted at `d1a056465` with none of the lane's production changes applied** — 1 failed / 13 passed, identical assertion. It is not caused by, and must not be attributed to, L-2.
**Fix directive:** raise as its own ticket against local `dev` (partner payload contract drift, likely a session-H follow-on); it will otherwise be mis-blamed at the next promotion gate.

---

## 6. F-W2-41 — still live, out of L-2's gated scope (immediate follow-up, NOT a lane failure)

The lane touched `SupplierInvoiceDetailPage.tsx` but F-W2-41 was measured **after** the brief was gated, so it was correctly not fixed. It is still fully reproducible, and it is worse than the tolerance comment records:

- `SupplierInvoiceDetailPage.tsx:51-56` — `matchIconConfig` is a `Record<SupplierInvoiceMatchStatus, …>` with **four** entries: `matched`, `price_variance`, `qty_blocked`, `unmatched`.
- `SupplierInvoiceDetailPage.tsx:58-59` — `MatchIcon` does `const { Icon, className } = matchIconConfig[status]`; an unmapped status destructures `undefined` ⇒ **TypeError, blank page**, rendered at `:607`.
- The backend enum `apps/api/app/Modules/Document/Domain/Enums/SupplierInvoiceMatchStatus.php:16-21` has **five** cases: `unmatched`, `matched`, `price_variance`, **`quantity_variance`**, **`exception`**. Both missing keys are emitted (`SupplierInvoiceMatcher.php:78`, `:581` severity ladder) and serialized at `SupplierInvoiceController.php:489`, `:550`, `:729`.
- **`qty_blocked` does not exist anywhere in `apps/api`** (`grep -rn "qty_blocked" app/` → no matches). Consequences beyond the crash:
  - `SupplierInvoiceDetailPage.tsx:125` `isQtyBlocked = invoice.match_status === 'qty_blocked'` is **permanently false**, so `postDisabled` (`:127`) never blocks a quantity-variance invoice from posting — a guard that can never fire.
  - `SupplierInvoiceListPage.tsx:33`, `:40`, `:265` map/filter on the same phantom value; the list filter sends a value the backend enum cannot parse.
  - `types.ts:16-20` is a hand-rolled union that has drifted from the generated backend enum — convention 11's "no hand-rolled FE type beside a generated DTO", exactly.

**Follow-up directive (new lane, FE + type regeneration):** replace `SupplierInvoiceMatchStatus` in `types.ts:16-20` with the five backend cases, add `quantity_variance` and `exception` rows to `matchIconConfig` and to `SupplierInvoiceListPage.tsx:33,:40`, re-point `isQtyBlocked` at `quantity_variance`, and make `MatchIcon` fall back rather than destructure `undefined`. Keep the `F-W2-41` tolerance at `e2e-local/wave2-support.ts:97-101` in place until that lands.

---

## 7. Commands I ran (verbatim)

```
$ cd /Users/houssamr/Projects/syneriva/apps/erp/.worktrees/l2-fe && git status --porcelain
 M apps/web/e2e/composite-item-delete.spec.ts
 M apps/web/src/components/ui/ConfirmDialog.test.tsx
 M apps/web/src/components/ui/ConfirmDialog.tsx
 M apps/web/src/features/dashboard/Dashboard.tsx
 M apps/web/src/features/dashboard/dashboard.test.tsx
 M apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.test.tsx
 M apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.tsx
 M apps/web/src/test/seedAuth.ts
```

```
$ cd .worktrees/l2-fe/apps/web
$ pnpm exec vitest run src/features/dashboard/dashboard.test.tsx src/components/ui/ConfirmDialog.test.tsx src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.test.tsx

 ✓ src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.test.tsx (22 tests) 2855ms

 Test Files  3 passed (3)
      Tests  42 passed (42)
   Duration  5.97s (transform 1.42s, setup 2.02s, collect 2.79s, tests 4.46s, environment 2.25s, prepare 317ms)
```
*(note: the plain `vitest run` invocation the brief prescribes works fine here — the summary's `--configLoader runner` workaround was a Codex-sandbox EPERM artefact, not a repo requirement.)*

```
$ pnpm exec vitest run src/features/dashboard src/components/ui/ConfirmDialog.test.tsx src/features/purchases/supplier-invoices

 ✓ src/features/purchases/supplier-invoices/api.tenantScope.test.tsx (2 tests) 203ms
 ✓ src/components/ui/ConfirmDialog.test.tsx (10 tests) 517ms
 ✓ src/features/dashboard/__tests__/Dashboard.tenantScope.test.tsx (3 tests) 795ms
 ✓ src/features/dashboard/dashboard.test.tsx (10 tests) 1538ms
 ✓ src/features/purchases/supplier-invoices/SupplierInvoiceListPage.test.tsx (14 tests) 2438ms
 ✓ src/features/purchases/supplier-invoices/SupplierInvoiceCreatePage.test.tsx (19 tests) 3562ms
 ✓ src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.test.tsx (22 tests) 4017ms

 Test Files  7 passed (7)
      Tests  80 passed (80)
   Duration  8.64s
```

**Independent RED verification** — base tree extracted with `git archive d1a056465` into the scratchpad, `node_modules` symlinked, then ONLY the lane's three test files + `seedAuth.ts` copied on top of **base production code**:

```
$ git archive d1a056465 apps/web packages package.json pnpm-workspace.yaml | tar -x -C <scratch>/l2base
$ cp <l2-fe>/…/{dashboard.test.tsx,ConfirmDialog.test.tsx,SupplierInvoiceDetailPage.test.tsx,seedAuth.ts} <scratch>/l2base/…
$ cd <scratch>/l2base/apps/web && ./node_modules/.bin/vitest run <the three files>

 ❯ src/components/ui/ConfirmDialog.test.tsx (10 tests | 4 failed) 504ms
     → Unable to find an accessible element with the role "dialog"
     → Unable to find an accessible element with the role "dialog" and name "Delete item?"
     → Unable to find an accessible element with the role "dialog"
     → Unable to find an accessible element with the role "dialog"
 ❯ src/features/dashboard/dashboard.test.tsx (10 tests | 2 failed) 552ms
     → expected [ Array(1) ] to have a length of +0 but got 1
     → expected [ Array(1) ] to have a length of +0 but got 1
 ❯ src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.test.tsx (22 tests | 2 failed) 2019ms
     → expected [ <span …(1)></span>, …(1) ] to have a length of 1 but got 2
     → expected [ <span …(1)></span>, …(2) ] to have a length of 2 but got 3
⎯⎯⎯⎯⎯⎯⎯ Failed Tests 8 ⎯⎯⎯⎯⎯⎯⎯
```

```
$ pnpm typecheck

> @autoerp/web@0.1.0 typecheck /…/.worktrees/l2-fe/apps/web
> tsc --noEmit

EXIT=0
```

```
$ pnpm exec eslint src/features/dashboard/Dashboard.tsx src/features/dashboard/dashboard.test.tsx \
    src/components/ui/ConfirmDialog.tsx src/components/ui/ConfirmDialog.test.tsx \
    src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.tsx \
    src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.test.tsx src/test/seedAuth.ts

✖ 26 problems (0 errors, 26 warnings)
  0 errors and 7 warnings potentially fixable with the `--fix` option.
```
*(the only two attributable to this diff are `ConfirmDialog.test.tsx:51:36` and `:60:36` — L2-C1-03. `SupplierInvoiceDetailPage.test.tsx:46` and `:608` sit outside the single added hunk `@@ -411,0 +412,74 @@`.)*

```
$ node tools/audit-tanstack-keys.mjs
[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: 0
[gate-summary] Gate C baseline: 0 acknowledged, 0 new, 0 stale baseline entries
KEYS_EXIT=0
```

```
$ node tools/audit-design-system.mjs
[sweep-progress] Design-system audit C1-C6 violations: 809
[gate-summary] Design-system baseline: 796 acknowledged, 13 new, 11 stale baseline entries
New design-system violations:
  src/features/import/pages/ImportWizardPage.tsx:1075:23 C2 …
  src/features/import/pages/ImportWizardPage.tsx:792:15 C3 …   (×12 more, ALL ImportWizardPage.tsx)
Stale design-system baseline entries: 11, all in src/features/import/pages/ImportWizardPage.tsx
```
```
$ git diff --stat -- apps/web/tools/audit-design-system-baseline.json apps/web/src/features/import/pages/ImportWizardPage.tsx
(empty — both files byte-untouched by this lane)
```

Blast radius:
```
$ pnpm exec vitest run <12 files from the summary's dialog-consumer list>
 Test Files  12 passed (12)
      Tests  95 passed (95)

$ pnpm exec vitest run <8 files the summary's grep missed>
 ✓ RejectDialog / AddToPoDialog / CreateTransferDialog / RolesPage.primitives /
   LocationsPage.primitives / LocationOnboarding / CreateStockTransferPage.lineEntry
 ❯ src/features/document-ingestions/__tests__/ReviewIngestionPage.test.tsx (14 tests | 1 failed)
 Test Files  1 failed | 7 passed (8)
      Tests  1 failed | 47 passed (48)

# same file, same failure, on the UNMODIFIED base tree → inherited, not L-2:
$ cd <scratch>/l2base/apps/web && ./node_modules/.bin/vitest run …/ReviewIngestionPage.test.tsx
 Test Files  1 failed (1)
      Tests  1 failed | 13 passed (14)
```

```
$ ps aux | grep -E 'node.*vitest' | grep -v grep | wc -l
       0
```
(no vitest worker pools left alive; default pool used throughout, never `--singleFork`.)

---

## 8. Post-merge orchestrator checklist

**A. Tolerances to DELETE in `apps/web/e2e-local/wave2-support.ts`** (line numbers re-verified by me in `.worktrees/L-po-flow` today):

| Delete | Lines | Notes |
|---|---|---|
| `F-W2-37` tolerance | **`:81-85`** (2 comment lines + `if` + `return` + `}`) | **Both** clauses. The second — `\|\| /^Access denied:/.test(text)` at `:84` — is a **catch-all** suppressing any 403-driven console error on `/dashboard`. See L2-C1-07: a re-appearing `Access denied:` for a *different* seeded role is the `usePermissions` role fallback, not an L-2 regression. |
| `F-W2-39` tolerance | **`:92-96`** (2 comment lines + `if` + `return` + `}`) | |
| **KEEP** L-ENV-1 | `:76-80` | Echo/Reverb websocket noise — still needed. |
| **KEEP** harness probe | `:86-91` | |
| **KEEP** `F-W2-41` | `:97-101` | **Not fixed by L-2** — see §6. Removing it will crash the leg. |

**B. Overlay-locator workarounds to collapse to `page.getByRole('dialog')`** — three sites, re-verified today (one has moved since the brief was written):

| File | Line | Note |
|---|---|---|
| `e2e-local/wave2-po.part1.spec.ts` | `:478` | as briefed |
| `e2e-local/wave2-po.part2.spec.ts` | **`:330`** | the brief said `:304`; the file has since moved — **re-locate before editing** |
| `e2e-local/wave2-shared.ts` | `:441` | as briefed |

**C. Re-runs required**
1. **Part 1 must re-run** (`playwright test --config e2e-local/pw.config.ts e2e-local/wave2-po.part1.spec.ts`) — **50/50 PASS, zero console errors, zero 5xx, with the two tolerances removed**. This is the subtractive acceptance criterion; the lane cannot prove it.
2. **Part 2 must re-run** — it owns the third overlay workaround (`wave2-po.part2.spec.ts:330`).
3. Expected new measured values (unchanged from the brief): `W2-SETUP-7` → zero `/payments` requests, no payments card, `/documents` still 200 and card still rendered; `W2-HP-2` → `getByRole('dialog')` matches directly; `W2-PART-6` → zero console errors, **one** match row for the shared PO line, money assertions unchanged.

**D. Browser-verify the two committed-spec edits** — `e2e/composite-item-delete.spec.ts:98`, `:164` are **unverified**: not CI-gated (`.github/workflows/smoke-test.yml:53` runs `e2e/smoke` only) and Playwright was correctly not run by the lane. Add them to the next browser leg.

**E. Watch list during that leg** — the four bare `page.getByRole('dialog')` sites in committed `e2e/` (L2-C1-02) now have a second element answering the role; a strict-mode violation there is an L-2 consequence, not a product defect.

**F. Two tickets to raise before the next promotion gate**
- **F-W2-41 lane** (§6) — `matchIconConfig`/`SupplierInvoiceMatchStatus` drift: a render crash *and* a never-firing post guard.
- **L2-C1-08** — `ReviewIngestionPage.test.tsx:400` is red on local `dev` independent of this lane.
