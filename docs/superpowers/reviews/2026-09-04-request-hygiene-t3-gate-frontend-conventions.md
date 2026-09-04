# Gate — Request Hygiene Phase A, Task 3 (WEB half) — frontend-conventions-reviewer

| | |
|---|---|
| Date | 2026-09-04 |
| Worktree | `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/rh-t3` |
| Branch | `lane/rh-t3-payments-list` @ `f7a3e1c9e` |
| Base | `b133caf21` |
| Web commits reviewed | `522bd92bc` (page, tests, dashboard, e2e), `c008547fe` (fix round 2: render-phase reset + PaymentStatus alias) |
| Scope | `apps/web`, `packages/` only. `apps/api` read for consumer contracts only (treasury-reviewer owns it). |
| Diff | 8 files, +217 / −61 (`git diff b133caf21..HEAD -- apps/web packages`) |

## VERDICT: **CHANGES**

One blocking finding. It requires **no product-code change** — a one-line, net-zero
`audit-design-system-baseline.json` re-key plus a handback correction. Everything else on the
brief's checklist verified clean, including the two fix-round-2 defects (render-phase reset,
`PaymentStatus` alias), which are correctly and completely closed.

---

## 1. Blocking

### B-1 (BLOCKER) — the lane silently adds a NEW `audit:design-system` violation naming its own file, and the handback claims the opposite

- `apps/web/src/features/treasury/PaymentListPage.tsx:66` — `const paymentStatusTones: Record<PaymentStatus, StatusTone>`
- `apps/web/tools/audit-design-system-baseline.json:791` — `"C6|src/features/treasury/PaymentListPage.tsx|Record<string, StatusTone>|#1"` (unchanged by the lane; the baseline file is not in the diff)
- Handback `docs/handoff/HANDBACK-request-hygiene-T3-2026-09-03.md` §2 D6: *"No violation is reported against any file this lane changed."*

**Falsifying scenario (run on this branch, `apps/web`):**

```
$ node tools/audit-design-system.mjs; echo "EXIT=$?"
[sweep-progress] Design-system audit C1-C6 violations: 811
[gate-summary] Design-system baseline: 795 acknowledged, 16 new, 12 stale baseline entries
New design-system violations:
  …
  src/features/treasury/PaymentListPage.tsx:66:27 C6 Local status map or switch should use StatusBadge/statusTone (Record<PaymentStatus, StatusTone>)
  …
Stale design-system baseline entries; shrink tools/audit-design-system-baseline.json:
  …
  C6|src/features/treasury/PaymentListPage.tsx|Record<string, StatusTone>|#1
EXIT=1
```

At base the same construct was **acknowledged**, not new:

```
$ git show b133caf21:apps/web/src/features/treasury/PaymentListPage.tsx | grep -n paymentStatusTones
47:const paymentStatusTones: Record<string, StatusTone> = {}
```

`Record<string, StatusTone>` matched baseline entry `:791` exactly. Fix round 2 changed the
annotation to `Record<PaymentStatus, StatusTone>`, which re-keys the C6 signature: the old entry
goes **stale** and the new signature is reported as **new debt on a file this lane wrote**. The
lane is the sole cause (1 of the 16 "new" and 1 of the 12 "stale" are its; the other 15/11 are
pre-existing `ImportWizardPage.tsx` / `UnmappedUnitTextsPanel.tsx` drift on `dev`, untouched here —
so this lane does not newly break CI, but it does put its own file on the list and then reports the
list as clean).

**Fix directive:** edit `apps/web/tools/audit-design-system-baseline.json:791` from
`C6|src/features/treasury/PaymentListPage.tsx|Record<string, StatusTone>|#1` to
`C6|src/features/treasury/PaymentListPage.tsx|Record<PaymentStatus, StatusTone>|#1`, and correct
handback §2 D6 / §R2.6 to state that the C6 entry was re-keyed (net zero, same single construct on
the same line).

**Pre-authorisation against the baseline-absorption rule:** this is explicitly *not* `--write-baseline`
absorbing a diff's new debt. The construct, the file and the line are the same acknowledged debt;
only the detector's snippet key changed. Do **not** "fix" it by reverting to
`Record<string, StatusTone>` — the enum-typed map is the whole point of fix 2 (a future
`PaymentStatus` member becomes a compile error at `:66`), and do not delete the map either (that
would drop the explicit `reversed → neutral` override the detail page carries).

---

## 2. Non-blocking

### N-1 (MAJOR, non-blocking) — the Dashboard S-2 fix ships with no regression guard
`apps/web/src/features/dashboard/Dashboard.tsx:152` now sends `/payments?page=1&per_page=5`, but
`src/features/dashboard/dashboard.test.tsx:206` only asserts `…filter(url => url.startsWith('/payments'))).toHaveLength(1)` — the *count*, never the URL. Nothing fails if a future edit
reintroduces an unbounded read. Plan Step 9 didn't ask for it, so this is not a deviation, but the
one behaviour change that actually removes an unbounded read from a hot screen is the one with no
falsifying test. **Fix directive:** add
`expect(requestedUrls).toContain('/payments?page=1&per_page=5')` at `dashboard.test.tsx:206`.

### N-2 (MINOR) — the exported `Payment` row type still misstates two API fields
`PaymentListPage.tsx:42` declares `amount: number`, but `apps/api/app/Modules/Treasury/Domain/Payment.php:37` documents `@property numeric-string $amount` with cast `'amount' => 'decimal:3'` (`:121`) and `PaymentController::formatPayment` emits `$payment->amount` verbatim (`:2207`) — i.e. a JSON **string**. `PaymentDetailPage.tsx:74` correctly uses `amount: string`. Likewise `partner_name`/`payment_method_name` are declared non-null at `:45,:47` but the controller emits `$payment->partner?->name` / `$payment->paymentMethod?->name` (`:2191,:2199`) — which is exactly what the two `no-unnecessary-condition` ESLint warnings at `PaymentListPage.tsx:159,163` are telling you. No runtime impact today (`formatCurrency` accepts `string | number`, rule 19 respected — no `parseFloat`/`Number()` anywhere in the touched lines). But round 2 *promoted this interface to an exported shared contract* (`:39`) and re-pointed the test fixture at it, so the wrong money type is now the canonical FE shape and the test fixture feeds numbers where production feeds strings. **Fix directive (follow-up):** `amount: string`, `partner_name: string | null`, `payment_method_name: string | null`.

### N-3 (MINOR) — `TreasuryTenantScope.test.tsx:136` generic mock meta (brief item 6, D5) — **acceptable to merge, fix cheap**
`: { data: { data: [], meta: { total: 0 } } }` makes `data?.meta` truthy at `PaymentListPage.tsx:262`, so `OffsetPagination` renders with `currentPage`/`lastPage`/`perPage` `undefined` ("Page undefined of undefined"). No crash, no assertion depends on it, suite green. **Ruling: acceptable — not blocking.** But the mock is untyped, so `tsc` cannot catch that it violates the now-**required** `PaymentsResponse.meta: OffsetPaginationMeta` — that is precisely how the next contract drift will hide. Give it the six fields.

### N-4 (MINOR) — other hand-rolled payment-status vocabularies (brief item 2 grep) — **ruling: acceptable to leave**
- `PaymentDetailPage.tsx:70` — hand-rolled but *correct* against the enum today; already recorded as follow-up R2.5 item 2, and its `PaymentType` at `:49` shows the alias it should adopt. Out of this lane's scope. **Acceptable.**
- `src/features/treasury/treasury.test.tsx:797` — still the **wrong** union `'pending' | 'completed' | 'cancelled'`, in a `MockPaymentDetail` for `PaymentDetailPage` (not the list page). Only the value `'completed'` is used, so it cannot produce a false green about `failed`/`reversed`. **Acceptable**, fold into the R2.5-2 follow-up.
- `src/utils/statusMapper.ts:22` — a third FE `PaymentStatus` (`'pending' | 'completed' | 'failed' | 'refunded' | 'cancelled'`), matching no backend enum. `grep -rn "statusMapper" src` outside the file returns nothing: dead module. One-surface-per-concept debt, not this lane's. **Acceptable**, worth a deletion ticket.

### N-5 (MINOR) — `e2e/payments.spec.ts:106` route glob is now dead for the list GET
`page.route('**/api/v1/payments', …)` (no trailing `**`) matched the old query-less list GET in its `else` branch. After the redirect that follows the POST, the list read is `/api/v1/payments?page=1&per_page=25`, which the glob no longer matches, so it falls through to the real network in an otherwise fully-mocked spec. The test only asserts `toHaveURL(/\/treasury\/payments/)`, so it does not break — but the mock is now silently dead. **Fix directive:** `'**/api/v1/payments**'` with the existing method check. (Lines 37/55/69 already use `**` and are fine.)

### N-6 (INFO) — W8 `per_page=100` reads: pre-ruled by the plan, but note the asymmetry
`e2e/money-campaign/w8-isolation.spec.ts:71` (`moneySnapshot`) and `:169` (MTP-ISO-03 leak sweep) send `/payments?per_page=100` with **no** `page`. Before this lane that took the unbounded `->get()` branch (whole set); it is now page 1 of 100. Plan Step 10 pre-rules these as "intentionally bounded campaign fixtures whose seeded cardinality stays below 100", so **not a finding** — recorded only because `moneySnapshot`'s "no row written" comparison at `:71` is the *same* whole-set→first-page semantic change the lane correctly fixed in `w5c-support.ts`, and would silently stop falsifying if a fixture ever exceeds 100 rows. Same for `e2e/campaign/onboarding.campaign.ts:732` (promotion-gate campaign; `apiRecords` → `requireApiData` reads `.data`, unaffected by the added `meta`).

### N-7 (INFO) — French `reversed` reads "Annulé"
`src/locales/fr/treasury.json` `payments.statuses` renders `reversed`, `voided` and `cancelled` all as "Annulé". Pre-existing, untouched, and the label path was already reachable before this lane (only the *tone* changed) — but a reversal presented as "cancelled" in French is a copy defect worth a ticket.

### N-8 (INFO) — shared follow-ups, already recorded by the lane
(a) extract the render-phase reset into a shared hook once T2 and T3 have both merged (R2.5-1 — agreed, and the T2 gate already ruled the duplicate deliberate);
(b) `OffsetPagination` is driven by `data.meta.current_page`, not local `page`, so during a `keepPreviousData` transition prev/next compute from the previous page's meta. `StockMovementsPage.tsx:430-442` (T2) is byte-identical in this respect, so this is a shared, already-ruled pattern — fold it into the shared-hook extraction, not into this lane.

---

## 3. What held up (verified, brief items 1–6)

**Item 1 — canonical list page.** `PaymentListPage.tsx`
- `OffsetPagination` with all six meta fields, `onPageChange={setPage}`, `onPerPageChange` setting per-page **and** resetting to page 1: `:262-276`. Byte-equivalent to T2 `StockMovementsPage.tsx:430-442`.
- `DataTable` `:240`, `SearchInput` `:228`, `ListPageLayout` `:213`, `StatusBadge`/`statusTone` `:188` — no raw `<table>`, no raw form control, no parallel picker.
- `tenantScopedKey(['payments', search, page, perPage])` `:105`; `placeholderData: keepPreviousData` `:117`.
- URL order **search → page → per_page** `:107-111`, matching the plan's exact snippet.
- Render-phase reset `:88-93` mirrors T2 `:127-137` exactly (same `JSON.stringify` signature, same guarded `setState`-during-render). **StrictMode-safe:** `src/main.tsx:20` wraps the app in `StrictMode`; the reset is guarded by `appliedFilterSignature !== filterSignature`, so the second render is a no-op. The reset block sits **above** the `useQuery` call (`:93` vs `:104`), so the key is read post-reset — the property the fix exists for.
- No hardcoded strings: every label goes through `t()` (`:136,148,169,176,186,195,202,206,214,217,218,223,231,237,249,250,255`); pagination copy is owned by `OffsetPagination` and `common:pagination.{page,of,showing,rowsPerPage,item,items,previous,next}` exist in en/fr/ar.
- No hardcoded Tailwind colours in touched lines — `tokens`/`textColors` only (`:10`).
- Rule 19: no `parseFloat`/`Number()` on money anywhere in the file; amounts render through `formatCurrency` (`:124-129, :198`), which accepts `string | number` and formats via big.js (`src/lib/format.ts:118-128`). No product-quantity surface in this diff, so the units.decimal_places contract does not apply.

**Item 2 — status union.**
- `PaymentListPage.tsx:32` aliases the **fully-qualified** `App.Modules.Treasury.Domain.Enums.PaymentStatus`. FQN is required and correct: `packages/shared/types/generated.d.ts:321` (Billing) and `:875` (Document) both declare a different `PaymentStatus`; the Treasury one at `:2611` is `'pending' | 'completed' | 'failed' | 'reversed'`, matching `apps/api/app/Modules/Treasury/Domain/Enums/PaymentStatus.php`.
- `paymentStatusTones: Record<PaymentStatus, StatusTone>` `:66-71` covers all four; exhaustive by type, so a new enum member is a compile error here.
- **Conservation check (zero visual change):** expanding through `statusTone()` (`src/components/atoms/StatusBadge/statusTone.ts:10-31`), the built-in map already yielded `pending→pending`, `completed→success`, `failed→danger`, and `reversed→` (absent) `→neutral` fallback. The new explicit map yields exactly the same four tones. The refactor is tone-preserving; nothing pops out, nothing competes with the page's one main element.
- i18n: `payments.statuses.{pending,completed,failed,reversed}` present in **all three** locales (`src/locales/{en,fr,ar}/treasury.json`), plus the `dishonored` key used at `:189`. No key added, none missing.
- Grep for other hand-rolled unions → N-4, all ruled acceptable for this lane.

**Item 3 — invalidation still matches the new key shape.** Every `payments` cache op in `apps/web/src` uses the length-tolerant namespace predicate (`k.length >= 3 && k[0] === 'payments' && k[-2] === tenantId && k[-1] === companyId`, `PaymentForm.tsx:249-263`): `PaymentForm.tsx:707`, `PaymentDetailPage.tsx:206,268`, `useSmartPayment.ts:126`, `RecordPaymentModal.tsx:336`. The new 6-element key `['payments', search, page, perPage, tenant, company]` matches all of them. No bare `queryKey: ['payments']` invalidation exists that a positional-prefix match could break. `pnpm audit:keys` names **no** touched file (see §4).

**Item 4 — Dashboard, RULED: strict improvement, accept.** At base, `PaymentController::index` (`b133caf21`, `:274-301`) read neither `limit` nor `sort` and, absent `page`, took an unbounded `->get()`. `Dashboard.tsx:427` maps `recentPayments` with **no client-side slice**, so the "Recent payments" widget was rendering *every payment in the company* — the S-2 defect in its purest form. `sort=-created_at` was a dead parameter; there is no regression to weigh. `page=1&per_page=5` against `payment_date DESC, id DESC` is the right semantics for a treasury widget (matches the list page's ordering, matches the Odoo/Dolibarr baseline of ordering payment lists by payment date) and is what the plan Step 9 specifies. The only behavioural nuance — a back-dated payment entered today sorts by its payment date, not its entry time — is standard and does not warrant adding a `sort` parameter this lane deliberately avoided. Guard owed: N-1.

**Item 5 — e2e (type-check only, no browser run, per brief).**
- `payments.spec.ts:6-22` two shared meta fixtures; used at `:41` (populated), `:59`, `:73`, `:124` (empty) — all four GET arms as the plan required. `:51` `Page 1 of 1` will resolve: `OffsetPagination` renders `Page 1 of 1` as one `<span>`'s text, Playwright's text engine matches the smallest containing element (no strict-mode ambiguity — "Showing 1 to 1 of 1 results" is a sibling span and does not contain the phrase), and the suite runs in the default `en` locale (`i18n-fr.spec.ts:160` has to opt in with `?lang=fr`). Route globs at `:37,55,69` carry the trailing `**` needed now that the list GET always has a query; `:106` does not — N-5.
- `w5c-support.ts:208-233` — page-walking `allPaymentIds()` is the plan's exact snippet. **Terminates**: `page` increments monotonically and `lastPage` comes from the server; `expect(body.meta.current_page).toBe(page)` per iteration catches a clamping server rather than looping. Reads the raw response because `treasury-support.ts`'s `asJsonResult()` drops sibling `meta` — the docblock at `:204` says so and it is true. `get()` is still imported and used at `:98,128,154,168,180,241,312`, so the import is not orphaned.
- `expenses-lifecycle.spec.ts:242-247` — the stale "omits `page` → `->get()` branch" comment is gone and the replacement is accurate.
- W8 `per_page=100` reads → N-6 (plan-pre-ruled).

**Item 6 — new tests are executable and meaningful.**
- `PaymentListPage.search.test.tsx:107-139` — the reset test pages forward to 2 first (`:118-123`), then types, then asserts `/payments?search=Alice&page=2&per_page=25` was **never** requested (`:138`). That negative is the whole point and is genuinely falsifying: the handback §R2.1 records the red against `HEAD~1`'s effect-based reset (`expected "spy" to not be called with arguments`), and the mechanism is sound — an effect fires after React Query has already read the stale key. The mock now echoes the requested page over a two-page set so the next button is actually enabled, i.e. the test can reach the state it claims to test.
- `PaymentListPage.test.tsx:106-115` — labels all four statuses; fixture rows 3/4 (`:72-73`) are `failed`/`reversed`. Honestly declared in §R2.2d as a **rendering guard**, not a test-first red — the red for the union fix is the typecheck (`TS2322` on `'failed'`/`'reversed'` against the old union), which is the correct falsifier for a type-level defect. Accepted as declared.
- D5 → N-3.

**Mechanism audit (no evasion).** `git diff b133caf21..HEAD -- apps/web packages | grep -E '^\+.*(eslint-disable|@ts-expect-error|@ts-ignore|skip\(|todo\(|only\()'` → **no matches**. No alias table, no renamed-but-equivalent literal, no detector-keyword suppression, no locale file touched, no `tools/` file touched, **no baseline written** (`git diff --stat … -- apps/web/tools` is empty) — which is what makes B-1 an omission rather than an absorption.

---

## 4. Commands and exact outputs

All run from `<worktree>/apps/web`. Zero leftover vitest workers before and after (`ps aux | grep -c "[n]ode (vitest"` → `0`). Default pool, no `--singleFork`.

**Vitest (path-scoped, the brief's five files):**
```
$ pnpm vitest run src/features/treasury/PaymentListPage.search.test.tsx \
    src/features/treasury/PaymentListPage.test.tsx \
    src/features/treasury/__tests__/TreasuryTenantScope.test.tsx \
    src/features/dashboard/dashboard.test.tsx \
    src/features/dashboard/__tests__/Dashboard.tenantScope.test.tsx

 ✓ src/features/treasury/__tests__/TreasuryTenantScope.test.tsx (2 tests) 311ms
 ✓ src/features/treasury/PaymentListPage.search.test.tsx (3 tests) 870ms
   ✓ … > restarts traversal at page one when the search term changes, without requesting the stale page  393ms

 Test Files  5 passed (5)
      Tests  23 passed (23)
   Duration  2.77s
```
Independently reproduces the handback's arithmetic: 21 before round 2, +1 reset test, +1 status-labelling test = 23. No test deleted (`PaymentListPage.test.tsx` kept all pre-existing cases).

**Typecheck:**
```
$ pnpm typecheck        # tsc --noEmit
(no output)  EXIT=0
$ pnpm typecheck:e2e    # tsc --noEmit -p e2e/tsconfig.json
(no output)  EXIT=0
```

**ESLint (touched files):**
```
$ pnpm exec eslint src/features/treasury/PaymentListPage.tsx \
    src/features/treasury/PaymentListPage.test.tsx \
    src/features/treasury/PaymentListPage.search.test.tsx \
    src/features/treasury/__tests__/TreasuryTenantScope.test.tsx \
    src/features/dashboard/Dashboard.tsx \
    e2e/payments.spec.ts e2e/money-campaign/w5c-support.ts e2e/money-campaign/expenses-lifecycle.spec.ts

Dashboard.tsx        123:47, 189:26, 207:34, 230:26, 263:50, 263:88   (6 warnings)
PaymentListPage.tsx  102:47, 139:11, 159:14, 163:14, 205:15           (5 warnings)
e2e/*                                                                 (3 "file ignored" warnings)
✖ 14 problems (0 errors, 14 warnings)
```
**0 errors.** Both test files lint clean. Every warning is on a line this lane did not write. **`react-hooks/set-state-in-effect` is gone** — deviation D3 independently confirmed closed.

**`pnpm audit:keys`:**
```
$ node tools/audit-tanstack-keys.mjs
[sweep-progress] Gate C — …: 1
[gate-summary] Gate C baseline: 0 acknowledged, 1 new, 0 stale baseline entries
New unscoped TanStack query key violations:
  src/features/uom/hooks/useUnits.ts:53:9 …
```
The single violation is in `src/features/uom/`, **not** touched by this diff (D6 accurate for this audit).

**`pnpm audit:design-system`:** see B-1. `EXIT=1`, 16 new / 12 stale, of which **1 new + 1 stale are this lane's** (`PaymentListPage.tsx:66` C6); the other 15/11 are pre-existing `ImportWizardPage.tsx` (14) + `UnmappedUnitTextsPanel.tsx` (1) drift on `dev`, so `pnpm lint` is already red at base for unrelated reasons.

**`pnpm audit:quantity`:**
```
[audit-quantity] raw quantity display sites: 0 total (0 baselined, 0 new, 0 stale baseline entries)
```

**Not run (out of scope by the brief / by the lane):** Playwright browser suite, live-stack probe of the five-row dashboard and list page 2, any `apps/api` leg.

---

## 5. Merge conditions

1. **B-1** — re-key `apps/web/tools/audit-design-system-baseline.json:791` to `Record<PaymentStatus, StatusTone>`; correct handback §2 D6 and §R2.6. Re-run `node tools/audit-design-system.mjs` and confirm no `PaymentListPage.tsx` line appears under "New" or "Stale".
2. Re-run the five vitest paths + `pnpm typecheck` (both should be untouched by a JSON edit; confirm anyway).
3. Still owed before **promotion** (not before merge, and outside this gate): browser check of the five-row dashboard and payment-list page 2, live Playwright run of `payments.spec.ts` / `expenses-lifecycle.spec.ts` / `w8-isolation.spec.ts`, external POS/mobile consumer evidence, and the treasury-reviewer gate on `apps/api`.

Recommended to land with B-1 or immediately after: **N-1** (one-line dashboard URL assertion) — it is the only falsifying guard the lane's headline fix lacks.
