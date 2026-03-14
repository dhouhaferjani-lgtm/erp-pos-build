# AutoERP POS — Multi-Country Compliance Gap Analysis

Last updated: 2026-03-14

---

## Executive Summary

AutoERP has a solid fiscal compliance foundation built for its invoicing modules: SHA-256 hash chains per company, immutable document storage enforced at the PostgreSQL trigger level, and a chain verification API. The POS module extends this infrastructure with receipt-specific hash chains per terminal, Z-report chains, shift management, cash drawer operations, and sequential receipt numbering.

However, significant gaps remain before the system can achieve formal NF525 certification in France, and substantial development is needed for expansion into Italy (SDI/FatturaPA), Saudi Arabia (ZATCA Phase 2), Tunisia, Morocco, Algeria, and the United Kingdom (MTD). This document provides a country-by-country gap analysis with effort estimates and a prioritized roadmap.

---

## Current Infrastructure (Implemented)

### Document-Level Compliance (Invoicing)

| Capability | Implementation | Status |
|------------|----------------|--------|
| SHA-256 hash chains per company | `FiscalHashService` with per-company genesis seed | Production |
| Chain types | `HashChainType` enum: Invoice, CreditNote, Receipt, Payment, JournalEntry, ZReport | Production |
| Immutable documents | PostgreSQL trigger prevents updates to sealed documents | Production |
| Fiscal status flow | DRAFT -> SEALED -> VOIDED with column-level protection | Production |
| Chain verification | `FiscalHashService::verifyChain()` validates entire chain integrity | Production |
| Document posting | `DocumentPostingService` with atomic hash chain extension | Production |
| Fiscal constraints | PostgreSQL CHECK constraints ensuring sealed documents have all required fields | Production |
| Fiscal metadata tables | Separate `invoice_metadata` and compliance tables | Production |

### POS-Specific Compliance (In Development)

| Capability | Implementation | Status |
|------------|----------------|--------|
| Receipt hash chains per terminal | `ReceiptHashService` with SHA-256 chaining per POS terminal | In development |
| Z-report hash chains | `ZReportHashService` with sequential `z_number` per terminal | In development |
| Immutable receipts | PostgreSQL trigger prevents updates (except void operation) | In development |
| Receipt void tracking | `voided_at`, `voided_by`, `void_reason` columns | In development |
| Receipt numbering | Sequential per terminal per year (e.g., POS01-2026-00000001) | In development |
| VAT breakdown hashing | Separate integrity hash for tax calculations within receipts | In development |
| Payment methods hashing | Separate integrity hash for payment method breakdown | In development |
| Z-report generation | Cumulative grand totals per terminal | In development |
| Grand total events | Perpetual cumulative counters across shifts | In development |
| Cash drawer operations | Deposits, payouts, refunds tracked per shift | In development |
| Shift management | Open/close with expected/actual cash and variance calculation | In development |
| Stock movement audit | Every POS sale/void creates `StockMovement` records | In development |
| Audit events | `DomainEventSubscriber` central audit trail for all domain events | Production |
| Multi-tenant isolation | Schema-based PostgreSQL tenancy | Production |
| Chain verification tools | `verifyTerminalChain()`, `verifyZReportChain()`, `findChainBreak()` | In development |

---

## 1. France — NF525 Certification

### Regulatory Context

The NF525 standard (published by AFNOR, certification by LNE or INFOCERT) applies to all cash register and POS software used in France. Since January 1, 2018, businesses accepting cash payments must use certified or self-attested software that guarantees data integrity (inalterable), security (secured), archival (archived), and traceability (traceable) — collectively known as the "CIAS" requirements (Article 286 CGI, BOI-TVA-DECLA-30-10-30).

### 1.1 What Is Implemented

- **Hash chain infrastructure**: SHA-256 receipt chains per terminal and Z-report chains, satisfying the inalterable (inalterable) requirement
- **Immutable receipt storage**: PostgreSQL triggers prevent modification of sealed receipts
- **Void tracking**: Receipts can only be voided (not deleted), with mandatory reason, operator ID, and timestamp
- **Grand totals and perpetual counters**: Cumulative totals across shifts, satisfying the perpetual grand total (GT) requirement
- **Z-report generation**: End-of-day closings with cumulative totals per terminal
- **Sequential receipt numbering**: Unbroken sequence per terminal per fiscal year (POS01-2026-00000001)
- **VAT breakdown integrity**: Separate hash ensuring tax calculations cannot be tampered with
- **Chain verification**: Automated verification of receipt and Z-report chains with break detection

### 1.2 In Progress (Current Development Session)

- **Receipt duplicate/reprint audit log**: NF525 JET requirement mandates logging every receipt duplication or reprint with operator identity and timestamp
- **NF525 JET export**: XML-structured audit trail (Journal des Evenements Techniques) covering all system events
- **Chain verification CLI tool**: Artisan command for on-demand chain integrity verification
- **Factur-X for B2B invoices**: EN 16931 / Factur-X PDF/A-3 generation for the September 2026 French e-invoicing mandate

### 1.3 Remaining Gaps for NF525 Certification

#### 1.3.1 Certification Body Engagement

**Gap**: No formal engagement with a certification body.

NF525 certification requires testing by an accredited body — either LNE (Laboratoire national de metrologie et d'essais) or INFOCERT. The process involves:

1. Submission of a technical dossier describing software architecture, hash chain implementation, and data retention strategy
2. On-site or remote testing of all CIAS requirements
3. Annual renewal audits

**Action**: Engage with LNE or INFOCERT to begin the dossier preparation. Lead time is typically 2-4 months from initial contact to certification.

#### 1.3.2 Software Self-Attestation

**Gap**: No self-certification document exists.

Per BOI-TVA-DECLA-30-10-30, software publishers must provide an attestation de conformite to their customers. Even before full NF525 certification, a self-attestation covering the four CIAS requirements can be issued. This document must include:

- Publisher identity and SIREN number
- Software name and version
- Declaration of compliance with Articles 286-I-3 bis and 286-II CGI
- Date and signature

**Effort**: 1-2 days (legal/compliance team, not engineering)

#### 1.3.3 Training Mode

**Gap**: No distinction between training and production transactions.

NF525 requires that training transactions (used for staff onboarding or demonstrations) are clearly separated from production data. Training receipts must:

- Not appear in fiscal totals or Z-reports
- Be clearly marked as "FORMATION" or "ESSAI"
- Be excluded from the fiscal hash chain
- Be stored separately for audit purposes

**Effort**: 3-5 days

#### 1.3.4 Terminal Event Logging

**Gap**: Terminal lifecycle events are not captured in the JET.

The JET must record:

- Terminal activation and deactivation timestamps
- Software version changes per terminal
- Operator login/logout events per terminal
- Configuration changes (tax rates, payment methods)
- Clock synchronization events
- Network connectivity changes (online/offline transitions)

**Effort**: 3-5 days

#### 1.3.5 Data Archival with Integrity Guarantee

**Gap**: No formal archival process with integrity verification.

French fiscal law requires 6-year data retention (Article L102 B du Livre des procedures fiscales) with the ability to produce data on demand during a tax audit. Requirements:

- Archived data must include complete hash chains
- Archives must be verifiable (re-calculable hashes)
- Archive medium must guarantee integrity (signed exports, read-only storage)
- Annual archival cycle with chain closure and new genesis

**Effort**: 1-2 weeks

#### 1.3.6 Version Tracking in JET

**Gap**: Software version is not recorded in the audit trail.

The JET must contain:

- Current software version at each event
- Version change history (upgrades, patches)
- Database schema version

**Effort**: 1-2 days

#### 1.3.7 Complete Data Export on Demand

**Gap**: No mechanism for producing a complete, hash-verified data export for tax inspectors.

During a verification de comptabilite informatisee (FEC audit), inspectors may request a full export of:

- All receipts with hash chains
- All Z-reports with cumulative totals
- The complete JET
- Perpetual grand totals
- Payment method breakdowns

This export must be in a structured format (XML or CSV with defined schema) and must be independently verifiable.

**Effort**: 1-2 weeks

### 1.4 Factur-X / E-Invoicing (September 2026 Mandate)

Starting September 2026, all French B2B invoices must be transmitted electronically via a PDP (Plateforme de Dematerialisation Partenaire) or PPF (Portail Public de Facturation). Requirements:

| Requirement | Status | Notes |
|-------------|--------|-------|
| Factur-X XML generation (EN 16931) | In progress | Minimum profile required |
| PDF/A-3 embedding | In progress | XML attached to compliant PDF |
| PDP integration | Not started | API client for submission/reception |
| Invoice status lifecycle (deposee, refusee, encaissee) | Not started | Chorus Pro / PDP status tracking |
| Siren/Siret validation | Not started | Validate recipient identifiers |
| Directory lookup | Not started | Annuaire PPF for routing |

**Effort**: 3-4 weeks for full PDP integration

---

## 2. Tunisia

### Regulatory Context

Tunisian fiscal compliance is governed by the Code des droits et procedures fiscaux and administered by the Direction Generale des Impots (DGI). Tunisia does not have an NF525 equivalent, but has specific requirements for invoicing, fiscal stamps, and tax declarations.

### 2.1 Current Compliance State

- Sequential document numbering (implemented)
- Multi-currency support including TND (implemented)
- VAT calculation infrastructure (implemented)
- Basic receipt and invoice generation (implemented)

### 2.2 Requirements

#### 2.2.1 Timbre Fiscal (Fiscal Stamp)

All invoices exceeding 1,000 TND (Article 117 du Code du Timbre) must bear a fiscal stamp of 1 TND per page. This applies to both paper and electronic invoices. The stamp must appear on the printed document and be accounted for separately.

#### 2.2.2 TVA Declarations

- Monthly TVA declarations for businesses with annual turnover exceeding 100,000 TND
- Quarterly declarations for smaller businesses
- Export format must conform to DGI specifications
- Declaration includes: TVA collectee, TVA deductible, TVA nette

#### 2.2.3 Matricule Fiscal

Every invoice must display the supplier's matricule fiscal (tax identification number) in the format: XXXXXXX/X/X/X/XXX (7 digits / letter / digit / letter / 3 digits).

#### 2.2.4 Electronic Invoicing

The Tunisian Ministry of Finance is progressively mandating electronic invoicing. The SADEC platform (Systeme Automatise de DEClaration) handles electronic declarations, and e-invoicing integration is expected to become mandatory by 2027-2028.

#### 2.2.5 Data Retention

Tunisian law requires 10-year retention of all accounting documents (Article 62 du Code de la TVA).

### 2.3 Gaps

| Gap | Priority | Effort |
|-----|----------|--------|
| Fiscal stamp calculation and display on invoices > 1,000 TND | High | 2-3 days |
| Matricule fiscal format validation | High | 1 day |
| TVA declaration export (monthly/quarterly XML or CSV) | High | 1 week |
| SADEC integration for electronic declarations | Medium | 2-3 weeks |
| 10-year data retention policy | Medium | Shared with archival infrastructure |

---

## 3. Italy — SDI / FatturaPA

### Regulatory Context

Italy has the most advanced mandatory e-invoicing system in the EU, operational since January 2019. All invoices (B2B, B2C, and B2G) must be transmitted through the Sistema di Interscambio (SDI), operated by the Agenzia delle Entrate. POS systems must additionally comply with corrispettivi elettronici requirements.

### 3.1 Requirements

#### 3.1.1 FatturaPA XML Format

All invoices must conform to the FatturaPA XML schema (version 1.2.2). Key elements:

- `FatturaElettronicaHeader`: Supplier and customer data, transmission data
- `FatturaElettronicaBody`: Invoice lines, tax calculations, payment terms
- `Allegati`: Attachments (PDF copy, supporting documents)

The XML schema is maintained by the Agenzia delle Entrate and is significantly different from EN 16931 / Factur-X.

#### 3.1.2 SDI Gateway Integration

Invoices must be submitted to SDI via:

- **Web service (SdIRiceviFile)**: SOAP/MTOM for B2B
- **PEC (Posta Elettronica Certificata)**: Certified email as alternative channel
- **FTP**: For high-volume submitters

SDI returns notification messages: `ricevuta di consegna` (delivered), `notifica di mancata consegna` (undeliverable), `notifica di scarto` (rejected).

#### 3.1.3 Digital Signature

Every FatturaPA XML must be digitally signed using:

- XAdES-BES (XML Advanced Electronic Signatures) — for XML enveloped signature
- CAdES-BES (CMS Advanced Electronic Signatures) — for detached signature (.p7m)

Signing certificates must be issued by an accredited Italian CA (Certificatore Accreditato) listed by AgID.

#### 3.1.4 Corrispettivi Elettronici (Electronic Receipts)

Since January 2020, all POS transactions must be transmitted daily to the Agenzia delle Entrate:

- POS device must be a certified **Registratore Telematico (RT)** or use compliant software
- Daily XML transmission of all receipt data
- 12-day grace period for transmission
- Unique `numero documento commerciale` per receipt

#### 3.1.5 Lotteria degli Scontrini

Receipt lottery program requiring:

- QR code on each receipt containing a lottery code
- Consumer provides their lottery code at purchase
- Data transmitted to the lottery system via corrispettivi flow

### 3.2 Gaps

| Gap | Priority | Effort |
|-----|----------|--------|
| FatturaPA XML generation (schema 1.2.2) | Critical | 2-3 weeks |
| SDI SOAP client (SdIRiceviFile) | Critical | 2 weeks |
| SDI notification handling (delivery, rejection, acceptance) | Critical | 1 week |
| XAdES-BES / CAdES-BES digital signature | Critical | 1-2 weeks |
| Italian CA certificate procurement | Critical | Administrative (2-4 weeks) |
| Corrispettivi elettronici daily XML | Critical (for POS) | 2 weeks |
| RT certification or software RT compliance | Critical (for POS) | Depends on approach |
| Lotteria degli scontrini QR code | Medium | 3-5 days |
| PEC integration | Low | 1 week |
| Codice Destinatario / PEC routing | Medium | 3-5 days |

**Total estimated effort**: 3-4 months for full compliance

---

## 4. Saudi Arabia — ZATCA Phase 2 (Fatoora)

### Regulatory Context

The Zakat, Tax and Customs Authority (ZATCA) mandates electronic invoicing in two phases:

- **Phase 1 (Generation)**: Effective December 4, 2021 — invoices must be generated electronically
- **Phase 2 (Integration)**: Rolling enforcement since January 2023 — invoices must be reported/cleared via the ZATCA Fatoora platform

Phase 2 applies progressively based on annual revenue thresholds, with full coverage expected by 2025-2026.

### 4.1 Requirements

#### 4.1.1 Invoice Format

ZATCA invoices use UBL 2.1 XML (OASIS Universal Business Language) with ZATCA-specific extensions:

- Standard tax invoices (B2B): UBL 2.1 with mandatory fields
- Simplified tax invoices (B2C): Reduced field set
- Credit/debit notes: Must reference original invoice UUID

#### 4.1.2 Cryptographic Requirements

- **ECDSA digital signature**: Each invoice signed with ECDSA using the secp256k1 curve
- **CSR generation**: Compliance Solution Provider generates a Certificate Signing Request
- **ZATCA-issued certificate**: Production certificates issued by ZATCA after onboarding
- **Invoice hash**: SHA-256 hash of the canonical XML (C14N)
- **Previous invoice hash**: Chain linking (similar to AutoERP's existing infrastructure)

#### 4.1.3 QR Code

Every invoice must include a QR code containing TLV-encoded fields:

| Tag | Field | Encoding |
|-----|-------|----------|
| 1 | Seller name | UTF-8 |
| 2 | VAT registration number | UTF-8 |
| 3 | Invoice timestamp | ISO 8601 |
| 4 | Invoice total (with VAT) | UTF-8 decimal |
| 5 | VAT amount | UTF-8 decimal |
| 6 | Invoice hash | Hex |
| 7 | ECDSA signature | Base64 |
| 8 | Public key | Base64 |

#### 4.1.4 Clearance vs. Reporting

- **Clearance model (B2B)**: Invoice submitted to ZATCA platform, cleared (stamped), then delivered to buyer. Invoice is not valid until cleared.
- **Reporting model (B2C)**: Simplified invoice reported to ZATCA within 24 hours of issuance. Invoice is valid immediately.

#### 4.1.5 Sandbox and Onboarding

ZATCA provides a sandbox environment for integration testing. Onboarding requires:

1. Register as a taxpayer on the Fatoora portal
2. Generate CSR and submit for certificate issuance
3. Complete compliance checks in sandbox
4. Production go-live

### 4.2 Gaps

| Gap | Priority | Effort |
|-----|----------|--------|
| UBL 2.1 XML generation with ZATCA extensions | Critical | 2-3 weeks |
| ECDSA signing (secp256k1) | Critical | 1 week |
| CSR generation and certificate management | Critical | 1 week |
| ZATCA API client (sandbox + production) | Critical | 2 weeks |
| Clearance flow (B2B submit, wait for stamp, deliver) | Critical | 1-2 weeks |
| Reporting flow (B2C async submission) | Critical | 1 week |
| TLV-encoded QR code generation | Critical | 3-5 days |
| Previous invoice hash chain (ZATCA format) | Low | Existing infrastructure adaptable |
| Onboarding portal integration | Medium | 1 week |

**Total estimated effort**: 2-3 months

**Note**: AutoERP's existing hash chain infrastructure (SHA-256, per-tenant genesis seed) aligns well with ZATCA's chain requirements. The main adaptation needed is switching from AutoERP's company seed to ZATCA's fixed `SHA256("0")` genesis and using ECDSA signatures instead of hash-only integrity.

---

## 5. Morocco

### Regulatory Context

Moroccan fiscal compliance is governed by the Code General des Impots (CGI) and administered by the Direction Generale des Impots (DGI). Morocco is progressively modernizing its tax infrastructure with plans for mandatory e-invoicing.

### 5.1 Requirements

#### 5.1.1 ICE (Identifiant Commun de l'Entreprise)

Since 2018, all invoices must display the ICE of both the supplier and the customer. The ICE is a 15-digit unique identifier replacing the previous IF (Identifiant Fiscal).

Format: 15 numeric digits (e.g., 001234567000089)

#### 5.1.2 Invoice Requirements

- Sequential numbering (already supported)
- Supplier and customer ICE
- TVA registration number
- Invoice date and sequential number
- Detailed description of goods/services
- Unit prices, quantities, total HT, TVA amount, total TTC

#### 5.1.3 TVA Declarations

- Monthly declarations (due by the 20th of the following month) for businesses on the regime de debit
- Quarterly declarations for businesses on the regime d'encaissement
- Annual declarations for businesses below threshold

#### 5.1.4 E-Invoicing Roadmap

Morocco's DGI has announced plans for mandatory electronic invoicing, expected to roll out progressively starting 2027 for large enterprises. The technical specifications have not been finalized.

### 5.2 Gaps

| Gap | Priority | Effort |
|-----|----------|--------|
| ICE validation (15-digit format check) | High | 1-2 days |
| ICE display on invoices and receipts | High | 1-2 days |
| TVA declaration export (DGI format) | Medium | 1 week |
| Simpl-TVA portal integration (future) | Low | TBD when specs published |

**Total estimated effort**: 2-3 weeks

---

## 6. Algeria

### Regulatory Context

Algerian fiscal compliance is governed by the Code des Impots Directs et Taxes Assimilees and administered by the Direction Generale des Impots (DGI). Algeria maintains traditional invoice requirements with limited digitalization progress.

### 6.1 Requirements

#### 6.1.1 NIF (Numero d'Identification Fiscale)

All invoices must display the supplier's NIF. Format: 15 digits (e.g., 000016001234567).

#### 6.1.2 NIS (Numero d'Identification Statistique)

Required on commercial invoices for statistical tracking. Format: 18 digits.

#### 6.1.3 Invoice Requirements

- Sequential numbering per fiscal year (already supported)
- Supplier NIF and NIS
- RC (Registre de Commerce) number
- AI (Article d'Imposition) number
- Detailed line items with TVA breakdown

#### 6.1.4 TVA/TAP Declarations

- Monthly G50 declarations combining TVA, TAP (Taxe sur l'Activite Professionnelle), and IRG withholding
- Specific format mandated by DGI
- Paper-based with growing JIBAYA'TIC electronic option

#### 6.1.5 Data Retention

10-year retention of accounting records (Article 44 du Code de la TVA).

### 6.2 Gaps

| Gap | Priority | Effort |
|-----|----------|--------|
| NIF validation (15-digit format) | High | 1 day |
| NIS validation (18-digit format) | Medium | 1 day |
| G50 declaration export format | Medium | 1 week |
| JIBAYA'TIC portal integration | Low | TBD |

**Total estimated effort**: 1-2 weeks

---

## 7. United Kingdom — Making Tax Digital (MTD)

### Regulatory Context

HMRC's Making Tax Digital program requires businesses to maintain digital records and submit VAT returns using MTD-compatible software. MTD for VAT has been mandatory for all VAT-registered businesses since April 2022. MTD for Income Tax Self Assessment (ITSA) is rolling out from April 2026.

### 7.1 Requirements

#### 7.1.1 MTD for VAT

- Quarterly VAT returns submitted via HMRC's MTD API (RESTful, OAuth 2.0)
- Digital record-keeping with no manual re-keying ("digital links")
- Nine-box VAT return format
- Bridging software acceptable (can submit via API)

#### 7.1.2 Digital Links

All data flowing into the VAT return must be transferred digitally — no copy-paste or manual re-entry between systems. This means the path from transaction recording to VAT return must be fully automated.

#### 7.1.3 HMRC API Integration

- OAuth 2.0 authentication with HMRC
- VAT return submission endpoint
- VAT obligations retrieval (deadlines)
- VAT liabilities and payments viewing
- Sandbox environment for testing

#### 7.1.4 Receipt and Invoice Requirements

UK invoices must include:

- VAT registration number
- Sequential invoice number
- Invoice date and tax point date
- Supplier and customer details
- Line item descriptions with VAT rate per line
- Total excluding VAT, VAT amount, total including VAT

### 7.2 Gaps

| Gap | Priority | Effort |
|-----|----------|--------|
| HMRC MTD API client (OAuth 2.0, VAT return submission) | Medium | 2 weeks |
| VAT return calculation (nine-box format) | Medium | 1 week |
| Digital links audit trail | Low | 3-5 days |
| HMRC sandbox testing | Medium | 1 week |

**Total estimated effort**: 3-4 weeks

**Note**: The UK market is not a primary target. Implementation can be deferred to 2027 unless a specific customer requires it.

---

## 8. Priority Roadmap

| Country | Priority | Estimated Effort | Target Completion | Rationale |
|---------|----------|------------------|-------------------|-----------|
| France (NF525 POS) | P0 — Critical | 2-3 weeks remaining | Q2 2026 | Core market, legal requirement for POS |
| France (Factur-X B2B) | P0 — Mandatory | In progress | September 2026 | Legal mandate, hard deadline |
| Tunisia | P1 — Next market | 2-3 weeks | Q3 2026 | Active customer pipeline |
| Saudi Arabia (ZATCA) | P1 — Growing market | 2-3 months | Q4 2026 | Large market opportunity |
| Morocco | P2 — Regional | 2-3 weeks | Q4 2026 | Francophone market synergy |
| Italy (SDI) | P2 — EU expansion | 3-4 months | 2027 | Complex but large market |
| Algeria | P3 — Regional | 1-2 weeks | 2027 | Lower priority, simpler requirements |
| UK (MTD) | P3 — Low priority | 3-4 weeks | 2027+ | Not primary market |

---

## 9. Shared Infrastructure Needs

The following cross-cutting capabilities are required by multiple country implementations. Building them as shared services will reduce per-country effort significantly.

### 9.1 Digital Signature Service

**Required by**: France (Factur-X), Italy (XAdES-BES / CAdES-BES), Saudi Arabia (ECDSA secp256k1)

Architecture:

```
SignatureServiceInterface
├── XAdESSignatureService       # Italy FatturaPA
├── CAdESSignatureService       # Italy FatturaPA (alternative)
├── ECDSASignatureService       # Saudi Arabia ZATCA
└── PDFSignatureService         # Factur-X PDF/A-3
```

Each implementation handles certificate management, key storage, and signing operations specific to its country's requirements. Private keys must be stored in a secure vault (not in the database).

**Effort**: 2-3 weeks for the abstraction + first implementation

### 9.2 XML Builder Framework

**Required by**: France (Factur-X EN 16931), Italy (FatturaPA 1.2.2), Saudi Arabia (UBL 2.1), NF525 JET export

Architecture:

```
XmlBuilderInterface
├── FacturXBuilder              # EN 16931 Cross-Industry Invoice
├── FatturaPABuilder            # Agenzia delle Entrate schema
├── ZatcaUblBuilder             # UBL 2.1 with ZATCA extensions
└── JetExportBuilder            # NF525 technical event log
```

Consider using a template-based approach (Twig or Blade) for XML generation rather than programmatic DOM construction, as the schemas are complex and templates are easier to audit against specifications.

**Effort**: 2 weeks for framework + first builder

### 9.3 Compliance Gateway Abstraction

**Required by**: France (PDP/PPF), Italy (SDI), Saudi Arabia (ZATCA Fatoora), UK (HMRC MTD)

Architecture:

```
ComplianceGatewayInterface
├── submit(document): SubmissionResult
├── checkStatus(submissionId): StatusResult
├── retrieve(notificationId): NotificationResult
└── healthCheck(): bool

Implementations:
├── PdpGateway                  # France Factur-X
├── SdiGateway                  # Italy Sistema di Interscambio
├── ZatcaGateway                # Saudi Arabia Fatoora
└── HmrcGateway                 # UK Making Tax Digital
```

Each gateway handles authentication (OAuth, certificates, API keys), rate limiting, retry logic, and error mapping specific to the government platform.

**Effort**: 2 weeks for abstraction + first gateway

### 9.4 QR Code Service

**Required by**: Saudi Arabia (TLV-encoded ZATCA QR), Italy (Lotteria degli scontrini), General (receipt QR for customer lookup)

Architecture:

```
QrCodeGeneratorInterface
├── ZatcaQrGenerator            # TLV encoding per ZATCA spec
├── LotteriaQrGenerator         # Italian receipt lottery format
└── GenericReceiptQrGenerator   # URL-based receipt lookup
```

**Effort**: 1 week

### 9.5 Data Retention and Archival Service

**Required by**: France (6 years), Tunisia (10 years), Algeria (10 years), All countries (audit readiness)

Architecture:

```
ArchivalServiceInterface
├── createArchive(tenantId, period): Archive
├── verifyArchive(archiveId): VerificationResult
├── exportForAudit(tenantId, dateRange, format): ExportResult
└── getRetentionPolicy(countryCode): RetentionPolicy
```

Must support:

- Hash chain closure and new genesis for archived periods
- Integrity verification of archived data
- On-demand export in country-specific formats (FEC for France, etc.)
- Configurable retention periods per country

**Effort**: 2-3 weeks

### 9.6 Tax Declaration Export Service

**Required by**: Tunisia (TVA monthly/quarterly), Morocco (TVA), Algeria (G50), UK (MTD VAT return), France (CA3)

Architecture:

```
TaxDeclarationExportInterface
├── generate(tenantId, period, type): DeclarationDocument
├── validate(declaration): ValidationResult
└── getDeadline(tenantId, period, type): DateTimeImmutable

Implementations:
├── FrenchCA3Export             # French monthly/quarterly TVA
├── TunisianTVAExport           # Tunisian DGI format
├── MoroccanTVAExport           # Moroccan DGI format
├── AlgerianG50Export            # Algerian combined declaration
└── UkVatReturnExport           # HMRC nine-box format
```

**Effort**: 1 week per country (after framework)

---

## 10. Risk Assessment

### High Risk

| Risk | Impact | Mitigation |
|------|--------|------------|
| NF525 certification delay | Cannot legally sell POS in France | Begin LNE/INFOCERT engagement immediately |
| Factur-X September 2026 deadline miss | Legal non-compliance for all French B2B | Prioritize PDP integration by July 2026 |
| ZATCA Phase 2 non-compliance | Cannot operate in Saudi Arabia | Begin sandbox integration Q3 2026 |

### Medium Risk

| Risk | Impact | Mitigation |
|------|--------|------------|
| Italian RT certification complexity | May need hardware partner for POS | Evaluate software RT path early |
| Tunisian e-invoicing mandate acceleration | Unplanned urgent work | Monitor DGI announcements quarterly |
| Multi-country tax calculation errors | Financial liability | Country-specific tax engine tests with real-world scenarios |

### Low Risk

| Risk | Impact | Mitigation |
|------|--------|------------|
| Algerian JIBAYA'TIC changes | Minor rework | Limited exposure, simple requirements |
| UK MTD scope expansion | Additional API endpoints | Modular gateway design handles additions |

---

## 11. References

| Standard / Authority | URL / Reference |
|---------------------|-----------------|
| NF525 (AFNOR) | NF525:2016 — Systemes de caisse |
| BOI-TVA-DECLA-30-10-30 | Bulletin Officiel des Finances Publiques |
| LNE Certification | lne.fr — Cash register software certification |
| INFOCERT Certification | infocert.org — Alternative certification body |
| Factur-X / ZUGFeRD | factur-x.org — EN 16931 hybrid invoice format |
| PPF / Chorus Pro | chorus-pro.gouv.fr — French public invoicing portal |
| Agenzia delle Entrate SDI | fatturapa.gov.it — Italian e-invoicing system |
| FatturaPA Schema | Schema version 1.2.2, Provvedimento del 30/04/2018 |
| ZATCA E-Invoicing | zatca.gov.sa — Fatoora e-invoicing portal |
| ZATCA Developer Portal | developer.zatca.gov.sa — API documentation and sandbox |
| HMRC MTD | developer.service.hmrc.gov.uk — MTD API documentation |
| Tunisia DGI | portail.finances.gov.tn — Tunisian tax authority |
| Morocco DGI | tax.gov.ma — Moroccan tax authority |
| Algeria DGI | mfdgi.gov.dz — Algerian tax authority |
