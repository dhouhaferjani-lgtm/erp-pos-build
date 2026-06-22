# Balance Conventions Audit — 04: Industry Standards & Hardening

> Audit date: 2026-06-22 · Scope: how AutoERP models customer/supplier credit & debit balances
> (store credit, advances/prepayments, AR/AP, credit limits) vs. accepted accounting/ERP practice,
> and a prioritized hardening backlog for a multi-tenant, multi-vertical, compliance ERP.
> **Read-only audit — no code was modified.**

This is part 04 of the balance-conventions audit series. It assumes the locked decision:
`partners.credit_balance` is a **non-negative magnitude**; `net = receivable − credit`; the immutable
fiscal payload enforces non-negative money strings.

---

## 0. Executive summary

AutoERP already does the **architecturally correct** thing in the place that matters most: balances
are derived from a **double-entry General Ledger subledger** (`journal_lines.debit` / `journal_lines.credit`
keyed by `partner_id` + `accounts.system_purpose`), with the per-partner cached columns on `partners`
explicitly documented as a *denormalized cache whose source of truth is the GL*
(`PartnerBalanceService`, migration `2025_12_06_100001_add_balance_fields_to_partners.php:14-15`).
It has a real **subledger ↔ control-account reconciliation** routine
(`PartnerBalanceService::reconcileSubledger`), standard **30/60/90 AR/AP aging**
(`AgedReceivablesService` / `AgedPayablesService`), an **all-string bcmath money pipeline**
(`CurrencyScale`, the precision contract), and an **immutable fiscal payload** that stores
non-negative money magnitudes and re-derives projected balances
(`FiscalPayloadConstraintValidator`, `AccountChargeBalanceSnapshotDTO`, `AccountPaymentBalanceSnapshotDTO`).

The customer-advance / prepayment liability is modeled correctly at the GL level
(`GeneralLedgerService::createCustomerAdvanceJournalEntry` credits a `CustomerAdvance` **liability**
account; `clearCustomerAdvanceToReceivable` reclassifies it) — this matches IFRS 15 / ASC 606
contract-liability treatment and how SAP/Odoo/NetSuite model customer credit balances.

The locked **non-negative-magnitude** convention for `credit_balance` is **the right industry-aligned
choice** for a *cached, presentation/fiscal-facing* field — it matches how mature ERPs present a
"customer credit available" figure and matches the unsigned-magnitude best practice for denormalized
caches (the signed double-entry truth stays in the GL). **However, the implementation does not yet
enforce the magnitude convention**, and that is the single most important hardening finding:

- **Defect (HIGH):** the cache-refresh path stores the *raw signed* GL result
  `SUM(debit) − SUM(credit)` of the `CustomerAdvance` account straight into `credit_balance`
  (`PartnerBalanceService::refreshPartnerBalance:311-316` ← `getPartnerBalance:62`). Because advances
  are **credited** to a liability account, that subledger balance is **negative**
  (`createCustomerAdvanceJournalEntry:307-316`). So the column the locked decision and the fiscal
  payload treat as a **non-negative magnitude** is actually populated with a **negative signed value**,
  and `net = receivable − credit_balance` (`Partner::getNetBalanceAttribute:278`) then *adds* the credit
  instead of subtracting it. There is **no DB CHECK constraint, no domain guard, and no test** pinning
  the sign. The fiscal layer's non-negative money regex (`moneyRegex`) would *reject* a negative
  string, so the cache and the canonical payload currently disagree on sign semantics.

The hardening backlog (§4) turns the locked decision into enforced invariants: a normalization step at
the cache boundary, DB CHECK constraints, a sign-pinning test matrix, a scheduled reconciliation job
that fails loudly on subledger ≠ control drift, type-level encoding of "money magnitude vs signed ledger
value," and per-currency balance storage before multi-currency partners ship.

---

## 1. AR/AP subledger vs GL control account; open-item vs balance-forward

### Industry standard
A subsidiary ledger (AR/AP subledger) holds one running balance per customer/supplier; a single
**control account** in the GL holds the aggregate. The cardinal control is that **the sum of the
subsidiary ledger must equal the control-account balance** at all times, and any drift is a
reconciliation exception to investigate. [[1]](#ref1) [[2]](#ref2)

**Open-item** accounting keeps every invoice/payment as an individually trackable item that is
explicitly matched (applied) — the balance is the sum of *unsettled items*; this is what AR/AP and
most B2B ERPs use because it supports aging, dunning, and partial allocation. **Balance-forward**
accounting carries a single rolling balance and is typical of consumer/utility statements where
line-level matching isn't needed. [[3]](#ref3) [[4]](#ref4)

### How AutoERP conforms
- **Subledger from double-entry GL.** `PartnerBalanceService::getPartnerBalance` derives a partner's
  balance as `SUM(journal_lines.debit) − SUM(journal_lines.credit)` for posted entries on accounts of
  a given `system_purpose`, keyed by `partner_id`
  (`PartnerBalanceService.php:40-70`). This *is* the subsidiary-ledger total per partner.
- **Explicit control reconciliation.** `reconcileSubledger` compares `getSubledgerTotal` (sum over all
  partners) to `getControlAccountBalance` (the account total, partnered + unpartnered) and reports
  `difference`, `is_balanced`, and the count of `entries_without_partner` — the exact root-cause hint
  for subledger drift (`PartnerBalanceService.php:190-217`). This is textbook subledger↔control control.
- **Open-item-style statement.** `getPartnerStatement` returns per-entry debit/credit lines with a
  running balance (`PartnerBalanceService.php:224-282`), and aging is computed per outstanding invoice
  (`AgedReceivablesService`), i.e. open-item, not balance-forward — the correct choice for a B2B ERP.

### Where it diverges / gaps
- **Reconciliation is on-demand only.** `reconcileSubledger` exists but there is no scheduled job that
  runs it per company/purpose and alerts on `is_balanced === false`. Mature ERPs run this at period
  close; a multi-tenant ERP should run it continuously per tenant. (Backlog H3.)
- **`status = 'posted'` is a magic string.** `getPartnerBalance` filters `journal_entries.status`
  with the literal `'posted'` (`:45`) rather than the `JournalEntryStatus` enum — violates the
  "enums for all status columns / no magic strings" rule and risks silent drift if the enum value
  changes. (Backlog M-misc.)
- **Cached vs live can silently diverge.** The cache is refreshed imperatively after each GL write
  (`refreshPartnerBalance`) and lazily when stale (`getCachedOrCalculateBalance`, 60-min TTL). There is
  no invariant test that `cached == live` after an arbitrary sequence of postings. (Backlog H3/M2.)

---

## 2. Customer advances / prepayments / store credit / gift credit — liability treatment

### Industry standard
Cash received before the related performance obligation is satisfied is **not revenue** and **not a
reduction of an asset** — it is a **liability** (a *contract liability* / deferred revenue under
IFRS 15 and ASC 606; "customer advances," "deposits," "unearned revenue"). It is recognized as revenue
only as/when the obligation is satisfied. [[5]](#ref5) [[6]](#ref6) [[7]](#ref7) [[8]](#ref8)
Gift cards and store credit are the canonical example of a contract liability (with breakage estimation
for unredeemed balances). [[9]](#ref9)

A **customer credit balance** — a customer whose AR nets *negative* because of an overpayment or a
refund-issued-as-credit — should be **reclassified out of the AR (asset) control account into a
liability** ("customer with a credit balance" / "credit balances in AR"), because presenting a
liability as a negative asset misstates the balance sheet. [[10]](#ref10) [[11]](#ref11)

### How AutoERP conforms
- **Advance is a liability with a partner subledger.** `createCustomerAdvanceJournalEntry`:
  Dr Bank / **Cr CustomerAdvance (liability)** with `partner_id` on the liability line for subledger
  tracking, and the docblock states "Advance payments create a liability (we owe the customer until
  invoice issued)" (`GeneralLedgerService.php:261-325`). This is exactly the IFRS 15 contract-liability
  posting.
- **Correct reclassification on invoicing.** `clearCustomerAdvanceToReceivable`:
  Dr CustomerAdvance / Cr AR — clearing the liability against the new receivable
  (`GeneralLedgerService.php:719-786`). This is the standard "apply prepayment to invoice" flow.
- **Separate purposes for separate semantics.** `SystemAccountPurpose::CustomerReceivable` (asset),
  `CustomerAdvance` (liability), `SupplierPayable`, `SupplierAdvance` are distinct GL purposes, so the
  asset and the liability are never commingled in the GL — the balance sheet stays correct. The
  per-partner cache splits them into `receivable_balance` vs `credit_balance` accordingly.

### Where it diverges / gaps
- **The cache loses the asset/liability distinction at the sign level.** As noted in §0, the
  `credit_balance` column is meant to be the *magnitude* of the customer-advance liability, but it is
  populated with the raw signed GL figure (negative). The GL itself is correct; the **denormalized
  presentation/fiscal layer is the leak**. (Backlog H1.)
- **No breakage / stale-credit policy.** Store-credit/gift-credit breakage (unredeemed balances) and
  an expiry/escheatment policy are not modeled. Acceptable pre-launch, but a multi-vertical retail ERP
  (IziPOS) will need it. (Backlog L1.)
- **Refund-as-store-credit lifecycle.** The deposit/credit issue path exists
  (`RecordCustomerDepositService`), but there is no first-class "store credit" object distinct from
  "advance on an order"; both ride the same `CustomerAdvance` liability. That is *acceptable*
  (single liability, partner subledger) but should be a documented, deliberate decision. (Backlog L2.)

---

## 3. How major ERPs model "customer credit balance" / on-account sales — and what AutoERP should adopt

### Industry standard (cross-system synthesis)
- **SAP FI-AR** uses **special G/L indicators** to post down payments/advances to a *separate*
  reconciliation (control) account from normal AR, so an advance never nets against a customer's open
  receivables until explicitly cleared; a customer can simultaneously carry a debit (AR) and a credit
  (advance) balance. [[12]](#ref12) [[13]](#ref13)
- **NetSuite** distinguishes a **Customer Deposit** (liability, money received against a sales order
  not yet invoiced) from a **Customer Credit / credit memo** (reduces AR), and keeps the deposit as a
  liability until applied. [[14]](#ref14) [[15]](#ref15)
- **Odoo** tracks **outstanding credits/payments** that are *matched* (reconciled) against invoices;
  an unapplied customer payment shows as a credit the customer can draw down — open-item matching.
  [[16]](#ref16)
- **QuickBooks** uses **credit memos / unapplied payments** that sit as an available credit and are
  later applied to invoices. [[17]](#ref17)
- **Dynamics 365** carries customer balance + **prepayments/deposits** posted to dedicated posting
  accounts. [[18]](#ref18)

The consistent pattern: **keep the receivable (asset) and the advance/credit (liability) in separate
accounts, track both per partner, and present a derived "net" or "credit available" without
commingling the underlying signed ledger values.**

### How AutoERP conforms
AutoERP's design matches this pattern well: separate `CustomerReceivable` / `CustomerAdvance` GL
purposes, both partner-keyed, with a derived `net_balance` and a derived `credit_available` decision
in the fiscal payload (`AccountChargeCreditDecisionDTO`: `creditAvailableBefore/After`, `creditLimit`,
`limitExceeded`). The on-account ("charge") sale is a first-class fiscal event with its own canonical
DTO family (`AccountChargeView`, `AccountChargeBalanceSnapshotDTO`, `AccountChargeCreditDecisionDTO`,
`AccountChargeTermsDTO`) and a hard payload guard that **forbids a `payments` key anywhere on an
`ACCOUNT_CHARGE`** (`FiscalPayloadConstraintValidator::rejectAccountChargePaymentsKeyRecursively`) —
i.e. a charge sale increases AR and is settled later, never paid at the till. This is a clean,
standards-aligned on-account model.

### Where it diverges / gaps
- **"Credit available" is derived but `credit_balance` sign is unenforced** (see §0/§2). The decision
  DTO's `creditAvailableBefore/After` rely on a correctly-signed magnitude. (Backlog H1/H2.)
- **No explicit "deposit vs credit" type split.** Like NetSuite's Deposit-vs-Credit distinction, it
  could help to tag whether a `CustomerAdvance` line originated from an order deposit vs a
  refund-as-store-credit, for reporting and breakage. Optional. (Backlog L2.)

---

## 4. Sign convention: unsigned magnitude + explicit semantics vs signed values

### Industry standard
Double-entry systems store **two non-negative columns** (debit, credit) at the journal-line level —
sign is encoded by *which column* and *which account type the line hits*, not by a `±` on a single
number. This is the canonical ledger representation and is what robust ledger/accounting-DB designs
recommend; a single signed "amount" column is discouraged at the journal level because it loses the
debit/credit semantics that make a trial balance verifiable. [[19]](#ref19) [[20]](#ref20)
[[21]](#ref21) For **denormalized/cached balances** presented to users, the common practice
is to expose **unsigned magnitudes labeled by meaning** ("you owe", "credit available") rather than a
raw signed number that the UI must interpret. [[22]](#ref22) [[23]](#ref23)

### How AutoERP conforms
- **GL is correct: two non-negative columns.** `journal_lines.debit` and `journal_lines.credit` are
  separate columns; sign is `debit − credit` interpreted against `accounts.system_purpose`
  (`PartnerBalanceService::getPartnerBalance`). This is the canonical, verifiable representation.
- **FormRequest layer already endorses unsigned-with-explicit-semantics.** The precision contract
  states money regex is `^\d` (no `-?`) for non-negative fields, and `-?` is allowed *only* where
  negatives are legitimate — "journal debit/credit, price_adjustment, stock deltas"
  (`docs/architecture/precision-contract.md:34`). The locked `credit_balance` magnitude decision is
  a direct application of this principle.
- **Fiscal payload enforces non-negative magnitudes.** `moneyRegex(scale)` returns
  `/^(0|[1-9]\d*)\.\d{N}$/` — **no minus sign, no leading zeros** — so every money string in the
  immutable canonical payload (including `credit_balance_before`, `projected_credit_balance_after`)
  is structurally non-negative (`FiscalPayloadConstraintValidator::moneyRegex:2590`). The net is
  floored at zero (`validateAccountChargeBalanceSnapshot` clamps `expectedNet` to ≥ 0,
  `:1497-1500`). This is the locked convention, **enforced in the payload**.

### Where it diverges / gaps — the core finding
- **The cache column does not honor the convention it is documented to follow.** The migration
  comments say `credit_balance` is "advance payments/credits (what we owe them)" — a magnitude
  framing — but stores `decimal(15,4)` signed with no CHECK, and is populated with the negative GL
  figure (§0). So three layers disagree:
  1. **GL truth:** advance liability balance is **negative** when expressed as `debit − credit`.
  2. **Locked decision + fiscal payload:** `credit_balance` is a **non-negative magnitude**.
  3. **Model math:** `net = receivable − credit_balance` (`Partner.php:278`) is only correct if
     `credit_balance` is a **positive magnitude**; fed the negative GL figure it computes the wrong net.
  This must be resolved by **normalizing at the cache boundary** (store `abs()` / negate the liability
  figure once, in `refreshPartnerBalance`) and **pinning it with a CHECK constraint + tests**.
  (Backlog H1, H2.)

**Verdict on the locked decision:** *Sound and industry-aligned.* Storing `credit_balance` as a
non-negative magnitude with explicit semantics (`net = receivable − credit`) is the correct choice for
a denormalized, presentation/fiscal-facing field — it matches the unsigned-magnitude best practice and
mirrors how SAP/NetSuite/Odoo present "credit available." Keep the **signed** truth in the GL
(two-column debit/credit) and the **unsigned magnitude** in the cache. The decision is right; it just
needs to be *enforced* (the current code silently violates it).

---

## 5. Credit-limit enforcement, AR aging, deposit/refund lifecycle

### Industry standard
Credit limits are enforced at order/charge time by comparing `open_AR + new_charge − available_credit`
against the limit, with override workflows for authorized users; AR aging buckets (current/30/60/90+)
drive dunning and credit decisions. [[24]](#ref24) [[25]](#ref25) [[26]](#ref26)

### How AutoERP conforms
- **Credit limit is a first-class fiscal concept.** `partners.credit_limit` (`decimal:4`),
  `Partner::hasActiveCreditLimit()` (bcmath compare, `Partner.php:212-219`), the charge decision DTO
  (`AccountChargeCreditDecisionDTO`: `creditLimit`, `limitExceeded`, `creditAvailableBefore/After`,
  `decision`, `warnings`), and an **override fiscal event** with its own payload
  (`OverrideCreditLimitPayload`) — i.e. limit breaches are auditable, signed, and overridable. Strong.
- **Standard 30/60/90 aging.** `AgedReceivablesService` / `AgedPayablesService` produce
  current/31-60/61-90/over-90 buckets per partner (`AgedReceivablesService.php:20-24`). Matches
  industry practice.

### Where it diverges / gaps
- **Limit check consumes the (possibly mis-signed) cache.** The credit decision relies on
  `credit_available`, which derives from `credit_balance`; if the magnitude is mis-signed (§0/§4) the
  limit math is wrong. Fixing H1 is a prerequisite for trustworthy limit enforcement. (Backlog H1.)
- **Staleness is handled but not hard-failed.** The charge payload carries `mirrorStaleAtAuthoring` /
  `stalePolicyAction` (`AccountChargeCreditDecisionDTO`), which is good (offline-aware), but the policy
  for "stale balance → allow charge" should be explicitly tested per vertical. (Backlog M3.)

---

## 6. Multi-currency balance storage

### Industry standard
Store amounts in their **transaction currency** plus a converted **functional/reporting currency**;
**never sum balances across currencies**; AR/AP per partner is typically tracked per currency (a
partner can owe in EUR and TND simultaneously). [[27]](#ref27) [[28]](#ref28)

### How AutoERP conforms (partially) / gaps
- **Per-currency scale is solved.** `CurrencyScale` encodes ISO-4217 minor units (TND=3, JPY=0,
  default 2) and the money pipeline is scale-aware end to end.
- **GAP — the cached balance columns are currency-agnostic.** `receivable_balance` / `credit_balance`
  / `payable_balance` are single scalar columns with **no `currency_code`** and `decimal(15,4)` —
  they implicitly assume one currency per partner. If a partner transacts in two currencies the GL
  is still correct (per journal line), but the cache would **sum across currencies** into a
  meaningless scalar, and `decimal(...,4)` is wrong for a 0-dp currency at the boundary. Before
  multi-currency partners ship, the cache must become per-currency (a `partner_currency_balances`
  child table, or JSONB keyed by currency). (Backlog M1 — must precede multi-currency rollout.)

---

## 7. Money representation

### Industry standard
Never use binary floats for money. Two accepted representations: **integer minor units** (cents) or
**fixed-scale decimals**; combine an amount with its currency (Fowler's Money pattern) and use the
ISO-4217 minor-unit exponent per currency. [[29]](#ref29) [[30]](#ref30) [[31]](#ref31)

### How AutoERP conforms — strong
- **All-string bcmath, no floats.** `CurrencyScale::bcformat/bcformatStrict/bcround/bcformatOrNull`
  operate on `numeric-string` via bcmath; `bcformatStrict` rejects non-numeric input; `bcround`
  documents half-away-from-zero and NC 01 §62 (Tunisia) "no rounding in recording." Balances are
  `numeric-string` properties cast `decimal:4` (`Partner.php`), payloads are validated money strings.
- **Per-currency scale + ceiling regex.** The precision contract pairs `numeric` validation with a
  scale-ceiling regex per column and forbids `parseFloat`/`Number()` on money in the frontend, with
  PHPStan + ESLint guards. This is best-in-class for a compliance ERP.

### Minor gaps
- **Storage scale is a fixed 4 dp, not currency-driven.** The cache columns are `decimal(15,4)`; the
  precision contract's "currency `decimal(N,3)` floor" implies 3-dp currency storage, yet balances are
  4-dp. For a 0-dp currency (JPY) or a 3-dp currency (TND) a 4-dp store is harmless for *internal*
  precision but should be **rounded to the currency scale at the read/display/fiscal boundary** (it is,
  via `CurrencyScale::bcformat(..., $scale)` in `RecordCustomerDepositService::balance`). Document this
  explicitly. (Backlog L3.)
- **No `currency_code` companion** on the cached balance (see §6).

---

## 8. Conformance scorecard

| Area | Standard | AutoERP status |
|---|---|---|
| AR/AP subledger from double-entry GL | Required | ✅ Conforms (`PartnerBalanceService`) |
| Subledger ↔ control reconciliation | Required | 🟡 Routine exists, not scheduled/alerting |
| Open-item (not balance-forward) | B2B norm | ✅ Per-invoice aging + statement |
| Advance/prepayment as contract liability | IFRS 15 / ASC 606 | ✅ GL is correct (`CustomerAdvance`) |
| Credit balance kept out of AR asset | Required | ✅ Separate GL purposes |
| Two-column debit/credit at journal level | Canonical | ✅ `journal_lines.debit/credit` |
| Unsigned magnitude in cache + explicit semantics | Best practice | 🔴 Decided but **not enforced** (mis-signed) |
| Non-negative money in immutable payload | Compliance | ✅ `moneyRegex` forbids `−` |
| Credit-limit enforcement + override audit | Required | ✅ Strong (DTO + override event) |
| 30/60/90 AR/AP aging | Required | ✅ Aging services |
| Multi-currency per-currency balances | Required (at scale) | 🔴 Cache is currency-agnostic scalar |
| No-float money (bcmath/decimal) | Required | ✅ Best-in-class |
| Money + currency scale (ISO-4217) | Best practice | ✅ `CurrencyScale` |

---

## 9. Prioritized hardening backlog

Priorities: **HIGH** = correctness/compliance risk now; **MED** = needed before more verticals/tenants
or multi-currency; **LOW** = polish/feature.

### HIGH

**H1 — Normalize `credit_balance` to a non-negative magnitude at the cache boundary.**
In `PartnerBalanceService::refreshPartnerBalance`, the value written to `credit_balance` (and the sign
used in `net`) must be the **magnitude** of the `CustomerAdvance` liability, not the raw
`debit − credit` (which is negative). Options: store `bcmul(getCustomerAdvanceBalance, '-1')` (negate
the liability), or compute the advance balance as `credit − debit` for liability purposes. Apply the
same review to `payable_balance` (supplier liability — likely also negative-as-stored). Add a single
"sign normalization per account-nature" helper so it can't drift again.
*Files:* `PartnerBalanceService.php:288-344`, `getPartnerBalance:62`, `Partner::getNetBalanceAttribute:278`.

**H2 — DB CHECK constraints + domain guard pinning the magnitude convention.**
Add tenant-DB migration CHECK constraints: `credit_balance >= 0`, `receivable_balance` /
`payable_balance` per their decided sign (and `credit_limit >= 0`). Mirror with a domain-level guard
(an immutable Money/Magnitude value object — see M4) so a bad write fails fast in PHP and in PG. This
turns the "locked decision" into an enforced invariant. *Note the PG-vs-SQLite gotcha*: a CHECK is a
constraint on PG; the SQLite Unit suite won't catch a PG-only failure — verify on real PG.

**H3 — Scheduled subledger↔control reconciliation job with alerting.**
Wrap `reconcileSubledger` in a per-tenant, per-company, per-`SystemAccountPurpose` scheduled command
that records results and **alerts (and ideally blocks period close) when `is_balanced === false`** or
`entries_without_partner > 0`. This is the single biggest scale safeguard for many tenants — drift in
one tenant must surface automatically, not at audit time. *New:* console command + Horizon schedule +
a `subledger_reconciliations` audit table (or TimescaleDB log).

### MED

**M1 — Per-currency cached balances (precede multi-currency partners).**
Replace the three scalar balance columns with a per-currency representation
(`partner_currency_balances(partner_id, currency_code, receivable, credit, payable, updated_at)` or
JSONB keyed by currency). Never sum across currencies. Until then, add an explicit guard/assert that a
partner's GL lines are single-currency so the scalar cache can't silently aggregate two currencies.

**M2 — Cache-vs-live invariant test + idempotent refresh.**
Property/feature test: after an arbitrary sequence of advance/charge/payment/clear postings,
`refreshPartnerBalance` then assert `cached receivable/credit/payable/net == live GL` for every
purpose, and that a second refresh is a no-op (idempotent). Include the mis-sign regression from H1.

**M3 — Per-vertical credit-limit + staleness policy tests.**
Test the `AccountChargeCreditDecisionDTO` path end-to-end: limit-exceeded blocks vs overrides; stale
mirror (`mirrorStaleAtAuthoring` + `stalePolicyAction`) behaves per the documented offline policy; net
floor-at-zero (`FiscalPayloadConstraintValidator:1497-1500`) holds. Verticals (Otospex B2B vs IziPOS
retail) may need different stale policies — encode and test each.

**M4 — Type-level encoding: `MoneyMagnitude` vs signed `LedgerAmount`.**
Introduce two value objects: a non-negative `MoneyMagnitude` (constructor rejects `< 0`) for cached
balances / payload fields, and a signed `LedgerAmount` for journal-line math. Cast `credit_balance`
etc. to `MoneyMagnitude`. This makes the §4 sign confusion *unrepresentable* and gives PHPStan a hook.
Pairs with H2.

**M5 — Replace the `'posted'` magic string with `JournalEntryStatus`.**
`PartnerBalanceService` filters `journal_entries.status` with the literal `'posted'` in 4+ queries —
use the enum to comply with the no-magic-strings rule and prevent silent balance drift if the value
changes.

### LOW

**L1 — Store-credit/gift-card breakage & expiry policy.** Model unredeemed-credit breakage and an
expiry/escheatment policy for the retail vertical (IziPOS) before gift cards/store credit go GA.

**L2 — Document the "advance vs store-credit" single-liability decision** (and optionally tag the
`CustomerAdvance` line origin: order-deposit vs refund-credit) for reporting/breakage.

**L3 — Document storage-scale vs currency-scale.** Make explicit that balances are stored at 4 dp for
internal precision and rounded to the currency scale only at read/display/fiscal boundaries (already
done via `CurrencyScale::bcformat(..., $scale)`), so future maintainers don't "fix" the 4-dp store.

**L4 — Reconciliation surface in admin UI.** Expose `reconcileSubledger` results per company/purpose
in the admin/accounting UI so operators can self-serve the control check.

---

## 10. References

Primary/authoritative sources are marked **(P)**. A few primary pages (IFRS.org PDF, IFRS Community,
NetSuite, QuickBooks community, TigerBeetle) returned 403/timeout on direct fetch; those quotes were
taken from search-result extracts containing the verbatim text — the IFRS.org PDF remains the canonical
citation for the standard.

**Subledger / control account / open-item vs balance-forward**
1. <a id="ref1"></a>AccountingTools — *Control account*: https://www.accountingtools.com/articles/control-account
2. <a id="ref2"></a>AccountingCoach — *AR control account & subsidiary ledger*: https://www.accountingcoach.com/blog/accounts-receivable-control-account-subsidiary-ledger
3. <a id="ref3"></a>Oracle CC&B docs **(P)** — *Open-item vs balance-forward*: https://docs.oracle.com/en/industries/energy-water/ccb/29013/ccb-user-guides/Topics/C1_03Finan_Open_Item_Versus_Balance_Forward_.html
4. <a id="ref4"></a>Invoice Data Extraction — *Open-item vs balance-forward statement*: https://invoicedataextraction.com/blog/open-item-vs-balance-forward-statement
   - Also: Lumen Learning — *Subsidiary ledgers & control accounts*: https://courses.lumenlearning.com/suny-finaccounting/chapter/subsidiary-ledgers-and-control-accounts/ ; Leapfin — *GL-to-subledger reconciliation*: https://www.leapfin.com/blog/general-ledger-to-subledger-reconciliation

**Customer advances / deposits / store credit — liability treatment**
5. <a id="ref5"></a>IFRS Foundation **(P)** — *IFRS 15 Revenue from Contracts with Customers* (Appendix A; 15.106): https://www.ifrs.org/content/dam/ifrs/publications/pdf-standards/english/2021/issued/part-a/ifrs-15-revenue-from-contracts-with-customers.pdf
6. <a id="ref6"></a>IFRS Community — *IFRS 15 contract assets & contract liabilities*: https://ifrscommunity.com/knowledge-base/ifrs-15-contract-assets-and-contract-liabilities/
7. <a id="ref7"></a>Deloitte DART **(P)** — *ASC 606 §14.2 Contract liabilities*: https://dart.deloitte.com/USDART/home/codification/revenue/asc606-10/roadmap-revenue-recognition/chapter-14-presentation/14-2-contract-liabilities
8. <a id="ref8"></a>PwC Viewpoint **(P)** — *ASC 606 §33.3 presenting contract-related assets/liabilities*: https://viewpoint.pwc.com/dt/us/en/pwc/accounting_guides/financial_statement_/financial_statement___18_US/Chapter-33--Revenue-and-contract-costs/33-3-Presenting-contract-related-assets-and-liabilities-ASC-606.html
9. <a id="ref9"></a>HubiFi — *Gift card liability accounting (deferred revenue + breakage)*: https://www.hubifi.com/blog/gift-card-liability-accounting ; Double-Entry Bookkeeping — *Accounting for gift cards*: https://www.double-entry-bookkeeping.com/deferred-revenue/accounting-for-gift-cards/
10. <a id="ref10"></a>Automotive Training Network — *Accounts receivable credit balance (reclassify to liability)*: https://www.automotivetrainingnetwork.com/glossary/accounts-receivable-credit-balance/
11. <a id="ref11"></a>NetSuite **(P)** — *Customer overpayment treatment*: https://www.netsuite.com/portal/resource/articles/accounting/overpayment.shtml
    - Also: AccountingTools — *Unearned revenue*: https://www.accountingtools.com/articles/what-is-unearned-revenue.html

**ERP modeling of customer credit balance / on-account sales**
12. <a id="ref12"></a>SAP Learning **(P)** — *Special G/L transaction use cases (down payments / advances)*: https://learning.sap.com/courses/detailing-special-g-l-transactions-in-accounts-receivable/outlining-special-g-l-transaction-use-cases
13. <a id="ref13"></a>S4Pedia — *Special G/L (SGL) transactions*: https://www.s4pedia.com/post/special-general-ledger-sgl-transactions
14. <a id="ref14"></a>NetSuite / Oracle Help **(P)** — *Customer deposits (Other Current Liability)*: https://docs.oracle.com/en/cloud/saas/netsuite/ns-online-help/section_N1296349.html
15. <a id="ref15"></a>Prolecto — *Converting NetSuite A/R credits to customer deposits*: https://blog.prolecto.com/2022/05/22/how-to-convert-netsuite-accounts-receivable-credits-to-customer-deposits/
16. <a id="ref16"></a>Odoo Docs **(P)** — *Payments / outstanding credits*: https://www.odoo.com/documentation/19.0/applications/finance/accounting/payments.html
17. <a id="ref17"></a>QuickBooks **(P)** — *Create & apply credit memos / unapplied payments*: https://quickbooks.intuit.com/learn-support/en-us/help-article/customer-refunds-credits/create-apply-credit-memos-delayed-credits-online/L5kne9EiI_US_en_US
18. <a id="ref18"></a>Microsoft Learn **(P)** — *Dynamics 365 Finance customer prepayments*: https://learn.microsoft.com/en-us/dynamics365/finance/accounts-receivable/customer-prepayments

**Sign convention / ledger DB design / Money pattern**
19. <a id="ref19"></a>TigerBeetle Docs **(P)** — *Debits & credits*: https://docs.tigerbeetle.com/concepts/debit-credit/
20. <a id="ref20"></a>Square Engineering — *Books: immutable double-entry accounting database*: https://developer.squareup.com/blog/books-an-immutable-double-entry-accounting-database-service/
21. <a id="ref21"></a>Martin Fowler **(P)** — *Accounting patterns: Account / AccountingEntry / AccountingTransaction*: https://martinfowler.com/eaaDev/Account.html
22. <a id="ref22"></a>Journalize — *An elegant DB schema for double-entry accounting*: https://blog.journalize.io/posts/an-elegant-db-schema-for-double-entry-accounting/
23. <a id="ref23"></a>Stitch Fix Engineering — *Patterns of SOA: denormalized cache*: https://multithreaded.stitchfix.com/blog/2017/06/28/patterns-of-soa-denormalized-cache/

**Credit limit / AR aging / deposit-refund lifecycle**
24. <a id="ref24"></a>Wikipedia — *Credit limit*: https://en.wikipedia.org/wiki/Credit_limit
25. <a id="ref25"></a>Emagia — *Credit exposure vs credit limit*: https://www.emagia.com/resources/glossary/credit-exposure/ ; SAP Community — *Credit exposure / open items*: https://community.sap.com/t5/enterprise-resource-planning-q-a/credit-exposure-open-items/qaq-p/11092804
26. <a id="ref26"></a>AccountingTools — *Accounts receivable aging (30/60/90 buckets)*: https://www.accountingtools.com/articles/what-is-accounts-receivable-aging.html ; AccountingTools — *Customer deposit lifecycle*: https://www.accountingtools.com/articles/what-is-a-customer-deposit.html

**Multi-currency balance storage**
27. <a id="ref27"></a>Modern Treasury Docs **(P)** — *Working with multiple currencies (one balance per currency; never sum across)*: https://docs.moderntreasury.com/docs/working-with-multiple-currencies
28. <a id="ref28"></a>SDK.finance — *What is a multi-currency ledger*: https://sdk.finance/blog/what-is-a-multi-currency-ledger-how-fintechs-track-balances-transfers-and-settlement-across-currencies/

**Money representation / ISO 4217 minor units**
29. <a id="ref29"></a>Martin Fowler **(P)** — *Money pattern* (and *no floating point for money*): https://martinfowler.com/eaaCatalog/money.html
30. <a id="ref30"></a>dev.to (aloukissas) — *Work in cents, not dollars (integer minor units)*: https://dev.to/aloukissas/you-better-work-in-cents-not-dollars-ngo
31. <a id="ref31"></a>Wikipedia **(P)** — *ISO 4217* (minor-unit exponent; TND/KWD/BHD = 3 decimals): https://en.wikipedia.org/wiki/ISO_4217
