# Phase 10.5: Internationalization (i18n)

> **Priority:** Do this BEFORE Phase 11 (Navigation restructure)
> **Languages:** English (primary), French (primary), Arabic (RTL infrastructure only — no translations yet)

---

## 10.5.1 Frontend i18n Setup

- [ ] Install `react-i18next` and `i18next-browser-languagedetector`
- [ ] Create translation structure:
  ```
  apps/web/src/locales/
  ├── en/
  │   ├── common.json      # Shared: buttons, labels, errors
  │   ├── auth.json        # Login, logout, session
  │   ├── sales.json       # Customers, quotes, invoices
  │   ├── purchases.json   # Suppliers, purchase orders
  │   ├── inventory.json   # Products, stock
  │   └── treasury.json    # Payments, instruments
  ├── fr/
  │   └── (same structure)
  └── ar/
      └── .gitkeep         # Placeholder for future Arabic translations
  ```
- [ ] Configure i18next with language detection (browser preference, then fallback to 'en')
- [ ] Create i18n configuration file at `apps/web/src/lib/i18n.ts`

---

## 10.5.2 Extract Existing Strings

- [ ] Audit ALL components for hardcoded strings
- [ ] Replace with translation keys using the `useTranslation` hook
- [ ] Key naming convention:
  ```
  common.actions.save        → "Save" / "Enregistrer"
  common.actions.cancel      → "Cancel" / "Annuler"
  common.actions.delete      → "Delete" / "Supprimer"
  common.actions.edit        → "Edit" / "Modifier"
  common.actions.create      → "Create" / "Créer"
  common.actions.search      → "Search" / "Rechercher"
  common.status.loading      → "Loading..." / "Chargement..."
  common.status.noResults    → "No results found" / "Aucun résultat trouvé"
  
  auth.login.title           → "Sign In" / "Connexion"
  auth.login.email           → "Email" / "E-mail"
  auth.login.password        → "Password" / "Mot de passe"
  auth.login.submit          → "Sign In" / "Se connecter"
  auth.login.error           → "Invalid credentials" / "Identifiants invalides"
  auth.logout                → "Sign Out" / "Déconnexion"
  
  sales.customers.title      → "Customers" / "Clients"
  sales.customers.new        → "New Customer" / "Nouveau client"
  sales.quotes.title         → "Quotes" / "Devis"
  sales.invoices.title       → "Invoices" / "Factures"
  sales.invoices.status.draft    → "Draft" / "Brouillon"
  sales.invoices.status.posted   → "Posted" / "Comptabilisée"
  sales.invoices.status.paid     → "Paid" / "Payée"
  sales.invoices.status.cancelled → "Cancelled" / "Annulée"
  
  purchases.suppliers.title  → "Suppliers" / "Fournisseurs"
  
  inventory.products.title   → "Products" / "Produits"
  inventory.stock.title      → "Stock Levels" / "Niveaux de stock"
  
  treasury.payments.title    → "Payments" / "Paiements"
  treasury.payments.new      → "Record Payment" / "Enregistrer un paiement"
  
  validation.required        → "This field is required" / "Ce champ est obligatoire"
  validation.email           → "Invalid email format" / "Format d'e-mail invalide"
  validation.minLength       → "Minimum {{count}} characters" / "Minimum {{count}} caractères"
  ```
- [ ] Include: buttons, menu items, table headers, form labels, empty states, error messages, tooltips

---

## 10.5.3 RTL-Ready CSS (MANDATORY)

**All components MUST use logical properties. This is not optional.**

### Replace ALL directional Tailwind classes:

| ❌ Don't Use | ✅ Use Instead |
|-------------|----------------|
| `ml-*` | `ms-*` |
| `mr-*` | `me-*` |
| `pl-*` | `ps-*` |
| `pr-*` | `pe-*` |
| `left-*` | `start-*` |
| `right-*` | `end-*` |
| `text-left` | `text-start` |
| `text-right` | `text-end` |
| `border-l-*` | `border-s-*` |
| `border-r-*` | `border-e-*` |
| `rounded-l-*` | `rounded-s-*` |
| `rounded-r-*` | `rounded-e-*` |
| `scroll-ml-*` | `scroll-ms-*` |
| `scroll-mr-*` | `scroll-me-*` |

### Tasks:

- [ ] Search codebase for all directional classes: `grep -r "ml-\|mr-\|pl-\|pr-\|left-\|right-\|text-left\|text-right\|border-l\|border-r\|rounded-l\|rounded-r" apps/web/src`
- [ ] Replace every occurrence with logical equivalent
- [ ] Fix icons that indicate direction (arrows, chevrons):
  ```tsx
  // For icons that should flip in RTL:
  <ChevronRight className="rtl:-scale-x-100" />
  ```
- [ ] Sidebar: ensure it renders on the right side in RTL
- [ ] Dropdowns/menus: ensure they open in correct direction
- [ ] Tables: verify alignment flips correctly

---

## 10.5.4 RTL Infrastructure

- [ ] Bind `dir` attribute to HTML root based on language:
  ```tsx
  // In App.tsx or root layout
  const { i18n } = useTranslation();
  const dir = i18n.language === 'ar' ? 'rtl' : 'ltr';
  
  useEffect(() => {
    document.documentElement.dir = dir;
    document.documentElement.lang = i18n.language;
  }, [dir, i18n.language]);
  ```

- [ ] Create Arabic locale placeholder:
  ```
  apps/web/src/locales/ar/.gitkeep
  ```

- [ ] Add Arabic to language configuration (selectable in UI):
  ```ts
  export const languages = [
    { code: 'en', name: 'English', dir: 'ltr' },
    { code: 'fr', name: 'Français', dir: 'ltr' },
    { code: 'ar', name: 'العربية', dir: 'rtl' },
  ] as const;
  ```

- [ ] Configure i18next fallback: Arabic falls back to English keys until translations are added

---

## 10.5.5 Language Switcher

- [ ] Add language selector to user dropdown menu in top bar
- [ ] Display language names in their native form:
  - English
  - Français  
  - العربية
- [ ] Persist selection to `localStorage` key: `autoerp-language`
- [ ] On app load, check: URL param → localStorage → browser preference → 'en'

---

## 10.5.6 Backend i18n

- [ ] Ensure Laravel localization is configured for `en` and `fr`
- [ ] Create/update translation files:
  ```
  apps/api/lang/
  ├── en/
  │   ├── validation.php
  │   ├── auth.php
  │   └── messages.php
  └── fr/
      ├── validation.php
      ├── auth.php
      └── messages.php
  ```
- [ ] Translate validation messages to French
- [ ] Translate API error messages to French
- [ ] Middleware: set app locale from `Accept-Language` header or user preference
  ```php
  // In middleware
  $locale = $request->getPreferredLanguage(['en', 'fr']) ?? 'en';
  app()->setLocale($locale);
  ```

---

## 10.5.7 Update CLAUDE.md

Add this section to CLAUDE.md:

```markdown
## Internationalization (i18n)

### Translation Keys
- All user-facing strings must use translation keys via `useTranslation` hook
- Never hardcode strings in components
- Key format: `namespace.section.key` (e.g., `sales.invoices.status.posted`)

### RTL Support (MANDATORY)

Always use logical CSS properties for RTL compatibility:

| Don't Use | Use Instead |
|-----------|-------------|
| `ml-*` / `mr-*` | `ms-*` / `me-*` |
| `pl-*` / `pr-*` | `ps-*` / `pe-*` |
| `left-*` / `right-*` | `start-*` / `end-*` |
| `text-left` / `text-right` | `text-start` / `text-end` |
| `border-l-*` / `border-r-*` | `border-s-*` / `border-e-*` |
| `rounded-l-*` / `rounded-r-*` | `rounded-s-*` / `rounded-e-*` |

For directional icons (arrows, chevrons), add RTL flip:
```tsx
<ChevronRight className="rtl:-scale-x-100" />
```

**Never use directional classes. This rule is mandatory for all new code.**
```

---

## Verification

### Automated Checks
```bash
# Frontend builds without errors
cd apps/web
pnpm build

# No missing translation keys (warnings in console)
pnpm dev  # Check browser console

# Tests pass
pnpm test

# Backend tests
cd apps/api
php artisan test
```

### Manual RTL Verification
1. Run the app: `pnpm dev`
2. Open language switcher
3. Select "العربية"
4. Verify:
   - [ ] Sidebar appears on RIGHT side
   - [ ] Text is right-aligned
   - [ ] Navigation icons point correct direction
   - [ ] Forms have labels on correct side
   - [ ] Tables align correctly
   - [ ] Dropdowns open in correct direction
   - [ ] No layout breaks on any page
5. Navigate through ALL pages to confirm no visual bugs

---

## Summary

| Language | Translations | RTL Support | Status |
|----------|--------------|-------------|--------|
| English | ✅ Complete | LTR | Primary |
| French | ✅ Complete | LTR | Primary |
| Arabic | ❌ Not yet | ✅ Ready | Infrastructure only |
| Italian | ❌ Not yet | LTR | Future |

---

## Notes

- French translations should use formal tone ("vous" not "tu")
- Business/accounting terms should follow standard French accounting vocabulary
- When in doubt, use Metropolitan French (European French) as the standard
