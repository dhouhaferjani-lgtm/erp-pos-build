# Sales & Payments Lifecycle Specification for Certified ERPs (Medium Version)

## 1. Introduction
This document outlines the standard flows for Quote → Sales Order → Delivery → Invoice → Payment, including constraints for certification environments such as NF525 (France), GoBD (Germany), and ZATCA (Saudi Arabia). It provides a balanced, medium-depth overview suitable for functional and technical design.

## 2. Core Document Types

### Non-Fiscal Documents (Editable)
- Quotation (Offer)
- Sales Order (SO)
- Delivery Note (DN)
- Work Order (for mechanics/services)

### Fiscal Documents (Immutable)
- Invoice
- POS Fiscal Receipt
- Advance Payment Invoice
- Credit Note
- Debit Note

### Payment Records
- Payments (cash, card, transfer, cheque, mixed)
- Customer Credit (unallocated payments)

## 3. Universal Sales → Payment Lifecycle
A typical lifecycle:

```
Quotation → Sales Order → Delivery → Invoice → Payment → Reconciliation
```

The ERP must support variations based on business model and country rules.

## 4. Standard Sales Flows

### Flow A — Quote → SO → Delivery → Invoice → Payment
Most common for wholesalers, car parts sellers, manufacturing.

### Flow B — Quote → Invoice → Payment
Used for services and simple businesses.

### Flow C — SO → Advance Invoice → Payment → Delivery → Final Invoice
Used when deposits or prepayments are required.

### Flow D — SO → Payment → Invoice
Payment received before billing; the ERP must support customer credit.

### Flow E — Partial Delivery + Partial Invoices
Common in large B2B operations.

### Flow F — POS Immediate Invoice → Payment
Mandatory for retail fiscalization.

## 5. Fiscal Certification Constraints

### Universal Requirements
- Immutable invoices
- Chronological numbering
- Audit logs for every change
- No deletion or modification of fiscal documents
- Credit notes are the only valid reversal mechanism

### France (NF525)
- Signature/hash chain (“scellage”)
- POS receipts must be sealed
- No modification after issuance

### Saudi Arabia (ZATCA)
- Real-time reporting
- QR codes and UUID
- Strict JSON/XML structures

### Germany (GoBD + TSE)
- TSE transaction signing
- DSFinV-K export
- Immutable logs

## 6. Required ERP Features

### Document Handling
- Versioning for non-fiscal documents
- Document state transitions
- Prevent fiscal backdating

### Invoicing
- Immutable fiscal data
- Sequence integrity
- Digital sealing as required per country

### Payments
- Partial & multi-method payments
- Customer credit support
- Auto-reconciliation (FIFO or country-specific)

### Audit Trails
- Non-editable
- Timestamped
- Detailed action logs

### Country Adaptation Layer
Defines:
- Allowed flow transitions
- Fiscal constraints
- Local numbering rules
- Digital signature requirements

## 7. Payment Allocation Engine
Supports:
1. Full payment
2. Partial payment
3. Overpayments → customer credit
4. Multi-invoice settlement
5. Advance payments applied later

## Allocation Strategies
- FIFO
- Exact match
- Manual override

## 8. Document Immutability & Audit Trails

### Immutable Fields on Fiscal Documents
- Date
- Number
- Tax
- Amount
- Customer
- Lines

### Allowed After Issuance
- Attach payments
- Add credit notes

### Audit Log Format
```
timestamp, user, action, entity_type, entity_id, old_value, new_value
```

## 9. Recommended Data Model (Simplified)

### documents
- id
- type
- status
- customer_id
- totals
- fiscal_seal
- immutable_flag

### document_lines
- product_id
- qty
- price
- tax_id

### payments
- id
- amount
- customer_id
- method

### payment_allocations
- payment_id
- invoice_id
- allocated_amount

### audit_logs
- entity_type
- entity_id
- action
- data (JSON)

## 10. Document State Machine (Simplified)

```
QUOTE
  ↓ approval
SALES ORDER
  ↓ fulfillment
DELIVERY NOTE
  ↓ invoicing
INVOICE (IMMUTABLE)
  ↓ payment
PAID
```

## 11. Key Edge Cases

### Overpayment
Generates customer credit.

### Underpayment
Invoice remains partially open.

### Refunds
Handled via credit notes only.

### Mixed Payments
Supported across POS and ERP.

### Cancellation After Payment
Credit note + optional stock reversal.

## 12. Glossary

- Fiscalization: Legal sealing of invoices
- Advance Invoice: Used for deposits/prepayments
- Credit Note: Official reversal
- Customer Credit: Funds available before invoicing
- Immutable: Cannot be edited or deleted

# End of Document
