import type { ReactNode } from 'react'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { act, cleanup, fireEvent, render, renderHook, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())
const mockNavigate = vi.hoisted(() => vi.fn())
const toastSuccess = vi.hoisted(() => vi.fn())
const toastError = vi.hoisted(() => vi.fn())

// This page renders SourceViewer, which imports pdfjs-dist (for in-app PDF
// rendering) and its worker asset. jsdom has no DOMMatrix/canvas support, so
// the real module fails to load; mock both — SourceViewer itself is unit
// tested (SourceViewer.test.tsx), these tests don't assert on its internals.
vi.mock('pdfjs-dist', () => ({
  getDocument: vi.fn(),
  GlobalWorkerOptions: { workerSrc: '' },
}))
vi.mock('pdfjs-dist/build/pdf.worker.min.mjs?url', () => ({ default: 'worker.js' }))

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
import { useDocumentIngestion } from '../queries'

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

    await screen.findByRole('option', { name: 'Main Warehouse' })
    await user.selectOptions(screen.getByLabelText('Location'), 'loc-1')

    const line = screen.getByTestId('review-line-0')
    await user.click(within(line).getByRole('button', { name: 'Serum C 30ml' }))
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

    await screen.findByRole('option', { name: 'Main Warehouse' })
    await user.selectOptions(screen.getByLabelText('Location'), 'loc-1')
    const line = screen.getByTestId('review-line-0')
    await user.click(within(line).getByRole('button', { name: 'Serum C 30ml' }))
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

  it('opens an in-page create-supplier dialog seeded from the extraction, without navigating away', async () => {
    const user = userEvent.setup()
    mockApiGet.mockImplementation((url: string) => {
      if (url === '/partners/sup-9') {
        return Promise.resolve({
          data: { data: { id: 'sup-9', name: 'PharmaDistrib', type: 'supplier', email: null, city: null } },
        })
      }
      return Promise.resolve(detailResponse())
    })

    renderReview()

    await user.click(await screen.findByRole('button', { name: 'Create supplier' }))

    const dialog = await screen.findByRole('dialog')
    expect(within(dialog).getByLabelText(/^name/i)).toHaveValue('Pharma Distribution')
    expect(mockNavigate).not.toHaveBeenCalled()
  })

  it('selects the newly created supplier once AddPartnerModal succeeds', async () => {
    const user = userEvent.setup()
    mockApiGet.mockResolvedValue(detailResponse())
    mockApiPost.mockResolvedValueOnce({
      id: 'sup-9',
      name: 'PharmaDistrib',
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

    renderReview()

    await user.click(await screen.findByRole('button', { name: 'Create supplier' }))
    const dialog = await screen.findByRole('dialog')
    await user.clear(within(dialog).getByLabelText(/^name/i))
    await user.type(within(dialog).getByLabelText(/^name/i), 'PharmaDistrib')
    await user.click(within(dialog).getByRole('button', { name: /create/i }))

    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
    await waitFor(() => {
      expect(screen.getByTestId('partner-picker')).toHaveTextContent('PharmaDistrib')
    })
  })

  it('posts only PartnerFormData keys when creating a supplier from the review page (no extraction-field leakage)', async () => {
    const user = userEvent.setup()
    mockApiGet.mockResolvedValue(detailResponse())
    mockApiPost.mockResolvedValueOnce({
      id: 'sup-9',
      name: 'Pharma Distribution',
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

    renderReview()

    await user.click(await screen.findByRole('button', { name: 'Create supplier' }))
    const dialog = await screen.findByRole('dialog')
    await user.click(within(dialog).getByRole('button', { name: /create/i }))

    await waitFor(() => expect(mockApiPost).toHaveBeenCalledWith('/partners', expect.any(Object)))
    const payload = mockApiPost.mock.calls[0]?.[1] as Record<string, unknown>
    expect(Object.keys(payload).sort()).toEqual(
      ['address', 'city', 'country', 'email', 'name', 'notes', 'phone', 'postal_code', 'tax_id', 'type'].sort(),
    )
  })

  afterEach(() => {
    vi.useRealTimers()
    vi.unstubAllGlobals()
  })

  it('lays out review-primary: wider review track and a sticky preview pane', async () => {
    mockApiGet.mockResolvedValue(detailResponse())

    renderReview()

    const grid = await screen.findByTestId('review-grid')
    expect(grid.className).toContain('xl:grid-cols-[minmax(300px,0.7fr)_minmax(0,1.4fr)]')
    const previewPane = screen.getByTestId('preview-pane')
    expect(previewPane.className).toContain('xl:sticky')
    // DOM order keeps the preview first (left in LTR).
    expect(grid.firstElementChild).toBe(previewPane)
  })

  it('opens a lightbox with a second source render when the preview is activated, and returns focus on close', async () => {
    const user = userEvent.setup()
    mockApiGet.mockResolvedValue(detailResponse())
    // SourceViewer fetches the signed URL and sniffs the bytes; serve PNG
    // magic bytes so it reaches the interactive image state.
    const pngBytes = new Uint8Array([0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A])
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      arrayBuffer: () => Promise.resolve(pngBytes.buffer),
    }))
    vi.stubGlobal('URL', Object.assign(URL, {
      createObjectURL: vi.fn(() => 'blob:mock-url'),
      revokeObjectURL: vi.fn(),
    }))

    renderReview()

    // The in-page preview is interactive (role button via onActivate).
    const preview = await screen.findByRole('button', { name: 'Source document' })
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()

    await user.click(preview)

    const dialog = await screen.findByRole('dialog')
    // The lightbox renders a SECOND, non-interactive source render (plain img).
    expect(await within(dialog).findByRole('img', { name: 'Source document' })).toBeInTheDocument()

    await user.click(within(dialog).getByRole('button', { name: 'Close' }))

    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(preview).toHaveFocus()
  })

  it('shows the staged processing state (not the missing-extraction card) while a scan is extracting', async () => {
    mockApiGet.mockImplementation((url: string) => {
      if (url === '/locations') {
        return Promise.resolve({ data: { data: [] } })
      }
      return Promise.resolve(detailResponse({ status: 'extracting', extraction: null }))
    })

    renderReview()

    expect(await screen.findByText('Extracting')).toBeInTheDocument()
    expect(screen.getByText(/keep working/iu)).toBeInTheDocument()
    expect(screen.queryByText('Extraction is not available for this scan.')).not.toBeInTheDocument()
  })

  it('falls back to a document glyph when the processing thumbnail fails to render (e.g. a PDF scan)', async () => {
    mockApiGet.mockImplementation((url: string) => {
      if (url === '/locations') {
        return Promise.resolve({ data: { data: [] } })
      }
      return Promise.resolve(detailResponse({ status: 'extracting', extraction: null, source_url: '/signed/source.pdf' }))
    })

    renderReview()

    expect(await screen.findByText('Extracting')).toBeInTheDocument()
    const thumbnail = screen.getByRole('img', { name: 'Processing scan' })
    expect(screen.queryByTestId('processing-thumb-fallback')).not.toBeInTheDocument()

    fireEvent.error(thumbnail)

    expect(screen.queryByRole('img', { name: 'Processing scan' })).not.toBeInTheDocument()
    expect(screen.getByTestId('processing-thumb-fallback')).toBeInTheDocument()
  })

  it('shows the queued hint (not "Extracting") while a scan is only uploaded', async () => {
    mockApiGet.mockImplementation((url: string) => {
      if (url === '/locations') {
        return Promise.resolve({ data: { data: [] } })
      }
      return Promise.resolve(detailResponse({ status: 'uploaded', extraction: null }))
    })

    renderReview()

    expect(await screen.findByText('Queued — extraction starts shortly')).toBeInTheDocument()
    expect(screen.queryByText('Extraction is not available for this scan.')).not.toBeInTheDocument()
  })

  it('shows the failure message and lets the user retry a failed scan', async () => {
    const user = userEvent.setup()
    mockApiGet.mockImplementation((url: string) => {
      if (url === '/locations') {
        return Promise.resolve({ data: { data: [] } })
      }
      return Promise.resolve(detailResponse({
        status: 'failed',
        extraction: null,
        error: { code: 'PROVIDER_TIMEOUT', message: 'Provider timeout' },
      }))
    })
    mockApiPost.mockResolvedValueOnce({
      data: { data: detailResponse({ status: 'extracting', extraction: null }).data.data },
    })

    renderReview()

    expect(await screen.findByText('Provider timeout')).toBeInTheDocument()
    await user.click(screen.getByRole('button', { name: 'Re-run extraction' }))

    await waitFor(() => expect(mockApiPost).toHaveBeenCalledWith('/document-ingestions/ing-1/extract', {}))
  })

  it('polls while extracting and flips to the review layout with a ready toast once needs_review data arrives', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    let detailCallCount = 0
    mockApiGet.mockImplementation((url: string) => {
      if (url === '/locations') {
        return Promise.resolve({ data: { data: [{ id: 'loc-1', name: 'Main Warehouse' }] } })
      }
      detailCallCount += 1
      if (detailCallCount === 1) {
        return Promise.resolve(detailResponse({ status: 'extracting', extraction: null }))
      }
      return Promise.resolve(detailResponse({ status: 'needs_review' }))
    })

    renderReview()

    expect(await screen.findByText('Extracting')).toBeInTheDocument()

    await act(async () => {
      await vi.advanceTimersByTimeAsync(4000)
    })

    expect(await screen.findByText('Extracted fields')).toBeInTheDocument()
    expect(toastSuccess).toHaveBeenCalledTimes(1)
    expect(toastSuccess).toHaveBeenCalledWith('Scan ready for review.')
  })
})

describe('useDocumentIngestion polling', () => {
  function wrapper({ children }: { children: ReactNode }) {
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    return <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  }

  beforeEach(() => {
    mockApiGet.mockReset()
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
    vi.useRealTimers()
  })

  it('refetches every 4000ms while status is extracting, and stops once status is needs_review', async () => {
    vi.useFakeTimers({ shouldAdvanceTime: true })
    let callCount = 0
    mockApiGet.mockImplementation(() => {
      callCount += 1
      const status = callCount < 3 ? 'extracting' : 'needs_review'
      return Promise.resolve(detailResponse({ status, extraction: null }))
    })

    renderHook(() => useDocumentIngestion('ing-1'), { wrapper })

    await waitFor(() => expect(callCount).toBeGreaterThanOrEqual(1))
    await act(async () => {
      await vi.advanceTimersByTimeAsync(4000)
    })
    await waitFor(() => expect(callCount).toBeGreaterThanOrEqual(2))
    await act(async () => {
      await vi.advanceTimersByTimeAsync(4000)
    })
    await waitFor(() => expect(callCount).toBeGreaterThanOrEqual(3))

    const stableCount = callCount
    await act(async () => {
      await vi.advanceTimersByTimeAsync(10000)
    })
    expect(callCount).toBe(stableCount)
  })
})
