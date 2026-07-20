# Multi-location — RESUME brief for the paused Codex Desktop thread (2026-07-20)

> Paste-context for resuming the multi-location implementation thread that was interrupted
> on 2026-07-19 by a network outage + laptop restart. This supplements — does not replace —
> the original dispatch brief `docs/handoff/CODEX-multi-location-2026-07-16.md`. All protocol
> rules there (worktree, wave/gate structure, TDD, commit discipline, ledger updates) still apply.

## Where you stopped (verified against the branch by the controller)

- Worktree: `/Users/houssamr/Projects/syneriva/apps/erp.multiloc`, branch `feat/multi-location`, tip `4c29e831b`.
- Wave 1 (§1 scope foundation): Tasks 1–11 complete, including the post-gate hardening commits
  `d44f43422..4c29e831b`. Plan checkboxes 86/86.
- Wave 2 (§2 inventory visibility): Tasks 1–8 complete, incl. deferred PostgreSQL tests
  (`StockRebalanceEndpointTest`, `StockMovementLocationFilterTest`).
- Wave 3 (§3 financial dimension): Tasks 1–3 complete, incl. deferred tests
  (`PaymentRepositoryLocationTest`, `PosBridgeLocationAttributionTest`).
- Wave 4 (§4 analytics): NOT started.
- Your `.superpowers/sdd/progress.md` multiloc ledger append is present but UNCOMMITTED in the
  worktree — commit it (with this brief) as your first housekeeping commit on resume.

## What the controller did while you were paused

- The three pending gate reviews (Gate 1 re-run after your fixes, Gate 2, Gate 3a) plus the
  §2-mandated frontend-conventions review were run by Claude reviewer agents on 2026-07-20.
  Verdicts are in `.gates/gate-1-verdict.md`, `.gates/gate-2-verdict.md`,
  `.gates/gate-3a-verdict.md`, `.gates/wave2-fe-conventions-verdict.md`.
  The stale `ENOTFOUND` error in gate-1-verdict.md has been replaced by a real verdict.
- The stray partial copy of Task-4 files that had leaked into the main `apps/erp` dev worktree
  was discarded there (your branch copies are canonical). Reminder: NOTHING from this branch may
  be cherry-picked onto dev standalone — the §2 stock-matrix endpoint commit that leaked onto dev
  500'd (no `LocationScopeResolver` there) and was reverted (`a130bdebb` on dev). Everything
  lands via the final whole-branch merge only.

## Gate outcomes and required fixes (controller, 2026-07-20 — full detail in `.gates/*-verdict.md`)

**Gate 1 (Wave 1 tenancy/authz): APPROVE.** All six hardening commits verified to hold; 3 minors,
none blocking (destinations-picker name disclosure is spec-sanctioned; stale autoload classmap entry
clears on deploy `dump-autoload`; optional intent guard for membership-less `users.create` caller
writing `[]` instead of NULL). Tag `multiloc-gate-1` at `4c29e831b` immediately on resume.

**Gate 2 (Wave 2 inventory): REJECT — fix before Wave 3 Task 4:**
- [IMPORTANT] The aggregate tests (`StockRebalanceEndpointTest`, `StockMovementLocationFilterTest`,
  `StockMatrixEndpointTest`, `StockThresholdTest`) run on SQLite `:memory:` (`apps/api/phpunit.xml:41-42`);
  the plan (constraint 9 / I8) requires PostgreSQL. Pin these named tests to a real pgsql connection
  (test-class override or dedicated phpunit testsuite + env) so "passes on PostgreSQL" is verifiable,
  and run them there.
- Also land the owed `GoodsReceiptDestinationTest` (item 2 below) — the reviewer confirmed the
  A-then-B semantics hold in code but are unpinned.
- Minors (fix opportunistically, don't block): rebalance severity sort uses `-available` not deficit
  depth; `StockThresholdService` read-then-insert race can 500 on the partial unique index;
  `incoming` parent/child sum edge documented in-code.

**Gate 3a (Wave 3 Tasks 1–3 treasury): REJECT — fix before Wave 3 Task 4:**
- [IMPORTANT] `AddRepositoryModal.test.tsx:26` mocks `useLocations` but the component consumes
  `useTransactionLocations` (`AddRepositoryModal.tsx:18,202`) — the committed test is RED and the
  `location_id` FE payload path has zero real coverage. Fix the mock target and make the test pass
  honestly (controller independently confirmed the mismatch).
- [IMPORTANT] `PosBridgeLocationAttributionTest.php` only reflection-invokes the private
  `resolveTerminalLocationId`. Rewrite to the real contract: dispatch each bridge `apply()` with
  `CompanyContext` cleared, assert the PERSISTED `Payment`/`PaymentInstrument.location_id`;
  cover terminal-overrides-repository precedence, the deposit `?? repository->location_id`
  fallback, and maturity-leg instrument propagation. On PostgreSQL.
- Minors: `ResolvesTerminalLocation.php:61-63` swallows `QueryException` unlogged (log like
  `PosCoreReceiptProjection::resolveTerminal`); document the intentional receipt-vs-deposit
  fallback asymmetry in-code.
- Note: your `ScopedExists::company` deviation from the plan was reviewed as CORRECT (locations
  has no `tenant_id`) — keep it; no change needed.

**Wave 2 FE conventions: REJECT — fix before Wave 3 Task 4:**
- [BLOCKER] `pnpm lint` exits 1: `audit:design-system` has 5 new unbaselined violations + 2 stale
  baseline entries. Replace raw elements with canonical components (do NOT baseline them):
  `ProductStockLevels.tsx:176` raw `<table>` → DataTable; `StockByLocationPage.tsx:28` raw search
  `<input>` → Input atom; `LocationAccessField.tsx:46,62,85` raw radios/checkbox → canonical
  Radio/Checkbox. Remove the 2 stale `OwnerDashboardFilters` baseline entries.
- [IMPORTANT] Missing-key i18n defects: `t('common.loading')` (`StockByLocationPage.tsx`,
  `RebalancingView.tsx`) and `t('common.cancel')`/`t('common.confirm')`
  (`TransferSourceSuggestion.tsx`) render literal keys — the `inventory`/`stock-transfers`
  namespaces have no `common` block and no `fallbackNS` is configured. Either add the keys to those
  namespaces (en+fr, ar where present) or reference the shared namespace explicitly.
- [MAJOR] `RebalancingView.tsx:18` renders raw location UUIDs instead of location names.
- Minors: `ar/inventory.json` missing `stock.minQuantity`/`stock.maxQuantity`;
  `CreateStockTransferPage` key `['locations','scoped']` mislabels an unscoped `fetchLocations()`;
  `ThresholdEditCell` doesn't invalidate `['product-stock', productId]` so product-page threshold
  edits don't refetch.
- Verified clean (don't re-churn): key scoping, no double-unwrap, precision/formatQuantity, color
  tokens, RTL, permission gating, en/fr parity outside the findings above.

## Remaining work, in order

1. **Gate-finding fixes** (all Critical/Important/Blocker items above), each with the usual
   TDD + fresh-review loop. Tag `multiloc-gate-1` now; tag `multiloc-gate-2`, `multiloc-gate-3a`
   once their finding sets are clean (the controller will re-verify before tagging counts).
2. **§2 Task 6 owed test:** dedicated `tests/Feature/Inventory/GoodsReceiptDestinationTest.php`
   (PostgreSQL) per plan `2026-07-16-multiloc-2-inventory-visibility.md` lines ~486–487, including
   the A-then-B repeated-partial-receipt case (receipt-1 movement immutable at A;
   `document_lines.location_id` follows the LATEST destination; incoming remainder follows the line).
   Check off those two plan boxes.
3. **Wave 3 Tasks 4–13** per `docs/superpowers/plans/2026-07-16-multiloc-3-financial-location-dimension.md`
   (66 open checkboxes): payment/document attribution writers (Tasks 4–6), guarded backfill
   command — command ONLY, never auto-run in a migration (Task 7), the four surfaces (Tasks 8–12),
   and the cross-grain reconciliation tests (Task 13). Then request **Gate 3b** (treasury-reviewer
   severity; the controller runs it — write `.gates/gate-3b-request.md` and STOP for the controller,
   do not self-approve).
4. **Wave 4 Tasks 1–6** per `docs/superpowers/plans/2026-07-16-multiloc-4-analytics-dashboards.md`
   (39 open checkboxes). Task 6 (consolidated home widgets) stays LAST as planned. Then request
   **Gate 4** the same way.
5. **Final handback:** E2E verification checklist from plan §2 (matrix render + threshold
   round-trip; suggested source on transfer create; receive-to-chosen-destination movement check;
   light/dark visual pass of the matrix page), deploy checklist update
   (`docs/handoff/multiloc-deploy-checklist.md` — includes `tenants:migrate` for the new
   location_id migrations + `goods_receipts.location_id`, permission cache reset if any perms
   added), ledger finalized, then STOP. The controller (Claude session) verifies and merges to
   local dev — you do NOT merge or push.

## Standing constraints (unchanged, restated because they bit us already)

- Work ONLY in `/Users/houssamr/Projects/syneriva/apps/erp.multiloc`. Never touch the main
  `apps/erp` worktree or shared `dev`.
- Precision contract: bcmath/`CurrencyScale`/`QuantityScale` everywhere; no float on money/qty;
  frontend `formatQuantity`/`formatCurrency`, payloads as strings.
- Queued jobs run with NO CompanyContext — pass explicit currency/tenant context (POS bridge
  resolvers already follow this; keep it that way in Tasks 4–6).
- Location attribution grains are pinned by the spec: repository = custody, instrument = origin
  (frozen at receive), payments/documents = header. Do not blur them in the surfaces.
- All new endpoints: `['api','auth:sanctum',SetPermissionsTeam::class]` + location access via the
  §1 `ValidLocationAccess` rule (422), not imperative context checks.
- i18n: every new key in en AND fr (ar where the namespace has it); tenant-scoped TanStack keys.
