## ADVERSARIAL MERGE-GATE REVIEW — milestone M3 (T10–T13), round 5

**Range reviewed:** `d682b38ec..HEAD` (`4446b7f62`). M3 source commits `d7f3ea21d`, `cdce8ab38`, `1184a83ac`, `85bf6b02d`; fix commits `9ac83f0eb`, `eb4ea4fe0`; evidence commits `ba40f4f83`, `57f708d35`, `1da344919`, `0d10c7a05`, `4446b7f62`.

**Lens applicability.** `frontend-conventions` — **applies** (route/barrel deletion, i18n namespace + per-key pruning, design-system baseline, TanStack key/invalidation conventions). `general` — **applies**. **Rule 19 (money/quantity), tenancy-authz, constructor injection, migrations, Horizon queues — do NOT apply:** `apps/api/**` is untouched in the whole range (`git diff --stat` shows zero backend files), no money/quantity code is added, no new query keys, no PHP.

**M3 manifest-drift exception honoured:** `git diff d3b0550ed..HEAD -- scripts/factory/manifests` is empty; an intermediate `check-manifest-drift.sh` exit 1 is *not* reported as a finding (§2 exception 2). Drift must pass at M4/M8.

**Round-4 findings re-verified at HEAD**
- **#1 (P1, 15 orphan `pos:` leaves) — FIXED and independently verified.** `eb4ea4fe0` removes exactly the 11 `xReport.*` + 4 `transactions.*` leaves from `en`/`fr` (`apps/web/src/locales/en/pos.json`, `fr/pos.json`). Each of the 15 has **0** consumers at HEAD (per-key grep over `src` + `e2e`; no `keyPrefix` usage exists in the repo, and no template-literal key construction targets these groups). The 7 live leaves (`rate,net,vat,gross,method,count,amount`) survive and are still consumed by `ZReportDetailPage.tsx:286-289,320-322`. `ar/pos.json` genuinely has no `xReport`/`shifts`/`shiftDashboard` block and `transactions` contains only `disposition` — **ar needed no change and carries no orphan** (I re-derived this directly rather than inheriting round 4's `.get()`-based claim).
- I also re-derived the *complete* key set the two deleted components consumed (all 57 `t('…')` literals at base): the removed `shiftDashboard.*` (19) and `shifts.toasts.*` (10) groups have **0** surviving consumers. No key was deleted while still in use; no surviving test was weakened to pass.
- **#3 (T12/T13 red-first evidence) — CLOSED.** The pasted red output is now consistent with reality: `routes.test.tsx` carries 17 tests at `1184a83ac` and 18 at `85bf6b02d` (`git show <sha>:… | grep -c 'it('`), matching the pasted `(17 tests | 1 failed)` / `(18 tests | 1 failed)`.
- **#2 — still open**, see finding 1 below. **#4, #5** — see findings 5 and 6.

---

## Findings register

### 1. **P2 — CONFIRMED (carry-over, correctly escalated, NOT executor-fixable)** — `/finance` renders a blank content pane; it does **not** fall through to `/dashboard` as `OWNER-DECISIONS:58` assumed
`apps/web/src/routes/index.tsx:1978`

`<Route path="finance">` carries a `path`, has **no `element`** and (verified across the whole branch, lines 1977–2132, including the multi-line `<Route\n index` form) **no index child**. Re-verified independently against the installed router this round:

```
/finance          -> / > finance
/finance/overview -> / > finance > overview
/nope             -> / > *
```

So `/finance` matches the element-less parent, renders the implicit `<Outlet/>` → `null`: app chrome with an empty main region. New this round: the **immediately following sibling** `<Route path="pricing">` (`:2132-2133`) does carry `<Route index element={<Navigate to="/pricing/price-lists" replace />} />`, as do `sales`, `purchases`, `treasury`, `crm`, `workshop`, `settings`, `pos` — an index redirect is the file's own convention for a pathed parent, and `/finance` is now the outlier.

**Failure scenario:** the exact population the ruling reasoned about (a typed/bookmarked `/finance`) gets a blank screen instead of `/dashboard`.

The executor implemented the brief verbatim (keep parent, delete index, add no redirect), invented no unruled behaviour, and disclosed it in **both** required places (`HANDBACK…:417`, `ui-wave0.progress.yaml` `findings:` line 159). Per §4 this is **close-before-merge** and is an owner-reserved decision, so it does not block M3 — but **it must not be merged undisclosed.** The parent terminal audit owes an explicit ruling: leave-as-ruled / add an `element` / add an index redirect.

### 2. **P3 — CONFIRMED** — the orphan-locale-key fix ships with no regression guard
`apps/web/src/locales/{en,fr}/pos.json` (commit `eb4ea4fe0`)

The fix is a pure JSON deletion with no test; nothing in typecheck, ESLint, `audit-design-system.mjs`, `audit-tanstack-keys.mjs` or `audit-quantity-display.mjs` detects unreferenced locale keys (verified — all four exit 0 both before and after the deletion). Nothing prevents the 15 keys returning, or new orphans accumulating. **Not a brief violation:** T10 explicitly prescribes a per-key grep as the proof for this criterion, so grep-only evidence is sanctioned here. Ticketable as a tooling gap (an orphan-key audit would have caught this at round 1 instead of round 4).

### 3. **P3 — CONFIRMED** — T12's route guard is string-form-dependent and can be evaded by the multi-line `<Route index>` form used elsewhere in the same file
`apps/web/src/routes/routes.test.tsx:106`

`expect(financeBranch).not.toContain('<Route index')` only matches the single-line form. The very same file writes the multi-line form at `src/routes/index.tsx:2256-2263` (`<Route`\n`  index`\n`  element=…`). A future re-mount in that style passes the guard; only the companion `not.toContain('FinanceHubPage')` would still bite, and only if the same component name were reused. Same class of defect as round-2 finding 1, at lower impact (a second assertion still covers the literal hub).

### 4. **P3 — CONFIRMED** — the round-5 per-key proof is a formatted digest with no generating command
`docs/handoff/HANDBACK-ui-wave0-2026-08-11.md:252-267`

The 15-line block (`xReport.title refs=[no output] locales=null,null,null` …) has no `$ <command>` line above it; §4 Per-task requires "a pasted command + output". The trailing `$ jq -c '.xReport' …` is properly formed. Evidentiary form only — I reproduced every line of the underlying claim independently and it is true.

### 5. **P3 — CONFIRMED (unchanged, brief-compliant)** — the retained-as-historical POS docs still display a deleted import as working API
`apps/web/src/features/pos/README.md:99` (`import { ShiftDashboardPage } from '@/features/pos'`), `:22,:149,:270,:520`; `IMPLEMENTATION_SUMMARY.md:109,:306-308,:367`. Authorised by T10's historical-retention disposition; the dated supersession notes are present at `README.md:3` and `IMPLEMENTATION_SUMMARY.md:3`, and `features/pos/index.ts:14`'s doc comment (the non-optional one) is corrected. Recorded only because a reader landing at `:99` sees a working-looking import ~96 lines below the notice.

### 6. **P3 — note, not a merge defect** — one empty leftover directory remains in the worktree
`apps/web/src/features/marketing/pages/` (0 files). `ShiftDashboardPage/` is now gone. Git does not track empty directories, `git status --porcelain` is clean, and the merge into `dev` cannot carry it — executor worktree hygiene only.

---

## Bypasses attempted that FAILED (the implementation held)

- **Tried to find a key deleted while still in use:** extracted all 57 `t('…')` literals from both deleted components at base and re-checked every one; also swept for `keyPrefix` (zero in repo) and template-literal key construction (none for these groups). Every removed key has 0 consumers; every retained key has a named live consumer.
- **Tried to find a key that should have been removed but survived:** derived the full removed set from `en/finance.json` (34 leaves) and grepped each — the only apparent survivors (`hub.title`, `hub.description`) belong to the **`pos`/`inventory`** namespaces (`PosHubPage.tsx:68,80`, `InventoryHubPage.tsx:128,146`). `finance:hub.cards.treasuryOverview.title` is preserved and still wired at `Sidebar.tsx:297`.
- **Tried to break the preserved borrowed key:** `i18nRawKeyCoverage.test.tsx` and `Sidebar/__tests__/Sidebar.test.tsx` are byte-unchanged in the range and pass; en/fr/ar values resolve `Treasury`/`Trésorerie`/`الخزينة`.
- **Tried to make the other three deletions blank-pane like `/finance`:** they cannot. `/pos/shifts` and `/marketing` were whole routes (no surviving parent branch consumes the path), and `/settings/chart-of-accounts` leaves an unconsumed extra segment under `<Route path="settings">` — all three fall to `path="*"` → `/dashboard`. Only the exact-parent-path case regresses.
- **Tried to prove the deleted `_invalidation.ts` removed a live tenant-isolation contract:** it did not. The only surviving shift invalidation is `ShiftOperationsMenu.tsx:93-98`, and `tools/audit-tanstack-keys.mjs:62-78` documents that cache-**filter** methods must use bare literal prefixes (a `tenantScopedKey` filter is a proven no-op and is *flagged*). The deleted predicates were the superseded pattern; deleting them and their helper-only tests loses no coverage of live code.
- **Tried to find unauthorised baseline movement:** `node tools/audit-design-system.mjs` → rc 0, `736 acknowledged, 0 new, 0 stale`; the whole-range baseline diff is **exactly** the two hand-removed lines (C2 `ShiftDashboardPage`, C3 `POSShiftsDashboard`) — no `--write-baseline` rewrite.
- **Tried to find a residual reference:** all four acceptance greps return **zero** at HEAD (T10's is now stricter than the brief required — even the `shiftApi` path strings don't match). Broad quote-agnostic sweep over `src` + `e2e` + `tools` leaves only the seven authorised `HubCard.test.tsx` fixtures (file absent from the diffstat → byte-identical), one self-referential `routes.test.tsx:123` construction, and backend **API paths** in `e2e/money-campaign/w8-support.ts:628` / `w8-isolation.spec.ts:227` (both `get(request, session, path)`, not `page.goto`). No breadcrumb/command-palette/quick-create residue.
- **Tried to find collateral E2E damage:** only the `MTP-GL-28` block and its header mention left `finance-permissions.spec.ts`; `MTP-GL-21/-22/-23/-27` intact. `ui-audit-shots.mjs` lost exactly one entry, no renumbering.
- **Tried to find a marketing-namespace remnant:** removed at all four `i18n.ts` sites plus the `ns` array; both locale files gone; no `useTranslation('marketing')`; surviving `customersAndMarketing`/`nav.marketing` are the sidebar *group* T11 exists to preserve.
- **Gates re-run independently:** `npx tsc --noEmit` rc 0 · `npx eslint .` **0 errors** (6508 warnings, pre-existing) · design-system rc 0 · tanstack Gate C `0/0/0` · quantity audit `0 new, 0 stale` · `vitest run --maxWorkers=1` over `routes.test.tsx`, `i18nRawKeyCoverage`, `Sidebar`, `POS/__tests__/tenantScope` → **69/69 pass** (exactly reproduces the handback's claimed figure) · broader run over `features/finance`, `pages/POS`, `HubCard`, `PosHubPage`, `features/pos/layouts` → **31 files / 171 tests pass**. `arLocaleCoverage.test.ts` fails 3 (vehicles/scheduling namespaces) — that is the **owner-ruled Arabic-parity exception** (`OWNER-RULING-2026-08-18-M0b.md`), untouched by M3; `pos` is not in its enforced namespace list.
- **Tried to find a smuggled manifest regeneration:** none; I deliberately did **not** run `gen-route-manifest.mjs`.

**Reviewer side effect, disclosed:** to verify the router-matching claim I copied a 6-line ESM probe to `apps/web/rrcheck.local.mjs` (node cannot resolve `react-router-dom` from `/tmp`), ran it, and deleted it in the same command. No tracked file was touched; `git status --porcelain` is empty; nothing staged or committed.

---

**Blocking set: empty.** No P1 remains — round-4's P1 is discharged and independently verified. Finding 1 (P2) is an **owner-reserved decision, correctly escalated in both required places**, and is a hard pre-merge gate the parent must rule on; findings 2–6 are P3/ticketable.

VERDICT: ACCEPT
