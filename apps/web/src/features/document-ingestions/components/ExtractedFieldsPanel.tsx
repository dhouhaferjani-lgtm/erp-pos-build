import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { borderColors, textColors, tokens, typography } from '@/lib/designTokens'
import type { ExtractionResult } from '../types'

interface ExtractedFieldsPanelProps {
  extraction: ExtractionResult
  flaggedPaths: ReadonlySet<string>
  flags: readonly string[]
}

function formatFieldLabel(path: string): string {
  return path.replace('.', ' / ').replaceAll('_', ' ')
}

export function ExtractedFieldsPanel({ extraction, flaggedPaths, flags }: ExtractedFieldsPanelProps) {
  const { t } = useTranslation(['documentIngestions'])
  const headerEntries = Object.entries(extraction.header)
  const supplierEntries = Object.entries(extraction.supplier)

  return (
    <section className={cn(tokens.card.base, 'space-y-4')} aria-label={t('review.extractedFields')}>
      <div>
        <h2 className={tokens.heading.section}>{t('review.extractedFields')}</h2>
        <p className={cn(typography.fontSize.sm, textColors.tertiary)}>
          {t('review.pages', { count: extraction.pages })}
        </p>
      </div>

      <div className="grid gap-3 md:grid-cols-2">
        {supplierEntries.map(([key, value]) => {
          const path = `supplier.${key}`
          const flagged = flaggedPaths.has(path) || value.confidence < 0.75
          return (
            <div
              key={path}
              data-testid={`field-${path}`}
              data-flagged={flagged ? 'true' : 'false'}
              className={cn('rounded-[var(--radius-card)] border p-3', flagged ? borderColors.warning : borderColors.light)}
            >
              <dt className={cn(typography.fontSize.xs, textColors.tertiary)}>{formatFieldLabel(path)}</dt>
              <dd>{value.value}</dd>
            </div>
          )
        })}
        {headerEntries.map(([key, value]) => {
          const path = `header.${key}`
          const flagged = flaggedPaths.has(path) || value.confidence < 0.75
          return (
            <div
              key={path}
              data-testid={`field-${path}`}
              data-flagged={flagged ? 'true' : 'false'}
              className={cn('rounded-[var(--radius-card)] border p-3', flagged ? borderColors.warning : borderColors.light)}
            >
              <dt className={cn(typography.fontSize.xs, textColors.tertiary)}>{formatFieldLabel(path)}</dt>
              <dd>{value.value}</dd>
            </div>
          )
        })}
      </div>

      {flags.length > 0 && (
        <div className={cn(tokens.alert.warning, 'space-y-1')}>
          <p className={typography.fontWeight.medium}>{t('review.reconciliationFlags')}</p>
          <ul className="list-inside list-disc">
            {flags.map((flag) => (
              <li key={flag}>{flag}</li>
            ))}
          </ul>
        </div>
      )}
    </section>
  )
}
