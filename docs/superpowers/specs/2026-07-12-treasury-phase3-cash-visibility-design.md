# Treasury Phase ③ — Cash-Visibility Read Layer (design spec, Rev 1)

> **Date:** 2026-07-12 · **Status:** Rev 1 — pending adversarial review
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
- **(a) CHOSEN — Laravel-native database notifications + slim module.** Standard `notifications` table, `Notifiable` already on both User models, notification classes already exist in 3 modules (two of which — `CriticalBatchExpiryNotification`, `EnrichmentCompletedNotification` — already declare a `database` channel that silently no-ops today for lack of the table; this fixes them as a side effect). Minimal new code: one migration + one thin Presentation layer.
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
| `amount` | required, string, numeric, gt:0, regex `^\d+(\.\d{1,3})?$` (rule 19: money ceiling, no sign — a transfer has no direction ambiguity) |
| `notes` | nullable, string, max 1000 |
| `transfer_group_id` | nullable, uuid (client idempotency key) |

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

Failures — **canonical envelope only** (`DomainException` → global handler `{error:{code:'BUSINESS_ERROR',message}}` 422; no flat-string 422s in new code, unlike the two legacy shapes the ui-gaps brief has to defend against):
- unknown/other-company repo → 404; same repo twice → FormRequest 422; currency mismatch → 422 (port `CurrencyMismatchException` mapped); frozen repository → 422 (`RepositoryFrozenException` mapped, message names the frozen repo); missing `gl_account_id` on either repo when cross-GL → 422 before any write (adjustment-endpoint precedent); insufficient balance → **allowed** (see 5.4); inactive repository (`is_active=false`) on either side → 422.

### 5.2 Service flow (`RepositoryTransferService`, Treasury Application layer)

Constructor-injected: `GeneralLedgerService`, `TreasuryMovementService` (rule 13). Flow:

1. Resolve + scope both repositories (tenant+company, `findOrFail`). Validate active. Determine `crossGl = from.gl_account_id !== to.gl_account_id`. If cross-GL and either `gl_account_id` is null → `DomainException` before any write.
2. `transferGroupId` = request value or `Str::uuid()`.
3. `DB::transaction` — **one uniform path for first call, client replay, and concurrent race** (no replay pre-check: `transfer()` rejects a null `journalEntryId` for cross-GL pairs *before* its replay detection, so the JE must always be supplied):
   a. If `crossGl`: create a **draft** (unposted) JE via a new `GeneralLedgerService::createRepositoryTransferJournalEntry(...)` — `Dr to.gl_account_id / Cr from.gl_account_id`, amount, currency from repo, `source_type='treasury_transfer'`, `source_id=$transferGroupId`, description `"Transfert {from.code} → {to.code}"` + notes. **Draft, not posted** — `transfer()` itself posts it via `postEntryNow` inside its own lock scope (this is the key difference from the adjustment exemplar, whose GL method posts immediately).
   b. Call `TreasuryMovementService::transfer(new TransferIntent(..., journalEntryId: $crossGl ? $entry->id : null, transferGroupId: $transferGroupId, createdBy: $user->id, notes: ...))`. `transfer()` opens its own `DB::transaction` — nests as a savepoint; the outer transaction exists so a `transfer()` failure rolls back the draft JE (no orphan drafts).
   c. **Replay/race resolution:** on a replayed `transferGroupId`, `transfer()` posts our fresh draft inside its savepoint, hits the legs' unique violation, rolls back to the savepoint (discarding that posting — the draft row itself, created before the savepoint, survives as Draft), and returns the ORIGINAL legs as idempotent hits. When the result reports `wasIdempotentHit` and a draft was created in (a), delete the now-orphaned draft JE in the same outer transaction — the replayed legs reference the original entry. The transient posting inside a rolled-back savepoint has no durable effect (DB state rolls back; `afterCommit` hooks on a rolled-back level never fire).
4. Return DTO for the controller (`idempotent_replay` from `wasIdempotentHit`).

**Lock order preserved:** the draft-JE insert takes no advisory/row locks; `transfer()` then acquires GL advisory lock → both repos id-sorted — identical to its test-proven contract. No instrument or document locks in this flow, so the global order instrument→documents→GL→repository is trivially respected.

**Amount handling:** canonical decimal string end-to-end; scale via `CurrencyScaleResolverInterface` with the repository currency passed explicitly (rule 19); no float ever.

### 5.3 GL & chart

- No new accounts seeded. Cross-GL uses the repositories' own `gl_account_id`s (two-bucket today: Cash 53 / Bank 512).
- New JE `source_type='treasury_transfer'`: add the source-type-scoped **partial unique index** on `journal_entries(source_type, source_id)` for this type, per repo convention (`journal_entries` has no global source uniqueness — see memory `reference_journal_entries_no_global_source_uniqueness`). One JE per transfer group.
- FEC/journal code: the entry goes through the same journal the adjustment entries use (general/OD journal). Descriptive label carries both repo codes. (D-1 confirms the journal code with the expert-comptable.)

### 5.4 Deliberate non-guards

- **No insufficient-balance guard.** The spine allows negative repository balances everywhere else (adjustments, expenses); a cashier recording the physical reality of "money already moved" must not be blocked by a stale book balance. Reconcile checks remain the drift detector. FE shows current balance next to the source-repo select as a soft signal. (D-6 if the owner wants a hard floor.)
- **No approval workflow.** Single-step, permission-gated (`treasury.transfer`), same trust level as `treasury.adjust`.

### 5.5 FE

- `TransferCashModal` (`features/treasury/components/`), opened from a `Transfer cash` button in `RepositoryListPage`'s `PageHeader` actions, gated `usePermissions().hasPermission('treasury.transfer')`.
- RHF + zod (exemplar: `features/vouchers/components/TransferVoucherModal.tsx` — the closest canonical zodResolver + Modal + FormField pattern). Fields: from-repo `Select` (active repos, shows current balance + currency per option), to-repo `Select` (active repos minus the selected source, client-filtered to same currency), `MoneyInput` (`@/components/atoms/MoneyInput`, currency from the selected source repo), notes `Textarea` (max 1000).
- Client generates `transfer_group_id` (`crypto.randomUUID()`) once per modal-open; submit button disabled while pending → double-click and retry-after-timeout are both idempotent server-side.
- `useTransferCash` mutation hook: `api.post`; on success invalidate `tenantScopedKey(['payment-repositories'])` (list), `tenantScopedKey(['payment-repository', fromId])`, `(['payment-repository', toId])`, prefix `(['repository-movements', fromId])`, `(['repository-movements', toId])`, `(['payment-repository-transactions', fromId])`, `([...toId])`, and `tenantScopedKey(['treasury-cash-position'])`. Error surface: `getErrorMessage()` (canonical envelope only — this endpoint never emits the flat-string shape).
- i18n: `treasury:repositories.transfer.*` in `en` + `fr` (+ `ar` where the file exists — treasury.json has all three locales).
- Movements rendering: `RepositoryMovementsTab` already lists `transfer` rows with the copy-id chip; unchanged (matches the `adjustment` precedent — no detail route).

## 6. Notification center

### 6.1 Data model

Laravel-standard `notifications` table, **tenant** migration: `id uuid PK`, `type string`, `notifiable_type/notifiable_id` (uuid morphs, index), `data jsonb`, `read_at timestampTz nullable`, timestamps. Standard framework shape — the `DatabaseChannel` and `$user->unreadNotifications()` work unmodified. No `company_id` column: company scoping lives **inside `data`** (`data.company_id`), because a notification belongs to a user; the FE panel shows all of the user's notifications for the active tenant DB regardless of active company, and each entry names its company when `data.company_id` is set (D-7).

### 6.2 Backend module

New slim module `app/Modules/Notification/` (Presentation only — the domain is the framework's):
- `GET /api/v1/notifications` — paginated `{data, meta}` (page/per_page, `filter=unread|all`), ordered `created_at desc`, scoped to `$request->user()`.
- `GET /api/v1/notifications/unread-count` — `{data:{count}}`.
- `POST /api/v1/notifications/{id}/read` — 404 if not the caller's; idempotent.
- `POST /api/v1/notifications/read-all`.
- Middleware `['api','auth:sanctum', SetPermissionsTeam::class, EnforceTokenTenantClaim::class]` (rule 12). **No extra permission** — a user reads only their own rows; authorization is ownership.
- Resource shape: `{id, type, data, read_at, created_at}` where `type` is a stable **string alias** (`treasury.reconcile.drift`, `treasury.portfolio_drift`, `treasury.instrument.maturity`, …) set via `databaseType()`/broadcast-safe alias, not the PHP FQCN — FE must never switch on a PHP class path.

### 6.3 Treasury alert senders

- `TreasuryAlertNotification` (Treasury module, `implements ShouldQueue` **NO** — sent synchronously inside the console commands to keep rule-20 surface minimal; sending N database rows is cheap). `via() = ['database']`. Payload: `{alert_type, company_id, company_name, severity, headline params (repository code / counts / window), deep_link}`. Deep links: drift → `/treasury/repositories/{id}`, portfolio drift → `/finance/overview`, maturity → `/treasury/instruments?maturing=1`.
- `ReconcileTreasuryCommand::freezeAndAlert()` + `alertPortfolioDrift()` and `InstrumentMaturityAlertsCommand`: after the existing audit_events write, resolve recipients and `Notification::send($users, new TreasuryAlertNotification(...))`, each wrapped in its own try/catch exactly like the existing alert channels (a notification failure must never abort the run or suppress the freeze — extends the existing `treasury.reconcile.alert_failed` pattern).
- **Recipient resolution:** users holding `treasury.manage` for the company. Console context: set the Spatie permissions team id explicitly for the company being processed before querying (`setPermissionsTeamId`), then restore — the commands already iterate companies; there is no HTTP `SetPermissionsTeam` here (rule 20 analog). Maturity alerts already produce ONE audit event per company per day — mirror that granularity: one notification per user per company per run (no per-instrument spam).
- audit_events writes are **unchanged** — the notification is an additional channel, never a replacement (compliance log stays authoritative).

### 6.4 FE

- `useNotifications` feature (`features/notifications/`): `useUnreadCount` (poll `refetchInterval: 60_000`), `useNotificationsList` (fetched on panel open), `useMarkRead`/`useMarkAllRead` mutations. Query keys: `tenantScopedKey(['notifications', userId, ...])` — userId in the key because localStorage/auth is shared on localhost and tenantScopedKey only appends tenant+company.
- TopBar bell (`TopBar.tsx:154-161`): replace the static button — badge renders **only when count > 0** (kills the hardcoded fake dot), opens a dropdown panel: last ~15 notifications, unread highlighted, click → mark read + navigate `deep_link`, mark-all-read action, empty state. Rendered for every authenticated user (no permission gate — the inbox is universal; senders decide targeting).
- Rendering by `type` with a **generic fallback** (type string + best-effort `data.message`) so the two pre-existing database-channel notification types (BatchExpiry, Enrichment) and any future type render harmlessly rather than crash the panel.
- i18n: new `notifications` namespace (en+fr+ar), registered in `i18n.ts` (3 touch points: import, resources, ns array).

## 7. G12 — Cash-movements report page

### 7.1 Backend (additive only)

`CashMovementsReportService` + `GetCashMovementsRequest`:
- New optional `direction` param (`in|out`) — applied as a WHERE on the wrapped UNION subquery.
- New `totals` block in `meta`: `{in, out, net}` — bcmath string sums over the **entire filtered range** (not the page). Same filters as the rows. One extra aggregate query over the union.
- Response stays `{data, meta}`; existing consumers unaffected (there are none).

### 7.2 FE

- `CashMovementsReportPage` (`features/finance/pages/`), route `/finance/cash-movements`, `RequirePermission permission="reports.view"`; nav entry in the `finance` sidebar group (`key: 'cashMovements'`, icon `ArrowLeftRight`, permission `'reports'`), beside the existing `treasuryOverview` entry. Cross-link: `TreasuryOverviewPage` "view movements" link; widget (§8) links here too.
- Pattern = `RepositoryMovementsTab` exemplar: `api.get` + `response.data` (`{data, meta}` — **never `apiGet`**, it drops `meta`), hand-rolled table (per the in-code warning that `DataTable` lacks pagination), `OffsetPagination`, `DateRangeFilter`, repository `Select` (from `usePaymentRepositories`), direction `Select`.
- Totals row above the table: In / Out / Net for the filtered range (from `meta.totals`), `formatCurrency` (from `@/lib/format`), color via design tokens.
- Columns: date, direction (badge), amount (signed color), source type, counterparty, GL account, source ref (copy chip; link where `sourceDocHref`-style mapping applies to payments).
- Default range: current month. `per_page` 50 (server default).
- No export in Phase ③ (follow-up: wire the orphan `ExportButton` or a server CSV — noted §13).

## 8. C9 — Cash-position widget

### 8.1 Backend (additive)

`CashPositionController::index`: optional `?flows_window=<1..90>` → response gains
```json
"flows": { "window_days": 7, "in": "...", "out": "..." }
```
computed as bcmath SUMs over `repository_movements` joined to the same included repositories (`cash_register|bank_account|safe`, active), `occurred_at >= now()-window`, grouped by direction. Absent when the param is absent (backward-compatible; `useCashPosition`'s existing consumer unchanged).

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
| `treasury.transfer` (NEW) | POST transfers | same bundles that hold `treasury.adjust` (RolesAndPermissionsSeeder; also add to PermissionSeeder base list, which is missing several treasury permissions already — add `treasury.transfer` to BOTH so the drift doesn't widen) |
| `treasury.view` | widget gate (existing) | unchanged |
| `reports.view` | movements report page (existing) | unchanged |
| — (none) | notifications API (ownership-scoped) | n/a |

Deploy owes: perm reseed + `permission:cache-reset` (tenant-blind cache — memory `project_spatie_permission_cache_tenant_blind`).

## 10. Testing & verification

- **Transfer endpoint (PHPUnit Feature):** cross-GL posts exactly one JE (Dr to / Cr from, posted status, correct amounts at currency scale); same-GL posts zero JEs; legs net to zero and both carry `transfer_group_id`; idempotent replay via client `transfer_group_id` (second call → `idempotent_replay: true`, no new JE, no orphan draft); draft-JE rollback on port failure (force a frozen target repo → no orphan draft JE); currency mismatch 422; frozen 422; inactive 422; missing GL account 422 (cross-GL only); permission 403; cross-company repo 404; **in-test `treasury:reconcile` run stays green after a mix of same-GL and cross-GL transfers** (checks #1–#3, Phase-② Task 8/9 pattern).
- **Notifications (PHPUnit):** endpoints scope to caller (404 on foreign id); unread-count; mark-read idempotent; commands send to exactly the `treasury.manage` holders of the affected company (team-id set/restored — test with two companies, disjoint managers); a notification-send failure does not abort the reconcile run nor suppress the freeze (extend the existing alert_failed tests).
- **Report additions:** direction filter; totals match bcmath sums under mixed payment-side/journal-side rows; pagination meta intact.
- **Cash-position flows:** window sums correct across repos; absent without the param.
- **FE (Vitest):** TransferCashModal (validation, currency filtering, idempotency-key stability across re-renders, success invalidations); useNotifications hooks; bell badge visibility (0 → hidden); CashMovementsReportPage filters/totals; CashPositionWidget gating (no permission / no module → null).
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

- Exact `GeneralLedgerService::createRepositoryTransferJournalEntry` placement/signature — mirror `createRepositoryAdjustmentJournalEntry` but returning a **Draft** entry (verify the existing method's status handling first; the adjustment one posts immediately, ours must not).
- **Verify §5.2.3c empirically**: `postEntryNow` on the fresh draft inside the replay savepoint must succeed *before* the legs' unique violation triggers the rollback (it should — a valid balanced draft posts fine), and its hash-chain/sequence side effects must be purely DB-state (rolled back with the savepoint). The transfer test suite (`TreasuryMovementServiceTransferTest`) exercises replay with a pre-posted JE, not this create-fresh-draft-per-attempt pattern — pin it with a dedicated test.
- Whether `EnforceTokenTenantClaim` is on all treasury routes' middleware group already (agent report says yes at the group level) — notifications module copies the same stack.
- `usePaymentRepositories` hook shape for the modal's balance display (may need the list endpoint's balance field confirmed).
- Poll interval + panel page size final values (60s / 15 — tune at review).
- Follow-up register: export on the report page; transfer shortcut on `RepositoryDetailPage` after `feat/treasury-ui-gaps` merges; full notifications page; Reverb real-time.

## 14. Migration, deploy, interlocks

- Migrations: `notifications` table (tenant); partial unique index on `journal_entries(source_type,source_id) WHERE source_type='treasury_transfer'`. Both additive.
- Deploy owes: `tenants:migrate`; perm reseed + `permission:cache-reset` (`treasury.transfer`). No chart reseed (no new accounts). Staging auto-deploys from dev with entrypoint-automated migrate/reseed/cache-reset — covered.
- Interlocks: **do not touch** `RepositoryDetailPage.tsx`/`ExpenseDetailPage.tsx` (`feat/treasury-ui-gaps`, not started as of 2026-07-12 — re-verify at FE-wave start) or `banks`/`BankPicker` (`feat/bank-reference-verification`). `TopBar.tsx` is claimed by NO in-flight track (verified against both briefs) — the bell wiring is safe.
- REALIGNMENT-LOG: not required (no published-API/canonical-shape change; all additive internal ERP endpoints).
