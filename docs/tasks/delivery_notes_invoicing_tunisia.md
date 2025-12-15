# Delivery Notes & Invoicing Logic  
## ERP Best Practices – Tunisia-Compliant Design

Version: 1.0  
Status: Implementation Specification  
Scope: Sales, Logistics, Invoicing, Fiscal Compliance  

---

## 1. Objective

This document defines the correct document lifecycle, data model, and business rules for handling:

- Sales Orders  
- Delivery Notes (Bon de livraison)  
- Invoices (Factures)  

With a specific focus on:
- Partial deliveries  
- Multiple delivery notes per sales order  
- Multiple delivery notes consolidated into invoices  
- Avoiding double invoicing  
- Tunisia fiscal compliance  

This specification is designed to be directly consumable by Claude Code or other AI coding agents.

---

## 2. Core Accounting & Fiscal Principles

### 2.1 Fundamental Rule

Invoices are issued based on delivered quantities, not ordered quantities.

Once a delivery note exists:
- The sales order is no longer the source of truth for invoicing  
- Delivery notes become the only invoiceable source  

---

## 3. Conceptual Role of Each Document

| Document | Role |
|--------|------|
| Sales Order | Commercial intent & reservation |
| Delivery Note | Legal proof of physical delivery |
| Invoice | Fiscal & accounting document |

---

## 4. Canonical Document Relationships

Sales Order (1)  
→ Delivery Notes (N)  
→ Invoices (N)

Rules:
- One sales order may generate many delivery notes  
- One invoice may consolidate multiple delivery notes  
- One delivery note may belong to only one invoice  

---

## 5. Sales Order → Delivery Notes

- Partial deliveries are allowed  
- Each delivery creates a new delivery note  
- Delivery notes are sequential and immutable once validated  

Example:
Sales Order:
- Product X: 10  
- Product Y: 20  

Delivery Notes:
- DN-001 → X: 5, Y: 10  
- DN-002 → X: 5, Y: 10  

---

## 6. Delivery Notes → Invoices

### 6.1 Allowed Scenarios

| Scenario | Allowed |
|-------|--------|
| 1 DN → 1 Invoice | Yes |
| Multiple DNs → 1 Invoice | Yes |
| 1 DN → Multiple Invoices | No |
| Invoice without DN | No (except services) |

### 6.2 Tunisia Constraint

All validated delivery notes must be invoiced or consolidated before fiscal year end.

---

## 7. Status Model

### Sales Order
- draft  
- confirmed  
- partially_delivered  
- fully_delivered  
- closed  

### Delivery Note
- draft  
- validated  
- invoiced  

### Invoice
- draft  
- validated  
- posted  
- partially_paid  
- paid  

---

## 8. Line-Level Tracking (Mandatory)

### Delivery Note Line

- delivery_note_id  
- product_id  
- quantity_delivered  
- quantity_invoiced  
- invoice_status  

Invoice status:
- not_invoiced  
- partially_invoiced  
- fully_invoiced  

### Invoice Line

- invoice_id  
- source_type = delivery_note_line  
- source_id  
- quantity_invoiced  
- unit_price  
- vat_rate  

Each invoice line must reference exactly one delivery note line.

---

## 9. Invoice Creation Logic

### Preferred Flow: Invoice from Delivery Notes

1. Select validated delivery notes  
2. Show only non-invoiced quantities  
3. Generate invoice  
4. Update invoiced quantities  
5. Mark delivery notes invoiced when complete  

### Controlled Flow: Invoice from Sales Order

Allowed only as a UI shortcut.
Internally, invoices must still consume delivery note lines.

---

## 10. Hard Business Rules

1. A delivery note line cannot be invoiced twice  
2. Invoiced quantity cannot exceed delivered quantity  
3. Invoice lines must reference delivery note lines  
4. Validated delivery notes are immutable  
5. Fiscal year cannot close with open delivery notes  

---

## 11. Forbidden Anti-Patterns

- Invoicing sales orders directly when deliveries exist  
- Editing validated delivery notes  
- Deleting delivery notes  
- Invoicing undelivered quantities  

---

## 12. Audit & Compliance Guarantees

This design ensures:
- Full traceability (SO → DN → Invoice)  
- Tunisia fiscal compliance  
- Safe extension to IFRS, EU VAT, NF525, ZATCA  

---

END OF DOCUMENT
