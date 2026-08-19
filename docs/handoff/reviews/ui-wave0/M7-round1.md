# UI Wave 0 — M7 bridge review, round 1

**Lens:** general (docs truthfulness — the no-overstated-guarantees charter is the operative dimension)
**Worktree:** `/Users/houssamr/Projects/syneriva/apps/erp/.worktrees/ui-wave0` (read-only for source; this register is the only write)
**Branch:** `codex/ui-wave0-2026-08-11`, tip `1dff4a00e4608885ac0bf8f9b23a2dec443881ff` — matches the expected tip
**Range reviewed:** `2621abbff..1dff4a00e`
**Contracts:** `docs/handoff/CODEX-DISPATCH-ui-wave0-2026-08-11.md` §T9:418-437 and the M7 row `:21`; the M6 register's two doc directives (`docs/handoff/reviews/ui-wave0/M6-round1.md` P2-1:101, P3-1:111)
**Reviewer:** adversarial bridge reviewer (general lens), 2026-08-19

Commits in range:

| SHA | Content |
|---|---|
| `6e51c4e48` | Phase 0.6.3 — close M6 / open M7 in the ledger; apply the M6 register's P2-1 and P3-1 |
| `85d2f8a26` | Phase 0.9.1 — T9 (UI-44): the listing canon + the navigation doc rewrite |
| `1dff4a00e` | Phase 0.9.2 — record M7/T9 in the ledger |

`git diff --stat 2621abbff..1dff4a00e` = 4 files: two architecture docs, the progress ledger, and **one** `.tsx` — `VoucherListPage.tsx` (+3/-2), which `git show 6e51c4e48` confirms is a comment body only. No code beyond the mandated comment. No `scripts/factory/` file in the range.

---

## 1. Verification actually executed (nothing accepted on report)

| Check | Command | Result |
|---|---|---|
| Typecheck | `pnpm exec tsc --noEmit` in `apps/web` | **exit 0** |
| Lint (chains `audit:keys`, `audit:design-system`, `audit:quantity`, RuleTester) | `pnpm --filter @autoerp/web lint` | **exit 0 — 6520 problems, 0 errors**, exactly the ledger's number; Gate C 0/0/0; design-system **818 acknowledged / 0 new / 0 stale**; audit-quantity 0/0/0; all three RuleTester suites pass |
| Manifest drift | `bash scripts/factory/check-manifest-drift.sh` | **exit 0** |
| Sidebar suite (the doc's own claim) | `pnpm vitest run src/components/organisms/Sidebar/__tests__/Sidebar.test.tsx src/features/vouchers` | **13 files / 123 tests passed**; `Sidebar.test.tsx` reports **45 tests** at runtime — the doc's "45 tests across 15 `describe` blocks" reproduces exactly (15 `describe(` statically) |
| Baseline honesty | `git diff 2621abbff..1dff4a00e -- apps/web/tools/audit-design-system-baseline.json` | **empty** — no baseline touched, no `--write-baseline` |

### 1.1 design-system.md — every stated claim re-derived

| Claim | Verified against | Result |
|---|---|---|
| F-10's zero-hit grep now hits | `grep -wnE "list page\|ListPageLayout\|FilterPanel\|canonical" docs/architecture/design-system.md` | **8** (see P3-1 on the exact formulation) |
| `ListPageLayout` **12 of 46** at base `d682b38ec` | `git ls-tree -r --name-only d682b38ec -- apps/web/src/features \| grep -E "(ListPage\|ListView\|QueuePage\|IndexPage)\.tsx$"` = **46**; `git grep -l ListPageLayout d682b38ec -- 'apps/web/src/features/*'` intersected with that cohort = **12** | **reproduces exactly at the pinned SHA**; the re-census also states "`ListPageLayout` remains 12/46" (`17-listing-census-recensus.md:77`) |
| `FilterBar` PROPOSED, not built | `grep -rn FilterBar apps/web/src` | **0 hits** — the PROPOSED label is honest |
| `DataTableColumn.sortable` PROPOSED, not built | `grep -rn sortable apps/web/src/components/molecules/DataTable/` = 0; `DataTable.tsx:14-35` exposes exactly `key/header/align/numeric/render/accessor/headerClassName/cellClassName/width` | **the nine listed fields are exact, `sortable` is absent** |
| Wave-3/CX-4 banner | `design-system.md:249` — "*Status: canon PROPOSED, Wave 3 gated on the `CX-4` re-census*" | **present**, at the top of the section as required |
| "Nothing in this section is enforced by a lint rule or a ratchet today" | `apps/web/eslint-rules/` (9 files, none listing-related); `tools/audit-design-system.mjs:84` categories are C1–C6 colour only | **true** |
| The eight component rows | every path exists; `components/ui/filters/` contains exactly `SearchFilter`, `EnumFilter`, `BooleanFilter`, `RangeFilter`, `DateRangeFilter` | **all real** (but see P3-2) |
| The composition example's props | `OffsetPaginationProps:7-23` (currentPage/lastPage/total/perPage/from/to/onPageChange/onPerPageChange); `DataTableProps:61-78` (columns/data/keyExtractor/isLoading/emptyTitle/emptyDescription/emptyState); legacy `DataTableMarkupProps:80-89` | **every prop in the snippet exists with the stated meaning** |
| Rule 1 — the shell renders the single `<h1>` via `PageHeader`; rules 2/4 — slots omitted when absent | `ListPageLayout.tsx:5,49-63` (`{filters ? … : null}`, `{pagination ? … : null}`) | **accurate** |
| Rule 3 — `DataTable` builds the `EmptyState` | `DataTable.tsx:4,296` | **accurate** |
| "the 6-tone API" (`StatusTone`) | `components/atoms/StatusBadge/StatusBadge.tsx:15-21` — neutral/info/success/warning/danger/pending | **six, exact** |
| The pre-existing token tables the footer date now covers | spot-checked `primary.50–800`, `textColors.*`, `borderColors.*` against `designTokens.ts:20-27,86-101,106-…` | **still accurate** — bumping *Last Updated* does not paper over stale content |

### 1.2 frontend-navigation.md 2.0 — every stated claim re-derived

| Claim | Verified against | Result |
|---|---|---|
| `MODULE_NAME_MAP` no longer exists | `grep -rn MODULE_NAME_MAP apps/web/src` | **0 hits**; the 1.0 text at `2621abbff` contained **13** — the "verified false and removed" narrative is itself true |
| The `isNavItemVisible` code block | `Sidebar.tsx:424-440` | **quoted verbatim**, no editorialising |
| Fail-closed on the permission axis | `usePermissions.ts:152-160` (`if (!requiredPermissions) return false`, UI-01 comment) and `ModuleKey` = `keyof typeof MODULE_PERMISSIONS` at `:94` | **fail-closed confirmed**; `BackendModule` union at `lib/modules.ts:44` |
| The 15-group table (module/permission per group) | `Sidebar.tsx:146-363`, extracted mechanically | **all 15 rows correct, in render order**, including the three "blank axis" rows (`purchases`, `customersAndMarketing`, `bankingAndPayments`), the any-of array on `automotive`, and `section: 'bottom'` on `supportAccess`/`settings`. Not a spot-check — all fifteen |
| `menu`/`tables`/`batch_expiry` are now in the sidebar | `Sidebar.tsx:201-203,222-223,236-237` | **correct**; 1.0's ":75 future modules" line genuinely existed and is genuinely stale |
| `VERTICAL_NAV_KEYS` covers `compositeItems` + `modifierGroups` only; catalog group deliberately does not adapt | `Sidebar.tsx:133-136` | **exact** (see P3-5 on one word) |
| Services placement by vertical | `Sidebar.tsx:147-149`, `:199` (non-automotive → catalog group), `:320` (automotive group) | **accurate** |
| `hasModule` = `modules.includes(name)`, case-sensitive, with `Object.values` normalisation | `CompanyConfigContext.tsx:65-76` | **accurate, including the stated failure mode** |
| Fail-closed loading/error | same, empty array before resolve | **accurate** |
| Suite = 45 tests / 15 `describe` blocks; `useRealModuleAccess` escape hatch | executed (45) + `Sidebar.test.tsx:22-37,549,688` | **reproduces**; the two named `describe` titles exist |
| Manifest 265 web / 9 pos | `grep -c "- path:"` on both manifests | **265 / 9 exact** |
| `module_gate` = ModuleGuard, which wins over `RequirePermission moduleKey` | `gen-route-manifest.mjs:11-13,170` | **accurate** |
| The four Wave-0 deletions absent | `grep -nE "path: (/pos/shifts\|/marketing\|/finance\|/settings/chart-of-accounts)$" routes-web.yaml` | **exit 1, zero hits**; `/finance/*` children still present (**15**) as the doc says |
| The remaining orphan candidates still present | grepped each: `/growth`, `/growth/modules`, `/scheduling/capacity`, `/treasury/payment-methods`, `/treasury/sales-withholding-tracking`, `/inventory/delivery-notes/consolidate`, `/finance/lane-separation`, four `/settings/compliance/*` | **all 11 present** (but see P2-1 — the list is not the whole remainder) |
| 22 candidates at the pinned base, four of them the Wave-0 deletions | `17-listing-census-recensus.md:134` and the table at `:137-163` | **22 confirmed**; `/finance`, `/marketing`, `/pos/shifts`, `/settings/chart-of-accounts` are all four in it |
| catch-all `/finance` → `/dashboard` | `routes/index.tsx:3139` | **accurate** |
| `ModuleGuard` redirects to `/dashboard`, overridable via `fallback` | `ModuleGuard.tsx:14-16,49,66,71` | **accurate** |
| `RequirePermission` re-exported from `components/auth/` | `components/auth/RequirePermission.tsx:1-2` | **accurate** |
| Every internal doc link | 11 of 12 targets exist | **one dangling** — see P3-3 |

### 1.3 Fabricated-evidence sweep (the specific hunt this milestone asked for)

I grepped 2.0 for anything shaped like measured evidence — benchmark figures, scorecards, pasted test output, percentages. **None remains.** The only quantities in 2.0 are `15` groups, `45` tests / `15 describe`, `265`/`9` manifest records, `22` candidates, and the version/date — every one of them re-derived above, and each sits next to a command that reproduces it (`pnpm vitest run …`, `node scripts/factory/gen-route-manifest.mjs`, `bash scripts/factory/check-manifest-drift.sh`, `node tools/audit-listing-census.mjs`). All four commands exist and the drift one was executed. The four deletions the commit claims (microbenchmarks `:580-582`, the 9/10 scorecard `:611-619`, the 16-test output block `:432-463`, the three "Visible Sidebar Items" checklists `:170,205,244` with their `Finance`/`Vehicles`/`Services` rows) all genuinely existed in the 1.0 text at `2621abbff` — the executor did not invent a deletion to claim credit for it.

### 1.4 The two M6 doc directives

- **P2-1 — correctly applied.** `arLocaleCoverage.test.ts:66-76` enumerates exactly the nine namespaces the rewritten clause names (common, validation, workshop-bundles, workshop-technicians, workshop-work-orders, vehicles, vehicle-ownership, scheduling, pickers) and never touches `ar/inventory`; the substituted proof is real (`comingSoonKeyPruning.test.ts:54,58,62` assert the three `ar/inventory` paths are gone) and the stated coverage gap is real (that file's alignment assertion at `:113-131` is EN↔FR only — `it.each([['inventory', enInventory, frInventory], ['pos', enPos, frPos]])`; no EN↔AR inventory alignment test exists anywhere in `apps/web/src`). The ledger now says the gap out loud instead of citing a suite that proves nothing. `.gitignore:58` also checks out.
- **P3-1 — accurate reword.** `VoucherListPage.tsx:106-111` now says duplicate colour utilities "resolve by stylesheet order, not attribute order, so an override is not deterministic". That is the correct CSS mechanism (Tailwind's `className` order is irrelevant; equal-specificity utilities resolve by generated-rule order), the conclusion is preserved, the comment block kept its length and the geometry classes are untouched.

---

## 2. Findings

### P2-1 — `frontend-navigation.md:342-362`: the "orphan candidates that remain" list is under-inclusive by four routes, and the arithmetic invites the reader to notice

The section states the re-census "left 22 candidates after manual call-flow review. Four of them are the Wave 0 deletions above" — so 18 remain — then lists **11** under the heading "Still present in the manifest and still without an inbound UI reference". The trailing caveat accounts for 3 more ("three action/form routes remain unresolved rather than confirmed orphaned" — `/expenses/:id/edit`, `/inventory/replenishment/new`, `/sales/credit-notes/new`). That leaves **four candidates silently dropped**: `/channels/:id/orders`, `/channels/:id/products`, `/channels/:id/sync` (the CX-2 cluster) and `/inventory/return-notes/:id` — and the source report explicitly says "the three CX-2 parameterized views … reproduce with zero inbound UI references" (`17-listing-census-recensus.md:166`, table rows `:138-141`). A reader of the reconciliation section concludes the remaining orphan surface is 11 routes when the audit of record says 18. In a doc whose sibling milestone *deleted* four routes on orphan evidence, an under-inclusive orphan list is a substantive accuracy defect, not a stylistic one.
**Fix:** add the four omitted rows to the list (a `parameterized views` sub-bullet), or replace the bare list with the report's own breakdown — "15 views (11 listed here), four parameterized views, three action/forms" — so the 22 → 4 → 18 arithmetic closes on the page.

### P3-1 — `ui-wave0.progress.yaml:234`: neither acceptance count reproduces as written

The ledger records "02 F-10's grep (`list page|ListPageLayout|FilterPanel|canonical`) … now returns 8 lines; `listing|ListPageLayout|FilterPanel` returns 13." Against `docs/architecture/design-system.md` at the tip: the first returns **9** as plain `grep -E` and **8** only with `-w` (word-boundary drops the "list pages" prose line at `:262`); the second returns **12** (`-E`) or **14** (`-Ei`) — **never 13**. It returns 13 only when the scope is the whole `docs/architecture/` directory, where `frontend-navigation.md` contributes the extra hit. The *substance* is fine (F-10's zero is now decisively non-zero and the section exists), but this is the acceptance evidence for a documentation milestone, recorded in the permanent ledger M8 inherits — the same class of defect M6's P2-1 was.
**Fix:** restate both lines with the exact command and scope actually run (`grep -wnE … docs/architecture/design-system.md` → 8; `grep -rnE … docs/architecture/` → 13).

### P3-2 — `design-system.md:270-279`: the "components that exist today" table omits a shared filter component the canon's own instruction sends readers past

The table is introduced as "These are all real and importable now. The canon below composes **only** these", and the PROPOSED block tells readers "Until [`FilterBar`] exists, assemble the filter slot from the existing primitives directly." `components/ui/ActiveFilters.tsx` — shared, exported, consumed by `FilterPanel.tsx` itself plus `features/inventory/ProductListPage.tsx`, `features/menu/pages/MenuListPage.tsx`, `features/parts-catalog/components/organisms/{FilterSidebar,TireDimensionSearch}.tsx` — is not in the table, so "assemble from the existing primitives" will produce a hand-rolled active-filter chip row that already exists. Related: `FilterPanel` is billed as "The one purpose-built filter container" with no adoption context, in a section headed "be honest about adoption" — it has exactly **one** feature consumer (`features/inventory/ProductListPage.tsx`).
**Fix:** add an `ActiveFilters` row to the table; optionally note FilterPanel's single-consumer status the way `ListPageLayout`'s 12/46 is noted.

### P3-3 — both docs' "audit of record" link is dangling in every clone but this session's

`docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/00-EXECUTIVE-REPORT.md` does not exist in this worktree (`docs/sessions/` is gitignored at `.gitignore:58`; only `17-…` and `18-…` were force-added). Both docs cross-link it because the brief mandates it, and both disclose the risk in-line, which is the honest handling — but the practical result is that the canon's `00 §D:189` and `00 §4:217` citations, and the nav doc's `:379` "audit of record", are unverifiable for anyone but this session, including the M8 gate. T9 was right not to force-add it (rule 4, out of scope) and flagged it.
**Fix (parent, not this milestone):** force-add `00-EXECUTIVE-REPORT.md` at M8 alongside the other two, or downgrade both links to "session artefact, not in the repo" without the file path.

### P3-4 — `frontend-navigation.md:30-34` states two-layer enforcement as fact; the manifest it later quotes contradicts the universal reading

"Access is enforced on the route (`ModuleGuard` + `RequirePermission`) and again on the API (`module:<Name>` middleware + permission checks)." Read as a rule, correct; read as description — which the present tense invites — it overstates. The very manifest the doc quotes has **34 of 265** web routes with *neither* `module_gate` nor `permission`, and **138 of 265** with no `module_gate` at all. The next sentence ("A nav item that is correctly hidden but whose route is unguarded is still a bug") partially rescues it, which is why this is P3 and not P2 — but the Route-reconciliation section is one `grep -c` away from stating the real coverage.
**Fix:** make the rule/description split explicit ("this is the rule; it is not yet universal") and, if cheap, put the ungated-route count in the reconciliation section.

### P3-5 — `frontend-navigation.md:175` names the wrong half of the `VERTICAL_NAV_KEYS` pair

"that item's label resolves through `catalog:vertical.<vertical>.<key>`". `getNavLabel` (`Sidebar.tsx:388-400`) interpolates the map's **value**, not the nav key — `modifierGroups` resolves to `catalog:vertical.<vertical>.modifierGroup` (singular). One of the two covered items is affected, so a reader copying the pattern gets a missing key. (The same phrasing is in the ledger.)
**Fix:** `catalog:vertical.<vertical>.<VERTICAL_NAV_KEYS[key]>`.

**No P1.** Nothing in either doc asserts a system guarantee that the tree contradicts; no number was fabricated; no evidence-defeating indirection appears anywhere in the range (there is no code in the range to hide behind); the baseline was not touched; the manifest was not regenerated.

---

## 3. Disposition

T9's contract is met item by item: the F-10 grep is decisively non-zero, the two PROPOSED pieces are labelled PROPOSED and verified absent from source, the single decision-grade number is pinned to the base SHA and reproduces there exactly, the Wave-3/CX-4 banner is present, every other distribution is delegated to the re-census with a working reproduction command, `MODULE_NAME_MAP` is genuinely gone, the 15-group table is right in all fifteen rows, the manifest counts and all four Wave-0 deletions check out, and the fabricated 1.0 evidence was deleted rather than refreshed. Both M6 directives landed accurately and the P2-1 rewrite states its coverage gap more honestly than the finding required. Gates: typecheck exit 0, lint exit 0 (0 errors / 6520 warnings, unchanged), design-system 818/0/0, Gate C 0/0/0, drift exit 0, Sidebar+vouchers 123/123.

Against that, P2-1 is a real accuracy gap in a permanent architecture doc — but it under-lists rather than misstates, the list is explicitly labelled "candidates, not rulings" with a regenerate instruction, and the full table is one link above. It is a one-line correction of the same weight and kind as M6's P2-1, which was accepted with the fix folded into the next commit. Same disposition here: **accept M7, correct P2-1 (and, cheaply, P3-1/P3-2/P3-5) in the M8 opening commit**; P3-3 and P3-4 are parent/merge-time items.

**Carried to M8:** P2-1 the orphan-list completeness fix; P3-1 the ledger's two grep counts; P3-2 the `ActiveFilters` row; P3-5 the label-key wording; P3-3 the executive-report force-add decision; P3-4 the enforcement-coverage clause; plus the four items M7 itself carried forward (M6 P3-2 chip accent, P3-3 merge-time C6 baseline re-check, P3-4 stray `apps/web/apps/web/src` tree, and the `Sidebar.tsx:143` stale `isModuleEnabledForVertical` comment — which I confirm is stale: no such function exists in `apps/web/src`).

VERDICT: ACCEPT
