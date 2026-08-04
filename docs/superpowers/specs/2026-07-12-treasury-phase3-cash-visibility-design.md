# Treasury Phase ③ — Cash-Visibility Read Layer (design spec, Rev 2)

> **Date:** 2026-07-12 · **Status:** Rev 2 — adversarially reviewed (3 lanes, all findings reconciled — see §15 and `docs/superpowers/specs/reviews/2026-07-12-treasury-phase3-adversarial-review.md`)
> **Mandate:** `docs/handoff/HANDOFF-treasury-phase3-spec-kickoff-2026-07-12.md`
> **Owner scope decisions (2026-07-12, recorded verbatim):** alerts → **build the platform notification center**; cash widget → **both dashboards**; transfer UI → **RepositoryListPage only**; cash-position snapshots → **deferred to Phase ⑤**.
> **Predecessors:** Phase ① spine + Phase ② instruments both SHIPPED on origin/dev (`18ad9e67c`, follow-ups merged at `3b4f11434`). Base branch: current origin/dev tip.

## 1. Goal

Answer the owner's first treasury question — "how much cash is in each shop / bank right now, and where did it go?" — and make the money movable between repositories. Four deliverables:

1. **G13** — inter-repository cash transfer: HTTP endpoint + GL + FE modal (drawer→bank "remise d'espèces", drawer→safe, safe→bank…). First production consumer of `TreasuryMovementService::transfer()`.
2. **Notification center** — platform-level in-app notifications (Laravel database channel) + wiring the treasury maturity/drift alerts into it. Discharges Phase ②'s explicit deferral of alert delivery.
3. **G12** — FE page for `GET /reports/cash-movements` (the unified in/out view, E9) + two additive endpoint improvements (direction filter, range totals).
4. **C9** — cash-position widget on both dashboards, with 7-day in/out flows.

## 2. Scope

**In:**
- `POST /api/v1/payment-repositories/transfers` + `RepositoryTransferService` + draft-JE creation for cross-GL-account transfers + new permission `treasury.transfer`.
- Transfer modal on `RepositoryListPage` (from/to/amount/notes), gated `treasury.transfer`.
- `notifications` table (tenant DB, Laravel-standard, uuid morphs) + slim `Notification` module: `GET /notifications`, `GET /notifications/unread-count`, `POST /notifications/{id}/read`, `POST /notifications/read-all`.
- `TreasuryAlertNotification` (database channel) sent by `treasury:reconcile` (drift + portfolio-drift) and `treasury:instrument-maturity-alerts` to users holding `treasury.manage` in the affected company.
- TopBar bell wiring: unread-count polling + dropdown panel (list, mark-read, mark-all-read, deep links). Replaces the current hardcoded fake red dot.
- Cash-movements report page at `/finance/cash-movements` (`reports.view`) with date-range + repository + direction filters, pagination, range totals row.
- `CashMovementsReportService`: additive `direction` filter param + `totals {in, out, net}` in `meta` (bcmath).
- `CashPositionController`: opt-in `?flows_window=<days>` → `flows {window_days, in, out}` (bcmath sums over `repository_movements`).
- Shared `CashPositionWidget` on `OwnerDashboardPage` + generic `Dashboard` (gated `treasury.view` + Treasury module enablement).

**Out (explicit):**
- Cash-position history/snapshots (owner: defer to Phase ⑤).
- Full-page notification center UI, Reverb/websocket real-time delivery, mail channel for alerts (polling + database channel only; mail = decision point D-4).
- Transfer header table / dedicated transfer detail page (derive from movement legs; `RepositoryMovementsTab`'s copy-id chip is the existing precedent for `transfer` rows).
- Bordereau de remise d'espèces as a document entity (denomination breakdown etc.) — D-3.
- `RepositoryDetailPage.tsx` / `ExpenseDetailPage.tsx` (owned by `feat/treasury-ui-gaps`) and `banks`/`BankPicker` (owned by `feat/bank-reference-verification`).
- Expense depth (Phase ④), bank statement import (Phase ⑤).
- Editing/backfilling the dead `last_reconciled_at`/`last_reconciled_balance` columns.

## 3. Research synthesis (what the standard is)

- **FR PCG:** account 58 "virements internes" is a transit account for moves between own treasury accounts; **optional, recommended only when the two legs are recorded at different times or in separate journals** (compta-online, pcg.fr). Direct `Dr 512 / Cr 53` is valid when both legs are recorded together. 58 must be zero at close.
- **TN PCE:** 54 = caisse, 532 = banques, 58 = virements internes exists in the national chart — **but our seeded TN chart has no 58 account** (verified: `TunisiaChartOfAccountsSeeder.php:205-217`, no `virement` hit). Our seeded charts: FR has `58` (untagged, no system purpose); both have Cash (53) and Bank (512) system purposes.
- **Odoo:** internal transfers = two entries bridged by a liquidity-transfer clearing account — the 58 pattern, needed because Odoo's legs are two separate reconcilable transactions. Ours are one atomic transaction.
- **Dashboards (Agicap/Pennylane):** consolidated cash position + per-account balances is the standard landing widget; Pennylane shows one consolidated solde with drill-down beneath.
- **Bordereau de remise d'espèces:** deposit slip with date, depositor, account, denomination breakdown, total. Phase ③ records the transfer + notes; the printable slip artifact is deferred (D-3).

## 4. Approaches considered

**G13 GL shape:**
- **(a) CHOSEN — direct posting, no transit account.** Cross-GL transfer posts one JE `Dr destination.gl_account / Cr source.gl_account`. Same-GL transfer (drawer↔drawer, drawer↔safe under the two-bucket mapping) posts **no JE** — `transfer()` mandates null JE for same-account and reconcile check #2 already exempts same-GL transfer legs (`ReconcileTreasuryCommand::isSameGlAccountTransfer`). Rationale: the two legs commit atomically in one DB transaction — there is no in-transit state to represent; FR practitioner guidance says direct is valid exactly in this case; TN chart lacks 58 entirely.
- (b) Rejected — 58-transit double-JE (Dr 58/Cr 53 then Dr 512/Cr 58): models a timing gap we don't have, requires seeding + purpose-tagging 58 in both charts, and doubles the JE surface for zero information gain. Revisit at Phase ⑤ if bank-statement import wants an in-transit account for deposits recorded before the statement confirms them (noted in D-2).
- (c) Rejected — transfer header table (`repository_transfers`): the paired legs sharing `transfer_group_id` + notes already fully describe the transfer; a header adds a second source of truth the reconcile command would then need to cross-check.

**Notification center:**
- **(a) CHOSEN — Laravel-native database notifications + slim module.** Standard `notifications` table, `Notifiable` already on both User models, notification classes already exist in 3 modules (two of which — `CriticalBatchExpiryNotification`, `EnrichmentCompletedNotification` — already declare a `database` channel that silently no-ops today for lack of the table; the table makes them **compatible** — their own delivery/tenant-routing correctness is out of scope, L3-7). Minimal new code: one migration + one thin Presentation layer.
- (b) Rejected — custom domain `app_notifications` table: more control over shape, but reimplements what the framework gives us and orphans the two existing database-channel notifications.
- (c) Rejected — audit_events + per-user read-tracking: audit_events is an immutable compliance log, not an inbox; bolting read-state onto it conflates concerns.

**Transfer idempotency:**
- **CHOSEN — optional client-supplied `transfer_group_id` (uuid).** If provided, it becomes the port's idempotency group (double-submit → idempotent replay); if omitted, server generates (adjustment-endpoint precedent). Stronger than the adjustment precedent because a duplicated transfer is two "valid-looking" movements, not an obviously-wrong duplicate adjustment.

## 5. G13 — Inter-repository transfer

### 5.1 API

`POST /api/v1/payment-repositories/transfers` — route in `app/Modules/Treasury/Presentation/routes.php`, middleware `can:treasury.transfer`, name `payment-repositories.transfers.store`.

Request (`TransferRepositoryRequest`):
| field | rules |
|---|---|
| `from_repository_id` | required, uuid |
| `to_repository_id` | required, uuid, different:from_repository_id |
| `amount` | required, string, numeric, gt:0, regex `^\d+(\.\d{1,3})?$` (rule 19: money ceiling, no sign — a transfer has no direction ambiguity). The service additionally **normalizes once at the boundary** via `CurrencyScale::bcformatStrict($amount, $resolver->getScale($fromRepo->currency))` before building the intent, so a scale-2 currency never stores sub-scale precision (L1-9). |
| `notes` | nullable, string, max 1000 |
| `transfer_group_id` | nullable, uuid (client idempotency key) |

**Repository eligibility (both sides):** type ∈ {`cash_register`, `safe`, `bank_account`} — `RepositoryType::Virtual` is **excluded** (422): virtual buckets are deliberately invisible to the cash-position surface this same phase ships, so "cash" must not be transferable into them (L1-4).

No `occurred_at` (always now — backdating a cash transfer is a books-integrity hazard; D-5). No `currency` (derived from the source repository; the port enforces equality with both repos).

Success 201:
```json
{ "message": "...", "data": {
    "transfer_group_id": "...",
    "journal_entry_id": "... | null",
    "out": { "movement_id": "...", "balance_after": "...", "repository_id": "..." },
    "in":  { "movement_id": "...", "balance_after": "...", "repository_id": "..." },
    "idempotent_replay": false } }
```

**`journal_entry_id` is always resolved from the persisted out-leg row** (`repository_movements.journal_entry_id` by `MovementResult->movementId`), never from the JE the service created this attempt — on idempotent replay the fresh draft is deleted and the legs reference the ORIGINAL entry (L1-3). Same-GL transfers return null.

Failures — **canonical envelope only** (`DomainException` → global handler `{error:{code:'BUSINESS_ERROR',message}}` 422; no flat-string 422s in new code, unlike the two legacy shapes the ui-gaps brief has to defend against):
- unknown/other-company repo → 404; same repo twice → FormRequest 422; currency mismatch → 422 (port `CurrencyMismatchException` mapped); frozen repository → 422 — **enforced by `RepositoryTransferService`, NOT the port: `transfer()` has no freeze check at all** (verified — `TransferIntent` has no `allowWhileFrozen`, both legs hardcode `recordedWhileFrozen: false`). The service checks `frozen_at` on BOTH repos and throws `RepositoryFrozenException` (extends `DomainException` → 422) naming the frozen repo. Known TOCTOU: the pre-check reads unlocked and `treasury:reconcile` (nightly, 02:15) could freeze between the check and the port's row locks — window accepted as negligible and documented here (L1-2); missing `gl_account_id` on either repo when cross-GL → 422 before any write (adjustment-endpoint precedent); virtual-type repo on either side → 422 (see eligibility above); insufficient balance → **allowed** (see 5.4); inactive repository (`is_active=false`) on either side → 422.

### 5.2 Service flow (`RepositoryTransferService`, Treasury Application layer)

Constructor-injected: `GeneralLedgerService`, `TreasuryMovementService` (rule 13). Flow:

1. Resolve + scope both repositories (tenant+company, `findOrFail`). Validate: active, non-virtual type, **not frozen** (both sides — `RepositoryFrozenException`; the port does not check, see §5.1), amount normalized at currency scale. Determine `crossGl = from.gl_account_id !== to.gl_account_id`. If cross-GL and either `gl_account_id` is null → `DomainException` before any write.
2. `transferGroupId` = request value or `Str::uuid()`.
3. `DB::transaction` — **one uniform path for first call, client replay, and concurrent race** (no replay pre-check: `transfer()` rejects a null `journalEntryId` for cross-GL pairs *before* its replay detection, so the JE must always be supplied):
   a. If `crossGl`: create a **draft** (unposted) JE via a new `GeneralLedgerService::createRepositoryTransferJournalEntry(...)` — `Dr to.gl_account_id / Cr from.gl_account_id`, amount, currency from repo, `source_type='treasury_transfer'`, `source_id=$transferGroupId`, description `"Transfert {from.code} → {to.code}"` + notes. **Draft, not posted** — `transfer()` itself posts it via `postEntryNow` inside its own lock scope (this is the key difference from the adjustment exemplar, whose GL method posts immediately).
   b. Call `TreasuryMovementService::transfer(new TransferIntent(..., journalEntryId: $crossGl ? $entry->id : null, transferGroupId: $transferGroupId, createdBy: $user->id, notes: ...))`. `transfer()` opens its own `DB::transaction` — nests as a savepoint; the outer transaction exists so a `transfer()` failure rolls back the draft JE (no orphan drafts).
   c. **Replay/race resolution:** on a replayed `transferGroupId`, `transfer()` posts our fresh draft inside its savepoint; the FIRST unique violation to fire is the legs' idempotency-key violation *or* the status-scoped JE index (§5.3) at the status-flip UPDATE inside `postEntryNow` — **both are `QueryException` 23505 inside the savepoint**, caught by `transfer()`'s `isUniqueViolation` path → `handleTransferIdempotentHit` returns the ORIGINAL legs (leg validation deliberately excludes `journal_entry_id`). The savepoint rollback reverts the fresh draft to Draft (the row itself, created before the savepoint, survives); the transient posting has no durable effect (row updates roll back; `afterCommit` hooks on a rolled-back level never fire; the hash-chain sequence allocation rolls back and `entry_number` is `max()+1`-derived so no permanent gap).
   d. **Draft cleanup (uniform compensating check, covers L1-3 + L1-5):** after `transfer()` returns, when a draft was created in (a), fetch the out-leg row by `movementId`; if its stored `journal_entry_id` differs from the draft's id (idempotent replay → references the original entry; or the rare `gl_account_id`-reassignment TOCTOU where the port's locked read decided same-GL and wrote null), **delete the draft JE in the same outer transaction**. No orphan drafts on ANY path: port failure → outer rollback removes it; replay/divergence → explicit delete.
4. Return DTO for the controller (`idempotent_replay` from `wasIdempotentHit`; `journal_entry_id` from the out-leg row per §5.1).

**Lock order preserved (corrected per L1-6):** draft-JE creation is NOT lock-free — `generateEntryNumber` takes the **same per-company GL advisory lock** (`pg_advisory_xact_lock(hashtextextended(companyId,0))`, `GeneralLedgerService.php:3867-3869`) at draft-creation time. That lock is re-entrant in-session and identical to the one `transfer()` acquires first, so the effective order is: GL advisory (held from draft creation to outer commit, serializing all GL posting for the company — same as the adjustment flow) → both repos id-sorted. No instrument or document locks in this flow, so the global order instrument→documents→GL→repository holds.

**Amount handling:** canonical decimal string end-to-end; scale via `CurrencyScaleResolverInterface` with the repository currency passed explicitly (rule 19); no float ever.

### 5.3 GL & chart

- No new accounts seeded. Cross-GL uses the repositories' own `gl_account_id`s (two-bucket today: Cash 53 / Bank 512).
- New JE `source_type='treasury_transfer'`: partial unique index on `journal_entries(source_type, source_id)` — **scoped `WHERE source_type='treasury_transfer' AND status='posted'`** (L1-1, BLOCKER fix). An unscoped index (procurement-convention shape) would 500 every replay at the draft INSERT, before `transfer()`'s unique-violation catch can ever run, structurally breaking the §4 idempotency design. Status-scoping moves the violation to the status-flip UPDATE inside `postEntryNow` — inside the savepoint, catchable, replay-safe. **Trade-off accepted:** "one JE per transfer group" is enforced on POSTED entries only; transient drafts are cleaned by §5.2.3d.
- FEC/journal code: `JournalCode::fromSourceType('treasury_transfer')` falls through to `Misc`/OD **with zero code changes** — same as `repository_adjustment`. Do NOT add a match arm without D-1's expert-comptable sign-off. Descriptive label carries both repo codes.
- **Operational caveat (L1-8):** reconcile check #2's same-GL exemption evaluates the repos' CURRENT `gl_account_id`. Phase ③ mints the first production null-JE same-GL legs; a later admin reassignment of one repo's GL account flips that history to non-exempt → both repos freeze at the next nightly run. Recorded as a known property; a guard on `gl_account_id` edits for repos with same-GL transfer history is a registered follow-up (§13), not Phase-③ scope.
- **Known non-goal (L1-7):** a cross-GL replay arriving after the fiscal period containing the original transfer closes gets `ClosedFiscalPeriodException` (422) instead of the idempotent result — `sealAndPersistEntry` checks the closed period before any unique violation can fire. Window is near-nil (transfers are never backdated, D-5).

### 5.4 Deliberate non-guards

- **No insufficient-balance guard.** The spine allows negative repository balances everywhere else (adjustments, expenses); a cashier recording the physical reality of "money already moved" must not be blocked by a stale book balance. Reconcile checks remain the drift detector. FE shows current balance next to the source-repo select as a soft signal. (D-6 if the owner wants a hard floor.)
- **No approval workflow.** Single-step, permission-gated (`treasury.transfer`), same trust level as `treasury.adjust`.

### 5.5 FE

- `TransferCashModal` (`features/treasury/components/`), opened from a `Transfer cash` button in `RepositoryListPage`'s `PageHeader` actions, gated `usePermissions().hasPermission('treasury.transfer')`. **Note (L2-8):** the page currently passes a single action node — compose multiple actions (fragment); the existing add button stays gated `repositories.manage`, the transfer button gates independently. Route reachability holds: the page route is `moduleKey="treasury"` (→ `treasury.view`), which every `treasury.transfer` holder's role bundle also carries.
- **FE permission registration (L2-2, HIGH):** `treasury.transfer` MUST be added to the `PERMISSIONS` map in `usePermissions.ts` (same role list as the `treasury.adjust`/manage bundle) — `Permission` is `keyof typeof PERMISSIONS`, so without this the gate does not typecheck and `hasPermission` returns false for any token predating the reseed. Check whether it belongs in `SERVER_AUTHORITATIVE_PERMISSIONS` alongside its peers at implementation time.
- RHF + zod (exemplar: `features/vouchers/components/TransferVoucherModal.tsx` — closest zodResolver + Modal + FormField pattern; note it's a *loose* match — PartnerPicker instead of Select — copy the form mechanics, not the fields). Fields: from-repo `Select` (active, non-virtual repos, shows current balance + currency per option), to-repo `Select` (active non-virtual repos minus the selected source, client-filtered to same currency), `MoneyInput` (`@/components/atoms/MoneyInput` — the shared atom, NOT `features/pos/atoms/MoneyInput`; currency from the selected source repo), notes `Textarea` (max 1000).
- Client generates `transfer_group_id` (`crypto.randomUUID()`) once per modal-open; submit button disabled while pending → double-click and retry-after-timeout are both idempotent server-side.
- `useTransferCash` mutation hook: `api.post`; on success invalidate `tenantScopedKey(['payment-repositories'])` (list), `tenantScopedKey(['payment-repository', fromId])`, `(['payment-repository', toId])`, `(['payment-repository-transactions', fromId])`, `([...toId])`, `tenantScopedKey(['treasury-cash-position'])`, and — **raw prefixes, deliberately NOT tenantScopedKey (L2-3, HIGH)** — `['repository-movements', fromId]` + `['repository-movements', toId]`: the movements query key is `tenantScopedKey(['repository-movements', id, filters])`, so the filters object sits between the id and the tenant/company suffix and defeats any fixed-position scoped prefix; a raw two-element prefix matches all filter variants (`audit-tanstack-keys.mjs` gates `useQuery` keys only, not invalidations). Error surface: `getErrorMessage()` (canonical envelope only — this endpoint never emits the flat-string shape).
- i18n: `treasury:repositories.transfer.*` in `en` + `fr` (+ `ar` where the file exists — treasury.json has all three locales).
- Movements rendering: `RepositoryMovementsTab` already lists `transfer` rows with the copy-id chip; unchanged (matches the `adjustment` precedent — no detail route).

## 6. Notification center

### 6.1 Data model

Laravel-standard `notifications` table, **tenant** migration: `id uuid PK`, `type string`, `notifiable_type/notifiable_id` (uuid morphs, index), `data jsonb`, `read_at timestampTz nullable`, timestamps. Standard framework shape — the `DatabaseChannel` and `$user->unreadNotifications()` work unmodified. No `company_id` column: company scoping lives **inside `data`** (`data.company_id`), because a notification belongs to a user; the FE panel shows all of the user's notifications for the active tenant DB regardless of active company, and each entry names its company when `data.company_id` is set (D-7).

### 6.2 Backend module

New slim module `app/Modules/Notification/` (Presentation only — the domain is the framework's). **Module registration is a first-class deliverable (L3-5):** `NotificationServiceProvider` with `loadRoutesFrom` (exemplar: `Cart/Providers/CartServiceProvider.php:16-19`) **and** an entry in `bootstrap/providers.php` — without both, every route 404s.
- `GET /api/v1/notifications` — paginated `{data, meta}` (page/per_page, `filter=unread|all`), ordered `created_at desc`, scoped to `$request->user()`.
- `GET /api/v1/notifications/unread-count` — `{data:{count}}`.
- `POST /api/v1/notifications/{id}/read` — **route param constrained `->whereUuid('id')`** (L3-4: an unguarded non-uuid id 500s on the uuid PK — the repo's documented PG pitfall); 404 if not the caller's; idempotent.
- `POST /api/v1/notifications/read-all`.
- Middleware `['api','auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` (rule 12). **No extra permission** — a user reads only their own rows; authorization is ownership.
- Resource shape: `{id, type, data, read_at, created_at}` where `type` is a stable **string alias** (`treasury.reconcile.drift`, `treasury.portfolio_drift`, `treasury.instrument.maturity`, …) set via `databaseType()`/broadcast-safe alias, not the PHP FQCN — FE must never switch on a PHP class path.

### 6.3 Treasury alert senders

- `TreasuryAlertNotification` (Treasury module, `implements ShouldQueue` **NO** — sent synchronously inside the console commands to keep rule-20 surface minimal; sending N database rows is cheap). `via() = ['database']`. Payload: `{alert_type, company_id, company_name, severity, headline params (repository code / counts / window), deep_link}`. Deep links: drift → `/treasury/repositories/{id}`, portfolio drift → `/finance/overview`, maturity → `/treasury/instruments?maturing=1`.
- `ReconcileTreasuryCommand::freezeAndAlert()` + `alertPortfolioDrift()` and `InstrumentMaturityAlertsCommand`: after the existing audit_events write, resolve recipients and `Notification::send($users, new TreasuryAlertNotification(...))`, each wrapped in its own try/catch exactly like the existing alert channels (a notification failure must never abort the run or suppress the freeze — extends the existing `treasury.reconcile.alert_failed` pattern).
- **Recipient resolution — mirror `DailyExpiryCheck.php:165-174` EXACTLY (L3-1 BLOCKER, L3-2 BLOCKER, L3-3 HIGH):**
  > **2026-08-04 addendum — exemplar moved.** `BatchExpiry/Jobs/DailyExpiryCheck.php` was
  > deleted when the job was converted to a `TenantScopedCommand`. The live exemplar for
  > this query shape is now
  > `apps/api/app/Modules/BatchExpiry/Infrastructure/Commands/BatchExpiryDailyCheckCommand.php`
  > → `notifyCompaniesOfCriticalBatches()`. Note the mirror is no longer partial: that
  > block now ALSO calls `forgetCachedPermissions()` (point 3 below) and constructor-injects
  > `PermissionRegistrar` instead of using `app()`. Text below kept as written.
  1. **Team = TENANT id, never company id:** `config/permission.php:99` sets `team_foreign_key = 'tenant_id'` — the Spatie team IS the tenant. `setPermissionsTeamId($tenant->id)` before querying, restore in a `finally`. (Setting the company id — Rev 1's wording — matches zero pivot rows: alerts silently delivered to NOBODY.)
  2. **Company-membership filter is mandatory:** permission scoping is tenant-wide, so `->permission('treasury.manage')` alone returns every holder across ALL companies of the tenant — a cross-company disclosure (payload carries company name, repo code, drift reason, balances). Query shape: `User::where('tenant_id', $tenant->id)->whereHas('companyMemberships', fn($q) => $q->where('company_id', $company->id)->where('status','active'))->permission('treasury.manage')->get()`. This filter is also the invariant that makes D-7's company-less inbox leak-free: every stored notification belongs to a company its recipient is a member of.
  3. **Flush the Spatie registrar per tenant iteration:** `app(PermissionRegistrar::class)->forgetCachedPermissions()` right after `setPermissionsTeamId`, before any permission query — `forEachTenant` swaps only the DB connection in one process, and the registrar memoizes permission UUIDs from the FIRST tenant (the known tenant-blind cache bug, memory `project_spatie_permission_cache_tenant_blind`); without the flush every tenant after the first resolves recipients with the wrong permission UUID.
  Maturity alerts already produce ONE audit event per company per day — mirror that granularity: one notification per user per company per run (no per-instrument spam).
- audit_events writes are **unchanged** — the notification is an additional channel, never a replacement (compliance log stays authoritative).

### 6.4 FE

- `useNotifications` feature (`features/notifications/`): `useUnreadCount` (poll `refetchInterval: 60_000`), `useNotificationsList` (fetched on panel open), `useMarkRead`/`useMarkAllRead` mutations. Query keys: `tenantScopedKey(['notifications', userId, ...])` — userId in the key because localStorage/auth is shared on localhost and tenantScopedKey only appends tenant+company.
- TopBar bell — **`components/organisms/TopBar/TopBar.tsx:154-161`** (the editable component; `components/layout/TopBar.tsx` is a 2-line re-export — L3-6): replace the static button — badge renders **only when count > 0** (kills the hardcoded fake dot), opens a dropdown panel: last ~15 notifications, unread highlighted, click → mark read + navigate `deep_link`, mark-all-read action, empty state. Rendered for every authenticated user (no permission gate — the inbox is universal; senders decide targeting).
- Rendering by `type` with a **generic fallback** (type string + best-effort `data.message`). The two pre-existing database-channel notification types (BatchExpiry, Enrichment) store their PHP FQCN as `type` (no `databaseType()` alias) and carry `data.message` but no `company_id`/`deep_link` — the table's existence makes them **compatible** (not "fixed": their own tenant-routing questions are theirs — L3-7); the panel test must seed one legacy-FQCN-shaped row to prove the fallback renders it (L3-8).
- i18n: new `notifications` namespace (en+fr+ar), registered in `i18n.ts` (3 touch points: import, resources, ns array).

## 7. G12 — Cash-movements report page

### 7.1 Backend (additive only)

`CashMovementsReportService` + `GetCashMovementsRequest`:
- New optional `direction` param (`in|out`) — applied as a WHERE on the wrapped UNION subquery (feasible: `direction` is a selected union column and `$base` is a `fromSub`).
- New `totals` block in `meta`, **grouped by currency** (L2-1 HIGH — report rows carry per-row currency: the payments leg selects `payments.currency`, the journal leg the company currency; a single summed `net` would silently add EUR to TND, the C8 gap):
  ```json
  "totals": { "TND": {"in": "...", "out": "...", "net": "..."}, "EUR": {...} }
  ```
  Computed over the **entire filtered range** (not the page) with **one SQL aggregate**: `SELECT currency, direction, SUM(CAST(amount AS NUMERIC)) … GROUP BY currency, direction` on the wrapped subquery (PG exact numeric — NOT PHP bcmath over an unbounded row fetch, L2-5), each result then formatted once via `CurrencyScale::bcformatStrict` at that currency's scale.
- Response stays `{data, meta}`; existing consumers unaffected (there are none).

### 7.2 FE

- `CashMovementsReportPage` (`features/finance/pages/`), route `/finance/cash-movements`, `RequirePermission permission="reports.view"`; nav entry in the `finance` sidebar group (`key: 'cashMovements'`, icon `ArrowLeftRight`, permission `'reports'`), beside the existing `treasuryOverview` entry. Cross-link: `TreasuryOverviewPage` "view movements" link; widget (§8) links here too.
- Pattern = `RepositoryMovementsTab` exemplar: `api.get` + `response.data` (`{data, meta}` — **never `apiGet`**, it drops `meta`), hand-rolled table (per the in-code warning that `DataTable` lacks pagination), `OffsetPagination`, `DateRangeFilter`, repository `Select` (from `usePaymentRepositories`), direction `Select`.
- Totals row above the table: In / Out / Net for the filtered range (from `meta.totals`), **rendered per currency** — one row per currency key when more than one is present (L2-1); `formatCurrency` (from `@/lib/format` — the Intl one, not `lib/decimal`'s), color via design tokens.
- Gating-axes note (L2-6, intentional): the report lives on the **Accounting/reports axis** (route `reports.view`, nav in the Accounting-gated group beside `treasuryOverview`) while the §8 widget lives on the **Treasury axis** — a treasury-only user reaches the report via the widget's deep link even when the nav entry is hidden. Documented as intended.
- Columns: date, direction (badge), amount (signed color), source type, counterparty, GL account, source ref (copy chip; link where `sourceDocHref`-style mapping applies to payments).
- Default range: current month. `per_page` 50 (server default).
- No export in Phase ③ (follow-up: wire the orphan `ExportButton` or a server CSV — noted §13).

## 8. C9 — Cash-position widget

### 8.1 Backend (additive)

`CashPositionController::index`: optional `?flows_window=<1..90>` → response gains
```json
"flows": { "window_days": 7, "in": "...", "out": "..." }
```
computed as SQL SUMs over `repository_movements` joined to the same included repositories (`cash_register|bank_account|safe`, active), **restricted to repos whose `currency = company currency`** (L2-4 — makes the endpoint's existing single-currency assumption explicit instead of silently widening it; `grand_total` already scales every balance at company currency with no guard), windowed on **`occurred_at`** (the indexed business-date column, populated non-null for every source type — L2-7), grouped by direction; results formatted once via `bcformatStrict`. Absent when the param is absent (backward-compatible; `useCashPosition`'s existing consumer unchanged).
**Dual-source note (L2-7):** flows derive from the movements ledger while `grand_total` derives from `payment_repositories.balance` — compatible with the controller's "never recompute a POSITION from movements" docblock (flows are not a position), but on a drift-frozen repo the two can visibly disagree in the same widget; acceptable, the drift alert is the explanation.

### 8.2 FE

- `CashPositionWidget` (`features/treasury/components/`): grand total (big figure + currency), per-type row (registers / banks / safes with counts), 7-day In / Out, links to `/finance/overview` and `/finance/cash-movements`. Data: `useCashPosition` extended with an options arg (`{flowsWindow?: number}`) folded into the query key.
- Rendered on **both** dashboards:
  - `OwnerDashboardPage`: a widget-grid cell, visually consistent with its `OwnerTableFrame`-based siblings.
  - Generic `Dashboard`: a card in the recent-activity grid, gated `usePermissions().canAccessModule('treasury')` **and** company module enablement (mirror Sidebar's dual gating: permission axis + `hasModule('Treasury')`) — renders nothing when either gate fails.
  - One component, both hosts; tokens per rule 18 (note `Dashboard.tsx` uses `semanticColorTokens` while treasury uses `tokens`/`textColors` — the widget uses the treasury convention internally; acceptable coexistence, both are sanctioned token sets).
- No alerts count on the widget: alerts surface through the bell (owner chose the notification center). Keeps the widget purely positional.

## 9. Permissions

| permission | purpose | roles |
|---|---|---|
| `treasury.transfer` (NEW) | POST transfers | same bundles that hold `treasury.adjust` (RolesAndPermissionsSeeder; also add to PermissionSeeder base list, which is missing several treasury permissions already — add `treasury.transfer` to BOTH so the drift doesn't widen). **Plus the FE `PERMISSIONS` map in `usePermissions.ts`** (L2-2 — `Permission` is `keyof typeof PERMISSIONS`; without it the gate fails typecheck and the button never renders); check `SERVER_AUTHORITATIVE_PERMISSIONS` membership at implementation time |
| `treasury.view` | widget gate (existing) | unchanged |
| `reports.view` | movements report page (existing) | unchanged |
| — (none) | notifications API (ownership-scoped) | n/a |

Deploy owes: perm reseed + `permission:cache-reset` (tenant-blind cache — memory `project_spatie_permission_cache_tenant_blind`).

## 10. Testing & verification

- **Transfer endpoint (PHPUnit Feature):** cross-GL posts exactly one JE (Dr to / Cr from, posted status, correct amounts at currency scale); same-GL posts zero JEs; legs net to zero and both carry `transfer_group_id`; **REQUIRED dedicated replay tests (L1-1): sequential replay via client `transfer_group_id`** (second call → `idempotent_replay: true`, no second posted JE, no orphan draft, `journal_entry_id` = the ORIGINAL entry's id per §5.1) **and a race-shape test** (pre-existing posted JE + legs for group G, then a second full request with `transfer_group_id=G` → idempotent replay, single posted JE) — the whole design hinges on the 23505 surfacing inside the savepoint, so these pins are non-negotiable. *(Amendment A-1, plan review 2026-07-12: "concurrent-race" relaxed to this sequential race-shape — the Fable lane verified it exercises the IDENTICAL 23505-inside-savepoint path as a two-connection race, and no two-connection harness exists in the port suite; the substitution is recorded as a deviation in the progress file at implementation.)*; draft-JE rollback on port failure — **vehicle: destination-repo currency mismatch** (`CurrencyMismatchException` fires inside the port AFTER the draft exists — L1-2c; "frozen target" is NOT a port failure, the freeze check is service-level) → no orphan draft JE; frozen source AND frozen destination 422 (service-level check); virtual-type repo 422 both directions; currency mismatch 422; inactive 422; missing GL account 422 (cross-GL only); sub-scale amount normalized (scale-2 currency, `10.005` → stored at scale — L1-9); permission 403; cross-company repo 404; **in-test `treasury:reconcile` run stays green after a mix of same-GL and cross-GL transfers** (checks #1–#3, Phase-② Task 8/9 pattern).
- **Notifications (PHPUnit):** endpoints scope to caller (404 on foreign id; 404 on malformed non-uuid id — L3-4); unread-count; mark-read idempotent; **recipient resolution (L3-1/2/3): two companies with disjoint managers — company-A manager does NOT receive company-B's alert (assert the deny direction, not just counts); ≥2-tenant command run — tenant B's managers actually receive theirs (proves the per-tenant registrar flush)**; a notification-send failure does not abort the reconcile run nor suppress the freeze (extend the existing alert_failed tests).
- **Report additions:** direction filter; **totals grouped per currency under a mixed-currency dataset** (EUR payment rows + TND journal rows — L2-1) and matching exact sums under mixed payment-side/journal-side rows; pagination meta intact.
- **Cash-position flows:** window sums correct across repos; company-currency restriction honored (foreign-currency repo movements excluded — L2-4); absent without the param.
- **FE (Vitest):** TransferCashModal (validation, currency filtering, idempotency-key stability across re-renders, success invalidations — including the raw-prefix movements invalidation); useNotifications hooks; bell badge visibility (0 → hidden); notification panel renders a seeded legacy-FQCN-shaped row via the generic fallback (L3-8); CashMovementsReportPage filters + per-currency totals rows; CashPositionWidget gating (no permission / no module → null).
- **E2E (Playwright, demo tenant):** drawer→bank transfer end-to-end — balances move on both repos, movements rows appear on both, JE visible in GL, cash-position updates; drawer→safe shows no JE; report page shows both legs with correct totals; bell shows a seeded alert, mark-read clears badge. Screenshots to `docs/sessions/treasury-phase3-e2e/`.
- Standing gates: phpstan level 8 zero new, pint, `pnpm typecheck && pnpm lint`, `audit-tanstack-keys.mjs`, `audit-design-system.mjs` 0 new, vitest by path only.

## 11. Standing invariants (restated as binding)

- Every cash movement through the Phase-① port; JE via `postEntryNow`, never afterCommit. `transfer()`'s byte-level contract is **pinned**: this phase adds callers, never edits the port (`TreasuryMovementService.php` port methods byte-untouched; new code lives in `RepositoryTransferService` + one new GL method).
- Global lock order instrument → documents → GL advisory → repository (transfers: GL advisory → both repos id-sorted — already inside `transfer()`).
- Rule 19 everywhere; rule 20 in the console senders (explicit currency/team context, no CompanyContext assumption).
- Reconcile checks #1–#4 stay green; the E2E and in-test reconcile runs prove it.
- Fiscal perimeter untouched (no `fiscal_events`, no canonical bytes, no `pos_receipt_payments`).

## 12. Owner / expert-comptable decision points (defaults chosen; none block implementation)

| # | decision | default |
|---|---|---|
| D-1 | Journal code for transfer JEs (OD/general as with adjustments, or a dedicated treasury journal) | same journal as adjustments |
| D-2 | 58 "virements internes" transit account — not used in Phase ③ (atomic legs). Revisit for Phase ⑤ deposits-in-transit; TN chart would need 58 seeded then | direct posting, no 58 |
| D-3 | Bordereau de remise d'espèces printable slip (denominations, signatures) | deferred; notes field carries the reference |
| D-4 | Mail channel for treasury alerts (in addition to database channel) | off in Phase ③; one-line `via()` change later |
| D-5 | Backdated transfers (`occurred_at` input) | not allowed — always now |
| D-6 | Hard floor on source-repo balance (block transfer > balance) | no hard floor (matches spine-wide policy); FE shows balance as soft signal |
| D-7 | Notification company scoping: filter panel by active company vs show all-with-label | show all, label per-company entries |
| D-8 | Alert recipients: `treasury.manage` holders | yes; revisit if role granularity needed |

## 13. Open questions for the implementation plan (not design blockers)

- Exact `GeneralLedgerService::createRepositoryTransferJournalEntry` placement/signature — mirror `createRepositoryAdjustmentJournalEntry` but returning a **Draft** entry. Verified by review (L1): the adjustment method ends with `postEntryNow` (`GeneralLedgerService.php:964`) — ours must stop before it; passing an already-posted JE to `transfer()` would 500 (`InvalidArgumentException 'Only draft entries can be posted'` is not a `QueryException`).
- ~~Verify §5.2.3c empirically~~ — RESOLVED by Lane 1: savepoint-rollback semantics verified against Laravel 12's `DatabaseTransactionsManager` (deferred events discarded, hash/sequence updates rolled back, entry_number `max()+1` reused — no permanent gap); the required pins are now the §10 sequential-replay + concurrent-race tests.
- ~~Whether `EnforceTokenTenantClaim` is on the treasury group~~ — RESOLVED: yes, group-level (`Treasury/Presentation/routes.php:31`); notifications module copies the same stack.
- `usePaymentRepositories` hook shape for the modal's balance display (may need the list endpoint's balance field confirmed).
- Poll interval + panel page size final values (60s / 15 — tune at review).
- Follow-up register: export on the report page; transfer shortcut on `RepositoryDetailPage` after `feat/treasury-ui-gaps` merges; full notifications page; Reverb real-time; guard on `gl_account_id` reassignment for repos with same-GL transfer history (L1-8 caveat, §5.3).

## 14. Migration, deploy, interlocks

- Migrations: `notifications` table (tenant); partial unique index on `journal_entries(source_type,source_id) WHERE source_type='treasury_transfer' AND status='posted'` (status-scoped per L1-1). Both additive.
- Deploy owes: `tenants:migrate`; perm reseed + `permission:cache-reset` (`treasury.transfer`). No chart reseed (no new accounts). Staging auto-deploys from dev with entrypoint-automated migrate/reseed/cache-reset — covered.
- Note: super-admin/central identities have no tenant notifications (the table is tenant-DB; the routes use the tenant `auth:sanctum` users provider — impersonation flows establish tenant context) — L3 verified, by design.
- Interlocks: **do not touch** `RepositoryDetailPage.tsx`/`ExpenseDetailPage.tsx` (`feat/treasury-ui-gaps`, not started as of 2026-07-12 — re-verify at FE-wave start) or `banks`/`BankPicker` (`feat/bank-reference-verification`). `TopBar.tsx` is claimed by NO in-flight track (verified against both briefs) — the bell wiring is safe.
- REALIGNMENT-LOG: not required (no published-API/canonical-shape change; all additive internal ERP endpoints).

## 15. Adversarial-review reconciliation (2026-07-12)

Full review: `docs/superpowers/specs/reviews/2026-07-12-treasury-phase3-adversarial-review.md` — 3 lanes (L1 treasury-reviewer/Fable money path, L2 treasury-reviewer/Opus read layer, L3 tenancy-authz-reviewer/Opus notification center), all CHANGES-REQUIRED; every finding reconciled below. IDs `L<lane>-<finding>`.

| id | sev | resolution in Rev 2 |
|---|---|---|
| L1-1 | BLOCKER | Partial unique index status-scoped to `posted` (§5.3, §14); replay + concurrent-race tests made REQUIRED (§10); posted-only-uniqueness trade-off stated |
| L1-2 | HIGH | Freeze check explicitly assigned to `RepositoryTransferService`, both repos (§5.1, §5.2.1); TOCTOU window documented; §10 draft-rollback test re-vehicled onto currency mismatch |
| L1-3 | MEDIUM | `journal_entry_id` always resolved from the persisted out-leg row (§5.1); pinned in replay test |
| L1-4 | MEDIUM | `Virtual` repos excluded both sides, 422 (§5.1) |
| L1-5 | MEDIUM | Uniform compensating draft-cleanup §5.2.3d (covers replay AND the gl_account_id-reassignment TOCTOU fresh-success/null-JE case) |
| L1-6 | LOW | Lock-order paragraph corrected: `generateEntryNumber` holds the same re-entrant GL advisory lock from draft creation (§5.2) |
| L1-7 | LOW | Fiscal-period-close replay documented as known non-goal (§5.3) |
| L1-8 | LOW | `gl_account_id`-reassignment reconcile hazard documented as operational caveat (§5.3) + follow-up guard registered (§13) |
| L1-9 | LOW | Amount normalized once at the boundary via `bcformatStrict` at source-repo scale (§5.1); sub-scale test added (§10) |
| L2-1 | HIGH | Report totals grouped per currency, SQL `SUM(CAST) GROUP BY currency, direction` + `bcformatStrict` (§7.1); FE totals rendered per currency (§7.2); mixed-currency test (§10) |
| L2-2 | HIGH | `treasury.transfer` added to FE `PERMISSIONS` map deliverable (§5.5, §9) |
| L2-3 | HIGH | `repository-movements` invalidations switched to raw two-element prefixes with rationale (§5.5) |
| L2-4 | MEDIUM | Flows restricted to company-currency repos; single-currency assumption made explicit (§8.1) |
| L2-5 | MEDIUM | "bcmath in one aggregate query" contradiction resolved → PG numeric aggregate, format at boundary (§7.1) |
| L2-6 | LOW | Report=Accounting axis vs widget=Treasury axis documented as intended (§7.2) |
| L2-7 | LOW | Flows/`grand_total` dual-source note + `occurred_at` pinned as window column (§8.1) |
| L2-8 | LOW | PageHeader multi-action composition + route-reachability note (§5.5) |
| L3-1 | BLOCKER | Recipient resolution: team = TENANT id (never company), set/restore in finally, mirror `DailyExpiryCheck.php:165-174` (§6.3) — **2026-08-04: exemplar moved to `BatchExpiry/Infrastructure/Commands/BatchExpiryDailyCheckCommand.php::notifyCompaniesOfCriticalBatches()`; the old job class is deleted** |
| L3-2 | BLOCKER | Active-company-membership filter mandatory in recipient query; deny-direction test required (§6.3, §10); underwrites D-7 |
| L3-3 | HIGH | `forgetCachedPermissions()` per tenant iteration; ≥2-tenant delivery test (§6.3, §10) |
| L3-4 | MEDIUM | `->whereUuid('id')` route constraint; malformed-id 404 test (§6.2, §10) |
| L3-5 | MEDIUM | `NotificationServiceProvider` + `bootstrap/providers.php` registration made explicit deliverables (§6.2) |
| L3-6 | LOW | TopBar path corrected to `components/organisms/TopBar/TopBar.tsx` (§6.4) |
| L3-7 | LOW | Legacy database-channel notifications downgraded from "fixed" to "compatible" (§4, §6.4) |
| L3-8 | LOW | Legacy-FQCN-row panel-render test added (§6.4, §10) |
| L3-9 | LOW | No change (userId-in-key confirmed correct) |
| L3-10 | LOW | No change (permission wiring confirmed; middleware stack verified) |

Verified-accurate claims from all three lanes (savepoint semantics, reconcile-green transfer shapes, seeder drift, JournalCode fallthrough, tenant-DB routing of the notifications table, apiGet/meta, MoneyInput rule-19 cleanliness, dual-gate buildability) are retained as design ground truth without change.

**Post-plan-review amendments (2026-07-12, plan review `docs/superpowers/plans/reviews/2026-07-12-treasury-phase3-plan-adversarial-review.md`):**
- **A-1 (§10):** the required "concurrent-race" transfer test is relaxed to a sequential race-shape test (pre-posted JE + legs, second full request with the same group id) — Fable-lane verified it exercises the identical 23505-inside-savepoint path; logged as a deviation in the progress file at implementation.
- **A-2 (§5.5/§7 supporting):** `PaymentRepositoryController::formatRepository` gains a `currency` field (plan H1 — the transfer modal's currency filter and MoneyInput prop are non-buildable without it). Additive.
- **A-3 (§6.3):** the maturity notification send is gated on `received_due_count + deposited_overdue_count > 0` (plan H2 — the audit event's unconditional per-company cadence is NOT mirrored; empty daily alerts would train users to ignore the bell).
