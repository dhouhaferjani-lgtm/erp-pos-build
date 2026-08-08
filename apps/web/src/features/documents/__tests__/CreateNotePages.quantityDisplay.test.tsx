import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import type { ReactNode } from 'react'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

import { CreateCreditNotePage } from '../CreateCreditNotePage'
import { CreateReturnNotePage } from '../CreateReturnNotePage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', async () => {
  const actual = await vi.importActual<typeof import('@/lib/api')>('@/lib/api')
  return {
    ...actual,
    api: {
      get: mockApiGet,
      post: vi.fn(),
    },
  }
})

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useNavigate: () => mockNavigate,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, fallback?: string | { defaultValue?: string }) => {
      if (typeof fallback === 'string') return fallback
      return fallback?.defaultValue ?? key
    },
  }),
}))

vi.mock('@/hooks/useCurrency', () => ({
  useCurrency: () => ({
    currency: 'TND',
    decimals: 3,
    toFixed: (value: number) => value.toFixed(3),
  }),
}))

vi.mock('@/components/molecules/pickers/InvoiceSearchSelect', () => ({
  InvoiceSearchSelect: ({ onChange }: { onChange: (invoice: { id: string; partner_id: string }) => void }) => (
    <button type="button" onClick={() => { onChange({ id: 'invoice-1', partner_id: 'partner-1' }); }}>
      choose-invoice
    </button>
  ),
}))

vi.mock('@/components/molecules/pickers/DeliveryNoteSearchSelect', () => ({
  DeliveryNoteSearchSelect: ({ onChange }: { onChange: (note: { id: string }) => void }) => (
    <button type="button" onClick={() => { onChange({ id: 'delivery-1' }); }}>
      choose-delivery-note
    </button>
  ),
}))

vi.mock('@/components/molecules/pickers/PartnerPicker', () => ({
  PartnerPicker: () => null,
}))

vi.mock('@/components/documents/DocumentLineEditor', () => ({
  DocumentLineEditor: () => null,
}))

vi.mock('../components/ReturnReasonSelect', () => ({ ReturnReasonSelect: () => null }))
vi.mock('../components/ReturnConditionSelect', () => ({ ReturnConditionSelect: () => null }))

function setTenant() {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'User',
      email: 'user@example.test',
      tenant_id: 'tenant-1',
      roles: [],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
}

function renderPage(page: ReactNode) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>{page}</MemoryRouter>
    </QueryClientProvider>,
  )
}

const precisionLine = {
  id: 'line-1',
  product_id: 'product-1',
  product_code: 'PCS-1',
  product_name: 'Precision part',
  description: 'Precision part',
  quantity: 1,
  quantity_decimals: 3,
  unit_price: '10.000',
  tax_rate: '0.00',
  total: '10.000',
}

beforeEach(() => {
  vi.clearAllMocks()
  setTenant()
  mockApiGet.mockImplementation(async (url: string) => {
    if (url === '/invoices/invoice-1') {
      return {
        data: {
          data: {
            id: 'invoice-1',
            partner_id: 'partner-1',
            document_number: 'INV-1',
            document_date: '2026-07-27',
            total: '10.000',
            lines: [precisionLine],
          },
        },
      }
    }
    if (url === '/delivery-notes/delivery-1') {
      return {
        data: {
          data: {
            id: 'delivery-1',
            document_number: 'DN-1',
            document_date: '2026-07-27',
            partner: { id: 'partner-1', name: 'Partner' },
            total: '10.000',
            lines: [precisionLine],
          },
        },
      }
    }
    return { data: { data: [] } }
  })
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('create note pages quantity display', () => {
  it('displays invoice quantities at unit precision on the credit-note page', async () => {
    const user = userEvent.setup()
    renderPage(<CreateCreditNotePage />)

    await user.click(screen.getByRole('button', { name: 'choose-invoice' }))
    await user.click(await screen.findByDisplayValue('partial'))

    expect(await screen.findByText('/ 1.000')).toBeInTheDocument()
  })

  it('displays delivery-note quantities at unit precision on the return-note page', async () => {
    const user = userEvent.setup()
    renderPage(<CreateReturnNotePage />)

    await user.click(screen.getByRole('button', { name: 'choose-delivery-note' }))
    await user.click(await screen.findByRole('button', { name: 'sales:returnNotes.form.partialReturn' }))

    expect(await screen.findByText('1.000')).toBeInTheDocument()
  })
})
