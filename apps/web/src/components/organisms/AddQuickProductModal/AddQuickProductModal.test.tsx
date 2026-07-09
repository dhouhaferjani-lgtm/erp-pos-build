import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, cleanup } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', () => ({
  apiGet: mockApiGet,
  apiPost: mockApiPost,
}))

import { AddQuickProductModal, type AddQuickProductModalProps } from './AddQuickProductModal'

function buildModal(props: Partial<AddQuickProductModalProps>) {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })

  return (
    <QueryClientProvider client={queryClient}>
      <AddQuickProductModal
        isOpen={props.isOpen ?? true}
        onClose={props.onClose ?? (() => undefined)}
        {...(props.onSuccess !== undefined && { onSuccess: props.onSuccess })}
        {...(props.prefill !== undefined && { prefill: props.prefill })}
      />
    </QueryClientProvider>
  )
}

function renderModal(props: Partial<AddQuickProductModalProps>) {
  return render(buildModal(props))
}

describe('AddQuickProductModal prefill', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    mockApiGet.mockResolvedValue([])
    window.localStorage.setItem('autoerp-language', 'en')
    useAuthStore.setState({
      user: {
        id: 'user-1',
        name: 'Admin User',
        email: 'admin@example.test',
        tenant_id: 'tenant-1',
        roles: ['admin'],
        email_verified_at: '2026-01-01T00:00:00.000Z',
      },
      token: 'token',
      isAuthenticated: true,
      isLoading: false,
    })
    useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
  })

  afterEach(() => {
    cleanup()
  })

  it('seeds name, sale_price and tax_rate from prefill when opened', async () => {
    renderModal({
      isOpen: true,
      prefill: { name: 'Doliprane 1g', sale_price: '4.850', cost: '4.850', tax_rate: '7' },
    })
    expect(await screen.findByLabelText(/^name/i)).toHaveValue('Doliprane 1g')
    // sale_price is a type="number" input — jest-dom reports blank/populated
    // number inputs as a JS number (or null when blank), not the raw string.
    expect(screen.getByLabelText(/sale price/i)).toHaveValue(4.85)
    // sku stays empty — never prefilled
    expect(screen.getByLabelText(/^sku/i)).toHaveValue('')
  })

  it('behaves exactly as before when prefill is absent (all fields empty)', async () => {
    renderModal({ isOpen: true })
    expect(await screen.findByLabelText(/^name/i)).toHaveValue('')
    expect(screen.getByLabelText(/sale price/i)).toHaveValue(null)
  })

  it('re-seeds from prefill each time the modal reopens', async () => {
    const user = userEvent.setup()
    const { rerender } = renderModal({ isOpen: true, prefill: { name: 'A' } })
    // user edits then closes
    await user.clear(await screen.findByLabelText(/^name/i))
    rerender(buildModal({ isOpen: false, prefill: { name: 'A' } }))
    rerender(buildModal({ isOpen: true, prefill: { name: 'A' } }))
    expect(await screen.findByLabelText(/^name/i)).toHaveValue('A')
  })
})
