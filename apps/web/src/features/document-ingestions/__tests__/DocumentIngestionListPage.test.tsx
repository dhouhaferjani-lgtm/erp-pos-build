import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const toastSuccess = vi.hoisted(() => vi.fn())
const toastError = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', () => ({
  api: {
    get: mockApiGet,
    post: mockApiPost,
  },
  getErrorMessage: (error: unknown): string => {
    if (
      typeof error === 'object'
      && error !== null
      && 'response' in error
      && typeof error.response === 'object'
      && error.response !== null
      && 'data' in error.response
      && typeof error.response.data === 'object'
      && error.response.data !== null
      && 'error' in error.response.data
      && typeof error.response.data.error === 'object'
      && error.response.data.error !== null
      && 'message' in error.response.data.error
      && typeof error.response.data.error.message === 'string'
    ) {
      return error.response.data.error.message
    }
    return error instanceof Error ? error.message : 'Unexpected error'
  },
}))

vi.mock('sonner', () => ({
  toast: {
    success: toastSuccess,
    error: toastError,
  },
}))

import { DocumentIngestionListPage } from '../DocumentIngestionListPage'

function renderPage(): QueryClient {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter>
        <DocumentIngestionListPage />
      </MemoryRouter>
    </QueryClientProvider>,
  )

  return queryClient
}

function listResponse() {
  return {
    data: {
      data: [
        {
          id: 'ing-1',
          kind: 'supplier_delivery_note',
          status: 'needs_review',
          provider: 'claude',
          provider_model: 'claude-haiku-4-5',
          confidence_summary: {
            averageConfidence: 0.82,
            lowConfidenceFields: [],
            reconciliation: { consistent: true, flags: [] },
          },
          created_at: '2026-07-07T08:15:00.000Z',
        },
        {
          id: 'ing-2',
          kind: 'supplier_invoice',
          status: 'extracting',
          provider: null,
          provider_model: null,
          confidence_summary: {
            averageConfidence: null,
            lowConfidenceFields: [],
            reconciliation: { consistent: false, flags: ['currency_mismatch'] },
          },
          created_at: '2026-07-07T08:20:00.000Z',
        },
      ],
      meta: {
        current_page: 1,
        last_page: 1,
        per_page: 25,
        total: 2,
      },
    },
  }
}

describe('DocumentIngestionListPage', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    toastSuccess.mockReset()
    toastError.mockReset()
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

  it('renders document ingestion rows with kind labels and status chips', async () => {
    mockApiGet.mockResolvedValue(listResponse())

    renderPage()

    expect(await screen.findByText('claude-haiku-4-5')).toBeInTheDocument()
    const scansTable = screen.getByRole('table')
    expect(within(scansTable).getByText('Delivery note')).toBeInTheDocument()
    expect(within(scansTable).getByText('Supplier invoice')).toBeInTheDocument()
    expect(within(scansTable).getByText('Needs review')).toBeInTheDocument()
    expect(within(scansTable).getByText('Extracting')).toBeInTheDocument()
    expect(within(scansTable).getByText('Unknown confidence')).toBeInTheDocument()
  })

  it('validates upload kind and file size before posting multipart form data', async () => {
    const user = userEvent.setup()
    mockApiGet.mockResolvedValue(listResponse())

    renderPage()

    await user.click(await screen.findByRole('button', { name: 'Upload scan' }))
    await user.click(screen.getByRole('button', { name: 'Start extraction' }))

    expect(await screen.findByText('Choose a document type.')).toBeInTheDocument()
    expect(mockApiPost).not.toHaveBeenCalled()

    const dialog = screen.getByRole('dialog', { name: 'Upload scan' })
    await user.selectOptions(within(dialog).getByLabelText('Document type'), 'supplier_invoice')
    const file = new File(['invoice'], 'invoice.pdf', { type: 'application/pdf' })
    await user.upload(within(dialog).getByLabelText('Source file'), file)

    mockApiPost.mockResolvedValueOnce({
      data: {
        data: {
          id: 'ing-3',
          kind: 'supplier_invoice',
          status: 'uploaded',
        },
      },
    })
    await user.click(screen.getByRole('button', { name: 'Start extraction' }))

    await waitFor(() => expect(mockApiPost).toHaveBeenCalled())
    const [url, body] = mockApiPost.mock.calls[0] as [string, FormData]
    expect(url).toBe('/document-ingestions')
    expect(body).toBeInstanceOf(FormData)
    expect(body.get('kind')).toBe('supplier_invoice')
    expect(body.get('file')).toBe(file)
  })

  it('branches duplicate uploads by the DUPLICATE_DOCUMENT error code', async () => {
    const user = userEvent.setup()
    mockApiGet.mockResolvedValue(listResponse())

    renderPage()

    await user.click(await screen.findByRole('button', { name: 'Upload scan' }))
    const dialog = screen.getByRole('dialog', { name: 'Upload scan' })
    await user.selectOptions(within(dialog).getByLabelText('Document type'), 'supplier_delivery_note')
    await user.upload(
      within(dialog).getByLabelText('Source file'),
      new File(['duplicate'], 'duplicate.pdf', { type: 'application/pdf' }),
    )

    mockApiPost.mockRejectedValueOnce({
      response: {
        data: {
          error: {
            code: 'DUPLICATE_DOCUMENT',
            message: 'This document has already been uploaded.',
            errors: { file: ['duplicate'] },
          },
        },
      },
    })

    await user.click(screen.getByRole('button', { name: 'Start extraction' }))

    expect(await screen.findByText('This source file is already in the scan queue.')).toBeInTheDocument()
  })
})
