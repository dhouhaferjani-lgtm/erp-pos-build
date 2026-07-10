import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes, useParams } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

const mockApiGet = vi.hoisted(() => vi.fn())
const mockApiPost = vi.hoisted(() => vi.fn())

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

import { UploadScanPage } from '../UploadScanPage'

function ReviewStub() {
  const { id } = useParams()
  return <div data-testid="review-stub">Reviewing {id}</div>
}

function renderPage(initialEntry = '/purchases/scans/new'): QueryClient {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  })

  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[initialEntry]}>
        <Routes>
          <Route path="/purchases/scans/new" element={<UploadScanPage />} />
          <Route path="/purchases/scans/:id" element={<ReviewStub />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )

  return queryClient
}

describe('UploadScanPage', () => {
  beforeEach(() => {
    mockApiGet.mockReset()
    mockApiPost.mockReset()
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

    Object.defineProperty(URL, 'createObjectURL', {
      writable: true,
      value: vi.fn(() => 'blob:mock-preview-url'),
    })
    Object.defineProperty(URL, 'revokeObjectURL', {
      writable: true,
      value: vi.fn(),
    })
  })

  it('shows the filename and a PDF glyph after selecting a file via the hidden input', async () => {
    renderPage()

    const input = screen.getByLabelText('Source file')
    const file = new File(['x'], 'invoice.pdf', { type: 'application/pdf' })
    fireEvent.change(input, { target: { files: [file] } })

    expect(await screen.findByText(/invoice\.pdf/)).toBeInTheDocument()
    expect(screen.getByTestId('upload-file-icon')).toBeInTheDocument()
  })

  it('shows an image thumbnail after dropping an image file on the dropzone', async () => {
    renderPage()

    const dropzone = screen.getByTestId('upload-dropzone')
    const file = new File(['x'], 'photo.png', { type: 'image/png' })
    fireEvent.drop(dropzone, { dataTransfer: { files: [file] } })

    expect(await screen.findByRole('img', { name: 'photo.png' })).toBeInTheDocument()
    expect(URL.createObjectURL).toHaveBeenCalledWith(file)
  })

  it('shows a specific error mentioning both the max and actual size for a 25 MB file', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.selectOptions(screen.getByLabelText('Document type'), 'supplier_invoice')

    const input = screen.getByLabelText('Source file')
    const file = new File(['x'], 'big.pdf', { type: 'application/pdf' })
    Object.defineProperty(file, 'size', { value: 25 * 1024 * 1024 })
    fireEvent.change(input, { target: { files: [file] } })

    await user.click(screen.getByRole('button', { name: 'Start scan' }))

    expect(await screen.findByText('PDF or image up to 20 MB — this file is 25.0 MB.')).toBeInTheDocument()
    expect(mockApiPost).not.toHaveBeenCalled()
  })

  it('shows an invalid-type error for a .txt file', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.selectOptions(screen.getByLabelText('Document type'), 'supplier_invoice')

    const input = screen.getByLabelText('Source file')
    const file = new File(['hello'], 'notes.txt', { type: 'text/plain' })
    fireEvent.change(input, { target: { files: [file] } })

    await user.click(screen.getByRole('button', { name: 'Start scan' }))

    expect(await screen.findByText('Choose a PDF or image file.')).toBeInTheDocument()
    expect(mockApiPost).not.toHaveBeenCalled()
  })

  it('submits multipart form data with kind and file, then navigates to the scan detail route', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.selectOptions(screen.getByLabelText('Document type'), 'supplier_invoice')

    const input = screen.getByLabelText('Source file')
    const file = new File(['invoice'], 'invoice.pdf', { type: 'application/pdf' })
    fireEvent.change(input, { target: { files: [file] } })

    mockApiPost.mockResolvedValueOnce({
      data: {
        data: {
          id: 'ing-9',
          kind: 'supplier_invoice',
          status: 'uploaded',
        },
      },
    })

    await user.click(screen.getByRole('button', { name: 'Start scan' }))

    await waitFor(() => expect(mockApiPost).toHaveBeenCalled())
    const [url, body] = mockApiPost.mock.calls[0] as [string, FormData]
    expect(url).toBe('/document-ingestions')
    expect(body).toBeInstanceOf(FormData)
    expect(body.get('kind')).toBe('supplier_invoice')
    expect(body.get('file')).toBe(file)

    expect(await screen.findByTestId('review-stub')).toHaveTextContent('Reviewing ing-9')
  })

  it('branches duplicate uploads by the DUPLICATE_DOCUMENT error code', async () => {
    const user = userEvent.setup()
    renderPage()

    await user.selectOptions(screen.getByLabelText('Document type'), 'supplier_delivery_note')

    const input = screen.getByLabelText('Source file')
    const file = new File(['duplicate'], 'duplicate.pdf', { type: 'application/pdf' })
    fireEvent.change(input, { target: { files: [file] } })

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

    await user.click(screen.getByRole('button', { name: 'Start scan' }))

    expect(await screen.findByText('This source file is already in the scan queue.')).toBeInTheDocument()
  })

  it('renders a real, keyboard-operable browse button', async () => {
    renderPage()

    const browseButton = screen.getByRole('button', { name: /browse/i })
    expect(browseButton.tagName).toBe('BUTTON')
    expect(browseButton).toHaveAttribute('type', 'button')
  })

  describe('context-locked kind', () => {
    it('hides the document-type select and shows the locked kind as static text, then submits with the locked kind', async () => {
      const user = userEvent.setup()
      renderPage('/purchases/scans/new?kind=supplier_invoice')

      expect(screen.queryByLabelText('Document type')).not.toBeInTheDocument()
      expect(screen.getByText('Supplier invoice')).toBeInTheDocument()

      const input = screen.getByLabelText('Source file')
      const file = new File(['invoice'], 'invoice.pdf', { type: 'application/pdf' })
      fireEvent.change(input, { target: { files: [file] } })

      mockApiPost.mockResolvedValueOnce({
        data: { data: { id: 'ing-locked', kind: 'supplier_invoice', status: 'uploaded' } },
      })

      await user.click(screen.getByRole('button', { name: 'Start scan' }))

      await waitFor(() => { expect(mockApiPost).toHaveBeenCalled() })
      const [, body] = mockApiPost.mock.calls[0] as [string, FormData]
      expect(body.get('kind')).toBe('supplier_invoice')
      expect(await screen.findByTestId('review-stub')).toHaveTextContent('Reviewing ing-locked')
    })

    it('falls back to the open select for an invalid kind query param', () => {
      renderPage('/purchases/scans/new?kind=bogus')

      expect(screen.getByLabelText('Document type')).toBeInTheDocument()
    })
  })
})
