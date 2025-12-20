# Fiscal Compliance Architecture

> Hash chain and compliance architecture for NF525/ZATCA readiness.

---

## Overview

AutoERP implements a tamper-proof fiscal document chain using SHA-256 hashes. This ensures:
- Documents cannot be modified after posting
- Documents cannot be deleted without detection
- Documents cannot be inserted into the middle of the chain

---

## Hash Chain Implementation

### Document Types in Fiscal Chain

| Document Type | Fiscal Category | Chain |
|---------------|-----------------|-------|
| Invoice | TAX_INVOICE | Separate chain per company |
| Credit Note | CREDIT_NOTE | Separate chain per company |
| Quote | NON_FISCAL | No chain |
| Sales Order | NON_FISCAL | No chain |
| Purchase Order | NON_FISCAL | No chain |

### Hash Calculation

```
Genesis:  SHA256(company_seed | doc_number | date | total | currency)
Chained:  SHA256(previous_hash | doc_number | date | total | currency)
```

**Genesis Seed:** Each company has a unique 256-bit `fiscal_chain_seed` generated at creation. This is more secure than ZATCA's fixed `SHA256("0")` approach.

### Fiscal Status Flow

```
DRAFT → SEALED → VOIDED
         ↓
      (immutable)
```

| Status | Meaning | Modifications Allowed |
|--------|---------|----------------------|
| DRAFT | Not yet posted | All fields |
| SEALED | Posted to fiscal chain | Only `balance_due` |
| VOIDED | Cancelled | None |

---

## Database Implementation

### Documents Table (Fiscal Fields)

```sql
-- Fiscal chain fields
fiscal_category     ENUM('NON_FISCAL', 'TAX_INVOICE', 'CREDIT_NOTE')
fiscal_status       ENUM('DRAFT', 'SEALED', 'VOIDED')
fiscal_hash         VARCHAR(64)     -- SHA-256 hash
previous_hash       VARCHAR(64)     -- Link to previous document
chain_sequence      INTEGER         -- Position in chain (1, 2, 3...)
```

### Companies Table (Genesis Seed)

```sql
fiscal_chain_seed   VARCHAR(64)     -- 256-bit hex seed
```

### Database Constraints (PostgreSQL)

```sql
-- Fiscal documents must have required fields
CHECK (
    fiscal_category = 'NON_FISCAL'
    OR (
        document_date IS NOT NULL
        AND document_number IS NOT NULL
        AND total IS NOT NULL
        AND currency IS NOT NULL
        AND fiscal_hash IS NOT NULL
        AND chain_sequence IS NOT NULL
    )
)
```

### Immutability Trigger (PostgreSQL)

Sealed documents are protected by a database trigger that prevents modification of:
- `total`, `subtotal`, `tax_amount`
- `document_number`, `document_date`
- `fiscal_hash`, `previous_hash`, `chain_sequence`
- `fiscal_category`, `fiscal_status` (except SEALED→VOIDED)

**Allowed:** `balance_due` updates for payment allocation.

---

## Services

### DocumentPostingService

```php
// Posts a confirmed document to the fiscal chain
$posted = $postingService->post($document);

// Cancels a posted document (marks as VOIDED)
$cancelled = $postingService->cancel($document);
```

### FiscalHashService

```php
// Calculate hash for a document
$hash = $hashService->calculateHash($input, $previousHash, $genesisSeed);

// Verify an entire chain
$valid = $hashService->verifyChain($documents, $genesisSeed);
```

---

## NF525 Readiness Checklist (Future POS)

When adding POS functionality:
- [ ] Z-reports (daily closings with grand totals)
- [ ] Perpetual totals (cumulative across periods)
- [ ] Receipt chaining (separate from invoice chain)
- [ ] Technical event log (JET)
- [ ] Duplicate/reprint tracking
- [ ] Digital signature (RSA 2048 or ECDSA 256)

---

## E-Invoicing (Factur-X) - Future

For French B2B invoices:
- Generate Factur-X XML (EN 16931 compliant)
- Embed in PDF/A-3
- Submit to PDP (Plateforme de Dématérialisation Partenaire)
