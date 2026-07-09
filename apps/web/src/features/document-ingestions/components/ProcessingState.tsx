import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { FileText } from 'lucide-react'
import { cn } from '@/lib/utils'
import { borderColors, colors, textColors, tokens, typography } from '@/lib/designTokens'
import type { IngestionStatus } from '../types'

interface ProcessingStateProps {
  status: IngestionStatus
  thumbnailUrl: string | null
  startedAt?: string | undefined
}

type StepState = 'done' | 'active' | 'pending'

// `startedAt` (the scan's created_at) seeds the counter with the real elapsed
// time so a user who navigates away and back doesn't see it reset to 0:00 —
// the 1s interval below then keeps it ticking from mount.
function elapsedSecondsSince(startedAt: string | undefined): number {
  if (!startedAt) return 0
  const started = new Date(startedAt).getTime()
  if (Number.isNaN(started)) return 0
  return Math.max(0, Math.floor((Date.now() - started) / 1000))
}

function formatElapsed(totalSeconds: number): string {
  const minutes = Math.floor(totalSeconds / 60)
  const seconds = totalSeconds % 60
  return `${minutes.toString()}:${seconds.toString().padStart(2, '0')}`
}

function stepTextClass(state: StepState): string {
  if (state === 'done') return cn(textColors.primary, typography.fontWeight.medium)
  if (state === 'active') return cn(textColors.brand, typography.fontWeight.medium)
  return textColors.tertiary
}

function stepDotClass(state: StepState): string {
  if (state === 'done') return colors.success[600]
  if (state === 'active') return colors.primary[600]
  return colors.neutral[300]
}

export function ProcessingState({ status, thumbnailUrl, startedAt }: ProcessingStateProps) {
  const { t } = useTranslation(['documentIngestions'])
  const [seconds, setSeconds] = useState(() => elapsedSecondsSince(startedAt))
  // Stores the URL that failed (not a boolean) so a fresher thumbnailUrl
  // resets the fallback naturally — no explicit reset effect needed.
  const [failedThumbnailUrl, setFailedThumbnailUrl] = useState<string | null>(null)

  useEffect(() => {
    const interval = setInterval(() => {
      setSeconds((current) => current + 1)
    }, 1000)
    return () => { clearInterval(interval) }
  }, [])

  // Only 'uploaded' and 'extracting' route into this component (see
  // ReviewIngestionPage branching), so the extracting step is either the
  // active step or still queued behind the upload step.
  const extractingState: StepState = status === 'extracting' ? 'active' : 'pending'
  const thumbnailFailed = thumbnailUrl !== null && failedThumbnailUrl === thumbnailUrl

  return (
    <div className={cn(tokens.card.base, 'space-y-5')}>
      <div>
        <h1 className={tokens.heading.section}>{t('processing.title')}</h1>
        <p className={cn(typography.fontSize.sm, textColors.tertiary)}>
          {t('processing.elapsed', { time: formatElapsed(seconds) })}
        </p>
      </div>

      {thumbnailUrl && (
        <div className={cn('relative h-48 w-full overflow-hidden rounded-[var(--radius-card)] border', borderColors.light, colors.neutral[50])}>
          {thumbnailFailed ? (
            <div
              data-testid="processing-thumb-fallback"
              className="flex h-full w-full items-center justify-center"
            >
              <FileText className={cn('h-10 w-10', textColors.tertiary)} aria-hidden="true" />
            </div>
          ) : (
            <img
              src={thumbnailUrl}
              alt={t('processing.title')}
              className="h-full w-full object-contain"
              onError={() => { setFailedThumbnailUrl(thumbnailUrl) }}
            />
          )}
          <div
            aria-hidden="true"
            className={cn(
              'pointer-events-none absolute inset-x-0 h-8 opacity-40',
              'motion-safe:animate-[scan-sweep_2.5s_ease-in-out_infinite]',
              colors.primary[600],
            )}
          />
        </div>
      )}

      <ol className="space-y-3">
        <li className={cn('flex items-center gap-2', stepTextClass('done'))}>
          <span aria-hidden="true" className={cn('h-2.5 w-2.5 rounded-full', stepDotClass('done'))} />
          <span>{t('processing.steps.uploaded')}</span>
        </li>
        <li className={cn('flex items-center gap-2', stepTextClass(extractingState))}>
          <span aria-hidden="true" className={cn('h-2.5 w-2.5 rounded-full', stepDotClass(extractingState))} />
          <span>{t('processing.steps.extracting')}</span>
          {extractingState === 'pending' && (
            <span className={cn(typography.fontSize.xs, textColors.tertiary)}>
              {t('processing.steps.queued')}
            </span>
          )}
        </li>
        <li className={cn('flex items-center gap-2', stepTextClass('pending'))}>
          <span aria-hidden="true" className={cn('h-2.5 w-2.5 rounded-full', stepDotClass('pending'))} />
          <span>{t('processing.steps.ready')}</span>
        </li>
      </ol>

      <div className="space-y-1">
        <p className={cn(typography.fontSize.sm, textColors.tertiary)}>{t('processing.keepWorking')}</p>
        <Link to="/purchases/scans" className={cn(textColors.brand, typography.fontSize.sm, 'hover:underline')}>
          {t('processing.backToList')}
        </Link>
      </div>
    </div>
  )
}
