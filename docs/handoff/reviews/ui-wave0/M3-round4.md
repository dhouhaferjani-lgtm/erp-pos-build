# ADVERSARIAL MERGE-GATE REVIEW — milestone M3 (T10–T13), round 4

**Range:** `d682b38ec..HEAD` (`0d10c7a05`). M3 source commits `d7f3ea21d`, `cdce8ab38`, `1184a83ac`, `85bf6b02d`; fix commit `9ac83f0eb`; evidence commits `ba40f4f83`, `57f708d35`, `1da344919`, `0d10c7a05`.
**Lenses:** `frontend-conventions` — **applies** (route/barrel deletions, i18n namespace + key pruning, design-system baseline, TanStack keys). `general` — **applies**. Rule 19 (money/quantity), tenancy-authz, migrations, Horizon queues, constructor injection — **do not apply**: `apps/api/**` untouched, no money/quantity code added, no new query keys, no backend surface.
**M3 manifest-drift exception honoured:** `git diff d3b0550ed..HEAD -- scripts/factory/manifests` is empty; `check-manifest-drift.sh` exiting 1 is *not* reported as a finding (§2 exception 2).

**Round-2 findings, re-verified at HEAD:** #1 (vacuous `/marketing` guard) **FIXED** — `routes.test.tsx:83` now builds the relative literal `marketing`, and `git show cdce8ab38^:…/routes/index.tsx | grep -c 'path="marketing"'` = 1, so the assertion is non-vacuous. #3 (`_invalidation.ts`) **FIXED** — module and its helper-only tests deleted; zero residual importers; the surviving probe comment's claim is true (`POSLayout.tsx:48` = `tenantScopedKey(['pos','shift',terminalCode])`). #4 **FIXED** (`POSTransactions.tsx:18`, describe labels). #5/#6 **CLOSED** — red-first reconstructions and the 35-key per-key finance grep are now pasted (`HANDBACK…:229-236`, `:256-260`, `:296-333`). #7 **RECORDED** (`HANDBACK…:382`). #2 remains open — see finding 2.

---

## Findings register

### 1. **P1 — CONFIRMED** — T10's acceptance criterion "Orphaned i18n keys removed" is unmet: 15 keys whose sole consumers were the two deleted components survive in `en`/`fr`, and the mandated per-key enumeration was never performed
`apps/web/src/locales/en/pos.json` · `apps/web/src/locales/fr/pos.json`

T10 requires: *"enumerate the `pos:` keys the deleted components were the sole consumers of, and delete them from **all** locales… prove per key with a grep."* §3 checklist item 5 makes it a completeness condition ("or the deletion is incomplete"). Only the two top-level groups `shiftDashboard` and `shifts` were removed. Fifteen leaf keys were missed:

**11 × `pos:xReport.*`** — sole consumer at base was `ShiftDashboardPage.tsx` (`git grep "pos:xReport.title'" d682b38ec` → `…/ShiftDashboardPage.tsx:473`; `.print` → `:570`). At HEAD every one has **zero** consumers:

```
generatedAt grossSales netSales paymentMethods print refundsCount
salesCount salesSummary taxAmount title vatBreakdown      → 0 refs each
```

The `xReport` *group* survives only because `ZReportDetailPage.tsx:286-322` uses a different 7 leaves (`rate,net,vat,gross,method,count,amount`). The criterion is explicitly per-key, not per-group.

**4 × `pos:transactions.*`** — `errors.terminalCreation`, `loading.terminal`, `noLocation`, `noLocationDescription`. Sole consumer at base was `POSShiftsDashboard.tsx:145,161`; the surviving `POSTransactions.tsx` uses only `transactions.disposition.{title,body}`. Zero refs at HEAD.

All 15 are still present in `en` and `fr` (verified by `jq`); `ar/pos.json` has no `xReport` block and nulls for the four `transactions` leaves, so **`ar` needs no change** — the fix is en+fr only.

`HANDBACK…:206` states *"sole-consumer `shiftDashboard` / `shifts` locale groups were deleted"* and pastes only a `jq '{shiftDashboard, shifts}'` proof. That evidence is true but does not discharge the criterion, and no per-key grep exists for T10 — the asymmetry is stark against T12, which **did** paste the full 35-key enumeration (`HANDBACK…:296-333`).

**Failure scenario:** the Arabic i18n backfill lane (`CODEX-DISPATCH-arabic-i18n-backfill-2026-08-10.md`, an active lane recorded in this wave's own `findings:`) works from the `en` locale as the source of truth; it will translate 15 strings for UI that no longer exists, and `arLocaleCoverage.test.ts` will then pin them. Nothing in lint/typecheck/tests detects unreferenced locale keys, so this is silent and permanent. **Fix:** delete the 15 leaves from `en`/`fr` `pos.json` and paste the per-key grep the criterion demands.

---

### 2. **P2 — CONFIRMED (round-2 finding 2, still open — correctly escalated, not closed)** — `/finance` renders a blank content pane, not the `/dashboard` fall-through the ruling was accepted on
`apps/web/src/routes/index.tsx:1977`

Re-affirmed independently: after T12, `<Route path="finance">` carries a `path` but **no `element`** and **no index child**. React Router builds a match branch for any pathed parent and ranks the static `finance` segment above the splat, so `/finance` matches the parent, renders the implicit `<Outlet/>`, and resolves to `null` — app chrome with an empty main region. `OWNER-DECISIONS:58`'s stated basis ("nothing links to `/finance`, so no broken-bookmark risk") rests on a `/dashboard` fall-through that does not occur.

The executor implemented the brief verbatim ("keep the parent, delete the index, add no redirect"), added no unruled behaviour, and has now disclosed it plainly in both required places — `HANDBACK…:384` and `ui-wave0.progress.yaml` `findings:` ("parent/owner must rule before merge"). Per §4 this is close-**before-merge**, and it is an owner decision the executor may not make; it therefore does not by itself block M3. **It must not be merged undisclosed** — the parent terminal audit owes a ruling: leave-as-ruled / add `element` / add an index redirect.

---

### 3. **P3 — CONFIRMED** — T12/T13 red-first evidence is still narrated rather than pasted
`HANDBACK…:337` ("The task-close full suite reported…"), `:341` — T13 pastes the pre-commit source but no red test output; T12 pastes greps but no red run. T10 and T11 were remediated (`:229-236`, `:256-260`); T12/T13 were not. §4 Per-task requires a pasted command + output or a `file:line`. I reconstructed non-vacuity myself: at `1184a83ac^` the `finance` branch contained both `<Route index` and `FinanceHubPage`; at `85bf6b02d^` the `settings` branch contained `path="chart-of-accounts"`. Evidentiary gap only.

---

### 4. **P3 — CONFIRMED** — the retained-as-historical POS docs still present a deleted import as working API
`apps/web/src/features/pos/README.md:99` (`import { ShiftDashboardPage } from '@/features/pos'`), `:22`, `:149`, `:270`, `:520`; `IMPLEMENTATION_SUMMARY.md:109`, `:306-308`, `:367`.

T10 authorises this **only** under the historical-retention disposition with a dated top-of-file supersession note — which is present at `README.md:3` and `IMPLEMENTATION_SUMMARY.md:3`, and the docs grep is pasted with that annotation (`HANDBACK…:216-236`). Brief-compliant; recorded because a reader landing at `:99` still sees a working-looking import, mitigated only by a note ~96 lines above.

---

### 5. **P3 — note, not a defect** — empty leftover directories `src/features/marketing/pages/` and `src/features/pos/pages/ShiftDashboardPage/` remain in the worktree

`find … -type f` = 0 in both. Git does not track empty directories, `git status --porcelain` is clean, and the merge into `dev` cannot carry them — so this is worktree hygiene for the executor only, not a merge defect.

---

## Bypasses attempted that FAILED (the implementation held)

- **Tried to break the build through the deleted barrels/types:** `npx tsc --noEmit` → **rc 0**. `features/pos/pages/index.ts` deleted with zero importers of `@/features/pos/pages`; `ShiftDashboardPageProps`/`Shift`/`Terminal` removals from `features/pos/index.ts:41` break nobody.
- **Tried to find unauthorised baseline movement:** `node tools/audit-design-system.mjs` → **rc 0**, `736 acknowledged, 0 new, 0 stale`. The baseline diff over the whole range is **exactly** the two hand-removed lines (C2 `ShiftDashboardPage`, C3 `POSShiftsDashboard`) — no `--write-baseline` wholesale rewrite.
- **Tried to find a TanStack-key regression from the deleted invalidation module:** `node tools/audit-tanstack-keys.mjs` → Gate C `0 acknowledged, 0 new, 0 stale`.
- **Tried to find a red test:** `vitest run --maxWorkers=1` over `routes.test.tsx`, `POS/__tests__/tenantScope.test.tsx`, `i18nRawKeyCoverage.test.tsx`, `Sidebar.test.tsx`, `HubCard.test.tsx` → **77/77 pass**.
- **Tried to break the preserved borrowed key:** `finance:hub.cards.treasuryOverview.title` resolves `Treasury` / `Trésorerie` / `الخزينة`; `i18nRawKeyCoverage.test.tsx` and `Sidebar/__tests__/Sidebar.test.tsx` byte-unchanged in the range; the `arFinanceHub` cast (`i18n.ts:160-164`, merge at `:356-367`) still type-checks after the `ar` `description` removal.
- **Tried to find a broken E2E navigation to a deleted route:** the only `/pos/shifts` occurrences outside `src` are `e2e/money-campaign/w8-isolation.spec.ts:227` and `w8-support.ts:628` — both **backend API GETs** (`get(request, session, path)`), not `page.goto`. No E2E navigates to any deleted route.
- **Tried to find residual `marketing`:** namespace removed at all four `i18n.ts` sites (`:33`, `:89`, `:192`, `:395`) plus the `ns` array (`:437`); locale files gone; the only surviving hits are the unrelated `common:nav.marketing` / `customersAndMarketing` sidebar **group** labels, which are the six-destination group T11 exists to preserve (`Sidebar.tsx:259`).
- **Tried to invalidate the filtered `/finance` zero:** broad quote-agnostic sweep over `src` + `e2e` + `tools` yields only the seven authorised `HubCard.test.tsx` fixtures (lines 12, 22, 35, 54, 63, 87, 103 — file is **byte-identical** to base) and one self-referential `routes.test.tsx:123` construction. `MTP-GL-28` removal touched nothing else in `finance-permissions.spec.ts` (`MTP-GL-21/-22/-23/-27` intact); `ui-audit-shots.mjs` lost exactly one entry with no renumbering.
- **Tried to find a smuggled manifest regeneration:** `git diff d3b0550ed..HEAD -- scripts/factory/manifests` empty.
- **Tried to find a key deleted while still in use:** every removed `shiftDashboard.*` / `shifts.toasts.*` / `finance:hub.*` key has zero surviving consumers (per-key verified). No surviving test was weakened to pass.

**Reviewer side effects:** none. Read-only throughout; I deliberately did **not** run `gen-route-manifest.mjs`. `git status --porcelain` is empty; nothing staged, committed, or modified.

---

**Blocking set:** finding 1 (P1) — T10's named acceptance criterion is not discharged; fix is deleting 15 leaf keys from `en`/`fr` `pos.json` plus the per-key grep evidence. Finding 2 (P2) stays open as a **parent/owner ruling owed before merge** and is correctly escalated in both the handback and the YAML. Findings 3–5 are P3, ticketable.

VERDICT: CHANGES-REQUIRED
