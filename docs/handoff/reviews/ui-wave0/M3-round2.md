# ADVERSARIAL MERGE-GATE REVIEW — milestone M3 (T10–T13), round 2

**Range reviewed:** `d682b38ec..HEAD` (M3 source commits `d7f3ea21d`, `cdce8ab38`, `1184a83ac`, `85bf6b02d`; evidence commits `ba40f4f83`, `57f708d35`).
**Lenses:** `frontend-conventions` — applies (deletions, i18n namespace/key removal, design-system baseline, route guards). `general` — applies. Rule 19 (money/quantity precision), tenancy/authz, migrations, Horizon queues, constructor injection — **do not apply**: this milestone is `apps/web` deletions only; `apps/api/**` untouched, no money/quantity code added, no query keys added.
**M3 manifest-drift exception honoured:** `git diff d3b0550ed..HEAD -- scripts/factory/manifests` is empty and `check-manifest-drift.sh` exiting 1 is *not* reported as a finding (§2 exception 2).

---

## Findings register

### 1. **P1 — CONFIRMED** — T11's required route-absence assertion is vacuous; it cannot ever go red
`apps/web/src/routes/routes.test.tsx:82-85`

```js
const retiredMarketingPath = ['/mark', 'eting'].join('')   // "/marketing"
expect(routesSource).not.toContain(`path="${retiredMarketingPath}"`)  // path="/marketing"
```

The deleted route was declared **relative**, `<Route path="marketing">` (pre-commit source line 1981), nested under the `/` Layout route. The literal `path="/marketing"` **never existed**:

```
$ git show cdce8ab38^:apps/web/src/routes/index.tsx | grep -c 'path="/marketing"'
0
$ git show d7f3ea21d^:apps/web/src/routes/index.tsx | grep -c 'path="/pos/shifts"'      # T10, for contrast
1
```

So the assertion passed identically before and after the deletion. The test as a whole was red only because of its companion `not.toContain('MarketingHubPage')` — the *route* guard, which is the thing T11's "Tests required" actually mandates ("Add a routing assertion that `/marketing` no longer resolves"), is non-functional.

**Failure scenario:** someone re-mounts `<Route path="marketing" element={<CampaignsHubPage/>} />`. No `MarketingHubPage` identifier appears, the guard stays green, and the owner-ruled duplicate hub is silently reinstated. T13 gets this right (`settingsBranch.not.toContain('path="chart-of-accounts"')`); T11 does not.

---

### 2. **P2 — CONFIRMED** — after T12, `/finance` renders a **blank content pane**, not the `/dashboard` fall-through the ruling was accepted on
`apps/web/src/routes/index.tsx:1977` (`<Route path="finance">` — retains a `path`, has no `element`, and after T12 has no index child)

The brief states as the accepted consequence: *"a typed or bookmarked `/finance` falls through the `path="*"` catch-all to `/dashboard` (`routes/index.tsx:3164`)"*. **That is false for the delivered route shape.** React Router 7 builds a match branch for any parent route that carries a path, and ranks the static `finance` segment above the splat. Reproduced against the installed router (`react-router@7.15.0`, `matchRoutes`):

```
/finance          -> "/" > "finance"
/finance/overview -> "/" > "finance" > "overview"
/nope             -> "/" > "*"
```

`<Route path="finance">` has no `element`, so it renders the default `<Outlet/>`; with no child match that resolves to `null`. The user gets the app chrome (sidebar/header) with an empty main region — no page, no error, no redirect. Only 3 other parent routes in the file lack an index (`ecommerce`, `catalog`, `parapharmacy`) and **none of them is a linked or previously-live destination**; `/finance` was a working hub until this commit.

The executor implemented exactly what the brief prescribed ("Keep the `<Route path="finance">` parent … only the index element goes"), so this is not an invented deviation — but §3 "if a task appears blocked or wrong, **stop and report**" applies: `OWNER-DECISIONS:58`'s stated basis ("nothing links to `/finance`, so no broken-bookmark risk") is now grounded on a redirect that does not happen. The executor's own T10 test reinforces the wrong model by asserting the catch-all string as the fall-through guarantee (`routes.test.tsx:76`).

**Failure scenario:** a user with a `/finance` bookmark — the exact population the owner reasoned about — lands on a blank screen. Must be escalated and ruled (leave as-is / add `element` / add an index redirect) before merge, not merged undisclosed.

---

### 3. **P2 — CONFIRMED** — the `_invalidation.ts` doc-comment fix replaced one false claim with another; the module is now dead production code
`apps/web/src/pages/POS/_invalidation.ts:13-15`

The commit rewrote the comment to: *"Used by POSTransactions (post-receipt cleanup, modal `onSuccess`) to cascade-invalidate every shift / shift-balance entry…"*. `apps/web/src/pages/POS/POSTransactions.tsx` is a **51-line static disposition stub** — its only imports are `react-i18next`, `lucide-react` and `designTokens`; it contains no `invalidateQueries`, no `useQueryClient`, and never imports `_invalidation`.

After T10 removed `POSShiftsDashboard`, the *only* importer of either predicate is the test:

```
$ grep -rn "posShiftInvalidationPredicate|posShiftBalanceInvalidationPredicate" src --include='*.ts*' | grep -v "_invalidation.ts:"
src/pages/POS/__tests__/tenantScope.test.tsx:11,12,60,62,68,74,81,83,88,93,227,292
```

The brief named `_invalidation.ts:13` explicitly as a "comment reference that becomes false" and requires (checklist item 2) verifying "any component that becomes unimported as a result". The fix corrected the `POSShiftsDashboard` half and left/asserted a `POSTransactions` claim that is untrue, while missing that the module itself is now orphaned.

**Failure scenario:** a maintainer reads the comment, believes the POS receipt flow depends on this tenant-scoped invalidation contract, and preserves or extends dead code; or treats the tenant-isolation guarantee as live when nothing in production calls it.

---

### 4. **P3 — CONFIRMED** — surviving stale current-tense references to the deleted console that the brief's greps structurally cannot catch
- `apps/web/src/pages/POS/POSTransactions.tsx:19-20` — *"Read-only receipt browsing and PDF download remain functional via other pages (e.g., **POS shifts dashboard**)."* Production file; prose form, so it evades all four T10 grep patterns (`pos/shifts"`, `'/pos/shifts'`, `POSShiftsDashboard`, `ShiftDashboardPage`) and the acceptance zero is therefore narrower than it reads.
- `apps/web/src/pages/POS/__tests__/tenantScope.test.tsx:60,81` — describe labels `(callsites .841, .846, .847)` / `(callsite .842)` still index the deleted dashboard's audit callsites; only line 102's comment was updated.

---

### 5. **P3 — CONFIRMED** — red-first evidence is narrated, not pasted, for all four tasks
`docs/handoff/HANDBACK-ui-wave0-2026-08-11.md:238` ("the new route test initially failed because the retired page string remained"), `:299` ("failed red on the settings branch"); **T11 and T12 carry no red-first statement at all.** §4 Per-task requires "a pasted command + output, or a `file:line`" — M2 round 1 raised exactly this and closed it by pasting output; M3 regressed to narrative.

Mitigating (I reconstructed non-vacuity myself by replaying `routeBranch()` against the pre-commit sources):

| Task | pre-commit state | red? |
|---|---|---|
| T10 | `path="/pos/shifts"` ×1, `POSShiftsPage` present | ✅ |
| T11 | `path="/marketing"` ×0 → see finding 1; `MarketingHubPage` present | ⚠️ partly |
| T12 | `financeBranch` contained `<Route index` **and** `FinanceHubPage` | ✅ |
| T13 | `settingsBranch` (6809 chars) contained `path="chart-of-accounts"` | ✅ |

---

### 6. **P3 — CONFIRMED** — T12's mandated **per-key** i18n greps were not pasted (the underlying claim is nonetheless true)
`HANDBACK…:262` asserts "A loop enumerated all 35 scalar `finance:hub.*` keys and grepped each" with no output. The brief is explicit: "paste a per-key grep proving zero surviving references outside `src/locales/`". I verified it independently — extracted all 35 leaf keys from `git show 1184a83ac^:…/en/finance.json`; the only apparent hits (`hub.title`, `hub.description`) belong to the **`pos`/`inventory`** namespaces (`PosHubPage.tsx:68,80`, `InventoryHubPage.tsx:128,146`), and no component calling `useTranslation('finance')` references `hub.*`. Evidentiary gap only.

---

### 7. **P3 — CONFIRMED** — newly-orphaned POS API exports not recorded as discovered findings
Newly zero-consumer after T10: `apps/web/src/features/pos/api/terminalApi.ts:123` `getOrCreateWebTerminal`, and `shiftApi.ts` `closeShift` / `XReportResponse` / `XReportData`. Deleting API clients is **explicitly out of T10 scope**, so this is not a defect — but §5 item 5 requires discovered findings to be recorded, and neither the M3 handback nor `ui-wave0.progress.yaml` `findings:` mentions them. Carry to the M8 report.

---

### 8. **P3** — source-text assertions substituted for the brief's behavioural wording
`routes.test.tsx:70-77,79-95` asserts on the *text* of `routes/index.tsx` / `Sidebar.tsx` rather than rendering. This matches the file's pre-existing convention (`routeBranch`, the legacy bank-reconciliation guard), so it is defensible — but T10's "Confirm `/pos/shift-history` **still renders**" is not actually proven, and T11's sidebar check is file-wide rather than scoped to the `customersAndMarketing` group (the six hrefs *are* in that group, `Sidebar.tsx:262-267` — verified).

---

## Bypasses attempted that FAILED (i.e. the implementation held)

- **Broadened, quote-agnostic reference sweep** across `src` **+ `e2e` + `tools`** (`['"\`]/finance['"\`]`, `['"\`]/marketing`, `/pos/shifts`, `settings/chart-of-accounts`): the only survivors are the seven authorised `HubCard.test.tsx` fixtures (lines 12, 22, 35, 54, 63, 87, 103 — exactly as enumerated), one self-referential `routes.test.tsx:123` construction, and legitimate **backend API paths** in `shiftApi.ts`/`shiftHistoryApi.ts`/e2e request specs. Zero production route references remain.
- **Tried to break the preserved borrowed key:** `finance:hub.cards.treasuryOverview.title` resolves in en/fr/ar (`Treasury`/`Trésorerie`/`الخزينة`); `i18nRawKeyCoverage.test.tsx` and `Sidebar/__tests__/Sidebar.test.tsx` are **byte-unchanged** in the range and pass; the `arFinanceHub` cast at `i18n.ts:158-163,356-366` still type-checks after the ar `description` removal.
- **Tried to find unauthorised baseline movement:** `node tools/audit-design-system.mjs` exits **0** — `736 acknowledged, 0 new, 0 stale`. The baseline diff is exactly the two hand-removed lines (C2 −1 `ShiftDashboardPage`, C3 −1 `POSShiftsDashboard`); no `--write-baseline` wholesale rewrite.
- **Tried to find a smuggled manifest regeneration:** `git diff d3b0550ed..HEAD -- scripts/factory/manifests` empty.
- **Tried to find collateral E2E damage:** only the `MTP-GL-28` block and its mention in the file header were removed from `finance-permissions.spec.ts`; `MTP-GL-21/-22/-23/-27` untouched.
- **Tried to find orphaned components missed by the sweep:** `OpenShiftModal` has zero consumers, but `git grep` at base `d682b38ec` proves it was **already** orphaned pre-M3 — not caused here. `MoneyInput`/`POSButton`/`DataTable`/`HubCard` all retain other consumers.
- **Tried to find cross-milestone breakage into M5:** the deleted hub pages passed `card.permissionModule` dynamically (no literal keys), so T4's "exactly five diagnostics" contract is unaffected; `finance` remains a valid `MODULE_PERMISSIONS` key (`usePermissions.ts:51`).
- **Gates re-run independently:** `pnpm typecheck` clean; `pnpm lint` **rc=0** (tanstack-keys 0 new/0 stale, design-system 0 new/0 stale, quantity 0, all three ESLint RuleTester suites pass); `vitest run --maxWorkers=1` over `routes.test.tsx`, `i18nRawKeyCoverage.test.tsx`, `Sidebar.test.tsx`, `POS/__tests__/tenantScope.test.tsx`, `HubCard.test.tsx` → **84/84 pass**.

**Reviewer side effect, disclosed and reverted:** I ran `node scripts/factory/gen-route-manifest.mjs` to confirm the generator still runs post-deletion (rc=0, 265 routes). It rewrote `scripts/factory/manifests/routes-web.yaml`; I restored it with `git checkout --` immediately. `git status --porcelain` is now empty — the tree is clean and nothing was staged or committed.

---

**Blocking set:** finding 1 (P1 — a required M3 test is vacuous) must be fixed. Findings 2 and 3 are P2 close-before-merge; finding 2 additionally needs an escalation record because it invalidates the stated basis of `OWNER-DECISIONS:58`.

VERDICT: CHANGES-REQUIRED
