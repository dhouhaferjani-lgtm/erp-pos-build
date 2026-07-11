import { useTranslation } from 'react-i18next'
import { Check, RotateCcw, X } from 'lucide-react'
import { Button } from '@/components/atoms'
import { cn } from '@/lib/utils'
import { tokens } from '@/lib/designTokens'

interface CommitBarProps {
  canCommit: boolean
  isCommitting: boolean
  isRejecting: boolean
  isReExtracting: boolean
  refusalReason: string | null
  canReExtract: boolean
  onCommit: () => void
  onReject: () => void
  onReExtract: () => void
}

export function CommitBar({
  canCommit,
  isCommitting,
  isRejecting,
  isReExtracting,
  refusalReason,
  canReExtract,
  onCommit,
  onReject,
  onReExtract,
}: CommitBarProps) {
  const { t } = useTranslation(['documentIngestions'])

  return (
    <div className={cn(tokens.card.base, 'flex flex-wrap items-center justify-between gap-3')}>
      {refusalReason ? <p className={tokens.helperText.error}>{refusalReason}</p> : <span />}
      <div className="flex flex-wrap gap-2">
        {canReExtract && (
          <Button
            type="button"
            variant="secondary"
            disabled={isReExtracting}
            onClick={onReExtract}
          >
            <RotateCcw className="me-2 h-4 w-4" aria-hidden="true" />
            {t('actions.reExtract')}
          </Button>
        )}
        <Button
          type="button"
          variant="dangerOutline"
          disabled={isRejecting}
          onClick={onReject}
        >
          <X className="me-2 h-4 w-4" aria-hidden="true" />
          {t('actions.reject')}
        </Button>
        <Button
          type="button"
          disabled={!canCommit || isCommitting}
          onClick={onCommit}
        >
          <Check className="me-2 h-4 w-4" aria-hidden="true" />
          {t('actions.commit')}
        </Button>
      </div>
    </div>
  )
}
