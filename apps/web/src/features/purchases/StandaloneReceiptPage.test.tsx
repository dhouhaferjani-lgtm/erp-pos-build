import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { toast } from 'sonner'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { renderWithProviders } from '../../test/renderWithProviders'
import { useAuthStore } from '../../stores/authStore'
import { useCompanyStore } from '../../stores/companyStore'

import { StandaloneReceiptPage } from './StandaloneReceiptPage'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())
const mockTranslate = vi.hoisted(() => vi.fn((key: string) => key))

vi.mock('../../lib/api', async () => {
  const actual = await vi.importActual<typeof import('../../lib/api')>('../../lib/api')
  return {
    ...actual,
    api: {
      ...actual.api,
      get: mockApiGet,
      post: mockApiPost,
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
  useTranslation: () => ({ t: mockTranslate }),
}))

vi.mock('sonner', () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
  },
}))

function setTenant() {
  useAuthStore.setState({
    user: {
      id: 'user-1',
      name: 'Receiver',
      email: 'receiver@example.com',
      tenant_id: 'tenant-A',
      roles: ['admin'],
      email_verified_at: null,
    },
    token: 'token',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({
    currentCompanyId: 'company-1',
    companies: [{
      id: 'company-1',
      name: 'Company',
      legalName: 'Company LLC',
      taxId: null,
      countryCode: 'TN',
      currency: 'TND',
      locale: 'fr_TN',
      timezone: 'Africa/Tunis',
    }],
    isLoading: false,
  })
}

describe('StandaloneReceiptPage', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    setTenant()
    let uuidCounter = 0
    vi.stubGlobal('crypto', { randomUUID: () => `uuid-${++uuidCounter}` })
    mockApiGet.mockImplementation((url: string) => {
      if (url.startsWith('/partners')) {
        return Promise.resolve({ data: { data: [{ id: 'supplier-1', name: 'Supplier A' }] } })
      }
      if (url.startsWith('/products')) {
        return Promise.resolve({ data: { data: [{ id: 'product-1', name: 'Product A', sku: 'PA', quantity_decimals: 4 }] } })
      }
      if (url.startsWith('/locations')) {
        return Promise.resolve({ data: { data: [{ id: 'location-1', name: 'Warehouse' }] } })
      }
      if (url.startsWith('/procurement-policies')) {
        return Promise.resolve({ data: { data: { allow_receipt_first: true } } })
      }
      return Promise.resolve({ data: { data: [] } })
    })
    mockApiPost.mockResolvedValue({
      data: {
        data: {
          purchase_order: { id: 'po-1' },
          goods_receipt: { id: 'gr-1', status: 'draft' },
        },
      },
    })
  })

  it('renders a scan-instead link with the locked supplier_delivery_note kind', () => {
    renderWithProviders(<StandaloneReceiptPage />)

    expect(screen.getByRole('link', { name: 'common:actions.back' })).toHaveAttribute('href', '/purchases/receipts')
    expect(screen.getByRole('link', { name: 'documentIngestions:actions.scanInstead' })).toHaveAttribute(
      'href',
      '/purchases/scans/new?kind=supplier_delivery_note',
    )
  })

  it('confirms before following the breadcrumb from a dirty draft', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const user = userEvent.setup()
    renderWithProviders(<StandaloneReceiptPage />)

    await screen.findByRole('option', { name: 'Supplier A' })
    await user.selectOptions(screen.getByLabelText('purchases:standaloneReceipt.fields.supplier'), 'supplier-1')
    await user.click(screen.getByRole('link', { name: 'common:actions.back' }))

    expect(confirmSpy).toHaveBeenCalledWith('confirmation.unsavedChangesBody')
    confirmSpy.mockRestore()
  })

  it('renders receipt lines in the shared DataTable shell', async () => {
    renderWithProviders(<StandaloneReceiptPage />)

    const table = await screen.findByRole('table')
    expect(table).toHaveClass('border-collapse')
    expect(table).not.toHaveClass('divide-y')
  })

  it('submits string qty and money values to the standalone receipt endpoint', async () => {
    const user = userEvent.setup()
    renderWithProviders(<StandaloneReceiptPage />)

    await screen.findByRole('option', { name: 'Supplier A' })
    await screen.findByRole('option', { name: 'Warehouse' })
    await screen.findByRole('option', { name: 'PA - Product A' })

    await user.selectOptions(screen.getByLabelText('purchases:standaloneReceipt.fields.supplier'), 'supplier-1')
    await user.selectOptions(screen.getByLabelText('purchases:standaloneReceipt.fields.location'), 'location-1')
    await user.type(screen.getByLabelText('purchases:standaloneReceipt.fields.blNumber'), 'BL-7788')
    await user.type(screen.getByLabelText('purchases:standaloneReceipt.fields.blDate'), '2026-07-05')
    await user.selectOptions(screen.getByLabelText('purchases:standaloneReceipt.fields.product'), 'product-1')
    await user.clear(screen.getByLabelText('purchases:standaloneReceipt.fields.quantity'))
    await user.type(screen.getByLabelText('purchases:standaloneReceipt.fields.quantity'), '3.1250')
    await user.clear(screen.getByLabelText('purchases:standaloneReceipt.fields.unitPrice'))
    await user.type(screen.getByLabelText('purchases:standaloneReceipt.fields.unitPrice'), '7.250')

    await user.click(screen.getByRole('button', { name: 'purchases:standaloneReceipt.actions.saveDraft' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/goods-receipts/standalone', {
        supplier_id: 'supplier-1',
        location_id: 'location-1',
        idempotency_key: expect.stringMatching(/^uuid-\d+$/),
        external_reference: 'BL-7788',
        external_date: '2026-07-05',
        post_immediately: false,
        lines: [{
          product_id: 'product-1',
          variant_id: null,
          qty: '3.1250',
          free_qty: '0.0000',
          unit_price: '7.250',
        }],
      })
    })
    expect(toast.success).toHaveBeenCalledWith('purchases:standaloneReceipt.toast.draftCreated')
    expect(mockNavigate).toHaveBeenCalledWith('/purchases/orders/po-1')
  })

  it('reuses the same idempotency key after a failed submit and mints a new one after success', async () => {
    const user = userEvent.setup()
    mockApiPost
      .mockRejectedValueOnce(new Error('network failed'))
      .mockResolvedValue({
        data: {
          data: {
            purchase_order: { id: 'po-1' },
            goods_receipt: { id: 'gr-1', status: 'draft' },
          },
        },
      })

    renderWithProviders(<StandaloneReceiptPage />)

    await fillStandaloneReceiptForm(user)

    const saveButton = screen.getByRole('button', { name: 'purchases:standaloneReceipt.actions.saveDraft' })
    await user.click(saveButton)
    await waitFor(() => {
      expect(toast.error).toHaveBeenCalledWith('network failed')
    })

    await user.click(saveButton)
    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledTimes(2)
    })

    const firstKey = mockApiPost.mock.calls[0]?.[1].idempotency_key
    const secondKey = mockApiPost.mock.calls[1]?.[1].idempotency_key
    expect(secondKey).toBe(firstKey)

    await user.click(saveButton)
    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledTimes(3)
    })
    expect(mockApiPost.mock.calls[2]?.[1].idempotency_key).not.toBe(firstKey)
  })

  it('shows a disabled policy notice instead of the form when receipt first is disabled', async () => {
    mockApiGet.mockImplementation((url: string) => {
      if (url.startsWith('/procurement-policies')) {
        return Promise.resolve({ data: { data: { allow_receipt_first: false } } })
      }
      if (url.startsWith('/partners')) {
        return Promise.resolve({ data: { data: [{ id: 'supplier-1', name: 'Supplier A' }] } })
      }
      if (url.startsWith('/products')) {
        return Promise.resolve({ data: { data: [{ id: 'product-1', name: 'Product A', sku: 'PA', quantity_decimals: 4 }] } })
      }
      if (url.startsWith('/locations')) {
        return Promise.resolve({ data: { data: [{ id: 'location-1', name: 'Warehouse' }] } })
      }
      return Promise.resolve({ data: { data: [] } })
    })

    renderWithProviders(<StandaloneReceiptPage />)

    expect(await screen.findByText('purchases:standaloneReceipt.policyDisabled')).toBeInTheDocument()
    expect(screen.queryByLabelText('purchases:standaloneReceipt.fields.supplier')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'purchases:standaloneReceipt.actions.saveDraft' })).not.toBeInTheDocument()
  })
})

async function fillStandaloneReceiptForm(user: ReturnType<typeof userEvent.setup>) {
  await screen.findByRole('option', { name: 'Supplier A' })
  await screen.findByRole('option', { name: 'Warehouse' })
  await screen.findByRole('option', { name: 'PA - Product A' })

  await user.selectOptions(screen.getByLabelText('purchases:standaloneReceipt.fields.supplier'), 'supplier-1')
  await user.selectOptions(screen.getByLabelText('purchases:standaloneReceipt.fields.location'), 'location-1')
  await user.type(screen.getByLabelText('purchases:standaloneReceipt.fields.blNumber'), 'BL-7788')
  await user.type(screen.getByLabelText('purchases:standaloneReceipt.fields.blDate'), '2026-07-05')
  await user.selectOptions(screen.getByLabelText('purchases:standaloneReceipt.fields.product'), 'product-1')
  await user.clear(screen.getByLabelText('purchases:standaloneReceipt.fields.quantity'))
  await user.type(screen.getByLabelText('purchases:standaloneReceipt.fields.quantity'), '3.1250')
  await user.clear(screen.getByLabelText('purchases:standaloneReceipt.fields.unitPrice'))
  await user.type(screen.getByLabelText('purchases:standaloneReceipt.fields.unitPrice'), '7.250')
}
