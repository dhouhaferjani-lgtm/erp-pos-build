# AutoERP Design System

> Visual design tokens, components, and patterns for AutoERP.
> 
> **Note:** This document may be updated based on Google AI Studio design exploration.

---

## Brand Positioning

- **Professional but not cold** — Trustworthy for compliance, approachable for daily use
- **Modern but not intimidating** — Subtle modern touches, not overtly "tech"
- **Capable but not overwhelming** — Progressive disclosure: show essentials, reveal complexity on demand
- **Clean with futuristic feel** — Serving traditional users with modern tools

---

## Target Users

1. **Garage owners/staff** — Vehicle reception, work orders, task tracking
2. **Office staff** — Multi-tasking across invoicing, customers, inventory
3. **Auto parts retailers** — Part lookups, inventory, VIN decoding, customer queries

---

## Design Tokens

### Colors

```javascript
// Primary - Horizon Blue (subject to change)
primary: {
  50: '#EFF6FF',
  100: '#DBEAFE',
  200: '#BFDBFE',
  500: '#3B82F6',
  600: '#2563EB',
  700: '#1D4ED8',
  900: '#1E3A5F',
}

// Secondary - Sage Green
secondary: {
  50: '#F0FDF4',
  100: '#DCFCE7',
  500: '#22C55E',
  600: '#16A34A',
}

// Semantic
destructive: '#DC2626'
warning: '#F59E0B'
info: '#0EA5E9'

// Neutrals - Slate
// Use Tailwind's built-in slate scale
```

### Typography

**Font Family:** Inter (Google Fonts)

```css
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
```

**Type Scale:**

| Name | Size | Weight | Use |
|------|------|--------|-----|
| display | 30px | 600 | Page titles |
| heading-1 | 24px | 600 | Section titles |
| heading-2 | 20px | 600 | Card titles |
| heading-3 | 16px | 600 | Sub-sections |
| body | 14px | 400 | Default text |
| body-medium | 14px | 500 | Emphasis |
| small | 12px | 400 | Secondary text |
| tiny | 11px | 500 | Labels, badges |

### Spacing

Use Tailwind's default 4px base scale.

### Border Radius

- `sm`: 4px — Inputs, badges
- `md`: 8px — Cards, buttons
- `lg`: 12px — Modals, large cards

### Shadows

- `sm`: Subtle elevation (cards)
- `md`: Medium elevation (dropdowns)
- `lg`: High elevation (modals)

---

## Components

### Buttons

```tsx
// Primary
<button className="bg-primary-600 text-white hover:bg-primary-700 px-4 py-2 rounded-md font-medium">
  Save
</button>

// Secondary
<button className="bg-slate-100 text-slate-700 hover:bg-slate-200 px-4 py-2 rounded-md font-medium">
  Cancel
</button>

// Ghost
<button className="bg-transparent hover:bg-slate-100 px-4 py-2 rounded-md font-medium">
  More Options
</button>
```

### Inputs

```tsx
<input 
  className="w-full px-3 py-2 border border-slate-300 rounded-md 
             focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
/>
```

### Badges

```tsx
// Status badges
<span className="px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-700">
  Paid
</span>
<span className="px-2 py-0.5 text-xs font-medium rounded-full bg-yellow-100 text-yellow-700">
  Pending
</span>
<span className="px-2 py-0.5 text-xs font-medium rounded-full bg-slate-100 text-slate-600">
  Draft
</span>
```

### Cards

```tsx
<div className="bg-white border border-slate-200 rounded-lg shadow-sm p-4">
  <h3 className="font-semibold text-slate-900">Card Title</h3>
  <p className="text-slate-500 mt-1">Card content</p>
</div>
```

---

## Layout

### Page Structure

```
┌─────────────────────────────────────────────────────────┐
│  TopBar (search, user, notifications)                   │
├──────────┬──────────────────────────────────────────────┤
│          │                                              │
│  Sidebar │  Page Content                                │
│          │  ┌──────────────────────────────────────┐   │
│  • Home  │  │ Page Header (title + actions)        │   │
│  • Docs  │  ├──────────────────────────────────────┤   │
│  • Parts │  │                                      │   │
│  • etc.  │  │ Content Area                         │   │
│          │  │                                      │   │
│          │  │                                      │   │
│          │  └──────────────────────────────────────┘   │
└──────────┴──────────────────────────────────────────────┘
```

### Sidebar

- Fixed width: 240px (expanded), 64px (collapsed)
- Dark background: `primary-900`
- Active item: Semi-transparent white background

### Content Area

- Max width: 1280px
- Padding: 24px
- Background: `slate-50`

---

## Patterns

### Progressive Disclosure

Show essential information by default, reveal details on demand.

```tsx
// Collapsed row
<tr>
  <td>INV-2025-0042</td>
  <td>ACME Corp</td>
  <td>€1,500.00</td>
  <td><ChevronDown /></td>
</tr>

// Expanded row (on click)
<tr className="bg-slate-50">
  <td colSpan={4}>
    {/* Line items, notes, history */}
  </td>
</tr>
```

### View Toggle

Allow users to switch between table and card views.

```tsx
<div className="flex gap-1 bg-slate-100 p-1 rounded-md">
  <button className={view === 'table' ? 'bg-white shadow-sm' : ''}>
    <TableIcon />
  </button>
  <button className={view === 'cards' ? 'bg-white shadow-sm' : ''}>
    <GridIcon />
  </button>
</div>
```

---

## Icons

Use **Lucide React** icons consistently.

```tsx
import { 
  FileText,      // Documents
  Users,         // Partners
  Package,       // Products
  Truck,         // Delivery
  CreditCard,    // Payments
  BarChart3,     // Reports
  Settings,      // Settings
  Search,        // Search
  Plus,          // Add
  Edit,          // Edit
  Trash2,        // Delete
  ChevronDown,   // Expand
  Check,         // Success
  AlertCircle,   // Warning
  X,             // Close/Error
} from 'lucide-react';
```

---

## Accessibility

- WCAG 2.1 AA compliance
- Minimum contrast ratio: 4.5:1
- Focus indicators on all interactive elements
- Keyboard navigation support
- Screen reader compatible

---

## Mobile Considerations

For React Native mobile app:
- Larger touch targets (44x44px minimum)
- Bottom navigation
- Pull-to-refresh
- Swipe actions

---

*Document Version: 1.0 (Placeholder)*
*Last Updated: November 2025*
*Note: Will be updated based on Google AI Studio exploration*
