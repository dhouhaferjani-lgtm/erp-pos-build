import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { RenderingCancelledException } from 'pdfjs-dist'
import { SourceViewer } from './SourceViewer'

const mockGetDocument = vi.hoisted(() => vi.fn())

// A fake `RenderingCancelledException` — the same class object the component
// imports (module mocking resolves both to this one), which is what lets
// `error instanceof RenderingCancelledException` inside SourceViewer match
// errors constructed here. Defined inside `vi.hoisted` because the
// `vi.mock` factory below is hoisted above normal top-level declarations.
const MockRenderingCancelledException = vi.hoisted(() => {
  return class MockRenderingCancelledException extends Error {
    extraDelay: number
    constructor(msg: string, extraDelay = 0) {
      super(msg)
      this.name = 'RenderingCancelledException'
      this.extraDelay = extraDelay
    }
  }
})

vi.mock('pdfjs-dist', () => ({
  getDocument: mockGetDocument,
  GlobalWorkerOptions: { workerSrc: '' },
  RenderingCancelledException: MockRenderingCancelledException,
}))

function mockPdfDoc(overrides: {
  numPages?: number
  getPage: () => Promise<{ getViewport: () => { width: number; height: number }; render: () => { promise: Promise<void>; cancel: () => void } }>
  destroy?: () => Promise<void>
}): {
  promise: Promise<{
    numPages: number
    loadingTask: { destroy: () => Promise<void>; destroyed: boolean }
    getPage: () => Promise<{ getViewport: () => { width: number; height: number }; render: () => { promise: Promise<void>; cancel: () => void } }>
  }>
  destroySpy: ReturnType<typeof vi.fn>
} {
  const destroySpy = vi.fn(overrides.destroy ?? (async () => {}))
  const loadingTask = { destroy: destroySpy, destroyed: false }
  return {
    promise: Promise.resolve({
      numPages: overrides.numPages ?? 1,
      loadingTask,
      getPage: overrides.getPage,
    }),
    destroySpy,
  }
}

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
        loadingTask: { destroy: vi.fn(async () => {}), destroyed: false },
        getPage: async () => ({
          getViewport: () => ({ width: 800, height: 1000 }),
          render: () => ({ promise: Promise.resolve(), cancel: vi.fn() }),
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

  it('fires onActivate when Enter is pressed on the interactive media', async () => {
    mockFetchResolvingBytes(PNG_BYTES)
    const onActivate = vi.fn()
    const user = userEvent.setup()

    render(<SourceViewer sourceUrl="https://signed.example.test/scan.png" onActivate={onActivate} />)

    const img = await screen.findByRole('button', { name: 'Source document' })
    img.focus()
    await user.keyboard('{Enter}')

    expect(onActivate).toHaveBeenCalledTimes(1)
  })

  it('gives the interactive canvas an accessible name', async () => {
    mockFetchResolvingBytes(PDF_BYTES)
    const onActivate = vi.fn()

    const { container } = render(
      <SourceViewer sourceUrl="https://signed.example.test/scan.pdf" onActivate={onActivate} />,
    )

    await waitFor(() => expect(container.querySelector('canvas')).not.toBeNull())

    const canvasButton = screen.getByRole('button', { name: 'Source document' })
    expect(canvasButton.tagName).toBe('CANVAS')
  })

  it('cancels the in-flight page-render task when the pager advances before it resolves, and swallows the resulting RenderingCancelledException', async () => {
    mockFetchResolvingBytes(PDF_BYTES)

    let rejectFirstRender: ((reason: unknown) => void) | undefined
    const firstRenderPromise = new Promise<void>((_resolve, reject) => {
      rejectFirstRender = reject
    })
    const firstCancel = vi.fn(() => {
      rejectFirstRender?.(new RenderingCancelledException('cancelled', 0))
    })
    const secondCancel = vi.fn()
    let pageRenderCalls = 0
    const pageRender = vi.fn(() => {
      pageRenderCalls += 1
      if (pageRenderCalls === 1) {
        return { promise: firstRenderPromise, cancel: firstCancel }
      }
      return { promise: Promise.resolve(), cancel: secondCancel }
    })

    mockGetDocument.mockReturnValue({
      promise: Promise.resolve({
        numPages: 2,
        loadingTask: { destroy: vi.fn(async () => {}), destroyed: false },
        getPage: async () => ({
          getViewport: () => ({ width: 800, height: 1000 }),
          render: pageRender,
        }),
      }),
    })

    const { container } = render(<SourceViewer sourceUrl="https://signed.example.test/scan.pdf" />)

    await waitFor(() => expect(container.querySelector('canvas')).not.toBeNull())
    await waitFor(() => expect(pageRenderCalls).toBe(1))

    const nextButton = screen.getByRole('button', { name: 'Next' })
    fireEvent.click(nextButton)

    await waitFor(() => expect(firstCancel).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(pageRenderCalls).toBe(2))

    // The cancelled first render must never flip the viewer into the error
    // state — the pager click is a normal navigation, not a failure.
    expect(screen.queryByText('The preview could not be rendered.')).not.toBeInTheDocument()
    expect(container.querySelector('canvas')).not.toBeNull()
  })

  it('revokes the blob URL when sourceUrl transitions to null while an image is loaded', async () => {
    mockFetchResolvingBytes(PNG_BYTES)

    const { rerender } = render(<SourceViewer sourceUrl="https://signed.example.test/scan.png" />)
    await screen.findByRole('img', { name: 'Source document' })

    rerender(<SourceViewer sourceUrl={null} />)

    expect(URL.revokeObjectURL).toHaveBeenCalledWith('blob:mock-url')
    expect(screen.getByText('Source preview is unavailable.')).toBeInTheDocument()
  })

  it('destroys the previous pdf document when a new source replaces it, and destroys the current one on unmount', async () => {
    const firstDoc = mockPdfDoc({
      getPage: async () => ({
        getViewport: () => ({ width: 800, height: 1000 }),
        render: () => ({ promise: Promise.resolve(), cancel: vi.fn() }),
      }),
    })
    const secondDoc = mockPdfDoc({
      getPage: async () => ({
        getViewport: () => ({ width: 800, height: 1000 }),
        render: () => ({ promise: Promise.resolve(), cancel: vi.fn() }),
      }),
    })
    mockGetDocument.mockReturnValueOnce({ promise: firstDoc.promise }).mockReturnValueOnce({ promise: secondDoc.promise })
    mockFetchResolvingBytes(PDF_BYTES)

    const { rerender, unmount, container } = render(
      <SourceViewer sourceUrl="https://signed.example.test/scan-a.pdf" />,
    )
    await waitFor(() => expect(mockGetDocument).toHaveBeenCalledTimes(1))
    await waitFor(() => expect(container.querySelector('canvas')).not.toBeNull())

    rerender(<SourceViewer sourceUrl="https://signed.example.test/scan-b.pdf" />)
    await waitFor(() => expect(mockGetDocument).toHaveBeenCalledTimes(2))

    expect(firstDoc.destroySpy).toHaveBeenCalledTimes(1)
    expect(secondDoc.destroySpy).not.toHaveBeenCalled()

    await waitFor(() => expect(container.querySelector('canvas')).not.toBeNull())

    unmount()

    expect(secondDoc.destroySpy).toHaveBeenCalledTimes(1)
  })
})
