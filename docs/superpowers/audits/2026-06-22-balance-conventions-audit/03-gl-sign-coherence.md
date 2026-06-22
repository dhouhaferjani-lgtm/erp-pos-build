# GL Sign Conventions & Partner-Balance Cache Coherence Audit

**Date:** 2026-06-22
**Scope:** Double-entry GL sign / normal-balance conventions and the denormalized
`partners.{receivable,credit,payable}_balance` cache vs. the GL source of truth.
**Mode:** Read-only audit. No code modified.

**Files in scope**

- `apps/api/app/Modules/Accounting/Domain/Services/GeneralLedgerService.php` (GL primitives)
- `apps/api/app/Modules/Accounting/Application/Services/PartnerBalanceService.php` (cache + reconciliation)
- `apps/api/app/Modules/Accounting/Domain/Enums/SystemAccountPurpose.php`
- `apps/api/app/Modules/Partner/Domain/Partner.php` (`net_balance`, casts, staleness)
- `apps/api/app/Modules/Partner/Presentation/Controllers/PartnerController.php` (SQL net_balance)
- `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php` (device snapshot guard)
- `apps/api/app/Modules/Partner/Application/Services/RecordCustomerDepositService.php`
- `apps/api/database/migrations/tenant/2025_12_06_100001_add_balance_fields_to_partners.php`

---

## 0. Executive answer to the critical question

> Does `reconcileSubledger` / `getSubledgerTotal` / `getControlAccountBalance` read
> `partners.credit_balance` (the cache), or only `journal_lines` (raw GL)?

**They read ONLY raw GL (`journal_lines` joined to `journal_entries`/`accounts`).
None of them touch `partners.credit_balance` or any cache column.** The proposed
sign flip of the cache therefore **cannot** cause false reconciliation failures.
Exact citations:

- `getSubledgerTotal` — `PartnerBalanceService.php:142-158`. Query is
  `DB::table('journal_lines')->join('journal_entries'...)->join('accounts'...)`,
  filtered by `accounts.system_purpose`, `journal_entries.status='posted'`,
  `whereNotNull('journal_lines.partner_id')`, returning
  `SUM(debit) - SUM(credit)` (line 153). No `partners` table reference.
- `getControlAccountBalance` — `PartnerBalanceService.php:163-179`. Query is
  `DB::table('journal_lines')->join('journal_entries'...)` filtered by
  `journal_lines.account_id = <control account>` and `status='posted'`, returning
  `SUM(debit) - SUM(credit)` (line 174). No `partners` table reference.
- `reconcileSubledger` — `PartnerBalanceService.php:190-217`. It only calls the two
  methods above (lines 193, 195), computes `bcsub(control, subledger, 4)` (line 196),
  and separately counts `journal_lines` with `partner_id IS NULL` (lines 200-206).
  **Pure GL-vs-GL. Zero cache reads.**

Reconciliation is therefore **structurally immune** to the cache convention. This is
correct accounting design: subledger-to-control reconciliation must be computed from
the ledger, never from a denormalized projection — otherwise the projection could
"agree with itself" and mask drift. Confirmed by the test
`test_subledger_matches_control_account` (`tests/Feature/Accounting/PartnerBalanceServiceTest.php:124-136`)
and `test_subledger_detects_entries_without_partner` (137-188), both of which assert
against GL state only.

---

## 1. GL sign conventions (per create*JournalEntry)

Convention model: there is **no explicit normal-balance metadata** on `Account` or
`SystemAccountPurpose` (grep for `normalBalance`/`isDebitNormal` returns nothing).
Normal balance is implicit in which leg each `create*` method puts `partner_id` on
and whether that leg is the debit or credit column. The balanced-entry property is
enforced only by construction (each method writes matched debit/credit legs); there
is **no runtime balanced-entry assertion** — see Finding F4.

Partner-bearing legs and their sign (all amounts written as positive magnitudes into
either the `debit` or `credit` column):

| Method | file:line of partner leg | Account purpose | Side | Effect on `SUM(debit)-SUM(credit)` |
|---|---|---|---|---|
| `createFromInvoice` | GLS:83-91 | CustomerReceivable | **Debit** total | + receivable (they owe more) |
| `createFromCreditNote` | GLS:181-189 | CustomerReceivable | **Credit** total | − receivable |
| `createPaymentEntry` | GLS:239-247 | (AR via arg) | **Credit** | − receivable |
| `createPaymentReceivedJournalEntry` | GLS:596-605 | CustomerReceivable | **Credit** | − receivable |
| `createPaymentToleranceJournalEntry` (under) | GLS:676-684 | CustomerReceivable | **Credit** | − receivable |
| `createPaymentToleranceJournalEntry` (over) | GLS:688-696 | CustomerReceivable | **Debit** | + receivable |
| `clearCustomerAdvanceToReceivable` | GLS:759-778 | Advance **Debit** / AR **Credit** | both partner | − advance, − receivable |
| `createCustomerAdvanceJournalEntry` | GLS:308-316 | CustomerAdvance | **Credit** amount | − (debit−credit) → magnitude grows on credit side |
| `createPOSChargeEntry` | GLS:1319-1327 | CustomerReceivable | **Debit** total | + receivable |
| `createSupplierInvoiceJournalEntry` | GLS:462-470 | SupplierPayable | **Credit** total | − (debit−credit) |
| `createSupplierPaymentJournalEntry` | GLS:517-525 | SupplierPayable | **Debit** | + (debit−credit) → reduces magnitude |
| `reverseSupplierAdvanceJournalEntry` | GLS:376-384 | SupplierAdvance | **Credit** | − supplier advance (asset) |

**Receivable (asset, debit-normal):** consistently positive = customer owes us.
`getCustomerReceivableBalance` = `debit − credit` (PBS:62, 75-80). Correct and
internally consistent across every AR method. ✅

**CustomerAdvance (liability, credit-normal):** an advance is written as a **credit**
(GLS:314). Therefore `getPartnerBalance(...CustomerAdvance)['balance'] = debit − credit`
is **negative** for a real outstanding advance (PBS:85-90). This signed-negative value
is what `refreshPartnerBalance` currently stores into `partners.credit_balance`
(PBS:312-316, 326). **This is the root inconsistency — see F1.**

**SupplierPayable (liability, credit-normal):** `getSupplierPayableBalance` = `debit − credit`
(PBS:95-100), which is **negative** when we owe the supplier. But every reader of
`payable_balance` treats it as a positive "what we owe" magnitude (Partner net_balance
accessor returns it raw at Partner.php:282; PartnerController aggregates `SUM(payable_balance)`
as `total_payable` at PartnerController.php:112-115). **Same latent sign bug as
credit_balance, but not in the stated flip scope — see F2.**

---

## 2. The proposed `credit_balance` flip and reader coherence

> Proposed: `refreshPartnerBalance` stores `credit_balance = max(0, credit − debit)`
> (non-negative magnitude), while receivable/payable stay `debit − credit` signed.

**Verdict: the flip makes the cache CORRECT and brings it into agreement with every
existing reader. The current signed-negative storage is the bug; the flip fixes it.**

Every consumer of `credit_balance` already assumes a **non-negative magnitude that is
subtracted from receivable**:

1. `Partner::getNetBalanceAttribute()` — `Partner.php:274-283`:
   `bcsub(receivable_balance, credit_balance)`. Only correct if `credit_balance ≥ 0`.
   Today, with `credit_balance` stored negative, this computes
   `receivable − (−advance) = receivable + advance` → **net balance is inflated by
   twice the advance**. The flip fixes this.
2. `PartnerController` list query — `PartnerController.php:96`:
   `selectRaw('*, (receivable_balance - credit_balance) AS net_balance')`. Same
   subtraction; same assumption; same bug today; fixed by flip.
3. `PartnerController` `has_balance` filter — `PartnerController.php:72`:
   `whereRaw('(receivable_balance - credit_balance) != 0')`. Same.
4. `FiscalPayloadConstraintValidator::validateAccountChargeBalanceSnapshot` —
   `FiscalPayloadConstraintValidator.php:1495-1503`. The **device** computes
   `projected_net_balance_after`, and the server recomputes
   `expectedNet = bcsub(projectedReceivable, projectedCredit)` then floors at zero
   (`if expectedNet < 0 → 0`, lines 1498-1500). This confirms the canonical contract
   is **credit as a non-negative magnitude subtracted from receivable, net floored at
   zero** — exactly the flip's convention. The device side and the server snapshot
   guard are already written to the post-flip convention; the cache writer is the
   only place still on the old (wrong) convention.
5. `RecordCustomerDepositService` — `RecordCustomerDepositService.php:119-121`:
   returns `credit_balance` raw and `net_balance` (the accessor). Inherits whatever
   the cache stores. Post-flip these become correct.
6. `PartnerData` DTO / `PosCustomerMirrorResource` / `RecordCustomerDepositResult` —
   pass-through serializers; they re-expose whatever is stored. Post-flip these
   surface the correct magnitude to POS mirror and admin.

**No reader anywhere expects a signed-negative `credit_balance`.** Searched all
`.php` under `app/` and `database/` — the only writer is `refreshPartnerBalance`
(PBS:327); every other reference is a subtract-from-receivable consumer or a
pass-through. The flip is safe and is the correct fix.

**Caveat on `max(0, …)`:** flooring at zero discards a *negative net advance*
(i.e. the advance account went net-debit, e.g. over-applied via
`clearCustomerAdvanceToReceivable`). In a healthy ledger that should not happen, but
silently clamping hides a real GL anomaly. Recommend storing the true magnitude and
asserting non-negativity (raise/log if `credit − debit < 0`) rather than clamping —
see Hardening H3. Note the validator at FPCV:1498-1500 already floors net at zero, so
the floor exists at two layers; double-flooring is harmless but the underlying
negative should be surfaced, not swallowed.

---

## 3. Bugs and latent issues (file:line)

### F1 — `credit_balance` stored with the wrong sign (active bug) — HIGH
- `PartnerBalanceService::refreshPartnerBalance` writes
  `'credit_balance' => $creditResult['balance']` (PBS:327) where `$creditResult` is
  `getPartnerBalance(...CustomerAdvance)` = `debit − credit` (PBS:62, 312-316).
  For a real advance this is **negative**.
- Every reader subtracts it from receivable assuming it is **positive**
  (Partner.php:278; PartnerController.php:72, 96; FPCV:1497).
- **Impact today:** any customer with an outstanding advance shows an *inflated*
  net balance (`receivable + advance` instead of `receivable − advance`). The "has
  balance" filter and list net column are wrong for these customers. The proposed
  flip is the fix.

### F2 — `payable_balance` has the same sign mismatch (out of stated scope, but same family) — HIGH
- Stored as `debit − credit` (PBS:319-323, 328) → **negative** when we owe the supplier.
- Read as a positive magnitude: `Partner::net_balance` returns `payable_balance` raw
  (Partner.php:282); `PartnerController` exposes `SUM(payable_balance)` as
  `total_payable` (PartnerController.php:112-115) and filters `payable_balance != 0`
  (PartnerController.php:73). A supplier we owe 500 shows `total_payable = -500`.
- If you flip `credit_balance` to a magnitude, **flip `payable_balance` in the same
  change** for a consistent "all cache columns are non-negative magnitudes" rule;
  otherwise you ship a half-converted convention (receivable signed, credit magnitude,
  payable signed-but-read-as-magnitude) that is harder to reason about than either pure
  convention.

### F3 — Cache only reflects POSTED entries, but writers create DRAFT entries — HIGH (eventual-consistency / staleness)
- `refreshPartnerBalance` → `getPartnerBalance` filters
  `journal_entries.status = 'posted'` (PBS:45). But every `create*JournalEntry` writes
  `'status' => JournalEntryStatus::Draft` (e.g. GLS:75, 291, 1312) and then calls
  `refreshPartnerBalance` **immediately** (e.g. GLS:120, 322, 1363). At that moment the
  entry is Draft, so it contributes **nothing** to the cache. Only the POS receipt path
  and Treasury bridge call `postEntry` (ReceiptPaymentService.php:313, 406;
  TreasuryReceiptBridge.php:432). `createFromInvoice`, `createCustomerAdvanceJournalEntry`,
  `createPOSChargeEntry`, `createSupplierInvoiceJournalEntry`,
  `clearCustomerAdvanceToReceivable`, etc. have **no posting step in their flow**.
- `RecordCustomerDepositService.php:108-110` documents this explicitly: "Balances
  reflect POSTED GL; a freshly-drafted customer-advance entry updates them once the
  accounting cycle posts it (eventually consistent)." So `refreshPartnerBalance`
  fired right after a Draft write is a **no-op for the new entry** and the returned
  result reflects only previously-posted state.
- **Impact:** the cache lags GL until a separate posting step runs. The
  `PartnerBalanceUpdated` event (PBS:335-343) is emitted with the *pre-posting* numbers.
  This is independent of the sign flip but compounds it: after the flip, both the sign
  AND the posted-only timing must be understood to predict a partner's displayed balance.
- **Subledger total includes only posted too** (PBS:150), so subledger and cache are at
  least filtered consistently — but `getControlAccountBalance` and `getSubledgerTotal`
  both being posted-only means **Draft entries are invisible to reconciliation as well**,
  which can mask in-flight imbalance. Norm: reconciliation should usually run on posted
  ledger, so this is acceptable, but it should be documented that Draft entries are
  excluded by design.

### F4 — No balanced-entry assertion anywhere — MEDIUM
- Each `create*` method writes matched legs by construction, but nothing verifies
  `SUM(debit) == SUM(credit)` before/at post. `postEntry` (GLS:1094-1132) computes
  `$totalDebit`/`$totalCredit` (lines 1115-1121) purely to populate the
  `JournalEntryPosted` event payload — it **never compares them** and never rejects an
  unbalanced entry. A future code path (or a partial-failure inside the non-atomic
  refresh, see F5) could post a one-legged entry and the hash chain would happily seal it.
- Norm: double-entry systems enforce the balance invariant at post time (or via a DB
  trigger / CHECK on aggregate). Recommend H1.

### F5 — Cache refresh is outside the GL write transaction — MEDIUM (coherence/atomicity)
- In most methods `refreshPartnerBalance` is called **after** the `DB::transaction`
  closure returns (e.g. GLS:119-120, 194-195, 321-322). If the GL commit succeeds but
  the refresh throws (or the worker dies), the GL is correct but the cache is stale
  with no compensating retry. `createPOSChargeEntry` is the exception — it calls
  `refreshPartnerBalance` *inside* the transaction (GLS:1363), which is better for
  atomicity but means a refresh failure rolls back the GL entry too (different
  trade-off, also undocumented). The inconsistency between the two patterns is itself a
  smell.
- Combined with F3 (refresh is a no-op for Draft anyway), the post-write refresh in the
  Draft-creating methods is largely **dead work** today.

### F6 — `getPartnerStatement` running balance is debit-normal regardless of account purpose — MEDIUM
- `PartnerBalanceService.php:268-281`: `running_balance += debit − credit` for every
  line. For an AR statement this is correct (positive = owed). But the same method is
  called for `CustomerAdvance` / `SupplierPayable` purposes (the `$purpose` arg is
  generic, PBS:227, 251-253), where a credit-normal running balance would be expected
  to **rise** as the liability grows. As written, a supplier statement's running
  balance goes **negative** as we owe more — the opposite of an AP aging statement's
  convention. No caller currently passes a liability purpose, but the method's contract
  invites it. At minimum document "running balance is always debit−credit (asset
  convention)"; ideally sign it by the account's normal balance.

### F7 — No DB-level guard on cache columns and no reconciliation job — MEDIUM
- Migration `2025_12_06_100001_add_balance_fields_to_partners.php:18-27` declares plain
  `decimal(15,4) default 0` with **no CHECK constraint** and a comment (line 17) that
  still describes the *old* signed convention ("negative = we owe them"). After the flip
  the comment will be stale/contradictory.
- No scheduled command/job calls `refreshAllPartnerBalances` or `reconcileSubledger`
  (grep for job/command/console references returns none). `refreshAllPartnerBalances`
  exists (PBS:350-363) but nothing invokes it on a schedule, so a stale cache (from F5)
  never self-heals except via the 60-minute staleness check in
  `getCachedOrCalculateBalance` (PBS:385) — and that only triggers on read, per partner.

---

## 4. Comparison to accounting-system norms

- **Subledger-to-control reconciliation must be ledger-derived, not projection-derived.**
  ✅ This system gets it right: `reconcileSubledger` is pure GL-vs-GL (PBS:142-217). The
  cache is never an input to reconciliation — exactly as it should be.
- **The cache should be a pure projection of the GL.** ⚠️ It is *mostly* a projection,
  but (a) it stores a different sign convention than the GL it projects (F1/F2), (b) it
  is refreshed imperatively and out-of-transaction (F5), and (c) the refresh is a no-op
  against Draft entries it is fired alongside (F3). A true projection would be
  rebuilt from a single GL query and would be reproducible by replaying
  `refreshAllPartnerBalances`.
- **Normal-balance should be first-class metadata.** ✗ Absent. Sign correctness is
  spread implicitly across each `create*` method and each reader, which is precisely
  how F1/F2/F6 arise. Norms (and most ERP ledgers) attach a debit/credit normal-balance
  flag to the account/account-type and derive display sign from it.
- **Balanced-entry invariant enforced at post.** ✗ Not enforced (F4). Norm is a hard
  invariant, often a DB trigger.

---

## 5. Prioritized hardening

**P0 — Make the flip, and make it complete and reversible**
- **H0 (the flip itself):** store `credit_balance` as a non-negative magnitude. Prefer
  `credit − debit` with an explicit non-negativity *assertion/log* over a silent
  `max(0, …)` clamp (clamping hides a genuine GL anomaly; see §2 caveat and H3). Apply
  the **same** treatment to `payable_balance` in the same change (F2) so the rule is
  uniform: "all three cache columns are non-negative magnitudes; receivable is the only
  one that can legitimately be negative if you keep it signed — pick one rule and state
  it." Update the migration comment (F7) or add a follow-up migration comment so the
  schema documents the post-flip convention.

**P0 — Property/characterization tests locking the convention**
- **H2:** add property tests asserting, for a randomized sequence of invoices /
  payments / advances / advance-applications:
  1. `partner.credit_balance >= 0` and `payable_balance >= 0` always;
  2. `partner.net_balance == max(0, receivable − credit)` matches the
     `FiscalPayloadConstraintValidator` floor (FPCV:1497-1500) — i.e. server accessor
     and device-snapshot guard agree;
  3. `reconcileSubledger(...)['is_balanced'] === true` for CustomerReceivable,
     CustomerAdvance, and SupplierPayable after each step (proves the flip did not
     perturb reconciliation — this is the regression guard for the stated concern);
  4. `getNetBalanceAttribute` equals the `PartnerController` SQL
     `(receivable_balance - credit_balance)` for the same row (PHP accessor vs. SQL
     must not diverge).

**P1 — Enforce the double-entry invariant**
- **H1:** in `postEntry` (GLS:1094-1132), after summing legs (already computed at
  GLS:1115-1121), reject when `bccomp($totalDebit, $totalCredit, scale) !== 0` before
  sealing the hash. Optionally back it with a deferred PG CHECK / trigger asserting
  per-entry `SUM(debit)=SUM(credit)` on `status='posted'` rows (note the PG-vs-SQLite
  trigger caveat in repo memory — guard the migration for PG only).

**P1 — Close the projection/atomicity gaps**
- **H3:** in `refreshPartnerBalance`, after computing the advance magnitude, assert
  `credit − debit >= 0` (and `payable` likewise); if violated, log + emit a metric
  rather than clamp, so a genuinely net-debit advance/payable surfaces instead of
  being masked.
- **H4:** decide one transaction discipline for cache refresh and apply it everywhere
  (F5). Either always-inside-transaction (like `createPOSChargeEntry` GLS:1363) or
  always-after-with-a-retry/outbox. Given F3 (refresh is a no-op against Draft),
  the cleaner fix is to **drive `refreshPartnerBalance` off the `postEntry` /
  `JournalEntryPosted` event** (so the cache updates exactly when posted GL changes),
  and drop the immediate post-Draft-write refresh calls that do nothing today.

**P2 — Operational reconciliation & projection purity**
- **H5:** add a scheduled console command that runs `reconcileSubledger` per control
  account per company and alerts on `is_balanced === false` or
  `entries_without_partner > 0` (the data already exists, PBS:208-216). This is the
  standard nightly subledger tie-out.
- **H6:** add a `partners:rebuild-balances` command wrapping `refreshAllPartnerBalances`
  (PBS:350-363) so the cache is reproducibly rebuildable — making it a true projection
  and giving F5/F3 a self-heal path.
- **H7 (statement sign):** document or fix `getPartnerStatement` running balance to be
  normal-balance-aware (F6) before any caller passes a liability purpose.

---

## 6. Summary table of findings

| ID | Severity | One-liner | Anchor |
|----|----------|-----------|--------|
| F1 | HIGH | `credit_balance` stored signed-negative; all readers expect non-negative magnitude (flip fixes it) | PBS:327 vs Partner.php:278, PartnerController.php:72/96, FPCV:1497 |
| F2 | HIGH | `payable_balance` has the same sign mismatch; flip it too | PBS:328 vs Partner.php:282, PartnerController.php:112-115 |
| F3 | HIGH | Cache reflects only POSTED entries, but writers create DRAFT + refresh immediately (no-op) | PBS:45 vs GLS:75/120, RecordCustomerDepositService.php:108-110 |
| F4 | MED | No balanced-entry assertion; `postEntry` computes but never compares totals | GLS:1115-1121 |
| F5 | MED | Refresh runs outside the GL transaction (except POSCharge); failure → silent stale cache | GLS:119-120 vs GLS:1363 |
| F6 | MED | `getPartnerStatement` running balance is debit-normal for all purposes | PBS:268-281 |
| F7 | MED | No CHECK constraint, stale migration comment, no scheduled reconcile/rebuild job | migration:17-27 |

**Reconcile cache-read answer (restated):** `reconcileSubledger`, `getSubledgerTotal`,
and `getControlAccountBalance` read **only `journal_lines`** (PBS:142-158, 163-179,
190-217). The `credit_balance` sign flip is invisible to reconciliation and **cannot**
produce false reconciliation failures.
