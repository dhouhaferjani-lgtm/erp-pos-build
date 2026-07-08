import { useTranslation } from 'react-i18next'
import { tokens } from '@/lib/designTokens'

interface SourceViewerProps {
  sourceUrl: string | null
}

export function SourceViewer({ sourceUrl }: SourceViewerProps) {
  const { t } = useTranslation(['documentIngestions'])

  if (!sourceUrl) {
    return (
      <div className={tokens.card.base}>
        <p>{t('review.sourceMissing')}</p>
      </div>
    )
  }

  return (
    <section className={tokens.card.base} aria-label={t('review.source')}>
      <iframe
        src={sourceUrl}
        title={t('review.source')}
        className="h-[640px] w-full rounded-[var(--radius-card)]"
      />
    </section>
  )
}
