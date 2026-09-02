# Lane L-2 "FE hygiene" — adversarial brief gate, round 1

**Artifact:** `docs/sessions/session-L-wave2-po-2026-09-01/LANE-L2-FE-HYGIENE-BRIEF.md` (pre-implementation dispatch brief)
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/L-po-flow` @ `be58e2339` (dev `62964e5cc` + the F-W2-39 evidence commit)
**Reviewer:** frontend-conventions-reviewer. Read-only; no suites, no servers run.

## Verdict: **CHANGES-REQUIRED**

Line-level citation accuracy is high — every `path:line` in the brief resolves to what it claims (see "Citations verified"), the two permission keys are the right ones, and `DataTable` really does expose `index`. The blocker is not accuracy, it is the **choice of fix for F-W2-39**: the match payload's four quantity columns are PO-line aggregates, so "render two rows" ships two byte-identical rows that a reader adds up. That re-scopes task 3's tests and the expected browser row, so it is not a one-line edit — hence CHANGES-REQUIRED rather than ACCEPT-WITH-CONDITIONS. Findings 02–05 are one-line edits and would, alone, have been conditions.

---

## Findings

### L2-G1-01 [BLOCKER] — Task 3's "recommended fix" contradicts the payload's grain; the two rows are identical
**Brief §Task 3, lines 115-119 ("Recommended fix — FE-only composite") and baseline row B56 (line 32).**

`SupplierInvoiceController::buildMatchBlock` emits, per invoice line:

```php
// apps/api/app/Modules/Procurement/Presentation/Controllers/SupplierInvoiceController.php:718-725
'po_line_id' => $invoiceLine->source_line_id,
'ordered'    => $poLine->quantity,
'received'   => $poLine->quantity_received,
'invoiced'   => $poLine->quantity_invoiced,
'matchable'  => $matchable,            // $this->matcher->matchableQty($poLine)  (:712)
'price_variance' => $priceVariance,    // the ONLY per-invoice-line value (:715-716)
```

Four of the five columns are **PO-line aggregates**. The FE match table renders exactly those columns (`SupplierInvoiceDetailPage.tsx:167-196`). So for two invoice lines sharing one PO line, the composite key `${row.po_line_id}:${index}` makes the table render **two rows showing the same ordered / received / invoiced / matchable numbers**. An operator reading `ordered 10 / ordered 10` reads 20 ordered. The duplicate-key *warning* disappears; the *data-meaning* defect gets shipped and then locked in by an expected-value row ("the match table renders two rows", brief line 159).

Baseline row **B56** ("two document lines that share an upstream source are still two distinct rows") is a `(m)`-labelled memory row being used to justify that outcome. It is the wrong guarantee for this surface: a 3-way *match* row's concept is the PO line (that is what "ordered vs received vs invoiced" compares), which is also the convention-11 reading — one row per concept.

**Fix wording (replaces brief lines 115-119):** "Before choosing a fix, reproduce and **record the rendered values of both rows**. Because `ordered/received/invoiced/matchable` are PO-line aggregates (`SupplierInvoiceController.php:719-723`) and only `price_variance` is per-invoice-line (`:715-716`), the default fix is to **collapse `invoice.match.per_line` to one row per `po_line_id` in the FE** (OR-ing `price_variance`), leaving `keyExtractor={(row) => row.po_line_id}` and the test id unchanged; the composite `(row, index)` key is correct **only if** the reproduction shows the two rows carry different numbers. If you conclude the row identity must be the invoice line, stop and escalate — that needs the `invoice_line_id` + per-invoice-line `invoiced` payload (ticket `L-2-FU-match-row-identity`), not an index." Task 3's tests (brief lines 121-125) and the `W2-PART-6` expected row (line 159) must be rewritten to match whichever shape is chosen (`getAllByTestId(/^match-row-/)` length **1** for the dedupe shape, plus an assertion on the rendered quantities — not just the absence of a console warning).

### L2-G1-02 [MAJOR] — The expected browser row for `W2-SETUP-7` is factually wrong about `/documents`
**Brief line 157.** It predicts "receiver context lands with **zero** `/payments` and `/documents` requests … no payments/documents card". But the harness grants that role `documents.view`:

```ts
// apps/web/e2e-local/wave2-po.part1.spec.ts:765-766
name: 'wave2-receiver',
permissions: ['purchase-orders.receive', 'purchase-orders.view', 'documents.view', 'inventory.view'],
```

and the FE reads server-authoritative permissions (`LoginPage.tsx:87`, `AuthProvider.tsx:87` → `usePermissions.ts:129`), so after the gate lands the documents card **still renders** and `GET /documents` **still fires** (200, no console error). An orchestrator holding the brief's table would read a correct run as a regression.
**Fix (one line):** "receiver lands with **zero** `/payments` requests and no payments card; `/documents` still fires and returns 200 — `wave2-receiver` holds `documents.view` (`wave2-po.part1.spec.ts:766`) — so the documents card is expected to render." Also reword Vitest arm 1 (line 78) to call its principal *synthetic* rather than "the receiver".

### L2-G1-03 [MAJOR] — Pre-existing dashboard tests will go red for the wrong reason; the brief only clears the other file
**Brief lines 77-82 (tests) and checklist line 185.** The brief guarantees `Dashboard.tenantScope.test.tsx` stays green — correct, it seeds `roles: ['admin']` by default (`src/features/dashboard/__tests__/Dashboard.tenantScope.test.tsx:41`). But `dashboard.test.tsx` seeds through `seedAuth()`, whose default is **`roles: []` with no `permissions`** (`src/test/seedAuth.ts:29`), so the moment the gates land these two arms fail:

- `src/features/dashboard/dashboard.test.tsx:139` `displays recent documents section`
- `src/features/dashboard/dashboard.test.tsx:154` `displays recent payments section`

An executor under TDD pressure meeting two unexplained reds is one step from loosening the predicate.
**Fix (one line):** "Expect `dashboard.test.tsx:139` and `:154` to fail on the first green run — `seedAuth()` defaults to `roles: []` (`src/test/seedAuth.ts:29`). Update those two arms to `seedAuth({ roles: ['admin'] })` (or mock `usePermissions`); do **not** weaken the gate."

### L2-G1-04 [MAJOR] — The `/dashboard/stats` verdict ossifies an ungated route and contradicts the brief's own B54
**Brief lines 67, 188 vs. baseline row B54 (line 30).** Verified: `apps/api/app/Modules/Dashboard/routes.php:20-21` carries no `can:` middleware and `DashboardController::stats` (`…/Controllers/DashboardController.php:33`) performs no authorization — so every authenticated user, receiver included, reads company revenue, invoice counts, partner counts and payment totals. That is the same B48/B54 class as the payments widget, only on the backend side. Instructing the executor to write a code comment saying there is "no gap" turns an unreviewed over-grant into documented intent.
**Fix (one line):** "`/dashboard/stats` is **ungated on the backend** (`Dashboard/routes.php:20-21`, no `can:`; controller does not authorize). Do not add an FE-only gate — it would hide data from legitimate users while the endpoint stays open. Write the comment as an observation citing ticket `L-2-FU-dashboard-stats-ungated` (backend gate belongs to lane L-14), and scope B54 to *endpoints that carry a `can:` gate*."

### L2-G1-05 [MAJOR] — The subtractive acceptance criterion under-enumerates what has to be removed and over-states what the tolerance covers
**Brief lines 151, 161.** Two problems, both verified:
1. The F-W2-37 tolerance's second clause is a **catch-all**: `… || /^Access denied:/.test(text)` (`apps/web/e2e-local/wave2-support.ts:81-83`) suppresses *any* 403-driven console error on `/dashboard`, not just `/payments`. Deleting it restores coverage the wave has never actually run under — that is the intent, but it must be stated so a new, unrelated dashboard 403 is not mistaken for an L-2 regression.
2. The F-W2-38 locator workaround exists in **three** places, not one: `wave2-po.part1.spec.ts:478`, `wave2-shared.ts:436`, `wave2-po.part2.spec.ts:302`. The brief names only the first, and says nothing about whether part 2 is re-run.
(The line ranges the brief does give — `wave2-support.ts:79-83`, `:90-94`, and "50/50" for part 1 — are all correct: part 1 declares 50 tests, `e2e-local/L0-part1-SUMMARY.md:10-20`.)
**Fix (one line):** "Tolerances to delete: `wave2-support.ts:79-83` (note its second clause is a catch-all `/^Access denied:/` for any 403 on `/dashboard`) and `:90-94`. Locator workarounds to collapse: `wave2-po.part1.spec.ts:478`, `wave2-shared.ts:436`, `wave2-po.part2.spec.ts:302`; part 2 is re-run for the third."

### L2-G1-06 [MINOR] — The `getByRole('dialog')` strict-mode warning is plausible but not demonstrated; state the actual condition
**Brief line 161.** Verified: `ConfirmDialog` returns `null` when closed (`ConfirmDialog.tsx:46`), the supplier-invoice detail page mounts **no** `ConfirmDialog` at all (no import in `src/features/purchases/supplier-invoices/`), and each harness helper re-navigates before opening its dialog (`wave2-po.part1.spec.ts:474-475`, `:484-485`; `:1372-1374`), which resets `confirmAction`. Ambiguity therefore requires a page that holds a `ConfirmDialog` open *while* a `Modal` is open — possible on `PurchaseOrderDetailPage.tsx:678-703`, where `confirmAction` and `showReceiveDialog` are independent booleans, but not observed in the harness's flows. Note also that the union locator keeps working after the change (`.last()` on backdrop + panel resolves to the panel).
**Fix (one line):** "Flag as *possible, not observed*: strict mode only breaks where a `ConfirmDialog` is open at the same time as a `Modal` (independent state on `PurchaseOrderDetailPage.tsx:678-703`); every harness helper re-navigates first, so no current row is known to be ambiguous."

### L2-G1-07 [MINOR] — `aria-modal="true"` promises containment the component does not implement
**Brief lines 89-91.** Verified: `ConfirmDialog` has **no** keydown handler, no focus management and no background `inert`/`aria-hidden` (whole file, 1-106); `Modal` is the same (`Modal.tsx:128-154`). So "Escape handling untouched" means *still absent*, and `aria-modal="true"` tells assistive tech the rest of the page is inert while it is not. Consistency with `Modal` justifies shipping it, but the claim must be labelled partial. (Also: the consumer grep will find **46** importing files, not "~20" — `grep -rln ConfirmDialog src | grep -v '\.test\.'`.)
**Fix (one line):** "State in the summary that this is a *partial* a11y fix — focus trap, Escape-to-close and background inert are absent from **both** dialog surfaces and are the substance of ticket `L-2-FU-dialog-surfaces`."

### L2-G1-08 [MINOR] — Hook placement is not specified and the file has an early return
**Brief line 90.** `ConfirmDialog.tsx:46` is `if (!isOpen) return null`, above the render. `useId()` must sit with `useTranslation()` at `:44`.
**Fix (one line):** "Add `const headingId = useId()` / `descriptionId` next to `useTranslation()` at `ConfirmDialog.tsx:44`, **above** the `if (!isOpen) return null` early return at `:46`."

### L2-G1-09 [MINOR] — `keyExtractor` doubles as selection identity; an index key would silently poison it
**Brief line 115.** True that `DataTable.tsx:64` exposes `(row, index)`, and this table passes no `selection`, so an index key is safe *today* — but the same function feeds `selection.selectedIds` (`DataTable.tsx:157`, `:262`, `:265`) and the row `key` (`:235`). An index-derived id survives re-render but not reordering or a later `selection` opt-in. (This table has no sorting — `DataTable` contains no sort logic — so re-sort is not a live risk; row identity is presentational only.)
**Fix (one line):** "If the composite key is used at all, add a one-line comment that this table passes no `selection` and must not gain one while the key is index-derived (`DataTable.tsx:157,262`)."

### L2-G1-10 [MINOR] — The dashboard-query audit table omits the fourth data-fetching widget on the screen
**Brief lines 64-69.** `Dashboard.tsx:343` mounts `CashPositionWidget`, which queries `GET /treasury/cash-position` (`can:treasury.view`, `Treasury/Presentation/routes.php:56-58`). It is already correctly gated (`CashPositionWidget.tsx:125-130`: `canAccessModule('treasury') && hasModule('Treasury')`, and `MODULE_PERMISSIONS.treasury = ['treasury.view']`, `usePermissions.ts:43`), so there is no gap — but a deliverable that claims to audit "the other dashboard queries" must show the row, otherwise the reader cannot tell it was checked.
**Fix (one line):** add the `CashPositionWidget` / `/treasury/cash-position` / `can:treasury.view` / "already gated at `CashPositionWidget.tsx:128`" row to the audit table.

### L2-G1-11 [MINOR] — Pre-declare the overloaded `source_line_id` so the executor does not "fix" it
`buildMatchBlock` resolves `source_line_id` as a **PO `DocumentLine` id** (`SupplierInvoiceController.php:707`) while `consumedReceipts` resolves the same column as a **`GoodsReceiptLine` id** (`:626-641`). The create page writes the PO line id (`SupplierInvoiceCreatePage.tsx:444`), so the consumed-receipts block is reading against the wrong table. One column, two concepts (convention 11). Out of L-2 scope (backend), but the executor will meet it while reproducing.
**Fix (one line):** "Observation to record, not to fix: `source_line_id` is read as a PO line id at `SupplierInvoiceController.php:707` and as a goods-receipt line id at `:626-641`; ticket it for the procurement lane."

### L2-G1-12 [MINOR] — Design-system ratchet: name the two baselined lines that must not be reformatted
`tools/audit-design-system-baseline.json` is **content-keyed**, not line-keyed, and holds exactly two `Dashboard.tsx` entries (`:356-357`, the onboarding-banner buttons) and zero `ConfirmDialog.tsx` entries. Re-indenting the recent-activity cards is therefore ratchet-safe; reformatting those two buttons is not.
**Fix (one line):** "Wrapping the cards is ratchet-safe (baseline entries are content-keyed); do not reformat the two baselined banner buttons at `Dashboard.tsx:199-206` / `:209-216`."

---

## Permission keys verified

| Endpoint (FE call site) | Backend gate (verified) | FE key proposed by brief | Verdict |
|---|---|---|---|
| `GET /onboarding/status` (`Dashboard.tsx:101-112`) | `can:settings.view` (existing) | `settings.view` (already in place, `:111`) | **Correct** — this is the pattern to copy, as the brief says |
| `GET /dashboard/stats` (`Dashboard.tsx:125-132`) | **none** — `Dashboard/routes.php:20-21`, no `can:`; `DashboardController::stats:33` does not authorize | none (brief: "leave as is + comment") | **Right action, wrong framing** → L2-G1-04. Leave ungated on the FE, but record it as backend debt, not as "no gap" |
| `GET /documents?limit=5&sort=-created_at` (`Dashboard.tsx:134-141`) | `can:documents.view` — `Document/Presentation/routes.php:80-82` | `documents.view` | **Correct key.** Exists in `permissionsMap.generated.ts:64` (`accountant, admin, cashier, manager, operator, viewer`) and mirrors `RolesAndPermissionsSeeder.php:568,671,707,773,804`. Server-authoritative permissions are hydrated on login (`LoginPage.tsx:87`) and `/auth/me` (`AuthProvider.tsx:87`), so custom roles (e.g. `wave2-receiver`) keep the card — **no legitimate user is hidden** |
| `GET /payments?limit=5&sort=-created_at` (`Dashboard.tsx:143-150`) | `can:payments.view` — `Treasury/Presentation/routes.php:181-182` | `payments.view` | **Correct key.** `permissionsMap.generated.ts:157` (same six roles), seeder `:586,682,721,786,813`. `technician` holds neither key — correctly loses both cards, since the backend would 403 |
| `GET /treasury/cash-position` (`CashPositionWidget`, mounted `Dashboard.tsx:343`) | `can:treasury.view` — `Treasury/Presentation/routes.php:56-58` | *(not in the brief's table)* | **Already gated** at `CashPositionWidget.tsx:125-130` via `canAccessModule('treasury')` (→ `treasury.view`, `usePermissions.ts:43`) **+** `hasModule('Treasury')` — both layers, fail-closed. Add the row → L2-G1-10 |

Fail-open check: `canAccessModule` denies unknown keys (`usePermissions.ts:163-170`) and the key space is a literal union — no unknown-key fail-open, no role-name heuristic in the proposed change.

## Tests that would be vacuous

| Brief | Arm | Why it is not red→green |
|---|---|---|
| Task 1, line 79 | "Admin principal: both queries fire, both cards render, current behaviour unchanged" | Passes **before and after**. Keep it as a regression guard, but it cannot be counted as a red run; and today it only passes if the arm switches to `seedAuth({ roles: ['admin'] })` (L2-G1-03) |
| Task 1, line 81 | "`isLoading` resolves (no perpetual skeleton) for the receiver principal" | Passes **before** the change too — today the queries fire and resolve against the mocks, so nothing hangs. Make it red by asserting, in the same arm, *both* "no skeleton" and "zero `/payments` calls", or drop the red claim |
| Task 2, line 97 | "Every existing assertion in the file still passes" | A re-run of `ConfirmDialog.test.tsx`, not a new test. Do not report it as one of the red→green arms |
| Task 3, line 123 | "Single-row case unchanged; the existing `:409` assertion updated to the new id form and still passing" | Green by construction after the rename — it proves nothing about duplication. It is a rename check, not evidence |
| Task 3, line 122 | "spy on `console.error` and assert it was never called with `/same key/`" | Mechanism assertion, not data meaning (and React de-dupes some warnings across renders). The load-bearing assertion is on the **rendered quantities of each row** — which is exactly the question L2-G1-01 says must be answered first |
| Checklist, line 190 | "renders two rows … with no `console.error` matching `/same key/`" | Asserts the outcome L2-G1-01 disputes; must be rewritten with the fix choice |

Genuinely red arms as written: Task 1 arm 1 (receiver — zero `/payments` calls) and arm 3 (partial principal), Task 2 arms 1-3.

## Citations verified (spot-check of every `path:line` in the brief)

| Brief claim | Verdict |
|---|---|
| `Dashboard.tsx:143-150` payments query ungated; `:134-141` documents ungated; `:111` settings gate; `:125-132` stats; `:152` `isLoading`; `:95` `usePermissions()` | **All exact** |
| TanStack v5 "a disabled query is pending but not `isLoading`" (line 74) | **Correct** (`isLoading = isPending && isFetching`; disabled ⇒ `fetchStatus: 'idle'`) |
| `ConfirmDialog.tsx:51-52` no `role`/`aria-modal`/name; `Modal.tsx:141-142` has them | **Exact** |
| `SupplierInvoiceDetailPage.tsx:596` `keyExtractor={(row) => row.po_line_id}`; `:173` test id; `:537`/`:561` keys are unique | **Exact** — `:596` is the only non-unique `keyExtractor`/`key` on the page (`:472,499,537,561,580,642,731,750` all unique ids) |
| `DataTable.tsx:64` exposes `(row, index)` | **Exact** |
| `SupplierInvoiceController.php:702-727` `foreach ($doc->lines as $invoiceLine)` emitting `'po_line_id' => $invoiceLine->source_line_id` | **Exact** (`:702` loop, `:718-725` row) |
| `SupplierInvoiceCreatePage.tsx:256-268` receipt-line grain ⇒ two invoice lines share a PO line | **Exact**, and the payload confirms it: `source_line_id: line.poLineId` at `:444` |
| `types.ts:88-96` `PerLineMatch` | **Exact** |
| `SupplierInvoiceDetailPage.test.tsx:409` `getByTestId('match-row-po-line-1')` is the only `match-row` consumer outside the component | **Exact** — repo-wide grep across `src`, `e2e`, `e2e-local` returns only `:409` and `SupplierInvoiceDetailPage.tsx:173` |
| `wave2-support.ts:79-83` / `:90-94` tolerances; `wave2-po.part1.spec.ts:477-478` workaround; bare `getByRole('dialog')` at `:486,:1378,:1430,:1478`; part 1 = 50 tests | **All exact** (see L2-G1-05 for what is *missing* from the list) |
| Evidence rows 13 / 17 / 20, run 24 | **Match** `docs/superpowers/reviews/2026-09-01-wave2-po-evidence.md:24,27,30,43-44,61-63` |
| Route gates: `Dashboard/routes.php:20-21` (none), `Document/.../routes.php:80-82` (`can:documents.view`), `Treasury/.../routes.php:181-182` (`can:payments.view`) | **All exact** (nit: `Document/Presentation/routes.php:70-72` asks callers to cite route **names**, not line numbers — prefer `documents.index`) |

## Scope hygiene, conventions, guardrails

- **No `e2e-local` / `e2e` edits, no `apps/api` edits**: forbidden correctly and repeated in the dispatch prompt (brief lines 56, 167-169). The tolerance deletion is assigned to the orchestrator, consistent with the ban. ✔
- **Convention 09**: the not-applicable statement (line 34) is present and correct — no catalogue entity, no migration, no unique key, no importer. ✔
- **Convention 10**: baseline table present, `(m)` memory labelling is explicitly permitted (`docs/conventions/10-BENCHMARK-FIRST-SPECS.md:43`) and the second-of-everything statement matches the skeleton (`:56`). ✔ B54/B56 are challenged above (L2-G1-01, L2-G1-04) — which is what the label is for.
- **Convention 11**: duplicate dialog surface correctly noted-not-fixed (line 36). `PerLineMatch` (`types.ts:88`) is a hand-rolled FE type, but it is pre-existing and the brief does not add one. ✔
- **Rule 14 / `audit:keys`**: only `enabled` predicates change, keys untouched. ✔
- **Rule 18 / `audit:design-system`**: no colour class is added; ratchet is content-keyed → safe (L2-G1-12). ✔
- **Rule 11**: no new user-facing string in any of the three tasks. ✔
- **Rule 19**: `formatCurrency`/big.js path in `Dashboard.tsx:158-176` untouched; `formatQuantity` on the match rows untouched. ✔
- **Verification block** (lines 130-139): the four scoped commands all exist (`package.json:10,14,15,21`); scoping away from full `pnpm lint` and full Vitest, plus the worker-pool warning, is correct for this machine.

## What round 2 must show

1. Task 3 re-scoped per L2-G1-01 — with the reproduction's **rendered row values** quoted, the fix chosen from those values, and tests/expected-browser rows rewritten to match.
2. L2-G1-02, 03, 04, 05 applied verbatim (four one-line edits).
3. L2-G1-06..12 applied or explicitly declined with a reason.
