## Internationalization (i18n) Rule

ALL user-facing text in the frontend MUST use translation keys. Never hardcode text.

### Setup
The project uses react-i18next. Translations are in:
```
apps/web/src/locales/
├── en/translation.json
├── fr/translation.json
└── ar/translation.json
```

### Usage
```tsx
// ❌ WRONG - Hardcoded text
<Button>Save</Button>
<h1>Chart of Accounts</h1>
<p>No data found</p>

// ✅ CORRECT - Translation keys
import { useTranslation } from 'react-i18next';

function MyComponent() {
  const { t } = useTranslation();
  
  return (
    <>
      <Button>{t('common.save')}</Button>
      <h1>{t('finance.chartOfAccounts.title')}</h1>
      <p>{t('common.noData')}</p>
    </>
  );
}
```

### Key Naming Convention
```
common.save
common.cancel
common.delete
common.edit
common.search
common.noData
common.loading
common.error

finance.chartOfAccounts.title
finance.chartOfAccounts.addAccount
finance.ledger.title
finance.reports.trialBalance
finance.reports.profitLoss

sales.invoices.title
sales.invoices.create
sales.quotes.title

inventory.products.title
inventory.stockLevels.title

settings.company.title
settings.subscription.title
```

### When Creating New Components

1. Identify all user-facing text
2. Add keys to `en/translation.json` first
3. Use `t('key')` in component
4. Add French translation to `fr/translation.json`
5. Add Arabic translation to `ar/translation.json` (can be placeholder initially)

### Example Translation Files
```json
// en/translation.json
{
  "common": {
    "save": "Save",
    "cancel": "Cancel",
    "delete": "Delete",
    "noData": "No data found"
  },
  "finance": {
    "chartOfAccounts": {
      "title": "Chart of Accounts",
      "addAccount": "Add Account"
    }
  }
}

// fr/translation.json
{
  "common": {
    "save": "Enregistrer",
    "cancel": "Annuler",
    "delete": "Supprimer",
    "noData": "Aucune donnée trouvée"
  },
  "finance": {
    "chartOfAccounts": {
      "title": "Plan Comptable",
      "addAccount": "Ajouter un compte"
    }
  }
}
```

### Validation

Before committing frontend code, verify:
- [ ] No hardcoded user-facing text in TSX files
- [ ] All new keys added to en/translation.json
- [ ] French translations added to fr/translation.json
