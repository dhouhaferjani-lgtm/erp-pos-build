## M1 review — round 1 (T1 / CX-4, component-graph-aware listing re-census)

Reviewed `d682b38ec..b59d67225` (M1 = `751255324` + `b59d67225`; the earlier commits in the range are the already-accepted M0b tests-only baseline). Everything below was verified by running the tool and reading the code, not from the brief or handback.

### Verified good (independently reproduced)

- Deliverables all present and match the brief's shape: `apps/web/tools/audit-listing-census.mjs` (pure exported functions + `main`, mirroring `tools/audit-design-system.mjs`), `apps/web/tools/__tests__/audit-listing-census.test.mjs`, and the report. M1 touches **zero production source** — the diff is tool + test + docs only.
- `node tools/audit-listing-census.mjs` → **exit 0, 1.06s**, no network. `main()` swallows scan errors and still sets `exitCode = 0` (`audit-listing-census.mjs:673-681`). Not in `package.json`'s `lint` chain, not in `.github/`, not in `scripts/` — grep for `listing-census` outside the two new files returns nothing.
- Tests: 10/10 under `node --test`, 10/10 under `pnpm exec vitest run` (vite config `include` covers `tools/**/*.{test,spec}.{ts,mjs}`, so `pnpm test` does exercise it). `pnpm typecheck` passes; `eslint` on both new files is clean.
- Required fixtures are all present and non-vacuous: organism-delegated pagination, empty state, and filter pattern; parameterized route with one dynamic `<Link>` → not orphaned; zero-reference param route → orphaned; breadcrumb-parent-only → not orphaned; plus a genuine over-match guard (`/${section}/${slug}` must not reach `/scheduling/capacity`). Both CX-4 worked examples assert through the organism (`ExpenseList.tsx`, `BundleList.tsx`), not the page file.
- Report fidelity to tool output is exact: 46 listings, 271 route records, 34 orphan candidates split 15 view / 4 parameterized / 15 action-form — all reproduce byte-for-byte from my own run.
- Spot-checks of the substantive numbers held: SupplierInvoice cursor controls are real `data.links.prev/next` buttons (`SupplierInvoiceListPage.tsx:349-366`), Contact/Z-Report bespoke paginators are real (`ContactListPage.tsx:129`, `ZReportListPage.tsx:293`), and **all 26** "bespoke" empty-state verdicts were individually confirmed as genuine `length === 0` render branches. `/inventory`, `/pos`, and the four deletion targets (`/pos/shifts`, `/marketing`, `/settings/chart-of-accounts`, `/growth`) all verify against source.

### Lens applicability

**frontend-conventions**: only partially exercised — no `apps/web/src` production code changed, so tokens/RHF/i18n/`tenantScopedKey` are not in play; the tooling-convention subset (exported-function shape, test co-location, lint/vitest wiring) passes. **general**: applies, findings below. **Rule 19 (money/quantity), tenancy/authz, constructor injection, migrations, Horizon queues, en+fr strings**: N/A — no backend, no runtime UI, no user-facing strings in this milestone.

---

## Findings register

**1. P2 — CONFIRMED — `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/17-listing-census-recensus.md:19`** (rule at `apps/web/tools/audit-listing-census.mjs:27`)
The discovery rule is presented as independently derived ("filesystem-derived… the number is an output, not an allowlist"), but it is verbatim the baseline audit's own definition — `02-design-system-consistency.md:66`: *"every `.tsx` under `src/features/**` matching `*ListPage | *ListView | *QueuePage | *IndexPage`. 46 files."* Landing on 46 is therefore tautological, not corroboration, and the report never says so. The population it inherits excludes **39 route-mounted, non-detail pages that render `<DataTable>`** — including `StockLevelsPage`, `StockMovementsPage`, `UsersPage`, `RolesPage`, `TenantsPage`, `AuditLogsPage`, `ShiftHistoryPage`, `WithholdingRulesPage`, `EntryExitNotesPage`, `ImportHistoryPage`, `PaymentMethodsPage` (64 including detail pages).
*Failure scenario:* Wave 3 scopes the listing-canon migration off "12/46 use `ListPageLayout`" and funds ~46 pages, then discovers ~85% more listing surface that was never in the denominator — the exact class of error CX-4 exists to eliminate, and `00 §5:248` makes this census the gate for that scoping. Reusing the baseline population for comparability is a defensible choice; presenting it as an independent re-derivation without naming the excluded set is not. Fix is report-only: state that the rule is inherited from `02:66`, and disclose the name-excluded listing surface with a count.

**2. P2 — CONFIRMED — `apps/web/tools/audit-listing-census.mjs:520`** (report claims at `:23` and `:164`)
`addExpression` discards any template whose **first** segment is a substitution: `templatePath()` (`:487`) builds `":param/new"` from `` `${basePath}/new` ``, which fails `candidate?.startsWith('/')` and is dropped entirely rather than treated as a wildcard-prefixed reference. 14 such production navigation sites exist. The report at `:164` attributes the 15 action/form orphan candidates to "opaque callback values or server-originated links" — that reason is factually wrong for at least seven of them, which are plain JSX links in production source: `/purchases/suppliers/new` ← `PartnerListPage.tsx:242,296`; `/purchases/suppliers/:id/edit` and `/sales/customers/:id/edit` ← `PartnerDetailPage.tsx:398`; `/sales/invoices/:id/edit`, `/sales/orders/:id/edit`, `/sales/quotes/:id/edit`, `/purchases/orders/:id/edit` ← `DocumentActionBar.tsx:371` and `DocumentForm.tsx:451`. The method section at `:23` compounds this by asserting "dynamic links are segment-matched" without the carve-out.
*Failure scenario:* a downstream reader (T9's doc, or Wave 3) treats "zero inbound UI references, cause unknown" as evidence toward deleting `/purchases/suppliers/new`, and supplier creation loses its only entry point — the link exists and is one `basePath` deref away from being resolvable. Either match prefix-dynamic templates by suffix or state the limitation in Method **and** correct the stated cause of the 15 rows.

**3. P3 — CONFIRMED — `apps/web/tools/audit-listing-census.mjs:295-299`**
`emptyBranch()`'s "true" test `length[\s\S]*(?:===?\s*0|<=\s*0|<\s*1)` runs first and spans the whole condition text, so a compound condition like `items.length > 0 && mode === 0` classifies as an *empty* branch. No live false positive exists at this base — I verified all 26 bespoke verdicts by hand — so this is latent, but UI-29's 24→1 reversal rests entirely on this predicate and it should be anchored on the same identifier.

**4. P3 — CONFIRMED — `apps/web/tools/audit-listing-census.mjs:203-205`**
Only **3 of 46** pages resolve past their own file (`Graph files = 2`; the other 43 are file-local), because traversal requires the imported binding's local name to end in `Filters|Grid|List|Queue|Results|Table|View`. Sampled misses are benign (badges, modals, row renderers), so the numbers stand — but the report claims graph-derivation as the basis for "decision-grade" without noting that 93% of rows are single-file and that delegation to a differently-named body is invisible.

**5. P3 — CONFIRMED — `apps/web/tools/audit-listing-census.mjs:27`**
`ListView` and `IndexPage` match zero files in `src/features/` (also inherited from `02:66`). Harmless, but they read as live coverage.

**6. P3 — CONFIRMED — `docs/handoff/HANDBACK-ui-wave0-2026-08-11.md:130`**
Red-first evidence for M1 is `ERR_MODULE_NOT_FOUND` — the module did not exist — not a per-assertion behavioral red, and tool + tests landed in one commit (`751255324`), so per-test red cannot be reconstructed from history. Standard degenerate case for greenfield tooling; recorded, not a blocker.

**7. P3 — note — `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/17-listing-census-recensus.md`**
The `17-` ordinal collides with the existing (untracked, gitignored) `17-research-open-question-best-practices.md` in the owner's main working copy. The path was mandated by the brief, so this is not an executor defect — flagged so the parent can renumber at merge.

### Bypasses attempted that FAILED to find a defect

- Tried to break the bespoke empty-state heuristic across all 26 positive verdicts — every one is a genuine empty-render branch (incl. the two that my first grep missed: `GoodsReceiptListPage.tsx:577,672` and `DocumentIngestionListPage.tsx:88`).
- Tried to trip the pagination regex on its literal `cursor-based pagination` comment alternative — SupplierInvoice has real controls; verdict independent of the comment.
- Tried to make the reference extractor over-match (API path strings like `apiGet('/fraud-alerts')`, external `href`, `<Navigate>` inside `routes/index.tsx`) — none are collected; `ROUTES_FILE` is correctly excluded from the reference set.
- Tried to falsify the four M3 deletion targets' orphan status — all four confirmed unreferenced (`/settings/chart-of-accounts` orphaned while `/finance/chart-of-accounts` is live in Sidebar/CommandPalette/FinanceHub, exactly as the duplicate-mount finding predicts).
- Tried to find the tool wired into `lint`, preflight, or CI, or exiting non-zero — none; exit 0 confirmed by execution.
- Tried to find scope creep into production code or a broken gate — `typecheck`, targeted `eslint`, `node --test`, and `vitest` are all green.

Both P2s are report-level corrections (plus, optionally, a small extractor change for #2); the tool, tests, and every headline number I could independently re-derive hold up.

VERDICT: CHANGES-REQUIRED
