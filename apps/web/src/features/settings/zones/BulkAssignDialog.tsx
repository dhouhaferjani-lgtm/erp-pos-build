import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { X } from 'lucide-react'
import { Button } from '@/components/atoms'
import { Modal, ModalContent, ModalFooter } from '@/components/organisms/Modal'
import { ProductPicker, type ProductPickerValue } from '@/components/molecules/pickers/ProductPicker'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'
import { listZoneProducts, type Zone } from './api'

interface BulkAssignDialogProps {
  isOpen: boolean
  onClose: () => void
  zone: Zone | null
  onSubmit: (productIds: string[]) => void
  isPending: boolean
}

export function BulkAssignDialog({ isOpen, onClose, zone, onSubmit, isPending }: BulkAssignDialogProps) {
  const { t } = useTranslation(['inventory', 'common'])
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const [pending, setPending] = useState<ProductPickerValue[]>([])
  const [pickerValue, setPickerValue] = useState<ProductPickerValue | null>(null)

  // Reset the picker's local selections whenever the dialog transitions
  // closed→open (including reopening for a different zone). Adjusting state
  // during render (rather than in a useEffect) avoids the extra commit +
  // re-render an effect-based reset would cause — React re-renders
  // immediately when render-phase state updates like this bail out.
  // See https://react.dev/learn/you-might-not-need-an-effect#adjusting-some-state-when-a-prop-changes
  const [prevIsOpen, setPrevIsOpen] = useState(isOpen)
  if (isOpen !== prevIsOpen) {
    setPrevIsOpen(isOpen)
    if (!isOpen) {
      setPending([])
      setPickerValue(null)
    }
  }

  const zoneId = zone?.id ?? null

  const { data: assigned, isLoading: isLoadingAssigned } = useQuery({
    queryKey: tenantScopedKey(['zone-products', zoneId]),
    queryFn: () => {
      if (zoneId === null) {
        return Promise.resolve([])
      }
      return listZoneProducts(zoneId)
    },
    enabled: isOpen && zoneId !== null && tenantId !== null && companyId !== null,
  })

  const assignedIds = new Set((assigned ?? []).map((a) => a.product_id))

  const handlePickerChange = (value: ProductPickerValue | null) => {
    if (value === null) {
      return
    }
    setPending((current) => {
      if (current.some((p) => p.id === value.id) || assignedIds.has(value.id)) {
        return current
      }
      return [...current, value]
    })
    setPickerValue(null)
  }

  const removePending = (id: string) => {
    setPending((current) => current.filter((p) => p.id !== id))
  }

  const handleSubmit = () => {
    if (pending.length === 0) {
      return
    }
    onSubmit(pending.map((p) => p.id))
  }

  if (zone === null) {
    return null
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title={t('inventory:zones.bulkAssign.title', { zone: zone.name })}
      size="lg"
    >
      <ModalContent>
        <div className="space-y-4">
          <div>
            <h4 className={cn('text-sm font-medium', textColors.primary)}>
              {t('inventory:zones.bulkAssign.currentlyAssigned')}
            </h4>
            {isLoadingAssigned ? (
              <p className={cn('mt-1 text-sm', textColors.tertiary)}>{t('common:status.loading')}</p>
            ) : (assigned ?? []).length === 0 ? (
              <p className={cn('mt-1 text-sm', textColors.tertiary)}>
                {t('inventory:zones.bulkAssign.noneAssigned')}
              </p>
            ) : (
              <ul className="mt-1 flex flex-wrap gap-2">
                {(assigned ?? []).map((a) => (
                  <li key={a.id} className={cn(tokens.badge.base, tokens.badge.gray)}>
                    {a.product_name ?? a.product_sku ?? a.product_id}
                  </li>
                ))}
              </ul>
            )}
          </div>

          <div>
            <h4 className={cn('text-sm font-medium', textColors.primary)}>
              {t('inventory:zones.bulkAssign.addProducts')}
            </h4>
            <div className="mt-1">
              <ProductPicker value={pickerValue} onChange={handlePickerChange} label="" productType="all" />
            </div>

            {pending.length > 0 && (
              <>
                <p className={cn('mt-2 text-xs', textColors.tertiary)}>
                  {t('inventory:zones.bulkAssign.pendingCount', { count: pending.length })}
                </p>
                <ul className="mt-2 flex flex-wrap gap-2">
                  {pending.map((p) => (
                    <li
                      key={p.id}
                      className={cn(
                        'inline-flex items-center gap-1',
                        tokens.badge.base,
                        tokens.badge.blue,
                      )}
                    >
                      {p.name}
                      <button
                        type="button"
                        aria-label={t('inventory:zones.bulkAssign.removeProduct', { name: p.name })}
                        onClick={() => { removePending(p.id) }}
                        className={textColors.tertiary}
                      >
                        <X className="h-3 w-3" aria-hidden />
                      </button>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </div>
        </div>
      </ModalContent>
      <ModalFooter>
        <Button type="button" variant="secondary" onClick={onClose}>
          {t('common:cancel')}
        </Button>
        <Button type="button" onClick={handleSubmit} disabled={isPending || pending.length === 0}>
          {isPending ? t('common:saving') : t('inventory:zones.bulkAssign.submit')}
        </Button>
      </ModalFooter>
    </Modal>
  )
}
