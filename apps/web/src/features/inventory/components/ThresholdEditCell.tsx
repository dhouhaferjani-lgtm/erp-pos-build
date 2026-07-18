import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { RequirePermission } from '@/components/auth'
import { QuantityInput } from '@/components/atoms/QuantityInput/QuantityInput'
import { formatQuantity } from '@/lib/format'
import { updateThresholds } from '../api/stockMatrix'
import type { MatrixCell, MatrixRow } from '../api/stockMatrix'

export function ThresholdEditCell({ row, locationId, cell }: { row: MatrixRow; locationId: string; cell: MatrixCell }) {
  const queryClient = useQueryClient()
  const { t } = useTranslation('inventory')
  const [min, setMin] = useState(cell.min_quantity ?? '')
  const [max, setMax] = useState(cell.max_quantity ?? '')
  const mutation = useMutation({ mutationFn: updateThresholds, onSuccess: () => { void queryClient.invalidateQueries({ queryKey: ['inventory-stock-matrix'] }) } })
  const save = () => mutation.mutate({ product_id: row.product_id, variant_id: row.variant_id, location_id: locationId, min_quantity: min || null, max_quantity: max || null })
  return (
    <RequirePermission permission="inventory.adjust">
      <div className="flex min-w-36 flex-col gap-1">
        <QuantityInput aria-label={t('stockByLocation.minQuantity')} value={min} onChange={setMin} decimalPlaces={4} min="0" onBlur={save} placeholder={formatQuantity(cell.min_quantity ?? '0')} />
        <QuantityInput aria-label={t('stockByLocation.maxQuantity')} value={max} onChange={setMax} decimalPlaces={4} min="0" onBlur={save} placeholder={formatQuantity(cell.max_quantity ?? '0')} />
      </div>
    </RequirePermission>
  )
}
