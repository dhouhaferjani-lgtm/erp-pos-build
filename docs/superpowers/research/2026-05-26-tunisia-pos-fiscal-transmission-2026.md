# Tunisia POS / Fiscal Cash-Register Data Transmission to the Tax Authority (effective ~1 July 2026)

> Research note for the AutoERP / IziPOS / Otospex fiscal-event engine.
> Author: research agent. Date compiled: **2026-05-26**.
> Subject system in Tunisia: **NACEF** — *Système NAtional de Caisses Enregistreuses Fiscales*.

---

## Summary / TL;DR

- **What:** Tunisia mandates a certified ("homologuée") fiscal cash-register system, branded **NACEF**, for on-premise food/drink consumption businesses. Each cash register must be paired with a **Module de Données Fiscales (MDF)** that **electronically signs every ticket before it is printed** and transmits sales data to a **central platform built by the Ministry of Finance's Centre Informatique (CIMF)**.
- **Legal chain:** Article 48 of the **2016 Finance Law** → **Décret gouvernemental n° 2019-1126 du 26 novembre 2019** (implementation modalities) → **Arrêté de la ministre des Finances du 14 octobre 2025** (JORT n° 125, signed by Mechket Slama Khaldi) setting the phased deadlines. Penalties via **Article 94 of the Code des Droits et Procédures Fiscaux (CDPF)**.
- **When:** Phased. **1 Nov 2025** (tourist-classified restaurants, tea salons, 2nd/3rd-category cafés) → **1 July 2026** (all other *personnes morales* / legal entities doing on-premise consumption) → **1 July 2027** (*personnes physiques* under the real regime filing monthly) → **1 July 2028** (remaining *personnes physiques*).
- **Who:** Businesses selling prepared/ready-to-consume food or drink **for consumption on the premises** ("consommation sur place") — restaurants, cafés, tea salons (salons de thé) and similar. The user's intuition is correct.
- **DECISIVE real-time-vs-deferred answer:** **Both modes exist, but the legal default is a permanent live connection.** The NACEF FAQ states the platform supports **"Online" and "Offline"** modes, and that the taxpayer must maintain **"une communication permanente et sans interruption entre les modules de la caisse et la plateforme NACEF."** Offline is a **degraded/temporary fallback**: tickets are still signed locally by the MDF (with an "OFFLINE" QR code) and **queued for transmission when connectivity returns.** So: **per-ticket cryptographic signing is mandatory and synchronous (before print); transmission to the State can be momentarily deferred only as a fault-tolerance fallback, not as a designed batch model.** This is NOT a "daily Z-report upload is sufficient" regime like some other countries.
- **Confidence:** HIGH on obligation/dates/scope/penalties (multiple Tunisian press + JORT references converge). MEDIUM-HIGH on the technical/transmission model (sourced from the official NACEF FAQ at `caisse-enregistreuse.nacef.tn`, which is authoritative but a FAQ, not the raw cahier des charges). The exact **wire protocol, payload schema, and sync cadence** are behind the supplier portal (`homologation.nacef.tn`) and require a registered editor account to download — **not independently verified here.**

---

## The obligation + dates

### Legal basis (the chain)

| Instrument | Role | Source confidence |
|---|---|---|
| **Article 48, Loi de Finances 2016** | Primary statutory obligation to use a fiscal cash register for on-premise consumption services. | HIGH (NACEF/jibaya.tn cites it directly) |
| **Décret gouvernemental n° 2019-1126 du 26 novembre 2019** | Defines practical modalities of the cash-register system. | HIGH (cited by JORT-referencing press + NACEF) |
| **Arrêté de la ministre des Finances du 14 octobre 2025** (JORT n° 125, signed Mechket Slama Khaldi) | Sets the phased compliance deadlines below. | HIGH (Kapitalis / WMC cite JORT n°125 + minister name) |
| **Article 94, Code des Droits et Procédures Fiscaux (CDPF)** | Penalties for non-compliance / tampering. | HIGH |

### Phase-in schedule

| Date | Who must comply |
|---|---|
| **1 November 2025** | Restaurants classés touristiques (tourist-classified restaurants), salons de thé, and **cafés de 2ᵉ et 3ᵉ catégorie**. |
| **1 July 2026** | **All other *personnes morales* (legal entities)** carrying out on-premise consumption activities. |
| **1 July 2027** | *Personnes physiques* under the **régime réel** who file **monthly** tax returns. |
| **1 July 2028** | All remaining *personnes physiques* in the sector. |

> Note: a 1 July 2026 deadline = **the user's stated target date is confirmed**, specifically for **legal entities** (sociétés). Sole proprietors get later dates depending on tax regime.

---

## Scope (who is in scope)

**In scope:** Establishments "vendant des plats ou boissons préparés ou prêts à consommer, et offrant un service sur place" — i.e. selling prepared or ready-to-consume food or drink **with on-premise table/counter service**. Explicitly named categories:

- Restaurants (incl. tourist-classified restaurants first)
- Cafés (the café-category classification — 1ʳᵉ/2ᵉ/3ᵉ catégorie — drives *ordering within the phase-in*, with 2ᵉ/3ᵉ category cafés in the first wave)
- Salons de thé (tea rooms)
- "et établissements similaires" — similar establishments offering restauration/consommation sur place

**Driver of obligation:** the **"consommation sur place"** (on-premise consumption) service characteristic, anchored in Article 59 of the IRPP/IS code definition of the activity per English-language coverage (Ecofin/webdo). The user's belief — *mainly restaurants, cafés/coffee shops with in-place consumption* — is **correct**.

**Thresholds / exemptions:**
- The phase-in itself is the main differentiator (legal-entity vs. real-regime individual vs. other individual), **not a revenue threshold** in the sources reviewed.
- No explicit revenue/turnover exemption threshold was found in the press/official FAQ material. **This is an OPEN QUESTION** — verify whether pure takeaway/delivery-only (no on-premise service) or micro/forfaitaire taxpayers are out of scope. Sources tie the obligation to the *on-premise service* characteristic and to tax-regime tiers rather than to a sales floor.
- Confidence: MEDIUM on "no revenue threshold" (absence of evidence, not evidence of absence).

---

## Real-time vs deferred (the decisive answer + evidence)

### The answer

**Per-transaction local fiscal signing is mandatory and synchronous; transmission to the State is designed to be permanent/live, with offline buffering allowed only as a temporary fault-tolerance fallback.** There is no sanctioned "just upload a daily batch/Z-report" mode that exempts a device from live connection.

### Evidence (from the official NACEF FAQ, `caisse-enregistreuse.nacef.tn`, accessed 2026-05-26)

1. **Two modes exist:**
   > "La plateforme NACEF permet une communication selon les deux modes « Online » et « Offline »."

2. **Permanent connection is a stated taxpayer obligation:**
   > taxpayers must ensure "une communication permanente et sans interruption entre les modules de la caisse et la plateforme NACEF."

3. **Every ticket is signed locally before printing (synchronous), regardless of mode:**
   > "Le logiciel de caisse doit enregistrer l'ensemble des données d'encaissement avant l'impression du ticket et garantir leur conservation inaltérable." The S-MDF signs the transaction and returns the QR code **before** the receipt is issued.

4. **Offline = degraded fallback, not a batch design:** When disconnected, the register may operate **temporarily**; tickets carry an **"OFFLINE"** QR code (containing IMDF id, ticket id, operation type, amounts, electronic signature) and data is **queued for transmission when the link returns.** Online tickets carry an **"ONLINE"** QR mention + unique ticket identifier.

5. **Downtime is bounded and reportable:** Taxpayer obligations include reporting malfunctions within ~3 days and a **maximum repair downtime of ~10 days per year**; repairs only by accredited suppliers. This confirms the regime treats disconnection as an exceptional, audited condition — not a normal operating mode.

### How transmission is mediated (architecture)

- The cash software calls three MDF services: **(1) certificate request, (2) synchronization** (authenticates the taxpayer, exchanges data, returns a **"Ticket Zéro"** on first sync), and **(3) electronic signature** (signs the ticket, returns the QR). Error codes are standardized (e.g. **509 SMDF_NOT_SYNCHRONIZED**, **518 expired certificate**).
- Connectivity uses a **dedicated APN network** specific to NACEF (i.e., the device connects to the central platform over a Ministry-designated channel).
- **What is NOT independently confirmed:** whether *each individual ticket* is pushed to the central platform the instant it is signed, or whether signed tickets are streamed/synced in micro-batches over the permanent connection. The "synchronization" service + "Ticket Zéro" + offline queue strongly imply a **continuous-sync model over a kept-alive connection** rather than true per-HTTP-call-per-sale. **Verify against the cahier des charges.**

> Bottom line for product design: **build for live continuous sync with a durable offline outbox/queue.** A pure end-of-day batch uploader will NOT satisfy the "permanent uninterrupted connection" obligation, and the local per-ticket signing-before-print is non-negotiable.

---

## Technical / compliance requirements

From the official NACEF FAQ + homologation portal (`homologation.nacef.tn`). Treat the FAQ as authoritative-but-summary; the binding detail is in the **"Document de spécifications techniques et fonctionnelles"** and **"Manuel des procédures techniques"** downloadable only inside the supplier portal.

### Device / module model
- **MDF — Module de Données Fiscales:** the fiscal core that protects and securely transmits data and signs tickets. Two physical/logical forms:
  - **E-MDF (hardware):** a physical device installed on-site, connected to the register.
  - **S-MDF (software):** Ministry-provided software module; has a **client** component (per terminal) and a **server** component (multi-register sites). Test versions ("S-MDF de tests") are downloadable for integration testing.

### Inalterability / tamper-proofing
- Must record all encashment data **before printing** and guarantee **inalterable retention**: "garantir leur conservation inaltérable."
- The register **must contain no function permitting modification or deletion of transactions**: "ne doit comporter aucune fonction permettant la modification ou la suppression des transactions."
- Tampering, destroying, or falsifying data is an Article-94 CDPF offense.

### Fiscal signature
- The MDF **electronically signs each ticket** before issuance. Signing requires the device to be **synchronized** (cert valid + first "Ticket Zéro" sync done).

### Sequential numbering
- Each ticket has a **unique sequential number**; reprints are flagged **"Ticket copie"** with a distinct number.

### Fiscal journal / audit trail
- A `/log/` service records `UPGRADE`, `CASHING`, `PURGE`, **OFFLINE/ONLINE transitions**, and `SYNC_REQUEST` events. The S-MDF maintains **encrypted audit logs**.

### Certificates & auth
- **Per-register electronic certificate**, issued after an enrollment appointment with the competent Ministry unit; requires connecting the register to the NACEF APN.
- **Software alerts 30 days before certificate expiry**; expired cert → new request required (error 518).
- Auth factors: a **PIN** tied to the certificate (personal authentication factor securing sync) + an **OTP** for critical operations.

### Receipt format (mandatory fields)
- Business identity (trade name, commercial name, tax id / matricule fiscal)
- Transaction number + date
- System identifiers (IMDF id, register id)
- Line items (qty, unit price, VAT rate)
- Totals (gross, tax, discounts), payment method, change (if cash)
- **QR code** — generated by the MDF at signing, **ISO/CEI 18004**, dimensions **170×220 px**; content differs Online vs Offline. Customers verify authenticity via the **NACEF mobile app**.
- All amounts in **Tunisian Dinars (TND)**.

### Homologation / certification scheme (supplier-side)
Steps published on `homologation.nacef.tn`:
1. Soumission du dossier d'homologation
2. Spécifications techniques saisies
3. Evaluation documentaire
4. Evaluation du dossier + compléments d'informations
5. **Tests de conformité** (integration tests against the central system)
6. Rapport de tests établi
7. **Décision d'homologation et émission du certificat**
8. Publication as "Système Homologué" (listed on jibaya.tn)
- Plus **"Contrôle sur place"** (on-site inspection) before certification.
- Supplier accounts (software editors) self-register; obtain test certificates and test S-MDF versions.
- Contact: **homologation-info@nacef.tn**.

### Supplier obligations (relevant to us as a vendor)
- Declare every sale of a register (serial number, customer identity, homologation reference).
- Report maintenance/incidents via the platform.
- Ship only **homologated software versions**; provide updates matching the certified build.
- **Report suspected fraud (phantomware / "zapper" software)** to the Ministry.

### Penalties (Article 94 CDPF)
> "En vertu de l'article 94 du Code des droits et procédures fiscaux, les contrevenants s'exposent à des peines de prison allant de seize jours à trois ans, ainsi qu'à des amendes comprises entre mille et 50.000 dinars."
- Prison: **16 days to 3 years**; fines **1,000–50,000 TND**. Covers not using a register, tampering, destroying/falsifying data.

### Retention / archival
- Encrypted audit logs in the S-MDF; taxpayer must preserve register data + supporting sales documentation, available on demand. **Exact retention period not specified in the FAQ — verify** (the general CDPF/accounting retention is typically 10 years in Tunisia; confirm the NACEF-specific rule).

---

## Relationship to e-invoicing (El Fatoora / TTN) — do not conflate

NACEF (fiscal cash registers for on-premise consumption) is **distinct from** Tunisia's **El Fatoora / TTN electronic-invoicing** mandate (real-time clearance model, **TEIF** format), which is being **expanded to services in 2026**. They are separate obligations with separate platforms:
- **NACEF** = B2C on-premise consumption tickets, MDF-signed, transmitted to the Ministry's CIMF central platform.
- **El Fatoora / TTN** = electronic **invoices** (notably B2B / VAT-registered transactions) cleared through **Tunisie TradeNet (TTN)**.
- A restaurant/café operator could be subject to **both** depending on activity (B2C tickets via NACEF; B2B invoices via TTN). **Confidence MEDIUM** on the exact boundary — flag for the accountant. (Source: VATupdate / KPMG / RTC Suite coverage of LF 2026; RTC/Voxel pages were not independently fetchable here.)

---

## Gap analysis vs our existing fiscal engine

Our engine: event-sourced, offline-first, tamper-proof, **SHA-256 hash-chained**, device is source of truth, server validates (NF525-style), with sealing, sequential numbering, and chain verification already built.

### What we likely ALREADY satisfy (reuse with confidence)
| NACEF requirement | Our existing capability |
|---|---|
| Inalterable recording before print; no edit/delete of transactions | Hash-chained, sealed, append-only fiscal event log. ✅ Strong match. |
| Sequential numbering; reprints flagged as copies | Sequential numbering exists. Need to confirm "Ticket copie" semantics + distinct numbering on reprint. ✅/⚠️ |
| Per-ticket integrity/signature concept | We seal each event; the *concept* of signing-before-print maps to our sealing. ⚠️ but algorithm differs (see below). |
| Offline-first operation with later reconciliation | Offline-first is our core design; maps directly to NACEF Offline mode + outbox/queue. ✅ Strong match. |
| Chain verification / audit trail | Chain verification + audit logging already implemented. ✅ |
| Receipt with line items, VAT, totals, payment, change | POS receipt rendering exists; needs Tunisia field set + TND. ⚠️ field-level work. |
| Bounded downtime / malfunction reporting concept | We have device/server validation; reporting workflow is new. ⚠️ |

### What is NEW to build (Tunisia-specific, not covered by NF525-style design)
1. **MDF integration (the big one).** NACEF mandates a **Ministry-provided/-certified signing module** (S-MDF software client/server, or E-MDF hardware). Our own SHA-256 sealing **does not substitute** for the MDF's electronic signature — the *legal* signature must come from the homologated MDF. **We must integrate the S-MDF, not replace it.** This is an architectural seam: our engine produces the canonical sale event → hand to S-MDF for signing + QR → print.
2. **Three MDF service calls:** certificate-request, **synchronization (incl. "Ticket Zéro" first-sync)**, electronic-signature. New client code + error-code handling (509/518/etc.).
3. **NACEF QR code on every receipt:** ISO/IEC 18004, 170×220 px, **Online vs Offline content variants**, generated by the MDF (we render what the MDF returns). New.
4. **Per-register electronic certificate lifecycle:** enrollment appointment, certificate provisioning over the NACEF **APN**, **30-day pre-expiry alerts**, renewal flow, PIN + OTP handling. New.
5. **Permanent-connection + durable outbox:** a kept-alive sync channel to the central platform with a **persistent offline queue** that drains on reconnect, plus OFFLINE/ONLINE transition logging to the `/log/` service. Our offline-first store helps, but the **transmission target is the State platform over a specific APN**, not just our own server. New transport + new "outbox to DGI" concept distinct from our device→server sync.
6. **Mandated event/log taxonomy:** `UPGRADE`, `CASHING`, `PURGE`, `SYNC_REQUEST`, OFFLINE/ONLINE — map/emit these to the S-MDF `/log/` service. New mapping.
7. **Homologation as a product:** to ship in Tunisia we (or our customers' device vendor) must pass **NACEF homologation** (dossier, conformity tests, on-site inspection, certificate, listing on jibaya.tn) and meet **supplier obligations** (declare sales, report incidents, ship only homologated builds, report zapper/phantomware). This is a certification/compliance program, not just code. NEW and lead-time-heavy.
8. **Tunisia receipt field set + TND formatting + matricule fiscal** on tickets. Field-level, but required.

### Architectural takeaway
Our hash-chain is a **complement**, not a replacement. The legally binding signature, QR, and transmission are owned by the **homologated MDF**. Treat the S-MDF as an external **fiscal co-processor**: our event-sourced engine remains the operational source of truth and offline buffer, but every printable ticket must round-trip through the S-MDF for signing/QR, and a separate **DGI outbox** must guarantee eventual transmission to the NACEF platform. Plan a clean adapter seam (similar in spirit to the NF525 sealing seam) so the MDF dependency is swappable per-country.

---

## Open questions + what to verify with a Tunisian accountant / fiscal lawyer

1. **Sync cadence:** Is each signed ticket pushed to the central platform *immediately*, or streamed/micro-batched over the permanent connection? (Decides outbox flush policy.) — get from the cahier des charges.
2. **Revenue threshold / exemptions:** Is there any turnover floor, or exemption for takeaway/delivery-only, forfaitaire/micro taxpayers, or non-VAT operators? Sources tie scope to *on-premise service* + tax-regime tier, not turnover — confirm.
3. **E-MDF vs S-MDF choice:** Can a software POS use the **S-MDF (software)** path standalone, or is an **E-MDF hardware** element required for certain configurations? (Affects whether IziPOS/Tauri can be pure-software certified.)
4. **Homologation path for a foreign/SaaS POS:** Process, cost, timeline, and whether the *software editor* or the *device* is certified; on-site inspection logistics.
5. **NACEF vs El Fatoora/TTN overlap:** For a café also issuing B2B invoices, are *both* NACEF tickets and TTN e-invoices required, and how do they interrelate?
6. **Retention period** specific to NACEF fiscal data (likely 10 years per CDPF — confirm).
7. **APN connectivity:** Is the dedicated NACEF APN mandatory for transmission, or is general internet acceptable? (Affects hardware/SIM requirements on POS terminals.)
8. **Reprint/copy rules:** exact "Ticket copie" numbering and how voids/refunds/corrections are represented (since edit/delete is forbidden — likely correction tickets only).
9. **Multi-register server S-MDF:** topology rules when one site has many terminals (one server S-MDF + client S-MDFs) — maps to our device/server model; confirm.
10. **Exact text of Décret 2019-1126 + Arrêté 14 Oct 2025 (JORT n°125):** obtain the JORT PDFs for the binding wording (not fetched here).

---

## Sources (with URLs + access dates)

All accessed **2026-05-26** unless noted. Tunisian press is used to triangulate the law; the **NACEF official sites** are the authoritative technical sources.

**Official / authoritative (NACEF / Ministry):**
- NACEF fiscal cash register FAQ + technical model — https://caisse-enregistreuse.nacef.tn/ (primary technical source for online/offline modes, MDF/S-MDF, QR, signing, certificates, obligations)
- NACEF homologation / certification portal (supplier-side, homologation steps, S-MDF test downloads; full spec docs behind login) — https://homologation.nacef.tn/
- JIBAYA — "NACEF : Le système NAtional de Caisses Enregistreuses Fiscales" (legal basis: Art. 48 LF 2016, Décret 2019-1126, Arrêté 14 Oct 2025; penalties Art. 94 CDPF) — https://jibaya.tn/blog/nacef-le-systeme-national-de-caisses-enregistreuses-fiscales-2/
- JIBAYA portal (approved-supplier list) — https://jibaya.tn/

**Tunisian press (obligation / dates / scope / penalties):**
- Le Temps News (2026-05-21) — 1 July 2026 obligation, phase-in, real-time central platform (CIMF), legal basis — https://letemps.news/2026/05/21/a-partir-du-1er-juillet-2026-toutes-les-entreprises-de-services-de-consommation-sur-place-devront-utiliser-des-caisses-enregistreuses/
- L'Économiste Maghrébin (2026-05-21) — obligation + phase-in + legal basis — https://www.leconomistemaghrebin.com/2026/05/21/caisses-enregistreuses-obligatoires-juillet-2026/
- Kapitalis (2025-11-02) — Arrêté 14 Oct 2025, **JORT n°125**, minister Mechket Slama Khaldi, full phase-in — https://kapitalis.com/tunisie/2025/11/02/tunisie-caisses-enregistreuses-obligatoires-dans-les-cafes-et-restaurants/
- La Presse de Tunisie (2025-10-15) — scope categories, obligation — https://www.lapresse.tn/2025/10/15/restauration-cafes-salons-de-the-les-caisses-enregistreuses-deviennent-obligatoires-en-tunisie/
- La Presse de Tunisie (2026-05-21) — digital cash registers from 1 July — https://www.lapresse.tn/2026/05/21/restaurants-salons-de-the-cafes-les-caisses-enregistreuses-digitales-obligatoires-des-le-1er-juillet/
- BusinessNews (2026-04-15) — **penalties** Art. 94 CDPF (16 days–3 yrs; 1,000–50,000 TND) — https://businessnews.com.tn/2026/04/15/caisses-enregistreuses-de-lourdes-sanctions-pour-les-restaurateurs-et-cafetiers/1396705/
- webdo (FR, 2026-05-21) — scope + timetable — https://www.webdo.tn/fr/actualite/national/restaurants-cafes-et-salons-de-the-les-caisses-enregistreuses-obligatoires-des-le-1er-juillet/398408/
- Ecofin Agency (EN) — restaurant e-invoicing/cash-register rollout, ~$1B tax-gap target, Art. 59 IRPP/IS framing — https://www.ecofinagency.com/news-digital/2010-49668-tunisia-rolls-out-mandatory-restaurant-e-invoicing-targeting-a-1-billion-tax-gap
- African Manager — phase-in / 1 Nov 2025 — https://africanmanager.com/services-de-consommation-sur-place-obligation-dinstaller-des-caisses-enregistreuses-a-partir-du-1er-novembre-2025/
- WMC / Web Manager Center (2025-11-04) — "real-time automatic control," connected registers transmit receipts/amounts/VAT/daily totals — https://www.webmanagercenter.com/2025/11/04/555113/tunisie-caisses-enregistreuses-fiscales-letat-branche-les-cafes-et-restaurants-au-controle-automatique

**E-invoicing context (separate El Fatoora/TTN mandate — for boundary awareness):**
- VATupdate (2025-10-24) — 2026 Finance Bill, e-invoicing expansion — https://www.vatupdate.com/2025/10/24/tunisian-parliament-considers-2026-finance-bill-with-vat-exemptions-and-e-invoicing-expansion/
- KPMG TaxNewsFlash (2025-10) — 2026 Finance Bill e-invoicing — https://kpmg.com/us/en/taxnewsflash/news/2025/10/tunisia-proposed-2026-finance-bill-expands-e-invoicing.html
- VATupdate (2026-01-14) — e-invoicing to all VAT-service transactions from Jan 2026 — https://www.vatupdate.com/2026/01/14/tunisia-expands-mandatory-e-invoicing-to-all-vat-service-transactions-from-january-2026/

**Sources attempted but NOT retrievable** (blocked / 403 / 410 / network policy) — content not independently verified, do not over-rely:
- webdo EN "who is affected" article (HTTP 410)
- TMS Tunisia certified-solution page (HTTP 403)
- rtcsuite.com e-invoicing page; news.gnet.tn (domain not fetchable in this environment)

---

## Confidence & conflicts summary

- **Dates / scope / legal chain / penalties:** HIGH. Multiple independent Tunisian outlets converge, and the NACEF/jibaya official pages cite the same instruments (Art. 48 LF 2016, Décret 2019-1126, Arrêté 14 Oct 2025 / JORT n°125, Art. 94 CDPF).
- **Real-time-vs-deferred conclusion:** MEDIUM-HIGH. The "two modes (Online/Offline)" + "permanent uninterrupted connection" + "sign before print" wording is taken directly from the official NACEF FAQ. The precise per-ticket-vs-continuous-sync cadence is **not** nailed down by public text and must be confirmed from the cahier des charges behind `homologation.nacef.tn`.
- **Technical spec details (S-MDF/E-MDF, error codes, QR dims, services):** MEDIUM-HIGH but FAQ-sourced; the binding "Document de spécifications techniques et fonctionnelles" and "Manuel des procédures techniques" were not directly downloaded (require a supplier account).
- **No conflicts** found across sources on dates/scope. The only ambiguity is **threshold/exemptions** (no evidence found of a turnover threshold) and **sync cadence** (deferred to the spec doc).
