import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { screen, waitFor, fireEvent } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { AxiosError, type AxiosResponse } from 'axios'

import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { createTestQueryClient, renderWithProviders } from '@/test/renderWithProviders'
import { PriceListForm } from '../PriceListForm'

// ─── mocks ──────────────────────────────────────────────────────────────────
const mockCreatePriceList = vi.hoisted(() => vi.fn())
const mockUpdatePriceList = vi.hoisted(() => vi.fn())
const mockFetchPriceList = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())

vi.mock('../api', () => ({
  createPriceList: mockCreatePriceList,
  updatePriceList: mockUpdatePriceList,
  fetchPriceList: mockFetchPriceList,
}))

vi.mock('react-router-dom', async () => {
  const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom')
  return {
    ...actual,
    useParams: () => ({ id: undefined }), // create mode
    useNavigate: () => mockNavigate,
    Link: ({ children }: { children: React.ReactNode }) => children,
  }
})

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (k: string, fallback?: string) => fallback ?? k }),
}))

function setTenant() {
  useAuthStore.setState({
    user: { id: 'u1', name: 'T', email: 't@t', tenant_id: 'tenant-A', roles: [], email_verified_at: null },
    token: 'tok',
    isAuthenticated: true,
    isLoading: false,
  })
  useCompanyStore.setState({ currentCompanyId: 'company-1', companies: [], isLoading: false })
}

function make422(errors: Record<string, string[]>): AxiosError {
  const err = new AxiosError('Request failed with status code 422', 'ERR_BAD_REQUEST')
  err.response = {
    status: 422,
    statusText: 'Unprocessable Content',
    headers: {},
    config: {} as AxiosResponse['config'],
    data: { error: { code: 'VALIDATION_ERROR', message: 'Validation failed', errors } },
  }
  return err
}

async function fillRequired() {
  await userEvent.type(screen.getByLabelText(/Code/), 'PL-1')
  await userEvent.type(screen.getByLabelText(/Name/), 'Retail')
}

beforeEach(() => {
  mockCreatePriceList.mockReset()
  mockUpdatePriceList.mockReset()
  mockFetchPriceList.mockReset()
  mockNavigate.mockReset()
  setTenant()
})

afterEach(() => {
  useAuthStore.setState({ user: null, token: null, isAuthenticated: false, isLoading: false })
  useCompanyStore.setState({ currentCompanyId: null, companies: [], isLoading: false })
})

describe('PriceListForm — DEV-QA-047 create response shape', () => {
  it('navigates to the new price list using the id from the (already-unwrapped) response', async () => {
    // apiPost unwraps response.data.data, so createPriceList resolves the PriceList directly — id at the top level.
    mockCreatePriceList.mockResolvedValue({ id: 'pl-new', code: 'PL-1', name: 'Retail' })
    renderWithProviders(<PriceListForm />, { queryClient: createTestQueryClient() })

    await fillRequired()
    await userEvent.click(screen.getByRole('button', { name: /save/i }))

    await waitFor(() => {
      expect(mockNavigate).toHaveBeenCalledWith('/pricing/price-lists/pl-new')
    })
    // Regression guard: must not read a non-existent nested `.data.id`.
    expect(mockNavigate).not.toHaveBeenCalledWith('/pricing/price-lists/undefined')
  })
})

describe('PriceListForm — DEV-QA-015 date-range validation', () => {
  it('blocks submit and shows a visible error when valid_until is before valid_from', async () => {
    mockCreatePriceList.mockResolvedValue({ id: 'pl-new' })
    renderWithProviders(<PriceListForm />, { queryClient: createTestQueryClient() })

    await fillRequired()
    fireEvent.change(screen.getByLabelText(/Valid From/), { target: { value: '2026-12-31' } })
    fireEvent.change(screen.getByLabelText(/Valid Until/), { target: { value: '2026-01-01' } })
    await userEvent.click(screen.getByRole('button', { name: /save/i }))

    expect(await screen.findByText(/Valid Until must be after Valid From/i)).toBeInTheDocument()
    expect(mockCreatePriceList).not.toHaveBeenCalled()
  })

  it('surfaces a backend 422 date-range error inline instead of failing silently', async () => {
    mockCreatePriceList.mockRejectedValue(
      make422({ valid_until: ['The valid until field must be a date after valid from.'] }),
    )
    renderWithProviders(<PriceListForm />, { queryClient: createTestQueryClient() })

    await fillRequired()
    await userEvent.click(screen.getByRole('button', { name: /save/i }))

    expect(
      await screen.findByText('The valid until field must be a date after valid from.'),
    ).toBeInTheDocument()
  })
})

describe('PriceListForm — gate r1 F-6: no server 422 message is silently dropped', () => {
  it('renders inline errors for the fields that previously had no renderer', async () => {
    // applyServerErrors writes to 8 fields; pre-fix only code/name/valid_until
    // had an inline renderer, so a 422 on currency/description/valid_from/
    // is_active/is_default went into react-hook-form state and was never shown.
    mockCreatePriceList.mockRejectedValue(
      make422({
        currency: ['The selected currency is invalid.'],
        description: ['The description may not be greater than 500 characters.'],
        valid_from: ['The valid from field must be a valid date.'],
        is_default: ['A default price list already exists for this currency.'],
      }),
    )
    renderWithProviders(<PriceListForm />, { queryClient: createTestQueryClient() })

    await fillRequired()
    await userEvent.click(screen.getByRole('button', { name: /save/i }))

    expect(await screen.findByText('The selected currency is invalid.')).toBeInTheDocument()
    expect(
      screen.getByText('The description may not be greater than 500 characters.'),
    ).toBeInTheDocument()
    expect(screen.getByText('The valid from field must be a valid date.')).toBeInTheDocument()
    expect(
      screen.getByText('A default price list already exists for this currency.'),
    ).toBeInTheDocument()
  })

  it('routes a 422 on a field this form has no input for to the form-level alert', async () => {
    mockCreatePriceList.mockRejectedValue(
      make422({ company_id: ['The selected company is invalid.'] }),
    )
    renderWithProviders(<PriceListForm />, { queryClient: createTestQueryClient() })

    await fillRequired()
    await userEvent.click(screen.getByRole('button', { name: /save/i }))

    expect(await screen.findByText('The selected company is invalid.')).toBeInTheDocument()
  })
})
