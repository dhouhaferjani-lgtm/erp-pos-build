# AutoERP Design System

> Design tokens and visual standards for the AutoERP frontend.
> Source of truth: `apps/web/src/lib/designTokens.ts`

---

## Overview

AutoERP uses a token-based design system built on Tailwind CSS 4. All visual values (colors, spacing, typography, shadows) are defined as composable Tailwind class strings in a single TypeScript file, ensuring consistency across the application.

### Usage

```tsx
import { tokens } from '@/lib/designTokens'

// Composed tokens for common elements
<input className={tokens.input.base} />
<button className={`${tokens.button.base} ${tokens.button.primary} ${tokens.button.sizes.md}`}>
  Save
</button>

// Individual token categories
import { colors, textColors, typography } from '@/lib/designTokens'
```

---

## Color Palette

### Brand Colors (Primary)

| Token | Value | Usage |
|-------|-------|-------|
| `primary.50` | `bg-blue-50` | Light backgrounds, hover states |
| `primary.100` | `bg-blue-100` | Selected item backgrounds |
| `primary.500` | `bg-blue-500` | Focus rings, accents |
| `primary.600` | `bg-blue-600` | Primary buttons, links |
| `primary.700` | `bg-blue-700` | Hover on primary buttons |
| `primary.800` | `bg-blue-800` | Active/pressed states |

### Semantic Colors

| Category | Shades | Usage |
|----------|--------|-------|
| **Success** (green) | 50, 100, 600, 700, 800 | Confirmations, positive states, paid status |
| **Error** (red) | 50, 100, 600, 700, 800 | Errors, destructive actions, overdue status |
| **Warning** (yellow) | 50, 100, 600, 700, 800 | Cautions, pending states, partial status |
| **Neutral** (gray) | 50–900 | Backgrounds, borders, text, disabled states |

### Text Colors

| Token | Value | Usage |
|-------|-------|-------|
| `textColors.primary` | `text-gray-900` | Main body text |
| `textColors.secondary` | `text-gray-700` | Secondary/supporting text |
| `textColors.tertiary` | `text-gray-600` | Labels, descriptions |
| `textColors.disabled` | `text-gray-400` | Disabled state text |
| `textColors.inverse` | `text-white` | Text on dark backgrounds |
| `textColors.error` | `text-red-700` | Error messages |
| `textColors.success` | `text-green-700` | Success messages |
| `textColors.warning` | `text-yellow-700` | Warning messages |
| `textColors.brand` | `text-blue-600` | Links, branded text |

### Border Colors

| Token | Value | Usage |
|-------|-------|-------|
| `borderColors.default` | `border-gray-300` | Standard input borders |
| `borderColors.light` | `border-gray-200` | Card borders, dividers |
| `borderColors.dark` | `border-gray-400` | Emphasized borders |
| `borderColors.primary` | `border-blue-500` | Focused input borders |
| `borderColors.error` | `border-red-500` | Error state borders |
| `borderColors.success` | `border-green-500` | Success state borders |

---

## Typography

### Font Sizes

| Token | Value | Usage |
|-------|-------|-------|
| `typography.fontSize.xs` | `text-xs` | Badges, helper text |
| `typography.fontSize.sm` | `text-sm` | Labels, table cells, buttons |
| `typography.fontSize.base` | `text-base` | Body text |
| `typography.fontSize.lg` | `text-lg` | Section headings |
| `typography.fontSize.xl` | `text-xl` | Page titles, modal titles |
| `typography.fontSize.2xl` | `text-2xl` | Hero headings |

### Font Weights

| Token | Value | Usage |
|-------|-------|-------|
| `typography.fontWeight.normal` | `font-normal` | Body text |
| `typography.fontWeight.medium` | `font-medium` | Labels, navigation |
| `typography.fontWeight.semibold` | `font-semibold` | Headings, emphasis |
| `typography.fontWeight.bold` | `font-bold` | Strong emphasis |

---

## Spacing

| Token | Value | Usage |
|-------|-------|-------|
| `spacing.xs` | `p-1` | Tight padding (icons, badges) |
| `spacing.sm` | `p-2` | Compact elements |
| `spacing.md` | `p-4` | Standard padding (cards, sections) |
| `spacing.lg` | `p-6` | Generous padding (modals, pages) |
| `spacing.xl` | `p-8` | Extra spacing (hero sections) |

---

## Border Radius

| Token | Value | Usage |
|-------|-------|-------|
| `borderRadius.none` | `rounded-none` | No rounding |
| `borderRadius.sm` | `rounded-sm` | Subtle rounding |
| `borderRadius.base` | `rounded` | Default rounding |
| `borderRadius.md` | `rounded-md` | Medium rounding |
| `borderRadius.lg` | `rounded-lg` | Cards, inputs, buttons |
| `borderRadius.xl` | `rounded-xl` | Large containers |
| `borderRadius.full` | `rounded-full` | Circles, pills, avatars |

---

## Shadows

| Token | Value | Usage |
|-------|-------|-------|
| `shadows.none` | `shadow-none` | Flat elements |
| `shadows.sm` | `shadow-sm` | Subtle elevation (inputs) |
| `shadows.base` | `shadow` | Default elevation |
| `shadows.md` | `shadow-md` | Cards, dropdowns |
| `shadows.lg` | `shadow-lg` | Modals, popovers |
| `shadows.xl` | `shadow-xl` | Dialogs, floating panels |

---

## Composed Tokens

These are pre-assembled class strings for common UI elements.

### Input Fields

```tsx
tokens.input.base    // Full input styling with focus, disabled states
tokens.input.error   // Error variant border + focus ring
tokens.input.success // Success variant border + focus ring
```

**Base classes:** `mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed`

### Buttons

```tsx
tokens.button.base       // Shared button styling (flex, rounded, transitions, disabled)
tokens.button.primary    // Blue filled button
tokens.button.secondary  // White bordered button
tokens.button.danger     // Red filled button
tokens.button.ghost      // Transparent hover button
tokens.button.sizes.sm   // px-3 py-1.5 text-sm
tokens.button.sizes.md   // px-4 py-2 text-sm
tokens.button.sizes.lg   // px-6 py-3 text-base
```

### Modals

```tsx
tokens.modal.backdrop    // Fixed overlay with centered flex
tokens.modal.container   // White rounded card with padding
tokens.modal.header      // Flex row with space-between
tokens.modal.title       // text-xl font-semibold
tokens.modal.closeButton // Rounded icon button
tokens.modal.footer      // Right-aligned button row
```

### Alerts

```tsx
tokens.alert.base    // Rounded padding + text-sm
tokens.alert.error   // Red background + text
tokens.alert.success // Green background + text
tokens.alert.warning // Yellow background + text
tokens.alert.info    // Blue background + text
```

### Cards

```tsx
tokens.card.base   // Rounded border + white background + padding
tokens.card.hover  // hover:shadow-md transition
```

### Badges

```tsx
tokens.badge.base    // Inline-flex pill with text-xs font-medium
tokens.badge.gray    // Neutral/default badge
tokens.badge.blue    // Info/primary badge
tokens.badge.green   // Success badge
tokens.badge.red     // Error/danger badge
tokens.badge.yellow  // Warning badge
```

### Other Elements

```tsx
tokens.select.base      // Same as input.base (select dropdowns)
tokens.textarea.base    // Input base + resize-y
tokens.checkbox.base    // 4x4 rounded checkbox
tokens.radio.base       // 4x4 radio button
tokens.label.base       // block text-sm font-medium text-gray-700
tokens.label.required   // text-red-500 (for asterisk)
tokens.helperText.base  // mt-1 text-xs text-gray-500
tokens.helperText.error // mt-1 text-xs text-red-600
```

---

## Focus & Accessibility

```tsx
focusRing.default  // focus:outline-none focus:ring-2 focus:ring-offset-2
focusRing.primary  // focus:ring-blue-500
focusRing.error    // focus:ring-red-500
focusRing.success  // focus:ring-green-500
```

All interactive elements include focus ring styles for keyboard navigation accessibility.

---

## Transitions

```tsx
transitions.base   // transition-colors
transitions.all    // transition-all
transitions.fast   // duration-150
transitions.normal // duration-200
transitions.slow   // duration-300
```

---

## Listing-Page Composition

> **Status: canon PROPOSED, Wave 3 gated on the `CX-4` re-census.**
> Nothing in this section is enforced by a lint rule or a ratchet today, and no
> page-migration wave is scheduled. Treat it as the agreed target shape, not as
> a rule you are already in breach of. Source: the UI presentation audit of
> record — `00-EXECUTIVE-REPORT.md`, a session artefact under
> `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/` which is **not tracked in
> this repository** (`docs/sessions/` is gitignored, `.gitignore:58`) and is
> therefore deliberately referenced by name rather than linked. Its §D is where
> the finding `UI-44` ("no listing-page composition guidance exists") is
> recorded. The re-census linked below *is* tracked (it was force-added).

Before this section, this document covered colours, typography, spacing and
composed element tokens only — there was no written guidance for how a list or
index page should be assembled. That absence is the whole reason list pages
diverged: every feature invented its own filter row, its own empty state and
its own pagination, and no reviewer had a canon to point at.

### The components that exist today

These are all real and importable now. The canon below composes **only** these.

| Component | Path | Role |
|---|---|---|
| `ListPageLayout` | `components/molecules/ListPageLayout/` | Page shell: `PageHeader` + optional `filters` slot + body + optional `pagination` slot |
| `DataTable` | `components/molecules/DataTable/` | The canonical list table; owns loading skeletons and the empty state |
| `EmptyState` | `components/molecules/EmptyState/` | Standalone empty-state block, also rendered internally by `DataTable` |
| `OffsetPagination` | `components/ui/OffsetPagination.tsx` | Offset/page-number pagination controls |
| `FilterPanel` | `components/ui/FilterPanel.tsx` | The only purpose-built filter container — but adopted by exactly **one** feature page today (`features/inventory/ProductListPage.tsx`); it is the canonical container by design, not by usage |
| `ActiveFilters` | `components/ui/ActiveFilters.tsx` | The applied-filter chip row with per-chip and clear-all removal. Rendered internally by `FilterPanel`, and used directly by four feature surfaces (`features/inventory/ProductListPage.tsx`, `features/menu/pages/MenuListPage.tsx`, `features/parts-catalog/components/organisms/FilterSidebar.tsx` and `…/TireDimensionSearch.tsx`). Do not hand-roll a chip row |
| `SearchInput` | `components/molecules/SearchInput/` | Debounced search box |
| `FilterTabs` | `components/molecules/FilterTabs/` | Tab-style segmented filter |
| `ui/filters/*` | `components/ui/filters/` | Filter primitives: `SearchFilter`, `EnumFilter`, `BooleanFilter`, `RangeFilter`, `DateRangeFilter` |

### The recommended composition

```tsx
<ListPageLayout
  title={t('…')}
  actions={<Button onClick={onCreate}>{t('…add')}</Button>}
  filters={/* filter bar — see below */}
  pagination={
    <OffsetPagination
      currentPage={meta.current_page}
      lastPage={meta.last_page}
      total={meta.total}
      perPage={meta.per_page}
      from={meta.from}
      to={meta.to}
      onPageChange={setPage}
      onPerPageChange={setPerPage}
    />
  }
>
  <DataTable
    data={rows}
    columns={columns}
    keyExtractor={(row) => row.id}
    isLoading={isLoading}
    emptyTitle={t('…empty.title')}
    emptyDescription={t('…empty.description')}
  />
</ListPageLayout>
```

Four rules follow from that shape:

1. **The shell is `ListPageLayout`.** It renders the page's single `<h1>` through
   `PageHeader`, so a page that uses it must not render its own heading.
2. **The filter bar goes in the `filters` slot**, not above the layout. The slot
   is omitted entirely when no filters are passed, so there is no empty gap.
3. **The empty state is passed into `DataTable`**, not conditionally rendered
   around it. `DataTable` accepts `emptyTitle`/`emptyDescription` (it builds an
   `EmptyState` for you) or a fully custom `emptyState` node. A page that
   branches on `rows.length === 0` and returns its own markup is the pattern
   this canon replaces.
4. **Pagination goes in the layout's `pagination` slot**, using
   `OffsetPagination` for offset-paginated endpoints.

`DataTable` also accepts a legacy children-markup form for pages that already
own their table semantics. It is explicitly typed as a passthrough for swept
pages; new call sites use the `columns`/`data`/`keyExtractor` API above.

### Two pieces of the canon are PROPOSED and do not exist yet

Do not import these; they are not built. They are recorded here because the
canon in `00 §D` names them, and because attempting to follow the canon without
knowing they are missing wastes a reviewer's time.

- **`FilterBar`** — a thin wrapper over `FilterPanel` + `SearchInput` +
  `FilterTabs` + the `ui/filters/*` primitives, so a page composes one component
  instead of hand-assembling four. **Not built.** Until it exists, assemble the
  filter slot from the existing primitives directly — including `ActiveFilters`
  for the applied-filter chip row, which already exists and must not be
  re-implemented by hand.
- **`DataTableColumn.sortable`** — sorting absorbed into the column descriptor
  so pages stop wiring their own sort headers. **Not built**: `DataTableColumn`
  today exposes `key`, `header`, `align`, `numeric`, `render`, `accessor`,
  `headerClassName`, `cellClassName` and `width`, and there is no `sortable`
  anywhere in `components/molecules/DataTable/`.

Nothing beyond these two is invented by the canon. If a proposal needs a third
new component, it is a change to the canon and belongs in the audit, not here.

### Adoption — be honest about it

**`ListPageLayout` is used by 12 of the 46 listing pages.** That is the one
adoption figure the audit marks decision-grade
(`00-EXECUTIVE-REPORT.md` §4), mechanically re-verified, and it is measured at
the wave's pinned base `d682b38ec9761a917b9716428091a482745795f6`. The other
34 pages each arrange the header, filters, table and pagination themselves.

Every other distribution — how many pages paginate, how many have a real empty
state, how the filter patterns split — is **deliberately not restated here**,
because those numbers move with every merge and a stale number in a canon
document is worse than no number. The live figures, the per-page table and the
reproduction command live in the re-census report:

- **[`docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/17-listing-census-recensus.md`](../sessions/UI-PRESENTATION-AUDIT-2026-08-10/17-listing-census-recensus.md)**
  — the component-graph-aware listing re-census (`CX-4`). Regenerate with
  `cd apps/web && node tools/audit-listing-census.mjs`.

Read that report's **Method** section before quoting any of its numbers: the
46-page population is discovered by a filename rule inherited from the original
audit (`*ListPage`, `*ListView`, `*QueuePage`, `*IndexPage` under
`src/features/`), and the report itself flags a secondary surface of ~33 further
route-mounted pages that render a `DataTable` under other names. The
distributions are decision-grade **for the inherited 46-page cohort only**, not
for the whole product.

### Why this is not a migration order

The audit sets two preconditions before any page is migrated. Both are now met:
the re-census (`CX-4`) is the report linked above, and writing the canon down
(`UI-44`) is this section. What is **not** done is the migration itself — it is
**Wave 3 and unscheduled**, and it must be budgeted against the re-census
figures, not against the refuted numbers in the original section-02 census.
Until it is scheduled and budgeted, existing pages are not in violation of
anything; this section binds new listing pages and voluntary rewrites.

One prerequisite that was *dropped*: extending `StatusTone`. `StatusBadge`
deliberately maps many domain statuses onto fewer semantic tones, and nothing in
source showed the 6-tone API forced any bespoke badge. Extend the tone palette
only if a domain-state→tone mapping exercise surfaces a distinction the existing
tones genuinely cannot express.

---

*Last Updated: 2026-08-19*
*Source: `apps/web/src/lib/designTokens.ts`*
*Listing-page canon: `00-EXECUTIVE-REPORT.md` §D — the audit of record, a session artefact under `docs/sessions/UI-PRESENTATION-AUDIT-2026-08-10/` that is not tracked in this repository (hence named, not linked)*
