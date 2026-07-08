import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

interface SourceViewerProps {
  sourceUrl: string | null
}

export function SourceViewer({ sourceUrl }: SourceViewerProps) {
  const { t } = useTranslation(['documentIngestions'])
  // The media serve route hardens against clickjacking (X-Frame-Options: DENY +
  // CSP frame-ancestors 'none'), so the source can never render inside an <iframe>.
  // Images are exempt from framing headers → render an <img>; if the scan is a PDF
  // the image load fails, so fall back to the open-in-new-tab link (top-level
  // navigation is not framed and is not blocked).
  // Track the URL that failed (not a bare boolean) so a new sourceUrl auto-resets
  // the retry without a state-syncing effect.
  const [failedUrl, setFailedUrl] = useState<string | null>(null)
  const imageFailed = failedUrl !== null && failedUrl === sourceUrl

  if (!sourceUrl) {
    return (
      <div className={tokens.card.base}>
        <p>{t('review.sourceMissing')}</p>
      </div>
    )
  }

  return (
    <section className={cn(tokens.card.base, 'space-y-3')} aria-label={t('review.source')}>
      {imageFailed ? (
        <div className="flex h-[640px] items-center justify-center rounded-[var(--radius-card)] border">
          <p className={textColors.tertiary}>{t('review.sourceMissing')}</p>
        </div>
      ) : (
        <img
          src={sourceUrl}
          alt={t('review.source')}
          onError={() => { setFailedUrl(sourceUrl) }}
          className="max-h-[640px] w-full rounded-[var(--radius-card)] border object-contain"
        />
      )}
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
