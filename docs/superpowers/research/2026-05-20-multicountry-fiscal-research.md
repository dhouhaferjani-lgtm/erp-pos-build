# Multi-Country Fiscal Receipt — Cross-Regime Field Research

**Date:** 2026-05-20
**Author:** External research subagent (general-purpose, model=opus) dispatched from controller during Task 27B Pass 2 pre-flight.
**Purpose:** Cross-reference the canonical sale-receipt payload requirements across 4 fiscal compliance regimes — **France (NF525)**, **Saudi Arabia (ZATCA E-Invoicing Phase 2)**, **Germany (KassenSichV / BSI TR-03151 SE-API + TR-03153 + DSFinV-K v2.3 + EKaBS v1.0)**, and **Italy (Corrispettivi Telematici RT v11.1 + Tipi Dati v7.0/7.1)**.

**Why this exists:** Task 27B Pass 2 implementer pre-flight surfaced that the plan §2144-2348 A4 25-field payload contradicts the locked PHP `FiscalPayloadConstraintValidator::PAYLOAD_KEYS['SALE_RECEIPT']` (10 fields). Owner directed: do proper NF525 + multi-country research, the 10-field shape may be legacy/incomplete, the rebuild's whole point is more fields + more events.

This document captures the external-spec research before synthesis v2. Codex round-1 review of the original synthesis (verdict BLOCK) flagged that DE/IT field claims need accessible source citations; this file is the grounding source.

Owner directive 2026-05-20: **commit to specs for all 4 regimes; implement NF525 + ZATCA first; DE + IT deferred in implementation but the canonical payload must NOT require a from-scratch redo when DE/IT come.**

---

## 1. France — NF525 (Art. 88 LF 2016, ISCA principles)

**Hashable / chained data per the standard (sourced from publicly available NF525 v2.1 vendor documentation; the AFNOR NF525 v2.1 source spec itself is paywalled):**

- Public technical material on NF525 v2.1 confirms that "the data constituting the chain to be signed for grand totals (period and ticket) include in position 3 the cumulative grand total in real value" (Crisalid). Each ticket's signature is "linked to the previous transaction by chaining" (myPOS, Tactill, Progmag).
- NF525 v2.1 added: "payment method code is mandatory on the receipt; operator code is required on the receipt; discount lines must be detailed with the discount percentage" (Crisalid / Dixisoft).
- Audit trail must log "date, time and author of line cancellations, document cancellations, payment-method changes, abandoned tickets and system-date modifications" (Dolibarr wiki on Art. 88).
- Fiscal archive export is mandatory (≥ 6 years / commonly 7 in vendor docs); cumulative gross-of-discounts ("real value") total must be exported (Crisalid, myFlexina).
- SIRET (seller tax number) required on the receipt itself (Polaris doc).
- Training-mode marker "NON VALABLE POUR ENCAISSEMENT" required (Polaris doc).

**Mapped to canonical-payload candidates:**
- Required: `cashier_id` (operator code), `terminal_id` (identifiant caisse), `business_date`, `event_time_device`, `currency_code`, `currency_scale`, `subtotal`, `vat_total`, `total`, `line_items[].{name, quantity, unit_price, line_discount_amount, line_discount_reason (percentage)}`, `payments[].method_code`, `transaction_discount_amount` + reason, VAT breakdown.
- Required at archive layer: `receipt_local_id` (sequential ticket #), `shift_id` (period close), `training_flag`, `notes`.
- Not mandated on every receipt: `customer_id`, `contact_id`, `consumption_mode`, `table_id`, `voucher`, `instrument_serial` (only when present).

**Missing from any candidate that lacks seller block:** SIRET must reach the printed/archived ticket — this lives at tenant-level in our model but must propagate to the audit ticket.

**Not required by NF525:** `voucher_redemptions`, `instrument_serial`, `contact_id`.

**Note on the in-tree consolidated spec §3.5.1:** The in-tree `docs/new_docs/03-MODULE-SPECS/hash-chain-fiscal-compliance-spec.md` v1.0 (2026-01-08) §3.5.1 shows a TICKET payload of header + timestamp + transactionId + VAT breakdown + totals + payment methods — no per-line detail. This appears to reflect NF525 pre-v2.1; v2.1 explicitly added operator code + payment method code requirements. The consolidated spec needs updating to v2.1 OR our payload contract must accommodate both signing-payload subsets (NF525 pre-v2.1 minimal) and v2.1 richer subsets via a country-specific projection.

---

## 2. Saudi Arabia — ZATCA Phase 2

**Sources:** ZATCA E-invoicing Detailed Technical Guidelines v2 (Nov 2022); ZATCA QR Code Annex 2; ZATCA XML Implementation Standard (May 2023); ClearTax KSA mandatory-fields guide.

**QR-code TLV (mandatory for every Simplified B2C invoice):**

| Tag | Field |
|---|---|
| 1 | Seller's name |
| 2 | Seller VAT registration number (15 digits) |
| 3 | Timestamp (ISO 8601, date+time) |
| 4 | Invoice total (incl. VAT) |
| 5 | VAT total |
| 6 | Hash of XML invoice (SHA-256, base64) |
| 7 | ECDSA signature |
| 8 | ECDSA public key |
| 9 | ZATCA technical-CA signature of the stamp public key |

**Chain identifiers required on every UBL 2.1 invoice:**
- `UUID` (per-invoice 128-bit ID)
- `ICV` (Invoice Counter Value, monotonic per EGS device)
- `PIH` (Previous Invoice Hash — first invoice uses SHA-256("0"))
- `Invoice Hash (IH)` of canonicalised XML (C14N11)
- `Cryptographic Stamp` (XAdES B-B enveloped signature)
- `CSID` (Cryptographic Stamp Identifier from FATOORA)

**UBL invoice fields (XPaths cited in ZATCA guide §6.2):**
- `/Invoice/cbc:IssueDate` + `/Invoice/cbc:IssueTime`
- `/Invoice/cbc:InvoiceTypeCode` (Standard T vs Simplified S, plus 0100/0200 sub-flags)
- `/Invoice/cbc:DocumentCurrencyCode`
- `/Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName`
- `/Invoice/cac:AccountingSupplierParty/cac:Party/cac:PartyTaxScheme/cbc:CompanyID` (15-digit VAT #)
- `/Invoice/cac:LegalMonetaryTotal/{cbc:LineExtensionAmount, cbc:TaxExclusiveAmount, cbc:TaxInclusiveAmount, cbc:AllowanceTotalAmount, cbc:PayableAmount}`
- `/Invoice/cac:TaxTotal/cbc:TaxAmount` + per-rate `cac:TaxSubtotal`
- `/Invoice/cac:InvoiceLine` repeated with `cbc:ID`, `cbc:InvoicedQuantity`, `cbc:LineExtensionAmount`, `cac:Item/cbc:Name`, `cac:Price/cbc:PriceAmount`, `cac:ClassifiedTaxCategory/{cbc:ID, cbc:Percent}`
- Line VAT category code BT-151 required per line (ClearTax citation)
- `cac:PaymentMeans/cbc:PaymentMeansCode` (UN/ECE 4461 codes — cash 10, card 48, etc.)
- `cac:AllowanceCharge` for invoice-level and line-level discounts

**Mapped to canonical-payload candidates:**
- Required: `currency_code`, `subtotal`, `vat_total`, `total`, `transaction_discount_amount`, `event_time_device` (IssueDate+IssueTime), `line_items[].{name, quantity, unit_price, line_subtotal, line_vat, vat_rate, line_discount_amount}`, `payments[].method_code`.
- **Missing from a Candidate-B-like 25-field shape:** UUID per invoice (ZATCA requires a distinct per-invoice UUID — but this CAN re-use receipt_local_id since both are device-generated UUIDs; codex review P1.7 confirms collapse); ICV (envelope `sequence_number`); PIH (envelope `previous_hash`); line-level VAT category code BT-151 (zero-rated / exempt / standard / out-of-scope); invoice-type-code (standard vs simplified vs note); seller name and VAT registration number (must travel with the invoice — full UBL is signed).
- ZATCA does NOT require: `table_id`, `consumption_mode`, `training_flag` (training is out-of-band — only production invoices are stamped), `voucher_code` per redemption (treated as discount), `cashier_id` (not in UBL — though many implementers add it as `cbc:Note`), `shift_id`.

**Cardinal observation:** ZATCA hashes the FULL UBL — there is no minimal-vs-rich payload split. The canonical_bytes must contain everything needed to reconstruct the UBL. PIH/ICV/cbc:ID are envelope-level (not payload-level) but must appear in the final UBL XML emitted by the export adapter; the export adapter reads from envelope + payload.

---

## 3. Germany — KassenSichV (Kassensicherungsverordnung) + BSI TR-03151 SE-API + BSI TR-03153 + DSFinV-K v2.3 + EKaBS v1.0

**TSE-signed processData (BSI TR-03151 §4 + EKaBS PDF p. 36):** The TSE signs `processData` + `processType` ("Kassenbeleg-V1" for sale receipts). `processData` is constructed from receipt totals per VAT rate plus payment-type breakdown (DFKA convention; max 30-char `processType`).

**DSFinV-K v2.3 (mandatory export to tax auditors) — Bonkopf (transactions.csv) fields:**
`Z_KASSE_ID, Z_ERSTELLUNG, Z_NR, BON_ID, BON_NR, BON_TYP, BON_NAME, TERMINAL_ID, BON_STORNO, BON_START, BON_ENDE, BEDIENER_ID, BEDIENER_NAME, UMS_BRUTTO, KUNDE_NAME, KUNDE_ID, KUNDE_TYP, KUNDE_STRASSE, KUNDE_PLZ, KUNDE_ORT, KUNDE_LAND, KUNDE_USTID, BON_NOTIZ` (DSFinV-K v2.3 §3.1.2).

**Bonpos (lines.csv) fields:**
`Z_KASSE_ID, Z_ERSTELLUNG, Z_NR, BON_ID, POS_ZEILE, GUTSCHEIN_NR, ARTIKELTEXT, POS_TERMINAL_ID, GV_TYP, GV_NAME, INHAUS, P_STORNO, AGENTUR_ID, ART_NR, GTIN, WARENGR_ID, WARENGR, MENGE, FAKTOR, EINHEIT, STK_BR` (§3.1.1).

**Bonpos_USt (lines_vat.csv):** `UST_SCHLUESSEL, POS_BRUTTO, POS_NETTO, POS_UST` per VAT rate per line (§3.1.1.1).

**Bonkopf_Zahlarten (datapayment.csv):** `ZAHLART_TYP, ZAHLART_NAME, ZAHLWAEH_CODE, ZAHLWAEH_BETRAG, BETRAG_WAEH_UMRECHNET` (§3.1.2.3).

**Bonkopf_AbrKreis (allocation_groups.csv):** `ABRECHNUNGSKREIS` — Gastronomie table-id grouping (§3.1.2.2).

**TSE_Transaktionen (transactions_tse.csv):** `TSE_ID, TSE_TANR, TSE_TA_START, TSE_TA_ENDE, TSE_TA_VORGANGSART, TSE_TA_SIGZ, TSE_TA_SIG, TSE_TA_FEHLER, TSE_VORGANGSDATEN` (§3.1.4).

**EKaBS receipt JSON (consumer-facing electronic receipt) — Pflichtfeld (mandatory):**
`$.version, $.cash_register.serial_number, $.head.id, $.head.number, $.head.seller.name, $.head.seller.tax_number, $.head.seller.address.{street, postal_code, city, country_code}, $.data.currency, $.data.payment_types[*].{name, amount}, $.data.vat_amounts[*].{percentage, incl_vat, excl_vat, vat}, $.data.lines[*].text, $.security.tse.signature, $.security.tse.process_type=Kassenbeleg-V1`.

**Training flag:** DSFinV-K marks training/test transactions via `BON_TYP="Trainingsbuchung"` and dedicated `GV_TYP` codes (fiskaly DSFinV-K process-type table). The TSE itself does NOT sign training transactions to a production chain.

**Mapped to canonical-payload candidates:**
- Required by DSFinV-K: every canonical-payload identity field maps to a DSFinV-K column. `cashier_id`→BEDIENER_ID, `terminal_id`→TERMINAL_ID/POS_TERMINAL_ID, `shift_id`→Z_NR, `business_date`→Z_ERSTELLUNG, `receipt_local_id`→BON_ID/BON_NR, `event_time_device`→BON_START/BON_ENDE, `training_flag`→BON_TYP, `consumption_mode`→INHAUS, `table_id`→ABRECHNUNGSKREIS, `customer_id`→KUNDE_ID, `notes`→BON_NOTIZ, line `sku`→ART_NR (mandatory in v2.3) and GTIN.
- **Missing from a Candidate-B-like 25-field shape:** `cashier_name` is mandatory in DSFinV-K (BEDIENER_NAME), not just ID; `customer.address` block (KUNDE_STRASSE/PLZ/ORT/LAND/USTID) required when the receipt names a buyer; full seller block (name, tax number, address) required on the EKaBS digital receipt; `gtin` is a separate field from `sku`.
- DSFinV-K does NOT need: `voucher_code` per se (handled via `GV_TYP="Gutschein"` plus `GUTSCHEIN_NR` field on Bonpos — but a voucher block fits).

---

## 4. Italy — Corrispettivi Telematici (RT v11.1 + Tipi Dati v7.0/7.1)

**Sources:** Specifiche Tecniche RT v11.1 (Agenzia delle Entrate); Tipi Dati Corrispettivi v7.0.

**Two distinct artefacts:**

**(a) Documento commerciale (consumer-facing per-receipt):**
- Matricola dispositivo (RT serial — 11-char ID required, printed with the "logotipo fiscale")
- Numero documento commerciale (4-digit closure # + "-" + 4-digit progressive)
- Data documento commerciale
- CCDC (in Server-RT scenarios)
- Partita IVA dell'esercente (seller VAT)
- Per line: descrizione bene/servizio, aliquota IVA propria (also for unbundled servings)
- Codice lotteria (optional but required if customer provides one)
- Codice fiscale cliente (optional but if present, lottery code suppressed)
- Sconto a pagare (discount-on-pay) tracked separately from line discounts (footnote 4)

**(b) XML daily totals to SdI (Tipi Dati v7.0):**
- `DataOraRilevazione` (xs:dateTime ISO 8601, daily closure timestamp)
- `Riepilogo` block repeated per VAT rate or `Natura` (exempt/zero/reverse-charge code) containing `Aliquota`, `Imponibile` (net of VAT), `Imposta`
- Differentiation of `non riscosso` (services vs goods vs subsequent-invoice vs gifts)
- Number of receipts in period; payment-type split (contanti vs elettronico)
- Ticket/buoni accepted; "sconto a pagare" amount
- File signed via the RT's certificate (PADES or CADES); per-RT progressivo univoco

**Refund/annullo:** documento commerciale di reso/annullo references the original receipt's `matricola + numero + data` AND lists each VAT rate refunded (RT v11.1 §2.7.2 Case A/B/C).

**Mapped to canonical-payload candidates:**
- Required: `terminal_id` (matricola), `business_date` (DataOraRilevazione), `receipt_local_id` (closure-progressive), `currency_code`/`scale`, `subtotal`, `vat_total`, `total`, `line_items[].{name, quantity, unit_price, vat_rate, line_subtotal}`, `payments[].method_code` (cash vs electronic split required for the daily roll-up), `transaction_discount_amount` ("sconto a pagare").
- **Missing from a Candidate-B-like 25-field shape:** `vat_nature_code` (Italian Natura codes N1–N7 for exempt / non-taxable / reverse-charge — distinct from `vat_rate`); `non_collected_subtype` (servizi/beni/omaggio/successiva); `lottery_code`; `codice_fiscale` of buyer (separate from `customer_id` — fiscal-code is the Italian buyer identifier); reference to original receipt for resi/annulli (4-digit closure+progressive composite key).
- Not required: `cashier_id`, `training_flag` (Italy uses RT modes managed in firmware, not in transmitted data), `table_id`, `consumption_mode` (handled via VAT rate selection only), `voucher_redemptions` (handled as line-level item with a specific natura code).

---

## 5. Synthesis — Cross-Regime Canonical Superset

### Identity / chain-position fields (required by ≥1 regime, recommended by all)
- `terminal_id` — DE (TERMINAL_ID), IT (matricola), FR (op. doc), KSA (EGS UUID + ICV per device)
- `cashier_id` + `cashier_name` — DE (BEDIENER_ID + NAME mandatory), FR (operator code mandatory v2.1)
- `shift_id` / closure number — DE (Z_NR mandatory), IT (4-digit closure prefix), FR (period close)
- `receipt_local_id` / per-device sequence — all 4 (DE BON_NR, IT progressivo, KSA ICV envelope-level, FR ticket #). **Note:** ZATCA cbc:UUID can re-use this since both are device-generated UUIDs (Codex round-1 P1.7).
- `business_date` — DE (Z_ERSTELLUNG), IT (DataOraRilevazione)
- `event_time_device` — all 4 (KSA IssueDateTime in QR Tag 3, DE BON_START/ENDE, IT, FR audit-trail)
- `currency_code` + `currency_scale` — all 4
- Per-invoice UUID — KSA (envelope-level via `sequence_number` + payload `receipt_uuid` derivable from above)

### Business-document fields (required by all 4 regimes)
- `subtotal`, `vat_total`, `total` — all 4
- `vat_breakdown[]` with `{rate, net, vat, gross, tax_category_code}` — all 4 (DE Bonkopf_USt + Bonpos_USt, IT Riepilogo, KSA TaxSubtotal, FR TVA breakdown). **Codex P1.6:** `tax_category_code` is ONE axis (KSA BT-151 / IT Natura) at the breakdown row level; do NOT separately name it `vat_nature_code` at one layer and `vat_category_code` at another.
- `line_items[].{sku, name, quantity, unit_price, line_subtotal, line_vat, vat_rate, line_discount_amount, line_discount_reason, tax_category_code}` — required by all 4 (DSFinV-K Bonpos+Bonpos_USt+Bonpos_Preisfindung; ZATCA InvoiceLine; IT documento commerciale; NF525 v2.1 discount-percentage detail)
- `line_items[].gtin` — DE (DSFinV-K GTIN field)
- `transaction_discount_amount` + `reason` — all 4
- `payments[].{method_code, amount}` — all 4; DE adds `instrument_type`, `currency_code` per payment, foreign-currency amount

### Seller block (required on the signed payload at least in KSA + DE-EKaBS)
- Seller name, VAT/tax number, full address (street, postal_code, city, country_code), tax_jurisdiction_country_code

### Buyer block (conditional)
- Required when buyer requests it / for B2B: DE (KUNDE_NAME, ID, TYP, STRASSE, PLZ, ORT, LAND, USTID), IT (codice_fiscale / partita IVA), KSA (buyer block for Standard invoice).
- **D16 invariant (Codex P1.4):** buyer block is a sale-time identity snapshot captured from POS-local mirror data at engine.append() time. The fiscal engine + projection NEVER call the customer/B2B modules at runtime to enrich the payload. The projector reads buyer data from the parsed payload only.

### Cross-regime extras
- `consumption_mode` — DE (INHAUS), IT (impacts VAT rate)
- `table_id` — DE (ABRECHNUNGSKREIS gastronomy mandatory)
- `training_flag` — DE (BON_TYP="Trainingsbuchung"), FR (school mode)
- `notes` — DE (BON_NOTIZ), KSA (cbc:Note common pattern)
- `voucher_redemptions[]` — DE (GUTSCHEIN_NR + GV_TYP="Gutschein")
- `instrument_serial` per payment — DE (DSFinV-K supports it via Bonkopf_Zahlarten extensions)

### Cross-regime extras specific to one regime
- IT-only: `lottery_code`, `non_collected_subtype` (`servizi`/`beni`/`omaggio`/`successiva`)
- Refund/void linkage: `original_receipt_reference` (KSA credit-note BillingReference, DE Bon_Referenzen, IT resi/annulli 4+4 composite, FR ticket reference)
- Invoice classification: `invoice_type_code` (SALE/REFUND/VOID/TRAINING) + `invoice_subtype_code` (KSA Standard/Simplified)

### Envelope vs payload split (Codex round-1 B2 mapping)

Codex correctly flagged that ZATCA's `PIH` (Previous Invoice Hash), `ICV` (Invoice Counter Value), and `cbc:ID` (invoice number) must be reconstructible from canonical_bytes. The clean mapping is:

| ZATCA UBL field | AutoERP source |
|---|---|
| `cbc:ID` (invoice number) | DERIVED at export time from `pos_receipts.receipt_number` (server-projected, per Task 21) — NOT in payload. **Note:** for ZATCA, cbc:ID is part of the hashed UBL; the device cannot author it. Resolution: cbc:ID is derived from `receipt_local_id` for B2C Simplified Invoice (since the device authors the UUID itself per Task 13), OR derived from `pos_receipts.receipt_number` AFTER the projector runs (server-allocated number, which means the ZATCA chain must be device-side-allocated cbc:ID = receipt_local_id, not server-allocated). This is a Pass 2 architectural lock: cbc:ID = canonical-payload.receipt_uuid (collapsed name) for ZATCA Simplified Invoices. |
| `cbc:UUID` | `receipt_uuid` (canonical-payload, device-authored) |
| `cac:AdditionalDocumentReference[ID='PIH']/cac:Attachment/cbc:EmbeddedDocumentBinaryObject` | `fiscal_events.previous_hash` (envelope-level, base64 transformation from hex) |
| `cac:AdditionalDocumentReference[ID='ICV']/cbc:UUID` | `fiscal_events.sequence_number` (envelope-level) |
| `cbc:IssueDate` + `cbc:IssueTime` | `event_time_device` (payload-level; ISO 8601 split at export time) |
| `cbc:InvoiceTypeCode` (388 Standard / 388-Simplified flag / 381 Credit) | `invoice_type_code` + `invoice_subtype_code` (payload-level) |
| All seller/buyer/line/total/VAT/payment fields | Direct payload fields |

This mapping makes the ZATCA export deterministic from `(envelope + payload)` without server-side re-serialization.

### Two-payload-level pattern — formal split?

- **Germany — formal split.** TSE signs a *minimal* `processData` string (per-VAT-rate totals + payment-type breakdown). DSFinV-K is the *rich* compliance/export payload (50+ fields per receipt). The TSE-signed string is the chain anchor; DSFinV-K is what gets exported on Außenprüfung. Linked by BON_ID → TSE_TANR.
- **Italy — formal split.** RT signs the *documento commerciale* with PADES/CADES (per-receipt); separately the daily XML *Tipi Dati Corrispettivi* aggregates per-VAT-rate totals to SdI. Per-receipt fiscal data lives in the RT's DGFE (Memoria di dettaglio); audit access is to the DGFE.
- **Saudi Arabia — NO meaningful split.** The full UBL 2.1 invoice (seller, buyer, lines, VAT, totals, payments, references) is canonicalised (C14N11) and the cryptographic stamp covers everything material. The QR is a *projection* of fields (Tags 1-5) plus integrity anchors (Tags 6-9 hash/sig/key).
- **France — implicit split.** NF525 chains per-ticket and per-period totals; the JET is a separate technical-event log; the fiscal archive is a third artefact.

**Implication for AutoERP:** Adopt ONE rich canonical payload (matching KSA's no-split model) + country-specific signature/export adapters that extract their respective projections. The DE TSE adapter pulls minimal `processData` from the rich payload before TSE signing; the IT RT adapter projects documento commerciale fields. This honors KSA's strictest requirement (full UBL hashed) while satisfying DE/IT minimal-signing via projection.

---

## 6. Final conclusion

> The 10-field Candidate A payload (`currency, currency_scale, discount_total, lines, payment_lines, subtotal, tax_total, total, vat_breakdown, voucher_redemptions`) is **demonstrably inadequate for all four regimes** — each of NF525, ZATCA Phase 2, KassenSichV/DSFinV-K, and Italian Corrispettivi Telematici additionally requires per-receipt identifier fields (terminal, cashier/operator, receipt sequence, business date, event timestamp) and at least a seller block plus an invoice-type/VAT-nature classifier that Candidate A entirely omits, so adopting Candidate A would block fiscal certification in every one of the four target markets.

---

## Sources

- [ZATCA E-invoicing Detailed Technical Guidelines v2](https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines/Documents/E-invoicing-Detailed-Technical-Guideline.pdf) (QR Tags 1-9, XPaths, C14N11, XAdES B-B)
- [ZATCA XML Implementation Standard](https://zatca.gov.sa/ar/E-Invoicing/SystemsDevelopers/Documents/20230519_ZATCA_Electronic_Invoice_XML_Implementation_Standard_%20vF.pdf)
- [ClearTax KSA — Mandatory e-Invoice Fields](https://www.cleartax.com/sa/key-mandatory-fields-e-invoice-ksa)
- [DSFinV-K v2.3 spec](https://www.kassensichv.com/downloads/DSFinV-K-Vers-2-3.pdf) (Bonkopf/Bonpos/TSE_Transaktionen field tables)
- [EKaBS Elektronischer Kassen-Beleg-Standard v1.0.0](https://dfka.net/wp-content/uploads/2021/04/EKaBS-Elektronischer-Kassen-Beleg-Standard_1.0.0_Stand_14.04.2021.pdf) (consumer receipt JSON schema with Pflichtfeld markers)
- [BSI TR-03151 SE-API](https://www.bsi.bund.de/SharedDocs/Downloads/DE/BSI/Publikationen/TechnischeRichtlinien/TR03151/TR-03151.pdf) (processData, Kassenbeleg-V1)
- [BSI TR-03153 TSE](https://www.bsi.bund.de/SharedDocs/Downloads/DE/BSI/Publikationen/TechnischeRichtlinien/TR03153/TR-03153.pdf)
- [Italian RT v11.1 Specifiche Tecniche](https://www.agenziaentrate.gov.it/portale/documents/20143/5852274/Specifiche_Tecniche_RT_V11.1_24-01-26.pdf) (matricola, documento commerciale, DGFE, Memoria di riepilogo)
- [Italian Tipi Dati Corrispettivi v7.0](https://www.agenziaentrate.gov.it/portale/documents/20143/2571432/Allegato+-TipiDatiCorrispettivi-V7.0+-+giugno+2020.pdf) (DataOraRilevazione, Aliquota, Natura, Riepilogo)
- [Crisalid NF525 v2.1 Compte-Rendu de Certification](https://doc.crisalid.com/home/certification-nf/compte-rendu-de-certification) (chain position 3 = real-value grand total; payment method + operator code mandatory)
- [Polaris NF18258 — NF525 version 2.1](https://extranet.vega-info.fr/doc-polaris/NF18258_%E2%80%94_NF525_version_2.1) (ticket redesign, training-mode "Brouillon" marker)
- [Dixisoft NF525 v12.00.013 release notes](https://aide.dixisoft.com/hc/fr/articles/360018785500-V12-00-013-NF525-Corrections-am%C3%A9liorations-et-%C3%A9volutions)
- [fiskaltrust DSFinV-K generation docs](https://github.com/fiskaltrust/interface-doc/blob/master/doc/middleware-de-kassensichv/procedural-documentation/dsfinv-k-generation.md)
- [Dolibarr wiki — LF 2016/2025 + NF525](https://wiki.dolibarr.org/index.php/Loi_finances_2016_et_2025_sur_les_logiciels_de_caisse_et_Certification_NF525_ou_LNE)

---

**End.** This document was committed 2026-05-20 to ground the synthesis v2 against accessible-to-reviewers sources (Codex round-1 BLOCKER B1 closure).
