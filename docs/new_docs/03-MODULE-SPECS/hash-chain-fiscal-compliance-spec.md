# Hash Chain Implementation Specification
## Fiscal Compliance for POS Terminals: NF525 (France) & ZATCA (Saudi Arabia)

**Version:** 1.0  
**Date:** January 8, 2026  
**Purpose:** Technical specification for implementing cryptographically secure hash chains to achieve fiscal certification for POS systems in France (NF525) and Saudi Arabia (ZATCA Phase 2).

---

## Table of Contents

1. [Executive Summary](#1-executive-summary)
2. [Architectural Overview](#2-architectural-overview)
3. [NF525 Requirements (France)](#3-nf525-requirements-france)
4. [ZATCA Requirements (Saudi Arabia)](#4-zatca-requirements-saudi-arabia)
5. [Common Patterns & Abstractions](#5-common-patterns--abstractions)
6. [Database Schema Design](#6-database-schema-design)
7. [Implementation Guidelines](#7-implementation-guidelines)
8. [Security Considerations](#8-security-considerations)
9. [Testing & Validation](#9-testing--validation)
10. [Certification Process](#10-certification-process)

---

## 1. Executive Summary

### 1.1 Purpose

This document specifies the technical requirements for implementing hash chains in POS terminals to achieve fiscal compliance certification. The hash chain mechanism ensures:

- **Inalterability**: Transaction records cannot be modified after creation
- **Traceability**: Complete audit trail from first to last transaction
- **Integrity**: Any tampering is cryptographically detectable
- **Non-repudiation**: Digital signatures prove authenticity

### 1.2 Scope

Two certification standards are covered:

| Standard | Country | Scope | Mandatory From |
|----------|---------|-------|----------------|
| NF525 | France | POS software certification | September 1, 2026 |
| ZATCA Phase 2 | Saudi Arabia | E-invoicing integration | Phased rollout (ongoing) |

### 1.3 Key Differences Summary

| Aspect | NF525 | ZATCA |
|--------|-------|-------|
| Chain Structure | Per terminal, per event type | Per EGS unit (single chain) |
| Signature Algorithm | RSA-2048 or ECDSA-256 | ECDSA-256 only |
| Hash Algorithm | SHA-256/384/512 | SHA-256 |
| Government Integration | Offline (export for audits) | Real-time API integration |
| Invoice Format | Proprietary event structure | UBL 2.1 XML |
| QR Code | Not required | Mandatory with crypto data |

---

## 2. Architectural Overview

### 2.1 High-Level Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                    Hash Chain Service Layer                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────────┐     ┌─────────────────┐                    │
│  │  NF525 Strategy │     │  ZATCA Strategy │                    │
│  │                 │     │                 │                    │
│  │ - Per terminal  │     │ - Per EGS unit  │                    │
│  │ - Multi-chain   │     │ - Single chain  │                    │
│  │ - Event types   │     │ - Invoice-based │                    │
│  └────────┬────────┘     └────────┬────────┘                    │
│           │                       │                              │
│           └───────────┬───────────┘                              │
│                       ▼                                          │
│           ┌─────────────────────┐                                │
│           │  Abstract HashChain │                                │
│           │      Interface      │                                │
│           └──────────┬──────────┘                                │
│                      │                                           │
│  ┌───────────────────┼───────────────────┐                      │
│  ▼                   ▼                   ▼                      │
│ ┌────────┐     ┌──────────┐     ┌─────────────┐                 │
│ │Signing │     │ Event    │     │ Certificate │                 │
│ │Service │     │ Store    │     │ Manager     │                 │
│ └────────┘     └──────────┘     └─────────────┘                 │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### 2.2 Core Components

1. **HashChainService**: Abstract service defining the interface for hash chain operations
2. **NF525Strategy**: France-specific implementation with multi-chain per terminal
3. **ZATCAStrategy**: Saudi-specific implementation with UBL XML and government API
4. **SigningService**: Handles cryptographic operations (hashing, signing, verification)
5. **EventStore**: Immutable storage for signed events
6. **CertificateManager**: Manages signing certificates lifecycle

---

## 3. NF525 Requirements (France)

### 3.1 Regulatory Framework

NF525 is part of the French anti-VAT fraud law (Article 88, Finance Act 2015-1785). It enforces the **ISCA** principles:

- **I**nalterability (Inaltérabilité)
- **S**ecurity (Sécurisation)
- **C**onservation (Conservation)
- **A**rchiving (Archivage)

### 3.2 Cryptographic Standards

| Component | Requirement | Notes |
|-----------|-------------|-------|
| Hash Algorithm | SHA-256, SHA-384, or SHA-512 | SHA-256 recommended |
| Signature Algorithm | RSA-2048+ or ECDSA-256+ | RSA-2048 most common |
| Certificate Type | Self-signed acceptable | Issued per customer/installation |
| Rejected Algorithms | CRC16, CRC32, SHA-1, MD5 | Never use these |

### 3.3 Event Types (JET - Journal of Technical Events)

NF525 requires tracking multiple event types, each maintaining its own chain per terminal:

| Event Type | Code | Description | Trigger |
|------------|------|-------------|---------|
| TICKET | `TICKET` | Individual sale transaction | Each sale completion |
| GRANDTOTAL Daily | `GRANDTOTAL_DAY` | Daily shift closure | End of business day |
| GRANDTOTAL Monthly | `GRANDTOTAL_MONTH` | Monthly period closure | End of month |
| GRANDTOTAL Yearly | `GRANDTOTAL_YEAR` | Yearly period closure | End of fiscal year |
| DUPLICATE | `DUPLICATE` | Receipt reprint | User requests duplicate |
| JET | `JET` | Modification/correction log | Any data correction |

### 3.4 Chain Structure

**Critical**: Each POS terminal maintains separate chains for each event type.

```
Terminal T1:
  ├── TICKET chain:      T1-TKT-001 → T1-TKT-002 → T1-TKT-003 → ...
  ├── GRANDTOTAL_DAY:    T1-GTD-001 → T1-GTD-002 → ...
  ├── GRANDTOTAL_MONTH:  T1-GTM-001 → T1-GTM-002 → ...
  ├── GRANDTOTAL_YEAR:   T1-GTY-001 → ...
  ├── DUPLICATE:         T1-DUP-001 → T1-DUP-002 → ...
  └── JET:               T1-JET-001 → T1-JET-002 → ...

Terminal T2:
  ├── TICKET chain:      T2-TKT-001 → T2-TKT-002 → ...
  └── ... (same structure)
```

### 3.5 Data Fields for Signing

#### 3.5.1 TICKET Event (Sales Transaction)

The following fields must be concatenated in order and signed:

```typescript
interface NF525TicketPayload {
  // Header
  eventType: 'TICKET';
  sequenceNumber: number;           // Sequential within this chain
  terminalId: string;               // POS unit identifier
  isFirstInSequence: boolean;       // True if first event ever
  previousSignature: string;        // Base64, empty if first
  
  // Timestamp
  timestamp: string;                // ISO 8601 format
  
  // Transaction Data
  transactionId: string;            // Unique transaction reference
  
  // VAT Breakdown (per rate)
  vatBreakdown: Array<{
    rate: number;                   // VAT rate (e.g., 20.00, 10.00, 5.50)
    netAmount: number;              // Amount before VAT
    vatAmount: number;              // VAT amount
    grossAmount: number;            // Amount including VAT
  }>;
  
  // Totals
  totalNetAmount: number;           // Sum of all net amounts
  totalVatAmount: number;           // Sum of all VAT amounts  
  totalGrossAmount: number;         // Total including VAT
  
  // Payment
  paymentMethods: Array<{
    type: string;                   // 'CASH', 'CARD', 'CHECK', etc.
    amount: number;
  }>;
}
```

#### 3.5.2 GRANDTOTAL Event

```typescript
interface NF525GrandTotalPayload {
  // Header
  eventType: 'GRANDTOTAL';
  periodType: 'DAY' | 'MONTH' | 'YEAR';
  sequenceNumber: number;
  terminalId: string;
  isFirstInSequence: boolean;
  previousSignature: string;
  
  // Period
  periodStart: string;              // ISO 8601 date
  periodEnd: string;                // ISO 8601 date
  timestamp: string;                // When closure was performed
  
  // Aggregated VAT Breakdown
  vatBreakdown: Array<{
    rate: number;
    totalNetAmount: number;         // Sum for period
    totalVatAmount: number;
    totalGrossAmount: number;
    transactionCount: number;       // Number of transactions at this rate
  }>;
  
  // Period Totals
  totalTransactions: number;        // Total ticket count
  totalNetAmount: number;
  totalVatAmount: number;
  totalGrossAmount: number;
  
  // Returns/Refunds (included in totals as negative)
  totalReturns: number;
  returnCount: number;
}
```

**Important**: GRANDTOTAL only counts actual items sold. VAT-free vouchers, gift cards used as payment, and similar are excluded from totals.

#### 3.5.3 DUPLICATE Event

```typescript
interface NF525DuplicatePayload {
  eventType: 'DUPLICATE';
  sequenceNumber: number;
  terminalId: string;
  isFirstInSequence: boolean;
  previousSignature: string;
  timestamp: string;
  
  // Reference to original
  originalEventType: string;        // Which event type was duplicated
  originalSequenceNumber: number;
  originalTimestamp: string;
  
  // Reason
  reason: string;                   // Why duplicate was requested
  operatorId: string;               // Who requested it
}
```

#### 3.5.4 JET Event (Corrections/Modifications)

```typescript
interface NF525JetPayload {
  eventType: 'JET';
  sequenceNumber: number;
  terminalId: string;
  isFirstInSequence: boolean;
  previousSignature: string;
  timestamp: string;
  
  // What was modified
  modifiedEventType: string;
  modifiedSequenceNumber: number;
  modificationType: 'VOID' | 'CORRECTION' | 'CANCEL';
  
  // Details
  reason: string;
  operatorId: string;
  supervisorId?: string;            // If supervisor override required
  
  // Before/After for corrections
  originalValues?: Record<string, any>;
  newValues?: Record<string, any>;
}
```

### 3.6 Signature Generation Process

```
1. Serialize payload to canonical JSON (sorted keys, no whitespace)
2. Concatenate: payload + previousSignature
3. Hash using SHA-256
4. Sign hash using RSA-2048 private key
5. Encode signature as Base64
6. Store: event + signature + chain metadata
```

**Canonical serialization example:**

```json
{"eventType":"TICKET","isFirstInSequence":false,"previousSignature":"ABC123...","sequenceNumber":42,"terminalId":"T001","timestamp":"2026-01-08T14:30:00Z","totalGrossAmount":120.00,"totalNetAmount":100.00,"totalVatAmount":20.00,"transactionId":"TRX-2026-001234","vatBreakdown":[{"grossAmount":120.00,"netAmount":100.00,"rate":20.00,"vatAmount":20.00}]}
```

### 3.7 Data Retention

| Requirement | Duration | Notes |
|-------------|----------|-------|
| Minimum retention | 6 years | From end of fiscal year |
| Extended retention | 7 years | If fiscal year differs from calendar |
| Archive format | Exportable | Must be readable by tax authorities |
| Integrity | Preserved | Hash chain must remain verifiable |

### 3.8 Certification Bodies

| Body | Role |
|------|------|
| INFOCERT | Primary certifier, manages NF525 for AFNOR |
| LNE | Alternative certification (Laboratoire National de Métrologie) |
| AFNOR Certification | Standards body |

### 3.9 Penalties for Non-Compliance

| Violation | Penalty |
|-----------|---------|
| Non-compliant register | €7,500 per register |
| Fraudulent manipulation | 80% penalty on non-compliant revenues |
| Continued non-compliance | Repeated fines every 30 days |

---

## 4. ZATCA Requirements (Saudi Arabia)

### 4.1 Regulatory Framework

ZATCA (Zakat, Tax and Customs Authority) mandates electronic invoicing with two phases:

- **Phase 1 (Generation)**: Basic e-invoice generation requirements
- **Phase 2 (Integration)**: Real-time integration with ZATCA platform

### 4.2 Cryptographic Standards

| Component | Requirement | Notes |
|-----------|-------------|-------|
| Hash Algorithm | SHA-256 | Only SHA-256 accepted |
| Signature Algorithm | ECDSA P-256 | Elliptic Curve only |
| XML Signature | XAdES B-B | ETSI EN 319 132-1, enveloped |
| PDF Signature | PAdES B-B | ETSI EN 319 142-1 (if PDF used) |
| Certificate | ZATCA-issued | Obtained through FATOORA portal |

### 4.3 Key Concepts

#### 4.3.1 CSID (Cryptographic Stamp Identifier)

```typescript
interface CSID {
  type: 'COMPLIANCE' | 'PRODUCTION';
  value: string;                    // Unique identifier
  issuedAt: Date;
  expiresAt: Date;
  egsUnitId: string;               // E-Invoice Generation Solution unit
  status: 'ACTIVE' | 'EXPIRED' | 'REVOKED';
}
```

- Unique per EGS (E-Invoice Generation Solution) unit
- Obtained through FATOORA portal onboarding
- Must be renewed before expiry
- Two types: Compliance (testing) and Production (live)

#### 4.3.2 PIH (Previous Invoice Hash)

```typescript
interface InvoiceChainLink {
  invoiceHash: string;              // SHA-256 of current invoice
  previousInvoiceHash: string;      // SHA-256 of previous (empty if first)
  invoiceCounterValue: number;      // ICV - sequential, never reused
}
```

- Each invoice references the hash of the previous invoice
- First invoice uses empty/zero hash
- Even rejected invoices consume sequence numbers
- Creates unbreakable chain

#### 4.3.3 ICV (Invoice Counter Value)

- Sequential counter per EGS unit
- **Never reused** - even for rejected/cancelled invoices
- Tamper-resistant counter required
- Persisted securely (survives restarts)

### 4.4 Invoice Processing Models

| Type | Model | Stamp Generation | Timing |
|------|-------|------------------|--------|
| B2B (Tax Invoice) | Clearance (Sync) | ZATCA generates stamp | Before issuing to customer |
| B2C (Simplified) | Reporting (Async) | EGS generates stamp | Report within 24 hours |

### 4.5 UBL 2.1 XML Structure

ZATCA uses Universal Business Language 2.1 format:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2"
         xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2"
         xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"
         xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2">
  
  <!-- UBL Extensions for security (signatures, hash) -->
  <ext:UBLExtensions>
    <ext:UBLExtension>
      <ext:ExtensionContent>
        <!-- Digital signature goes here -->
      </ext:ExtensionContent>
    </ext:UBLExtension>
  </ext:UBLExtensions>
  
  <!-- Invoice identification -->
  <cbc:ID>INV-2026-000001</cbc:ID>
  <cbc:UUID>550e8400-e29b-41d4-a716-446655440000</cbc:UUID>
  <cbc:IssueDate>2026-01-08</cbc:IssueDate>
  <cbc:IssueTime>14:30:00</cbc:IssueTime>
  
  <!-- Invoice type code -->
  <cbc:InvoiceTypeCode>388</cbc:InvoiceTypeCode>
  
  <!-- Previous Invoice Hash (PIH) -->
  <cac:AdditionalDocumentReference>
    <cbc:ID>PIH</cbc:ID>
    <cac:Attachment>
      <cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">
        <!-- Base64 encoded SHA-256 of previous invoice -->
      </cbc:EmbeddedDocumentBinaryObject>
    </cac:Attachment>
  </cac:AdditionalDocumentReference>
  
  <!-- Invoice Counter Value (ICV) -->
  <cac:AdditionalDocumentReference>
    <cbc:ID>ICV</cbc:ID>
    <cbc:UUID>12345</cbc:UUID>
  </cac:AdditionalDocumentReference>
  
  <!-- Seller information -->
  <cac:AccountingSupplierParty>
    <cac:Party>
      <cac:PartyIdentification>
        <cbc:ID schemeID="VAT">300000000000003</cbc:ID>
      </cac:PartyIdentification>
      <cac:PartyLegalEntity>
        <cbc:RegistrationName>Seller Company Name</cbc:RegistrationName>
      </cac:PartyLegalEntity>
    </cac:Party>
  </cac:AccountingSupplierParty>
  
  <!-- Buyer information -->
  <cac:AccountingCustomerParty>
    <!-- ... -->
  </cac:AccountingCustomerParty>
  
  <!-- Tax totals -->
  <cac:TaxTotal>
    <cbc:TaxAmount currencyID="SAR">15.00</cbc:TaxAmount>
    <cac:TaxSubtotal>
      <cbc:TaxableAmount currencyID="SAR">100.00</cbc:TaxableAmount>
      <cbc:TaxAmount currencyID="SAR">15.00</cbc:TaxAmount>
      <cac:TaxCategory>
        <cbc:ID>S</cbc:ID>
        <cbc:Percent>15</cbc:Percent>
        <cac:TaxScheme>
          <cbc:ID>VAT</cbc:ID>
        </cac:TaxScheme>
      </cac:TaxCategory>
    </cac:TaxSubtotal>
  </cac:TaxTotal>
  
  <!-- Line items -->
  <cac:InvoiceLine>
    <!-- ... -->
  </cac:InvoiceLine>
  
</Invoice>
```

### 4.6 Hash Generation Process

```
1. Take complete UBL XML invoice
2. Remove QR code element (if present)
3. Canonicalize XML (C14N)
4. Apply SHA-256 hash
5. This hash becomes the "Invoice Hash"
6. Sign hash with ECDSA private key
7. Include signature in UBL extension
```

### 4.7 QR Code Requirements (Phase 2)

#### 4.7.1 Required Tags (TLV Format)

| Tag | Field | Data Type |
|-----|-------|-----------|
| 1 | Seller Name | String (UTF-8) |
| 2 | VAT Registration Number | String (15 digits) |
| 3 | Invoice Timestamp | ISO 8601 DateTime |
| 4 | Invoice Total (with VAT) | Decimal |
| 5 | VAT Amount | Decimal |
| 6 | Invoice Hash | Base64 SHA-256 |
| 7 | ECDSA Signature | Base64 |
| 8 | Public Key | Base64 |
| 9 | Certificate Signature (CSH) | Base64 |

#### 4.7.2 TLV Encoding

```typescript
function encodeTLV(tag: number, value: string | Buffer): Buffer {
  const tagByte = Buffer.from([tag]);
  const valueBuffer = Buffer.isBuffer(value) ? value : Buffer.from(value, 'utf-8');
  const lengthByte = Buffer.from([valueBuffer.length]);
  return Buffer.concat([tagByte, lengthByte, valueBuffer]);
}

function generateQRData(invoice: ZATCAInvoice): string {
  const tlvParts = [
    encodeTLV(1, invoice.sellerName),
    encodeTLV(2, invoice.vatNumber),
    encodeTLV(3, invoice.timestamp),
    encodeTLV(4, invoice.totalWithVat.toFixed(2)),
    encodeTLV(5, invoice.vatAmount.toFixed(2)),
    encodeTLV(6, Buffer.from(invoice.hash, 'base64')),
    encodeTLV(7, Buffer.from(invoice.signature, 'base64')),
    encodeTLV(8, Buffer.from(invoice.publicKey, 'base64')),
    encodeTLV(9, Buffer.from(invoice.certificateSignature, 'base64'))
  ];
  
  return Buffer.concat(tlvParts).toString('base64');
}
```

#### 4.7.3 QR Code Technical Specs

| Property | Requirement |
|----------|-------------|
| Type | QR Code Model 2 |
| Error Correction | Level M (15%) |
| Minimum Size | 2 x 2 cm |
| Encoding | Base64 |
| Maximum Length | 700 characters |

### 4.8 Validation Pyramid

ZATCA validates invoices at three levels:

```
┌─────────────────────────────────────┐
│      Level 3: Hash Validation       │
│  ZATCA computes hash, compares      │
│  with submitted hash to detect      │
│  any tampering                      │
├─────────────────────────────────────┤
│     Level 2: Business Validation    │
│  VAT numbers valid, amounts         │
│  correct, required fields present   │
├─────────────────────────────────────┤
│    Level 1: Technical Validation    │
│  XML structure valid, UBL 2.1       │
│  compliant, schema validation       │
└─────────────────────────────────────┘
```

### 4.9 API Integration

#### 4.9.1 Endpoints

| Endpoint | Purpose | Model |
|----------|---------|-------|
| `/compliance` | Testing/validation | Both |
| `/invoices/clearance` | B2B invoice submission | Sync |
| `/invoices/reporting` | B2C invoice reporting | Async |

#### 4.9.2 B2B Flow (Clearance)

```
1. Generate UBL invoice with PIH and ICV
2. Sign invoice with EGS private key
3. Submit to ZATCA clearance endpoint
4. Wait for ZATCA response (synchronous)
5. ZATCA adds/modifies cryptographic stamp
6. Receive cleared invoice with ZATCA signature
7. Only then can invoice be issued to customer
```

#### 4.9.3 B2C Flow (Reporting)

```
1. Generate UBL invoice with PIH and ICV
2. Generate cryptographic stamp locally
3. Generate QR code with all 9 tags
4. Issue invoice to customer immediately
5. Report to ZATCA within 24 hours
6. Handle any rejection/correction asynchronously
```

### 4.10 Data Retention

| Requirement | Specification |
|-------------|---------------|
| Storage | Secure vault for certificates and keys |
| Encryption | TLS for all connections |
| Retention | Per ZATCA specified timelines |
| Key Security | Non-exportable from security module |

### 4.11 Penalties

| Violation | Penalty |
|-----------|---------|
| Missing/incorrect QR code | Up to SAR 50,000 per instance |
| Missing cryptographic stamp | Up to SAR 50,000 per instance |
| Incorrect data fields | Up to SAR 50,000 per instance |

---

## 5. Common Patterns & Abstractions

### 5.1 Abstract Hash Chain Interface

```php
<?php

namespace App\Modules\FiscalCompliance\Domain\Contracts;

interface HashChainStrategy
{
    /**
     * Get the chain identifier for a given context
     */
    public function getChainId(ChainContext $context): string;
    
    /**
     * Get the previous entry in the chain
     */
    public function getPreviousEntry(string $chainId): ?SignedEvent;
    
    /**
     * Create the payload to be signed
     */
    public function createPayload(FiscalEvent $event, ?SignedEvent $previous): array;
    
    /**
     * Sign the payload and create a signed event
     */
    public function sign(array $payload, Certificate $certificate): SignedEvent;
    
    /**
     * Verify a signed event
     */
    public function verify(SignedEvent $event, Certificate $certificate): bool;
    
    /**
     * Get cryptographic configuration
     */
    public function getCryptoConfig(): CryptoConfig;
}
```

### 5.2 Chain Context

```php
<?php

namespace App\Modules\FiscalCompliance\Domain\ValueObjects;

final class ChainContext
{
    public function __construct(
        public readonly string $countryCode,      // 'FR', 'SA'
        public readonly string $unitId,           // Terminal ID or EGS ID
        public readonly string $eventType,        // 'TICKET', 'INVOICE', etc.
        public readonly ?string $periodType = null // 'DAY', 'MONTH', 'YEAR' for grandtotals
    ) {}
    
    public function toChainId(): string
    {
        $parts = [$this->countryCode, $this->unitId, $this->eventType];
        if ($this->periodType) {
            $parts[] = $this->periodType;
        }
        return implode(':', $parts);
    }
}
```

### 5.3 Signed Event Entity

```php
<?php

namespace App\Modules\FiscalCompliance\Domain\Entities;

final class SignedEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $chainId,
        public readonly int $sequenceNumber,
        public readonly string $eventType,
        public readonly array $payload,
        public readonly string $payloadHash,
        public readonly string $signature,
        public readonly ?string $previousSignature,
        public readonly bool $isFirst,
        public readonly DateTimeImmutable $timestamp,
        public readonly string $certificateFingerprint
    ) {}
}
```

### 5.4 Crypto Configuration

```php
<?php

namespace App\Modules\FiscalCompliance\Domain\ValueObjects;

final class CryptoConfig
{
    public function __construct(
        public readonly string $hashAlgorithm,      // 'SHA256', 'SHA384', 'SHA512'
        public readonly string $signatureAlgorithm, // 'RSA', 'ECDSA'
        public readonly int $keySize,               // 2048 for RSA, 256 for ECDSA
        public readonly string $signatureFormat,    // 'RAW', 'XADES', 'PADES'
    ) {}
    
    public static function forNF525(): self
    {
        return new self(
            hashAlgorithm: 'SHA256',
            signatureAlgorithm: 'RSA',
            keySize: 2048,
            signatureFormat: 'RAW'
        );
    }
    
    public static function forZATCA(): self
    {
        return new self(
            hashAlgorithm: 'SHA256',
            signatureAlgorithm: 'ECDSA',
            keySize: 256,
            signatureFormat: 'XADES'
        );
    }
}
```

---

## 6. Database Schema Design

### 6.1 Core Tables

```sql
-- Signing certificates per unit/country
CREATE TABLE fiscal_certificates (
    id UUID PRIMARY KEY,
    country_code CHAR(2) NOT NULL,
    unit_id VARCHAR(100) NOT NULL,
    certificate_type VARCHAR(50) NOT NULL, -- 'SELF_SIGNED', 'ZATCA_COMPLIANCE', 'ZATCA_PRODUCTION'
    
    -- Certificate data
    public_key TEXT NOT NULL,
    private_key_encrypted TEXT NOT NULL,
    certificate_pem TEXT,
    fingerprint VARCHAR(64) NOT NULL,
    
    -- ZATCA specific
    csid VARCHAR(255),
    csid_type VARCHAR(20), -- 'COMPLIANCE', 'PRODUCTION'
    
    -- Lifecycle
    issued_at TIMESTAMPTZ NOT NULL,
    expires_at TIMESTAMPTZ NOT NULL,
    revoked_at TIMESTAMPTZ,
    status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
    
    -- Audit
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    UNIQUE(country_code, unit_id, certificate_type, status)
);

-- Hash chains metadata
CREATE TABLE fiscal_chains (
    id UUID PRIMARY KEY,
    chain_id VARCHAR(255) NOT NULL UNIQUE,
    country_code CHAR(2) NOT NULL,
    unit_id VARCHAR(100) NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    period_type VARCHAR(20), -- NULL for non-grandtotal events
    
    -- Chain state
    current_sequence INT NOT NULL DEFAULT 0,
    last_signature TEXT,
    last_event_at TIMESTAMPTZ,
    
    -- Statistics
    total_events BIGINT NOT NULL DEFAULT 0,
    
    -- Audit
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    INDEX idx_chains_country_unit (country_code, unit_id),
    INDEX idx_chains_event_type (event_type)
);

-- Signed events (immutable)
CREATE TABLE fiscal_signed_events (
    id UUID PRIMARY KEY,
    chain_id VARCHAR(255) NOT NULL REFERENCES fiscal_chains(chain_id),
    sequence_number INT NOT NULL,
    
    -- Event data
    event_type VARCHAR(50) NOT NULL,
    payload JSONB NOT NULL,
    payload_canonical TEXT NOT NULL, -- Canonical form used for hashing
    
    -- Cryptographic data
    payload_hash VARCHAR(128) NOT NULL,
    signature TEXT NOT NULL,
    previous_signature TEXT,
    is_first BOOLEAN NOT NULL DEFAULT FALSE,
    
    -- Certificate reference
    certificate_id UUID NOT NULL REFERENCES fiscal_certificates(id),
    certificate_fingerprint VARCHAR(64) NOT NULL,
    
    -- Timestamps
    event_timestamp TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    
    -- Reference to business entity
    reference_type VARCHAR(50), -- 'SALE', 'INVOICE', 'GRANDTOTAL', etc.
    reference_id UUID,
    
    UNIQUE(chain_id, sequence_number),
    INDEX idx_events_chain_seq (chain_id, sequence_number DESC),
    INDEX idx_events_reference (reference_type, reference_id),
    INDEX idx_events_timestamp (event_timestamp)
);

-- ZATCA specific: Invoice counter (ICV)
CREATE TABLE zatca_invoice_counters (
    id UUID PRIMARY KEY,
    egs_unit_id VARCHAR(100) NOT NULL UNIQUE,
    current_value BIGINT NOT NULL DEFAULT 0,
    last_used_at TIMESTAMPTZ,
    
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ZATCA specific: Invoice submissions
CREATE TABLE zatca_invoice_submissions (
    id UUID PRIMARY KEY,
    signed_event_id UUID NOT NULL REFERENCES fiscal_signed_events(id),
    
    -- Submission data
    invoice_type VARCHAR(20) NOT NULL, -- 'TAX', 'SIMPLIFIED'
    processing_model VARCHAR(20) NOT NULL, -- 'CLEARANCE', 'REPORTING'
    
    -- Request
    request_xml TEXT NOT NULL,
    request_hash VARCHAR(64) NOT NULL,
    submitted_at TIMESTAMPTZ NOT NULL,
    
    -- Response
    status VARCHAR(20) NOT NULL, -- 'PENDING', 'CLEARED', 'REPORTED', 'REJECTED'
    response_xml TEXT,
    zatca_invoice_hash VARCHAR(64),
    zatca_stamp TEXT,
    warnings JSONB,
    errors JSONB,
    responded_at TIMESTAMPTZ,
    
    -- QR Code
    qr_code_data TEXT,
    qr_code_image BYTEA,
    
    INDEX idx_submissions_status (status),
    INDEX idx_submissions_event (signed_event_id)
);
```

### 6.2 Locking Strategy

For maintaining chain integrity, use pessimistic locking:

```php
<?php

public function appendToChain(string $chainId, FiscalEvent $event): SignedEvent
{
    return DB::transaction(function () use ($chainId, $event) {
        // Lock the chain row for update
        $chain = FiscalChain::where('chain_id', $chainId)
            ->lockForUpdate()
            ->firstOrFail();
        
        // Get previous event
        $previous = $chain->last_signature 
            ? $this->getEventBySignature($chain->last_signature)
            : null;
        
        // Create and sign new event
        $signedEvent = $this->strategy->sign(
            $this->strategy->createPayload($event, $previous),
            $this->getCertificate($chain)
        );
        
        // Persist event
        FiscalSignedEvent::create([
            'chain_id' => $chainId,
            'sequence_number' => $chain->current_sequence + 1,
            // ... other fields
        ]);
        
        // Update chain state
        $chain->update([
            'current_sequence' => $chain->current_sequence + 1,
            'last_signature' => $signedEvent->signature,
            'last_event_at' => now(),
            'total_events' => $chain->total_events + 1,
        ]);
        
        return $signedEvent;
    });
}
```

---

## 7. Implementation Guidelines

### 7.1 Module Structure

```
app/Modules/FiscalCompliance/
├── Domain/
│   ├── Contracts/
│   │   ├── HashChainStrategy.php
│   │   ├── SigningService.php
│   │   └── CertificateManager.php
│   ├── Entities/
│   │   ├── SignedEvent.php
│   │   ├── FiscalChain.php
│   │   └── Certificate.php
│   ├── ValueObjects/
│   │   ├── ChainContext.php
│   │   ├── CryptoConfig.php
│   │   └── Signature.php
│   ├── Events/
│   │   ├── FiscalEvent.php
│   │   ├── TicketEvent.php
│   │   ├── GrandTotalEvent.php
│   │   └── InvoiceEvent.php
│   └── Services/
│       └── HashChainService.php
├── Infrastructure/
│   ├── Strategies/
│   │   ├── NF525Strategy.php
│   │   └── ZATCAStrategy.php
│   ├── Signing/
│   │   ├── RsaSigner.php
│   │   ├── EcdsaSigner.php
│   │   └── XadesBuilder.php
│   ├── Persistence/
│   │   ├── EloquentEventStore.php
│   │   └── EloquentChainRepository.php
│   └── External/
│       └── ZATCAApiClient.php
├── Application/
│   ├── Commands/
│   │   ├── AppendToChainCommand.php
│   │   ├── CloseGrandTotalCommand.php
│   │   └── SubmitToZATCACommand.php
│   ├── Handlers/
│   │   └── ...
│   └── DTOs/
│       └── ...
└── Presentation/
    ├── Controllers/
    │   └── FiscalComplianceController.php
    └── routes.php
```

### 7.2 Event Sourcing Integration

The fiscal module should integrate with the existing event-sourced architecture:

```php
<?php

// When a sale is completed
class SaleCompletedHandler
{
    public function handle(SaleCompleted $event): void
    {
        // Determine country
        $country = $this->getCountryForTerminal($event->terminalId);
        
        // Create fiscal event
        $fiscalEvent = match($country) {
            'FR' => new NF525TicketEvent($event),
            'SA' => new ZATCAInvoiceEvent($event),
            default => null,
        };
        
        if ($fiscalEvent) {
            $this->hashChainService->append($fiscalEvent);
        }
    }
}
```

### 7.3 Error Handling

```php
<?php

// Fiscal operations must never silently fail
class HashChainService
{
    public function append(FiscalEvent $event): SignedEvent
    {
        try {
            return $this->doAppend($event);
        } catch (ChainIntegrityException $e) {
            // Critical: Chain is broken
            $this->alertService->critical('Fiscal chain integrity failure', [
                'chain_id' => $e->chainId,
                'error' => $e->getMessage(),
            ]);
            throw $e; // Must not continue
        } catch (CryptoException $e) {
            // Critical: Signing failed
            $this->alertService->critical('Fiscal signing failure', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
```

### 7.4 Canonical Serialization

```php
<?php

class CanonicalSerializer
{
    /**
     * Serialize payload to canonical JSON for hashing
     * - Keys sorted alphabetically
     * - No whitespace
     * - Numbers without trailing zeros
     * - Consistent decimal precision
     */
    public function serialize(array $payload): string
    {
        return json_encode(
            $this->normalize($payload),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
    
    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if ($this->isAssociative($value)) {
                ksort($value);
            }
            return array_map([$this, 'normalize'], $value);
        }
        
        if (is_float($value)) {
            return round($value, 2); // Monetary precision
        }
        
        return $value;
    }
}
```

---

## 8. Security Considerations

### 8.1 Key Management

| Requirement | Implementation |
|-------------|----------------|
| Storage | Encrypted at rest (Laravel's encryption) |
| Access | Minimal permissions, audit logging |
| Backup | Geo-redundant, encrypted backups |
| Rotation | Support certificate renewal without chain break |

### 8.2 Private Key Protection

```php
<?php

// Private keys should never be exposed
class SecureKeyStore
{
    public function sign(string $data, string $keyId): string
    {
        // Key never leaves secure storage
        $encryptedKey = $this->getEncryptedKey($keyId);
        $key = $this->decrypt($encryptedKey);
        
        try {
            return $this->signer->sign($data, $key);
        } finally {
            // Wipe key from memory
            sodium_memzero($key);
        }
    }
}
```

### 8.3 Audit Logging

All fiscal operations must be logged:

```php
<?php

class FiscalAuditLogger
{
    public function log(string $operation, array $context): void
    {
        FiscalAuditLog::create([
            'operation' => $operation,
            'chain_id' => $context['chain_id'] ?? null,
            'event_id' => $context['event_id'] ?? null,
            'operator_id' => auth()->id(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'context' => $context,
            'timestamp' => now(),
        ]);
    }
}
```

---

## 9. Testing & Validation

### 9.1 Chain Integrity Tests

```php
<?php

class HashChainIntegrityTest extends TestCase
{
    /** @test */
    public function chain_maintains_integrity_across_events(): void
    {
        $chain = $this->createChain('FR', 'T001', 'TICKET');
        
        // Append multiple events
        $events = [];
        for ($i = 0; $i < 100; $i++) {
            $events[] = $this->service->append($chain, $this->createTicketEvent());
        }
        
        // Verify entire chain
        $this->assertTrue($this->verifyChainIntegrity($chain));
        
        // Verify each link
        for ($i = 1; $i < count($events); $i++) {
            $this->assertEquals(
                $events[$i - 1]->signature,
                $events[$i]->previousSignature
            );
        }
    }
    
    /** @test */
    public function tampering_is_detected(): void
    {
        $chain = $this->createChain('FR', 'T001', 'TICKET');
        $event = $this->service->append($chain, $this->createTicketEvent());
        
        // Tamper with stored payload
        DB::table('fiscal_signed_events')
            ->where('id', $event->id)
            ->update(['payload->totalGrossAmount' => 999999]);
        
        // Verification must fail
        $this->assertFalse($this->verifyEvent($event->id));
    }
}
```

### 9.2 ZATCA Compliance Tests

```php
<?php

class ZATCAComplianceTest extends TestCase
{
    /** @test */
    public function generates_valid_ubl_xml(): void
    {
        $invoice = $this->createInvoice();
        $xml = $this->zatcaStrategy->generateUBL($invoice);
        
        // Validate against ZATCA schema
        $this->assertTrue($this->validateAgainstSchema($xml, 'zatca-ubl-2.1.xsd'));
    }
    
    /** @test */
    public function qr_code_contains_all_required_tags(): void
    {
        $invoice = $this->createSignedInvoice();
        $qrData = $this->zatcaStrategy->generateQRCode($invoice);
        
        $decoded = $this->decodeTLV(base64_decode($qrData));
        
        $this->assertArrayHasKey(1, $decoded); // Seller name
        $this->assertArrayHasKey(2, $decoded); // VAT number
        $this->assertArrayHasKey(3, $decoded); // Timestamp
        $this->assertArrayHasKey(4, $decoded); // Total
        $this->assertArrayHasKey(5, $decoded); // VAT amount
        $this->assertArrayHasKey(6, $decoded); // Hash
        $this->assertArrayHasKey(7, $decoded); // Signature
        $this->assertArrayHasKey(8, $decoded); // Public key
        $this->assertArrayHasKey(9, $decoded); // Certificate signature
    }
}
```

### 9.3 Performance Tests

```php
<?php

class HashChainPerformanceTest extends TestCase
{
    /** @test */
    public function signing_completes_within_acceptable_time(): void
    {
        $chain = $this->createChain('FR', 'T001', 'TICKET');
        
        $start = microtime(true);
        
        for ($i = 0; $i < 1000; $i++) {
            $this->service->append($chain, $this->createTicketEvent());
        }
        
        $elapsed = microtime(true) - $start;
        
        // Must complete 1000 signatures in under 30 seconds
        $this->assertLessThan(30, $elapsed);
        
        // Average per signature under 30ms
        $this->assertLessThan(0.030, $elapsed / 1000);
    }
}
```

---

## 10. Certification Process

### 10.1 NF525 Certification Steps

1. **Pre-audit preparation**
   - Implement all ISCA requirements
   - Prepare documentation
   - Internal testing

2. **Contact certification body**
   - INFOCERT or LNE
   - Submit application

3. **Compliance audit**
   - Technical review of implementation
   - Security assessment
   - Documentation review

4. **Corrections (if needed)**
   - Address any findings
   - Re-submit for review

5. **Certification granted**
   - Receive NF525 certificate
   - Valid for 3 years with annual surveillance

### 10.2 ZATCA Onboarding Steps

1. **FATOORA Portal Registration**
   - Create account
   - Register EGS solution

2. **Compliance CSID**
   - Generate CSR
   - Submit to ZATCA
   - Receive compliance certificate

3. **Compliance Testing**
   - Submit test invoices
   - Validate against ZATCA API
   - Fix any issues

4. **Production CSID**
   - Request production certificate
   - Activate for live invoicing

5. **Go Live**
   - Begin submitting real invoices
   - Monitor for errors

### 10.3 Ongoing Compliance

| Activity | NF525 | ZATCA |
|----------|-------|-------|
| Annual audit | Yes | No (continuous) |
| Certificate renewal | Every 3 years | Before expiry |
| Software updates | Report changes | Update EGS registration |
| Data retention | 6-7 years | Per ZATCA rules |

---

## Appendix A: Glossary

| Term | Definition |
|------|------------|
| CSID | Cryptographic Stamp Identifier (ZATCA) |
| EGS | E-Invoice Generation Solution |
| ISCA | Inalterability, Security, Conservation, Archiving (NF525) |
| ICV | Invoice Counter Value |
| JET | Journal of Technical Events (NF525) |
| PIH | Previous Invoice Hash |
| TLV | Tag-Length-Value encoding |
| UBL | Universal Business Language |
| XAdES | XML Advanced Electronic Signatures |

---

## Appendix B: References

- [INFOCERT NF525 Certification](https://infocert.org/en/nf525/)
- [ZATCA E-Invoicing Technical Guidelines](https://zatca.gov.sa/en/E-Invoicing/Introduction/Guidelines/Documents/E-invoicing-Detailed-Technical-Guideline.pdf)
- [OASIS UBL 2.1 Specification](http://docs.oasis-open.org/ubl/os-UBL-2.1/UBL-2.1.html)
- [ETSI XAdES Specification (EN 319 132-1)](https://www.etsi.org/deliver/etsi_en/319100_319199/31913201/)

---

**Document End**
