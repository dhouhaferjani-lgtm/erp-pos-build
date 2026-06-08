# Branch (establishment) Tax-ID — Research Doc 09a: Per-Branch Sub-Account Practice vs. Branch Dimension (the Hybrid Question)

**Date:** 2026-06-04
**Series:** Doc 09a in the `branch-tax-id` research series (companion / drill-down to the 01–04 model docs on `feat/branch-tax-id-spec`).
**Type:** Domain / best-practice research. **No code.**
**Scope:** Does the owner's proposed HYBRID — keep a per-branch *dimension* on journal lines AND ALSO expose per-branch *sub-accounts* in the chart of accounts (e.g. parent `6420` → `64201`, `64202`, `64203`) — match real accounting law and ERP practice, primarily in **Tunisia** and **France** (also Morocco, Algeria)? Is the hybrid wise, and if so which accounts should get branch sub-accounts? How is it usually configured?

> Reminder of the fiscal framing (from Doc 03): for **legal/fiscal** purposes only the **main entity matricule fiscal / SIREN-level identity** matters here (branches of ONE legal entity). The per-branch sub-accounts discussed below are for **analytical accounting, end-of-period reporting, and presentation** — NOT a fiscal-identity mechanism. The signed canonical receipt payload is unaffected by sub-account choice.

**Confidence legend:** 🟢 high (primary/official source) · 🟡 medium (reputable secondary source) · 🔴 low (inference / weak source).

---

## 0. TL;DR

- **Tunisia 🟢:** The Tunisian *Système Comptable des Entreprises* (Loi 96-112, NC 01) **explicitly permits** an enterprise to create the account subdivisions ("subdivisions de comptes") it needs. Account-code depth is open-ended (class 1 digit → sous-classe 2 → compte 3 → then free subdivisions). The framework *itself* ships a per-establishment subdivision pattern: **compte 17 "comptes de liaison des établissements et succursales" est subdivisé en autant de comptes de liaison que d'établissements ou succursales.** So per-establishment sub-account breakdown is a *recognised, sanctioned* practice — but it is **permitted, not legally required**.
- **France 🟢:** The PCG (Art. 1131-1) likewise lets an entity "ouvrir toute subdivision nécessaire" when standard accounts don't suffice, and ships account **18** for inter-establishment links (establishments keeping autonomous books). But the French *norm* for slicing a single set of books by establishment is the **analytic dimension (comptabilité analytique / sections analytiques)**, not GL sub-accounts. Regulators even phrase the choice as an either/or ("isolation dans des sous-comptes dédiés **OU** comptabilité analytique").
- **Morocco / Algeria 🟡:** Same family rule — mandatory main accounts, **free** lower subdivisions/sous-comptes. Per-establishment sub-account breakdown is permitted, not required.
- **Hybrid verdict:** Running BOTH a full per-branch sub-account scheme AND a per-branch dimension on *every* account is **redundant double-tracking** and is the textbook ERP anti-pattern (CoA explosion). The defensible design is **dimension-first, with selective opt-in sub-accounts** for a small set of P&L accounts where direct trial-balance visibility is genuinely wanted. The branch **dimension** should remain the single source of truth for branch slicing; sub-accounts, where enabled, must **roll up** to the parent for statutory presentation.
- **Configurability:** Make it **per-account (or per-account-class) opt-in**, defaulting OFF — not a single global "explode everything by branch" toggle. This matches how QuickBooks/Xero/Intacct/Dynamics expose it.

---

## 1. TUNISIA (primary focus) 🟢

### 1.1 The framework explicitly authorises enterprise-created sub-accounts

NC 01 (Norme Comptable Générale, the cornerstone of the *Système Comptable des Entreprises* established by **Loi 96-112 du 30 décembre 1996**) states that the proposed nomenclature is **adaptable by each enterprise**:

> « Chaque entreprise adaptera la nomenclature proposée selon son statut et ses activités. Cette adaptation peut être faite en procédant aux regroupements appropriés ou en créant des comptes ou **les subdivisions de comptes nécessaires**. »

with the caveat that additions must be documented:

> « L'adaptation de la nomenclature doit être accompagnée des explications appropriées ainsi que des définitions et des règles de fonctionnement afférentes aux ajouts ou regroupements opérés. »

(Source: NC 01 / procomptable nc1_partie3; Nomenclature et fonctionnement des comptes, alliance-tunisie.)

**Implication:** Creating `64201/64202/64203` as sub-accounts of `6420` for three establishments is **expressly within** what NC 01 permits, provided the enterprise documents the subdivision rules. 🟢

### 1.2 Account-code structure and subdivision depth

Tunisian PCG hierarchy (consistent across the official nomenclature and the practitioner summaries):

| Level | Digits | Example |
|---|---|---|
| Classe | 1 | `6` (charges) |
| Sous-classe | 2 (1st = class) | `64` |
| Compte | 3 (1st two = sous-classe) | `642` |
| Sous-compte / compte divisionnaire | 4+ (begins with the parent's number) | `6420`, `64201`, … |

Subdivision is **open-ended downward**: each sub-account number must *begin with* the number of the account/sub-account it subdivides, but there is no mandated cap — enterprises extend as deep as they need. So branch sub-accounts (`64201…`) are structurally normal. 🟢

### 1.3 The framework SHIPS a per-establishment subdivision pattern (compte 17)

The single most directly on-point finding: the *Système Comptable* itself prescribes a per-establishment subdivision for inter-unit balances —

> « Le **compte 17** est subdivisé en autant de **comptes de liaison** que d'établissements ou succursales. »

and the liaison accounts:

> « … servent de contrepartie lors de la comptabilisation des opérations réalisées entre le siège et l'établissement ou la succursale et entre deux établissements ou deux succursales. Ce compte doit être à tout moment soldé par le jeu des écritures réciproques… »

(Source: Nomenclature et fonctionnement des comptes, alliance-tunisie.)

**Reading:** Tunisia *normalises* "one sub-account per establishment" as a built-in pattern — for the **liaison/inter-branch** account. This validates the owner's claim that the per-establishment sub-account pattern "is used in Tunisia." It does **not**, however, mean the framework requires every P&L account to be exploded per establishment — that remains an optional enterprise adaptation under §1.1.

### 1.4 Legally required vs. common practice (Tunisia)

- **Legally required:** Compte 17 *must* be subdivided per establishment/succursale when inter-unit operations exist (it's how the framework defines the account). 🟢
- **Permitted (not required):** Per-establishment subdivision of *other* accounts (revenue/expense) for analytical/presentation purposes — explicitly allowed by NC 01's adaptation clause, but at the enterprise's discretion. 🟢
- **Note on succursales of foreign companies:** "Une succursale d'une société étrangère est soumise aux mêmes obligations comptables et fiscales qu'une société nationale" — i.e. a *branch of a foreign company* is a full reporting unit. That is a different case from multiple branches of ONE domestic legal entity (our case), where the legal entity files once. 🟡

---

## 2. FRANCE (PCG) 🟢

### 2.1 Free subdivision is permitted

PCG (current, ANC) **Art. 1131-1**:

> « Lorsque les comptes prévus par les normes comptables ne suffisent pas à l'entité pour enregistrer distinctement toutes ses opérations, elle peut **ouvrir toute subdivision nécessaire**. »

Same digit logic as Tunisia: classe (1) → sous-classe (2) → compte (3) → sous-comptes/comptes divisionnaires (4+), each subdivision number beginning with its parent's number. (Source: comptanat PCG règles; PCG Wikipédia.) 🟢

### 2.2 The French *norm* for per-establishment slicing is the analytic dimension, not GL sub-accounts

Two distinct mechanisms exist in France and they are normally **not** both used to slice the *same* accounts by establishment:

1. **GL sub-accounts** — allowed (Art. 1131-1), and account **18 "comptes de liaison des établissements"** exists specifically for **établissements/succursales/usines/ateliers d'une même entité qui tiennent une comptabilité autonome** (autonomous bookkeeping per site). This is the GL-side analogue of Tunisia's compte 17. 🟢
2. **Comptabilité analytique (sections / axes analytiques)** — the management-accounting layer that analyses charges/produits **par destination** (which service, product, **site/établissement**) rather than by nature. Historically the optional **classe 9** "comptabilité analytique"; in the modern PCG the obligatory plan is **classes 1–8**, and analytic accounting is **free/optional** and typically held outside the statutory GL (often via the ERP's analytic dimension). 🟡

The regulator frames these as *alternatives*. The 2020 apprentissage analytic-accounting rules describe achieving activity separation by **"comptabilité distincte, isolement des activités dans des sous-comptes déterminés, OU mise en place d'une comptabilité analytique"** — explicitly listing dedicated sub-accounts **and** analytic accounting as substitutable options, not a stacked pair. (Source: Arrêté 21 juillet 2020; AMUE compta analytique recueil.) 🟢

**French takeaway:** establishment-level slicing of one set of books is **idiomatically done via the analytic dimension / sections analytiques**, with GL sub-accounts reserved for sites that keep autonomous books (account 18). Doing *both* on the same accounts is not the French norm. 🟢

---

## 3. MOROCCO / ALGERIA (brief) 🟡

Same Franco-tradition rule: mandatory upper-level accounts, free lower subdivisions.

- **Morocco — CGNC / PCGE:** main accounts (4 digits) are mandatory; **comptes divisionnaires (5 digits) recommended**; **sous-comptes (6+ digits) freely created by each enterprise.** "Lorsque les comptes prévus … ne suffisent pas … elle peut ouvrir toutes les subdivisions nécessaires." → Per-establishment sub-account breakdown **permitted, not required.** 🟡
- **Algeria — SCF (Loi 07-11, 2007):** "une subdivision plus détaillée que la nomenclature officielle est autorisée pourvu qu'elle respecte l'ordre de classement." Compte principal (2) → divisionnaire (3) → sous-compte (4+), each beginning with its parent's number. **Constraint worth noting:** you cannot use *both* a 3-digit divisionnaire and a 4-digit sous-compte under the same coding line — pick one depth. Per-establishment subdivision **permitted, not required.** 🟡

No source surfaced a *requirement* in MA/DZ to subdivide ordinary accounts per establishment for a single legal entity.

---

## 4. THE HYBRID QUESTION (the crux)

### 4.1 Is "dimension AND full sub-account scheme" redundant?

**Yes — if applied to every account, it is double-tracking and the canonical ERP anti-pattern.** Both the branch dimension and a branch sub-account encode the *same fact* ("this line belongs to branch X"). The modern ERP consensus (Oracle Cloud ERP, Microsoft Dynamics 365 F&O, Sage Intacct, NetSuite, ERPNext, Business Central) is unambiguous:

> "A common mistake is subdividing account types into unnecessary dimensions — instead of creating separate main accounts for office equipment at different locations, use a financial dimension for cost centers / departments." (Oracle Cloud ERP CoA design.)

> "Dimensional accounting collapses many segmented accounts into core accounts augmented by dimensions, reducing GL lines to maintain and reconcile, rather than creating separate accounts for each department/location combination… adding a new location simply requires adding a new dimension value." (Sage Intacct / dimensional-accounting writeups.)

So you should **not** build a parallel branch sub-account for every account when you already carry a branch dimension.

### 4.2 Concrete trade-offs

| | **Per-branch sub-accounts** | **Per-branch dimension** |
|---|---|---|
| Branch P&L visible in raw trial balance / GL? | ✅ Yes, directly, no analytic tooling | ❌ Needs a dimension-aware report |
| Simple reports / cheap exports | ✅ | 🟡 (report must group by dimension) |
| CoA size | ❌ Explodes: **N accounts × M branches** | ✅ One CoA |
| Adding a new branch | ❌ Clone the whole sub-account set | ✅ Add one dimension value |
| Account-purpose lookups ("which account is sales VAT?") | ❌ Every posting rule must pick the right branch sub-account | ✅ One account, dimension set separately |
| Statutory financial statements | ❌ Must roll sub-accounts back up to parent | ✅ Native (account is already the parent) |
| Ad-hoc multi-axis slicing (branch × product × period) | ❌ Combinatorial explosion | ✅ Pivot on the fly |
| Matches French norm | ❌ (norm = dimension) | ✅ |
| Matches Tunisian *built-in* pattern | ✅ (compte 17 precedent) | ✅ (NC 01 also allows analytic use) |

### 4.3 How real ERPs reconcile the two

The mainstream pattern is **not** "both everywhere" — it is **dimension as the backbone, with selective, opt-in sub-account expansion** on the few accounts where finance staff want branch breakdown to fall out of the plain GL:

- **QuickBooks Online / Xero:** **Class / Location** (QBO) and **Tracking Categories** (Xero) are the dimension; **sub-accounts** exist independently. Guidance is explicit: "Class & location tracking provides a way to separate your lines of business **without having to create unique accounts**." Dimensions are toggled in settings; sub-accounts are created selectively per account. They are treated as **alternatives**, with sub-accounts used only when you want hierarchy in the account list itself.
- **Sage Intacct / NetSuite:** dimensions are first-class; segmented/expanded accounts are discouraged in favour of dimensions.
- **Dynamics 365 / Oracle / ERPNext:** "financial dimensions" / "accounting dimensions" extend the CoA via tagging; designers are warned against fixing branch at the account level.

The practical hybrid that *is* sensible: **keep one CoA + branch dimension, and allow specific accounts to be "expanded" into branch sub-children** (those children still post the branch dimension, and they roll up to the parent for statutory output). The sub-account becomes a *presentation convenience* layered on top of the authoritative dimension — never a second source of truth.

### 4.4 Recommendation

**Is the hybrid wise?** A *full* hybrid (every account both dimensioned and branch-sub-accounted) is **not** wise — redundant, bloats the CoA, complicates posting-rule lookups, and breaks statutory rollups. **A selective hybrid is defensible and matches both the law and ERP practice:**

1. **Branch dimension = single source of truth** for branch slicing. Every journal line carries it. This is the France-idiomatic, scalable backbone and satisfies analytic-accounting expectations in TN/FR/MA/DZ.
2. **Selective, opt-in branch sub-accounts** only on a short list of accounts where direct trial-balance/GL visibility is valued — and even then as a *toggle*, defaulting OFF.
3. **Sub-accounts MUST roll up to the parent** for statutory financial statements (bilan / compte de résultat). Statutory presentation is always at parent-account level; the branch breakdown is sub-account or dimension detail beneath it.
4. **Sub-accounts still post the branch dimension** — never let a branch sub-account be the *only* place the branch is recorded, or your dimension reports and your sub-account reports will disagree.

**Which accounts typically get branch sub-accounts (if hybrid):**

| Account type | Branch sub-account? | Rationale |
|---|---|---|
| **Revenue (class 7)** — sales by branch | ✅ Often | Branch P&L is the #1 thing owners want to see directly in the GL |
| **Operating expenses (class 6)** — branch rent, utilities, payroll, COGS | ✅ Often | Direct branch cost visibility; the `6420` example sits here |
| **Inter-establishment liaison** (TN compte 17 / FR compte 18) | ✅ Required/idiomatic | Framework-defined per-establishment subdivision |
| **Cash / till / bank tied to a physical branch** (class 5) | 🟡 Sometimes | A branch's drawer is genuinely a distinct asset; often modelled as separate accounts anyway |
| **VAT / tax accounts** (445x) | ❌ Keep single | One legal entity files one VAT return; per-branch tax sub-accounts complicate the declaration with no fiscal benefit (the branches share the matricule). Slice by dimension if needed. |
| **Other balance-sheet** (capital, long-term debt, receivables/payables ledgers) | ❌ Keep single | Entity-level; subledgers (customer/supplier) already provide detail; branch attribution, if wanted, is a dimension |

Rule of thumb: **P&L accounts may be branch-expanded; balance-sheet and tax accounts stay single and rely on the dimension.** This mirrors the analytic principle (analyse *charges et produits* by destination) without polluting the balance sheet.

---

## 5. CONFIGURABILITY — what to expose as a setting

**Do NOT expose a single global "subdivide all accounts by branch" toggle.** That guarantees CoA explosion (N × M) and is exactly what every ERP guide warns against.

Recommended exposure, in order of granularity:

1. **Master toggle (company settings):** "Enable per-branch sub-accounts" — gates the whole feature; **default OFF**. The branch *dimension* should be available independently of this (and ideally on by default for multi-branch tenants), so a tenant can run dimension-only with no sub-accounts at all.
2. **Per-account opt-in (primary control):** on an individual account, "Break this account down per branch" → auto-generates `<account><branch-suffix>` children for active branches, wires them to roll up to the parent, and (if the branch's books post here) tags the branch dimension. This is the QBO/Xero/Intacct idiom (sub-accounts are created per account, dimensions toggled globally).
3. **Per-account-class default (convenience):** optional defaults like "auto-expand new class 6 and class 7 accounts per branch" so finance doesn't tick each account by hand — but always overridable per account, and never auto-applied to class 1–5 or 44x.
4. **Branch lifecycle:** adding a branch should, for accounts marked "expand per branch," create the new child sub-account; closing a branch should *deactivate* (never delete — historical postings/fiscal-chain immutability) its sub-accounts.

This keeps the dimension as the cheap, scalable default and treats sub-accounts as a deliberate, bounded presentation layer.

---

## 6. Open items / lower-confidence flags

- 🟡 The precise *current* legal status of PCG **classe 9** (kept vs. dropped as a formal class in the latest ANC PCG) was not pinned to a single official article here; the safe statement is "analytic accounting is free/optional and typically outside the statutory GL." Confirm against the ANC PCG text before citing class 9 normatively in the spec.
- 🟡 Whether any Tunisian *sectoral* norm (banking/insurance) *requires* broader per-establishment account subdivision was not investigated; general commercial enterprises are covered by §1.
- 🔴 No source found asserting that ordinary (non-liaison) per-establishment account subdivision is *mandatory* in any of the four countries for a single legal entity. Treat per-branch P&L sub-accounts as **permitted practice**, not a statutory obligation.

---

## 7. Sources

**Tunisia**
- Système comptable tunisien — Ordre des Experts Comptables de Tunisie: https://oect.org.tn/systeme-comptable-tunisien/
- NC 01 — Norme Comptable Générale (OECT PDF): https://oect.org.tn/wp-content/uploads/2023/01/NC_01.pdf
- NC 01 — Législation comptable, partie 3 (procomptable): https://www.procomptable.com/normes/nc1_partie3.htm
- Nomenclature des comptes et fonctionnement général des comptes (alliance-tunisie PDF) — compte 17 / liaison: https://alliance-tunisie.com/wp-content/uploads/2019/04/Nomenclature-et-Fonctionnement-des-comptes.pdf
- Le Plan Comptable Tunisien (Legalstart.tn): https://legalstart.tn/le-plan-comptable-tunisien/
- Plan comptable tunisien (swiver): https://swiver.io/blog/plan-comptable-tunisien/

**France**
- PCG — règles (comptanat, Art. 1131-1 "ouvrir toute subdivision nécessaire"): https://www.comptanat.fr/pcg/regles.htm
- Plan Comptable Général (France) — Wikipédia (structure, comptes de liaison 18): https://fr.wikipedia.org/wiki/Plan_comptable_g%C3%A9n%C3%A9ral_(France)
- Plan Comptable Général — ANC (autorité): https://www.anc.gouv.fr/plan-comptable-general
- Arrêté du 21 juillet 2020 (sous-comptes OU comptabilité analytique) — Légifrance: https://www.legifrance.gouv.fr/jorf/id/JORFTEXT000042165230
- Mise en place d'une comptabilité analytique (AMUE recueil): https://www.amue.fr/fileadmin/amue/documents-publications/amue/ComptaAna/ComptaAnaRecueil.pdf
- Comptabilité analytique — Wikipédia: https://fr.wikipedia.org/wiki/Comptabilit%C3%A9_analytique

**Morocco / Algeria**
- CGNC (Maroc) — texte officiel PDF: http://befec.ma/documentation/comptabilite/Plan_comptable/CGNC/cgnc.pdf
- Plan comptable PCGE marocain (IZRI guide — structure / subdivisions): https://guide.izri.ma/plan-comptable-pcge-maroc/
- Plan Comptable Algérien SCF (Loi 07-11) — règles de fonctionnement (adesk PDF): https://www.adesk.dz/adesk%20Fonctionnement%20de%20comptes%20SCF.pdf
- Manuel SCF (univ-tlemcen PDF): https://elearn.univ-tlemcen.dz/pluginfile.php/76137/mod_resource/content/1/MANUEL%20SCF%20CNC%202014%2072%20(2).pdf

**ERP / dimensions vs sub-accounts practice**
- Oracle Cloud ERP — Chart of Accounts design considerations: https://blogs.oracle.com/erp-ace/oracle-cloud-erp-chart-of-accounts-design-considerations
- Sage Intacct — Dimensions vs Chart of Accounts: https://inixion.com/dimensions-vs-chart-of-accounts-why-sage-intacct-wins/
- Rand Group — What is dimensional accounting: https://www.randgroup.com/insights/sage/sage-intacct/what-is-dimensional-accounting-and-how-can-it-help-you/
- NetSuite GL / CoA guide: https://www.brokenrubik.com/blog/netsuite-general-ledger-guide
- Microsoft Dynamics 365 F&O — Account structures overview: https://learn.microsoft.com/en-us/dynamics365/finance/general-ledger/configure-account-structures
- ERPNext — Accounting Dimensions: https://docs.erpnext.com/docs/user/manual/en/accounting-dimensions
- QuickBooks Online vs Xero — Class/Location vs Tracking Categories: https://bookkeeper360.com/blog/class-tracking-in-quickbooks-online-and-tracking-categories-in-xero/
- When to use Class & Location in QBO (Lend A Hand): https://lendahandaccounting.com/2024/03/10/class-location-setup-qbo/
- Set up tracking categories — Xero Central: https://central.xero.com/s/article/Set-up-tracking-categories
