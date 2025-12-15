# AutoERP Features

> Cross-module features and business workflows.

---

## Feature Index

| Feature | Status | Description |
|---------|--------|-------------|
| [Smart Payment](./smart-payment.md) | Complete | Intelligent payment allocation |
| [Landed Cost & Margin](./landed-cost.md) | Complete | Cost tracking and margin calculation |
| [Credit Notes](./credit-notes.md) | Complete | Invoice corrections and refunds |
| [Inventory Counting](./inventory-counting.md) | In Progress | Double-blind physical inventory counts |

---

## Feature vs Module

**Modules** are technical boundaries around domain concepts (Document, Treasury, Inventory).

**Features** are business capabilities that span multiple modules:

```
Smart Payment Feature
├── Treasury Module (payment recording)
├── Document Module (invoice allocation)
├── Accounting Module (GL entries)
└── Partner Module (balance tracking)
```
