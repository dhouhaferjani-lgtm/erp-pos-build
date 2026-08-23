# RUNBOOK — Opening cash float at go-live

**Date:** 2026-08-23
**Owner ruling:** B-2 (owner sheet `OWNER-SHEET-2026-08-21-first-client-session.md`, resolution line 40)
**Authority:** `docs/handoff/RESEARCH-opening-float-and-vat-doc-count-2026-08-23.md` §1.6 (Recommendation 1, pre-launch mandatory)
**Audience:** whoever onboards a tenant — operator and accountant alike.

> **Why this document exists.** No document anywhere told an operator how to establish cash
> opening balances. `STAGING-RUNBOOK-first-tenant-2026-07-31.md` is a staging/deploy ops runbook;
> `HANDOVER-opening-balance.md` covers product/inventory opening only. This section fills that hole.
>
> **The risk it closes is not double-counting — it is phantom revenue.** The only operator-facing
> path that exists today (Treasury → "Adjust balance") books the float as **income** (`7580 Écart
> de règlement (produits)`), and seals it immutably into the fiscal hash chain. 500 TND of float
> becomes 500 TND of income that never existed.

> ⚠️ **Caveat on the account codes cited below (owner-sheet B-15, OPEN).** The codes `53` (Caisse)
> and `119` (Solde d'ouverture) are the **currently seeded** chart
> (`apps/api/database/seeders/TunisiaChartOfAccountsSeeder.php:136`, `:324-325`) — they are **under
> review**. The research brief §1.3.1 verified line-by-line against the official OECT nomenclature
> that our "Tunisian" chart is a French PCG chart under a Tunisian label (official TN cash is
> `54/5411`; real TN `53` is *Banques*; `63`↔`66` and `73`↔`75` are swapped). B-15 is an open owner
> ruling + accountant confirmation. **The procedure below is correct regardless of how B-15 rules —
> only the account numbers may move.** Use the account that the payment repository is actually
> linked to (`payment_repositories.gl_account_id`), not the literal code, if in doubt.

---

## Opening cash float — go-live (do this once, in this order)

### 1. The float is an accounting opening balance. It is entered exactly once, in the accounting opening batch — never through Treasury → "Adjust balance".

Go to **Settings → Opening balances** (`/settings/opening-balances`, type `ACCOUNTING`; the wizard is
live — `apps/web/src/routes/index.tsx:2426-2437`, API
`apps/api/app/Modules/Accounting/Presentation/routes.php:108-126`).

Count the physical cash you are putting in each till *before* the first shift. Include those amounts
in the GL opening balance batch as debits to the till's own cash account (on our current TN chart:
`53 Caisse`, or the sub-account linked to that payment repository — but see the B-15 caveat above
first). The batch balances itself against `119 Solde d'ouverture`. Post it; it validates and **locks
automatically** in the same transaction.

### 2. Do NOT use Treasury → repository → "Adjust balance" to seed a till.

That dialog books the amount as **income** (`7580 Écart de règlement (produits)`), not as an opening
balance. It exists for *count variances and corrections during operation*, not for go-live.

If you have already used it: the entry cannot be edited — have the correction posted as a manual
journal entry reversing `7580` against `119` for the same amount and date, and do not also include
the float in the opening batch.

> Since 2026-08-23 the API **refuses** this misuse mechanically: an adjustment on a payment
> repository that has never moved (zero movements, zero prior adjustments) is rejected with
> `REPOSITORY_NOT_SEEDED` and a message pointing back at this runbook. Step 2 is therefore enforced,
> not merely documented — but the remediation paragraph above still applies to any tenant seeded
> before that guard shipped.

### 3. Enter the same float on the POS when you open the first shift.

The device asks for the opening float when a shift is opened. Enter the counted amount. This number
is the drawer's own expectation — it does not post anywhere and cannot double-count with step 1.

### 4. Known gap while `opening_float` (B-2 option b) is not shipped.

The till's balance shown in Treasury will be understated by the float amount. Two consequences to
expect:

- Cash-position screens show the till short by the float. The GL (`53`) and the physical drawer are
  correct; the Treasury figure is the one that is behind.
- A cash **outflow** (safe drop, bank deposit, payout) may be refused with "insufficient balance" if
  it exceeds `till balance shown in Treasury`. Workaround until (b) ships: split the deposit, or keep
  the float out of the deposit amount.

### 5. Do not enable `TREASURY_SHIFT_VARIANCE_GL_ENABLED`.

It must stay `false` until the float is booked into Treasury (SV-3/SV-4). Enabling it first books
the float into `6580/7580` on the first close. Current default is already `false`
(`apps/api/config/treasury.php:28`) — this is a launch-checklist assertion, not a code change.

---

## Verdict on this posture

Acceptable as a launch posture **for a single-till, owner-operated tenant**, because the operator and
the accountant are the same person and step 2 is now enforced by the guard. It stops being acceptable
the moment a second tenant with a real bookkeeper onboards — at which point ship `opening_float`
(research brief §1.7, Recommendation 3, effort M: the enum case
`MovementSourceType.php` and the reconciler's JE exemption `ReconcileTreasuryCommand.php:899-907`
already exist; only the writer is missing).

Promote `opening_float` to pre-launch if tenant #1 will make routine bank deposits from the till
(step 4's outflow refusal becomes a daily obstruction rather than an annoyance).
