# Navigation & Routing Conventions

> **Purpose:** How to add pages and manage navigation in the dashboard
> **Last Updated:** 2025-12-30

## File Structure

```
apps/web/src/
├── routes/index.tsx                  # Main routing configuration
├── components/organisms/Sidebar/     # Navigation sidebar
├── features/[feature]/pages/         # Page components
└── locales/[lang]/common.json        # Navigation translations
```

## Adding a New Page (Step-by-Step)

### Step 1: Create Page Component

**File:** `apps/web/src/features/[module]/pages/MyPage.tsx`

```typescript
import { useTranslation } from 'react-i18next'
import { Plus } from 'lucide-react'

export function MyPage() {
  const { t } = useTranslation(['myModule', 'common'])

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">{t('myModule:title')}</h1>
        <button className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2">
          <Plus className="h-4 w-4" />
          {t('common:actions.add')}
        </button>
      </div>
    </div>
  )
}
```

### Step 2: Add Translation Keys

**Files:** `apps/web/src/locales/en/common.json` and `fr/common.json`

```json
{
  "navigation": {
    "myPage": "My Page Title"
  }
}
```

### Step 3: Add Route

**File:** `apps/web/src/routes/index.tsx`

```typescript
// Add lazy import at top
const MyPage = lazy(() =>
  import('../features/myModule/pages/MyPage').then((m) => ({
    default: m.MyPage
  }))
)

// Add route in appropriate section
<Route path="my-module">
  <Route path="my-page" element={
    <RequirePermission moduleKey="myModule">
      <SuspenseWrapper>
        <MyPage />
      </SuspenseWrapper>
    </RequirePermission>
  } />
</Route>
```

### Step 4: Add to Sidebar Navigation

**File:** `apps/web/src/components/organisms/Sidebar/Sidebar.tsx`

```typescript
const navigation: NavModule[] = [
  {
    key: 'myModule',
    icon: MyIcon,
    children: [
      { key: 'myPage', href: '/my-module/my-page', icon: FileIcon },
    ],
  },
]
```

## Navigation Structure

```typescript
interface NavModule {
  key: string                    // Translation key
  icon: React.ComponentType
  href?: string                  // If no children
  children?: NavChild[]
  module?: string                // For permission checking
}

interface NavChild {
  key: string                    // Translation key suffix
  href: string                   // URL path
  icon: React.ComponentType
  module?: string                // Override permission module
}
```

## Permission-Based Filtering

Navigation automatically filters based on user permissions:

```typescript
const filteredNavigation = useMemo(() => {
  return navigation
    .filter((module) => canAccessModule(module.module ?? module.key))
    .map((module) => {
      if (!module.children) return module
      const filteredChildren = module.children.filter((child) =>
        canAccessModule(child.module ?? module.module ?? module.key)
      )
      return { ...module, children: filteredChildren }
    })
}, [canAccessModule])
```

## Route Nesting Pattern

```typescript
<Route path="sales">
  <Route index element={<Navigate to="/sales/customers" replace />} />
  <Route path="customers" element={...} />
  <Route path="customers/new" element={...} />
  <Route path="customers/:id" element={...} />
  <Route path="customers/:id/edit" element={...} />
</Route>
```

## Common Mistakes to Avoid

1. ❌ Hardcoding text instead of using `t()` translation keys
2. ❌ Missing `RequirePermission` wrapper on routes
3. ❌ Forgetting to add page to sidebar navigation
4. ❌ Not adding translations to both `en/` and `fr/` files
5. ❌ Missing `SuspenseWrapper` around lazy-loaded components
6. ❌ Inconsistent route paths (use `/module/resource` pattern)

## Checklist

When adding a new page:
- [ ] Create page component in `features/[module]/pages/`
- [ ] Add translation keys to `en/` and `fr/` common.json
- [ ] Add lazy import in `routes/index.tsx`
- [ ] Add route with `RequirePermission` and `SuspenseWrapper`
- [ ] Add navigation item to Sidebar.tsx
- [ ] Test that page only shows for users with permission
