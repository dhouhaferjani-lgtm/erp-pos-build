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

*Last Updated: February 2026*
*Source: `apps/web/src/lib/designTokens.ts`*
