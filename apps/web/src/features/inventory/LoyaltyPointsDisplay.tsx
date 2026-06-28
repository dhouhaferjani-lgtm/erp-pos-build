import type { ReactElement } from 'react'
import { useTranslation } from 'react-i18next'
import { useLoyaltyEarnRate } from './useLoyaltyEarnRate'

interface Props {
  salePrice: string
}

/**
 * Read-only, indicative "≈ N points" derived from sale price × active earn rate.
 *
 * Renders null when:
 * - No earn-rate is configured (rate === null)
 * - The product has no sale price yet
 * - Arithmetic yields a non-finite result
 *
 * This value is NEVER persisted or sent in any payload. The Number() coercion
 * is intentional for a display-only indicative estimate (CLAUDE.md rule 19
 * governs persisted/payload money, not a read-only on-screen figure).
 */
export function LoyaltyPointsDisplay({ salePrice }: Props): ReactElement | null {
  const { t } = useTranslation()
  const { rate } = useLoyaltyEarnRate(true)

  if (rate === null) {
    return null
  }

  // eslint-disable-next-line precision/no-parsefloat-on-money -- display-only indicative estimate; never persisted or sent in any payload (CLAUDE.md rule 19)
  const priceNum = Number(salePrice)
  // eslint-disable-next-line precision/no-parsefloat-on-money -- display-only indicative estimate; never persisted or sent in any payload (CLAUDE.md rule 19)
  const rateNum = Number(rate)
  const points = Math.round(priceNum * rateNum)

  if (!Number.isFinite(points) || Number.isNaN(points)) {
    return null
  }

  return (
    <div data-testid="loyalty-derived-points-wrap">
      <span data-testid="loyalty-derived-points">{points}</span>
      <p className="text-xs text-muted-foreground">
        {t('catalog:editor.loyalty.indicativeCaption')}
      </p>
    </div>
  )
}
