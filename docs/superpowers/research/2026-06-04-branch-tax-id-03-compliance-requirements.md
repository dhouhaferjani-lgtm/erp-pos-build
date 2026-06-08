# Branch Tax-ID — 03 Compliance Requirements (per-establishment)

**Date:** 2026-06-04
**Author:** Research subagent (compilation, not spec).
**Scope:** What each modeled country REQUIRES *per establishment / per branch* for its
seller tax identifier on POS receipts/invoices, derived strictly from AutoERP's existing
fiscal research + locked specs. This is a compliance-requirements compilation only — it
does NOT propose a data model or implementation. The downstream spec is a separate doc.

**Sources mined (all in this worktree's `apps/erp.branch-tax-id/`):**
- `docs/superpowers/research/2026-05-20-multicountry-fiscal-research.md` (4-regime field research; FR/KSA/DE/IT)
- `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v3.md` (seller block shape, §7 per-country tax-number table)
- `docs/superpowers/research/2026-05-20-sale-receipt-canonical-payload-synthesis-v5.md` (LOCKED §7 landed per-country table)
- `docs/superpowers/coordination/2026-05-21-codex-handover-phase-1-5-and-phase-3.md` (Phase 1.5.2 per-country regex table with the establishment-number annotations — the sharpest source)
- `docs/pos/COMPLIANCE-GAPS.md` (country-by-country gap analysis incl. Tunisia matricule fiscal, retention)
- `docs/pos/ROADMAP.md` (per-country tax-ID validation tasks)
- `docs/new_docs/02-ROADMAP/TRACK-2-TUNISIA-COMPLIANCE.md` (TN WHT/MatriculeFiscal XML)
- User auto-memory `project_tunisia_nacef_fiscal` (NACEF MDF per-register angle, eff. 1 Jul 2026)

---

## 1. France — SIRET = SIREN (company) + NIC (establishment)

### Structure
- **SIREN** = 9 digits, identifies the **legal entity (company)**. Constant across all the
  company's establishments.
- **NIC** (Numéro Interne de Classement) = 5 digits, identifies a **specific
  establishment** of that company.
- **SIRET** = SIREN (9) + NIC (5) = **14 digits**, identifies one **establishment**.

The handover doc states this directly (Phase 1.5.2 table):

> **FR** | `^([0-9]{9}|[0-9]{14})$` | SIREN (9-digit company root) OR SIRET (14-digit SIREN +
> 5-digit NIC establishment). NF525 v2.1 requires SIRET on the receipt; SIREN acceptable for
> single-establishment businesses. INSEE definitions + Article 88 LF 2016.
> — `coordination/2026-05-21-codex-handover-phase-1-5-and-phase-3.md` line 108

### Per-establishment requirement
- **NF525 v2.1 requires the SIRET on the receipt itself.** Because SIRET = SIREN + the
  branch's NIC, the receipt's SIRET must reflect the **NIC of the establishment that issued
  it.** Two branches of the same company share one SIREN but emit different SIRETs (different
  NIC). So FR is genuinely a **per-establishment** tax-id case at the receipt layer.

Source for the on-receipt SIRET requirement:

> SIRET (seller tax number) required on the receipt itself (Polaris doc).
> — `research/2026-05-20-multicountry-fiscal-research.md` line 23 (§1 France — NF525)

> **Missing from any candidate that lacks seller block:** SIRET must reach the
> printed/archived ticket — this lives at tenant-level in our model but must propagate to the
> audit ticket.
> — `research/2026-05-20-multicountry-fiscal-research.md` line 31

NF525 regulatory grounding (CIAS: inalterable / secured / archived / traceable; Art. 286 CGI,
BOI-TVA-DECLA-30-10-30) — `pos/COMPLIANCE-GAPS.md` §1, lines 52–56.

### Where the locked spec puts it today
The canonical SALE_RECEIPT payload carries a single `seller` block with one `tax_number` +
`tax_jurisdiction_country_code` — there is no notion of "which branch" beyond whatever string
is placed in `seller.tax_number`:

> seller: { ... tax_number: string; // FR SIRET, TN matricule fiscal, KSA 15-digit, IT
> P.IVA, DE USt-ID }  — REQUIRED — NF525 SIRET + TN matricule fiscal
> — `synthesis-v3.md` lines 85–90

> | FR | `^([0-9]{9}|[0-9]{14})$` | SIREN or SIRET for seller/customer tax number. ...
> — `synthesis-v5.md` §7 line 169 (LOCKED)

**Gap implication (for the downstream spec, not decided here):** the spec validates the
*format* of `seller.tax_number` but the value is sourced per-receipt from whatever the device
mirror supplies. For multi-branch FR compliance, the **per-branch NIC must be the authoritative
source of `seller.tax_number`'s last 5 digits** so each branch's receipts carry that branch's
SIRET. The research notes the seller identity "lives at tenant-level in our model but must
propagate to the audit ticket" (line 31) — that tenant-level assumption is exactly what a
per-branch model must refine.

---

## 2. Tunisia — Matricule Fiscal with 3-digit establishment number

### Structure
The Matricule Fiscal embeds an establishment number. The handover doc is the most precise
source in-tree:

> **TN** | `^[0-9]{7,8}[A-Z]{2}[0-9]{3}$` | Matricule Fiscal compact form: 7–8 digits
> (taxpayer #) + 2 letters (category code A/M/N/P + tax-type code M/N/T) + **3-digit
> establishment number (000 = head office)**. Slash-separated form `1234567/A/M/000` MUST be
> normalized to compact at input time. Direction Générale des Impôts Tunisie.
> — `coordination/2026-05-21-codex-handover-phase-1-5-and-phase-3.md` line 109

So a TN Matricule Fiscal = **taxpayer# + category/tax-type letters + 3-digit establishment
suffix**, where `000` = head office and `001`, `002`, … = subsequent establishments. This is
explicitly a **per-establishment** identifier — the same legal taxpayer's branches differ only
in the trailing 3 digits.

The locked spec records the same compact pattern:

> | TN | `^[0-9]{7,8}[A-Z]{2}[0-9]{3}$` | Slash input such as `1234567/A/M/000` normalizes to
> compact `1234567AM000`; canonical producers emit compact.
> — `synthesis-v5.md` §7 line 170 (LOCKED)

`COMPLIANCE-GAPS.md` §2.2.3 also names the matricule fiscal as mandatory on every invoice
(format given there as `XXXXXXX/X/X/X/XXX`) and lists "Matricule fiscal format validation"
as a High-priority gap (lines 215–218, 230–233).

### Per-establishment invoice-series implication
Tunisian invoicing requires an **uninterrupted (continuous) sequential number series**, and
the rule the user references — that **multiple establishments may keep distinct series if
each series is individualized per establishment** — means a multi-branch TN deployment can
(and for clean audit, should) run **one numbering series per establishment**, each
uninterrupted within that establishment.

> **Caveat on sourcing:** the *literal text* of the "distinct series if individualized by
> establishment" rule is NOT quoted verbatim in any in-tree doc I could find — it is the
> user's stated regulatory premise. The in-tree docs corroborate the surrounding
> requirements: sequential/uninterrupted numbering is repeatedly listed as a TN requirement
> (`COMPLIANCE-GAPS.md` §2.1 "Sequential document numbering (implemented)" line 197;
> TRACK-2 lines 77, 137, 224 "Sequential numbering / Certificate numbering (sequential per
> year)"). AutoERP's POS already numbers receipts **sequentially per terminal per year**
> (`COMPLIANCE-GAPS.md` §1.1 line 65: "Unbroken sequence per terminal per fiscal year
> (POS01-2026-00000001)"), which is *finer-grained* than per-establishment and therefore
> satisfies a per-establishment-series requirement as long as terminals map cleanly to
> establishments. **VERIFY the exact "distinct series per establishment" wording with a TN
> accountant before locking the spec** — see Open Questions.

### NACEF angle (the heavier, separate workstream)
Per user auto-memory `project_tunisia_nacef_fiscal` (point-in-time; verify against live law):

- Tunisia's **NACEF** (Système National de Caisses Enregistreuses Fiscales) mandates fiscal
  cash registers for on-premise-consumption businesses; **1 Jul 2026 = ALL legal entities
  (the target segment).**
- Per-ticket signing is **mandatory + synchronous (sign-before-print)** via a homologated
  **MDF** (Module De Fiscalisation, software S-MDF or hardware E-MDF). Our SHA-256 hash chain
  does **NOT** legally substitute for the MDF signature.
- **Per-register relevance to branch tax-id:** NACEF operates a **per-register certificate
  lifecycle** ("certificate-request, synchronization incl. 'Ticket Zéro', electronic-signature"
  per register, over the NACEF APN). A register physically lives at one establishment, so the
  MDF identity/certificate is implicitly establishment-scoped. The matricule fiscal carried on
  the NACEF-signed ticket would still be the issuing establishment's matricule (trailing 3
  digits = that establishment). This reinforces that TN needs a per-establishment seller
  identifier, and adds a per-register/per-establishment certificate dimension on top.
- NACEF is a **distinct compliance program** from El Fatoora/TTN B2B e-invoicing; a café may be
  subject to both. NACEF homologation (dossier, conformity tests, on-site inspection, jibaya.tn
  listing) is lead-time-heavy and out of scope for the branch-tax-id data model itself.

### Where the locked spec puts it today
Same single `seller.tax_number` slot as FR (synthesis-v3 line 89, synthesis-v5 §7 line 170).
The format validator accepts the compact matricule including its 3-digit establishment suffix,
but **nothing in the current model guarantees the suffix matches the branch that issued the
receipt** — that is the per-branch gap the downstream spec must close.

---

## 3. Other modeled countries — company-level vs establishment-level

| Country | Seller tax-id | Per-establishment component? | Source |
|---|---|---|---|
| **Italy (P.IVA)** | Partita IVA = 11 digits | **No.** P.IVA identifies the legal entity, not the establishment. The establishment/device identity is the **RT serial (matricola, 11-char)** + the closure-progressive numbering — those are device-scoped, not part of the *tax number*. | `multicountry-fiscal-research.md` §4 lines 125–129 (matricola dispositivo, Partita IVA dell'esercente); `synthesis-v5.md` §7 line 173 (`^[0-9]{11}$` Partita IVA) |
| **Saudi Arabia (ZATCA VAT)** | 15-digit VAT reg. number `^3[0-9]{12}03$` | **No** at the *VAT number* level (it's the taxpayer's company VAT reg). Per-device identity is handled by **EGS UUID + ICV (Invoice Counter Value, monotonic per EGS device)** and a per-device cryptographic stamp / CSID — i.e. device-scoped chain identifiers, not a branch tax number. | `multicountry-fiscal-research.md` §2 lines 44, 57–63; `synthesis-v5.md` §7 line 171 (`^3[0-9]{12}03$`); handover line 110 |
| **Germany (USt-IdNr)** | `^DE[0-9]{9}$` | **No.** USt-IdNr is the company VAT-ID. Establishment/till identity is carried by DSFinV-K device columns (**Z_KASSE_ID, TERMINAL_ID, KASSE serial / TSE serial**), not by the tax number. EKaBS seller block = company name + tax_number + address. | `multicountry-fiscal-research.md` §3 lines 92, 106, 112; `synthesis-v5.md` §7 line 172 (`^DE[0-9]{9}$`); handover line 111 |
| **United Kingdom** | VAT registration number | **No.** UK invoice rules require the VAT reg number (company-level) + sequential number; no establishment sub-identifier in the tax id. (MTD is a reporting regime, not a per-branch tax-id regime.) | `pos/COMPLIANCE-GAPS.md` §7.1.4 lines 510–519 |

**Pattern:** IT/SA/DE/UK separate the **company tax number** (no branch component) from
**device/till/establishment identity** (RT matricola, EGS UUID+ICV, DSFinV-K Z_KASSE_ID /
TSE serial). Only **FR (SIRET via NIC)** and **TN (matricule 3-digit suffix)** bake the
establishment *into the tax number itself*.

---

## 4. Synthesis — who genuinely needs per-branch tax IDs

### Genuinely per-establishment (the tax number changes per branch)
- **France** — the on-receipt **SIRET embeds the establishment's NIC**; NF525 v2.1 requires
  SIRET on the receipt. Each branch's receipts must carry that branch's SIRET.
- **Tunisia** — the **Matricule Fiscal embeds a 3-digit establishment number** (000 = head
  office); each branch's receipts/invoices must carry that branch's matricule, and (pending TN
  accountant confirmation) per-establishment invoice series are permitted/expected. NACEF adds
  a per-register certificate dimension scoped to the establishment.

### Company-level only (tax number constant; establishment identity lives elsewhere)
- **Italy** (P.IVA constant; establishment = RT matricola + closure progressive)
- **Saudi Arabia** (VAT reg constant; establishment = EGS UUID + ICV + CSID)
- **Germany** (USt-IdNr constant; establishment = DSFinV-K Z_KASSE_ID / TSE serial)
- **United Kingdom** (VAT reg constant)
- (Morocco ICE / Algeria NIF in COMPLIANCE-GAPS §5–6 are also company-level identifiers — no
  establishment sub-field documented.)

### Minimum viable per-branch model to be compliant
Derived from the requirements above (stated as requirements, not as a design decision):

1. **A per-branch seller tax number must exist and be authoritative.** For FR and TN, the
   value differs per establishment (FR: full 14-digit SIRET incl. branch NIC; TN: full compact
   matricule incl. 3-digit establishment suffix). The receipt's `seller.tax_number` must be
   sourced from the **issuing branch**, not from a single tenant/company-wide value.
2. **For company-level countries (IT/SA/DE/UK), one company-level tax number is sufficient** —
   so the model must allow a branch to *inherit* the company tax number when no branch-specific
   identifier exists.
3. **Therefore the minimum viable model is: tax number resolved at the branch layer, with
   fallback to company.** FR/TN populate a branch-specific value; IT/SA/DE/UK leave it null and
   fall back to company. This is the smallest change that keeps the locked canonical payload's
   single `seller.tax_number` slot correct for every modeled country.
4. **Tunisia additionally needs per-establishment-scoped sequential numbering** (already
   satisfiable via the existing per-terminal-per-year sequence if terminals map to
   establishments — confirm).
5. **NACEF (TN, on-premise) needs per-register MDF certificate handling** — a separate
   workstream, but it shares the "establishment-scoped identity" premise.

---

## 5. Compliance must-haves vs nice-to-haves

### Must-haves (block legal compliance in FR or TN if absent)
- **FR:** Receipt `seller.tax_number` = the **issuing branch's SIRET** (SIREN + that branch's
  NIC). Single-establishment FR businesses may use SIREN(9) or SIRET(14); multi-branch MUST use
  the branch SIRET. (NF525 v2.1 — SIRET on receipt.)
- **TN:** Receipt/invoice `seller.tax_number` = the **issuing branch's Matricule Fiscal**
  including the correct 3-digit establishment suffix (000 head office, 001+ branches).
- **TN:** Sequential/uninterrupted invoice numbering; if multiple establishments, per-branch
  series must each be uninterrupted (confirm exact rule — see Open Questions).
- A **branch→company fallback** so company-level-only countries (IT/SA/DE/UK) stay correct
  with one company tax number.

### Nice-to-haves / deferred (do not block FR or TN format compliance now)
- **NACEF MDF integration** (TN on-premise, per-register certificates) — separate heavy
  compliance program; eff. 1 Jul 2026 for legal entities. Not part of the tax-id data model.
- **IT RT matricola / SA EGS UUID+ICV / DE Z_KASSE_ID** establishment-device identifiers —
  needed only when those regimes are actually implemented (all currently deferred per
  synthesis D7/D8 and COMPLIANCE-GAPS roadmap); they are device identifiers, not branch tax
  numbers, so they don't change the branch-tax-id model.
- **Buyer-side per-branch tax ids** — not required; buyer tax number is company-level and only
  when a B2B buyer voluntarily attaches one (synthesis-v3 lines 113–120; handover lines 114–117).
- **Factur-X SIRET validation / PDP routing** (FR Sept-2026 B2B e-invoicing) — separate
  workstream (COMPLIANCE-GAPS §1.4), reuses the same SIRET-per-establishment data once it exists.

---

## 6. Open questions (need owner / accountant resolution before the spec locks)

1. **TN "distinct series per establishment" — exact wording.** The premise that "multiple
   establishments may have distinct invoice series if individualized by establishment" is the
   user's stated rule but is NOT quoted verbatim in any in-tree doc. Confirm the precise DGI
   wording and whether per-establishment series are *permitted*, *required*, or *required-only-if*
   the business opts into branch-level matricules. (AutoERP's existing per-terminal-per-year
   sequence may already satisfy it.)
2. **TN matricule category/tax-type letters.** Handover says `[A-Z]{2}` = "category code
   (A/M/N/P) + tax-type code (M/N/T)" (line 109); synthesis-v3 had an older 3-letter slashed
   placeholder (line 227). Confirm the authoritative letter set with a TN accountant — affects
   the validator regex, not the per-branch structure.
3. **FR single vs multi-establishment policy.** Spec allows SIREN(9) OR SIRET(14). Decide
   whether multi-branch FR tenants are *forced* to SIRET (so a branch can never emit a
   company-only SIREN receipt). NF525 v2.1 implies SIRET on the receipt; confirm the single-
   establishment SIREN allowance is acceptable to the certification body.
4. **Source of truth for the branch tax number.** Where does the per-branch SIRET/matricule
   live and how does it reach the device mirror so `seller.tax_number` is per-branch at
   `engine.append()` time? (The locked synthesis says seller identity currently "lives at
   tenant-level"; a branch model must move/augment this. Resolution belongs to the data-model
   spec, not this compilation.)
5. **NACEF per-register certificate vs branch tax-id model interplay** — whether the
   branch-tax-id work should reserve a hook for the per-register MDF certificate, or keep them
   fully separate. Defer to the NACEF workstream owner.

---

**End of compilation.** This is a requirements survey only; the per-branch tax-id data model
and validation spec is a separate document.
