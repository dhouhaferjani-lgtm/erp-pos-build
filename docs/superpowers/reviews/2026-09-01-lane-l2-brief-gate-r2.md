# Lane L-2 "FE hygiene" — adversarial brief gate, round 2

**Artifact:** `docs/sessions/session-L-wave2-po-2026-09-01/LANE-L2-FE-HYGIENE-BRIEF.md` (revision r2, rewritten in place with a "Gate r1 response" table)
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/L-po-flow` (== dev `62964e5cc`)
**Round 1:** `docs/superpowers/reviews/2026-09-01-lane-l2-brief-gate-r1.md` (CHANGES-REQUIRED — L2-G1-01 BLOCKER, 02-05 MAJOR, 06-12 MINOR)
**Reviewer:** frontend-conventions-reviewer. Read-only; no suites, no servers run.

## Verdict: **ACCEPT-WITH-CONDITIONS**

The r1 BLOCKER is genuinely fixed. Task 3's fix is now "collapse to one row per `po_line_id`, `price_variance` OR-ed", the reproduce-and-record step is mandatory and precedes the fix choice, the escalation path is named, and the `W2-PART-6` expected row now says **one** match row with the aggregates rendered once — I re-verified the payload grain that forces that conclusion (`SupplierInvoiceController.php:719` key, `:720-723` all read `$poLine`, `:715-716` the only per-invoice-line value). 02-05 are applied and 02/05's factual corrections are right (`wave2-po.part1.spec.ts:766` does grant `documents.view`; the three workaround sites really are `part1:478`, `part2:304`, `wave2-shared:441` — r2's re-verification beat r1 here).

Six MAJOR conditions remain, every one a one-line edit. Three of them (G2-01, G2-02, G2-03) are places where the *new* r2 content is not executable as written: the load-bearing task-3 arm asserts an icon that carries no accessible name and no test id, the prescribed `useMemo` lands below an early return that `react-hooks/rules-of-hooks` (error, `eslint.config.js:107`) rejects, and the deliberate `role="dialog"` addition deterministically breaks a committed Playwright spec the brief forbids touching. One (G2-06) is a baseline-provenance defect: the withdrawn `(m)` row B56's guarantee was moved into **B50**, whose cited source says something else and carries verdict OK/ALREADY.

---

## Findings

### L2-G2-01 [MAJOR] — Adding `role="dialog"` deterministically breaks two committed `apps/web/e2e/` assertions, and the brief forbids fixing them
**Brief lines 133 (consumer check), 232 (forbidden actions).**

`e2e/composite-item-delete.spec.ts:98` and `:164`:
```ts
const confirmDialog = page.locator('[role="dialog"], .fixed.inset-0')
await expect(confirmDialog).toBeVisible()
```
Today that union resolves to **one** element — the `ConfirmDialog` backdrop (`ConfirmDialog.tsx:51`, `fixed inset-0 z-50`); the inner panel (`:52`) matches neither clause. After task 2 puts `role="dialog"` on the panel the union resolves to **two** (backdrop + panel, both in the subtree), and `expect(locator).toBeVisible()` is a strict-mode operation → *"strict mode violation: resolved to 2 elements"*. Both specs fail, deterministically, in exactly the flow they test (dialog open).

The brief mandates the consumer grep ("confirm no existing spec becomes strict-mode ambiguous", line 133) but gives no disposition for a hit, while line 232 forbids editing `apps/web/e2e/**`. The executor will find this and have nowhere to put it. (Not a CI break — `smoke-test.yml:53` runs only `e2e/smoke/**.smoke.ts`, which contains no `dialog` locator — so this is silent committed debt, not a red pipeline. That is why it is MAJOR and not a blocker.)

**Fix (one line), in the "Browser re-verification" deletion table:** add a row — "`ConfirmDialog` locator workaround (committed e2e): `e2e/composite-item-delete.spec.ts:98`, `:164` — `page.locator('[role="dialog"], .fixed.inset-0')` resolves to 2 elements once the panel carries the role; collapse both to `page.getByRole('dialog')`. Orchestrator-owned like the `e2e-local` sites; not CI-gated (`smoke-test.yml:53` runs `e2e/smoke` only)."

### L2-G2-02 [MAJOR] — The prescribed `useMemo` sits below an early return; `react-hooks/rules-of-hooks` is a hard error
**Brief line 159** ("Derive the table's data with a `useMemo` that groups `invoice.match.per_line` by `po_line_id`").

`SupplierInvoiceDetailPage.tsx:114` is `if (isLoading || !invoice) { return (…) }`, and the match columns/data live at `:167`/`:595` — **below** it. A `useMemo` placed where the brief implies (next to `matchColumns`) violates the rules of hooks, which this repo enforces as an error, not a warning:
```
// apps/web/eslint.config.js:101-107
// EXCEPTION: rules-of-hooks is a hard error everywhere. …
'react-hooks/rules-of-hooks': 'error',
```
So the brief's own scoped `eslint` command (line 189) fails the lane. r1 pinned this exact trap for `ConfirmDialog`'s `useId` (L2-G1-08, applied at brief line 121) and r2 left the bigger change unpinned.

**Fix (one line), appended to brief line 159:** "Placement: either put the `useMemo` beside the other hooks **above** the `if (isLoading || !invoice) return` early return at `SupplierInvoiceDetailPage.tsx:114` (guard with `invoice?.match.per_line ?? []`), or derive it as a plain non-hook `const` next to `matchColumns` at `:167` — a plain derivation is acceptable for a list this size and avoids the hook-order constraint. Do **not** call `useMemo` below `:114` (`react-hooks/rules-of-hooks` is an error, `eslint.config.js:107`)."

### L2-G2-03 [MAJOR] — The load-bearing arm asserts a price-variance indicator that has no accessible name and no test id
**Brief line 170** ("the surviving row shows the price-variance indicator (the OR)").

The column renders a bare lucide icon with nothing queryable:
```tsx
// SupplierInvoiceDetailPage.tsx:200-205
render: (row) =>
  row.price_variance
    ? <AlertTriangle className={`inline h-4 w-4 ${textColors.warningDark}`} />
    : <CheckCircle2 className={`inline h-4 w-4 ${textColors.success}`} />,
```
No `data-testid`, no `aria-label`, no text, no role. The only way to assert it with today's markup is `container.querySelector('.lucide-…')` — a CSS-class assertion, which rule 17 forbids ("Test rendered HTML output rather than CSS class names"). The brief simultaneously says the detail-page change is limited to the derived row list and that the test id at `:173` stays unchanged, so the executor has no authorisation to add a hook for the assertion. The OR of `price_variance` is the *only* semantic content the collapse creates — leaving it unassertable guts the arm the brief calls load-bearing.

**Fix (one line), appended to brief line 163 (the "stays unchanged" paragraph):** "Exception, explicitly authorised: add `data-testid={\`match-variance-${row.po_line_id}\`}` **and** a `data-variance={String(row.price_variance)}` attribute to the two icons at `SupplierInvoiceDetailPage.tsx:202`/`:204` so the OR is assertable without a CSS-class query (rule 17). No visible string is added, so rule 11 is untouched."

### L2-G2-04 [MAJOR] — The prescribed fixture makes "the ordered quantity appears once" unsatisfiable after the fix
**Brief line 170** (fixture `ordered 10.0000, received 10.0000, invoiced 10.0000, matchable 0.0000`; assert "the rendered ordered quantity appears **once**").

`formatQuantity` is `Big(v).toFixed(4)` (`src/lib/decimal.ts:206-214`), and the ordered/received/invoiced columns all call it (`SupplierInvoiceDetailPage.tsx:174,182,188`). With the brief's values the string `10.0000` renders **three times inside the single surviving row**, so `getAllByText('10.0000')` has length 3 after the fix and the assertion can never go green as written. Worse, the invoice-lines table above renders `formatQuantity(line.quantity)` at `:144` from the same fixture, adding more collisions. The executor's cheapest escape is to delete the assertion — leaving only the testid count, which is the weaker signal r1 asked to strengthen.

**Fix (one line), replacing the fixture values at brief line 170:** "Give each aggregate a distinct value that appears nowhere else in the fixture (e.g. `ordered 11.0000`, `received 7.0000`, `invoiced 6.0000`, `matchable 3.0000`; the invoice-lines table uses `4.0000`/`6.0000` — keep `invoiced` off those too), then assert `getAllByText('11.0000')` has length **1** after the collapse and **2** before it."

### L2-G2-05 [MAJOR] — Both tolerance line ranges are two lines low; deleting `wave2-support.ts:79-83` destroys the L-ENV-1 tolerance and leaves a dangling `if`
**Brief lines 212, 213** (and the same numbers in the Gate r1 response, line 18). Re-read today:

```
78:  if (/^WebSocket connection to 'wss?:\/\/localhost:\d+\/app\//.test(text)) {
79:    return `Echo websocket to local vite host refused (L-ENV-1, no Reverb locally) …`
80:  }
81:  // F-W2-37 (MEASURED run 12, receiver context): …
83:  if (/\/dashboard(?:[/?#]|$)/.test(page.url()) && ((… /403/.test(text)) || /^Access denied:/.test(text))) {
85:  }
…
92:  // F-W2-39 (MEASURED run 19, P3): …
94:  if (/\/purchases\/supplier-invoices\//.test(page.url()) && /^Encountered two children with the same key/.test(text)) {
96:  }
```
The F-W2-37 tolerance is **`:81-85`**, the F-W2-39 tolerance is **`:92-96`**. An orchestrator executing "delete `:79-83`" verbatim removes the L-ENV-1 `return` and its closing brace plus the F-W2-37 comment and opening `if` — a TypeScript syntax error and a lost, still-needed environment tolerance (no Reverb locally). r2 explicitly re-verified the *workaround* line numbers (correctly: `:478`/`:304`/`:441`) but carried r1's tolerance ranges through unchecked; this is a mechanical deletion instruction, so the numbers must be right.

**Fix (one line):** in the deletion table replace `wave2-support.ts:79-83` with `wave2-support.ts:81-85` and `:90-94` with `:92-96`, and add "delete the comment lines with the predicate; leave the L-ENV-1 tolerance at `:76-80` and the harness-probe tolerance at `:86-91` in place."

### L2-G2-06 [MAJOR] — B56 was not dropped, it was moved into B50 — whose cited source says something else and carries verdict OK/ALREADY
**Brief lines 14, 50, 55.**

The Gate r1 response says: "**B56 dropped** (it was an `(m)` row justifying the wrong grain); the guarantee now lives in **B50**, which is cited, not remembered" (line 14), and line 50 states "Rows **B48/B50** are cited from `01-research.md` §3". The cited source reads:

```
docs/superpowers/audits/2026-09-01-wave2-po-flow/01-research.md:208
| B50 | Traceability is navigable both ways: bill → receipts → PO, and PO → receipts → bills → payments | ✅ | ✅ | ✅ | … | OK | ALREADY (⚠ the landed-cost panel returns null in most states …) |
```
B50 says nothing about match-row grain, and its verdict is **OK / ALREADY**. The brief's B50 (line 55) appends "**and a 3-way match row compares one PO line** (ordered vs received vs invoiced vs matchable are PO-line aggregates)" and flips Gap/Decision to **WRONG / MATCH — task 3**. So a memory-sourced guarantee the gate rejected as `(m)` has been re-shipped inside a row that *looks* code-cited, and a research-doc verdict was silently reversed. (B48's guarantee text is likewise reworded — source: "a receiver/cashier can receive goods but cannot confirm a PO or post a bill; submit ≠ create" (`01-research.md:206`) vs the brief's "never asks the server for data that role may not read" — but B48's WRONG/MATCH verdict does match the source, so that one is wording drift only.)

This does **not** change the technical decision: one row per PO line is independently forced by the payload's own shape (`SupplierInvoiceController.php:720-723`), which is a code argument, not a benchmark argument. It is a provenance defect, and convention 10 exists precisely so an `(m)` claim stays visibly `(m)`.

**Fix (one line):** restore B50 to its source guarantee and OK/ALREADY verdict with a pointer to `01-research.md:208`, and re-add the match-grain guarantee as its own row labelled `(m)` (e.g. B56′, vendor cells `✅ (m)`), whose AutoERP-today cell carries the `SupplierInvoiceController.php:720-723` citation — i.e. "memory-sourced benchmark, code-verified gap". Also soften line 50 to "B48/B50 are adapted from `01-research.md` §3; the appended clauses are `(m)`."

---

### Minors

- **L2-G2-07 [MINOR] — `usePermissions.ts:43` is the wrong line for `treasury`.** Brief lines 23 and 96 cite `usePermissions.ts:43` for `MODULE_PERMISSIONS.treasury = ['treasury.view']`. Actual: **`:41`**; `:43` is `remittances: ['instruments.remit']`. Carried from r1 unverified. **Fix:** change both to `:41`.
- **L2-G2-08 [MINOR] — `seedAuth.ts:29` is the wrong line for the `roles` default.** Brief lines 104 and 274 (dispatch prompt) cite `src/test/seedAuth.ts:29`; `:29` is `const email = …`, the default is `const roles = overrides?.roles ?? []` at **`:30`** (signature `:19-25`, and the seeded `user` object at `:33-40` has no `permissions` key at all — which is what makes the role fallback fire, so the brief's *reasoning* is right). **Fix:** `:29` → `:30` in both places.
- **L2-G2-09 [MINOR] — the cash-position assertion is at `dashboard.test.tsx:160`, not `:157`.** Brief line 108. `:157` is `await waitFor(() => {`; `expect(screen.getByTestId('cash-position-widget'))` is at `:160`. The module mock at `:30-32` is cited correctly. **Fix:** `:157` → `:160`.
- **L2-G2-10 [MINOR] — two path-less citations resolve to surprising paths.** `CashPositionWidget.tsx:124-131` is `src/features/**treasury**/components/CashPositionWidget.tsx` (not `features/dashboard/`), and `PurchaseOrderDetailPage.tsx:678-703` is `src/features/**documents**/purchase-orders/PurchaseOrderDetailPage.tsx` (not `features/purchases/`). Both line ranges are exact. **Fix:** prefix both with their real directory on first mention (brief lines 96 and 226).
- **L2-G2-11 [MINOR] — the strict-mode flag omits `wave2-shared.ts:450`.** Brief line 226 lists the bare `page.getByRole('dialog')` sites as `part1:486,:1378,:1430,:1478`; `e2e-local/wave2-shared.ts:450` (`openReceiveDialog`) is a fifth. Same "possible, not observed" status. **Fix:** add `wave2-shared.ts:450` to the list.
- **L2-G2-12 [MINOR] — name the one `src` consumer that mounts `ConfirmDialog` *and* has a `getByRole('dialog')` spec.** The mandatory 46-file grep is the right instruction, but the executor can be pointed straight at the only live collision candidate: `src/features/documents/sales-orders/SalesOrderDetailPage.tsx` mounts `ConfirmDialog` (5 sites) and `__tests__/SalesOrderDetailPage.tenantScope.test.tsx:474` does `screen.getByRole('dialog')` on the invoice picker. It should stay unambiguous (the `ConfirmDialog` is closed in that flow, and `ConfirmDialog.tsx:46` returns `null` when closed), but `ConfirmDialog` portals to `document.body` (`:50`), so RTL *would* see it if both were open. The other six `getByRole('dialog')` specs are on components that never mount a `ConfirmDialog`. **Fix:** name that file/line in the consumer-check paragraph (line 133) and pre-authorise scoping the query with `within(...)`/`getAllByRole` if it turns ambiguous.
- **L2-G2-13 [MINOR] — the `aria-modal` negative arm is not independent red evidence.** Brief line 130 labels it "NEGATIVE, RED-by-intent". It is red today only as collateral of arm 1 (`getByRole('dialog')` does not resolve yet); it proves nothing that arm 1 does not already prove, and its value is as a pin against a future "consistency" edit. **Fix:** relabel "[pin, not independent evidence] — red today only because the role does not exist yet".
- **L2-G2-14 [MINOR] — `Dashboard.tsx:101-118` overshoots the onboarding query.** Brief line 92. The query block is `:101-112`; `:114-118` is `hasIncompleteRequired` + the dismiss handler. **Fix:** `:101-112`. (Same class: `LoginPage.tsx:87` at brief line 222 is the `roles:` line; `permissions: data.user.permissions` is `:88`.)

---

## Disposition audit — r1 findings against the code

| r1 # | Brief claims | Verified against code | Verdict |
|---|---|---|---|
| **L2-G1-01** BLOCKER | Task 3 rewritten: reproduce + record both rows first, default fix = dedupe to one row per `po_line_id` with `price_variance` OR-ed, composite only if rows differ, invoice-line grain escalated; B56 dropped | `SupplierInvoiceController.php:719` `'po_line_id' => $invoiceLine->source_line_id`; `:720-723` `ordered/received/invoiced/matchable` all read `$poLine` (`matchable` from `matchableQty($poLine)` `:712`); `:715-716` `price_variance` the only per-invoice-line value — **exact**. Brief lines 139-143 make the reproduction+recording a hard precondition; 155-161 the collapse; 163 the fallback with the `DataTable.tsx:157,262` caveat; 165 the escalation. `W2-PART-6` expected row (line 224) now says "**one** row for the shared PO line, showing ordered 10.0000 / received 10.0000 / invoiced 10.0000 **once**" | **FIXED on substance.** Residual: tests not executable as written → G2-03, G2-04; hook placement → G2-02; B56's guarantee reappears inside B50 → G2-06 |
| Are the rewritten tests red today? | Arm 1 "red on all three counts"; arm 2 "length 2, distinct ids"; arm 3 regression guard | Today `data={invoice.match.per_line}` (`:595`) renders one testid span per row (`:173`), so a two-row shared-`po_line_id` fixture gives `getAllByTestId(/^match-row-/)` = 2 → arm 1 red ✔; a 3-row fixture (2 shared + 1 other) gives 3 → arm 2 red ✔; `:409` `getByTestId('match-row-po-line-1')` is the only external consumer (repo-wide grep) and is unaffected by the collapse ✔ | **Correct**, except the "appears once" (G2-04) and price-variance (G2-03) sub-assertions |
| **L2-G1-02** MAJOR | `W2-SETUP-7` expectation corrected: `wave2-receiver` holds `documents.view` | `e2e-local/wave2-po.part1.spec.ts:766` `permissions: ['purchase-orders.receive','purchase-orders.view','documents.view','inventory.view']` — **exact**. Server permissions are hydrated (`LoginPage.tsx:88`, `usePermissions.ts:129`) and neither key is in `SERVER_AUTHORITATIVE_PERMISSIONS` (`:10-18`) | **FIXED** (nit → G2-14) |
| **L2-G1-03** MAJOR | `dashboard.test.tsx:139`/`:154` named, fix = `seedAuth({roles:['admin']})` at `:104`, rationale = not in `SERVER_AUTHORITATIVE_PERMISSIONS` (`usePermissions.ts:10-18`) so the role fallback applies | `:139` *displays recent documents section*, `:154` *displays recent payments section*, `beforeEach` `seedAuth()` at `:104` — **exact**. `SERVER_AUTHORITATIVE_PERMISSIONS` at `:10-18` holds only pricing/bank-statements/support-access → `documents.view`/`payments.view` fall through to `PERMISSIONS[…]` at `usePermissions.ts:137-145`, and `permissionsMap.generated.ts:64,157` both list `admin` — **the stated mechanism is correct**. `seedAuth` seeds no `permissions` field at all (`seedAuth.ts:33-40`), so `serverPermissions` is `undefined` and the fallback is the only path ✔. `Dashboard.tenantScope.test.tsx:41` default `roles: string[] = ['admin']` ✔ stays green | **FIXED** (line nits → G2-08, G2-09) |
| **L2-G1-04** MAJOR | `/dashboard/stats` → backend debt + ticket `L-2-FU-dashboard-stats-ungated` (lane L-14), no FE gate, "do not write 'no gap'"; B54 rescoped to gated endpoints | `apps/api/app/Modules/Dashboard/routes.php:20-21` carries no `can:` ✔. Brief line 93 and B54 (line 56) are consistent with each other and with the OUT scope (line 77) | **FIXED, consistent** |
| **L2-G1-05** MAJOR | Both tolerance clauses called out (incl. the `/^Access denied:/` catch-all); three workaround sites with re-verified numbers; part 2 re-run required | Catch-all confirmed at `wave2-support.ts:83`. Workarounds: `part1:478` ✔, `part2:304` ✔, `wave2-shared:441` ✔ — r2's correction of r1 is right. **But** the tolerance ranges `:79-83` / `:90-94` are both two lines low → **G2-05**. Part 1 = 50 tests (`L0-part1-SUMMARY.md:10-20`) ✔ | **PARTIALLY FIXED** → G2-05 |
| **L2-G1-06** MINOR | Restated as "possible, not observed", exact condition given | `ConfirmDialog.tsx:46` returns null when closed ✔; no `ConfirmDialog` under `src/features/purchases/supplier-invoices/` (grep empty) ✔; `PurchaseOrderDetailPage.tsx:678-703` = two `ConfirmDialog`s + `ReceiveGoodsDialog` on independent state ✔ | **FIXED** (one site missing → G2-11) |
| **L2-G1-07** MINOR | Went further than asked: `aria-modal` **omitted** + a negative test pinning it; 46-consumer count | `ConfirmDialog.tsx` is 106 lines with no keydown/focus/inert ✔; `Modal.tsx:141-142` has `role`/`aria-modal` ✔; `grep -rln ConfirmDialog src \| grep -v '\.test\.'` = **46** ✔ | **FIXED**; the omission is the right call (arguably better than r1's ask) — label nit → G2-13 |
| **L2-G1-08** MINOR | `useId()` pinned at `:44` above the `:46` early return | `:44` `const { t } = useTranslation('common')`, `:46` `if (!isOpen) return null`, `:51` backdrop, `:52` panel, `<h3>`/`<p>` at `:132`/`:133` — **all exact**, and the "inner panel not the backdrop" instruction matches `Modal.tsx:139-141` | **FIXED** — and this is exactly the trap left unpinned for task 3 (G2-02) |
| **L2-G1-09** MINOR | Comment required only on the composite branch | `DataTable.tsx:157`, `:262` selection identity — cited on the fallback branch only (brief line 163); correct, since the default branch keeps `row.po_line_id` | **FIXED** |
| **L2-G1-10** MINOR | `CashPositionWidget` row added, already gated both layers | `Dashboard.tsx:343` mounts it inside the `lg:grid-cols-2` grid at `:342` ✔; `CashPositionWidget.tsx:124-131` `if (!canAccessModule('treasury') \|\| !hasModule('Treasury')) return null` ✔ (fail-closed, `usePermissions.ts:165-172`) | **FIXED** (line nit → G2-07, path nit → G2-10) |
| **L2-G1-11** MINOR | `source_line_id` overload pre-declared as record-not-fix + ticket | `SupplierInvoiceController.php:707` reads it as a PO `DocumentLine` id; `:626-631` (`consumedReceipts`) plucks the same column and queries `GoodsReceiptLine` at `:637-640`; `SupplierInvoiceCreatePage.tsx:444` writes the PO line id — **exact** (`:626-641` is the right span) | **FIXED** |
| **L2-G1-12** MINOR | Two baselined `Dashboard.tsx` entries named, ratchet is content-keyed | `tools/audit-design-system-baseline.json:356-357` are the two `C3` onboarding-banner button entries, matching `Dashboard.tsx:199-206` and `:209-216` ✔; zero `ConfirmDialog.tsx` entries ✔; entries are `TYPE\|path\|normalised-source\|#n` — content-keyed ✔ | **FIXED** |
| (nit) route names | Route names used with line as secondary locator | `documents.index`/`payments.index`/`dashboard.stats`/`treasury.cash-position` used at brief lines 92-96 | **FIXED** |
| (vacuous tests) | Six arms relabelled `[regression guard]` / `[mechanism only]` or replaced | Spot-checked 4: task 1 arm 1 (**red** — queries fire today) ✔; task 1 arm 3 admin `[regression guard]` (passes before and after) ✔; task 3 arm 3 `:409` `[regression guard]` (unchanged by the collapse) ✔; task 3 arm 4 console-spy `[mechanism only — do not report as evidence]` ✔. Task 2 arm 4 mislabelled → G2-13 | **FIXED**, one label nit |

---

## Fresh pass on r2-new content

**Honesty of dropping B56 / rescoping B54.** Rescoping B54 to *gated* endpoints is honest: it is a new `(m)` row, labelled, and `/dashboard/stats` is not swept under it — it is routed to a named ticket and lane, with an explicit "do not write 'no gap'" (brief line 93). That satisfies "a baseline guarantee the flow silently lacks is a finding" because it is not silent. Dropping B56 is **not** honest as executed → G2-06.

**[red] / [regression guard] labels.** Four spot-checks pass (table above). The only mislabel is task 2 arm 4 (G2-13). Genuinely red arms as written: task 1 arms 1-2, task 2 arms 1-3, task 3 arms 1-2 — subject to G2-02/03/04 making arms 1 and 3 of task 3 writable.

**Rule 18 / design tokens on touched lines.** Task 1 wraps the recent-activity cards in a predicate and touches no colour class; the two `Dashboard.tsx` baseline entries (`:199-206`, `:209-216`) are explicitly quarantined (brief line 196). Task 2 adds only ARIA attributes. Task 3's icons already use `textColors.warningDark`/`textColors.success` (`SupplierInvoiceDetailPage.tsx:202,204`) — the `data-testid` G2-03 asks for adds no class. **Clean.** No token/opacity interpolation anywhere in the proposed change.

**Rule 14 / tenant-scoped query keys.** `Dashboard.tsx:102,126,135,144` all use `tenantScopedKey([...])`; only `enabled` predicates change (brief lines 99, 238), and `audit:keys` is in the verification block (line 191). **Clean.**

**Other conventions.** Rule 11: no new user-facing string in any task (the G2-03 fix keeps it that way — `data-testid`, not `aria-label`). Rule 19: `formatCurrency`/`formatQuantity` paths untouched; no `parseFloat`. Rule 3: "no `any` in the fixtures" stated (line 174). Convention 09 non-applicability stated and correct (no catalogue entity, no migration, no unique key, no importer). Convention 11: the two duplicate-surface observations (`Modal`/`ConfirmDialog`, `source_line_id`) are recorded-not-fixed with tickets, and the collapse itself is the convention-11 argument for task 3. Owner rulings: hiding the card rather than shipping an empty "no payments" panel is the OQ-11 reading and is explicitly required (line 100); no new competing colour/badge/accent.

**Verification block.** `pnpm audit:design-system` (`package.json:15`), `audit:keys` (`:14`), `typecheck` (`:21`) all exist; scoping away from full `pnpm lint` (`:10`, which bundles `audit:quantity`, `audit:i18n:local`, `test:eslint-rules`, `test:tools`) is right for this machine, and the vitest worker-pool warning is present.

---

## Conditions (all one-line edits; re-gate not required if applied verbatim)

1. G2-01 — add the `e2e/composite-item-delete.spec.ts:98,164` row to the orchestrator deletion table.
2. G2-02 — pin the `useMemo`/plain-const placement relative to `SupplierInvoiceDetailPage.tsx:114`.
3. G2-03 — authorise `data-testid`/`data-variance` on `SupplierInvoiceDetailPage.tsx:202,204`.
4. G2-04 — give the task-3 fixture distinct per-column values and restate the count assertion.
5. G2-05 — `wave2-support.ts:79-83` → `:81-85`, `:90-94` → `:92-96`.
6. G2-06 — restore B50 to its source guarantee/verdict; re-add the match-grain guarantee as its own `(m)` row.
7. G2-07..G2-14 — eight one-token citation corrections and labels.
