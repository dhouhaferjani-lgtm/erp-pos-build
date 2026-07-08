import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())
const toastSuccess = vi.hoisted(() => vi.fn())
const toastError = vi.hoisted(() => vi.fn())

vi.mock('@/lib/api', () => ({
  api: {
    get: mockApiGet,
    post: mockApiPost,
  },
  apiPost: mockApiPost,
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

vi.mock('react-router-dom', async (importOriginal) => {
  const actual = await importOriginal<typeof import('react-router-dom')>()
  return {
    ...actual,
    useNavigate: () => mockNavigate,
  }
})

import { ReviewIngestionPage } from '../ReviewIngestionPage'

function renderReview(): QueryClient {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/purchases/scans/ing-1']}>
        <Routes>
          <Route path="/purchases/scans/:id" element={<ReviewIngestionPage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  return queryClient
}

function field(value: string, confidence = 0.95) {
  return { value, confidence, sourceBbox: null }
}

function detailResponse(overrides: Record<string, unknown> = {}) {
  return {
    data: {
      data: {
        id: 'ing-1',
        kind: 'supplier_delivery_note',
        status: 'needs_review',
        source_url: '/signed/source.pdf',
        provider: 'claude',
        provider_model: 'claude-haiku-4-5',
        committed_type: null,
        committed_id: null,
        confidence_summary: {
          averageConfidence: 0.72,
          lowConfidenceFields: ['header.number'],
          reconciliation: {
            consistent: true,
            flags: ['field_unparseable:header.delivery_date', 'line_1_total_mismatch'],
          },
        },
        extraction: {
          docKind: 'supplier_delivery_note',
          pages: 1,
          supplier: {
            name: field('Pharma Distribution', 0.93),
          },
          header: {
            number: field('BL-42', 0.55),
            delivery_date: field('2026-07-06', 0.65),
            currency: field('TND', 0.90),
          },
          lines: [
            {
              description: field('Serum C 30ml', 0.94),
              supplierRef: field('SKU-SERUM', 0.86),
              quantity: field('3.0000', 0.91),
              unitPrice: field('12.500', 0.90),
              taxRate: null,
              lineTotal: field('37.500', 0.52),
              batchNumber: field('', 0.40),
              expiryDate: field('', 0.40),
            },
          ],
          totalsConsistent: false,
        },
        suggestions: {
          supplierCandidates: [{ id: 'supplier-1', name: 'Pharma Distribution', vat: 'TN123', score: '1.00' }],
          productCandidates: [
            [
              {
                id: 'product-1',
                name: 'Serum C 30ml',
                sku: 'SKU-SERUM',
                requires_batch_tracking: true,
                tax_rate: '19.00',
              },
            ],
          ],
          purchaseOrderCandidates: [],
          receiptLineCandidates: [{ po_line_id: 'po-line-1', receipt_line_id: 'receipt-line-1', label: 'PO-7 / GRN-9' }],
        },
        created_at: '2026-07-07T08:15:00.000Z',
        ...overrides,
      },
    },
  }
}

describe('ReviewIngestionPage', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
    mockNavigate.mockReset()
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

  it('renders extracted fields and highlights low-confidence plus reconciliation-flagged fields', async () => {
    mockApiGet.mockResolvedValue(detailResponse())

    renderReview()

    expect(await screen.findByTestId('field-supplier.name')).toHaveTextContent('Pharma Distribution')
    expect(screen.getByText(/claude-haiku-4-5/u)).toBeInTheDocument()
    expect(screen.getByTestId('field-header.number')).toHaveAttribute('data-flagged', 'true')
    expect(screen.getByTestId('field-header.delivery_date')).toHaveAttribute('data-flagged', 'true')
    expect(screen.getByText('line_1_total_mismatch')).toBeInTheDocument()
  })

  it('shows BL location and batch inputs and commits the reviewed payload as strings with sourceLineId mapping', async () => {
    const user = userEvent.setup()
    mockApiGet.mockImplementation((url: string) => {
      if (url === '/locations') {
        return Promise.resolve({ data: { data: [{ id: 'loc-1', name: 'Main Warehouse' }] } })
      }
      return Promise.resolve(detailResponse({
        confidence_summary: {
          averageConfidence: 0.90,
          lowConfidenceFields: [],
          reconciliation: {
            consistent: true,
            flags: ['line_1_total_mismatch'],
          },
        },
      }))
    })
    mockApiPost.mockResolvedValueOnce({
      data: {
        data: {
          committedType: 'goods_receipt',
          committedId: 'gr-1',
          goodsReceiptNumber: 'GRN-1',
        },
      },
    })

    renderReview()

    await user.selectOptions(await screen.findByLabelText('Supplier'), 'supplier-1')
    await user.selectOptions(screen.getByLabelText('Location'), 'loc-1')

    const line = screen.getByTestId('review-line-0')
    await user.selectOptions(within(line).getByLabelText('Product'), 'product-1')
    await user.type(within(line).getByLabelText('Batch number'), 'LOT-7')
    await user.type(within(line).getByLabelText('Expiry date'), '2027-12-31')
    await user.click(screen.getByRole('button', { name: 'Commit document' }))

    await waitFor(() => expect(mockApiPost).toHaveBeenCalledWith('/document-ingestions/ing-1/commit', {
      supplierId: 'supplier-1',
      locationId: 'loc-1',
      reference: 'BL-42',
      documentDate: '2026-07-06',
      lines: [
        {
          productId: 'product-1',
          quantity: '3.0000',
          unitPrice: '12.500',
          freeQuantity: '0',
          sourceLineId: 'po-line-1',
          batch: {
            batch_number: 'LOT-7',
            expiry_date: '2027-12-31',
          },
        },
      ],
    }))
    expect(mockNavigate).toHaveBeenCalledWith('/purchases/receipts')
  })

  it('keeps commit disabled for reconciliation refusals and renders commit 422 server messages', async () => {
    const user = userEvent.setup()
    mockApiGet.mockImplementation((url: string) => {
      if (url === '/locations') {
        return Promise.resolve({ data: { data: [{ id: 'loc-1', name: 'Main Warehouse' }] } })
      }
      return Promise.resolve(detailResponse({
        confidence_summary: {
          averageConfidence: 0.90,
          lowConfidenceFields: [],
          reconciliation: {
            consistent: false,
            flags: ['currency_mismatch'],
          },
        },
      }))
    })

    renderReview()

    expect(await screen.findByText('Reconciliation must be resolved before commit.')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Commit document' })).toBeDisabled()

    cleanup()
    mockApiGet.mockImplementation((url: string) => {
      if (url === '/locations') {
        return Promise.resolve({ data: { data: [{ id: 'loc-1', name: 'Main Warehouse' }] } })
      }
      return Promise.resolve(detailResponse({
        confidence_summary: {
          averageConfidence: 0.90,
          lowConfidenceFields: [],
          reconciliation: {
            consistent: true,
            flags: [],
          },
        },
      }))
    })
    renderReview()

    await user.selectOptions(await screen.findByLabelText('Supplier'), 'supplier-1')
    await user.selectOptions(screen.getByLabelText('Location'), 'loc-1')
    const line = screen.getByTestId('review-line-0')
    await user.selectOptions(within(line).getByLabelText('Product'), 'product-1')
    await user.type(within(line).getByLabelText('Batch number'), 'LOT-7')
    await user.type(within(line).getByLabelText('Expiry date'), '2027-12-31')
    mockApiPost.mockRejectedValueOnce({
      response: {
        data: {
          error: {
            code: 'COMMIT_FAILED',
            message: 'Line 1 price is required.',
          },
        },
      },
    })

    await user.click(screen.getByRole('button', { name: 'Commit document' }))

    expect(await screen.findByText('Line 1 price is required.')).toBeInTheDocument()
  })
})
