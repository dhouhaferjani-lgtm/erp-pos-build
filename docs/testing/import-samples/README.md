# Import Test CSV Files

This directory contains sample CSV files for testing the import functionality.

## Two Import Screens

AutoERP has two separate import screens for different purposes:

### 1. Import Screen (`/settings/import`)
- **Purpose**: Day-to-day data import
- **Use Case**: Add new customers, products, stock at any time
- **Types**: Partners, Products, Stock Levels, GL Opening Balances
- **Workflow**: 5-step wizard

### 2. Opening Balances Screen (`/settings/opening-balances`)
- **Purpose**: One-time migration from legacy system
- **Use Case**: Initial setup when switching from another system
- **Types**: GL Balances, Inventory, AR Open Items, AP Open Items
- **Workflow**: 7-step wizard with Preview and Lock
- **Special**: Has Lock feature to make data immutable after posting

---

## Test Files

### For Import Screen (`/settings/import`)

| File | Import Type | Description |
|------|-------------|-------------|
| `partners.csv` | Partners | Customers and suppliers |
| `products.csv` | Products | Parts, services, consumables |
| `stock_levels.csv` | Stock Levels | Inventory quantities (requires products + locations first) |
| `opening_balances_gl.csv` | Opening Balances | GL account balances |

### For Opening Balances Screen (`/settings/opening-balances`)

| File | Batch Type | Description |
|------|------------|-------------|
| `opening_balances_gl.csv` | ACCOUNTING | GL account opening balances |
| `opening_inventory.csv` | INVENTORY | Initial stock with costs |
| `opening_ar.csv` | AR_OPEN_ITEMS | Unpaid customer invoices |
| `opening_ap.csv` | AP_OPEN_ITEMS | Unpaid supplier invoices |

---

## Prerequisites

Before testing stock imports, ensure you have:
1. Products created (either manually or via products.csv import)
2. Locations created in the system (e.g., MAIN, SHELF-A1)

---

## Column Reference

### partners.csv
| Column | Required | Values |
|--------|----------|--------|
| name | Yes | Company/person name |
| type | Yes | customer, supplier, both |
| email | No | Email address |
| phone | No | Phone number |
| vat_number | No | Tax ID / VAT number |
| address | No | Street address |
| city | No | City name |
| country | No | 2-letter country code (FR, TN, etc.) |

### products.csv
| Column | Required | Values |
|--------|----------|--------|
| name | Yes | Product name |
| sku | Yes | Unique product code |
| type | Yes | part, service, consumable |
| description | No | Product description |
| sale_price | No | Selling price |
| purchase_price | No | Cost price |
| barcode | No | Barcode/EAN |

### stock_levels.csv
| Column | Required | Values |
|--------|----------|--------|
| product_sku | Yes | Must match existing product SKU |
| location_code | Yes | Must match existing location code |
| quantity | Yes | Stock quantity |
| notes | No | Optional notes |

### opening_balances_gl.csv
| Column | Required | Values |
|--------|----------|--------|
| account_code | Yes | Must match existing GL account |
| debit | Yes* | Debit amount (*either debit or credit required) |
| credit | Yes* | Credit amount |
| description | No | Entry description |
| reference | No | Reference number |

### opening_inventory.csv
| Column | Required | Values |
|--------|----------|--------|
| product_code | Yes | Must match existing product SKU |
| location_code | Yes | Must match existing location code |
| quantity | Yes | Opening stock quantity |
| unit_cost | Yes | Unit cost for weighted average |

### opening_ar.csv / opening_ap.csv
| Column | Required | Values |
|--------|----------|--------|
| partner_code | Yes | Must match existing partner code |
| external_invoice_number | Yes | Legacy invoice number |
| document_date | Yes | Original invoice date (YYYY-MM-DD) |
| due_date | Yes | Payment due date |
| total | Yes | Original invoice total |
| open_amount | Yes | Remaining unpaid amount |
| document_type | Yes | invoice or credit_note |
| currency | Yes | 3-letter currency code (EUR, TND) |
| notes | No | Optional notes |
