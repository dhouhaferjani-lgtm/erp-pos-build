# Treasury Phase ③ — Adversarial Spec Review (Rev 1 → Rev 2)

> **Date:** 2026-07-12 · **Spec reviewed:** `docs/superpowers/specs/2026-07-12-treasury-phase3-cash-visibility-design.md` Rev 1 (commit `8b57353d5`)
> **Lanes (owner tiering per handoff):** Lane 1 = treasury-reviewer on **Fable 5** (transfer/GL money path — owner-authorized escalation surface); Lane 2 = treasury-reviewer on **Opus** (read layer); Lane 3 = tenancy-authz-reviewer on **Opus** (notification center).
> **Verdicts:** Lane 1 CHANGES-REQUIRED · Lane 2 CHANGES-REQUIRED · Lane 3 CHANGES-REQUIRED
> **Tally:** 3 BLOCKER · 5 HIGH · 6 MEDIUM · 13 LOW/notes. Reconciliation → spec Rev 2 §15.

---

## LANE 1 — money path: inter-repo transfer + GL + reconcile (treasury-reviewer, Fable 5)

**Reviewed against code at:** `dev` (`3b4f11434`), backend `apps/api`
**Key files read in full:** `apps/api/app/Modules/Treasury/Application/Services/TreasuryMovementService.php`, `apps/api/app/Modules/Treasury/Presentation/Console/ReconcileTreasuryCommand.php`, `apps/api/app/Modules/Treasury/Presentation/Controllers/RepositoryAdjustmentController.php`, `GeneralLedgerService.php` (postEntryNow / sealAndPersistEntry / createRepositoryAdjustmentJournalEntry / generateEntryNumber), transfer DTOs, `RepositoryType`, seeders, routes, `JournalCode`, migration `2026_06_26_120000_unique_journal_entries_source_procurement.php`, vendor `DatabaseTransactionsManager`.

### Findings

#### 1. [BLOCKER] §5.3 + §14 vs §5.2.3 — the proposed partial unique index kills the spec's own replay design

The spec proposes `CREATE UNIQUE INDEX ... ON journal_entries(source_type, source_id) WHERE source_type='treasury_transfer'` (§5.3, §14), mirroring the procurement convention (`2026_06_26_120000_unique_journal_entries_source_procurement.php:44-48` — note that index has **no status filter**, and none of its flows re-insert a JE per attempt).

But §5.2.3's replay design creates a **fresh draft JE with `source_id = $transferGroupId` on every attempt**, in the outer transaction, **before** `transfer()` runs. On a replay (or a concurrent double-submit), the original attempt has already committed a posted JE with the same `(source_type, source_id)`. The second draft's INSERT violates the index **at step (a)** — outside `transfer()`'s unique-violation catch (`TreasuryMovementService.php:298-305`, which only guards the savepoint body). The `QueryException` propagates unmapped → HTTP 500, the outer transaction rolls back, and the idempotent-replay path is **never reached**. The concurrent-race variant is worse: T2's draft insert blocks on T1's uncommitted index entry, then errors when T1 commits → 500. The spec's own §10 test ("idempotent replay … no new JE, no orphan draft") would fail as designed.

**Why it matters:** the entire idempotency story (§4 "CHOSEN — optional client-supplied transfer_group_id", §5.5 FE double-click safety) is structurally broken by the spec's own index.

**Fix:** scope the index `WHERE source_type = 'treasury_transfer' AND status = 'posted'`. Then: replay draft inserts cleanly; `postEntryNow`'s `$entry->update(['status' => Posted, …])` (`GeneralLedgerService.php:2912-2919`) fires the 23505 **inside** `transfer()`'s savepoint; it IS a `QueryException` → caught at `TreasuryMovementService.php:298` → `isUniqueViolation()` (`:674-689`, SQLSTATE 23505) → `handleTransferIdempotentHit()` (`:554-576`) returns the original legs; the savepoint rollback reverts the fresh draft to Draft; the service deletes it per §5.2.3c. Trade-off to state explicitly in Rev 2: "one JE per transfer group" is then enforced on **posted** entries only. Rev 2 must promote §13's "verify §5.2.3c empirically" bullet to a **required dedicated test** (sequential replay + concurrent race), because the whole flow now hinges on the violation surfacing at the status-flip UPDATE rather than the draft INSERT.

#### 2. [HIGH] §5.1/§10/§11 — frozen-repo 422 is claimed from the port, but `transfer()` has NO freeze check at all

§5.1: "frozen repository → 422 (port `RepositoryFrozenException` mapped, message names the frozen repo)". §10: "draft-JE rollback on port failure (**force a frozen target repo** → no orphan draft JE)". Verified against the port: `transfer()` (`TreasuryMovementService.php:154-355`) **never reads `frozen_at`** on either repository; `TransferIntent` has no `allowWhileFrozen` field; both legs hardcode `recordedWhileFrozen: false` (`:272`, `:291`). Only `record()` enforces the freeze policy (`:79-85`). So as specced, a transfer into or out of a drift-frozen repository **succeeds silently** — writing unflagged movements into a repo frozen precisely because its ledger is suspect — and the §10 test fails, inviting an implementer to "fix" it by editing the port, which §11 forbids ("`TreasuryMovementService.php` port methods byte-untouched").

**Fix:** Rev 2 must (a) state explicitly that the freeze check lives in `RepositoryTransferService` (both repos, throw `RepositoryFrozenException` — it extends `DomainException` → 422, `RepositoryFrozenException.php:17`), (b) document the TOCTOU: the service reads unlocked, and `treasury:reconcile` can freeze between the pre-check and the port's row locks (nightly cadence → tiny window; acceptable if stated), and (c) rewrite the §10 draft-rollback test to force a port failure the port actually throws — e.g. destination-repo currency mismatch (`CurrencyMismatchException` thrown at `:204-206`, after the draft exists) — instead of "frozen target".

#### 3. [MEDIUM] §5.1 response contract — on idempotent replay, `journal_entry_id` would be the DELETED draft's id

The 201 body includes `journal_entry_id` (§5.1). On replay, the service's fresh draft is deleted (§5.2.3c) and `TransferResult`/`MovementResult` carry no journal-entry field (`MovementResult` = movementId/balanceAfter/ordinal/wasIdempotentHit only — DTO verified). Returning `$entry->id` hands the client a dangling id; returning null is wrong (the original legs DO carry a JE). Confirmed safe on the DB side: legs reference the ORIGINAL entry, and `assertTransferLegMatches` deliberately does **not** compare `journal_entry_id` (`TreasuryMovementService.php:583`), so deleting the fresh draft never conflicts with replay validation — but the HTTP contract as written returns wrong data. **Fix:** when `wasIdempotentHit`, fetch the out-leg row by `MovementResult->movementId` and return its stored `journal_entry_id`. Pin in the replay test.

#### 4. [MEDIUM] §5.1/§5.2 — `RepositoryType::Virtual` is not excluded from transfers

`RepositoryType` has a fourth case, `Virtual` (`RepositoryType.php:12`), which the cash-position surface deliberately excludes (spec §8.1 itself lists `cash_register|bank_account|safe`). The transfer spec validates only `is_active` (§5.1/§5.2.1) — a transfer to/from a `virtual` repository would post real cash Dr/Cr against whatever `gl_account_id` the virtual bucket carries, moving "cash" into a non-physical bucket invisible to the cash-position widget the same phase ships. The spec is silent. **Fix:** restrict both sides to `CashRegister|Safe|BankAccount` (422 otherwise), or record an explicit owner decision that virtual repos are transferable.

#### 5. [MEDIUM] §5.2 — TOCTOU on `gl_account_id` can commit an orphan Draft JE despite §5.2.3b's promise

The service computes `crossGl` from an **unlocked** read (§5.2.1); the port recomputes from the locked rows (`TreasuryMovementService.php:214`). Divergence case (a) — service says same-GL, port says cross-GL — fails closed (`DomainException` at `:215-217`, outer rollback, no orphan). Case (b) — service says cross-GL and creates the draft, port's locked read says same-GL (concurrent `gl_account_id` reassignment) — `$legJournalEntryId` stays null (`:247-254`), the legs insert successfully as a **fresh** success, `wasIdempotentHit` is false, and the service's §5.2.3c cleanup never runs → **committed orphan Draft JE**. Reconcile stays green (same-GL legs are exempt, `ReconcileTreasuryCommand.php:590-592`; drafts are invisible to check #2), so the orphan persists silently — exactly what §5.2.3b promises cannot happen. Rare, but the fix is one cheap compensating check: after a fresh (non-replay) success, fetch the out-leg row and delete the draft if the leg's `journal_entry_id` is null / differs from the draft id. Fold into the same check as finding 3.

#### 6. [LOW] §5.2 "Lock order preserved" paragraph is factually wrong (harmlessly, but correct it)

"The draft-JE insert takes no advisory/row locks" — false. Any `createRepositoryTransferJournalEntry` mirroring the adjustment method will call `generateEntryNumber`, which takes the **same per-company GL advisory lock** (`GeneralLedgerService.php:3867-3869`) at draft-creation time, before `transfer()` runs. No inversion — it is the identical `pg_advisory_xact_lock(hashtextextended(companyId,0))` the port takes first anyway (`TreasuryMovementService.php:175`), re-entrant in-session, and still strictly before any repo row lock — so the global order holds, just for a different reason than the spec states. Correct the sentence so implementers carry the right mental model (the advisory lock is held from draft creation to outer commit, serializing all GL posting for the company across the transfer — same as the adjustment flow).

#### 7. [LOW] Replay idempotency breaks across a fiscal-period close

On a cross-GL replay, `sealAndPersistEntry` checks `isDateInClosedPeriod` **before** anything can hit the legs' unique violation (`GeneralLedgerService.php:2902-2904`). A replay arriving after the period containing "now" closes gets `ClosedFiscalPeriodException` (422) instead of the idempotent result. Window is near-nil (`entry_date` = now, D-5 forbids backdating); document as a known non-goal in Rev 2 rather than engineering around it.

#### 8. [LOW] Same-GL exemption is evaluated against the repos' CURRENT `gl_account_id` — reassignment becomes a nightly-freeze hazard once same-GL legs exist at volume

`isSameGlAccountTransfer` resolves exemption from the repositories' **present** `gl_account_id` (`ReconcileTreasuryCommand.php:603-617`), not a value captured at transfer time. Phase ③ is the first feature minting null-JE same-GL legs in production; any later admin reassignment of one repo's GL account flips that history to non-exempt → check #2 "missing journal entry" (`:502-513`) → both repos freeze on the next run. Pre-existing reconcile property, but the spec should name it: either guard `gl_account_id` edits on repos with same-GL transfer history, or record it as an operational caveat.

#### 9. [LOW] Amount scale: the regex ceiling (3 dp) is per-column, not per-currency — port stores the raw string

For a scale-2 currency, `10.005` passes §5.1's regex; `insertMovementLeg` stores `amount` raw while `balance_after` truncates at currency scale (`TreasuryMovementService.php:414-416`). Reconcile stays green (check #1 recomputes at the same scale, `ReconcileTreasuryCommand.php:452, 473-475`; check #2 compares raw-to-raw with 1-ULP tolerance), and this matches the adjustment endpoint's shape — but the JE would carry sub-scale precision in the books. Cheap hardening for Rev 2: service normalizes via `CurrencyScale::bcformatStrict($amount, $resolver->getScale($repo->currency))` before building the intent (rule 19 "round once at the boundary"). Practically nil for TND(3); include for parity.

### Verified-accurate spec claims (checked, not trusted)

- **Adjustment exemplar posts immediately** — `createRepositoryAdjustmentJournalEntry` ends with `postEntryNow` (`GeneralLedgerService.php:964`); the spec's draft-vs-posted distinction (§5.2.3a note, §13) is correct AND necessary: passing an already-posted JE would hit `'Only draft entries can be posted'` (`:2853-2855`) — an `InvalidArgumentException`, not a `QueryException`, sailing past the catch at `TreasuryMovementService.php:298` as a 500.
- **§5.2.3's "no replay pre-check" rationale** — the cross-GL null-JE guard sits at `:214-217`, before the savepoint/replay machinery. Correct.
- **§5.2.3's savepoint-rollback claim** — Laravel 12's `DatabaseTransactionsManager::rollback` rejects pending transactions with `level > newTransactionLevel` (vendor, lines 128-136), discarding both `postEntryNow`'s deferred event (`GeneralLedgerService.php:2832-2834`) and the leg events (`TreasuryMovementService.php:309-342`) registered inside the rolled-back savepoint. `sealAndPersistEntry`'s other effects (status/hash/chain_sequence) are pure row UPDATEs — rolled back. Chain integrity survives: the rolled-back sequence allocation never commits, and `entry_number` is `max()+1`-derived (`:3871-3879`) so the deleted draft's number is reused, no permanent gap.
- **Orphan-draft deletion is mechanically clean** — `journal_lines` has `cascadeOnDelete` (`2025_11_30_100000_create_journal_entries_table.php:49`); no immutability trigger exists on `journal_entries`; nothing references the fresh draft (replayed legs point at the original entry).
- **Reconcile stays green for spec-shaped transfers** — check #2: out leg matches the Cr on `from.gl_account_id`, in leg the Dr on `to.gl_account_id` via the authoritative branch (`ReconcileTreasuryCommand.php:654-675`); same-GL null-JE legs exempt (`:590-592`); check #3 nets tenant-wide by group (`:737-753`) — equal-amount paired legs net to zero. Check #1 unaffected (port-maintained continuity).
- **Negative-balance policy (§5.4)** — consistent: neither `record()` nor `insertMovementLeg` imposes a floor.
- **Seeder-drift claim (§9)** — accurate: `PermissionSeeder.php:88-89` has only `treasury.view`/`treasury.manage` (already missing `treasury.adjust`); `RolesAndPermissionsSeeder.php:222-224` has `treasury.adjust`. Adding `treasury.transfer` to both is right.
- **§13 middleware open question — resolved:** the treasury route group already carries `['api','auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` (`Treasury/Presentation/routes.php:31`); the adjustment route's `can:treasury.adjust` (`:92-94`) is the exact pattern to copy.
- **D-1 journal code default needs zero code** — `JournalCode::fromSourceType('treasury_transfer')` falls through to `Misc`/OD (`JournalCode.php:40`), same as `'repository_adjustment'` (also unlisted → OD). Rev 2 should state this explicitly so nobody adds a match arm without D-1's expert-comptable sign-off.
- **§11 "port byte-untouched" sufficiency** — yes, with two amendments: the frozen check must be explicitly assigned to the service (finding 2), and the index must be status-scoped (finding 1). With those, one new GL method + `RepositoryTransferService` genuinely suffice; nothing else in the flow needs the port edited.

**VERDICT: CHANGES-REQUIRED**

**What to fix before Rev 2 goes to planning:** (1) scope the partial unique index to `status='posted'` and pin the replay + concurrent-race path with a dedicated test — as written the index 500s every replay before `transfer()` runs; (2) move the frozen-repo check explicitly into `RepositoryTransferService` (the port has none) and re-vehicle the §10 draft-rollback test onto a failure the port actually throws; (3) define the replay response's `journal_entry_id` source (out-leg row, not the deleted draft); (4) decide virtual-repo eligibility; (5) add the compensating draft-cleanup for the fresh-success/null-JE divergence case.

---

## LANE 2 — read layer: G12 report page, C9 flows + widget, FE transfer modal (treasury-reviewer, Opus)

### Findings

#### 1. [HIGH] §7.1 — `totals {in, out, net}` mixes currencies; `net` is meaningless across foreign-currency rows
The report rows carry a **per-row** currency, not a single company currency. The payments leg selects `payments.currency` (`CashMovementsReportService.php:205`) while the journal-lines leg selects the injected `$companyCurrency` (`CashMovementsReportService.php:287`), and `formatRow()` resolves scale per-row from `$row->currency` (`CashMovementsReportService.php:349-350`). The service is explicitly designed to emit heterogeneous-currency rows. A single `net = in − out` (and even `in`/`out`) computed by bcmath-summing across those rows silently adds e.g. EUR to TND — exactly the C8 "FE currency mixing" gap the mandate flags.
**Fix:** either (a) return totals **grouped by currency** (`totals: { EUR: {in,out,net}, TND: {...} }`), or (b) compute totals only for rows whose `currency === companyCurrency` and surface a `has_foreign_currency` flag so the FE totals row can warn instead of lying. The FE totals row in §7.2 must render per-currency, not one scaled figure. Add a test asserting totals under a mixed-currency dataset (the spec §10 "mixed payment-side/journal-side rows" test does NOT cover mixed *currency* — it must).

#### 2. [HIGH] §5.5 / §9 — `treasury.transfer` is not a valid FE `Permission`; the gate won't typecheck and the dev-role fallback never shows the button
`Permission = keyof typeof PERMISSIONS` (`usePermissions.ts:235`). The `PERMISSIONS` map contains only `treasury.view|create|edit` (`usePermissions.ts:65-67`) — no `treasury.transfer` (and no `treasury.adjust`/`treasury.manage` either). So `usePermissions().hasPermission('treasury.transfer')` as written in §5.5 (a) fails TypeScript strict compile (rule 3), and (b) even if forced, falls through `hasPermission` to `PERMISSIONS[permission] === undefined → return false` (`usePermissions.ts:299-301`) whenever the token has not yet been reseeded/re-issued — so the button is invisible in dev and to any user whose token predates the perm reseed.
**Fix:** §9 must add `'treasury.transfer': [<same roles as treasury bundle>]` to `PERMISSIONS` in `usePermissions.ts` (FE), not only to the backend seeders. Confirm whether it belongs in `SERVER_AUTHORITATIVE_PERMISSIONS` (`usePermissions.ts:237`). Add this to the spec's deploy/FE checklist explicitly.

#### 3. [HIGH] §5.5 — the `repository-movements` invalidation key cannot match the real query key → stale movements after a transfer
`useRepositoryMovements` keys as `tenantScopedKey(['repository-movements', repositoryId, filters])` → actual runtime key `['repository-movements', repoId, filters, tenantId, companyId]` (`useRepositoryMovements.ts` queryKey + `tenantScopedKey.ts:` appends tenant/company as **suffixes**). The spec's invalidation `tenantScopedKey(['repository-movements', fromId])` produces `['repository-movements', fromId, tenantId, companyId]`. React Query v5 (`^5.90.11`) prefix-matches element-by-element: index 0 ✓, index 1 ✓, index 2 → `filters` (object) vs `tenantId` (string) ✗ → **no match**. The transfer's own new movement rows on both repos stay stale until a manual refetch.
**Fix:** invalidate movements with a **raw** prefix `['repository-movements', fromId]` / `['repository-movements', toId]` (no `tenantScopedKey`), since the filters object sits between the id and the tenant/company suffix and defeats any fixed-position scoped prefix. (`audit-tanstack-keys.mjs` only gates `useQuery` keys, not `invalidateQueries`, so a raw prefix here is allowed.) Note: the other five listed keys are fine — `payment-repositories`, `payment-repository/{id}`, `payment-repository-transactions/{id}` (`RepositoryDetailPage.tsx:242`, no filters segment), and `treasury-cash-position` (`useCashPosition.ts`) all match exactly.

#### 4. [MEDIUM] §8.1 — cash-position `flows {in, out}` inherits/extends the single-currency assumption without guarding it
`repository_movements.currency` is per-row `char(3)` (`2026_07_08_100100_create_repository_movements_table.php:21`). Summing `amount` across all included cash repos into one `flows.in`/`flows.out` returned under a single top-level `currency` mixes currencies if the company has repos in more than one currency. The existing `grand_total` already carries this latent assumption — it scales every `repository.balance` at `company->currency` with no repo-currency check (`CashPositionController.php:68, 80-89`) — so flows would merely propagate it, but the spec should make the assumption **explicit** (filter movements to company-currency repos, or group flows by currency) rather than silently widen an unstated invariant.
**Fix:** add a currency guard in §8.1 (either restrict the flows SUM to repos where `currency = company.currency`, or return per-currency flows) and state the single-currency-position assumption in §8/§11.

#### 5. [MEDIUM] §7.1 — "bcmath string sums … one extra aggregate query" is internally inconsistent given the union's TEXT amounts
The union casts amounts to text: `CAST(payments.amount AS TEXT)` (`CashMovementsReportService.php:204`) and `CAST(... AS TEXT)` (`:286`). A single SQL aggregate over `$base` therefore needs `SUM(CAST(amount AS NUMERIC))` — that is Postgres numeric arithmetic, **not bcmath**, and it cannot express per-currency grouping without a `GROUP BY currency`. Conversely, doing genuine PHP-bcmath sums requires fetching **all** filtered rows (unbounded — the report is paginated precisely because the set can be large), which contradicts "one extra aggregate query."
**Fix:** pick one and pin it: `SELECT currency, direction, SUM(CAST(amount AS NUMERIC)) … GROUP BY currency, direction` (one query, exact PG numeric, per-currency — also resolves Finding 1), then format each with `CurrencyScale::bcformatStrict`. Do not claim bcmath over an unbounded row fetch.

#### 6. [LOW] §7.2 vs §8.2 — gating axes for the report page and the widget are inconsistent
The report nav entry lands in the `accountingAndReports` sidebar group, which is gated `module: 'Accounting'` + `permission: 'accounts'` at the group and `permission: 'reports'` on the sibling `treasuryOverview` entry (`Sidebar.tsx:278-284`). The route uses `reports.view` (matches `/finance/overview` at `routes/index.tsx:1870`). The §8.2 widget instead gates on **Treasury** module + `treasury.view`. So a treasury-only user (Treasury module, no Accounting module) sees the widget and its "view movements" deep-link, but the report's **nav entry** is hidden (Accounting-gated) even though the route itself is reachable via `reports.view`. Functional, but the axes should be reconciled or the intended behavior documented (widget = Treasury axis, report = Accounting/reports axis).

#### 7. [LOW] §8 — flows are derived from `repository_movements` while `grand_total` is derived from `payment_repositories.balance`
`CashPositionController`'s own docblock forbids recomputing **position** from `repository_movements` ("MUST NOT recompute a position from repository_movements", `CashPositionController.php:27-31`). Flows (windowed in/out) are not a position, so the spec is compatible — but on a frozen/drifted repo the movements-derived flows and the balance-derived grand total can visibly disagree in the same widget. Note this dual-source in §8 and confirm `occurred_at` (indexed at `2026_07_08_100100…:39`) is the intended window column — it is the correct business-date choice and is populated non-null for every source type.

#### 8. [LOW] §5.5 — `RepositoryListPage` PageHeader currently passes a single action node
`RepositoryListPage.tsx:209` spreads a single `addButton` into `actions`, and its own add button gates on `repositories.manage` (`:69-70`), not `treasury.transfer`. Adding the Transfer button requires composing multiple actions (fragment/array) and confirming `PageHeader` renders more than one. Also confirm a user with `treasury.transfer` but not `repositories.manage` still reaches this page (route gate) — otherwise the transfer entry point is unreachable for that persona.

### Verified as correct (no action)
- §7.1 additive `direction` filter on the wrapped subquery is feasible: `direction` is a selected column of the union and `$base = DB::query()->fromSub($union, …)` (`CashMovementsReportService.php:91`) accepts a `->where('direction', …)` before count/rows.
- §7.2 exemplar claims hold: `RepositoryMovementsTab.tsx` uses `OffsetPagination` (`:13`), `DateRangeFilter` (`:14`), `api.get` returning `{data, meta}` (`:110`), and carries the in-code warning that the shared `DataTable` has no pagination (`:216-220`). `apiGet` dropping `meta` is real (rule 14) — the report page correctly must use `api.get` + `response.data`.
- §5.5 `TransferVoucherModal.tsx` is a valid `zodResolver` + `Modal` + `FormField` exemplar (`:5-8, 29-31`) — but note it uses `PartnerPicker`, not `Select`, and stores one field in local state; it is a *loose* pattern match, not a drop-in for a from/to/amount form.
- MoneyInput emits strings with a currency-aware `step` (EUR `0.01`, TND `0.001`) and no `parseFloat`/`Number` (`MoneyInput.test.tsx:46,56-63`; `MoneyInput.tsx:9,14`) — rule 19 clean.
- Route/permission consistency: `/finance/overview` uses `RequirePermission permission="reports.view"` (`routes/index.tsx:1870`); the proposed `/finance/cash-movements` under the same gate is consistent.
- `MODULE_PERMISSIONS.treasury → ['treasury.view']` (`usePermissions.ts:252`), `canAccessModule` (`:321-324`), and `useCompanyConfig().hasModule('Treasury')` (`CompanyConfigContext.tsx:65-75`; Sidebar module string is `'Treasury'` at `Sidebar.tsx:267`) all exist as claimed — the §8.2 dual-gate is buildable.
- i18n: `treasury.json` and `finance.json` each exist in `en/fr/ar` (`src/locales/{en,fr,ar}/`) — the §5.5 `ar` claim holds.
- `useCashPosition` extension with an options arg folded into the key is backward-compatible: current key is `tenantScopedKey(['treasury-cash-position'])` with no consumer-supplied args (`useCashPosition.ts`), and `TreasuryOverviewPage` calls it arg-less; a defaulted `{flowsWindow?}` folded in only changes the key when a window is passed.

**VERDICT: CHANGES-REQUIRED**

Fix before merge: (1) make report `totals` and (2) widget `flows` per-currency (kill the C8 currency-mix), (3) add `treasury.transfer` to the FE `Permission` map, and (4) invalidate `repository-movements` with a raw prefix (not `tenantScopedKey`) so post-transfer ledgers aren't stale.

---

## LANE 3 — notification center: multi-tenancy, authz, console-context Spatie (tenancy-authz-reviewer, Opus)

### Findings

#### 1. [BLOCKER] §6.3 — recipient resolution sets the Spatie team to the *company id*, but the team foreign key is `tenant_id`; as written the query returns ZERO recipients → alerts are silently never delivered
Spec §6.3: *"set the Spatie permissions team id explicitly for the company being processed before querying (`setPermissionsTeamId`)."*

Code evidence:
- `apps/api/config/permission.php:99` — `'team_foreign_key' => 'tenant_id'` (teams=true at `:134`). The Spatie team IS the tenant, never the company.
- Every existing call sets the team to a **tenant id**: `SetPermissionsTeam.php:28` (`$user->tenant_id`), `Identity/Domain/User.php:158-161` `getPermissionsTeamId()` returns `tenant_id`, and `DailyExpiryCheck.php:165` (`setPermissionsTeamId($tenantId)`).

If the sender calls `setPermissionsTeamId($company->id)`, the `->permission('treasury.manage')` scope filters `model_has_roles`/`model_has_permissions` on `tenant_id = <a company uuid>`, which matches no rows → **the `Notification::send($users, …)` collection is empty → the treasury drift/maturity alerts reach nobody**. This is the notification analog of the silent-403 trap: the whole feature no-ops in production while tests that bind context differently may pass.

Fix: mirror the proven exemplar `DailyExpiryCheck.php:165-174` exactly — `setPermissionsTeamId($tenant->id)` (tenant, not company), query, then restore in a `finally`. The command already has `$tenant` in scope inside `forEachTenant` (`ReconcileTreasuryCommand.php:136`, `InstrumentMaturityAlertsCommand.php:42`).

#### 2. [BLOCKER] §6.3 / D-7 — recipient query lacks a company-membership filter → cross-company information leak
Even after Finding 1 is fixed (team = tenant_id), *"users holding `treasury.manage` for the company"* resolved purely via `->permission('treasury.manage')` returns **every `treasury.manage` holder in the whole tenant, across all companies** — because Spatie permission scoping is tenant-wide (there is no company axis in the permission model). A user who is a member of company A only would then receive company B's `TreasuryAlertNotification`, whose payload (§6.3) carries `company_name`, the repository code, drift reason, and balance context. That is a cross-company disclosure.

Users ARE company-scoped in this repo: `Identity/Domain/User.php:137` (`companyMemberships`), `:231-236` and `:259-263` gate channel access on an *active membership in the specific company*. The correct exemplar already does this — `DailyExpiryCheck.php:167-174` filters `->where('tenant_id',$tenantId)->whereHas('companyMemberships', active in $companyId)->permission('batches.view')`.

This finding also underwrites D-7's safety: the *"show all the user's notifications regardless of active company"* FE decision (§6.1, §6.4) is only leak-free **because** every stored notification belongs to a company the user is a member of. That invariant holds only if the send-side membership filter exists. Without it, D-7 turns a send-side leak into a persistent inbox leak.

Fix: recipient query = `User::where('tenant_id',$tenant->id)->whereHas('companyMemberships', fn($q)=>$q->where('company_id',$company->id)->where('status','active'))->permission('treasury.manage')->get()`, team pre-set to `$tenant->id`. Add a §10 test with two companies + disjoint managers asserting a company-A manager does **not** receive company-B's alert (the spec's §10 line "disjoint managers" must assert the deny/non-delivery direction, not just counts).

#### 3. [HIGH] §6.3 — Spatie permission-cache tenant-blindness across the `forEachTenant` loop silently corrupts recipient resolution for every tenant after the first
`forEachTenant` (`TenantScopedCommand.php:150-187`) runs all tenants in **one process**, swapping only the DB connection (`tenancy()->initialize` at `:162`). No bootstrapper flushes the Spatie `PermissionRegistrar` — `config/tenancy.php:38-43` lists only Database/Cache/Filesystem/Queue bootstrappers, and grep found no `forgetCachedPermissions`/`PermissionRegistrar` in any provider or tenancy bootstrapper. The registrar memoizes its permission collection in an in-process property and only reloads it after `forgetCachedPermissions()`.

Consequence: the first `->permission('treasury.manage')` call (tenant A) memoizes tenant A's permission UUID. For tenant B (a *different* DB with a different `treasury.manage` UUID), the scope resolves the name to tenant A's UUID and queries tenant B's pivots with it → zero/incorrect recipients. This is the exact "permission cache tenant-blind" platform bug recorded in memory (`project_spatie_permission_cache_tenant_blind`). The spec §6.3 does not address it; §14's `permission:cache-reset` is a *deploy* step and does nothing for an in-process multi-tenant loop.

Fix: call `app(PermissionRegistrar::class)->forgetCachedPermissions()` at the start of each tenant iteration in both senders (right after `setPermissionsTeamId($tenant->id)`), before any permission query. Add a §10 test that runs the command across ≥2 tenants and asserts tenant B's managers actually receive the notification (the current single-tenant test would mask this).

#### 4. [MEDIUM] §6.2 — `POST /notifications/{id}/read` binds `{id}` into a uuid PK without a UUID guard → PostgreSQL 500 on a non-uuid id
`notifications.id` is a uuid PK (§6.1). `$request->user()->notifications()->findOrFail($id)` with `$id='not-a-uuid'` issues `where "id" = 'not-a-uuid'` against a uuid column → Postgres `invalid input syntax for type uuid` → **500, not the intended 404**. This is the repo's documented UUID-in-uuid-column pitfall (MEMORY: "validate `Str::isUuid()` before `where('uuid',$val)` or it 500s").

Fix: constrain the route param (`->whereUuid('id')`) or `Str::isUuid($id)` guard → 404/422 before the query. Add a test posting a malformed id expecting 404.

#### 5. [MEDIUM] §6.2 / §14 — missing module-registration step for the new `Notification` module
The spec describes the slim module but never lists the two mechanical steps every module needs: a `NotificationServiceProvider` that `loadRoutesFrom(__DIR__.'/../Presentation/routes.php')` (exemplar `Cart/Providers/CartServiceProvider.php:16-19`) **and** registering it in `bootstrap/providers.php` (the module list at `bootstrap/providers.php:3-56`). Without the provider entry, `routes.php` is never loaded and all four endpoints 404. Add both to §6.2/§14 as explicit deliverables.

#### 6. [LOW] §6.4 / §14 — wrong TopBar file path; the editable bell is in `organisms/`, not the cited `components/layout/TopBar.tsx`
`apps/web/src/components/layout/TopBar.tsx` is a 2-line re-export (`export { TopBar } from '../organisms/TopBar'`). The real component with the hardcoded fake dot is `apps/web/src/components/organisms/TopBar/TopBar.tsx` — static button at `:154`, `Bell` at `:159`, hardcoded always-on danger dot at `:160` (`absolute … rounded-full ${colorTokens.intent.danger.bg}`). The spec's line numbers (154-161) match the organisms file, so the intent is right, but §6.4/§14 name the re-export path. Correct the path so the FE lane edits the right file (and note the dot uses `colorTokens.intent.danger`, already a design token per rule 18).

#### 7. [LOW] §4(a)/§6(a) — the "fixes the two existing database-channel notifications as a side effect" justification is not fully verifiable and should not be leaned on
Verified: `CriticalBatchExpiryNotification.php:26-29` and `EnrichmentCompletedNotification.php:27-30` both declare `via() = ['database']`, and no generic `notifications` table exists yet (only `database/migrations/tenant/…create_certification_expiry_notifications_table.php`, a domain table). So the table is genuinely absent and these two currently no-op — correct. **But** their delivery lands in the tenant DB only when tenant context is initialized. `DailyExpiryCheck.php:33` is annotated `@cross-tenant-by-design … scans the whole table` — under db-per-tenant there is no cross-tenant `batches` table, so that job's tenant routing is itself an open pre-existing question. The notification center's own value does not depend on this claim; recommend downgrading §4(a)/§6(a) wording from "fixes them" to "becomes compatible with them" and not counting it as a deliverable.

#### 8. [LOW] §6.2/§6.4 — legacy rows' top-level `type` will be a PHP FQCN; generic fallback must be verified against the actual legacy payload shape
Neither legacy notification defines `databaseType()`, so their framework `type` column stores the FQCN (`App\Modules\BatchExpiry\…`), contradicting the spec's "stable string alias" resource contract for those rows. The generic fallback (§6.4) covers this, and both legacy `toArray()` payloads do carry a `data.message` string (`CriticalBatchExpiryNotification.php:47-49`, `EnrichmentCompletedNotification.php:45`) and no `company_id`/`deep_link` — so the fallback and the "name company only when `data.company_id` is set" path (§6.1) render them safely. Just make §10 include one seeded legacy-shape row in the panel-render test so the fallback is actually exercised, not assumed.

#### 9. [LOW] §6.1/§6.4 — `tenantScopedKey` behavior confirmed; the userId addition is correct and necessary
Verified `tenantScopedKey` (`apps/web/src/lib/tenantScopedKey.ts:29-37`) appends only `tenant_id` + `currentCompanyId`. On shared-localhost auth, two users on the same tenant+company would otherwise collide, so §6.4's `['notifications', userId, …]` is right. Minor: the appended `companyId` will re-key the company-agnostic inbox on company switch (harmless refetch). No change required.

#### 10. [LOW] §9 — permission wiring is correct; confirm the deploy note
Verified `treasury.transfer` is NOT yet seeded (`RolesAndPermissionsSeeder.php` has only `treasury.view/manage/adjust` at `:222-224`, `:474`, `:694`); §9 correctly requires adding it to both the base list and the `treasury.adjust` bundles, plus `permission:cache-reset` (§14). Note for completeness that the notifications routes correctly carry **no** `can:` gate (ownership-only), matching the treasury group's `['api','auth:sanctum',SetPermissionsTeam::class,EnforceTokenTenantClaim::class]` stack verified at `Treasury/Presentation/routes.php:31` — the spec's §6.2 middleware assumption holds, and EnforceTokenTenantClaim IS applied at group level as §13 assumes.

### Positives verified (no defect)
- Tenant-DB routing for the `notifications` table is sound: `forEachTenant` initializes tenancy → default connection is the tenant DB (`TenantScopedCommand.php:161-163`), so `Notification::send` via `DatabaseChannel` writes to the tenant DB in console context, and per-request DB swap does the same for the HTTP read endpoints. The auth user on these routes is `Identity\Domain\User` (`config/auth.php:4,76`, `AUTH_MODEL` default), which lives in the tenant DB and has `Notifiable` — so `$user->notifications()` hits the tenant table. `app/Models/User.php` is vestigial for this flow.
- Central-DB 42P01 risk is low: the notifications routes use `auth:sanctum` (users provider), not the `super_admins` guard (`config/auth.php:52,80`); a super admin only reaches them via impersonation, which establishes a tenant token/context. Worth one explicit note in §14 that super-admin/central identities have no tenant notifications, but not a blocker.
- 60s unread-count polling per session = one indexed count on the tenant `notifications` table per user/min — acceptable load.

**VERDICT: CHANGES-REQUIRED**

**What to fix before merge:** rewrite §6.3 recipient resolution to mirror `DailyExpiryCheck.php:165-174` exactly — team = `tenant_id` (not company), add the active-company-membership filter, and `forgetCachedPermissions()` per tenant iteration — plus UUID-guard the `{id}` read route and add the missing `NotificationServiceProvider` registration; then correct the TopBar path.
