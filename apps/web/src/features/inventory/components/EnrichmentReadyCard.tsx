import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query'
import { CheckCircle2, Loader2 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { toast } from 'sonner'
import { acceptEnrichmentResult } from '@/features/enrichment/api/enrichmentApi'
import { colors, textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import type { FastPathState } from '../hooks/useEnrichmentFastPath'

interface EnrichmentReadyCardProps {
  state: FastPathState
  canReview: boolean
  productQueryKey: QueryKey
}

const ACCEPT_FIELDS = ['name', 'brand', 'description', 'barcode'] as const

export function EnrichmentReadyCard({
  state,
  canReview,
  productQueryKey,
}: EnrichmentReadyCardProps): React.JSX.Element | null {
  const { t } = useTranslation('inventory')
  const queryClient = useQueryClient()

  const acceptMutation = useMutation({
    mutationFn: () => {
      if (state.phase !== 'ready') {
        throw new Error('No enrichment result is ready')
      }

      return acceptEnrichmentResult(state.result.id, [...ACCEPT_FIELDS])
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: productQueryKey })
      toast.success(t('barcodeLookup.fastPathAccepted'))
    },
    onError: () => {
      toast.error(t('barcodeLookup.fastPathAcceptError'))
    },
  })

  if (state.phase === 'idle' || state.phase === 'polling') {
    return null
  }

  if (state.phase === 'timeout') {
    return (
      <div className={cn(tokens.alert.base, tokens.alert.info)}>
        {t('barcodeLookup.fastPathTimeout')}
      </div>
    )
  }

  return (
    <section className={cn(tokens.card.base, 'flex items-start justify-between gap-4')}>
      <div className="flex min-w-0 items-start gap-3">
        <div className={cn(colors.success[50], textColors.success, 'rounded-md p-2')}>
          <CheckCircle2 className="h-4 w-4" aria-hidden="true" />
        </div>
        <div className="min-w-0">
          <h3 className={tokens.heading.section}>{t('barcodeLookup.fastPathReadyTitle')}</h3>
          <p className={tokens.helperText.base}>
            {t('barcodeLookup.fastPathReadyBody')}
          </p>
        </div>
      </div>
      <button
        type="button"
        className={cn(tokens.button.base, tokens.button.primary, tokens.button.sizes.sm)}
        onClick={() => acceptMutation.mutate()}
        disabled={!canReview || acceptMutation.isPending}
      >
        {acceptMutation.isPending ? (
          <Loader2 className="mr-2 h-4 w-4 animate-spin" aria-hidden="true" />
        ) : null}
        {t('barcodeLookup.fastPathApplyNow')}
      </button>
    </section>
  )
}
