# M1 review — round 2 (T1 / CX-4, component-graph-aware listing re-census)

Reviewed `d682b38ec..HEAD`. M1 = `751255324`, `b59d67225`, `5383a2467`, `f37eace25`; earlier commits in the range are the accepted M0b tests-only baseline. Everything below was reproduced by running the tool and reading source, not taken from the brief or handback.

### Round-1 findings — disposition (independently checked)

| R1 | Status | Evidence |
|---|---|---|
| #1 P2 inherited population presented as independent | **Resolved** | `17-…recensus.md:19` now says "intentionally inherits the filename rule… not an independent proof"; `:25` discloses the 39 excluded route-mounted `DataTable` pages and names 11 of them; `:70` scopes decision-grade to "the inherited cohort" (but see finding 2 below) |
| #2 P2 prefix-dynamic templates dropped, wrong stated cause | **Partially resolved — recurs, see finding 1** | All 7 named rows genuinely resolved: `/purchases/suppliers/new` ← `PartnerListPage.tsx:242,296`; `/purchases/suppliers/:id/edit`,`/sales/customers/:id/edit` ← `PartnerDetailPage.tsx:398`; the four `:id/edit` document rows ← `DocumentActionBar.tsx:371` with literal props at `InvoiceDetailPage.tsx:445`, `PurchaseOrderDetailPage.tsx:340`, `QuoteDetailPage.tsx:247`, `SalesOrderDetailPage.tsx:281` |
| #3 P3 regex empty-state predicate spanned whole condition | **Resolved** | Replaced by AST evaluation, `audit-listing-census.mjs:294-411`; new non-vacuous test `audit-listing-census.test.mjs:89-102` (old regex classified `items.length > 0 && mode === 0` as empty) |
| #4 P3 93% of rows file-local undisclosed | **Resolved** | `:25` — "43 of 46 rows remain file-local… a differently named body component is outside this scanner's graph" |
| #5 P3 `ListView`/`IndexPage` match nothing | **Resolved** | `:19` — "44 `ListPage`, two `QueuePage`, and zero `ListView` or `IndexPage` matches" |
| #6 P3 greenfield red-first | Recorded, still degenerate (tests+impl in one commit again at `5383a2467`) |
| #7 P3 `17-` ordinal collision | Unchanged, parent's call at merge |

### Independently reproduced this round

`node tools/audit-listing-census.mjs` → **exit 0, 1.16s**, no network; 46 listings / 271 route records / 27 orphan candidates split 15 view / 4 parameterized / 8 action-form — the report's JSON block (`:33-64`) and every distribution (pagination 18/3/1/24, empty 17/2/26/1, filters 1/16/13/3/2/11, `ListPageLayout` 12, `DataTable` 35) reproduce exactly from my own run. `node --test` 12/12, `pnpm exec vitest run tools/__tests__/audit-listing-census.test.mjs` 12/12, `tsc --noEmit` clean, `eslint` on both new files clean. The four M1 commits touch **zero production source** (`git diff --name-only 04c96be26..HEAD` = 2 tool files + 4 docs). Not wired into `lint`, `preflight`, or `.github/` — `grep -rn listing-census` outside the two files returns nothing; `package.json` unchanged.

### Lens applicability

**frontend-conventions**: only the tooling subset is in play (exported-function shape, test co-location, lint/vitest wiring) — passes; no `apps/web/src` production code changed, so tokens/RHF/i18n/`tenantScopedKey` are N/A. **general**: applies, findings below. **Rule 19 (money/quantity), tenancy/authz, constructor injection, migrations, Horizon queues, en+fr strings**: N/A — no backend, no runtime UI, no user-facing strings in this milestone.

---

## Findings register

**1. P2 — CONFIRMED — `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/17-listing-census-recensus.md:159`** (compounded by the round-2 claim at `:127`)
Round-1 finding #2 recurs, in the same shape, for **5 of the 8** remaining action/form candidates. The report's stated cause — "a static navigation scan cannot prove reachability through opaque callback values or server-originated links" — and the round-2 assertion that "only unconstrained prefix templates remain excluded" are both factually wrong for:
- `/sales/orders/new`, `/inventory/delivery-notes/new`, `/inventory/return-notes/new`, `/sales/credit-notes/create` — all four are the target of the shared document list's Add button: `DocumentListPage.tsx:309-310` (`documentType === 'credit_note' ? \`${basePath}/create\` : \`${basePath}/new\``), navigated at `DocumentListPage.tsx:432` and `:497`, with `basePath` from `DocumentListPage.tsx:148` reading the **module-level literal** `documentTypeToPath` (`:46-54`), bound per route mount by `routes/index.tsx:640,726,1197,1239`. That is a *bounded* prefix template — one `ElementAccessExpression` deref from resolvable — not an unconstrained one. The scanner drops it at `audit-listing-census.mjs:624-628` because the template head is empty and `documentTypeToPath[effectiveType]` is unhandled.
- `/income/:id/edit` — built at `LinePanel.tsx:41-46` (`provenanceLink()`, three literal-prefixed returns) and rendered as `<Link to={link}>` at `LinePanel.tsx:92`. A local pure function, not an opaque callback.

*Failure scenario:* T9's doc and Wave-3 scoping consume this report (`00 §5:248` makes this census their gate). A reader takes "zero inbound UI references, cause = opaque/server-originated" as evidence toward retiring `/sales/orders/new` or `/inventory/delivery-notes/new`, and document creation loses the Add button target of the very list page it sits under. The headline `action_or_form: 8` / `total: 27` (`:60-62`) are correspondingly overstated. Fix is report-level at minimum (correct the stated cause and re-attribute these five rows); resolving object-literal map lookups in `expressionPaths` would close it in the tool.
The other three (`/sales/credit-notes/new`, `/expenses/:id/edit`, `/inventory/replenishment/new`) I tried to falsify and could not — no inbound production reference exists for any of them.

**2. P2 — CONFIRMED — `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/17-listing-census-recensus.md:19`**
The provenance citation added to remediate round-1 finding #1 names a file that does not exist: `02-route-listing-inventory.md:66`. The audit session directory contains no such file; the actual source is `02-design-system-consistency.md:66` ("Definition: every `.tsx` under `src/features/**` matching `*ListPage | *ListView | *QueuePage | *IndexPage`. 46 files."), which is exactly what round-1 cited. The quoted rule is correct; only the pointer is broken.
*Failure scenario:* the one sentence whose entire job is to prove the 46 is inherited rather than independently derived sends the reader to a 404 — the honesty disclosure becomes unverifiable at the point of use, and a later reader re-promotes 46 to "independently confirmed".

**3. P3 — CONFIRMED — `apps/web/tools/audit-listing-census.mjs:668-706` and `:722-729`** (report Method at `:27`)
The new cross-file resolution (`collectStaticJsxPropBindings`) keys literal `basePath` props by JSX tag name and applies them to any file whose *basename* equals that name, unioning **all** callers' values. `DocumentActionBar` therefore carries 7 base paths at once, so any `${basePath}/…` inside it marks all 7 expansions reachable regardless of which caller can actually render it. No live false positive exists at this base — I checked; no `/inventory/return-notes/:id/edit`-style route is registered — so this is latent. The report's Method paragraph (`:27`) still lists only "`to`, `href`, `navigate(...)`, breadcrumb maps, and `entityRoutes`" and does not mention this pass or its union approximation, in a report whose premise is method-per-number.

**4. P3 — CONFIRMED — `docs/handoff/HANDBACK-ui-wave0-2026-08-11.md:130` and `:132`**
Stale/inaccurate evidence lines. `:130` still reads "After implementation, Node reports 10/10 and Vitest reports 10/10" while `:136-137` correctly claim 12/12. `:132` and report `:15` claim the scan "completes in roughly five seconds"; I measured **1.16s** (round 1 measured 1.06s) — the claim moved away from the measurement rather than toward it. Neither changes a verdict; both are handback-fidelity defects in a milestone whose product *is* evidence.

**5. P3 — PLAUSIBLE — `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/17-listing-census-recensus.md:25`**
The "39 route-mounted, non-detail pages that render `DataTable`" figure is adopted verbatim from the round-1 review with no stated method and no reproduction command, unlike every other number in the report. My independent recount (route-mounted `<Component />` in `routes/index.tsx` ∩ files containing `<DataTable`, minus the listing-name cohort, minus `*Detail*`/`*Form*`/`Create*`) gives **33**; a looser mount-extraction gives more. The 11 named examples all verify. Directionally right, method-less — give it the one-line reproduction rule the rest of the report gets.

**6. P3 — note (carried, not an executor defect) — report path**
The `17-` ordinal still collides with the owner's existing untracked `17-research-open-question-best-practices.md`. The path was mandated by the brief; parent renumbers at merge.

### Bypasses attempted that FAILED to find a defect

- Tried to make the round-2 extractor manufacture reachability: test files are excluded from the corpus (`audit-listing-census.mjs:761,764`), so `DocumentActionBar.test.tsx:85`'s `basePath="/sales/quotes"` cannot leak in — production-only holds.
- Tried to find a route that the new prop-union pass *falsely* flipped from orphan to reachable — none; the orphan-set delta round-1→round-2 is exactly the 7 rows named, each verified genuine against source.
- Tried to falsify the four M3 deletion targets' orphan status after the extractor change — `/pos/shifts`, `/marketing`, `/finance`, `/settings/chart-of-accounts` all still reproduce with 0 inbound references, plus `/growth`.
- Tried to break the new AST empty-state predicate: `!items?.length`, `(data?.length ?? 0) === 0`, `0 === items.length`, `!isLoading && items.length === 0`, `items.length > 0 && mode === 0` — all classify correctly, and every census distribution is byte-identical to round 1 (26 bespoke / 17 `EmptyState` / 2 `emptyTitle` / 1 none), so the rewrite regressed nothing.
- Tried to show the two new tests are vacuous — both fail against the round-1 implementation by construction (old regex mis-classified the compound condition; old `addExpression` had no `ConditionalExpression` or cross-file prop path).
- Tried to find wiring into `lint`, `preflight`, CI, a non-zero exit, a `package.json` script chained into `lint`, or scope creep into production source — none.
- Could not verify the handback's "Post-review-fix full run: 4,236 passed, 5 failed" claim: running the full suite is prohibited without owner permission. Recorded as unverified, not disputed.

Both P2s are report-level corrections (finding 1 optionally also a small extractor change). The tool, the tests, and every distribution I could re-derive independently hold up; the blocker is that the round-1 P2 the executor claims to have resolved still misattributes 5 of the 8 rows it left standing.

VERDICT: CHANGES-REQUIRED
