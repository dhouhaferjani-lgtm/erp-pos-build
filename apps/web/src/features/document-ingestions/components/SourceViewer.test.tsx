import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { SourceViewer } from './SourceViewer'

const mockGetDocument = vi.hoisted(() => vi.fn())

vi.mock('pdfjs-dist', () => ({
  getDocument: mockGetDocument,
  GlobalWorkerOptions: { workerSrc: '' },
}))

vi.mock('pdfjs-dist/build/pdf.worker.min.mjs?url', () => ({ default: 'worker.js' }))

function bufferFrom(bytes: number[]): ArrayBuffer {
  return new Uint8Array(bytes).buffer
}

const PNG_BYTES = [0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A]
const PDF_BYTES = [0x25, 0x50, 0x44, 0x46, 0x2D, 0x31, 0x2E, 0x34] // '%PDF-1.4'

function mockFetchResolvingBytes(bytes: number[]): void {
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
    ok: true,
    arrayBuffer: async () => bufferFrom(bytes),
  }))
}

describe('SourceViewer', () => {
  beforeEach(() => {
    mockGetDocument.mockReset()
    mockGetDocument.mockReturnValue({
      promise: Promise.resolve({
        numPages: 1,
        getPage: async () => ({
          getViewport: () => ({ width: 800, height: 1000 }),
          render: () => ({ promise: Promise.resolve() }),
        }),
      }),
    })
    vi.stubGlobal('URL', Object.assign(URL, {
      createObjectURL: vi.fn(() => 'blob:mock-url'),
      revokeObjectURL: vi.fn(),
    }))
    // jsdom does not implement canvas 2d context — stub a minimal one so the
    // pdf branch can proceed past the null-context guard.
    HTMLCanvasElement.prototype.getContext = vi.fn(() => ({
      clearRect: vi.fn(),
      drawImage: vi.fn(),
    })) as unknown as typeof HTMLCanvasElement.prototype.getContext
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
  })

  it('renders an <img> from an object URL when the fetched bytes are an image', async () => {
    mockFetchResolvingBytes(PNG_BYTES)

    render(<SourceViewer sourceUrl="https://signed.example.test/scan.png" />)

    const img = await screen.findByRole('img', { name: 'Source document' })
    expect(img).toHaveAttribute('src', 'blob:mock-url')
    expect(screen.queryByRole('img', { name: /canvas/iu })).not.toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Open document in new tab' })).toHaveAttribute(
      'href',
      'https://signed.example.test/scan.png',
    )
  })

  it('renders a <canvas> via pdf.js when the fetched bytes start with the PDF magic number', async () => {
    mockFetchResolvingBytes(PDF_BYTES)

    const { container } = render(<SourceViewer sourceUrl="https://signed.example.test/scan.pdf" />)

    await waitFor(() => expect(mockGetDocument).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(container.querySelector('canvas')).not.toBeNull())
    expect(screen.queryByRole('img')).not.toBeInTheDocument()
  })

  it('shows the preview-failed message and keeps the open-in-new-tab link when the fetch rejects', async () => {
    vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('network down')))

    render(<SourceViewer sourceUrl="https://signed.example.test/scan.pdf" />)

    expect(await screen.findByText('The preview could not be rendered.')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Open document in new tab' })).toBeInTheDocument()
  })

  it('shows the existing source-missing card when sourceUrl is null', () => {
    render(<SourceViewer sourceUrl={null} />)

    expect(screen.getByText('Source preview is unavailable.')).toBeInTheDocument()
    expect(screen.queryByRole('link', { name: 'Open document in new tab' })).not.toBeInTheDocument()
  })

  it('fires onActivate when the rendered media is clicked', async () => {
    mockFetchResolvingBytes(PNG_BYTES)
    const onActivate = vi.fn()
    const user = userEvent.setup()

    render(<SourceViewer sourceUrl="https://signed.example.test/scan.png" onActivate={onActivate} />)

    // The interactive img takes on role="button" (its implicit "img" role is
    // overridden), which is itself part of what this test verifies.
    const img = await screen.findByRole('button', { name: 'Source document' })
    await user.click(img)

    expect(onActivate).toHaveBeenCalledTimes(1)
  })

  it('does not make the media interactive when onActivate is absent', async () => {
    mockFetchResolvingBytes(PNG_BYTES)

    render(<SourceViewer sourceUrl="https://signed.example.test/scan.png" />)

    const img = await screen.findByRole('img', { name: 'Source document' })
    expect(img).not.toHaveAttribute('tabindex')
    expect(img).not.toHaveAttribute('role', 'button')
  })
})
