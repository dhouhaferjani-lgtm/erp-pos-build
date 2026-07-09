import type { KeyboardEvent, MouseEvent } from 'react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import * as pdfjs from 'pdfjs-dist'
import type { PDFDocumentProxy } from 'pdfjs-dist'
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url'
import { Spinner } from '@/components/atoms/Spinner'
import { textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

// The media serve route hardens against clickjacking (X-Frame-Options: DENY +
// CSP frame-ancestors 'none'), so the source can never render inside an
// <iframe>/<embed>/<object>. Instead we fetch the signed URL ourselves,
// sniff the bytes (signed URLs carry no file extension), and render either
// an <img> (object URL) or a PDF page onto a <canvas> via pdf.js.
pdfjs.GlobalWorkerOptions.workerSrc = pdfWorkerUrl

interface SourceViewerProps {
  sourceUrl: string | null
  onActivate?: () => void
}

type ViewerState = 'loading' | 'image' | 'pdf' | 'error'

const PDF_MAGIC = '%PDF-'

function looksLikePdf(bytes: Uint8Array): boolean {
  if (bytes.length < PDF_MAGIC.length) {
    return false
  }
  let header = ''
  for (let i = 0; i < PDF_MAGIC.length; i += 1) {
    header += String.fromCharCode(bytes[i])
  }
  return header === PDF_MAGIC
}

export function SourceViewer({ sourceUrl, onActivate }: SourceViewerProps) {
  const { t } = useTranslation(['documentIngestions', 'common'])
  const [state, setState] = useState<ViewerState>('loading')
  const [imageObjectUrl, setImageObjectUrl] = useState<string | null>(null)
  const [pdfDoc, setPdfDoc] = useState<PDFDocumentProxy | null>(null)
  const [numPages, setNumPages] = useState(1)
  const [currentPage, setCurrentPage] = useState(1)
  const containerRef = useRef<HTMLDivElement | null>(null)
  const canvasRef = useRef<HTMLCanvasElement | null>(null)
  // The signed URL currently being resolved — lets async work (fetch, pdf.js
  // decode) discard its result if a fresher sourceUrl arrived meanwhile.
  const inFlightUrlRef = useRef<string | null>(null)
  const objectUrlRef = useRef<string | null>(null)

  useEffect(() => {
    if (!sourceUrl) {
      return
    }

    inFlightUrlRef.current = sourceUrl
    setState('loading')
    setPdfDoc(null)
    setNumPages(1)
    setCurrentPage(1)
    if (objectUrlRef.current) {
      URL.revokeObjectURL(objectUrlRef.current)
      objectUrlRef.current = null
    }
    setImageObjectUrl(null)

    const controller = new AbortController()
    const requestedUrl = sourceUrl

    async function load() {
      try {
        // The signed URL itself carries the auth signature — no headers needed,
        // same as the previous <img src> behavior.
        const response = await fetch(requestedUrl, { signal: controller.signal })
        if (!response.ok) {
          throw new Error(`Failed to fetch source document (${String(response.status)})`)
        }
        const buffer = await response.arrayBuffer()
        if (inFlightUrlRef.current !== requestedUrl) {
          return
        }

        const bytes = new Uint8Array(buffer)
        if (looksLikePdf(bytes)) {
          const doc = await pdfjs.getDocument({ data: bytes }).promise
          if (inFlightUrlRef.current !== requestedUrl) {
            return
          }
          setPdfDoc(doc)
          setNumPages(doc.numPages)
          setCurrentPage(1)
          setState('pdf')
        } else {
          const objectUrl = URL.createObjectURL(new Blob([buffer]))
          if (inFlightUrlRef.current !== requestedUrl) {
            URL.revokeObjectURL(objectUrl)
            return
          }
          objectUrlRef.current = objectUrl
          setImageObjectUrl(objectUrl)
          setState('image')
        }
      } catch {
        if (inFlightUrlRef.current !== requestedUrl) {
          return
        }
        setState('error')
      }
    }

    void load()

    return () => {
      controller.abort()
      if (inFlightUrlRef.current === requestedUrl) {
        inFlightUrlRef.current = null
      }
    }
  }, [sourceUrl])

  // Render the current PDF page into the canvas. Re-runs on page navigation.
  useEffect(() => {
    if (!pdfDoc) {
      return
    }
    const doc = pdfDoc
    let cancelled = false

    async function renderPage() {
      try {
        const page = await doc.getPage(currentPage)
        const naturalViewport = page.getViewport({ scale: 1 })
        // `|| ` (not `??`) is deliberate: an unmeasured container reports
        // clientWidth 0, which must also fall back to the natural width —
        // ?? would only catch null/undefined and leave scale at 0.
        // eslint-disable-next-line @typescript-eslint/prefer-nullish-coalescing
        const containerWidth = containerRef.current?.clientWidth || naturalViewport.width
        const scale = containerWidth / naturalViewport.width
        const viewport = page.getViewport({ scale })
        const canvas = canvasRef.current
        if (!canvas) {
          return
        }
        // pdf.js v6 renders against `canvas` directly; still probe getContext
        // first so a jsdom-style environment without 2d canvas support (the
        // null-context guard) surfaces as the error state rather than a
        // silent/broken render.
        if (!canvas.getContext('2d')) {
          if (!cancelled) {
            setState('error')
          }
          return
        }
        canvas.width = viewport.width
        canvas.height = viewport.height
        await page.render({ canvas, viewport }).promise
      } catch {
        if (!cancelled) {
          setState('error')
        }
      }
    }

    void renderPage()

    return () => {
      cancelled = true
    }
  }, [pdfDoc, currentPage])

  useEffect(() => {
    return () => {
      if (objectUrlRef.current) {
        URL.revokeObjectURL(objectUrlRef.current)
      }
    }
  }, [])

  if (!sourceUrl) {
    return (
      <div className={tokens.card.base}>
        <p>{t('review.sourceMissing')}</p>
      </div>
    )
  }

  const interactiveProps = onActivate
    ? {
        role: 'button' as const,
        tabIndex: 0,
        onClick: (event: MouseEvent) => {
          event.preventDefault()
          onActivate()
        },
        onKeyDown: (event: KeyboardEvent) => {
          if (event.key === 'Enter') {
            event.preventDefault()
            onActivate()
          }
        },
      }
    : {}

  return (
    <section className={cn(tokens.card.base, 'space-y-3')} aria-label={t('review.source')}>
      <div ref={containerRef} className="w-full">
        {state === 'loading' && (
          <div className="flex h-[640px] items-center justify-center rounded-[var(--radius-card)] border">
            <Spinner />
          </div>
        )}
        {state === 'error' && (
          <div className="flex h-[640px] items-center justify-center rounded-[var(--radius-card)] border">
            <p className={textColors.tertiary}>{t('review.previewFailed')}</p>
          </div>
        )}
        {state === 'image' && imageObjectUrl && (
          <img
            src={imageObjectUrl}
            alt={t('review.source')}
            className={cn(
              'max-h-[640px] w-full rounded-[var(--radius-card)] border object-contain',
              onActivate && 'cursor-pointer',
            )}
            {...interactiveProps}
          />
        )}
        {state === 'pdf' && (
          <div className="space-y-2">
            <canvas
              ref={canvasRef}
              className={cn(
                'max-h-[640px] w-full rounded-[var(--radius-card)] border object-contain',
                onActivate && 'cursor-pointer',
              )}
              {...interactiveProps}
            />
            {numPages > 1 && (
              <div className="flex items-center justify-center gap-3">
                <button
                  type="button"
                  disabled={currentPage <= 1}
                  onClick={() => { setCurrentPage((page) => Math.max(1, page - 1)) }}
                  className={cn(textColors.brand, 'hover:underline disabled:cursor-not-allowed disabled:opacity-40 disabled:no-underline')}
                >
                  {t('common:previous')}
                </button>
                <span className={textColors.tertiary}>
                  {t('review.pageOf', { page: currentPage, total: numPages })}
                </span>
                <button
                  type="button"
                  disabled={currentPage >= numPages}
                  onClick={() => { setCurrentPage((page) => Math.min(numPages, page + 1)) }}
                  className={cn(textColors.brand, 'hover:underline disabled:cursor-not-allowed disabled:opacity-40 disabled:no-underline')}
                >
                  {t('common:next')}
                </button>
              </div>
            )}
          </div>
        )}
      </div>
      <a
        href={sourceUrl}
        target="_blank"
        rel="noopener noreferrer"
        className={cn(textColors.brand, 'hover:underline')}
      >
        {t('review.openSource')}
      </a>
    </section>
  )
}
