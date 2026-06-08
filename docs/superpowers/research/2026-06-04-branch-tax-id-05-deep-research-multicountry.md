# Branch / Establishment-Level Tax IDs — Deep Multi-Country Research

> Doc 05 of the `branch-tax-id` research series. Companion to docs 01–04
> (model & flow, canonical payload impact, compliance requirements, numbering series).
> Date: 2026-06-04. Author: research subagent (Opus 4.8).

## Purpose & method

Question driving this doc: **how broadly do the countries AutoERP serves (or plans to
serve) assign a distinct tax / registration identifier per BRANCH / ESTABLISHMENT, and
do any of them require per-establishment sequential invoice numbering?** The owner's
prior is that per-branch tax IDs are "common"; this doc confirms or tempers that with
sourced data.

Method: WebSearch + WebFetch against tax-authority sites and official guidance where
reachable, secondary tax-compliance vendors otherwise. Each claim is cited inline.
Conflicts and weak sources are flagged explicitly. Primary-law citations were obtained
for France (INSEE), Tunisia (Code de la TVA via jurisitetunisie), Algeria (decree
97-396 secondary refs), and the EU VAT Directive Art. 226.

**Adversarial-verification posture:** I deliberately separated "has a per-establishment
identifier in the tax-ID structure" from "must individualize the *invoice series* per
establishment." The owner's framing conflates them. The data shows the first is common;
the second is almost always *permissive, not mandatory* — see §2 and the Tunisia caveat.

---

## §1. Country-by-country: per-establishment tax / establishment IDs

**Legend for "per-establishment ID?":**
- **Structural** = the establishment number is *embedded in* the company tax/registration ID (France SIRET, Morocco ICE, Tunisia MF).
- **Separate-but-linked** = the establishment gets its own distinct identifier alongside the company ID (Algeria NIF for separate-subject branches).
- **Operational tag** = no new ID, but a branch *code* must be carried in fiscal/e-invoicing payloads (Egypt branch code, ZATCA branch CRN).
- **No (entity-level)** = tax identity is purely company-level; branches share it.

| Country | Per-establishment ID? | What it is | Must it appear on the fiscal receipt / invoice? | Source |
|---|---|---|---|---|
| **France** | **Yes — structural** | **SIRET** = 9-digit SIREN (company) + 5-digit **NIC** (establishment: 4-digit rank + 1 check). One SIREN, as many SIRETs as establishments. | SIRET (the issuing establishment's) is a mandatory mention on invoices; SIREN/SIRET appears on receipts. The *establishment's* SIRET is the natural per-branch identifier. | [INSEE definition](https://www.insee.fr/en/metadonnees/definition/c1841); [SIRET — Wikipedia](https://en.wikipedia.org/wiki/SIRET_code) |
| **Tunisia** | **Yes — structural** | **Matricule Fiscal**: 7-digit + control letter, then TVA code, category code, and a **3-digit establishment number** — `000` = siège (HQ), `001`–`999` = secondary establishments. | Matricule fiscal of the taxpayer is a mandatory invoice mention (art. 18 Code TVA); the establishment suffix is part of that MF. | [LookupTax TN guide](https://lookuptax.com/docs/tax-identification-number/tunisia-tax-id-guide); [Code TVA art.18 (jurisitetunisie)](https://www.jurisitetunisie.com/tunisie/codes/tva/tva1060.htm); MF example `001985G A M 000` in [CMF SFBT report](https://www.cmf.tn/sites/default/files/pdfs/emetteurs/informations/rapports-societes/rapport_sfbt_2021.pdf) |
| **Morocco** | **Yes — structural** | **ICE** (Identifiant Commun de l'Entreprise): 15 digits = 9 company-specific + **4 digits identifying the establishment** (`0000` for single-establishment firms; distinct per establishment otherwise) + 2 check. | **Yes, mandatory on all invoices since 2019 Finance Law**, for *both seller and buyer*. Omission → loss of deductibility of the charge and associated VAT. | [WeCount ICE](https://wecount.ma/fr/ice-identifiant-commun-de-lentreprise); [OMPIC](http://www.ompic.ma/fr/content/identifiant-commun-de-lentreprise) |
| **Algeria** | **Yes — separate-but-linked** | **NIF** is 15 positions, but **20 positions when a branch / secondary establishment is a *separate tax subject*** (the extra positions identify the secondary establishment). **NIS** (statistical) = 15-digit parent NIS + 3-digit sequential order number per secondary establishment (decree 97-396 art.5). | Both NIF and secondary-establishment numbers must be quoted in correspondence with public bodies concerning those establishments (decree 97-396 art.25); NIF is a required invoice mention. | [Legal-doctrine NIF DZ](https://legal-doctrine.com/en/edition/le-numero-identification-fiscale-nif-en-algerie); [Legal-doctrine NIS DZ](https://legal-doctrine.com/en/edition/attribution-du-numero-d-identification-statistique-en-algerie) |
| **Egypt** | **Operational tag (no new tax ID)** | Company has one 9-digit **TRN**. For e-invoicing, ETA requires at least one **branch**, the main branch coded **`0`**; the issuing branch's registration code must be included in the e-invoice. | The branch code is a required field in the **e-invoice document** (ETA system), not a separate tax number. TRN itself is company-level. | [LookupTax EG guide](https://lookuptax.com/docs/tax-identification-number/egypt-tax-id-guide); [iX ERP Egypt e-invoice setup](https://wiki.ixerp.net/wiki/egypt-tax-e-invoice-setup/) |
| **Saudi Arabia (ZATCA)** | **Operational tag (no new VAT number)** | 15-digit VAT/TIN is **entity-level** (digits 10–12 carry a subsidiary/serial segment, and 11th digit `1` flags a *VAT group*). Branches are distinguished in e-invoicing by the **branch CRN** in `supplier.identification`, not by a separate VAT number. Group members are tagged via `X-ZATCA-Branch` = member TIN. | Branch CRN goes in the **Phase-2 (FATOORA) cleared/signed e-invoice** supplier identification; VAT number is shared with parent. | [ZATCA branches & group VAT (Wafeq docs)](https://zatca.wafeq.com/docs/branches-and-group-vat); [Fonoa KSA guide](https://www.fonoa.com/resources/country-tax-guides/saudi-arabia) |
| **UAE** | **No (entity-level)** | **One TRN per legal entity**, irrespective of branches/activities. Sole establishments/branches cannot be separate VAT-group members (treated as same person). | No per-branch tax ID; single TRN on invoices. | [IMC TRN Dubai](https://intuitconsultancy.com/ae/understanding-tax-registration-number-trn-in-dubai/); [FTA Tax Group registration](https://tax.gov.ae/en/services/tax.group.registration.aspx) |
| **Italy** | **No (entity-level for VAT)** | One **Partita IVA** per company. Multiple locations (**unità locali**) are registered at the Registro Imprese / **REA**, each with its own REA identifier, but **VAT identity is the single P.IVA**. The per-branch concept lives as an invoice **sezionale** (numbering series), not a separate VAT ID. | P.IVA on invoice is mandatory; the unità locale / REA is *not* a per-branch tax ID on the fiscal invoice. | [Agenzia Entrate VAT registration](https://www.agenziaentrate.gov.it/portale/web/english/vat-registration-in-italy); [LookupTax IT guide](https://lookuptax.com/docs/tax-identification-number/italy-tax-id-guide) |
| **Spain** | **No (entity-level)** | One **NIF / CIF** per company; VAT number = `ES` + NIF. Branches operate under the parent NIF. | Single company NIF on invoices. | [Fonoa Spain guide](https://www.fonoa.com/resources/country-tax-guides/spain); [Spanish NIF/VAT guide](https://vatgreentax.com/en/spanish-nif-and-vat-number-guide/) |
| **Germany** | **No (entity-level)** — *caveat* | One **USt-IdNr** (VAT ID) per legal entity. The **Steuernummer** can change/multiply by Finanzamt jurisdiction or by separate business, but that is *not* a per-branch establishment ID for invoicing. | USt-IdNr / Steuernummer on invoice is company-level. | [Marosa DE: Steuernummer vs USt-IdNr](https://marosavat.com/manual/vat/germany/difference-steuernummer-vat-id-numbers-ust-idnr/) |
| **UK** | **Mostly No — optional branch suffix** | Standard VAT number = `GB` + 9 digits (entity-level). **Branch traders** and VAT-group members may use a **12-digit** form (`GB` + 9 + **3-digit branch suffix**, e.g. `GB123456789001`). Divisions of a body corporate *may* register separately, each with its own VAT number. | When used, the 12-digit branch form appears on that branch's invoices; but it is **opt-in**, not mandatory for ordinary branches. | [HMRC design pattern – VAT number](https://design.tax.service.gov.uk/hmrc-design-patterns/vat-registration-number/); [GOV.UK groups/divisions](https://www.gov.uk/guidance/vat-registration-for-groups-divisions-and-joint-ventures) |
| **Turkey** | **No (entity-level)** *(low confidence on branch specifics)* | One 10-digit **VKN** per legal entity; e-Fatura is clearance-based. Branch (`şube`) handling not authoritatively documented in sources found — **needs confirmation**. | VKN on invoice is company-level (per sources found). | [Fonoa Türkiye guide](https://www.fonoa.com/resources/country-tax-guides/turkiye) — branch detail **NOT** authoritatively sourced |
| **Senegal (UEMOA)** | **No (entity-level)** *(branch handling unconfirmed)* | **NINEA** identifies the enterprise; sources state it "contains no characteristic code identifying the unit." Per-establishment behaviour not documented in sources found — **needs confirmation**. | NINEA on invoice is enterprise-level (per sources found). | [DGID Senegal: Qu'est-ce que le NINEA](http://www.impotsetdomaines.gouv.sn/fr/quest-ce-que-le-ninea) — establishment detail **NOT** sourced |

### Breadth finding (answering Q1 / Q3a)

The owner's intuition is **partially right but needs tempering**:

- **A per-establishment identifier embedded in or alongside the company tax ID is genuinely
  common in the Francophone / Maghreb cluster AutoERP targets**: **France (SIRET/NIC),
  Tunisia (MF establishment suffix), Morocco (ICE establishment digits), Algeria
  (NIF extended for separate-subject branches, NIS sequential suffix)** all have it, and
  in Morocco/Tunisia/Algeria the relevant ID is a **mandatory invoice mention**.
- **It is NOT universal.** **UAE, Spain, Italy, Germany** are firmly **single-VAT-number-
  per-entity**; branches share the company VAT ID. **UK** has only an *optional* branch
  suffix. **Egypt and Saudi Arabia** carry a **branch code/CRN in the e-invoice payload**
  but do **not** mint a separate per-branch *tax number*.

So: **the design must support a per-establishment identifier, but must NOT assume every
country issues a distinct branch tax number.** The right data model is "company tax ID +
optional establishment identifier/segment," where the establishment part is sometimes
structural (France/Tunisia/Morocco), sometimes a separate linked ID (Algeria), sometimes
just a branch *code* carried in the fiscal payload (Egypt/KSA), and sometimes absent
(UAE/ES/IT/DE/UK-default).

---

## §2. Per-branch / per-establishment sequential invoice numbering

### The EU baseline (Art. 226 VAT Directive)

EU invoices must carry **"a sequential number, based on one or more series, which uniquely
identifies the invoice."** Crucially, the unique number **may be based on one or more
series** — so multiple series (e.g. per branch, per device, per document type) are
*permitted* as long as each invoice is uniquely identified. The directive does **not
mandate** per-establishment series. ([VATupdate Art. 226](https://www.vatupdate.com/2022/05/12/eu-vat-directive-2006-112-ec-explained-art-226-content-of-an-invoice/))

### France — NF525 / anti-fraud law (chaining is per *device*, not per branch)

France's requirement is **inalterability + chaining (chaînage) + continuous numbering**,
enforced per **cash register / record type**, not per legal establishment. From the
NF525 mechanics: *"the next sequential number is selected for the same register and the
same record type, and the previous signature for the same register and record type is
added"* — i.e. the signature chain and the sequential counter are **per terminal/register
+ per record type**. The Z-report sequence is likewise "numbered without break in
sequence" per terminal. ([NF525 mechanics — digabloPos](https://digablopos.fr/fr/blog/rapport-z-caisse-enregistreuse);
[infocert NF525](https://infocert.org/en/nf525/); underlying law: anti-VAT-fraud art.88 /
[BOI-TVA-DECLA-30-10-30 BOFiP](https://bofip.impots.gouv.fr/bofip/10691-PGP.html/identifiant=BOI-TVA-DECLA-30-10-30-20210519)).

**Verdict for France:** the hard requirement is **per-device** sequential + chained
numbering, which is *finer-grained than* per-branch and operationally implies branch
separation (devices live in branches) but is **not legally framed as "per establishment."**

### Tunisia — uninterrupted series mandatory; per-establishment series PERMISSIVE

This is the claim the task flagged for adversarial scrutiny, and the scrutiny matters:

- **What the Code de la TVA (art. 18) actually mandates:** *"d'utiliser des factures
  numérotées dans une **série ininterrompue**"* — invoices in an **uninterrupted series**.
  An uninterrupted series may be year-suffixed (e.g. `.../92`) or run without a limit.
  ([Code TVA art.18, jurisitetunisie](https://www.jurisitetunisie.com/tunisie/codes/tva/tva1060.htm))
- **On multi-establishment firms, the *primary-law text* speaks of separate *monthly
  turnover declarations* per distinct establishment** — **not** a separate invoice series.
  My direct read of the article (via WebFetch) found **no clause in the code mandating a
  distinct invoice series per establishment.**
- **The "individualized per establishment" framing comes from practitioner guidance, and
  it is PERMISSIVE, not mandatory.** A Tunisian accounting source states: *"Une entreprise
  à succursales **peut** avoir une série distincte par établissement, mais chaque série
  doit rester ininterrompue et identifiable."* ("…**may** have a distinct series per
  establishment, but each series must stay uninterrupted and identifiable.")
  ([Swiver — Facturation en Tunisie](https://swiver.io/blog/facturation-en-tunisie-les-obligations-legales/))

> **VERDICT (Tunisia numbering):** The mandatory rule is **one uninterrupted series**.
> A **per-establishment** series is an **allowed option**, not a legal requirement. The
> "individualized by establishment" phrasing is real but describes an *option for
> multi-branch firms to keep separate identifiable series*, not an obligation.
> **This nuance should be treated as the load-bearing finding and confirmed with a
> Tunisian accountant before the spec hard-codes per-establishment series.**
> Marked: **needs local-accountant confirmation.**

### Italy — sezionali are optional

Italy requires a **progressive number, unique and without interruptions** for the tax
period; structure is free. Multiple **sezionali** (series distinguished by a suffix, e.g.
`01/2026/a`) are **available** for managing different points of sale or activities, with
the rule that within a sezionale no two invoices share a progressive number. Sezionali are
a **tool, not a mandate**. ([Aruba — sezionale e progressivo](https://guide.pec.it/fatturazione-elettronica/creazione-fatture-documenti/inserimento-campi/dati-documento/sezionale-progressivo.aspx);
[European VAT Desk — Italy numbering](https://vatdesk.eu/en/eu-countries-vat/italy-mandatory-mentions-on-invoice/))

### GCC (KSA/UAE)

No per-establishment invoice-series mandate surfaced. ZATCA Phase-2 enforces per-document
integrity (hash chaining of the cleared invoice set via the previous invoice hash /
ICV — invoice counter value), which is an **entity/solution-unit-level** counter, and
branches are tagged by CRN rather than getting a distinct legal numbering series.
([ZATCA branches & group VAT](https://zatca.wafeq.com/docs/branches-and-group-vat))

### Numbering verdict (answering Q2 / Q3b)

**Per-establishment sequential invoice numbering is NOWHERE a hard, standalone legal
requirement among the surveyed countries.** What *is* mandatory is:

- **Uninterrupted / gap-free sequential numbering** (EU Art.226, Tunisia, Italy) — at
  *company or series* level.
- **Per-device chained + sequential numbering** in France (NF525) and an equivalent
  **per-solution-unit hash chain** in KSA Phase-2 — both **finer than per-branch**.

Per-establishment / per-POS series (sezionali, "série distincte par établissement",
NF525 per-register counters) are **permitted and operationally common, but the obligation
is "uninterrupted & uniquely identifying," not "one series per branch."**

---

## §3. Signed/fiscalized receipt vs printed/B2B invoice (answering Q3c)

Where does the branch identifier have to live — in the *cryptographically signed/fiscalized*
artifact, or only in the human-readable printed/B2B invoice?

| Country | Branch ID required in the SIGNED/FISCALIZED artifact? | Notes |
|---|---|---|
| **France** | **Effectively yes (per device).** The NF525 signature/chaining is computed per **register**, and the register lives in a branch; the signed journal/ticket is tied to that terminal. The branch's **SIRET** is also an invoice mention. | The fiscalized object is per-device; branch separation is implicit in the device identity. |
| **Saudi Arabia** | **Yes.** The branch **CRN** sits in `supplier.identification` inside the **Phase-2 cleared & cryptographically signed e-invoice**. | Branch identity is *inside* the signed XML, not just the print. |
| **Egypt** | **Yes (in the ETA-submitted e-document).** Issuing branch code is a required field of the e-invoice submitted/validated by ETA. | Egypt's e-invoice is the authoritative fiscal record. |
| **Morocco** | **Printed/B2B invoice (no national clearance yet).** ICE (with establishment digits) is mandatory on the *invoice*; Morocco has no live nationwide e-invoice clearance signing the document at issue time (as of sources found). | So the branch identifier is in the **printed/B2B invoice**, not a national signed artifact. |
| **Tunisia** | **Printed/B2B invoice.** MF (with establishment suffix) is a mandatory *invoice* mention. No general fiscal-signing of each retail ticket today — but **NACEF on-prem fiscal-POS law (eff. 1 Jul 2026)** will require each ticket be signed/transmitted via a homologated MDF; whether the MDF payload must carry the establishment suffix is **open and needs confirmation**. | Cross-ref the NACEF workstream; this is the place the branch ID could migrate into a *signed* artifact. |
| **Italy / Spain / Germany / UAE / UK** | **N/A — no per-branch tax ID** to place anywhere (single entity VAT number). Italy's signed FatturaPA carries the single P.IVA. | — |

**Takeaway:** in the two clearance regimes AutoERP is most likely to hit for branches
(**KSA, Egypt**), the branch identifier is **inside the signed/cleared e-document**. In the
Maghreb invoice-mention regimes (**Morocco, Tunisia today**), it lives in the
**printed/B2B invoice**. France straddles: the fiscalized object is per-device and the
branch SIRET is an invoice mention. **Tunisia's NACEF (2026) is the watch-item** that could
move the Tunisian establishment suffix into a signed artifact.

---

## §4. Conflicts, weak spots, and confidence flags

- **[HIGH confidence]** France SIRET/NIC, Tunisia MF establishment suffix, Morocco ICE
  establishment digits, Algeria NIF/NIS secondary-establishment numbering, EU Art.226
  "one or more series," KSA branch-by-CRN, UAE one-TRN-per-entity, Italy sezionali optional.
  Multiple independent sources incl. tax-authority / primary-law refs.
- **[MEDIUM-HIGH, with the key caveat]** **Tunisia per-establishment invoice series is
  PERMISSIVE, not mandatory.** Primary code text (art.18) mandates only "série
  ininterrompue"; the per-establishment framing is practitioner guidance using *peut*
  ("may"). **Flagged: needs Tunisian-accountant confirmation** before the spec treats
  per-establishment series as a requirement. Do **not** let the owner's "common" intuition
  harden this into a mandate.
- **[MEDIUM]** France NF525 "per register/record-type" chaining is sourced from NF525
  vendor/explainer material consistent with the law, but I did not extract the exact
  clause from the full AFNOR NF525 spec or the complete BOFiP text. Direction is certain;
  exact wording would need the spec PDF.
- **[LOW confidence — gaps]** **Turkey** branch (`şube`) tax-ID handling and **Senegal/UEMOA**
  per-establishment NINEA behaviour were **not authoritatively resolved**. Sources indicate
  entity-level identifiers, but branch specifics are unconfirmed. **Needs confirmation** if
  these markets become near-term targets.
- **Germany caveat:** Steuernummer can multiply by Finanzamt — do **not** mistake this for a
  per-branch establishment ID; it isn't one for invoicing purposes.

---

## §5. What this means for the spec

1. **Model: "company tax ID + optional establishment identifier," with a typed shape, not
   a single string.** Support three establishment-ID modes per country:
   (a) **structural segment** of the company ID (France NIC, Tunisia 3-digit suffix,
   Morocco 4-digit ICE segment); (b) **separate linked ID** (Algeria extended NIF / NIS
   suffix); (c) **operational branch code/CRN** carried only in the fiscal/e-invoice
   payload (Egypt branch code, KSA branch CRN). And a fourth, **"none"** (UAE, ES, IT, DE,
   UK-default) where branches inherit the single entity VAT number.

2. **Do NOT assume a distinct per-branch *tax number* exists.** Treat the establishment
   identifier as **optional and country-driven**. The "common" intuition holds for the
   Maghreb/Francophone cluster but is false for GCC-VAT/EU single-number countries.

3. **Per-establishment sequential numbering is NOT a hard legal requirement anywhere.**
   Implement **uninterrupted, uniquely-identifying** numbering as the invariant, and make
   **per-branch / per-POS series an *optional* configuration** (maps cleanly to Italy
   sezionali, Tunisia optional per-establishment series, France per-device counters).
   The mandatory finer-grained rule that *does* exist is **per-device chaining (France
   NF525) and per-solution-unit hash chaining (KSA Phase-2)** — already aligned with
   AutoERP's device-authority + two-tier hash-chain design; keep counters/chains keyed by
   **device**, with branch as an attribute, not as the chaining key.

4. **Place the branch identifier where the regime fiscalizes:** for **KSA/Egypt** it must
   be **inside the signed/cleared e-document** (supplier identification / branch code);
   for **Morocco/Tunisia (today)** it's a **printed/B2B invoice** mention; for **France**
   it's both (per-device signed journal + branch SIRET as invoice mention). The canonical
   sale-receipt payload (see docs 02/03) should therefore carry the establishment
   identifier as a **first-class field available to both the signed payload and the print
   layer**, not bolt it onto print only.

5. **Two explicit confirmation gates before locking the spec:**
   - **Tunisia:** confirm with a local accountant whether per-establishment invoice series
     is ever *mandatory* (sources say permissive) — and how **NACEF (1 Jul 2026)** treats
     the establishment suffix in the MDF-signed ticket.
   - **Turkey & Senegal/UEMOA:** confirm branch handling **only if** they become near-term
     targets; current evidence says entity-level but is not conclusive.

---

## Sources

- [INSEE — SIRET definition](https://www.insee.fr/en/metadonnees/definition/c1841)
- [SIRET code — Wikipedia](https://en.wikipedia.org/wiki/SIRET_code)
- [LookupTax — Tunisia MF guide](https://lookuptax.com/docs/tax-identification-number/tunisia-tax-id-guide)
- [Code de la TVA art.18 — jurisitetunisie](https://www.jurisitetunisie.com/tunisie/codes/tva/tva1060.htm)
- [Swiver — Facturation en Tunisie : obligations légales](https://swiver.io/blog/facturation-en-tunisie-les-obligations-legales/)
- [CMF — SFBT report (MF example 001985G A M 000)](https://www.cmf.tn/sites/default/files/pdfs/emetteurs/informations/rapports-societes/rapport_sfbt_2021.pdf)
- [WeCount — ICE Maroc](https://wecount.ma/fr/ice-identifiant-commun-de-lentreprise)
- [OMPIC — Identifiant Commun de l'Entreprise](http://www.ompic.ma/fr/content/identifiant-commun-de-lentreprise)
- [Legal-doctrine — NIF Algérie](https://legal-doctrine.com/en/edition/le-numero-identification-fiscale-nif-en-algerie)
- [Legal-doctrine — NIS Algérie](https://legal-doctrine.com/en/edition/attribution-du-numero-d-identification-statistique-en-algerie)
- [LookupTax — Egypt TRN guide](https://lookuptax.com/docs/tax-identification-number/egypt-tax-id-guide)
- [iX ERP — Egypt e-invoice setup](https://wiki.ixerp.net/wiki/egypt-tax-e-invoice-setup/)
- [ZATCA — Branches and Group VAT (Wafeq docs)](https://zatca.wafeq.com/docs/branches-and-group-vat)
- [Fonoa — Saudi Arabia VAT guide](https://www.fonoa.com/resources/country-tax-guides/saudi-arabia)
- [IMC — TRN in Dubai](https://intuitconsultancy.com/ae/understanding-tax-registration-number-trn-in-dubai/)
- [FTA — Tax Group registration (UAE)](https://tax.gov.ae/en/services/tax.group.registration.aspx)
- [Agenzia delle Entrate — VAT registration in Italy](https://www.agenziaentrate.gov.it/portale/web/english/vat-registration-in-italy)
- [LookupTax — Italy tax ID guide](https://lookuptax.com/docs/tax-identification-number/italy-tax-id-guide)
- [Aruba — sezionale e progressivo](https://guide.pec.it/fatturazione-elettronica/creazione-fatture-documenti/inserimento-campi/dati-documento/sezionale-progressivo.aspx)
- [European VAT Desk — Italy invoice numbering](https://vatdesk.eu/en/eu-countries-vat/italy-mandatory-mentions-on-invoice/)
- [Fonoa — Spain VAT guide](https://www.fonoa.com/resources/country-tax-guides/spain)
- [Spanish NIF/VAT guide](https://vatgreentax.com/en/spanish-nif-and-vat-number-guide/)
- [Marosa — Germany Steuernummer vs USt-IdNr](https://marosavat.com/manual/vat/germany/difference-steuernummer-vat-id-numbers-ust-idnr/)
- [HMRC design pattern — VAT registration number](https://design.tax.service.gov.uk/hmrc-design-patterns/vat-registration-number/)
- [GOV.UK — VAT registration for groups, divisions and joint ventures](https://www.gov.uk/guidance/vat-registration-for-groups-divisions-and-joint-ventures)
- [Fonoa — Türkiye VAT guide](https://www.fonoa.com/resources/country-tax-guides/turkiye)
- [DGID Senegal — Qu'est-ce que le NINEA](http://www.impotsetdomaines.gouv.sn/fr/quest-ce-que-le-ninea)
- [VATupdate — EU VAT Directive Art.226](https://www.vatupdate.com/2022/05/12/eu-vat-directive-2006-112-ec-explained-art-226-content-of-an-invoice/)
- [infocert — NF525 certification](https://infocert.org/en/nf525/)
- [digabloPos — Ticket Z et NF525 (per-register sequence/chaining)](https://digablopos.fr/fr/blog/rapport-z-caisse-enregistreuse)
- [BOFiP — BOI-TVA-DECLA-30-10-30](https://bofip.impots.gouv.fr/bofip/10691-PGP.html/identifiant=BOI-TVA-DECLA-30-10-30-20210519)
