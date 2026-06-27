# Post-Save Stay-on-Record Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** After saving a record, the user stays on that record's detail page instead of being bounced to the list — applied consistently via shared primitives across the Product, Loyalty Program, Loyalty Member, and Document editors.

**Architecture:** Three new shared FE primitives — `useAfterSaveNavigation` (destination helpers), `<SaveSplitButton>` (presentational Save / Save & Close with intent callbacks + keyboard a11y), `useUnsavedChangesGuard` (BrowserRouter-compatible `beforeunload` + Cancel confirm) — plus a `postSavePolicy` registry of list-return exceptions and an extension to `useDraftAutoSave`. Editors adopt them; create flows navigate to the record **detail route `/:id`**. No backend changes (Product status/Publish deferred to §3).

**Tech Stack:** React 19, TypeScript strict, react-router-dom 7 (`<BrowserRouter>`/`<Routes>`), TanStack Query 5, react-hook-form + zod, Vitest + Testing Library, react-i18next (EN/FR/AR), design tokens (`@/lib/designTokens`).

## Global Constraints

- **No `any`** — use `unknown` + type guards (rule 3).
- **All user-facing text via `t()`** — no hardcoded strings (rule 11). New keys go in EN/FR/AR.
- **Design tokens only** for colors in `apps/web/src` (rule 18) — import from `@/lib/designTokens`; reuse the `Button` atom for clickable surfaces.
- **Destination after create/update = record DETAIL route `/:id`** (not `/:id/edit`). Exception preserved: Documents `purchase_order` create → `/:id/edit` (needs additional-costs editing).
- **Guard is `beforeunload` + explicit Cancel confirm only** — NOT `useBlocker` (app is not a data router). No data-router migration.
- **Product is nav-only** this session: no `status`/Publish backend; the stubbed "Publish" button is removed in favor of a single Save split button.
- **Run tests by path** with Vitest; never the full PHPUnit suite. FE-only plan.
- **Frequent commits** — one per task (TDD: red → green → commit).

---

### Task 1: `useAfterSaveNavigation` hook

**Files:**
- Create: `apps/web/src/hooks/useAfterSaveNavigation.ts`
- Test: `apps/web/src/hooks/__tests__/useAfterSaveNavigation.test.tsx`

**Interfaces:**
- Produces:
  - `interface AfterSaveNavConfig { recordPath: (id: string) => string; listPath: string; createPath?: string }`
  - `interface AfterSaveNav { goToRecord: (id: string) => void; goToNew: () => void; goToList: () => void }`
  - `function useAfterSaveNavigation(config: AfterSaveNavConfig): AfterSaveNav`

- [ ] **Step 1: Write the failing test**

```tsx
import { describe, it, expect, vi } from 'vitest'
import { renderHook } from '@testing-library/react'
import { useAfterSaveNavigation } from '../useAfterSaveNavigation'

const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({ useNavigate: () => mockNavigate }))

describe('useAfterSaveNavigation', () => {
  it('goToRecord navigates to the record detail path', () => {
    const { result } = renderHook(() =>
      useAfterSaveNavigation({ recordPath: (id) => `/inventory/products/${id}`, listPath: '/inventory/products' }),
    )
    result.current.goToRecord('abc')
    expect(mockNavigate).toHaveBeenCalledWith('/inventory/products/abc')
  })

  it('goToList navigates to the list path', () => {
    const { result } = renderHook(() =>
      useAfterSaveNavigation({ recordPath: (id) => `/x/${id}`, listPath: '/x' }),
    )
    result.current.goToList()
    expect(mockNavigate).toHaveBeenCalledWith('/x')
  })

  it('goToNew navigates to createPath when provided', () => {
    const { result } = renderHook(() =>
      useAfterSaveNavigation({ recordPath: (id) => `/x/${id}`, listPath: '/x', createPath: '/x/new' }),
    )
    result.current.goToNew()
    expect(mockNavigate).toHaveBeenCalledWith('/x/new')
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/hooks/__tests__/useAfterSaveNavigation.test.tsx`
Expected: FAIL — cannot find module `../useAfterSaveNavigation`.

- [ ] **Step 3: Write minimal implementation**

```ts
import { useMemo } from 'react'
import { useNavigate } from 'react-router-dom'

export interface AfterSaveNavConfig {
  /** Detail route for a record id, e.g. (id) => `/inventory/products/${id}` */
  recordPath: (id: string) => string
  /** Parent list route, e.g. `/inventory/products` */
  listPath: string
  /** Create route for Save & New; falls back to listPath when omitted */
  createPath?: string
}

export interface AfterSaveNav {
  goToRecord: (id: string) => void
  goToNew: () => void
  goToList: () => void
}

/** Canonical post-save navigation. Editors call these AFTER their own
 *  mutation success side effects (invalidation, uploads, toasts) complete. */
export function useAfterSaveNavigation(config: AfterSaveNavConfig): AfterSaveNav {
  const navigate = useNavigate()
  return useMemo<AfterSaveNav>(() => ({
    goToRecord: (id) => { void navigate(config.recordPath(id)) },
    goToNew: () => { void navigate(config.createPath ?? config.listPath) },
    goToList: () => { void navigate(config.listPath) },
  }), [navigate, config])
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/web && pnpm vitest run src/hooks/__tests__/useAfterSaveNavigation.test.tsx`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/hooks/useAfterSaveNavigation.ts apps/web/src/hooks/__tests__/useAfterSaveNavigation.test.tsx
git commit -m "feat(web): add useAfterSaveNavigation post-save destination hook"
```

---

### Task 2: `postSavePolicy` list-return exception registry

**Files:**
- Create: `apps/web/src/lib/postSavePolicy.ts`
- Test: `apps/web/src/lib/__tests__/postSavePolicy.test.ts`

**Interfaces:**
- Produces: `LIST_RETURN_EXCEPTIONS` (readonly string tuple), `type ListReturnException`, `function isListReturnException(key: string): boolean`.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect } from 'vitest'
import { isListReturnException, LIST_RETURN_EXCEPTIONS } from '../postSavePolicy'

describe('postSavePolicy', () => {
  it('lists the known batch/reference-data exceptions', () => {
    expect(LIST_RETURN_EXCEPTIONS).toEqual([
      'menu', 'promotion', 'coupon',
      'parapharmacy.ingredient', 'parapharmacy.certification',
      'parapharmacy.healthClaim', 'parapharmacy.keyComponent',
    ])
  })

  it('treats stay-on-record editors as non-exceptions', () => {
    expect(isListReturnException('product')).toBe(false)
    expect(isListReturnException('loyalty.program')).toBe(false)
    expect(isListReturnException('document')).toBe(false)
  })

  it('recognizes a declared exception', () => {
    expect(isListReturnException('coupon')).toBe(true)
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/lib/__tests__/postSavePolicy.test.ts`
Expected: FAIL — cannot find module `../postSavePolicy`.

- [ ] **Step 3: Write minimal implementation**

```ts
/** Editors that intentionally return to their list after save (batch /
 *  reference-data entry). Every other editor defaults to stay-on-record.
 *  Add new exceptions here explicitly — never via a scattered ad-hoc flag. */
export const LIST_RETURN_EXCEPTIONS = [
  'menu', 'promotion', 'coupon',
  'parapharmacy.ingredient', 'parapharmacy.certification',
  'parapharmacy.healthClaim', 'parapharmacy.keyComponent',
] as const

export type ListReturnException = typeof LIST_RETURN_EXCEPTIONS[number]

export function isListReturnException(key: string): boolean {
  return (LIST_RETURN_EXCEPTIONS as readonly string[]).includes(key)
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/web && pnpm vitest run src/lib/__tests__/postSavePolicy.test.ts`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/lib/postSavePolicy.ts apps/web/src/lib/__tests__/postSavePolicy.test.ts
git commit -m "feat(web): add postSavePolicy list-return exception registry"
```

---

### Task 3: i18n keys for save actions + unsaved-changes confirm

**Files:**
- Modify: `apps/web/src/locales/en/common.json`
- Modify: `apps/web/src/locales/fr/common.json`
- Modify: `apps/web/src/locales/ar/common.json`
- Test: `apps/web/src/locales/__tests__/saveActionKeys.test.ts`

**Interfaces:**
- Produces (under the existing `actions` and `confirmation` objects in `common`):
  `actions.saveAndNew`, `actions.saveAndClose`, `actions.openSaveMenu`,
  `confirmation.unsavedChangesTitle`, `confirmation.unsavedChangesBody`,
  `confirmation.leaveWithoutSaving`, `confirmation.stayOnPage`.

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect } from 'vitest'
import en from '../en/common.json'
import fr from '../fr/common.json'
import ar from '../ar/common.json'

const REQUIRED_ACTIONS = ['saveAndNew', 'saveAndClose', 'openSaveMenu'] as const
const REQUIRED_CONFIRM = ['unsavedChangesTitle', 'unsavedChangesBody', 'leaveWithoutSaving', 'stayOnPage'] as const

describe.each([['en', en], ['fr', fr], ['ar', ar]])('common save-action keys (%s)', (_name, bundle) => {
  const b = bundle as { actions: Record<string, string>; confirmation: Record<string, string> }
  it.each(REQUIRED_ACTIONS)('has actions.%s', (k) => {
    expect(typeof b.actions[k]).toBe('string')
    expect(b.actions[k].length).toBeGreaterThan(0)
  })
  it.each(REQUIRED_CONFIRM)('has confirmation.%s', (k) => {
    expect(typeof b.confirmation[k]).toBe('string')
    expect(b.confirmation[k].length).toBeGreaterThan(0)
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/locales/__tests__/saveActionKeys.test.ts`
Expected: FAIL — `actions.saveAndNew` (etc.) undefined.

- [ ] **Step 3: Add the keys (merge into the existing `actions` and `confirmation` objects)**

In `en/common.json` add to `actions`: `"saveAndNew": "Save & New"`, `"saveAndClose": "Save & Close"`, `"openSaveMenu": "More save options"`; to `confirmation`: `"unsavedChangesTitle": "Unsaved changes"`, `"unsavedChangesBody": "You have unsaved changes. Leave without saving?"`, `"leaveWithoutSaving": "Leave"`, `"stayOnPage": "Stay"`.

In `fr/common.json`: `actions.saveAndNew` = `"Enregistrer & Nouveau"`, `actions.saveAndClose` = `"Enregistrer & Fermer"`, `actions.openSaveMenu` = `"Plus d'options d'enregistrement"`; `confirmation.unsavedChangesTitle` = `"Modifications non enregistrées"`, `confirmation.unsavedChangesBody` = `"Vous avez des modifications non enregistrées. Quitter sans enregistrer ?"`, `confirmation.leaveWithoutSaving` = `"Quitter"`, `confirmation.stayOnPage` = `"Rester"`.

In `ar/common.json`: `actions.saveAndNew` = `"حفظ وجديد"`, `actions.saveAndClose` = `"حفظ وإغلاق"`, `actions.openSaveMenu` = `"خيارات حفظ إضافية"`; `confirmation.unsavedChangesTitle` = `"تغييرات غير محفوظة"`, `confirmation.unsavedChangesBody` = `"لديك تغييرات غير محفوظة. المغادرة دون حفظ؟"`, `confirmation.leaveWithoutSaving` = `"مغادرة"`, `confirmation.stayOnPage` = `"البقاء"`.

(If `confirmation` already contains `unsavedChanges`, keep it; only add the missing keys.)

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/web && pnpm vitest run src/locales/__tests__/saveActionKeys.test.ts`
Expected: PASS (21 assertions: 7 keys × 3 locales).

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/locales/en/common.json apps/web/src/locales/fr/common.json apps/web/src/locales/ar/common.json apps/web/src/locales/__tests__/saveActionKeys.test.ts
git commit -m "feat(web): i18n keys for save-action variants + unsaved-changes confirm (en/fr/ar)"
```

---

### Task 4: `<SaveSplitButton>` component

**Files:**
- Create: `apps/web/src/components/molecules/SaveSplitButton/SaveSplitButton.tsx`
- Create: `apps/web/src/components/molecules/SaveSplitButton/index.ts`
- Test: `apps/web/src/components/molecules/SaveSplitButton/SaveSplitButton.test.tsx`

**Interfaces:**
- Consumes: `Button` from `@/components/atoms`; `actions.*` keys from Task 3.
- Produces:
  ```ts
  interface SaveSplitButtonProps {
    onPrimarySave: () => void
    onSaveAndNew?: () => void
    onSaveAndClose?: () => void
    isPending?: boolean
    disabled?: boolean
    primaryLabel?: string         // default: t('actions.save')
    form?: string                 // primary becomes type=submit form={form}
    primaryType?: 'submit' | 'button'  // default 'submit'
  }
  ```
  The host owns submission/intent: the primary submits the form; menu items invoke `onSaveAndClose`/`onSaveAndNew`, which the host wires to "set intent ref → `form.requestSubmit()`" (see Tasks 7–10). Menu items are hidden when their callback is omitted.

- [ ] **Step 1: Write the failing test**

```tsx
import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { SaveSplitButton } from './SaveSplitButton'

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (k: string) => k }) }))

describe('SaveSplitButton', () => {
  it('renders the primary save with default label and a menu trigger', () => {
    render(<SaveSplitButton onPrimarySave={vi.fn()} onSaveAndClose={vi.fn()} />)
    expect(screen.getByRole('button', { name: 'actions.save' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'actions.openSaveMenu' })).toHaveAttribute('aria-haspopup', 'menu')
  })

  it('hides the caret when no secondary actions are provided', () => {
    render(<SaveSplitButton onPrimarySave={vi.fn()} />)
    expect(screen.queryByRole('button', { name: 'actions.openSaveMenu' })).not.toBeInTheDocument()
  })

  it('opens the menu and fires Save & Close', () => {
    const onClose = vi.fn()
    render(<SaveSplitButton onPrimarySave={vi.fn()} onSaveAndClose={onClose} />)
    fireEvent.click(screen.getByRole('button', { name: 'actions.openSaveMenu' }))
    fireEvent.click(screen.getByRole('menuitem', { name: 'actions.saveAndClose' }))
    expect(onClose).toHaveBeenCalledOnce()
  })

  it('closes the menu on Escape and returns focus to the trigger', () => {
    render(<SaveSplitButton onPrimarySave={vi.fn()} onSaveAndClose={vi.fn()} />)
    const trigger = screen.getByRole('button', { name: 'actions.openSaveMenu' })
    fireEvent.click(trigger)
    expect(screen.getByRole('menu')).toBeInTheDocument()
    fireEvent.keyDown(screen.getByRole('menu'), { key: 'Escape' })
    expect(screen.queryByRole('menu')).not.toBeInTheDocument()
    expect(trigger).toHaveFocus()
  })

  it('uses the primaryLabel override and renders as a submit for the given form', () => {
    render(<SaveSplitButton onPrimarySave={vi.fn()} primaryLabel="catalog:editor.actions.save" form="product-editor-form" />)
    const primary = screen.getByRole('button', { name: 'catalog:editor.actions.save' })
    expect(primary).toHaveAttribute('type', 'submit')
    expect(primary).toHaveAttribute('form', 'product-editor-form')
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/components/molecules/SaveSplitButton/SaveSplitButton.test.tsx`
Expected: FAIL — cannot find module `./SaveSplitButton`.

- [ ] **Step 3: Write the implementation**

```tsx
import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { ChevronDown } from 'lucide-react'
import { Button } from '@/components/atoms'

export interface SaveSplitButtonProps {
  onPrimarySave: () => void
  onSaveAndNew?: () => void
  onSaveAndClose?: () => void
  isPending?: boolean
  disabled?: boolean
  primaryLabel?: string
  form?: string
  primaryType?: 'submit' | 'button'
}

export function SaveSplitButton({
  onPrimarySave, onSaveAndNew, onSaveAndClose,
  isPending = false, disabled = false, primaryLabel, form, primaryType = 'submit',
}: SaveSplitButtonProps) {
  const { t } = useTranslation('common')
  const [open, setOpen] = useState(false)
  const triggerRef = useRef<HTMLButtonElement>(null)
  const menuRef = useRef<HTMLUListElement>(null)

  const items: Array<{ key: string; label: string; onClick: () => void }> = []
  if (onSaveAndNew) items.push({ key: 'new', label: t('actions.saveAndNew'), onClick: onSaveAndNew })
  if (onSaveAndClose) items.push({ key: 'close', label: t('actions.saveAndClose'), onClick: onSaveAndClose })
  const hasMenu = items.length > 0

  const close = useCallback((focusTrigger = true) => {
    setOpen(false)
    if (focusTrigger) triggerRef.current?.focus()
  }, [])

  useEffect(() => {
    if (!open) return
    const onDocClick = (e: MouseEvent) => {
      if (!menuRef.current?.contains(e.target as Node) && !triggerRef.current?.contains(e.target as Node)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', onDocClick)
    return () => document.removeEventListener('mousedown', onDocClick)
  }, [open])

  useEffect(() => {
    if (open) (menuRef.current?.querySelector('[role="menuitem"]') as HTMLElement | null)?.focus()
  }, [open])

  const onMenuKeyDown = (e: React.KeyboardEvent<HTMLUListElement>) => {
    const nodes = Array.from(menuRef.current?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? [])
    const idx = nodes.indexOf(document.activeElement as HTMLElement)
    if (e.key === 'Escape') { e.preventDefault(); close() }
    else if (e.key === 'ArrowDown') { e.preventDefault(); nodes[Math.min(idx + 1, nodes.length - 1)]?.focus() }
    else if (e.key === 'ArrowUp') { e.preventDefault(); nodes[Math.max(idx - 1, 0)]?.focus() }
    else if (e.key === 'Home') { e.preventDefault(); nodes[0]?.focus() }
    else if (e.key === 'End') { e.preventDefault(); nodes[nodes.length - 1]?.focus() }
  }

  return (
    <div className="relative inline-flex items-center">
      <Button
        type={primaryType}
        {...(form ? { form } : {})}
        variant="primary"
        disabled={disabled || isPending}
        onClick={primaryType === 'button' ? onPrimarySave : undefined}
      >
        {isPending ? t('saving') : (primaryLabel ?? t('actions.save'))}
      </Button>
      {hasMenu && (
        <>
          <Button
            ref={triggerRef}
            type="button"
            variant="primary"
            aria-haspopup="menu"
            aria-expanded={open}
            aria-label={t('actions.openSaveMenu')}
            disabled={disabled || isPending}
            onClick={() => setOpen((v) => !v)}
            className="ml-px px-2"
          >
            <ChevronDown className="h-4 w-4" aria-hidden="true" />
          </Button>
          {open && (
            <ul
              ref={menuRef}
              role="menu"
              onKeyDown={onMenuKeyDown}
              className="absolute right-0 top-full z-20 mt-1 min-w-[12rem] rounded-[var(--radius-button)] border border-neutral-200 bg-white py-1 shadow-lg"
            >
              {items.map((item) => (
                <li key={item.key}>
                  <button
                    type="button"
                    role="menuitem"
                    onClick={() => { close(false); item.onClick() }}
                    className="block w-full px-4 py-2 text-left text-sm hover:bg-neutral-50"
                  >
                    {item.label}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </>
      )}
    </div>
  )
}
```

> Note: confirm `Button` from `@/components/atoms` forwards `ref` and accepts `form`/`aria-*`. If it does not forward refs, wrap the caret trigger in a native `<button>` styled with `tokens.button.primary` from `@/lib/designTokens` instead (rule 18 — no raw hex). Adjust the border/shadow utility classes to the nearest existing tokens used by `ActionMenu`.

- [ ] **Step 4: Add the barrel export**

```ts
export { SaveSplitButton } from './SaveSplitButton'
export type { SaveSplitButtonProps } from './SaveSplitButton'
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd apps/web && pnpm vitest run src/components/molecules/SaveSplitButton/SaveSplitButton.test.tsx`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/components/molecules/SaveSplitButton/
git commit -m "feat(web): SaveSplitButton (Save / Save & Close) with keyboard a11y"
```

---

### Task 5: `useUnsavedChangesGuard` (beforeunload) + `confirmDiscard`

**Files:**
- Create: `apps/web/src/hooks/useUnsavedChangesGuard.ts`
- Test: `apps/web/src/hooks/__tests__/useUnsavedChangesGuard.test.tsx`

**Interfaces:**
- Produces:
  - `interface DirtyState { isDirty: boolean; autosavePending?: boolean; autosaveFailed?: boolean }`
  - `function useUnsavedChangesGuard(dirty: DirtyState): void` — registers `beforeunload` while there is anything to warn about.
  - `function confirmDiscard(message: string): boolean` — wraps `window.confirm`; editors call it from Cancel/Back when dirty (used by Tasks 7–10).

- [ ] **Step 1: Write the failing test**

```tsx
import { describe, it, expect, vi, afterEach } from 'vitest'
import { renderHook } from '@testing-library/react'
import { useUnsavedChangesGuard, confirmDiscard } from '../useUnsavedChangesGuard'

afterEach(() => vi.restoreAllMocks())

describe('useUnsavedChangesGuard', () => {
  it('adds a beforeunload listener when dirty and removes it on cleanup', () => {
    const add = vi.spyOn(window, 'addEventListener')
    const remove = vi.spyOn(window, 'removeEventListener')
    const { unmount } = renderHook(() => useUnsavedChangesGuard({ isDirty: true }))
    expect(add).toHaveBeenCalledWith('beforeunload', expect.any(Function))
    unmount()
    expect(remove).toHaveBeenCalledWith('beforeunload', expect.any(Function))
  })

  it('does not register when nothing is dirty/pending/failed', () => {
    const add = vi.spyOn(window, 'addEventListener')
    renderHook(() => useUnsavedChangesGuard({ isDirty: false }))
    expect(add).not.toHaveBeenCalledWith('beforeunload', expect.any(Function))
  })

  it('warns on autosavePending and autosaveFailed even when not dirty', () => {
    const add = vi.spyOn(window, 'addEventListener')
    renderHook(() => useUnsavedChangesGuard({ isDirty: false, autosavePending: true }))
    expect(add).toHaveBeenCalledWith('beforeunload', expect.any(Function))
  })
})

describe('confirmDiscard', () => {
  it('returns the window.confirm result', () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    expect(confirmDiscard('msg')).toBe(true)
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/hooks/__tests__/useUnsavedChangesGuard.test.tsx`
Expected: FAIL — cannot find module `../useUnsavedChangesGuard`.

- [ ] **Step 3: Write minimal implementation**

```ts
import { useEffect } from 'react'

export interface DirtyState {
  isDirty: boolean
  autosavePending?: boolean
  autosaveFailed?: boolean
}

/** BrowserRouter-compatible guard: warns on browser unload (tab close /
 *  refresh) while there are unsaved or un-persisted changes. In-app Cancel/
 *  Back uses confirmDiscard(). Full in-app route blocking (useBlocker) is
 *  deferred — it needs a data-router migration. */
export function useUnsavedChangesGuard(dirty: DirtyState): void {
  const shouldWarn = dirty.isDirty || !!dirty.autosavePending || !!dirty.autosaveFailed
  useEffect(() => {
    if (!shouldWarn) return
    const handler = (e: BeforeUnloadEvent) => { e.preventDefault(); e.returnValue = '' }
    window.addEventListener('beforeunload', handler)
    return () => window.removeEventListener('beforeunload', handler)
  }, [shouldWarn])
}

export function confirmDiscard(message: string): boolean {
  return window.confirm(message)
}
```

> Follow-up (not this task): replace `confirmDiscard`'s `window.confirm` with the app's modal using `confirmation.unsavedChangesTitle/Body/leaveWithoutSaving/stayOnPage`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/web && pnpm vitest run src/hooks/__tests__/useUnsavedChangesGuard.test.tsx`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/hooks/useUnsavedChangesGuard.ts apps/web/src/hooks/__tests__/useUnsavedChangesGuard.test.tsx
git commit -m "feat(web): useUnsavedChangesGuard (beforeunload) + confirmDiscard"
```

---

### Task 6: Extend `useDraftAutoSave` with pending/failed state

**Files:**
- Modify: `apps/web/src/hooks/useDraftAutoSave.ts`
- Test: `apps/web/src/hooks/__tests__/useDraftAutoSave.state.test.tsx`

**Interfaces:**
- Produces (added to `AutoSaveState`): `autosavePending: boolean`, `autosaveFailed: boolean`, `lastError: Error | null`. Existing fields (`draftId`, `isSaving`, `lastSavedAt`, `saveNow`, `reset`) unchanged.

- [ ] **Step 1: Write the failing test**

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { renderHook, waitFor, act } from '@testing-library/react'
import { useDraftAutoSave } from '../useDraftAutoSave'
import * as api from '../../lib/api'

vi.mock('../../lib/api', () => ({ apiPost: vi.fn() }))
const apiPost = api.apiPost as unknown as ReturnType<typeof vi.fn>

const draft = { type: 'invoice' as const, lines: [{ product_id: 'p1', quantity: 1, unit_price: 1 }] }

describe('useDraftAutoSave failure/pending state', () => {
  beforeEach(() => { vi.useFakeTimers(); apiPost.mockReset() })

  it('exposes autosaveFailed=true and lastError when save throws', async () => {
    apiPost.mockRejectedValueOnce(new Error('boom'))
    const { result } = renderHook(() => useDraftAutoSave(draft, { debounceMs: 10 }))
    await act(async () => { await result.current.saveNow().catch(() => {}) })
    await waitFor(() => expect(result.current.autosaveFailed).toBe(true))
    expect(result.current.lastError?.message).toBe('boom')
  })

  it('clears autosaveFailed after a subsequent success', async () => {
    apiPost.mockResolvedValueOnce({ draft_id: 'd1', saved_at: new Date(0).toISOString() })
    const { result } = renderHook(() => useDraftAutoSave(draft, { debounceMs: 10 }))
    await act(async () => { await result.current.saveNow() })
    expect(result.current.autosaveFailed).toBe(false)
    expect(result.current.draftId).toBe('d1')
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/hooks/__tests__/useDraftAutoSave.state.test.tsx`
Expected: FAIL — `autosaveFailed`/`lastError` are `undefined` on the returned state.

- [ ] **Step 3: Implement the extension**

In `useDraftAutoSave.ts`:
- Add to the `AutoSaveState` interface: `autosavePending: boolean`, `autosaveFailed: boolean`, `lastError: Error | null`.
- Add state near the other `useState` calls:
  ```ts
  const [autosaveFailed, setAutosaveFailed] = useState(false)
  const [autosavePending, setAutosavePending] = useState(false)
  const [lastError, setLastError] = useState<Error | null>(null)
  ```
- In `performSave`, on entry set `setAutosaveFailed(false)`; in the success branch (inside `if (!isUnmountedRef.current)`) add `setAutosavePending(false)`; in the catch branch (inside `if (!isUnmountedRef.current)`) add `setAutosaveFailed(true); setLastError(error as Error); setAutosavePending(false)`.
- In the debounce `useEffect`, when the timer is scheduled (after the `hasMinimalData` guard) add `setAutosavePending(true)`, and in `saveNow`/`reset`/cleanup where the timer is cleared, add `setAutosavePending(false)`.
- Add the three fields to the returned object.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/web && pnpm vitest run src/hooks/__tests__/useDraftAutoSave.state.test.tsx`
Expected: PASS (2 tests). Also re-run any existing autosave test to confirm no regression:
`cd apps/web && pnpm vitest run src/hooks` (autosave-related files).

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/hooks/useDraftAutoSave.ts apps/web/src/hooks/__tests__/useDraftAutoSave.state.test.tsx
git commit -m "feat(web): expose autosavePending/autosaveFailed/lastError from useDraftAutoSave"
```

---

### Task 7: Loyalty Program editor — stay on record + SaveSplitButton + guard

**Files:**
- Modify: `apps/web/src/features/loyalty/pages/ProgramFormPage.tsx`
- Test: `apps/web/src/features/loyalty/pages/__tests__/ProgramFormPage.test.tsx`

**Interfaces:**
- Consumes: `useAfterSaveNavigation` (Task 1), `SaveSplitButton` (Task 4), `useUnsavedChangesGuard` + `confirmDiscard` (Task 5).

- [ ] **Step 1: Write the failing test** (extend the existing file)

```tsx
// Add to the existing react-router-dom + react-query mocks: capture the create onSuccess.
// Replace the useMutation mock so mutate invokes its onSuccess with a created record.
vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: () => ({ data: undefined, isLoading: false }),
    useMutation: () => ({
      mutate: (_vars: unknown, opts?: { onSuccess?: (d: unknown) => void }) => opts?.onSuccess?.({ id: 'prog-1' }),
      isPending: false,
    }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

it('navigates to the new program detail page after create (not the list)', () => {
  mockParams = {}
  render(<ProgramFormPage />)
  fireEvent.submit(screen.getByRole('button', { name: 'common:save' }).closest('form')!)
  expect(mockNavigate).toHaveBeenCalledWith('/pos/loyalty/programs/prog-1')
})
```

(Import `fireEvent` from `@testing-library/react`. Keep the existing render/title tests.)

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/features/loyalty/pages/__tests__/ProgramFormPage.test.tsx`
Expected: FAIL — create still navigates to `/pos/loyalty/programs`.

- [ ] **Step 3: Implement**

In `ProgramFormPage.tsx`:
- Add imports:
  ```ts
  import { useAfterSaveNavigation } from '@/hooks/useAfterSaveNavigation'
  import { useUnsavedChangesGuard, confirmDiscard } from '@/hooks/useUnsavedChangesGuard'
  import { SaveSplitButton } from '@/components/molecules/SaveSplitButton'
  ```
- After the `form` is created, add:
  ```ts
  const nav = useAfterSaveNavigation({
    recordPath: (rid) => `/pos/loyalty/programs/${rid}`,
    listPath: '/pos/loyalty/programs',
  })
  useUnsavedChangesGuard({ isDirty: form.formState.isDirty })
  const cancel = () => {
    if (!form.formState.isDirty || confirmDiscard(t('common:confirmation.unsavedChangesBody'))) {
      navigate('/pos/loyalty/programs')
    }
  }
  ```
- Change the create branch of `onSubmit` to consume the created id:
  ```ts
  createMutation.mutate(payload, {
    onSuccess: (created: { id: string }) => nav.goToRecord(created.id),
  })
  ```
  and the update branch to `onSuccess: () => nav.goToRecord(id)`.
- Replace the footer's submit `Button` with `<SaveSplitButton onPrimarySave={() => {}} onSaveAndClose={() => { closeIntentRef.current = true; form.handleSubmit(onSubmit)() }} isPending={isSaving} />`. Simpler for this editor (form buttons are inside the form): keep the primary as `type="submit"` (default) so RHF submit fires; for Save & Close set a ref then submit:
  ```tsx
  const closeIntentRef = useRef(false)
  // in onSubmit success, branch: const go = closeIntentRef.current ? nav.goToList : (id?:string)=>nav.goToRecord(id!)
  ```
  Wire `onSuccess` to: `closeIntentRef.current ? nav.goToList() : nav.goToRecord(created.id)`, then reset `closeIntentRef.current = false`.
- Update both Cancel buttons (header back arrow + footer) to call `cancel`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/web && pnpm vitest run src/features/loyalty/pages/__tests__/ProgramFormPage.test.tsx`
Expected: PASS (existing tests + the new create-destination test).

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/loyalty/pages/ProgramFormPage.tsx apps/web/src/features/loyalty/pages/__tests__/ProgramFormPage.test.tsx
git commit -m "feat(web): loyalty program editor stays on record after create + SaveSplitButton + guard"
```

---

### Task 8: Loyalty Member editor — stay on record + SaveSplitButton + guard

**Files:**
- Modify: `apps/web/src/features/loyalty/pages/MemberFormPage.tsx`
- Test: `apps/web/src/features/loyalty/pages/__tests__/MemberFormPage.test.tsx`

**Interfaces:** same primitives as Task 7.

- [ ] **Step 1: Write the failing test** (mirror Task 7 in the member test file)

```tsx
// Same useMutation mock returning { id: 'mem-1' } on create onSuccess.
it('navigates to the new member detail page after create (not the list)', () => {
  mockParams = {}
  render(<MemberFormPage />)
  fireEvent.submit(screen.getByRole('button', { name: 'common:save' }).closest('form')!)
  expect(mockNavigate).toHaveBeenCalledWith('/pos/loyalty/members/mem-1')
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/features/loyalty/pages/__tests__/MemberFormPage.test.tsx`
Expected: FAIL — create still navigates to `/pos/loyalty/members`.

- [ ] **Step 3: Implement**

Apply the same changes as Task 7 to `MemberFormPage.tsx`, with member paths:
- `nav = useAfterSaveNavigation({ recordPath: (rid) => `/pos/loyalty/members/${rid}`, listPath: '/pos/loyalty/members' })`
- create `onSuccess: (created: { id: string }) => closeIntentRef.current ? nav.goToList() : nav.goToRecord(created.id)`
- update `onSuccess: () => nav.goToRecord(id)`
- `useUnsavedChangesGuard({ isDirty: form.formState.isDirty })`, `cancel` helper, replace submit button with `<SaveSplitButton>`, wire both Cancel controls to `cancel`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/web && pnpm vitest run src/features/loyalty/pages/__tests__/MemberFormPage.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/loyalty/pages/MemberFormPage.tsx apps/web/src/features/loyalty/pages/__tests__/MemberFormPage.test.tsx
git commit -m "feat(web): loyalty member editor stays on record after create + SaveSplitButton + guard"
```

---

### Task 9: Product editor — stay on record + single Save split button (Publish removed)

**Files:**
- Modify: `apps/web/src/features/inventory/ProductForm.tsx`
- Test: `apps/web/src/features/inventory/ProductForm.test.tsx`

**Interfaces:** consumes Tasks 1, 4, 5. Product create mutation returns `Product` (has `.id`).

**Context:** Currently the header has two `type="submit" form="product-editor-form"` buttons (`saveDraft`, `publish`) and `onSubmit` navigates to `/inventory/products` on create (the bug) and `/inventory/products/${id}` on update. The create mutation's `onSuccess(newProduct)` already awaits buffered-image upload before resolving, so navigating after `mutateAsync` is safe.

- [ ] **Step 1: Write the failing test**

```tsx
// Drive a create submit and assert navigation to the new product's detail route.
// (Match the existing ProductForm.test.tsx mock setup for navigate + mutations;
//  mock the create mutation so mutateAsync resolves to { id: 'prod-9' }.)
it('navigates to the new product detail page after create', async () => {
  mockParams = {}
  render(<ProductForm />)
  fireEvent.submit(document.getElementById('product-editor-form') as HTMLFormElement)
  await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith('/inventory/products/prod-9'))
})

it('shows a single Save action (no separate Publish) in nav-only phase', () => {
  mockParams = {}
  render(<ProductForm />)
  expect(screen.queryByText('catalog:editor.actions.publish')).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'catalog:editor.actions.save' })).toBeInTheDocument()
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/features/inventory/ProductForm.test.tsx`
Expected: FAIL — create navigates to `/inventory/products`; Publish button still present.

- [ ] **Step 3: Implement**

In `ProductForm.tsx`:
- Imports: `useAfterSaveNavigation`, `useUnsavedChangesGuard` + `confirmDiscard`, `SaveSplitButton`.
- Add `const nav = useAfterSaveNavigation({ recordPath: (rid) => `/inventory/products/${rid}`, listPath: '/inventory/products' })`.
- Add `useUnsavedChangesGuard({ isDirty: formState.isDirty })` (use the form's `formState.isDirty`).
- In `onSubmit`, change the create path: `const created = await createMutation.mutateAsync(data); ...; nav.goToRecord(created.id)` (replace `navigate('/inventory/products')` at the old line 302). Keep the update path navigating to `nav.goToRecord(id)`.
- Replace the two header submit buttons (`saveDraft` + `publish`, lines ~510–529) with a single:
  ```tsx
  <SaveSplitButton
    primaryLabel={t('catalog:editor.actions.save')}
    form="product-editor-form"
    isPending={isSubmitting}
  />
  ```
  (No `onSaveAndClose` for Phase 1 unless desired; if added, wire an intent ref + `(document.getElementById('product-editor-form') as HTMLFormElement).requestSubmit()` and branch nav to `nav.goToList()`.) Remove the now-unused `catalog:editor.actions.saveDraft`/`publish` button markup. Leave `BeforePublishChecklist` in the rail as-is (informational); it no longer gates a Publish action this phase.
- Update the header Cancel button onClick to: `() => { if (!formState.isDirty || confirmDiscard(t('common:confirmation.unsavedChangesBody'))) void navigate('/inventory/products') }`.
- Add an i18n key `catalog:editor.actions.save` = "Save" / "Enregistrer" / "حفظ" in the `catalog` namespace bundles (en/fr/ar) if not present.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/web && pnpm vitest run src/features/inventory/ProductForm.test.tsx`
Expected: PASS (existing + 2 new). Then run the broader product editor suite to confirm no regression:
`cd apps/web && pnpm vitest run src/features/inventory/__tests__ src/features/products/editor`

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/inventory/ProductForm.tsx apps/web/src/features/inventory/ProductForm.test.tsx apps/web/src/locales/*/catalog.json
git commit -m "feat(web): product editor stays on record after create; single Save (Publish deferred to §3)"
```

---

### Task 10: Documents editor — SaveSplitButton (Save & Close) + autosave-aware guard

**Files:**
- Modify: `apps/web/src/features/documents/DocumentForm.tsx`
- Test: `apps/web/src/features/documents/DocumentForm.test.tsx`

**Interfaces:** consumes Tasks 1, 4, 5, 6. Documents already navigate to detail on create (`${basePath}/${documentId}`, with `purchase_order` → `/edit`) — keep that. Add Save & Close + the autosave-aware guard.

- [ ] **Step 1: Write the failing test**

```tsx
// Assert the guard warns while a draft autosave is pending, and that Save & Close
// is available. Use the existing DocumentForm test harness/mocks.
it('renders a Save split button with a Save & Close option', () => {
  render(/* existing DocumentForm render helper, create mode */)
  fireEvent.click(screen.getByRole('button', { name: 'common:actions.openSaveMenu' }))
  expect(screen.getByRole('menuitem', { name: 'common:actions.saveAndClose' })).toBeInTheDocument()
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/features/documents/DocumentForm.test.tsx`
Expected: FAIL — no Save split menu yet.

- [ ] **Step 3: Implement**

In `DocumentForm.tsx`:
- Imports: `useAfterSaveNavigation`, `useUnsavedChangesGuard` + `confirmDiscard`, `SaveSplitButton`.
- Pull the new autosave state: `const { draftId: _draftId, isSaving, lastSavedAt, autosavePending, autosaveFailed } = useDraftAutoSave(...)` (replace the existing destructure).
- Build the dirty state and register the guard:
  ```ts
  const docDirty = {
    isDirty: form.formState.isDirty || (lines.length > 0 && !lastSavedAt),
    autosavePending,
    autosaveFailed,
  }
  useUnsavedChangesGuard(docDirty)
  ```
- Add `const nav = useAfterSaveNavigation({ recordPath: (rid) => `${basePath}/${rid}`, listPath: basePath })` (used for Save & Close → list). Keep the existing create/update onSuccess navigation untouched (including the `purchase_order` → `/edit` special case).
- Replace the footer submit `Button` (lines ~531–533) with:
  ```tsx
  <SaveSplitButton
    form={undefined}
    primaryType="submit"
    isPending={isSubmitInProgress}
    onSaveAndClose={() => { closeIntentRef.current = true; formRef.current?.requestSubmit() }}
  />
  ```
  Add `const closeIntentRef = useRef(false)` and a `formRef` on the `<form>` element. In both create and update `onSuccess`, after the existing navigation logic, branch: if `closeIntentRef.current` then `nav.goToList()` instead of the detail navigation, then reset the ref. (Save & Close overrides the default stay-on-record.)
- Update the footer Cancel `onClick` to `() => { if (!docDirty.isDirty || confirmDiscard(t('confirmation.unsavedChangesBody'))) void navigate(basePath) }`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apps/web && pnpm vitest run src/features/documents/DocumentForm.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/documents/DocumentForm.tsx apps/web/src/features/documents/DocumentForm.test.tsx
git commit -m "feat(web): document editor Save & Close + autosave-aware unsaved-changes guard"
```

---

### Task 11: Final verification

- [ ] **Step 1: Typecheck + lint + targeted tests**

Run:
```bash
cd apps/web
pnpm typecheck
pnpm lint
pnpm vitest run \
  src/hooks/__tests__/useAfterSaveNavigation.test.tsx \
  src/lib/__tests__/postSavePolicy.test.ts \
  src/locales/__tests__/saveActionKeys.test.ts \
  src/components/molecules/SaveSplitButton \
  src/hooks/__tests__/useUnsavedChangesGuard.test.tsx \
  src/hooks/__tests__/useDraftAutoSave.state.test.tsx \
  src/features/loyalty/pages/__tests__ \
  src/features/inventory/ProductForm.test.tsx \
  src/features/documents/DocumentForm.test.tsx
```
Expected: typecheck clean, lint 0 errors, all listed tests PASS.

- [ ] **Step 2: Manual smoke (optional, via the run skill)** — create a Loyalty Program and a Product; confirm you land on the record detail page (not the list) and a success toast shows.

---

## Self-Review

**Spec coverage:**
- §2 canonical model (Save split button, separate lifecycle) → Tasks 4, 9 (Publish removed for Product per v3-A; lifecycle stays for Documents untouched). ✓
- §3 nav contract (create/update → `/:id`; Save & Close → list; Cancel guard) → Tasks 1, 7–10. ✓
- §4 entity matrix (Product nav-only; Loyalty nav-only; Documents existing lifecycle) → Tasks 7–10. ✓
- §5.1 hook intent/destination + cache (fetch-on-destination; Product navigates after image side effects) → Tasks 1, 9. ✓
- §5.2 SaveSplitButton presentational + a11y → Task 4. ✓
- §5.3 BrowserRouter guard + dirtyState → Tasks 5, 6, 10. ✓
- §6 registry → Task 2. ✓
- §8 i18n keys → Task 3 (+ catalog `save` in Task 9). ✓
- §9 exact-destination tests → Tasks 7–10; a11y → Task 4. ✓
- v3-A Product nav-only / hide Publish → Task 9. ✓
- v3-C requestSubmit intent mechanism → Tasks 9 (option), 10. ✓
- v3-D useDraftAutoSave extension → Task 6. ✓

**Placeholder scan:** Each code step shows real code; editor steps reference exact files/lines and provide the concrete edits. The one conditional ("if `Button` doesn't forward refs") names the exact fallback. No "TBD"/"handle edge cases".

**Type consistency:** `AfterSaveNav` (`goToRecord`/`goToNew`/`goToList`), `DirtyState` (`isDirty`/`autosavePending`/`autosaveFailed`), `SaveSplitButtonProps`, and the extended `AutoSaveState` fields are used identically across producing and consuming tasks.

**Known small risk to confirm during execution:** the `Button` atom's `ref`/`form`/`aria-*` forwarding (Task 4 note) and whether loyalty create hooks pass the created record through to the component-level `onSuccess` (Task 7 — verify `createProgram`/`createMember` resolve the entity). Both have explicit fallbacks in-task.
