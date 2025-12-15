## ATOMIC DESIGN REFACTOR

Refactor the frontend codebase to follow Atomic Design methodology. This establishes consistent UI patterns for all future development.

### ATOMIC DESIGN PRINCIPLES

Five levels of components:

1. **Atoms** - Smallest building blocks, no dependencies on other components
   - Button, Input, Label, Icon, Badge, Spinner, Avatar, Checkbox, Radio, Select, Textarea, Switch, Tooltip

2. **Molecules** - Simple combinations of atoms
   - FormField (Label + Input + Error), SearchBox, MenuItem, Card, Alert, Modal, Dropdown, Tabs, Breadcrumb

3. **Organisms** - Complex UI sections made of molecules and atoms
   - Header, Sidebar, TopBar, DataTable, Form, CompanySelector, LocationSelector, NavigationMenu, PageHeader

4. **Templates** - Page layouts without real data
   - DashboardLayout, AuthLayout, SettingsLayout, FullWidthLayout

5. **Pages** - Actual pages with real data and business logic
   - DashboardPage, LoginPage, StockLevelsPage, InvoicesPage, etc.

### CURRENT STATE ANALYSIS

First, analyze the current component structure:
```bash
# List all current components
find apps/web/src/components -type f -name "*.tsx" | head -50
find apps/web/src/features -type f -name "*.tsx" | head -50

# Check current directory structure
ls -la apps/web/src/components/
ls -la apps/web/src/features/
```

Document findings in: docs/tasks/ATOMIC-DESIGN-AUDIT.md

### TARGET DIRECTORY STRUCTURE
apps/web/src/
├── components/
│   ├── atoms/
│   │   ├── Button/
│   │   │   ├── Button.tsx
│   │   │   ├── Button.test.tsx
│   │   │   └── index.ts
│   │   ├── Input/
│   │   │   ├── Input.tsx
│   │   │   ├── Input.test.tsx
│   │   │   └── index.ts
│   │   ├── Label/
│   │   ├── Icon/
│   │   ├── Badge/
│   │   ├── Spinner/
│   │   ├── Avatar/
│   │   ├── Checkbox/
│   │   ├── Radio/
│   │   ├── Select/
│   │   ├── Textarea/
│   │   ├── Switch/
│   │   ├── Tooltip/
│   │   ├── Skeleton/
│   │   └── index.ts          # Barrel export
│   │
│   ├── molecules/
│   │   ├── FormField/
│   │   │   ├── FormField.tsx
│   │   │   ├── FormField.test.tsx
│   │   │   └── index.ts
│   │   ├── SearchBox/
│   │   ├── MenuItem/
│   │   ├── Card/
│   │   ├── Alert/
│   │   ├── Modal/
│   │   ├── Dropdown/
│   │   ├── Tabs/
│   │   ├── Breadcrumb/
│   │   ├── EmptyState/
│   │   ├── ConfirmDialog/
│   │   ├── Toast/
│   │   └── index.ts
│   │
│   ├── organisms/
│   │   ├── Header/
│   │   ├── Sidebar/
│   │   ├── TopBar/
│   │   ├── DataTable/
│   │   ├── Form/
│   │   ├── CompanySelector/
│   │   ├── LocationSelector/
│   │   ├── NavigationMenu/
│   │   ├── PageHeader/
│   │   ├── StatsCard/
│   │   └── index.ts
│   │
│   └── templates/
│       ├── DashboardLayout/
│       ├── AuthLayout/
│       ├── SettingsLayout/
│       └── index.ts
│
├── features/
│   ├── auth/
│   │   ├── pages/
│   │   │   ├── LoginPage.tsx
│   │   │   └── RegisterPage.tsx
│   │   ├── components/       # Feature-specific organisms
│   │   │   └── LoginForm.tsx
│   │   ├── hooks/
│   │   ├── api.ts
│   │   ├── types.ts
│   │   └── index.ts
│   │
│   ├── inventory/
│   │   ├── pages/
│   │   │   ├── StockLevelsPage.tsx
│   │   │   └── StockMovementsPage.tsx
│   │   ├── components/
│   │   │   ├── StockTable.tsx
│   │   │   └── StockAdjustmentModal.tsx
│   │   ├── hooks/
│   │   ├── api.ts
│   │   ├── types.ts
│   │   └── index.ts
│   │
│   ├── documents/
│   ├── partners/
│   ├── products/
│   ├── settings/
│   └── company/
│
├── hooks/                    # Shared hooks
│   ├── useDebounce.ts
│   ├── usePagination.ts
│   └── index.ts
│
├── contexts/                 # Global contexts
│   ├── AuthContext.tsx
│   ├── CompanyContext.tsx
│   └── ThemeContext.tsx
│
├── lib/                      # Utilities
│   ├── api.ts
│   ├── utils.ts
│   └── cn.ts                 # className utility
│
├── styles/
│   └── globals.css
│
└── types/                    # Shared types
└── index.ts
### REFACTORING RULES

1. **No breaking changes** - All existing functionality must continue to work
2. **Update imports progressively** - After moving a component, update all imports
3. **Test after each move** - Run `pnpm build` after moving each component group
4. **Preserve shadcn/ui components** - If using shadcn/ui, keep them in components/ui/ or integrate into atoms

### STEP-BY-STEP EXECUTION

#### Phase 1: Setup Structure (30 min)

Create the directory structure:
```bash
mkdir -p apps/web/src/components/{atoms,molecules,organisms,templates}
touch apps/web/src/components/atoms/index.ts
touch apps/web/src/components/molecules/index.ts
touch apps/web/src/components/organisms/index.ts
touch apps/web/src/components/templates/index.ts
```

#### Phase 2: Identify and Categorize (30 min)

List all existing components and categorize them:
```markdown
# Component Categorization

## Atoms (move to components/atoms/)
- [ ] Button
- [ ] Input
- [ ] ...

## Molecules (move to components/molecules/)
- [ ] FormField
- [ ] ...

## Organisms (move to components/organisms/)
- [ ] TopBar
- [ ] CompanySelector
- [ ] LocationSelector
- [ ] ...

## Templates (move to components/templates/)
- [ ] DashboardLayout
- [ ] ...

## Feature-Specific (keep in features/)
- [ ] LoginForm → features/auth/components/
- [ ] StockTable → features/inventory/components/
- [ ] ...
```

#### Phase 3: Refactor Atoms (1-2 hours)

For each atom:

1. Create folder: `components/atoms/Button/`
2. Move/create component: `Button.tsx`
3. Create barrel export: `index.ts`
4. Update imports across codebase
5. Run build to verify

Example Button atom:
```tsx
// components/atoms/Button/Button.tsx
import { forwardRef } from 'react';
import { cva, type VariantProps } from 'class-variance-authority';
import { cn } from '@/lib/utils';

const buttonVariants = cva(
  'inline-flex items-center justify-center rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 disabled:pointer-events-none disabled:opacity-50',
  {
    variants: {
      variant: {
        default: 'bg-primary text-primary-foreground hover:bg-primary/90',
        destructive: 'bg-destructive text-destructive-foreground hover:bg-destructive/90',
        outline: 'border border-input bg-background hover:bg-accent',
        secondary: 'bg-secondary text-secondary-foreground hover:bg-secondary/80',
        ghost: 'hover:bg-accent hover:text-accent-foreground',
        link: 'text-primary underline-offset-4 hover:underline',
      },
      size: {
        default: 'h-10 px-4 py-2',
        sm: 'h-9 rounded-md px-3',
        lg: 'h-11 rounded-md px-8',
        icon: 'h-10 w-10',
      },
    },
    defaultVariants: {
      variant: 'default',
      size: 'default',
    },
  }
);

export interface ButtonProps
  extends React.ButtonHTMLAttributes<HTMLButtonElement>,
    VariantProps<typeof buttonVariants> {
  loading?: boolean;
}

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  ({ className, variant, size, loading, children, disabled, ...props }, ref) => {
    return (
      <button
        className={cn(buttonVariants({ variant, size, className }))}
        ref={ref}
        disabled={disabled || loading}
        {...props}
      >
        {loading ? <Spinner className="mr-2 h-4 w-4" /> : null}
        {children}
      </button>
    );
  }
);

Button.displayName = 'Button';
```
```ts
// components/atoms/Button/index.ts
export { Button, type ButtonProps } from './Button';
```
```ts
// components/atoms/index.ts
export * from './Button';
export * from './Input';
export * from './Label';
// ... etc
```

#### Phase 4: Refactor Molecules (1-2 hours)

Molecules combine atoms. Example FormField:
```tsx
// components/molecules/FormField/FormField.tsx
import { Label } from '@/components/atoms/Label';
import { Input } from '@/components/atoms/Input';
import { cn } from '@/lib/utils';

interface FormFieldProps {
  label: string;
  name: string;
  error?: string;
  required?: boolean;
  children?: React.ReactNode; // Allow custom input
  className?: string;
}

export function FormField({ 
  label, 
  name, 
  error, 
  required, 
  children,
  className,
  ...inputProps 
}: FormFieldProps & React.InputHTMLAttributes<HTMLInputElement>) {
  return (
    <div className={cn('space-y-2', className)}>
      <Label htmlFor={name}>
        {label}
        {required && <span className="text-destructive ml-1">*</span>}
      </Label>
      {children || <Input id={name} name={name} {...inputProps} />}
      {error && <p className="text-sm text-destructive">{error}</p>}
    </div>
  );
}
```

#### Phase 5: Refactor Organisms (1-2 hours)

Move complex components. Ensure they import from atoms/molecules:
```tsx
// components/organisms/TopBar/TopBar.tsx
import { Button } from '@/components/atoms/Button';
import { Avatar } from '@/components/atoms/Avatar';
import { Dropdown } from '@/components/molecules/Dropdown';
import { CompanySelector } from '@/components/organisms/CompanySelector';
import { LocationSelector } from '@/components/organisms/LocationSelector';

export function TopBar() {
  return (
    <header className="border-b h-16 flex items-center px-4 gap-4">
      <CompanySelector />
      <LocationSelector />
      <div className="flex-1" />
      <NotificationBell />
      <UserMenu />
    </header>
  );
}
```

#### Phase 6: Refactor Templates (30 min)

Move layout components:
```tsx
// components/templates/DashboardLayout/DashboardLayout.tsx
import { Sidebar } from '@/components/organisms/Sidebar';
import { TopBar } from '@/components/organisms/TopBar';

interface DashboardLayoutProps {
  children: React.ReactNode;
}

export function DashboardLayout({ children }: DashboardLayoutProps) {
  return (
    <div className="flex h-screen">
      <Sidebar />
      <div className="flex flex-col flex-1">
        <TopBar />
        <main className="flex-1 overflow-auto p-6">
          {children}
        </main>
      </div>
    </div>
  );
}
```

#### Phase 7: Organize Features (1 hour)

Each feature folder should have:
features/inventory/
├── pages/           # Page components (connected to routes)
├── components/      # Feature-specific organisms
├── hooks/           # Feature-specific hooks
├── api.ts           # API calls
├── types.ts         # TypeScript types
└── index.ts         # Public exports

Move feature-specific components out of generic components/ into their feature folder.

#### Phase 8: Update All Imports (1-2 hours)

After moving files, update imports everywhere:
```bash
# Find all imports of moved components
grep -r "from.*components/Button" apps/web/src/
grep -r "from.*components/TopBar" apps/web/src/
# etc.
```

Update to new paths:
```tsx
// Before
import { Button } from '@/components/Button';

// After
import { Button } from '@/components/atoms/Button';
// OR use barrel export
import { Button } from '@/components/atoms';
```

#### Phase 9: Verify and Clean Up (30 min)
```bash
# Build to catch any broken imports
cd apps/web && pnpm build

# Run tests
cd apps/web && pnpm test

# Check for unused files
# Remove any empty directories or orphaned files
```

### COMPONENT INVENTORY CHECKLIST

Mark each component as you refactor it:

#### Atoms
- [ ] Button
- [ ] Input
- [ ] Label
- [ ] Select
- [ ] Checkbox
- [ ] Radio
- [ ] Switch
- [ ] Textarea
- [ ] Badge
- [ ] Avatar
- [ ] Spinner/Loader
- [ ] Icon
- [ ] Tooltip
- [ ] Skeleton

#### Molecules
- [ ] FormField
- [ ] SearchBox
- [ ] Card
- [ ] Alert
- [ ] Modal/Dialog
- [ ] Dropdown/DropdownMenu
- [ ] Tabs
- [ ] Breadcrumb
- [ ] EmptyState
- [ ] ConfirmDialog
- [ ] Toast/Notification
- [ ] Pagination

#### Organisms
- [ ] TopBar
- [ ] Sidebar
- [ ] CompanySelector
- [ ] LocationSelector
- [ ] DataTable
- [ ] PageHeader
- [ ] NavigationMenu
- [ ] UserMenu
- [ ] NotificationBell

#### Templates
- [ ] DashboardLayout
- [ ] AuthLayout
- [ ] SettingsLayout

### HANDLING SHADCN/UI

If the project uses shadcn/ui components (in components/ui/):

Option A: Keep as-is and import from atoms
```tsx
// components/atoms/Button/Button.tsx
export { Button } from '@/components/ui/button';
```

Option B: Move shadcn components into atoms structure
```bash
mv apps/web/src/components/ui/button.tsx apps/web/src/components/atoms/Button/Button.tsx
```

Choose Option A for faster refactor - just re-export shadcn components from atomic structure.

### DEFINITION OF DONE

1. All components organized into atoms/molecules/organisms/templates
2. All feature-specific components in features/*/components/
3. Barrel exports (index.ts) in each directory
4. All imports updated to new paths
5. `pnpm build` passes with no errors
6. `pnpm test` passes (if tests exist)
7. Application runs and all pages render correctly
8. Document final structure in docs/ATOMIC-DESIGN.md

### PROGRESS TRACKING

Create: docs/tasks/ATOMIC-DESIGN-PROGRESS.md
```markdown
# Atomic Design Refactor Progress

Started: [TIMESTAMP]

## Phase 1: Setup - [ ]
## Phase 2: Categorize - [ ]
## Phase 3: Atoms - [ ]
## Phase 4: Molecules - [ ]
## Phase 5: Organisms - [ ]
## Phase 6: Templates - [ ]
## Phase 7: Features - [ ]
## Phase 8: Imports - [ ]
## Phase 9: Verify - [ ]

Completed: [TIMESTAMP]
```

### START NOW

1. First, analyze current structure and create categorization
2. Create directory structure
3. Refactor atoms first (smallest, no dependencies)
4. Work up through molecules, organisms, templates
5. Commit after each phase
6. Run build after each major change

Begin with Phase 1 and 2: Setup and categorization.
