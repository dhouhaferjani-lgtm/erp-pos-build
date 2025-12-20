# Audit & Logging

> Lifecycle tracing and compliance

---

## Executive Summary

**Compliance Readiness: 85%**

The system has a robust fiscal compliance foundation with hash chains and 5 domain events. Gaps exist in document lifecycle tracing (quotes, orders) and regular user action logging.

---

## 1. Domain Event System

### Implemented Events (5 total)

| Event | Entity | Fiscal? | Trigger |
|-------|--------|---------|---------|
| `InvoicePosted` | Document | Yes | Invoice posting |
| `InvoiceCancelled` | Document | Yes | Invoice cancellation |
| `InvoicePaid` | Document | No | Full payment received |
| `DeliveryNoteConfirmed` | Document | Yes | DN confirmation |
| `PaymentRecorded` | Payment | Yes* | Payment recording |

### Event Payloads

**InvoicePosted**:
```json
{
  "invoiceId": "uuid",
  "tenantId": "uuid",
  "companyId": "uuid",
  "documentNumber": "INV-2025-001",
  "documentType": "invoice",
  "partnerId": "uuid",
  "total": "1500.00",
  "currency": "TND",
  "fiscalHash": "sha256(...)",
  "chainSequence": 42,
  "postedAt": "2025-12-13T10:30:45Z"
}
```

### Missing Events (Gaps)

| Operation | Impact |
|-----------|--------|
| Document created (Draft) | No creation audit |
| Document confirmed | No confirmation trail |
| Quote → Order conversion | Conversion opaque |
| Partner created/updated | No lifecycle tracking |
| Product created/updated | No product audit |
| Payment method changes | Config changes hidden |

---

## 2. Audit Trail Implementation

### Database Schema

**Table**: `audit_events`

```sql
id UUID PRIMARY KEY
tenant_id UUID (indexed)
company_id UUID (indexed)
user_id UUID (nullable, indexed)
event_type VARCHAR(100) (indexed)      -- e.g., 'invoice.posted'
aggregate_type VARCHAR(100) (indexed)  -- e.g., 'Document'
aggregate_id VARCHAR(100) (indexed)    -- Entity UUID
payload JSONB                          -- Full event data
metadata JSONB                         -- event_class, occurred_at
event_hash VARCHAR(64)                 -- SHA-256 hash
occurred_at TIMESTAMP (indexed)

-- Composite Indexes
INDEX [tenant_id, event_type]
INDEX [aggregate_type, aggregate_id]   -- Document history
INDEX [company_id, user_id, occurred_at]
```

### Event Subscriber

**Class**: `DomainEventSubscriber`

**Features**:
- Persists all 5 events to `audit_events`
- Non-blocking (audit failure doesn't break operations)
- Records company_id for multi-tenancy
- Captures full payload + metadata

---

## 3. Fiscal Hash Chain

### Implementation

**Service**: `FiscalHashService`

```
Hash = SHA256(previous_hash | document_number | posted_at | total | currency)
```

**Key Features**:
- Genesis seed per company (256-bit entropy, better than ZATCA's "0")
- Separate chains per [company_id, document_type]
- Previous_hash reference prevents insertion attacks
- Chain verification command available

### Document Fields

```sql
fiscal_hash VARCHAR(64)
chain_sequence INT
previous_hash VARCHAR(64)
fiscal_status ENUM (null, 'Sealed', 'Voided')
```

### Verification Command

```bash
php artisan verify:fiscal-chains --company={id} --type=invoice
```

Reports:
- Chain sequence gaps
- Hash mismatches
- Previous_hash breaks

---

## 4. Admin Audit Logs

### Super-Admin Level

**Table**: `admin_audit_logs`

```sql
id UUID PRIMARY KEY
super_admin_id UUID
tenant_id UUID (nullable)
action VARCHAR(50)        -- e.g., 'tenant.created'
entity_type VARCHAR(50)
entity_id UUID
old_values JSON
new_values JSON
ip_address VARCHAR(45)
user_agent VARCHAR(255)
notes TEXT
created_at TIMESTAMP
```

### What's Tracked

- Tenant creation/updates
- User role changes
- System configuration changes

### What's NOT Tracked

- Regular user CRUD operations
- Login/logout events
- Permission check failures
- Failed authentication attempts

---

## 5. Anomaly Detection

**Service**: `AnomalyDetectionService`

### Detection Types

| Type | Threshold | Severity |
|------|-----------|----------|
| `high_void_rate` | ≥10 voided docs | warning/critical |
| `high_activity_rate` | ≥100 events/min | warning |
| `repeated_action` | >50 same event | info |
| `after_hours_activity` | Before 8am/after 8pm | flagged |

### API Endpoint

```
GET /api/v1/audit/anomalies?from=2025-12-01&to=2025-12-31
```

---

## 6. Traceability Analysis

### What CAN Be Traced

```
Invoice (id=INV-001)
├─ posted_at: 2025-12-05 → InvoicePosted EVENT ✓
│   - fiscal_hash recorded
│   - chain_sequence recorded
├─ Payment 1 → PaymentRecorded EVENT ✓
├─ Payment 2 → PaymentRecorded EVENT ✓
└─ paid_at: 2025-12-10 → InvoicePaid EVENT ✓

Query: GET /audit/events?aggregate_type=Document&aggregate_id=INV-001
```

### What CANNOT Be Traced

```
Quote → Order → Invoice conversion chain NOT logged
- Only implicit via source_document_id field
- Must manually walk relationships
- No audit event marks transitions
```

### GL Entry Tracing ✅

```sql
SELECT * FROM journal_entries
WHERE source_type = 'Document'
  AND source_id = 'uuid-of-invoice-001'
```

### Stock Movement Tracing ⚠️

```sql
-- Has fields but no guaranteed link
SELECT * FROM stock_movements
WHERE reference = 'INV-001'  -- Not enforced
```

---

## 7. Compliance Status

### NF525 (France)

| Requirement | Status |
|-------------|--------|
| Fiscal hash chain | ✅ Ready |
| Immutability post-posting | ✅ Ready |
| Sequential numbering | ✅ Ready |
| Z-reports (daily closings) | ❌ Not implemented |
| Perpetual totals | ✅ Ready |
| Technical event log (JET) | ⚠️ Partial |

### ZATCA (Saudi Arabia)

| Requirement | Status |
|-------------|--------|
| Hash chain | ✅ Ready (enhanced) |
| Digital signature | ❌ Not implemented |
| Seller info immutability | ⚠️ Partial |

### Tunisia FANID

| Requirement | Status |
|-------------|--------|
| Delivery note chaining | ✅ Ready |
| E-invoice compatibility | ⚠️ Partial |

---

## 8. API Endpoints

### Audit Events

```
GET /api/v1/audit/events
  ?event_type=invoice.posted
  ?aggregate_type=Document&aggregate_id=uuid
  ?from=2025-12-01&to=2025-12-31
```

### Anomalies

```
GET /api/v1/audit/anomalies
  ?from=2025-12-01&to=2025-12-31
```

---

## 9. Gaps & Recommendations

### Phase 1 - Critical (Compliance Risk)

| Gap | Impact | Effort |
|-----|--------|--------|
| Document lifecycle events | No draft/confirm audit | 8h |
| Quote→Order→Invoice tracking | Conversion opaque | 4h |
| User CRUD logging | Actions invisible | 6h |
| Opening balance event | No import audit | 4h |

### Phase 2 - Compliance Enhancement

| Feature | Status | Effort |
|---------|--------|--------|
| Z-report generation | Missing | 12h |
| Digital signatures (RSA 2048) | Missing | 8h |
| POS receipt chaining | Missing | 16h |
| E-invoice Factur-X | Partial | 12h |

### Phase 3 - Operational

| Enhancement | Benefit | Effort |
|-------------|---------|--------|
| User action logging | Track all CRUD | 8h |
| API request audit | Detect abuse | 6h |
| Real-time anomaly alerts | Email on breach | 4h |
| Audit export (ZIP) | Regulatory | 8h |

---

## 10. Recommended New Events

### Document Lifecycle

```php
DraftDocumentCreated::class
DocumentConfirmed::class
DocumentConverted::class  // With source_type, target_type
```

### Opening Balances

```php
AccountingOpeningProcessed::class
InventoryOpeningProcessed::class
```

### User Actions

```php
UserAuthenticated::class
UserLoggedOut::class
PermissionCheckFailed::class
```

---

## 11. File Locations

### Backend

```
apps/api/app/Modules/Compliance/
├── Services/
│   ├── FiscalHashService.php
│   ├── AnomalyDetectionService.php
│   └── UninvoicedDeliveryNoteService.php
├── Listeners/
│   └── DomainEventSubscriber.php
├── Commands/
│   └── VerifyFiscalChainsCommand.php
└── Providers/
    └── ComplianceServiceProvider.php

apps/api/app/Modules/Document/Domain/Events/
├── InvoicePosted.php
├── InvoiceCancelled.php
├── InvoicePaid.php
└── DeliveryNoteConfirmed.php

apps/api/app/Modules/Treasury/Domain/Events/
└── PaymentRecorded.php
```

### Database

```
apps/api/database/migrations/
├── 2025_11_30_140000_create_audit_events_table.php
└── 2025_12_01_194632_create_admin_audit_logs_table.php
```

---

## 12. Testing Checklist

### Hash Chain

- [ ] Genesis document creates valid hash
- [ ] Chain of 100 documents remains valid
- [ ] Gap detection works
- [ ] Hash mismatch detection works

### Events

- [ ] InvoicePosted fires on posting
- [ ] InvoiceCancelled fires on cancellation
- [ ] PaymentRecorded fires on payment
- [ ] Events persist to audit_events table

### Anomalies

- [ ] High void rate detection
- [ ] After-hours activity flagging
- [ ] Repeated action detection

---

**File**: `docs/live-readiness/07-AUDIT-LOGGING.md`
**Generated**: 2025-12-13
