# Treasury Money-Movement Spine — Design Spec (Phase 1 of 5)

> **Date:** 2026-07-07 · **Rev 2 (2026-07-08):** reconciled against the Codex adversarial review — [reviews/2026-07-08-treasury-spine-codex-review.md](reviews/2026-07-08-treasury-spine-codex-review.md) (2 BLOCKER / 8 HIGH / 4 MED, all accepted; resolutions in §16) · **Status:** awaiting owner review
> **Owner decisions captured:** phasing (spine first), no legacy-data constraints (no clients yet), gated adjustment documents, POS auto-tolerance deferred, architecture Option A (movements ledger + cached balance), NF525 scope correction (POS perimeter only).
> **Inputs:** [2026-07-07 industry-gap audit](../audits/2026-07-07-treasury-industry-gap-audit/README.md) · certification research (NF525/FEC/PCG/TEJ) · ledger-engineering research (Modern Treasury/Stripe/Square/pgledger/TigerBeetle) · code infrastructure map (agents, file:line verified)
> **Program context:** Phase 1 of: ① spine → ② instrument portfolio → ③ cash-visibility read layer → ④ expense depth → ⑤ bank reconciliation → then TEJ platform integration.

---

## 1. Goal and invariant

Make every money movement **traceable, auditable, and justified**:

> **THE INVARIANT: money in a payment repository changes only through one port, and every change carries its justification — a source document reference and a GL journal entry — written atomically.**

Downstream everything else follows: trustworthy cash position, drill-down from any balance to its constituent movements, clean FEC/PCG audit trail, and a foundation the instrument portfolio (Phase 2) and TEJ/RAS integration can stand on.

## 2. Scope

**In:** append-only `repository_movements` ledger + single write port; migration of ALL money-moving flows onto it (incl. POS bridges — fixes the wrong Total Cash); new POS return/refund GL bridge; treasury refund balance+GL; MultiPayment GL; inter-repository transfers; gated adjustment documents; unpaid-expense AP fix **+ expense settlement path** (`POST /expenses/{id}/pay` — closes the AP loop, review F6); period-close guard wired into posting; GL chain-sequence race fix; `journal_code` column (FEC-readiness); `payment_repositories.currency` column + `gl_account_id` canonicalization in projection bridges (review F12/F14); reconciliation command with freeze-on-drift; minimal read surface (cash-position endpoint, movements drill-down + Movements tab); instrument list-page contract fix (P0 bug, G3).

**Out (explicit):** instrument GL/échéancier (Phase 2); dashboards/charts (Phase 3); expense VAT/recurring/analytics (Phase 4); statement import (Phase 5); POS auto-tolerance (own track); card acquirer-settlement modeling; multi-currency (hard guard only); FEC exporter (schema-readiness only); TEJ integration (already has its own module; untouched); movements hash chain (see §11 delta D6).

## 3. Existing infrastructure — reuse map (verified file:line)

| Building block | State | Phase-1 action |
|---|---|---|
| `RepositoryInflow/OutflowInterface` + services (`Shared/Contracts/Treasury/`; `Treasury/Application/Services/Repository*.php:19-57`) | Balance-only port; only Expense + Income call it | **Absorb into new port** (keep interfaces as thin adapters or migrate callers — plan decides) |
| `PaymentController` inline balance writes (`:602-609`, `:960-961`) | Bypasses port — divergent path | **Migrate onto port** |
| `RepositoryBalanceChanged` event | Fired, zero listeners, not in `DomainEventSubscriber` | Superseded by richer `RepositoryMovementRecorded`; register in `DomainEventSubscriber` → `audit_events` (review F13) |
| GL hash chain (`journal_entries.fiscal_hash/previous_hash/chain_sequence`; seal in `GeneralLedgerService::postEntryWithOptionalActor:1957-2014`) | Works; **debits==credits already asserted `:1984-1988`** | Reuse as-is; fix `chain_sequence` allocation race (no lock today) |
| `FiscalPeriodResolverService::isDateInOpenPeriod` (`:246-256`) + `FiscalPeriodAutoLockService` + `LockExpiredFiscalPeriodsCommand` | Exists, **unwired** — nothing blocks posting into closed periods | **Wire into GL posting + movement recording** |
| `fiscal_events` immutability (BEFORE UPDATE/DELETE/TRUNCATE triggers, `2026_05_14_100002`) | The in-repo append-only template | **Copy trigger pattern** for `repository_movements` |
| Withholding/RAS (`Taxation`: `WithholdingCertificateService::createFromPayment:114`, dated `withholding_tax_rules`, TEJ XML export, own hash chain) | Full feature, attaches to payments | **No spine changes needed** — movements link to payments; certificates hang off payments already. Don't break it |
| `audit_events` + `AuditService::record` + `DomainEventSubscriber` (JET-equivalent; plain PG, per-row hash) | Exists; POS closures/config changes logged; treasury events absent | Register spine events (movements via balance-changed, adjustments, reconciliation results, period closes) |
| Numbering | Fragmented; JE number = racy unlocked `max()+1` (`GeneralLedgerService:2781`) | Movements get per-repo `ordinal` (free, §4); JE-number race fixed alongside chain-seq fix |
| POS `pos_z_report_counts` (DB-CHECKed expected/actual/variance) | POS-only cash counts | Pattern noted for future treasury PV de caisse — NOT Phase 1 |

## 4. Data model — `repository_movements`

Append-only. Immutability enforced three ways: no update/delete code paths; model guards; **DB trigger raising on UPDATE/DELETE/TRUNCATE** (per `fiscal_events` template).

| Column | Type / constraint | Notes |
|---|---|---|
| `id` | uuid PK | |
| `tenant_id`, `company_id` | required | tenant DB, company-scoped |
| `payment_repository_id` | FK, indexed | |
| `direction` | enum `in`/`out` (PHP enum, rule 9) | |
| `amount` | `decimal(15,3)`, `CHECK (amount > 0)` | direction carries sign — no signed amounts (anti-pattern) |
| `currency` | char(3), must equal **repository currency AND company currency** | **hard guard** — mismatched currency throws; requires new `payment_repositories.currency` col (review F12); multi-currency deferred |
| `balance_after` | `decimal(15,3)` | running balance in **record order** (see D3) |
| `ordinal` | bigint | **gapless per-repository sequence**: counter column on the locked `payment_repositories` row, incremented in-transaction — rolls back with it |
| `source_type` | enum: `payment`, `expense`, `income`, `refund`, `fiscal_event`, `transfer`, `adjustment`, `opening_balance`, `instrument` *(reserved, Phase 2)* | provenance = pièce justificative |
| `source_id` | uuid | indexed with source_type |
| `journal_entry_id` | FK nullable* | the GL justification. *Nullable ONLY for `opening_balance` and same-GL-account transfer legs; architecture test asserts every other source type carries one |
| `idempotency_key` | string, **unique index** | deterministic `"{source_type}:{source_id}:{leg}"` where `leg` is a **stable ordinal, never a method label** (review F3): POS tenders → `fiscal_event:{uuid}:payment:{canonical_index}` (the canonical `PaymentDTO` index, persisted on `pos_receipt_payments` — see F3/F4 below); transfer → `transfer:{uuid}:out`/`:in`; reversal → `reversal_of:{movement_id}`. Retried writers SELECT-on-conflict, **validate semantic fields, and treat as success only on full match** (mismatch throws) |
| `transfer_group_id` | uuid nullable | pairs transfer legs; legs must net to zero |
| `reverses_movement_id` | self-FK nullable | corrections are compensating movements, never edits |
| `reason_code` | enum nullable | required for `adjustment` and reversals |
| `occurred_at` / `created_at` | both | business time vs record time; backdating bounded: not before last clean reconciliation checkpoint nor into a closed fiscal period |
| `created_by` | user FK | |
| `recorded_while_frozen` | bool default false | set when a queued projection wrote into a frozen repository (§9, review F5); drives operator alert |
| `notes` | text nullable | operator context |

Indexes: PK · unique `idempotency_key` · `(payment_repository_id, occurred_at)` · `(source_type, source_id)`. **No** partitioning/BRIN/fillfactor tuning (unwarranted below tens of millions of rows). `payment_repositories.balance` stays as the cached read column; loses mass-assignability; direct writes outside the port forbidden by PHPStan rule + architecture test.

## 5. The write port

`App\Shared\Contracts\Treasury\TreasuryMovementServiceInterface`:

```php
public function record(MovementIntent $intent): MovementResult;   // one movement
public function transfer(TransferIntent $intent): TransferResult; // two paired legs
```

`MovementIntent` DTO (strict-typed, rule 3): repositoryId, tenantId, companyId, direction, amount (numeric-string), currency, sourceType, sourceId, idempotencyLeg, journalEntryId?, occurredAt?, reasonCode?, reversesMovementId?, createdBy, notes?.

Transaction semantics (single DB transaction; **GL posting is synchronous inside it** — see the BLOCKER resolution below):
1. Resolve scale via injected `CurrencyScaleResolverInterface` (explicit currency — queue-safe, rules 13/19/20). Validate intent currency against **repository currency AND company currency** (new `payment_repositories.currency` column, review F12).
2. **Post the GL entry synchronously in-transaction.** New `postEntryNow()` path on `GeneralLedgerService` (alongside the existing `postEntryAndDispatchPostedEventAfterCommit`, which defers via `DB::afterCommit` — `GeneralLedgerService.php:83-94` — and is therefore FORBIDDEN for spine flows): creates + posts + hash-seals the JE inside the caller transaction; only the `JournalEntryPosted` **event dispatch** stays afterCommit. Period validation (`isDateInOpenPeriod`) happens ONCE, here, in-transaction — never split between movement-time and post-time (review BLOCKERs 1+2).
3. `lockForUpdate` the repository row(s). `transfer()` is a **single dedicated transaction that locks BOTH repositories sorted by id BEFORE any insert** and writes both legs + both balances itself — never implemented as two `record()` calls (review F7).
4. Exact savepoint ordering (review F11): lock repo → **SAVEPOINT** → increment `ordinal` (counter on the locked row) → compute `balance_after` → insert movement → update cached `balance` → release savepoint. On `idempotency_key` unique violation: roll back to savepoint (undoes ordinal + balance — no gaps), SELECT the existing movement, **validate its semantic fields** (repository, direction, amount, currency, source) against the intent — match ⇒ return as success; mismatch ⇒ throw loudly (review F3).
5. `afterCommit`: fire `RepositoryMovementRecorded` (richer successor to `RepositoryBalanceChanged`; both registered in `DomainEventSubscriber` → `audit_events` — review F13).

Callable from HTTP controllers and queued projections. The old `RepositoryInflow/OutflowService` are **deleted in the same convergence wave** (not kept as adapters — one code path only; review F8). **Acceptance test injects a GL-post failure after the movement write point and proves nothing survives commit** — no payment, allocation, movement, balance change, or JE.

## 6. Flow convergence (all writers, after Phase 1)

| Flow | Today (verified) | After |
|---|---|---|
| B2B customer/supplier payment | inline balance write + GL (`PaymentController:602-609,960`) | port: movement(`payment`) + GL |
| Expense post (paid) | old port + GL | port: movement(`expense`) + GL |
| Expense post (unpaid) | **unpaid still credits Cash** (bug, `GeneralLedgerService:2318-2327`) | **Cr AP liability, no movement**; settled later via new `POST /expenses/{id}/pay` → movement(out) + Dr AP/Cr Cash, atomic (review F6) |
| Expense refund | old port + GL | port: movement(`refund`) |
| Income | old port + GL | port: movement(`income`) + GL |
| Vendor/PO refund | inline write + GL | port: movement(`refund`) |
| POS sale (`TreasuryReceiptBridge`) | GL + Payment, **no balance** | port: movement(`fiscal_event`) per tender line, keyed by canonical index; **complete-set idempotency** — replay validates every expected leg exists, repairs partial sets in-txn (review F4) |
| **POS returns/refunds** | drawer only — no GL, no balance | **new return projection bridge**: movement(out) + GL reversal |
| Treasury payment refunds | neither (`PaymentRefundService`) | port + GL reversal; **`lockForUpdate` original payment, compute refunded-total under lock, idempotency key on partial refunds** (review F10) |
| POS deposit / account-payment bridges | GL only, **no balance** | **first convergence wave** (not later cleanup, review F9): port movement added; canonicalize on `gl_account_id` (review F14) |
| MultiPayment split/deposit/on-account | balances only, no GL | port + GL (closes F16) |
| **Inter-repo transfer** | impossible | **new**: `transfer()` — paired legs, GL when crossing GL accounts |
| Adjustment | impossible | adjustment document (§7) |
| Opening balance | seeder sets column | seeder writes `opening_balance` movement |

POS card tenders keep today's repository mapping (traced, not remodeled); acquirer clearing/settlement + `has_deducted_fees` wiring deferred to Phase 5.

## 7. Adjustment documents

Permission `treasury.adjust` (seeded to admin/owner roles). Endpoint + minimal FE action on repository detail. Requires: reason enum (`count_variance`, `correction`, `theft_loss`, `other` + mandatory text), amount, direction. Writes movement(`adjustment`) + GL entry (cash account ↔ configured variance account, TN 658-family via `SystemAccountPurpose`). Immutable once posted; mistakes are reversed (`reverses_movement_id` + reason), never edited. Logged to `audit_events`.

## 8. GL hardening (rides along, protects the invariant)

- **Wire the closed-period guard**: `postEntry` and `record()` reject dates outside an open `FiscalPeriod` (`isDateInOpenPeriod` — exists, unwired). Closed periods therefore lock both GL and movements (FEC ValidDate discipline).
- **Fix `chain_sequence`/JE-number allocation races**: sequence allocation under a company-scoped lock (the withholding chain and `fiscal_events` already do gapless correctly; GL is the outlier). FEC requires sequential `EcritureNum` — racy `max()+1` is both a correctness and a compliance defect.
- **`journal_code` enum column on `journal_entries`**, populated going forward — makes future FEC export a query, not a migration. Declared FEC codes are `VT` (sales), `AC` (purchases), `BQ` (bank), `CA` (cash/POS), `EF` (payment-instrument portfolio operations whose source type is `instrument` or `instrument_remittance`), and `OD` (miscellaneous/default). Receipt-side payment entries retain their existing `BQ`/`CA` code; `EF` identifies only portfolio lifecycle postings. Exporter itself is out of scope.
- debits==credits assertion: **already exists** (`:1984-1988`) — no work, covered by tests only.

**`payment_repositories` schema changes (review F12/F14):**
- Add `currency char(3)` (backfilled from company currency; the movement port's hard currency guard needs it — repositories carry none today, so payment/repository currency can diverge silently).
- Add `frozen_at timestamptz null` + `frozen_reason` (reconciliation freeze, §9).
- Add `next_movement_ordinal bigint default 0` (the gapless per-repo counter, §5 step 4).
- **Canonicalize cash-GL resolution on `gl_account_id`.** Today `TreasuryDepositBridge` requires `account_id` (`:196-215`) while `TreasuryAccountPaymentBridge` falls back `account_id ?? gl_account_id` (`:216-226`) and `createPOSPaymentEntry` uses `gl_account_id` (`:2092-2133`) — a rejected-but-valid repository looks like a movement-port failure. Projection bridges + the movement port resolve one cash-GL account via `gl_account_id`; `account_id` kept only as a backfilled legacy alias, dropped from bridge validation.

## 9. Reconciliation — `treasury:reconcile` (scheduled, per tenant)

1. Per repository: `balance == Σ(signed movements)` AND `balance_after`/`ordinal` chain continuity (gap ⇒ tamper/bug signal).
2. Ledger↔GL coherence: every non-exempt movement's `journal_entry_id` exists with matching amount; every cash/bank journal line has a movement (completeness, both directions).
3. Transfer clearing: every `transfer_group_id` nets to zero.

**On drift: freeze the repository + alert. Never silently repair.** Results logged to `audit_events`.

**Freeze is interactive-only — it must NOT throw from queued fiscal projections** (review F5, BLOCKER-adjacent): a POS device is offline-first and keeps selling; if a frozen repository made `TreasuryReceiptBridge` throw, the fiscal-projection Horizon rows would retry then dead-letter (`ApplyFiscalEventProjectionJob.php:428-455`), stranding already-sealed sales. Policy: freeze rejects **interactive port writes** (HTTP: payments, expenses, adjustments, transfers) with an explicit 4xx, but **projection writes into a frozen repository still record, flagged `recorded_while_frozen=true`** on the movement, and raise an operator alert rather than an exception. The operator resolves the drift and clears the flag/freeze; the queue never stalls. `frozen_at` + `frozen_reason` columns on `payment_repositories`.

Full-scan is fine at our volume; watermark checkpoints deferred until row counts warrant.

## 10. Read surface (minimal)

- `GET /api/v1/treasury/cash-position` — per-repository balances grouped by type + totals + `as_of` (server-side; replaces FE summing).
- `GET /api/v1/payment-repositories/{id}/movements` — paginated, filters: date range, source_type, direction. Each row links to its source document and JE.
- FE: **Movements tab** on `RepositoryDetailPage` (tenant-scoped query keys, `formatCurrency`, i18n — rules 11/14/19). Owner-verifiable E2E surface.
- Instrument list-page contract fix (G3): align `InstrumentListPage.tsx` fields to the API response shape.

Routes: `['api','auth:sanctum',SetPermissionsTeam::class]` + `can:` permissions (rule 12). New TS types via `typescript:transform` (rule 7).

## 11. Deltas from the conversationally-approved skeleton (all flagged)

| # | Delta | Why |
|---|---|---|
| D1 | `idempotency_key` column replaces `(source_type,source_id,direction)` uniqueness | multi-leg sources (split tender, transfers) and reversals collide under the naive key (ledger research) |
| D2 | `ordinal` gapless per-repo sequence added | free under the existing row lock; auditor-friendly; reconciliation watermark (ledger research) |
| D3 | `balance_after` defined as **record-time** running balance only | backdated `occurred_at` must never rewrite snapshots (MT caveat) |
| D4 | DB trigger blocks UPDATE/DELETE on movements | app-layer discipline is one Eloquent `update()` away from broken; `fiscal_events` template exists |
| D5 | `reverses_movement_id` + `reason_code`; corrections = compensating movements | PCG piste d'audit fiable + plain auditability |
| D6 | **No hash chain on movements** (research recommendation overridden by owner) | NF525 scope = POS encaissement perimeter, already discharged by the device fiscal-event chain; back-office movements inherit tamper-evidence transitively via the GL chain each movement links to. Third chain = rejected ceremony |
| D7 | PaymentController migration onto the port made explicit | code map found it bypasses the port entirely — two divergent mutation paths today |
| D8 | debits==credits assertion REMOVED from scope | already exists (`postEntry:1984-1988`); June-audit finding F20 was stale. Replaced by the chain-sequence race fix |
| D9 | Closed-period guard = **wiring**, not building | `isDateInOpenPeriod` + auto-lock exist unwired |
| D10 | No RAS fields on movements | full withholding/TEJ module already exists attached to payments; movements→payment linkage suffices |
| D11 | `journal_code` column added (FEC-readiness) | FEC export needs it; adding now is a column, adding later is a backfill |
| D12 | Spine events logged via existing `audit_events`/`DomainEventSubscriber` | JET-equivalent already exists; no new audit infra |

## 12. Certification traceability (constraint → design element)

| Constraint (source) | Design element |
|---|---|
| Pièce justificative on every entry (PCG 921-2; FEC PieceRef/PieceDate) | `source_type`/`source_id` mandatory; JE `source_*` populated; `journal_code` |
| Sequential numbering (FEC EcritureNum) | `ordinal` per repo; JE number + chain-seq race fix |
| Corrections never destructive (PCG/NF203) | append-only + trigger + `reverses_movement_id` + reason codes |
| Period locking / ValidDate (FEC) | closed-period guard wired into posting + movements |
| Inaltérabilité POS perimeter (NF525) | already discharged by device fiscal-event chain; movements are projections |
| 10-year retention (L123-22 / TN loi 96-112) | append-only ledger, no purge paths; archival = later phase |
| RAS at payment time (TEJ) | existing withholding module on payments; movements link to payments |
| Auditable cash counts / reconciliation evidence | POS Z-counts exist; `treasury:reconcile` results persisted to `audit_events`; treasury PV de caisse = later phase |

## 13. Testing & verification

- TDD per task (rule 2). Per flow in §6: red-green test proving {movement + GL + subledger} atomicity **including rollback** (GL failure ⇒ no movement, no balance change).
- Port concurrency test: parallel movements on one repository → both recorded, exact balance, dense ordinals. Transfer deadlock test (two opposing transfers, sorted locking).
- Idempotency: replayed POS fiscal event / retried queue job → single movement per leg.
- Projection tests clear `CompanyContext` (rule 20); explicit currency everywhere (rule 19).
- Immutability: UPDATE/DELETE on movements throws at DB level.
- Period guard: posting/moving into a closed period rejected.
- Reconcile: seeded drift → freeze + alert; clean tenant → green.
- Live Playwright E2E: POS sale → movement on repository detail → cash-position reflects → expense post → transfer drawer→bank → adjustment → `treasury:reconcile` clean. Full-suite PHPUnit NOT run locally (standing rule); suites run by path + CI.
- **Gates:** adversarial review of this spec + the plan before dispatch; `treasury-reviewer` on every milestone; human merges.

## 14. Execution model

Worktree off dev (`feat/treasury-spine`). Implementation plan = bounded, independently-reviewable tasks sized for Codex/Opus dispatch (Codex quota caveat; CLI-brokered Codex cannot write to `apps/erp.*` worktrees → Codex Desktop or Claude agents). Rough task shape (plan will finalize): schema+model+trigger → `postEntryNow()` GL path + period-guard wiring → port+DTOs → **all-writers convergence in ONE wave** → new bridges (returns) → expense settlement → adjustments → GL hardening → reconcile command → read surface+FE → E2E.

**Migration safety (review F8 — no double-count window):** the balance-write cutover is NOT staged writer-by-writer. Within the convergence wave, all writers move onto the port together, the old `RepositoryInflow/OutflowService` are deleted, `balance` is removed from `$fillable`, and a DB trigger rejects direct `balance` UPDATEs except from the port's controlled session (belt-and-braces beyond the architecture test + PHPStan rule, which miss raw-query/`update()` paths). No deploy boundary exists where two balance-mutation paths coexist.

## 15. Open questions

Resolved by the adversarial review: old ports **deleted** not adapted (F8); freeze = **`frozen_at` column** (F5); `journal_code` = **PHP enum `fromSourceType()`** (Rev-1 lean confirmed).

Remaining for the implementation plan to trace before writing code (not design blockers):
1. `TreasuryDepositBridge` FIFO allocation GL: does it interact with movement amounts 1:1 per tender or per allocation? Determines whether the deposit-bridge movement leg is one row or N. Trace `PaymentAllocationService:251-347` first.
2. Persisting the canonical `PaymentDTO` index onto `pos_receipt_payments` (for the F3/F4 idempotency key): new column vs derivable from canonical bytes? Plan task decides after reading `PosCoreReceiptProjection:733-801`.
3. Expense settlement (`POST /expenses/{id}/pay`) reuses the movement port directly vs shares code with the supplier-payment path — trace for overlap, avoid a third outflow variant.

## 16. Adversarial-review reconciliation (Codex, 2026-07-08)

Full review: [reviews/2026-07-08-treasury-spine-codex-review.md](reviews/2026-07-08-treasury-spine-codex-review.md). All 14 findings accepted after firsthand verification of the two load-bearing claims. Resolutions:

| # | Sev | Finding (short) | Resolution in this spec |
|---|---|---|---|
| 1 | BLOCKER | GL posts via `DB::afterCommit` → movement can commit while GL post fails later | §5.2 new **`postEntryNow()`** synchronous-in-transaction path; afterCommit path forbidden for spine flows |
| 2 | BLOCKER | Period guard split between movement-time and post-time worsens the gap | §5.2 period validation happens **once, in-transaction**, alongside the synchronous post |
| 3 | HIGH | Split-tender idempotency key collides (two CASH lines) | §4 key uses **canonical payment index**, not method label; §5.4 validate-semantic-fields-or-throw |
| 4 | HIGH | Partial projection replay marked as success (any-payment-exists probe) | §6 POS bridges use **complete-set idempotency** — validate every expected leg, repair partial in-txn |
| 5 | HIGH | Freeze throws from fiscal projections → queue dead-letters | §9 freeze is **interactive-only**; projections record with `recorded_while_frozen` + alert, never throw |
| 6 | HIGH | Unpaid-expense AP fix has no settlement path (dead-end liability) | §2/§6 add **`POST /expenses/{id}/pay`** (movement + Dr AP/Cr Cash, atomic) |
| 7 | HIGH | Transfer two-lock + GL atomicity underspecified | §5.3 `transfer()` = **single txn, both repos locked sorted-by-id before any insert**, never two `record()` calls |
| 8 | HIGH | Migration window lets old + new balance writes coexist / double-count | §14 **one-wave cutover**: delete old ports, drop `balance` from fillable, DB trigger rejects direct writes |
| 9 | HIGH | Account/deposit bridges create Payment+GL but no movement | §6 folded into the **first** convergence wave, not later cleanup |
| 10 | HIGH | Refund flows lack original-payment locking / partial-refund idempotency | §6 **`lockForUpdate` original payment**, refunded-total under lock, idempotency key on partials |
| 11 | MED | Savepoint recovery ordinal/balance consistency underspecified | §5.4 exact ordering: SAVEPOINT → ordinal → balance_after → insert → cached balance → release; rollback undoes all |
| 12 | MED | Currency guard needs a repository currency column (none today) | §8 add **`payment_repositories.currency`**; §4/§5.1 guard against repo AND company currency |
| 13 | MED | `RepositoryBalanceChanged` not subscribed to `audit_events` | §3/§5.5 **`RepositoryMovementRecorded`** registered in `DomainEventSubscriber` |
| 14 | MED | `account_id` vs `gl_account_id` inconsistent across bridges | §8 **canonicalize on `gl_account_id`**; `account_id` legacy alias only, dropped from bridge validation |
