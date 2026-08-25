# BRIEF — Lane B2-6 / residual sweep (small backend items from Wave 1 + one web one-liner)

Follow `LANE-PROTOCOL.md`. Worktree (parent creates): `.worktrees/sb2-b26-residual-sweep`, branch
`fix/sb2-b26-residual-sweep`, base = dev tip at dispatch. PG 5433 `autoerp`/`autoerp_secret`, own DB
`autoerp_b26_test`. Tool calls < 90 s, one test file per run, by path, never the suite, no stash, never push.
No migration in this lane. Each item = its own commit (`fix(sb2-b26/<id>): …`) so the gate can bisect.

DEFERRED, do NOT touch (own lanes): C-15(ii) void-edge guard widening (fiscal migration), C-16(ii) `terminal_id` on
held-order recall (web POS — Session A's Playwright surface), C-17(i) device binding invalidation (POS device lane),
C-17(iii) web `releaseTerminal` client, C-20(i) Menu/Tables table-state writes (F&B track), C-14(v) PG fixture
overflow (test-hygiene lane).

## Part A — backend (gate: tenancy-authz + inventory-costing)
- **C-30(i)** `Treasury/Presentation/Controllers/PaymentMethodController.php:95,147,201-206`: stop STORING an
  arbitrary UUID into `payment_methods.default_journal_id` (no `journals` table exists anywhere). Rule:
  `'default_journal_id' => ['prohibited']` on store AND update (typed 422 via the standard `{error:{errors}}`
  envelope), never written from `$validated`; keep the column (dropping it is a migration). Red-first: POST with a
  uuid today persists it. Docblock says why (C-30, Q-12 gate).
- **C-14(ii)** `Inventory/…/InventoryCountingService::manualOverride()` (`~:1086`) + `CountingItemController`
  (`~:288-310`): take the same header lock (`lockCounting`) and refuse terminal states with the SAME typed 422
  `COUNTING_TRANSITION_REFUSED` the sibling `setOpeningCost` uses. Red-first: override on a finalized counting today
  writes.
- **C-14(iii)** `finalize()` unresolved-items path (`~:1131`, pinned by `ReconciliationTest.php:494` as a 500) → the
  typed 422 like its sibling refusal; update that pin honestly (it asserted the wrong behaviour).
- **C-14(iv)** the `COUNTING_TRANSITION_REFUSED` message: put the key in the i18n backend catalogue the other typed
  422s use (find how `MATCH_NOT_ALLOWED` / `TERMINAL_HAS_OPEN_SHIFT` are surfaced; if the backend does not
  translate, the FE key is the deliverable — report which).
- **C-16(iii)** `POS/…/routes_held_orders.php:15-20`: add `permission:pos_held_orders.view|create|delete`
  middleware per verb (permissions are seeded at `RolesAndPermissionsSeeder.php:375-377`). Red-first: a company
  member with none of the three can discard a held order today. Check the seeded roles that must keep working
  (cashier role has them? — verify in the seeder, report the matrix).
- **C-17(iv)** `POS/routes.php:63-77` terminal route group: `->whereUuid('id')` group-wide (non-UUID → 404 not 500).
  Red-first: `GET /pos/terminals/not-a-uuid` 500s.
- **C-17(ii)** `TerminalController.php:~785` `z_hash_sequence` from `count()` → derive from the latest Z row (MAX /
  latest by `z_number`), consistent with `z_number`. Red-first: delete one middle Z row in a fixture → count ≠ max.
- **C-17(vii)/(viii)** `generateTerminalCode()` `count()`-TOCTOU → max+1 under the existing advisory/row lock of the
  claim path (state which); `release()` open-shift probe `:540-561` check-then-act → run the probe after
  `lockForUpdate()` on the terminal row inside the transaction. Pin each with the query-log/ordering style used in C-27.

## Part B — web one-liner (gate: frontend-conventions, may be parent-verified if trivially exact)
- **C-28(i)** `apps/web/src/features/purchases/supplier-invoices/SupplierInvoiceDetailPage.tsx:353-361`: wrap the
  Re-match button in `{!isPosted && …}` exactly like Post (`:343`), and give `handleRematch` (`:223-229`) an
  `onError` that surfaces the API message like `handleLinkReceipts` does. Vitest by path for that page if a spec
  exists; `pnpm lint` + typecheck on the file; kill vitest pools afterwards
  (`pgrep -fl 'node (vitest'`). Design tokens rule 18 applies only to lines you touch.

## Deliverable
LANE-PROTOCOL §Deliverable per item (red→green, file:line), the held-order permission matrix, the i18n finding,
manifest numbers. Do NOT merge.
