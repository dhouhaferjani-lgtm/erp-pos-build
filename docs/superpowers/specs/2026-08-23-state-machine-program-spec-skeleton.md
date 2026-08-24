# SPEC SKELETON — State machines, transitions & aggregate write-paths program

> **Status: SKELETON + owner ratification questions. No program code is authorized by this
> document.** Prepared by Session B (2026-08-23) per the owner's data-structure soundness ruling:
> quick fixes land now (Wave 1 Q-1..Q-12 + Slice D entry, tracked in the S-17 register); the
> program below executes **post-first-customer**, spec-first, after the owner answers §R.
>
> Inputs: `docs/handoff/TRIAGE-state-machine-audit-2026-08-23.md` §4 (charter sketch),
> `docs/handoff/AUDIT-state-machine-sweep-local-2026-08-23.md` §3 (four amendments, adopted
> verbatim here), the six sub-sweeps (`AUDIT-state-machine-sweep-sub-reports-2026-08-23.md`),
> and `docs/handoff/RETRO-partner-party-drift-2026-08-23.md` §5 (guard idioms).

## 0. Scope ruling baked in (from the census — do not re-litigate)

85 enums / 90 status columns / 76 without CHECKs / 4 adjacency maps / 3 transition surfaces /
1 bypassable PHPStan rule. The program is **smaller than the surface count suggests**:
Treasury instruments, Inventory counting, stock transfers, goods receipts, supplier return
notes, stock adjustments and voucher redemption are already app-layer governed.
**There is NO Treasury workstream and NO Inventory workstream** — those modules receive only
Slice D CHECKs and (optionally, §5) transition tables. A future lane proposing a Treasury or
Inventory state-machine rewrite is out of charter by this line.

## 1. Workstreams

| WS | Noun(s) | Contents | Depends on |
|---|---|---|---|
| **G0 — guard shape** (FIRST) | the PHPStan rule itself | Fix #27 before any propagation: parameterised rule (aggregate FQCN → allowed writer FQCN) covering property-assign AND `update()`/`fill()`/`forceFill()`/query-builder forms with a literal `status` key; `status` OUT of `$fillable` on every governed aggregate; backfill the missing Scheduling rule; fixture test per conv. 08 | — |
| **A — POS Orders** | `pos_orders`, `pos_order_lines` | SM-2/SM-4/SM-5(order+line thirds)/DM-1(transition slice): `OrderStatusMachine` + single `OrderTransitionService` + typed 422 exception + transitions table + G0 rule instance. Shift third of SM-5 stays with R-8/SV — not here | G0; F1/Q-9 landed |
| **B — Documents** | `documents` | SM-3 + DS-5(FK/delete-policy half) + **C-8 trigger-widening (MERGED INTO THIS WS — one lane, not two)**: app-level graph over `DocumentStatus`, single transition service, **built on P1's `DraftNotEditableException` — a parallel `InvalidDocumentTransitionException` dialect is forbidden** (extend/compose, don't duplicate — #29's lesson); keep `trg_document_immutability` as seal backstop and widen it per C-8; `source_document_id` FK + delete policy (NOT VALID + VALIDATE, after orphan census) | G0; P1 landed (done `81e0948b9`) |
| **C — StockLevel write path** | `stock_levels` | DM-3: aggregate methods (reserve/release/receive/consume) or single facade; ~20 write sites across 8 services migrated behind the ratchet; G0 rule instance; clamp `getAvailableQuantity` | G0 |
| **V — Vouchers** | `vouchers` | #24 residual after Q-5: full adjacency map on `VoucherStatus`, the three guard lists (controller/redemption/lookup) unified onto it; expiry engine per §R-D2 ruling | Q-5 landed |
| **I — Import jobs** | `import_jobs` | #23 residual after Q-1: adjacency map on `ImportStatus` (incl. the `Pending`-as-start-state question §R-A5), wire or delete `isTerminal()` | Q-1 landed |
| **D — DB CHECK hardening** | 76-column register (#26) | Entry conditions §3; money/fiscal-first per the #26 table; dead-state cleanup rides per batch (§4); **started by Session B in Wave 2** (parity test + first batches), remainder post-launch | parity test; per-batch census |
| **E — Doc reconciliation** | `.claude/context/architecture.md`, `deptrac.yaml` | DM-4 doc half only: make the doc tell the truth about Active-Record-in-Domain, or add a deptrac layer forbidding `Illuminate\Database` in `*/Domain` with a 223-file baseline — §R-E1 chooses | — |

Explicitly OUT (documented won't-fix, ratified by triage §4): POS de-anemification (DM-1 broad),
`ReceiptCreationService` split, Money-VO migration (DM-2 — contradicts the precision contract),
bigint→uuid PKs (DS-3, nine tables, `product_batches` has the uuid escape hatch), pure-domain
repository rewrite (DM-4 refactor half).

## 2. Per-workstream milestone shape (A, B, C, V, I all follow it)

1. **Census (red-first, machine-derived)** — every status write site for the noun, script not
   hand-list; implied graph vs stated graph table (`file:line → from-set → to-set`).
2. **Graph ratification** — owner signs the adjacency map (§R-A questions). No code before this.
3. **Machine + typed exception** — Workshop shape (`StatusMachine` 88-line mould; exception
   `extends DomainException`, HTTP 422, named constructors).
4. **Single write path + G0 rule instance IN THE SAME MILESTONE** — a transition service without
   its PHPStan rule is decorative (#27); `status` leaves `$fillable`.
5. **Transition table** — EXACT Workshop/Scheduling column vocabulary (uuid PK, tenant_id,
   entity FK cascade, `from_status`, `to_status`, `reason_code`, `triggered_by_user_id`
   nullOnDelete, `triggered_at` timestampTz, `context` jsonb, index (entity, triggered_at));
   written inside the same DB transaction as the status write. `instrument_events` is the
   third proven instance — adding `journal_entry_id`-style linkage columns is permitted, a new
   dialect is not.
6. **Call-site migration** behind a shrink-only ratchet; deptrac/ratchet deltas pre-agreed at
   spec time (§R-F1), never at merge.
7. **DB CHECK** for the noun folds into the nearest Slice D batch (not a per-WS migration).

## 3. Slice D — entry conditions (unchanged from triage §4, amendments 2 applied)

1. **Per-tenant distinct-value census BEFORE each batch's migration** (S-16 pattern; a CHECK
   aborts the whole per-tenant `tenants:migrate` on the first non-conforming row). A LEDGER
   staging-owes row arms the fleet census at deploy, per batch.
2. **`NOT VALID` + separate `VALIDATE CONSTRAINT`** so additive DDL cannot fail closed mid-fleet.
3. **The `pg_constraint` parity test FIRST** — every column backed by a Domain enum has a CHECK
   covering exactly that enum's cases, **including the audit tables' `from_status`/`to_status`
   columns** (three of the 76 are on the transition-audit surfaces themselves). 95 of 110
   CHECK-bearing migrations are pgsql-guarded and SQLite sees none — the parity test is the only
   thing keeping the constraints honest. Red against the current 76; baseline shrink-only.
   Sequencing: money/fiscal subset from #26's table first (`payments`, `payment_instruments`,
   `instrument_remittances(+lines)`, `instrument_events`, `journal_entries`, `fiscal_events`×3,
   `fiscal_event_projections`, `fiscal_event_quarantine`, `pos_receipts.fiscal_status`,
   `fiscal_periods`, `vat_periods`, `opening_balance_batches(+rows)`, `import_jobs`, `vouchers`,
   `goods_receipts`, `stock_transfers`, `stock_adjustments`, `supplier_goods_return_notes`,
   `withholding_certificates`, loyalty ×3, impersonation ×2).
   State-invariant CHECKs (the `pos_shifts_closed_logic` shape) ride the same batches where the
   sub-sweeps named them (stock_transfers completed/cancelled timestamps; opening_balance_batches
   LOCKED⇒locked_at+hash; instrument_remittance_lines cleared/bounced timestamps).

## 4. Dead-state cleanup register (rides Slice D batches; each row needs the §R-D ruling)

| Dead state | Evidence | Options |
|---|---|---|
| `AssignmentStatus::Overdue` | never written | implement scheduled sweep vs delete case |
| `VoucherStatus::Expired` + `VoucherEvent::Expired` | reads only, no engine | expiry job + GL breakage entry vs delete |
| `CouponStatus::Expired` | read at one site, never written | wire expiry vs delete |
| `JournalEntryStatus::Reversed` + `reversed_at/by`, `reversal_entry_id` | zero writers; InstrumentLifecycleService works around it with a documented heuristic | stamp linkage at reversal post vs delete both |
| `SignatureStatus::{Pending,Signed,Failed}` | trigger freezes the column — structurally unreachable | drop cases until signing real vs add trigger transitions now |
| `TenantStatus::Pending` (column default!) + `::Archived`; no reactivate edge | suspend is one-way | add guarded `Suspended→Active` reactivate + fix default + implement-or-delete Archived |
| `bank_reconciliations` schema + `ReconciliationStatus` + `payments.is_reconciled` | zero code references; live surface is bank_statements | drop tables+enum+columns vs document reserved & un-fillable |
| `DeviceLossIncidentStatus` lifecycle | table+CHECK+index wired, ZERO write sites — incidents stuck at `reported` forever | implement recovery service vs mark not-yet-operational (launch-relevant: single-till loss story) |
| `PaymentStatus::{Pending,Failed}` (`pending` is the column default) + dead `isTerminal()` | 15 write sites, none writes them | align default to `completed` semantics vs wire |
| `InstrumentStatus` reserved-dormant (`InTransit`,`Expired`,`Collected`) + dead `isTerminal()`s (also `ImportStatus`, Treasury ×2) | never called | wire into guards vs delete (per-enum) |
| `pos_receipts.is_voided` + device `offline_receipts.voided` | written only `false`; stale device comment | retire/document (retro G3 axis) |
| `SubscriptionStatus` Stripe fail-open mapping | `default => Active` resurrection of terminal states | `default => throw`/PastDue + consult `isTerminal()` (pre-self-serve gate) |

## 5. Composition with the enforcement layer

This program is a fourth enforcement package in everything but name: guard shape = PHPStan rule
+ `tests/PHPStan/` fixture + `tests/Architecture/*RatchetTest` shrink-only baseline + conv. 08
tamper test. S-14 applies if anything touches `.github/workflows/**`. S-17: merges land
CI-UNVERIFIED with local evidence while the window is armed. Transition-table vs lighter
column-level audit (the `CustomerAccountStatusService` shape: version + changed_at/by/reason
columns + fiscal event) is a per-noun choice — §R-F2.

## R. OWNER RATIFICATION QUESTIONS (blocking — the program does not start without these)

### R-A — Graphs to sign (one adjacency map each; non-obvious edges called out)
- **R-A1 POS Order**: `closeOrder` is HTTP-dead (410) — is `Closed` retired from the graph
  (fixture-only) or kept for a future close path? Is `Ready → SentToKitchen` (un-bump) a legal
  edge? Cancelled: terminal absolutely (Q-9 already blocks the Cancelled→Ready hole)?
- **R-A2 POS OrderLine**: formalize `pending→sent` and `ready→served` (today bulk-only,
  unguarded); is `Cancelled` line terminal?
- **R-A3 Document**: may `Paid → Cancelled` exist (r2f4 correcting-document / credit-note
  interaction)? Is `Received` terminal? Which states may `revert` reach? (DocumentPostingService
  already guards post/cancel/revert edges — the graph must match those exactly or name the
  deliberate differences.)
- **R-A4 Voucher**: ratify Q-5's allow-list (voidable = Issued|PartiallyRedeemed;
  FullyRedeemed/Expired/Voided terminal). May an Expired voucher be reactivated (expiry
  extension exists as a ledger event)?
- **R-A5 Import**: is `Pending` a legal execute start state (today: yes, by comment) or
  Validated-only?
- **R-A6 Replenishment**: name the compensating `Fulfilled → Pending` edge (transfer-cancel
  reopen) in the map; `isTerminal()` corrected to derive from it.
- **R-A7 FiscalPeriod**: ratify Q-10's `Closed → Open` reopen (permission name? who holds it?
  no-closed/filed-successor guard copied from VatPeriodManagementService). `Locked` stays
  terminal-except-support?
- **R-A8 pos_receipts.fiscal_status**: split device-sync states (`pending_sync`,`synced`,
  `sync_failed`) into their own column, narrowing fiscal_status to 3 real values? (Q-6 freezes
  them; the split is program scope.)

### R-B — Workstream rulings
- **R-B1**: Confirm NO Treasury / NO Inventory workstream (fold-in via Slice D only).
- **R-B2**: Confirm workstream B absorbs the C-8 trigger-widening lane (one lane, one owner).
- **R-B3**: `TableStatus` + F&B order surfaces — confirm deferral to the Dhouha/F&B track
  (SM-7, DM-8 ride it as review inputs).

### R-C — Guard shape (G0)
- **R-C1**: One parameterised PHPStan rule (map-driven) instead of one class per noun — ratify.
- **R-C2**: `status` removed from `$fillable` on every governed aggregate (WorkOrder,
  Appointment, then each new noun) — breaking for any mass-assign caller; ratify the sweep.

### R-D — Dead-state cleanup (per §4 row: implement vs delete)
- **R-D1** AssignmentStatus::Overdue · **R-D2** Voucher/Coupon Expired + expiry engines (GL
  breakage income = an accounting-policy call, country-seeded) · **R-D3**
  JournalEntryStatus::Reversed + columns · **R-D4** SignatureStatus · **R-D5** TenantStatus
  default/reactivate/Archived · **R-D6** bank_reconciliations + payments.is_reconciled ·
  **R-D7** DeviceLossIncident recovery service (pre-launch relevance: yes/no) · **R-D8**
  PaymentStatus dead cases + default · **R-D9** Stripe mapping fail-closed.

### R-E — Docs & structure
- **R-E1**: DM-4 — amend `.claude/context/architecture.md` to ratify Active-Record-in-Domain
  with boundary rules, OR add the deptrac `Illuminate\Database`-in-Domain layer with a baseline?
- **R-E2**: DS-3 — sign the documented won't-fix (nine bigint tables, escape hatch noted).
- **R-E3**: DM-5 — `composite_items.tax_rate` string→decimal ratified as P2 folded into the
  next Catalog/CompositeItems lane (module dark for tenant #1).
- **R-E4**: `'TND'` currency fallback (`OrderManagementService.php:130`) → routed to the
  country-defaults authority (O-20/O-25 family), not this program — confirm.

### R-F — Process
- **R-F1**: Pre-agree ratchet/deptrac deltas per workstream at spec time.
- **R-F2**: Per noun: full transitions table vs `CustomerAccountStatusService`-style column
  audit — default proposal: tables for Order/Document, column-style for Voucher/Import.
- **R-F3**: DS-1 treasury FK lane go/no-go — gated on Q-12's orphan census output (fleet run
  at deploy); `NOT VALID`+`VALIDATE`, S-16 staging-owes row.

*Prepared by Session B, 2026-08-23. Companion quick-fix state: S-17 register + LEDGER.*
