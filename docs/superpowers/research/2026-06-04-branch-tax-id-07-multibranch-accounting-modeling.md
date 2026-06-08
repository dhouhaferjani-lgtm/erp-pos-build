# Multi-Branch / Multi-Establishment Accounting — Modeling Options & Trade-offs

> Doc 07 of the `branch-tax-id` research series. Companion to docs 01–06
> (model & flow, canonical payload impact, compliance requirements, numbering series,
> deep multi-country research). Date: 2026-06-04. Domain / best-practice research
> (WebSearch + WebFetch). Author: research subagent (Opus 4.8).
>
> **RESEARCH ONLY** — no code was touched. This doc informs FUTURE planning of
> per-branch accounting for AutoERP (serving primarily France + Tunisia; also UK,
> Italy, North Africa).

---

## 0. The owner's question, answered up front

> "If I understand correctly, every branch would get its own subaccount. Accounting is
> consolidated but data can also be displayed by branch."

**Verdict: half right — correct *intent*, wrong *mechanism*.**

- ✅ **Right intent:** one set of books for the legal entity, with the ability to slice
  the same books by branch. That is exactly the standard answer for branches of ONE
  company.
- ❌ **Wrong mechanism (in the general case):** "every branch gets its own subaccount"
  is the *legacy* way to do it and the one modern ERPs explicitly moved away from.
  The dominant best practice is to keep **ONE chart of accounts** and tag each journal
  line with a **branch dimension** (analytic account / cost center / segment). You do
  NOT explode account `411 Clients` into `411-01`, `411-02`, … per branch.
- ⚠️ **Important framing correction:** the word "consolidated" the owner used is *not*
  statutory consolidation. Branches of one legal entity are never "consolidated" in the
  accounting-standards sense — they are simply *one* ledger that you *segment* for
  internal reporting. See §5. Using "consolidation" loosely here will mislead the spec.

So the precise restatement is:
**"One chart of accounts for the legal entity; every journal line carries a branch
dimension; statutory reports print at entity level; management/segment reports filter or
group by the branch dimension. Subaccounts only where a regulator forces a *separate
account* for a specific item."**

Confidence: **High.** This is the convergent recommendation of Odoo, ERPNext, SAP
Business One, Sage/Intacct, and dimensional-accounting guidance (sources throughout).

---

## 1. The three modeling approaches

### (a) Subaccounts per branch (account-code extension)

Extend the account code so each branch has its own child account under a parent:
`411` → `4110001`, `4110002`, … or a suffix scheme `411-01`, `411-02`. Every
branch-specific balance is a distinct GL account.

**Pros**
- Branch balances are *real GL accounts* — they appear directly in the trial balance and
  general ledger with no extra reporting layer. Auditors and old-school accountants find
  this familiar.
- Works in *any* accounting system, even ones with no dimension support.
- Where a regulator demands a **legally separate account** (rare; e.g. a segregated
  client/trust account, or a tax authority that wants a dedicated VAT-payable account per
  establishment-declaration), a subaccount is the *correct* tool.

**Cons**
- **Chart-of-accounts explosion.** N branches × M dimensioned accounts = N×M accounts.
  Add departments/projects and it becomes combinatorial. Dimensional-accounting guidance
  calls this out explicitly: *"instead of creating a new GL account for every
  department-location combination, you simply select the department and location
  dimensions"* — the subaccount approach is the thing they are arguing against
  ([Intuit / Intacct][intuit], [Synerg ERP][synerg]).
- Adding a branch means cloning a whole sub-tree of the CoA and remapping every default
  account, posting rule, and report. High operational cost per new branch.
- Reports that want "all branches for account 411" must re-aggregate the children;
  reports that want "one branch across all accounts" must scan many account codes.
- Brittle for cross-branch analysis (department × branch, product line × branch).
- Accounting dimensions are explicitly described as *"a replacement for subaccounts in
  your GL"* in modern ERP literature ([Rand Group / Sage Intacct][rand]).

**Verdict:** legacy. Use only for the *specific accounts* a law/regulator forces to be
separate, never as the general branch model.

### (b) Analytical / cost-center / dimension accounting (ONE chart of accounts + a branch tag)

Keep a single chart of accounts. Each journal line carries one or more **dimension**
values; "branch/location" is one such dimension. Statutory books are the untagged GL;
management reports group/filter by the branch dimension.

This is the same idea under four names:

- **France — comptabilité analytique (classe 9).** A parallel, internal analytic
  bookkeeping. Class 9 is *"not normalized by the PCG: each company can structure it
  freely … completely disconnected from the general accounting and appears neither on the
  balance sheet nor the income statement"* ([compta-online / PCG class 9][drivn]). It is
  **optional** (see §2).
- **Odoo — analytic accounts + analytic plans.** *"Odoo maintains a single chart of
  accounts and uses analytic accounts and plans to track costs and revenues across
  organizational dimensions … Rather than creating separate charts of accounts for each
  dimension, Odoo tags individual journal lines with analytic distributions"*
  ([Odoo 19 docs][odoo-analytic]). For multi-location: *"create a 'Locations' plan to
  track costs by warehouse, branch, or store"* ([odooskillz][odooskillz]).
- **SAP — cost center / profit center / segment / business area.** SAP Business One
  *"enables you to distribute costs across up to five dimensions … Branch and Product
  Line"* ([Vision33][vision33], [Sterling][sterling]).
- **ERPNext — Cost Center + Accounting Dimensions (incl. a built-in "Branch").** *"Accounting
  Dimensions enable organizations to tag financial transactions with additional analytical
  attributes … Department, Branch, Product Line, or any custom dimension"*
  ([ERPNext docs][erpnext-dim]).

**Pros**
- **One clean CoA.** *"This keeps your chart of accounts clean and manageable. Each
  transaction can be tagged with the relevant dimension values"* ([Intuit][intuit]).
- Adding a branch = adding one dimension *value*, not a CoA sub-tree. O(1) onboarding.
- Multi-dimensional slicing for free: branch × department × project × product line, all
  on the same ledger ([Acumatica][acumatica], [Synerg][synerg]).
- Statutory output is untouched (it ignores the tag); segment output is a `GROUP BY`.
- Matches IFRS 8 / internal segment reporting intent (§5).

**Cons**
- Requires the data model to carry dimension(s) on every journal line and enforce them at
  posting (a "dimension required" rule), plus dimension-aware reports. More upfront
  engineering than subaccounts in a trivial system — but AutoERP is event-sourced with a
  journal layer, so this is the natural fit.
- Balance-sheet-by-branch is harder than P&L-by-branch: balances (cash, AR, AP, stock)
  must also carry the dimension and be reconcilable per branch, or you accept that only
  P&L is branch-sliceable. Most SMB setups branch-slice P&L + a few balance items, not a
  fully balanced per-branch balance sheet. (Confidence: **Medium-High** — widely true in
  practice; the depth of balance-sheet dimensioning varies by product.)
- A dimension is *not* a legally separate account — if a regulator demands a separate
  account, the dimension alone won't satisfy it (then fall back to a targeted subaccount
  for that one account).

**Verdict:** the **default recommendation** for branches of one legal entity.

### (c) Separate ledgers per branch + consolidation

Each branch keeps full, independent books; a consolidation step combines them.

**Pros**
- Genuine per-branch trial balance and balance sheet, fully self-contained.
- Necessary when a branch is (or is treated as) a **separate legal/reporting entity** —
  e.g. a foreign branch that files locally, or an intercompany structure. Odoo's
  *multi-company* + *Consolidation* app and SAP/NetSuite intercompany features exist for
  exactly this ([Odoo Consolidation][odoo-consol], [Acumatica intercompany][acumatica]).

**Cons**
- Overkill for branches of one legal entity that file ONE statutory return. You take on
  intercompany eliminations, currency translation, and a consolidation engine you do not
  legally need.
- Heaviest operationally; slowest to add a branch.
- Odoo explicitly contrasts the two: *"Consolidating companies involves legally separate
  entities, whereas branches are subdivisions of a single legal entity which … are not
  consolidated in the same way"* ([Odoo branch concept][odoo-branch],
  [bytelegions][byteleg]).

**Verdict:** correct only when branches are effectively separate entities. Not the
owner's case.

---

## 1.x Comparison table

| Dimension | (a) Subaccounts per branch | (b) Dimension / analytic tag | (c) Separate ledgers + consolidation |
|---|---|---|---|
| Chart of accounts | Explodes (N branches × M accounts) | **One, clean** | One per branch |
| Add a new branch | Clone CoA sub-tree, remap defaults | **Add one dimension value** | Stand up new books |
| Statutory output (entity level) | Re-aggregate children | **Native (ignore tag)** | Requires consolidation step |
| Per-branch P&L | Yes, via account ranges | **Yes, GROUP BY tag** | Yes, native |
| Per-branch balance sheet | Yes (if every balance acct is split) | Partial (needs balance dims) | Yes, native |
| Cross-dim (branch×dept×project) | Combinatorial explosion | **Yes, cheap** | Hard across ledgers |
| Legally-separate-account need | **Satisfies it** | Does not (use a subaccount for that acct) | Satisfies it |
| Right when… | A regulator forces a separate account | **Branches of ONE legal entity** | Branch ≈ separate entity |
| Engineering cost | Low logic, high CoA maintenance | Medium logic, low maintenance | High (consol engine) |
| Modern ERP stance | "legacy / replaced by dimensions" | **Recommended default** | For multi-entity only |

---

## 1.y Recommendation framework: dimension vs subaccount vs separate entity

Decide per concern, not once globally:

1. **Is the branch a separate legal entity / does it file its own statutory return?**
   → **Separate ledger + consolidation (c)** / multi-company. (Not the owner's case.)
2. **Does a law or regulator demand a *physically separate account* for a specific
   item** (e.g. a dedicated VAT-payable per establishment-declaration, a segregated
   client account)? → Use a **targeted subaccount (a)** *for that one account only*,
   while everything else stays on the dimension model.
3. **Otherwise — you just want to *see* the same books by branch** →
   **branch dimension (b).** This is the overwhelming majority of "I want per-branch
   reporting" requests, and it is the owner's case.

---

## 2. France specifics (Plan Comptable Général)

**Confidence: High** on the structural facts (SIREN/SIRET, single statutory filing,
analytic optionality); Medium on edge cases.

- **One legal entity = one SIREN = ONE set of statutory accounts, regardless of how many
  établissements.** SIRET = 9-digit SIREN (legal entity) + 5-digit NIC (establishment);
  *"an enterprise has only one SIREN … but can have as many SIRET as it has
  establishments"* ([l-expert-comptable SIRET][lec-siret], [INSEE/data.gouv Sirene][sirene]).
  The statutory accounts (bilan, compte de résultat) and the liasse fiscale are filed at
  **SIREN / legal-entity level** — there is no per-établissement statutory filing of
  annual accounts. **Confirmed.** (The SIRET does appear on commercial/payroll documents
  — that is an *identification* requirement, not a separate-books requirement; see doc 03
  for the document-level tax-ID story.)
- **General accounting (comptabilité générale) using the PCG is mandatory** for
  essentially all non-micro businesses ([legalplace PCG][legalplace],
  [BOFiP normalisation][bofip]). The PCG normalizes classes 1–7.
- **Per-établissement accounting is NOT a statutory accounting requirement.** It is a
  **management / analytical choice.** Where établissement-level figures live is the
  **comptabilité analytique** (class 9 / sections analytiques), which is **optional**:
  *"keeping general accounting is an obligation for every business, whereas analytic
  accounting is not mandatory … it is facultative and produces calculations that remain
  internal"* ([Shine][shine], [Mooncard][mooncard]). Class 9 is free-form: *"unlike
  classes 1–7, class 9 is not normalized by the PCG; each company structures it freely …
  disconnected from the general accounting, appearing neither on the balance sheet nor
  the income statement"* ([Drivn / compta-online][drivn]).
- **What this means:** the French regulator does *not* push you toward subaccounts per
  établissement. The PCG gives you general accounting (mandatory, entity-level) + analytic
  accounting (optional, free-form) — i.e. exactly the **dimension model (1b)** dressed in
  French terminology. A branch dimension on each journal line *is* a comptabilité
  analytique by section.
- **Edge to flag for later (per-establishment VAT):** France files VAT at the legal-entity
  level by default, so this does not normally force per-branch VAT accounts. (Tunisia is
  different — see §3.) Doc 03/05 in this series carry the document-level and per-country
  declaration detail; cross-check there before the spec locks. Confidence: Medium on the
  VAT-account interaction.

---

## 3. Tunisia specifics (Système Comptable des Entreprises)

**Confidence: Medium** (fewer primary sources reachable than for France; flagged).

- **Accounting framework:** Loi n°96-112 du 30 décembre 1996 *relative au système
  comptable des entreprises*, with standards from the Conseil National de la Comptabilité,
  applicable to all local and foreign companies ([CCSAV][ccsav], [BNP Paribas Trade][bnp]).
  Like France, it is a normalized **general accounting** obligation at the legal-entity
  level; **analytical breakdown by establishment is a management choice, not a separate
  statutory bookkeeping mandate.** (Confidence: Medium — inferred from the framework being
  a general-accounting law plus the absence of any per-establishment annual-accounts
  filing requirement in the sources; no source explicitly says "you may consolidate at the
  legal entity with analytical branch breakdown," so treat as best-supported reading.)
- **Matricule fiscal has an establishment suffix.** The Tunisian matricule fiscal is
  structured with a code TVA + category code + an **establishment/secondary number**
  (a 3-digit establishment component) ([Copep's matricule guide][copeps],
  [profiscal TCA][profiscal]). This is the Tunisian analogue of the French NIC: the tax ID
  *itself* encodes the establishment. (See doc 01/05 for the exact decomposition.)
- **VAT / turnover declarations** are filed at the *bureau de contrôle des impôts du lieu
  d'imposition* (place of taxation) ([profiscal TCA][profiscal]). This is the part to
  watch: **if Tunisian practice ties a declaration to an establishment (lieu
  d'imposition), the ledger may need to produce per-establishment VAT/turnover figures**
  — which the **dimension model handles cleanly** (filter VAT accounts by branch
  dimension), and which would only need a *subaccount* if the authority demanded a
  physically separate VAT-payable account per establishment. We did **not** find a primary
  source mandating separate VAT *accounts* per establishment — flag as **open question for
  a Tunisian accountant** before the spec. Confidence: Low on this specific point.
- A foreign company's Tunisian **succursale** *"is subject to the same accounting and tax
  obligations as a national company"* ([copeps][copeps]) — i.e. it keeps Tunisian books;
  that is a separate-entity-ish case (closer to 1c) and distinct from "branches of one
  Tunisian company."

**Net for Tunisia:** same shape as France — consolidated (entity-level) books with
analytical branch breakdown is the expected model; the live risk is per-establishment VAT
declaration granularity, resolvable with the dimension model and only escalating to
subaccounts if a tax inspector demands separate accounts. Verify with a TN accountant.

---

## 4. Consolidation vs segmentation — clearing up the word "consolidated"

Two genuinely different things, often conflated:

| | (i) Statutory consolidation | (ii) Internal segment / branch reporting |
|---|---|---|
| What it combines | **Separate legal entities** (parent + subsidiaries) | Branches/divisions **within ONE legal entity** |
| Mandated by | IFRS 10 / local GAAP, when control exists | Nobody — it's a management choice (IFRS 8 governs *disclosure* if listed) |
| Output | Group consolidated financial statements | Internal P&L/BS by segment; ENTITY-level statutory output is the only filing |
| Eliminations | Intercompany eliminations required | None — it's one entity's own ledger |
| Mechanism | Consolidation engine / multi-company | **Dimension tag on journal lines** |

- Statutory/legal consolidation *"is mandatory for companies exercising control over
  subsidiaries … combining the financial statements of separate legal entities"*
  ([Trijotech][trijo], [IFRS 10][ifrs10]).
- Management/segment consolidation *"is performed to know segment/division-wise P&L or
  balance sheet … offices are not always created as legal entities"* ([Trijotech][trijo]).
- IFRS 8 is a **disclosure** standard for *reportable segments* of listed/public entities;
  it does not require separate books, and most SMBs aren't even in scope ([IFRS 8 /
  segment discussion][ifrs10]). It nonetheless validates that "report the one entity by
  segment" is the canonical pattern.

**The owner's case is unambiguously (ii):** branches of ONE company, ONE set of statutory
books, segment-reportable by branch. That is **not** consolidation. The right mechanism
for (ii) is the **dimension/analytic tag (1b)** — not subaccounts (which bloat the CoA and
still don't give clean cross-dimension slicing) and not separate ledgers (which import a
consolidation problem you don't have). **Confidence: High.**

---

## 5. Practical ERP patterns (how the big systems model "branch")

Dominant pattern across all of them: **a tagged dimension on the journal line + ONE chart
of accounts**, with optional subaccounts only where a regulator demands a separate account.

- **Odoo** — *analytic accounts + analytic plans* (a "Locations"/branch plan), single CoA,
  journal lines carry analytic distributions; Odoo 19 supports multi-plan distribution so
  one line can split across branch × department simultaneously
  ([Odoo 19 analytic][odoo-analytic], [odooskillz][odooskillz]). Odoo *also* has a
  separate **"Branches"** concept under **multi-company** (a subdivision of one legal
  entity that shares journals/taxes/CoA but is access-segmented and report-filterable) and
  a distinct **Consolidation** app for legally separate entities — note these are three
  different tools, and the owner wants the analytic/branch-filter combo, not the
  Consolidation app ([Odoo branch concept][odoo-branch], [Odoo Consolidation][odoo-consol],
  [bytelegions][byteleg]).
- **ERPNext** — built-in **Cost Center** plus user-defined **Accounting Dimensions**, and
  it ships a **"Branch"** doctype usable as a dimension; tag transactions, single CoA,
  multidimensional reports ([ERPNext accounting-dimensions][erpnext-dim],
  [DeepWiki][deepwiki]).
- **SAP Business One** — up to **five dimensions** (Branch, Department, Product Line, …)
  with **cost centers** and **distribution rules**; B1 also has profit-center reporting.
  (In larger SAP S/4HANA the analogues are **profit center / segment / business area** —
  same idea, segment is a financial-statement dimension)
  ([Vision33][vision33], [Sterling][sterling], [Vinasystem][vinasystem]).
- **Sage Intacct / dimensional accounting** — the canonical "kill subaccounts, use
  dimensions" product; *"accounting dimensions serve as a replacement for subaccounts …
  analyze across an unlimited number of dimensions"* ([Rand Group][rand],
  [Intuit/Intacct][intuit]).

**Dominant pattern (confirmed): branch = a posting dimension on the journal line, on a
single chart of accounts.** Subaccounts survive only as a targeted, regulator-driven
exception. **Confidence: High.**

---

## 6. Recommendation & what this means for AutoERP planning

**Recommended modeling approach: (1b) — ONE chart of accounts per legal entity + a
mandatory `branch` (location/establishment) dimension on every journal line; statutory
reports at entity level; segment reports grouped/filtered by the branch dimension.**
Subaccounts only for the specific accounts a regulator forces to be separate (treat as a
narrow, per-account override, not the default).

Concrete implications for AutoERP (research-level pointers; the spec decides):

1. **Map "branch" onto the existing `companies` (legal entity) → `locations` (physical
   place) split.** `locations` is the natural home of the branch dimension and of the
   per-establishment tax-ID override that docs 01/05 already scope (FR NIC/SIRET, TN
   establishment suffix). This keeps tax identity and the accounting dimension on the same
   entity.
2. **Add a `location_id` (branch dimension) to the journal-line / ledger-entry level**,
   not just the document header, so P&L and dimensioned balances can be sliced per branch.
   Enforce "branch required" at posting (analogous to Odoo's "mandatory analytic" rule).
   This is the single load-bearing data-model decision.
3. **Statutory output ignores the dimension** (entity-level bilan / compte de résultat /
   liasse for FR; entity-level returns for TN). **Segment reporting is a `GROUP BY
   location_id`.** No consolidation engine, no intercompany eliminations — this is ONE
   entity (do NOT reuse the multi-tenant / multi-company DB-per-tenant machinery for
   branches; branches are *within* a tenant's legal entity).
4. **Do NOT explode the chart of accounts per branch.** Keep one CoA; if a TN VAT-by-
   establishment requirement turns out to need a physically separate VAT-payable account,
   add a *targeted* subaccount for that account only and resolve it via the
   location/establishment context.
5. **Per-branch balance sheet is a stretch goal, not the MVP.** P&L-by-branch and key
   balances (cash drawer, branch stock, branch AR) by dimension are the realistic first
   cut; a fully balanced per-branch balance sheet needs every balance account
   dimensioned and reconciled and can be phased later.
6. **Open questions to close before the spec locks:**
   - TN per-establishment VAT/turnover declaration granularity — does any TN authority
     demand a *separate VAT account* per establishment, or is a dimensioned VAT figure
     enough? (Verify with a Tunisian accountant; cross-ref doc 03/05.) Confidence currently
     Low.
   - Whether the fiscal hash-chain / canonical SALE_RECEIPT payload needs the branch
     dimension inside the signed bytes (the per-branch tax-ID work in docs 01/02 already
     touches this — keep the accounting dimension and the signed tax-ID story aligned).
   - UK / Italy / other North-African verticals: the dimension model is country-agnostic;
     confirm none of them mandate per-branch separate books (expectation: they don't — same
     single-legal-entity logic). Confidence: Medium-High that the dimension model covers all.

**Bottom line for the owner:** keep one set of books, add a branch *tag*, report by tag.
That delivers "consolidated accounting that can also be displayed by branch" without the
subaccount sprawl — and it is what every major ERP does for branches of one company.

---

## Sources

Multi-branch / dimensional-accounting best practice:
- [Intuit / Intacct — Multidimensional accounting][intuit]
- [Rand Group / Sage Intacct — What is dimensional accounting][rand]
- [Synerg ERP — How dimensional accounting helps][synerg]
- [Acumatica — Multi-entity & intercompany accounting][acumatica]

Odoo:
- [Odoo 19 — Analytic accounting (docs)][odoo-analytic]
- [odooskillz — Analytic setup: cost centers & locations plan][odooskillz]
- [Odoo 17 — Branch concept (multi-company)][odoo-branch]
- [Odoo 19 — Consolidation (docs)][odoo-consol]
- [bytelegions — Odoo 19 branches & reporting][byteleg]

ERPNext:
- [ERPNext docs — Accounting Dimensions][erpnext-dim]
- [DeepWiki — ERPNext accounting dimensions & cost centers][deepwiki]

SAP Business One:
- [Vision33 — Add more dimensions to cost accounting][vision33]
- [Sterling — SAP B1 dimensions & cost centers guide][sterling]
- [Vinasystem — Cost accounting in SAP Business One][vinasystem]

France (PCG / SIREN-SIRET / analytique):
- [legalplace — Plan comptable général][legalplace]
- [BOFiP — Normalisation des comptabilités][bofip]
- [Shine — La comptabilité analytique est-elle obligatoire ?][shine]
- [Mooncard — Comptabilité analytique vs générale][mooncard]
- [Drivn / compta-online — PCG class 9 free-form][drivn]
- [l-expert-comptable — Le code SIRET (SIREN + NIC)][lec-siret]
- [INSEE / data.gouv — Base Sirene][sirene]

Tunisia (Système Comptable des Entreprises / matricule fiscal):
- [CCSAV — Normalisation comptable en Tunisie (loi 96-112)][ccsav]
- [BNP Paribas Trade Solutions — Fiscalité & comptabilité Tunisie][bnp]
- [Copep's — Matricule fiscale & code TVA Tunisie][copeps]
- [profiscal — Taxation du chiffre d'affaires en Tunisie (TCA)][profiscal]

Consolidation vs segment reporting:
- [Trijotech — Legal vs management consolidation (Part II)][trijo]
- [IFRS 10 — Consolidated Financial Statements (IFRS.org)][ifrs10]

[intuit]: https://www.intuit.com/enterprise/blog/financials/multi-dimensional-accounting/
[rand]: https://www.randgroup.com/insights/sage/sage-intacct/what-is-dimensional-accounting-and-how-can-it-help-you/
[synerg]: https://synergerp.com/blog/how-dimensional-accounting-makes-it-easier-to-run-your-business-2/
[acumatica]: https://www.acumatica.com/cloud-erp-software/inter-company-accounting/
[odoo-analytic]: https://www.odoo.com/documentation/19.0/applications/finance/accounting/reporting/analytic_accounting.html
[odooskillz]: https://www.odooskillz.com/blog/odoo-skillz-insights-1/odoo-analytic-accounting-cost-centers-setup-guide-2026-346
[odoo-branch]: https://www.technaureus.com/blog-detail/odoo-17-branch-concept-multi-company-management
[odoo-consol]: https://www.odoo.com/documentation/19.0/applications/finance/accounting/get_started/consolidation.html
[byteleg]: https://bytelegions.com/multi-company-setup-in-odoo-19-branches-reporting/
[erpnext-dim]: https://docs.erpnext.com/docs/user/manual/en/accounting-dimensions
[deepwiki]: https://deepwiki.com/frappe/erpnext/3.3-accounting-dimensions-and-cost-centers
[vision33]: https://blog.vision33.com/tips-and-tricks-add-more-dimensions-to-your-cost-accounting
[sterling]: https://www.sterling-team.com/news/en/sap-business-one-dimensions-cost-centers-guide/
[vinasystem]: https://www.vinasystem.com/en/blogs/sap-hana/cost-accounting-in-sap-business-one
[legalplace]: https://www.legalplace.fr/guides/plan-comptable-pdf/
[bofip]: https://bofip.impots.gouv.fr/bofip/3412-PGP.html/identifiant=BOI-BIC-DECLA-30-10-20-20-20141027
[shine]: https://www.shine.fr/blog/comptabilite-analytique-obligatoire/
[mooncard]: https://www.mooncard.co/fr/cas-usage/comptabilite/types-de-comptabilite/analytique/generale
[drivn]: https://drivn.fr/blog/quest-ce-que-le-plan-comptable-general-pcg
[lec-siret]: https://www.l-expert-comptable.com/a/529494-le-code-siret.html
[sirene]: https://www.data.gouv.fr/datasets/base-sirene-des-entreprises-et-de-leurs-etablissements-siren-siret
[ccsav]: https://ccsav.ca/normalisation-comptable-en-tunisie/
[bnp]: https://m.tradesolutions.bnpparibas.com/fr/implanter/tunisie/la-fiscalite-et-la-comptabilite
[copeps]: https://copeps.fr/actualites/matricule-fiscale-tunisie-code-tva/
[profiscal]: http://www.profiscal.com/Etudiants/TCA/tca_ch9_06.htm
[trijo]: https://trijotech.com/consolidation-what-is-the-difference-between-legal-management-consolidation-part-ii/
[ifrs10]: https://www.ifrs.org/content/dam/ifrs/publications/pdf-standards/english/2021/issued/part-a/ifrs-10-consolidated-financial-statements.pdf
