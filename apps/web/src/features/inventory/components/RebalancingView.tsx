import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { EmptyState } from '@/components/molecules/EmptyState/EmptyState'
import { formatQuantity } from '@/lib/format'
import { locationScopedKey } from '@/lib/locationScopedKey'
import { textColors, tokens } from '@/lib/designTokens'
import { useViewScope } from '@/features/locations/hooks/useViewScope'
import { getRebalance } from '../api/stockMatrix'
import { pairRebalanceRows } from '../lib/rebalance'

export function RebalancingView() {
  const { t } = useTranslation('inventory')
  const { scope, effectiveLocationIds } = useViewScope()
  const query = useQuery({ queryKey: locationScopedKey(['inventory-rebalance'], scope), queryFn: () => getRebalance(effectiveLocationIds), enabled: effectiveLocationIds.length > 0 })
  const moves = pairRebalanceRows(query.data?.data ?? [])
  if (query.isLoading) return <div role="status">{t('common.loading')}</div>
  if (moves.length === 0) return <EmptyState title={t('stockByLocation.rebalance.empty')} />
  return <section className="space-y-3" aria-label={t('stockByLocation.rebalance.title')}><h2 className={`text-lg font-semibold ${textColors.primary}`}>{t('stockByLocation.rebalance.title')}</h2><div className="space-y-2">{moves.map((move, index) => <div className={`${tokens.card.base} flex items-center justify-between p-3`} key={`${move.row.product_id}-${move.from.location_id}-${move.to.location_id}-${index}`}><span className={textColors.primary}>{move.row.name}</span><span className={textColors.tertiary}>{move.from.location_id} → {move.to.location_id} · {formatQuantity(move.quantity)}</span></div>)}</div></section>
}
