import { describe, it, expect, vi, beforeEach } from 'vitest'
import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { renderWithProviders } from '@/test/renderWithProviders'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { RecordDepositModal } from './RecordDepositModal'

const { mockApiPost, mockGetErrorMessage } = vi.hoisted(() => ({
  mockApiPost: vi.fn(),
  mockGetErrorMessage: vi.fn(() => 'An error occurred'),
}))

const mockApiInstance = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  patch: vi.fn(),
  delete: vi.fn(),
}))

vi.mock('../../lib/api', () => ({
  apiGet: vi.fn(),
  apiPost: mockApiPost,
  apiPatch: vi.fn(),
  apiDelete: vi.fn(),
  api: mockApiInstance,
  getErrorMessage: mockGetErrorMessage,
  isApiError: vi.fn(() => false),
}))

const mockToast = vi.hoisted(() => ({
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('sonner', () => ({ toast: mockToast }))

describe('RecordDepositModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()

    useAuthStore.setState({
      user: {
        id: 'user-1',
        name: 'Owner',
        email: 'o@x.test',
        tenant_id: 'tenant-A',
        roles: [],
        email_verified_at: null,
      },
      token: 'test-token',
      isAuthenticated: true,
      isLoading: false,
    })
    useCompanyStore.setState({
      currentCompanyId: 'company-1',
      companies: [
        {
          id: 'company-1',
          name: 'Test Company',
          legalName: 'Test Company LLC',
          taxId: null,
          countryCode: 'TN',
          currency: 'TND',
          locale: 'en_US',
          timezone: 'Africa/Tunis',
        },
      ],
      isLoading: false,
    })

    mockApiInstance.get.mockImplementation((url: string) => {
      if (url === '/payment-methods') {
        return Promise.resolve({ data: { data: [{ id: 'm1', code: 'CASH', name: 'Cash', is_active: true }] } })
      }
      if (url === '/payment-repositories') {
        return Promise.resolve({
          data: { data: [{ id: 'r1', code: 'DRAWER', name: 'Drawer 1', type: 'cash_register', is_active: true, is_default: true }] },
        })
      }
      return Promise.resolve({ data: { data: [] } })
    })
  })

  it('renders the deposit form fields when open', async () => {
    renderWithProviders(<RecordDepositModal isOpen onClose={vi.fn()} partnerId="p1" />)

    expect(screen.getByLabelText('Amount')).toBeInTheDocument()
    expect(screen.getByLabelText('Payment method')).toBeInTheDocument()
    expect(screen.getByLabelText('Treasury repository')).toBeInTheDocument()

    // Options load from the (mocked) endpoints.
    await waitFor(() => { expect(screen.getByRole('option', { name: 'Cash' })).toBeInTheDocument(); })
    expect(screen.getByRole('option', { name: 'Drawer 1' })).toBeInTheDocument()
  })

  it('disables submit until amount, method and repository are provided', async () => {
    renderWithProviders(<RecordDepositModal isOpen onClose={vi.fn()} partnerId="p1" />)

    const submit = screen.getByRole('button', { name: 'Record payment' })
    expect(submit).toBeDisabled()

    await waitFor(() => { expect(screen.getByRole('option', { name: 'Cash' })).toBeInTheDocument(); })
    await userEvent.type(screen.getByLabelText('Amount'), '120')
    await userEvent.selectOptions(screen.getByLabelText('Payment method'), 'CASH')
    await userEvent.selectOptions(screen.getByLabelText('Treasury repository'), 'r1')

    expect(submit).toBeEnabled()
  })

  it('posts the deposit and surfaces the allocation outcome on success', async () => {
    mockApiPost.mockResolvedValue({
      deposit_receipt_uuid: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
      amount: '120.000',
      currency_code: 'TND',
      settled_amount: '0.000',
      credited_amount: '120.000',
    })
    const onClose = vi.fn()

    renderWithProviders(<RecordDepositModal isOpen onClose={onClose} partnerId="p1" />)

    await waitFor(() => { expect(screen.getByRole('option', { name: 'Cash' })).toBeInTheDocument(); })
    await userEvent.type(screen.getByLabelText('Amount'), '120')
    await userEvent.selectOptions(screen.getByLabelText('Payment method'), 'CASH')
    await userEvent.selectOptions(screen.getByLabelText('Treasury repository'), 'r1')
    await userEvent.click(screen.getByRole('button', { name: 'Record payment' }))

    await waitFor(() => {
      expect(mockApiPost).toHaveBeenCalledWith('/partners/p1/deposits', {
        amount: '120',
        payment_method_code: 'CASH',
        repository_id: 'r1',
        currency: 'TND',
        note: null,
      })
    })
    expect(mockToast.success).toHaveBeenCalled()
    expect(onClose).toHaveBeenCalled()
  })
})
