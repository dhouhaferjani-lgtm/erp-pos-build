import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { locationScopedKey } from '@/lib/locationScopedKey'
import { formatQuantity } from '@/lib/format'
import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { Button } from '@/components/atoms/Button/Button'
import { Modal, ModalFooter } from '@/components/organisms/Modal'
import { getProductStock } from '@/features/products/api/productStock'
import { useViewScope } from '@/features/locations/hooks/useViewScope'
import { suggestSource } from '../lib/suggestSource'

export function TransferSourceSuggestion({ productId, variantId, destinationLocationId, requestedQuantity, lineCount, onUseSource }: { productId: string; variantId: string | null; destinationLocationId: string; requestedQuantity: string; lineCount: number; onUseSource: (locationId: string) => void }) {
  const { t } = useTranslation('stock-transfers')
  const { scope } = useViewScope()
  const [confirmOpen, setConfirmOpen] = useState(false)
  const query = useQuery({ queryKey: locationScopedKey(['product-stock-suggestion', productId, variantId], scope), queryFn: () => getProductStock(productId), enabled: productId !== '' })
  const locations = query.data?.locations ?? []
  const suggestion = suggestSource(locations, destinationLocationId, requestedQuantity)
  if (query.isLoading) return <span className={`text-xs ${textColors.tertiary}`}>{t('create.suggestion.loading')}</span>
  if (query.isError || locations.length === 0) return null
  return (
    <div className={`${tokens.card.base} mt-2 space-y-2 p-3`}>
      <p className={`text-xs font-medium ${textColors.secondary}`}>{t('create.suggestion.title')}</p>
      <div className="grid gap-1">
        {locations.map((location) => <div key={location.location_id} className={`flex items-center justify-between border-b py-1 ${borderColors.light}`}>
          <span className={location.location_id === destinationLocationId ? textColors.warning : location.location_id === suggestion?.locationId ? textColors.success : textColors.secondary}>{location.location_name}</span>
          <span className={textColors.secondary}>{formatQuantity(location.available)} / {location.max_quantity === null ? '—' : formatQuantity(location.max_quantity)}</span>
        </div>)}
      </div>
      {suggestion ? <>
        <Button type="button" size="sm" variant="secondary" onClick={() => setConfirmOpen(true)}>{t('create.suggestion.useSource')}</Button>
        <Modal isOpen={confirmOpen} onClose={() => setConfirmOpen(false)} title={t('create.suggestion.confirmTitle')}>
          <div className="p-4 text-sm">{t('create.suggestion.confirmSwitch', { count: lineCount })}</div>
          <ModalFooter>
            <Button type="button" variant="secondary" onClick={() => setConfirmOpen(false)}>{t('common.cancel')}</Button>
            <Button type="button" onClick={() => { onUseSource(suggestion.locationId); setConfirmOpen(false) }}>{t('common.confirm')}</Button>
          </ModalFooter>
        </Modal>
      </> : <p className={`text-xs ${textColors.tertiary}`}>{t('create.suggestion.none')}</p>}
    </div>
  )
}
