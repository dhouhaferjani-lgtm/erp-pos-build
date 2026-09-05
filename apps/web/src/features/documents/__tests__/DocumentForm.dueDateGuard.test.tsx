/**
 * DocumentForm — the due date may not precede the issue date.
 *
 * DEV-QA-008 / DEV-QA-057, gate r1 finding F5.5. PR #212 added a client-side
 * mirror of the backend guard (a native `min` bound to the issue date plus an
 * RHF `validate`), but the only coverage was `e2e/document-due-date-guard.spec.ts`
 * — a Playwright spec that is eslint-ignored, needs a live stack, and asserts
 * hardcoded English UI strings. This file pins the same two guarantees as a unit
 * test that runs in `pnpm test`:
 *
 *   1. the `min` attribute tracks the issue-date field, so the native picker
 *      cannot even offer an earlier day;
 *   2. `handleSubmit` refuses an earlier due date, renders the localized message,
 *      and fires NO mutation — and lets the equality boundary through, matching
 *      the server's `>=` semantics (`DueDateNotBeforeDocumentDate`).
 *
 * The backend remains authoritative: `DocumentDueDateGuardTest` is the guard that
 * actually protects the row.
 */

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render, screen, fireEvent, waitFor } from '@testing-library/react'
import { DocumentForm } from '../DocumentForm'
import type { DocumentLine } from '@/components/documents/DocumentLineEditor'

const draftAutoSaveState = vi.hoisted(() => ({
  draftId: undefined as string | undefined,
  isSaving: false,
  lastSavedAt: null as Date | null,
  autosavePending: false,
  autosaveFailed: false,
}))

const routerState = vi.hoisted(() => ({ id: '', pathname: '/sales/quotes/new', search: '' }))

const reactQueryState = vi.hoisted(() => ({
  document: undefined as unknown,
  mutationPayloads: [] as unknown[],
}))

const partnerA = vi.hoisted(() => ({
  id: 'partner-1',
  name: 'Partner A',
  type: 'customer' as const,
  email: 'partner-a@example.test',
  city: 'Tunis',
}))

const stubLine = vi.hoisted(() => ({
  value: {
    id: 'line-1',
    product_id: 'prod-1',
    product_name: 'Crème hydratante',
    description: 'Crème hydratante',
    quantity: '1',
    unit_price: '100.000',
    tax_rate: '7.00',
    line_total: '100.000',
    price_entry_mode: 'unit' as const,
  },
}))

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string) => (typeof fallback === 'string' ? fallback : key),
  }),
}))

const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useParams: () => ({ id: routerState.id }),
  useLocation: () => ({ pathname: routerState.pathname, search: routerState.search }),
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: (options: { queryKey?: readonly unknown[] }) => {
      const queryKey = options.queryKey ?? []
      if (queryKey[0] === 'pickers' && queryKey[1] === 'partner') {
        return { data: [partnerA], isLoading: false, isError: false }
      }
      if (queryKey[0] === 'partner') {
        return { data: partnerA, isLoading: false, isError: false }
      }
      return { data: reactQueryState.document, isLoading: false, isError: false }
    },
    useMutation: () => ({
      mutate: vi.fn((payload: unknown) => {
        reactQueryState.mutationPayloads.push(payload)
      }),
      isPending: false,
    }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

vi.mock('../../../hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { currency: 'TND' } }),
}))

vi.mock('../../../hooks/useDraftAutoSave', () => ({
  useDraftAutoSave: () => draftAutoSaveState,
}))

vi.mock('../../../components/documents/DocumentLineEditor', () => ({
  DocumentLineEditor: ({
    lines,
    onChange,
  }: {
    lines: DocumentLine[]
    onChange: (lines: DocumentLine[]) => void
  }) => (
    <div data-testid="line-editor">
      <button
        type="button"
        onClick={() => {
          onChange([...lines, { ...stubLine.value } as DocumentLine])
        }}
      >
        Add mocked line
      </button>
    </div>
  ),
}))

vi.mock('../components/PurchaseOrderAdditionalCosts', () => ({
  PurchaseOrderAdditionalCosts: () => null,
}))

/** The RHF message the guard renders — the `t()` mock echoes the key. */
const GUARD_MESSAGE = 'sales:documents.dueDateBeforeIssue'

function dateInputs(container: HTMLElement): { issue: HTMLInputElement; due: HTMLInputElement } {
  const issue = container.querySelector('#issue_date')
  const due = container.querySelector('#due_date')

  if (!(issue instanceof HTMLInputElement) || !(due instanceof HTMLInputElement)) {
    throw new Error('Issue date and due date inputs must both render on the document form.')
  }

  return { issue, due }
}

/**
 * Submit the form the way the Save button does.
 *
 * `fireEvent.submit()` on the `<form>` rather than a click on the Save button:
 * the button IS `type="submit"` inside the form (`SaveSplitButton.tsx:69` with
 * the default `primaryType`), but neither `fireEvent.click` nor
 * `userEvent.click` dispatches a submit event for it under jsdom 27 — probed on
 * this tree, a click leaves the form untouched while `fireEvent.submit` produces
 * the full RHF error set (partner required + this guard). Submitting the form
 * exercises the same `handleSubmit(onSubmit)` handler (`DocumentForm.tsx:540`).
 */
function submit(container: HTMLElement): void {
  const form = container.querySelector('form')

  if (!(form instanceof HTMLFormElement)) {
    throw new Error('The document form must render a <form> element.')
  }

  fireEvent.submit(form)
}

describe('DocumentForm — due date may not precede the issue date (DEV-QA-008/057)', () => {
  beforeEach(() => {
    draftAutoSaveState.draftId = undefined
    routerState.id = ''
    routerState.pathname = '/sales/quotes/new'
    reactQueryState.document = undefined
    reactQueryState.mutationPayloads = []
    mockNavigate.mockClear()
  })

  it('binds the due-date `min` attribute to the issue date as it is typed', () => {
    const { container } = render(<DocumentForm documentType="quote" />)
    const { issue, due } = dateInputs(container)

    fireEvent.change(issue, { target: { value: '2026-09-10' } })

    expect(due).toHaveAttribute('min', '2026-09-10')

    // It TRACKS the field — a later issue date must move the floor with it.
    fireEvent.change(issue, { target: { value: '2026-10-01' } })
    expect(due).toHaveAttribute('min', '2026-10-01')
  })

  it('blocks the submit and shows the localized message when the due date is earlier', async () => {
    const { container } = render(<DocumentForm documentType="quote" />)
    const { issue, due } = dateInputs(container)

    fireEvent.click(screen.getByRole('button', { name: 'Add mocked line' }))
    fireEvent.change(issue, { target: { value: '2026-09-10' } })
    fireEvent.change(due, { target: { value: '2026-09-05' } })
    submit(container)

    await waitFor(() => {
      expect(screen.getByText(GUARD_MESSAGE)).toBeInTheDocument()
    })

    expect(reactQueryState.mutationPayloads).toHaveLength(0)
  })

  it('does not fire the guard when the due date equals the issue date (server semantics are >=)', async () => {
    const { container } = render(<DocumentForm documentType="quote" />)
    const { issue, due } = dateInputs(container)

    fireEvent.click(screen.getByRole('button', { name: 'Add mocked line' }))
    fireEvent.change(issue, { target: { value: '2026-09-10' } })
    fireEvent.change(due, { target: { value: '2026-09-10' } })
    submit(container)

    await waitFor(() => {
      expect(screen.queryByText(GUARD_MESSAGE)).not.toBeInTheDocument()
    })
  })

  it('does not fire the guard when no due date was entered at all', async () => {
    const { container } = render(<DocumentForm documentType="quote" />)
    const { issue } = dateInputs(container)

    fireEvent.click(screen.getByRole('button', { name: 'Add mocked line' }))
    fireEvent.change(issue, { target: { value: '2026-09-10' } })
    submit(container)

    await waitFor(() => {
      expect(screen.queryByText(GUARD_MESSAGE)).not.toBeInTheDocument()
    })
  })
})
