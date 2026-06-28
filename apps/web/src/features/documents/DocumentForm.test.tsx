import { describe, it, expect, vi } from 'vitest'
import { render, screen, fireEvent } from '@testing-library/react'
import { DocumentForm, computeLinesDirty } from './DocumentForm'

// i18n → return the key so assertions are deterministic
vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string, fallback?: string) => fallback ?? key }),
}))

// router
const mockNavigate = vi.fn()
vi.mock('react-router-dom', () => ({
  useNavigate: () => mockNavigate,
  useParams: () => ({ id: '' }),
  useLocation: () => ({ pathname: '/sales/invoices/new', search: '' }),
  Link: ({ to, children, ...props }: { to: string; children: React.ReactNode; className?: string }) => (
    <a href={to} {...props}>{children}</a>
  ),
}))

// decouple from network — the form's own queries/mutations
vi.mock('@tanstack/react-query', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@tanstack/react-query')>()
  return {
    ...actual,
    useQuery: () => ({ data: undefined, isLoading: false }),
    useMutation: () => ({ mutate: vi.fn(), isPending: false }),
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
  }
})

vi.mock('../../hooks/useCompany', () => ({
  useCompany: () => ({ currentCompany: { currency: 'TND' } }),
}))

vi.mock('../../hooks/useDraftAutoSave', () => ({
  useDraftAutoSave: () => ({ draftId: undefined, isSaving: false, lastSavedAt: null, autosavePending: false, autosaveFailed: false }),
}))

// child components that fetch / render heavy trees — stub them out
vi.mock('../../components/documents/DocumentLineEditor', () => ({
  DocumentLineEditor: () => <div data-testid="line-editor" />,
}))
vi.mock('../../components/ui/PartnerSearchSelect', () => ({
  PartnerSearchSelect: () => <div data-testid="partner-select" />,
}))
vi.mock('../../components/organisms', () => ({
  AddPartnerModal: () => null,
}))

// ---------------------------------------------------------------------------
// Bug 3 — unit tests for the pure linesDirty helper
// ---------------------------------------------------------------------------
describe('computeLinesDirty', () => {
  const line = { id: 'a', product_id: 'p', quantity: 1, unit_price: 10, tax_rate: 0, line_total: 10 }

  it('returns false when no lines and never saved (blank new doc)', () => {
    expect(computeLinesDirty(null, [])).toBe(false)
  })

  it('returns true when lines exist and never autosaved', () => {
    expect(computeLinesDirty(null, [line])).toBe(true)
  })

  it('returns true when all lines cleared after a successful autosave', () => {
    // autosave had one line, user removed it → snapshot mismatch → guard fires
    expect(computeLinesDirty(JSON.stringify([line]), [])).toBe(true)
  })

  it('returns false when lines match the saved snapshot', () => {
    expect(computeLinesDirty(JSON.stringify([line]), [line])).toBe(false)
  })

  it('returns true when lines differ from saved snapshot', () => {
    const line2 = { ...line, id: 'b' }
    expect(computeLinesDirty(JSON.stringify([line]), [line, line2])).toBe(true)
  })
})

describe('DocumentForm (canonical layout)', () => {
  it('renders a single page-level heading with the entity name', () => {
    render(<DocumentForm documentType="invoice" />)
    const h1s = screen.getAllByRole('heading', { level: 1 })
    expect(h1s).toHaveLength(1)
    expect(h1s[0]).toHaveTextContent(/invoice/i)
  })

  it('groups fields under a section heading', () => {
    render(<DocumentForm documentType="invoice" />)
    // Canonical forms section their fields under an <h2>; the original flat
    // form had none. This is the red→green driver for the migration.
    expect(screen.getByRole('heading', { level: 2 })).toBeInTheDocument()
  })

  it('renders labelled form fields via FormField', () => {
    render(<DocumentForm documentType="invoice" />)
    expect(
      screen.getByLabelText('sales:documents.issueDate', { exact: false }),
    ).toBeInTheDocument()
    expect(
      screen.getByLabelText('sales:documents.notes', { exact: false }),
    ).toBeInTheDocument()
  })

  it('renders a submit button', () => {
    render(<DocumentForm documentType="invoice" />)
    const save = screen.getByRole('button', { name: 'actions.save' })
    expect(save).toHaveAttribute('type', 'submit')
  })

  it('renders a Save split button with a Save & Close option', () => {
    render(<DocumentForm documentType="invoice" />)
    fireEvent.click(screen.getByRole('button', { name: 'actions.openSaveMenu' }))
    expect(screen.getByRole('menuitem', { name: 'actions.saveAndClose' })).toBeInTheDocument()
  })
})
