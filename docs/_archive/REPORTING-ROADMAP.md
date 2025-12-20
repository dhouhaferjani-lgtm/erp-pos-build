# AutoERP - Reporting & Analytics Roadmap

> **Purpose:** Document all reporting features - existing, missing, and recommended before forking
> **Last Updated:** December 2025

---

## Executive Summary

This document outlines the reporting capabilities needed for a business owner to effectively monitor their automotive service business. Reports are categorized by priority and business impact.

### Current State
- **Dashboard**: Basic KPIs implemented
- **Financial Reports**: 5 standard reports (UI ready, backend partial)
- **Export**: PDF documents only
- **Charts**: None implemented

### Recommended Before Fork
1. Sales Revenue Chart (daily/weekly/monthly)
2. Excel/CSV Export for all reports
3. Cash Flow Summary
4. Top Customers/Products reports

---

## Existing Reports Inventory

### 1. Dashboard (Implemented)

**Location**: `/dashboard`

| Metric | Description | Data Source |
|--------|-------------|-------------|
| Revenue | Current month vs previous | Posted invoices |
| Invoice Count | Total, pending, overdue | Documents table |
| Partner Count | Total, new this month | Partners table |
| Payments Received | Amount received, pending | Payments table |
| Recent Documents | Last 5 created | Documents table |
| Recent Payments | Last 5 created | Payments table |

**Status**: COMPLETE

---

### 2. Financial Reports (Partial)

#### 2.1 Trial Balance
**Location**: `/finance/trial-balance`
**Backend**: `GET /api/v1/trial-balance?as_of_date={date}`

| Column | Description |
|--------|-------------|
| Account Code | GL account code |
| Account Name | GL account name |
| Debit Balance | Sum of debits |
| Credit Balance | Sum of credits |
| **Totals** | Must balance (Debit = Credit) |

**Status**: UI ready, backend query needed

---

#### 2.2 Profit & Loss Statement
**Location**: `/finance/profit-loss`
**Backend**: `GET /api/v1/profit-loss?from={date}&to={date}`

| Section | Accounts |
|---------|----------|
| Revenue | Account type = Revenue (7xxx) |
| Expenses | Account type = Expense (6xxx) |
| **Net Income** | Revenue - Expenses |

**Filters**: Date range (from_date, to_date)

**Status**: UI ready, backend query needed

---

#### 2.3 Balance Sheet
**Location**: `/finance/balance-sheet`
**Backend**: `GET /api/v1/balance-sheet?as_of_date={date}`

| Section | Accounts |
|---------|----------|
| Assets | Account type = Asset (1xxx-5xxx) |
| Liabilities | Account type = Liability (4xxx) |
| Equity | Account type = Equity (1xxx) |

**Validation**: Assets = Liabilities + Equity

**Status**: UI ready, backend query needed

---

#### 2.4 Aged Receivables
**Location**: `/finance/aged-receivables`
**Backend**: `GET /api/v1/aged-receivables?as_of_date={date}`

| Bucket | Days |
|--------|------|
| Current | 0 days |
| 1-30 Days | 1-30 |
| 31-60 Days | 31-60 |
| 61-90 Days | 61-90 |
| Over 90 Days | 90+ |

**Grouping**: By customer (partner_id)

**Status**: UI ready, backend query implemented

---

#### 2.5 Aged Payables
**Location**: `/finance/aged-payables`
**Backend**: `GET /api/v1/aged-payables?as_of_date={date}`

Same structure as Aged Receivables, grouped by supplier.

**Status**: UI ready, backend query implemented

---

### 3. Partner Reports (Implemented)

#### 3.1 Partner Balance
**Backend**: `GET /api/v1/companies/{cid}/partners/{pid}/balance`

Returns:
- Receivable balance (customers)
- Payable balance (suppliers)
- Credit balance (customer credit on account)

**Status**: COMPLETE

---

#### 3.2 Partner Statement
**Backend**: `GET /api/v1/companies/{cid}/partners/{pid}/statement?from={date}&to={date}`

Returns:
- Transaction list (invoices, payments, credit notes)
- Running balance per transaction
- Opening/closing balance

**Status**: COMPLETE

---

#### 3.3 Subledger Reports
**Backend**:
- `GET /api/v1/companies/{cid}/subledger/receivables`
- `GET /api/v1/companies/{cid}/subledger/payables`

Returns all partner balances with totals.

**Status**: COMPLETE

---

### 4. Compliance Reports (Implemented)

#### 4.1 Audit Log
**Backend**: `GET /api/v1/audit/events?from={date}&to={date}&event_type={type}`

Filters:
- Date range
- Event type (InvoicePosted, PaymentRecorded, etc.)
- Aggregate type and ID

**Status**: COMPLETE

---

#### 4.2 Uninvoiced Delivery Notes
**Backend**: `UninvoicedDeliveryNoteService`

For year-end compliance (Tunisia/France):
- List of DNs without matching invoices
- Totals with tax breakdown
- Partner grouping
- GL adjustment entry generation

**Status**: COMPLETE (service only, no UI)

---

### 5. Document Reports (Partial)

#### 5.1 PDF Export
**Backend**: `GET /api/v1/documents/{id}/pdf`

Supported:
- Quotes
- Sales Orders
- Purchase Orders
- Invoices
- Credit Notes
- Delivery Notes

Multi-country templates (France, Tunisia, UK, Italy)

**Status**: COMPLETE

---

### 6. Inventory Reports (Partial)

#### 6.1 Stock Levels
**Location**: `/inventory/stock`
**Backend**: `GET /api/v1/stock-levels`

Shows current stock by location with:
- Available quantity
- Reserved quantity
- Reorder point status

**Status**: COMPLETE

---

#### 6.2 Low Stock Alert
**Location**: `/reports` (dashboard widget)

Products below minimum quantity threshold.

**Status**: PARTIAL (widget only)

---

#### 6.3 Inventory Counting Reports
**Location**: `/inventory-counting/discrepancy`

Variance analysis between counted and system quantities.

**Status**: COMPLETE

---

## Missing Reports (Prioritized)

### Priority 1: CRITICAL (Implement Before Fork)

#### 1.1 Sales Revenue Chart
**Why Critical**: Business owners need to visualize revenue trends at a glance.

**Specification**:
```
Endpoint: GET /api/v1/reports/sales-chart
Query Params:
  - period: 'daily' | 'weekly' | 'monthly'
  - from_date: ISO date
  - to_date: ISO date

Response:
{
  "data": [
    { "period": "2025-12-01", "revenue": 15000.00, "invoice_count": 12 },
    { "period": "2025-12-02", "revenue": 18500.00, "invoice_count": 15 },
    ...
  ],
  "summary": {
    "total_revenue": 125000.00,
    "total_invoices": 89,
    "average_per_period": 4166.67
  }
}
```

**Frontend**:
- Use Recharts library (already in ecosystem)
- Bar chart or line chart
- Toggle: Daily / Weekly / Monthly
- Date range picker

**Effort**: Medium (Backend: 4h, Frontend: 8h)

---

#### 1.2 Excel/CSV Export
**Why Critical**: Business owners need to export data for accountants or further analysis.

**Specification**:
Add export endpoints for all reports:
```
GET /api/v1/reports/trial-balance/export?format=xlsx
GET /api/v1/reports/profit-loss/export?format=csv
GET /api/v1/reports/aged-receivables/export?format=xlsx
```

**Implementation**:
- Use `maatwebsite/excel` package (Laravel)
- Support XLSX and CSV formats
- Include headers and proper formatting

**Frontend**:
- Add "Export" button to all report pages
- Format selector (Excel/CSV)

**Effort**: Medium (Backend: 6h, Frontend: 2h)

---

#### 1.3 Cash Flow Summary
**Why Critical**: Understanding cash position is essential for business operations.

**Specification**:
```
Endpoint: GET /api/v1/reports/cash-flow
Query Params:
  - from_date: ISO date
  - to_date: ISO date

Response:
{
  "data": {
    "opening_balance": 50000.00,
    "inflows": {
      "customer_payments": 125000.00,
      "other_income": 5000.00,
      "total": 130000.00
    },
    "outflows": {
      "supplier_payments": 85000.00,
      "expenses": 15000.00,
      "total": 100000.00
    },
    "closing_balance": 80000.00,
    "net_change": 30000.00
  },
  "breakdown_by_method": [
    { "method": "Cash", "inflow": 45000.00, "outflow": 30000.00 },
    { "method": "Bank Transfer", "inflow": 80000.00, "outflow": 65000.00 },
    { "method": "Check", "inflow": 5000.00, "outflow": 5000.00 }
  ]
}
```

**Effort**: High (Backend: 8h, Frontend: 6h)

---

### Priority 2: HIGH (Recommended Before Fork)

#### 2.1 Top Customers Report
**Why Important**: Identify most valuable customers for retention efforts.

**Specification**:
```
Endpoint: GET /api/v1/reports/top-customers
Query Params:
  - limit: number (default 10)
  - from_date: ISO date
  - to_date: ISO date
  - sort_by: 'revenue' | 'invoice_count' | 'payment_count'

Response:
{
  "data": [
    {
      "partner_id": "uuid",
      "name": "ACME Corp",
      "total_revenue": 45000.00,
      "invoice_count": 12,
      "average_invoice": 3750.00,
      "last_invoice_date": "2025-12-10"
    },
    ...
  ]
}
```

**Effort**: Low (Backend: 3h, Frontend: 4h)

---

#### 2.2 Top Products Report
**Why Important**: Identify best-selling products for inventory planning.

**Specification**:
```
Endpoint: GET /api/v1/reports/top-products
Query Params:
  - limit: number (default 10)
  - from_date: ISO date
  - to_date: ISO date
  - sort_by: 'quantity' | 'revenue' | 'margin'

Response:
{
  "data": [
    {
      "product_id": "uuid",
      "sku": "OIL-5W30",
      "name": "Motor Oil 5W30",
      "quantity_sold": 150,
      "revenue": 7500.00,
      "profit_margin": 35.5
    },
    ...
  ]
}
```

**Effort**: Low (Backend: 3h, Frontend: 4h)

---

#### 2.3 Payment Collection Report
**Why Important**: Track payment performance and collection efficiency.

**Specification**:
```
Endpoint: GET /api/v1/reports/payment-collection
Query Params:
  - from_date: ISO date
  - to_date: ISO date

Response:
{
  "data": {
    "total_invoiced": 150000.00,
    "total_collected": 125000.00,
    "collection_rate": 83.33,
    "average_days_to_collect": 18,
    "overdue_amount": 25000.00,
    "breakdown_by_age": {
      "current": 10000.00,
      "1_30_days": 8000.00,
      "31_60_days": 4000.00,
      "61_90_days": 2000.00,
      "over_90_days": 1000.00
    }
  }
}
```

**Effort**: Medium (Backend: 5h, Frontend: 4h)

---

#### 2.4 Inventory Valuation Report
**Why Important**: Know the total value of inventory on hand.

**Specification**:
```
Endpoint: GET /api/v1/reports/inventory-valuation
Query Params:
  - as_of_date: ISO date
  - location_id: uuid (optional)

Response:
{
  "data": {
    "total_value": 85000.00,
    "total_items": 450,
    "total_sku": 125,
    "by_category": [
      { "category": "Parts", "value": 50000.00, "items": 300 },
      { "category": "Fluids", "value": 20000.00, "items": 100 },
      { "category": "Accessories", "value": 15000.00, "items": 50 }
    ],
    "by_location": [
      { "location": "Main Warehouse", "value": 70000.00 },
      { "location": "Branch 1", "value": 15000.00 }
    ]
  }
}
```

**Effort**: Medium (Backend: 5h, Frontend: 4h)

---

### Priority 3: MEDIUM (Nice to Have)

#### 3.1 Tax Report
**Purpose**: Summarize VAT/tax collected and paid for filing.

**Specification**:
```
Endpoint: GET /api/v1/reports/tax-summary
Query Params:
  - from_date: ISO date
  - to_date: ISO date

Response:
{
  "data": {
    "vat_collected": 25000.00,
    "vat_paid": 15000.00,
    "net_vat_due": 10000.00,
    "by_rate": [
      { "rate": 19, "collected": 20000.00, "paid": 12000.00 },
      { "rate": 7, "collected": 5000.00, "paid": 3000.00 }
    ]
  }
}
```

**Effort**: Medium (Backend: 5h, Frontend: 3h)

---

#### 3.2 Sales by Service Type
**Purpose**: Understand revenue distribution across service categories.

**Specification**:
```
Endpoint: GET /api/v1/reports/sales-by-service
Query Params:
  - from_date: ISO date
  - to_date: ISO date

Response:
{
  "data": [
    { "category": "Oil Change", "revenue": 25000.00, "job_count": 200 },
    { "category": "Brake Service", "revenue": 35000.00, "job_count": 50 },
    { "category": "Tire Service", "revenue": 15000.00, "job_count": 75 },
    ...
  ]
}
```

**Effort**: Medium (Backend: 4h, Frontend: 4h)

---

#### 3.3 Document Status Summary
**Purpose**: Overview of document pipeline (quotes pending, orders in progress).

**Specification**:
```
Endpoint: GET /api/v1/reports/document-pipeline

Response:
{
  "data": {
    "quotes": {
      "draft": 5,
      "sent": 12,
      "accepted": 8,
      "expired": 3,
      "total_value_pending": 45000.00
    },
    "orders": {
      "confirmed": 10,
      "in_progress": 5,
      "total_value": 75000.00
    },
    "invoices": {
      "draft": 3,
      "posted": 45,
      "overdue": 8,
      "total_receivable": 125000.00
    }
  }
}
```

**Effort**: Low (Backend: 3h, Frontend: 3h)

---

#### 3.4 Customer Activity Report
**Purpose**: Track customer engagement and identify inactive customers.

**Specification**:
```
Endpoint: GET /api/v1/reports/customer-activity
Query Params:
  - inactive_days: number (default 90)

Response:
{
  "data": {
    "active_customers": 85,
    "inactive_customers": 25,
    "new_customers_this_month": 12,
    "at_risk": [
      { "partner_id": "uuid", "name": "Customer A", "last_activity": "2025-09-15", "total_spent": 5000.00 },
      ...
    ]
  }
}
```

**Effort**: Low (Backend: 3h, Frontend: 3h)

---

### Priority 4: LOW (Future Enhancement)

#### 4.1 Profit Margin Analysis
Per-product/service profitability with cost breakdown.

#### 4.2 Technician Performance (Workshop Module)
Labor hours, jobs completed, efficiency metrics.

#### 4.3 Quote Conversion Rate
Track quotes to orders to invoices pipeline effectiveness.

#### 4.4 Scheduled Reports
Email reports on schedule (daily/weekly/monthly).

#### 4.5 Custom Report Builder
User-defined report with drag-and-drop fields.

#### 4.6 Comparative Reports
This period vs previous period analysis.

#### 4.7 Forecast/Projection
Based on historical data, project future revenue.

---

## Implementation Recommendations

### Charting Library
**Recommendation**: Recharts

- Already compatible with React
- Lightweight and performant
- Good TypeScript support
- Responsive out of the box

```bash
cd apps/web && pnpm add recharts
```

### Export Library (Backend)
**Recommendation**: maatwebsite/excel

```bash
cd apps/api && composer require maatwebsite/excel
```

### Database Considerations

For large datasets, consider:

1. **Materialized Views** for frequently accessed aggregations
2. **Indexes** on date columns used in reports
3. **Caching** for reports that don't need real-time data

Example index additions:
```sql
CREATE INDEX idx_documents_company_date ON documents(company_id, document_date);
CREATE INDEX idx_payments_company_date ON payments(company_id, payment_date);
CREATE INDEX idx_journal_lines_account_date ON journal_lines(account_id, created_at);
```

---

## Summary: Pre-Fork Checklist

### Must Have (Critical)
- [ ] Sales Revenue Chart (daily/weekly/monthly toggle)
- [ ] Excel/CSV Export for financial reports
- [ ] Cash Flow Summary

### Should Have (High Priority)
- [ ] Top Customers Report
- [ ] Top Products Report
- [ ] Payment Collection Report
- [ ] Inventory Valuation Report

### Could Have (Medium Priority)
- [ ] Tax Report
- [ ] Sales by Service Type
- [ ] Document Status Summary
- [ ] Customer Activity Report

### Won't Have (Post-Fork)
- [ ] Custom Report Builder
- [ ] Scheduled Reports
- [ ] Forecast/Projection
- [ ] Technician Performance

---

## Estimated Total Effort

| Priority | Reports | Backend Hours | Frontend Hours | Total |
|----------|---------|---------------|----------------|-------|
| Critical | 3 | 18h | 16h | 34h |
| High | 4 | 16h | 16h | 32h |
| Medium | 4 | 15h | 13h | 28h |
| **Total** | **11** | **49h** | **45h** | **94h** |

**Recommendation**: Implement Critical and High priority reports before fork (66 hours total, approximately 2 weeks of focused work).

---

## Translation Keys Needed

Add to `apps/web/src/locales/en/reports.json`:

```json
{
  "reports": {
    "salesChart": {
      "title": "Sales Revenue",
      "daily": "Daily",
      "weekly": "Weekly",
      "monthly": "Monthly",
      "revenue": "Revenue",
      "invoiceCount": "Invoice Count"
    },
    "cashFlow": {
      "title": "Cash Flow Summary",
      "openingBalance": "Opening Balance",
      "closingBalance": "Closing Balance",
      "inflows": "Inflows",
      "outflows": "Outflows",
      "netChange": "Net Change"
    },
    "topCustomers": {
      "title": "Top Customers",
      "totalRevenue": "Total Revenue",
      "invoiceCount": "Invoice Count",
      "averageInvoice": "Average Invoice"
    },
    "topProducts": {
      "title": "Top Products",
      "quantitySold": "Quantity Sold",
      "revenue": "Revenue",
      "margin": "Margin"
    },
    "export": {
      "button": "Export",
      "excel": "Export to Excel",
      "csv": "Export to CSV",
      "pdf": "Export to PDF"
    }
  }
}
```

---

*Document Version: 1.0*
*Created: December 2025*
