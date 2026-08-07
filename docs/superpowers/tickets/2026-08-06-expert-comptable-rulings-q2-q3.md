# Expert-comptable rulings — Q1 (2026-08-07), Q2 & Q3 (2026-08-06) — ALL THREE ANSWERED

Source: owner relayed the expert-comptable's answers to the three questions in
`docs/handoff/OWNER-INDEX-2026-08-03.md` §1.

## Q1 — Timbre fiscal sur avoir : le 411 n'est réduit QUE du montant hors timbre (2026-08-07)

Verbatim answer:

> Le compte client (411) ne doit être diminué que du montant crédité hors timbre, et le
> timbre de l'avoir doit être comptabilisé séparément comme une charge fiscale pour
> l'entreprise.

### Consequence: the SUB-LEDGER (lettrage) was right; the GL is wrong — fix owed

`GeneralLedgerService::createFromCreditNote()` credits AR (411) by the FULL stamp-inclusive
CN total; `allocateToInvoice`'s ex-stamp clamp is the correct behaviour. Required change:
1. CN GL entry credits 411 with the EX-STAMP credited amount only.
2. The CN's own stamp duty books as a separate self-balancing pair: DEBIT a fiscal-charge
   expense account (TN: the 6xxx charge class; FR/Generic: the seeded 6581 pair from the L1
   lane — implementer to confirm per-chart mapping), CREDIT the stamp-payable account
   (TN 4375 SalesStampDutyPayable class) — the company owes the avoir's timbre to the state;
   it no longer reduces what the customer owes.
3. DoubleEntry balance must hold on both the new shape and the lineless/edge branches; the
   L1 preflight (DocumentGlPreflightInterface) and the L2 reversal mirror (sealed-legs
   string-exact) both consume CN entries — sweep both for shape assumptions.
4. Already-posted CNs (demo tenant has live ones, e.g. the 1.600/0.400 desync case in ticket
   2026-08-03-credit-note-regate-carryovers.md §N1): NO automatic rewrite — sealed entries
   are mirrored by the L2 reversal logic; disposition joins the accountant list (same class
   as the other campaign artifacts). New postings only.
This closes N1 and unblocks credit-note GL certification (project_accounting_gl_roadmap A1).

RELATED (separate feature line, owner 2026-08-06): whether a CN/return note carries a stamp
AT ALL should become configurable/optional — see the note at the bottom of this file.

---

## Q2 — TVA déductible partielle sur charges : base à déclarer = VALEUR FACIALE TOTALE

Verbatim answer:

> Il faut déclarer la valeur faciale totale de la transaction (100,000 HT) et non la base au
> prorata.
>
> L'administration fiscale tunisienne croise les déclarations mensuelles (et les annexes
> annuelles) entre clients et fournisseurs. Si votre fournisseur a déclaré une vente de
> 100,000 HT, vous devez déclarer un achat avec une base de 100,000 HT pour éviter toute
> anomalie de recoupement sur les systèmes de la DGI.
>
> Base à déclarer : 100,000 HT.
> TVA à déclarer (case TVA déductible) : Seulement la part déductible (les 80 %, soit 15,200).
>
> Votre système actuel (identité base × taux = TVA déduite) est une logique de contrôle
> mathématique interne, mais elle fausse la déclaration fiscale. Il faut dé-corréler la base
> déclarée du montant de la TVA effectivement déduite.

### Consequence: the V5 prorata choice is OVERRULED — fix owed

`ExpenseService::writeDeductibleVatSnapshot()` currently writes
`tax_base = ExpenseVatSplit::deductible($subtotal, $deductiblePercent, $scale)` (the
deductible-proportion base introduced by the 2026-08-03 VAT-declaration fix lane, V5). Per the
ruling this **falsifies the declaration** for any partially-deductible expense and breaks DGI
cross-matching against the supplier's declared sale.

Required change (small, contained):

1. `tax_base` = the FULL attested subtotal (`$expense->subtotal`), NOT the prorated share.
2. `tax_amount` stays the DEDUCTIBLE share (`ExpenseVatSplit::deductible($vatAmount, …)`) —
   unchanged.
3. `base × rate == tax_amount` is now EXPECTED to fail for partially-deductible expenses —
   this is by design per the ruling ("dé-corréler la base déclarée du montant de la TVA
   effectivement déduite"). Sweep declaration-side consumers for any identity assertion:
   `EloquentVatDataRepository` aggregation and any verifier/test that asserts
   `SUM(tax_base) × rate == SUM(tax_amount)` on the INPUT side must not.
4. Update the `writeDeductibleVatSnapshot()` docblock (the FLAG paragraph anticipated exactly
   this outcome — resolve it, cite this ticket) and `ExpenseVatPostingTest` expectations.
5. Backfill question: existing `document_tax_details` rows written with prorated bases on
   partially-deductible expenses under-declare the base. `vat:backfill-tax-details` does not
   cover the expense writer — decide whether to extend it or fix by hand pre-filing (ties into
   the PRE-FILING runbook item in `2026-08-03-vat-regate-carryovers.md` §N2). No tenant has
   filed yet, so no filed-period correction is needed.

Note: at 100% deductible (the common case) prorated base == full base, so the live blast
radius is limited to partially-deductible expenses.

---

## Q3 — Mapping des cases de la déclaration TVA : VALIDÉ (a)–(d)

Verbatim answer:

> Votre mapping est globalement excellent. Voici la validation point par point par rapport à la
> déclaration mensuelle des impôts en Tunisie :
>
> Point (a) - Timbre distinct : Validé. Le droit de timbre n'a rien à voir avec l'assiette TVA.
> Il est déclaré dans une tout autre section de la déclaration mensuelle (section "Droits de
> Timbre et autres taxes"). Il doit être strictement exclu des lignes de TVA collectée.
>
> Point (b) - CA exonéré/0 % : Validé. Il alimente la case des ventes exonérées ou des ventes
> en suspension de taxes (selon la nature exacte de l'exonération, il y a des cases distinctes,
> mais la logique de l'isoler est correcte).
>
> Point (c) - Avoirs en déduction : Validé. En Tunisie, on ne déclare pas les avoirs en "TVA
> déductible". Les avoirs émis viennent directement en diminution du Chiffre d'Affaires HT du
> mois et de la TVA collectée du mois pour le taux correspondant.
>
> Point (d) - Bases par taux (net commercial) : Validé. L'assiette de la TVA est toujours le
> net commercial, c'est-à-dire le montant HT déduction faite de toutes les remises, rabais et
> ristournes accordés sur la facture.

### Consequences

- Points (a), (c), (d): current implementation confirmed correct as shipped by the 2026-08-03
  VAT-declaration lane. Close the V5/DGI-form verification line of
  `2026-08-03-vat-declaration-gate.md` for these axes.
- Point (b) refinement (NEW, small, non-blocking): the expert notes exonéré vs **vente en
  suspension de taxes** land in DISTINCT boxes on the DGI form. We currently pool all
  zero/exempt CA into one `base_0` bucket. The isolation logic is validated; a future
  refinement should discriminate exemption nature (exonéré / suspension / export…) when a
  tenant actually needs suspension-regime sales. Not a launch blocker for tenant #1
  (parapharmacy, no suspension regime).

---

## Q1 — STILL PENDING (timbre fiscal sur avoir : réduit-il la créance client ?)

The GL-vs-subledger alignment question (`2026-08-03-credit-note-regate-carryovers.md` §N1)
remains open — owner is taking it back to the expert. It still blocks CN GL certification
(`project_accounting_gl_roadmap` A1).

Related owner remark (2026-08-06, separate decision, NOT the Q1 answer): whether a
credit/return note carries a duty stamp AT ALL should be **configurable/optional** — no
current law mandates it, but one could come. That is a stamp-application policy toggle
(document-type-level tax config), distinct from Q1's accounting-treatment question. File as a
tax-configuration feature line when Q1's answer lands.
