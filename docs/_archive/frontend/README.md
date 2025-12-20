# Frontend Documentation

> React patterns, components, and styling for AutoERP web application.

---

## Documentation

| Document | Description |
|----------|-------------|
| [Architecture](./architecture.md) | Project structure, state management, API patterns |
| [Design System](./design-system.md) | Colors, typography, components |

---

## Quick Reference

### Tech Stack

| Technology | Purpose |
|------------|---------|
| React 18+ | UI library |
| TypeScript (strict) | Type safety |
| TanStack Query | Server state |
| Zustand | Client state |
| Tailwind CSS 4+ | Styling |

### Project Structure

```
apps/web/src/
├── components/
│   ├── atoms/         # Basic elements (Button, Input)
│   ├── molecules/     # Combinations (FormField)
│   ├── organisms/     # Complex (Sidebar, Modal)
│   └── ui/            # shadcn/ui components
├── features/          # Feature-specific pages/components
├── hooks/             # Custom React hooks
├── lib/               # Utilities, API client
├── locales/           # i18n translations
├── stores/            # Zustand stores
└── routes/            # Route definitions
```

### Type Flow Rule

**Types flow from Backend → Frontend. Never reverse.**

```bash
# Generate types from PHP DTOs
cd apps/api
php artisan typescript:transform
```

Import from generated types:
```typescript
import { PartnerData, DocumentData } from '@autoerp/shared';
```

### i18n Rule

**All user-facing text must use translation keys.**

```tsx
// WRONG
<button>Save</button>

// RIGHT
const { t } = useTranslation();
<button>{t('common.save')}</button>
```
