import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
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

import { AddPartnerModal, type AddPartnerModalProps } from './AddPartnerModal'

function buildModal(props: Partial<AddPartnerModalProps>) {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })

  return (
    <MemoryRouter>
      <QueryClientProvider client={queryClient}>
        <AddPartnerModal
          isOpen={props.isOpen ?? true}
          onClose={props.onClose ?? (() => undefined)}
          {...(props.partnerType !== undefined && { partnerType: props.partnerType })}
          {...(props.onSuccess !== undefined && { onSuccess: props.onSuccess })}
          {...(props.prefill !== undefined && { prefill: props.prefill })}
        />
      </QueryClientProvider>
    </MemoryRouter>
  )
}

function renderModal(props: Partial<AddPartnerModalProps>) {
  return render(buildModal(props))
}

describe('AddPartnerModal prefill', () => {
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

  it('maps PartnerPrefill onto the modal fields when opened', async () => {
    renderModal({
      isOpen: true,
      partnerType: 'supplier',
      prefill: {
        name: 'PharmaDistrib SARL',
        vat_number: 'TN1234567',
        phone: '71 234 567',
        email: 'contact@pharmadistrib.tn',
        street_address: '12 Rue de Carthage',
        city: 'Tunis',
        state: 'Tunis',
        postal_code: '1000',
        country_code: 'TN',
      },
    })
    expect(await screen.findByLabelText(/^name/i)).toHaveValue('PharmaDistrib SARL')
    expect(screen.getByLabelText(/tax/i)).toHaveValue('TN1234567') // vat_number → tax_id
    expect(screen.getByLabelText(/^address/i)).toHaveValue('12 Rue de Carthage') // street_address → address
    expect(screen.getByLabelText(/^city/i)).toHaveValue('Tunis')
    expect(screen.getByLabelText(/postal/i)).toHaveValue('1000')
    expect(screen.getByLabelText(/^country/i)).toHaveValue('TN')
    expect(screen.getByLabelText(/^email/i)).toHaveValue('contact@pharmadistrib.tn')
    expect(screen.getByLabelText(/^phone/i)).toHaveValue('71 234 567')
  })

  it('unchanged without prefill: all fields empty', async () => {
    renderModal({ isOpen: true, partnerType: 'supplier' })
    expect(await screen.findByLabelText(/^name/i)).toHaveValue('')
    expect(screen.getByLabelText(/tax/i)).toHaveValue('')
    expect(screen.getByLabelText(/^address/i)).toHaveValue('')
  })

  it('submits mapped values (never seeds notes or any commercial field)', async () => {
    const user = userEvent.setup()
    mockApiPost.mockResolvedValue({
      id: 'partner-1',
      name: 'PharmaDistrib SARL',
      type: 'supplier',
      email: null,
      phone: null,
      address: null,
      city: null,
      postal_code: null,
      country: null,
      tax_id: null,
      notes: null,
    })

    renderModal({
      isOpen: true,
      partnerType: 'supplier',
      prefill: {
        name: 'PharmaDistrib SARL',
        vat_number: 'TN1234567',
        street_address: '12 Rue de Carthage',
        city: 'Tunis',
        postal_code: '1000',
        country_code: 'TN',
      },
    })

    await screen.findByLabelText(/^name/i)
    await user.click(screen.getByRole('button', { name: /create/i }))

    expect(mockApiPost).toHaveBeenCalledTimes(1)
    expect(mockApiPost).toHaveBeenCalledWith(
      '/partners',
      expect.objectContaining({
        name: 'PharmaDistrib SARL',
        tax_id: 'TN1234567',
        address: '12 Rue de Carthage',
        city: 'Tunis',
        postal_code: '1000',
        country: 'TN',
        notes: '',
      }),
    )
    const payload: unknown = mockApiPost.mock.calls[0]?.[1]
    expect(payload).not.toHaveProperty('street_address')
    expect(payload).not.toHaveProperty('vat_number')
    expect(payload).not.toHaveProperty('state')
  })

  it('re-seeds from prefill each time the modal reopens', async () => {
    const user = userEvent.setup()
    const { rerender } = renderModal({
      isOpen: true,
      partnerType: 'supplier',
      prefill: { name: 'A' },
    })
    await user.clear(await screen.findByLabelText(/^name/i))
    rerender(buildModal({ isOpen: false, partnerType: 'supplier', prefill: { name: 'A' } }))
    rerender(buildModal({ isOpen: true, partnerType: 'supplier', prefill: { name: 'A' } }))
    expect(await screen.findByLabelText(/^name/i)).toHaveValue('A')
  })

  it('does not wipe user edits when the modal stays open and a caller passes a referentially-new but value-identical prefill', async () => {
    const user = userEvent.setup()
    const { rerender } = renderModal({
      isOpen: true,
      partnerType: 'supplier',
      prefill: { name: 'A' },
    })
    const nameInput = await screen.findByLabelText(/^name/i)
    await user.clear(nameInput)
    await user.type(nameInput, 'Edited')
    // Same value, but a brand-new object reference — simulates an inline
    // buildSupplierPrefill(...) call on an unrelated parent re-render while
    // the modal remains open.
    rerender(buildModal({ isOpen: true, partnerType: 'supplier', prefill: { name: 'A' } }))
    expect(screen.getByLabelText(/^name/i)).toHaveValue('Edited')
  })
})
