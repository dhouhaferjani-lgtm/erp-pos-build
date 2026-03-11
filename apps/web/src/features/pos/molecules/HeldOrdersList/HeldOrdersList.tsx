import { useTranslation } from 'react-i18next'
import { ShoppingBag, X } from 'lucide-react'
import { toast } from 'sonner'
import { cn } from '@/lib/utils'
import { useHeldOrders, useRecallOrder, useDiscardOrder } from '../../hooks/useHeldOrders'
import { HeldOrderCard } from '../HeldOrderCard'
import type { HeldOrderData } from '../../api/heldOrderApi'

export interface HeldOrdersListProps {
  terminalId: string
  shiftId?: string
  isOpen: boolean
  onClose: () => void
  onRecall: (order: HeldOrderData) => void
}

/**
 * HeldOrdersList - Slide-out panel showing all held (parked) orders.
 *
 * Displays a list of held order cards with recall and discard actions.
 * Shows an empty state when no orders are held.
 */
export function HeldOrdersList({
  terminalId,
  shiftId,
  isOpen,
  onClose,
  onRecall,
}: HeldOrdersListProps) {
  const { t } = useTranslation(['pos'])
  const { data: heldOrders, isLoading } = useHeldOrders(
    isOpen ? terminalId : undefined,
    shiftId,
  )
  const recallMutation = useRecallOrder()
  const discardMutation = useDiscardOrder()

  const handleRecall = (id: string) => {
    recallMutation.mutate(id, {
      onSuccess: (data) => {
        toast.success(t('pos:heldOrders.recallSuccess'))
        onRecall(data)
        onClose()
      },
      onError: () => {
        toast.error(t('pos:heldOrders.recallFailed'))
      },
    })
  }

  const handleDiscard = (id: string) => {
    discardMutation.mutate(id, {
      onSuccess: () => {
        toast.success(t('pos:heldOrders.discardSuccess'))
      },
      onError: () => {
        toast.error(t('pos:heldOrders.discardFailed'))
      },
    })
  }

  return (
    <>
      {/* Backdrop */}
      {isOpen && (
        <div
          className="fixed inset-0 z-40 bg-black/30 transition-opacity"
          onClick={onClose}
          aria-hidden="true"
        />
      )}

      {/* Panel */}
      <div
        className={cn(
          'fixed inset-y-0 right-0 z-50 w-full max-w-sm transform bg-white shadow-xl transition-transform duration-300',
          isOpen ? 'translate-x-0' : 'translate-x-full',
        )}
      >
        {/* Header */}
        <div className="flex items-center justify-between border-b px-4 py-3">
          <h2 className="text-lg font-semibold text-gray-900">
            {t('pos:heldOrders.title')}
          </h2>
          <button
            type="button"
            onClick={onClose}
            className="rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
          >
            <X className="h-5 w-5" />
          </button>
        </div>

        {/* Content */}
        <div className="h-full overflow-y-auto pb-20 pt-2">
          {isLoading && (
            <div className="flex items-center justify-center py-12">
              <div className="h-6 w-6 animate-spin rounded-full border-2 border-blue-600 border-t-transparent" />
            </div>
          )}

          {!isLoading && (!heldOrders || heldOrders.length === 0) && (
            <div className="flex flex-col items-center justify-center px-6 py-16 text-center">
              <ShoppingBag className="mb-3 h-12 w-12 text-gray-300" />
              <p className="text-sm font-medium text-gray-500">
                {t('pos:heldOrders.noHeldOrders')}
              </p>
              <p className="mt-1 text-xs text-gray-400">
                {t('pos:heldOrders.noHeldOrdersDescription')}
              </p>
            </div>
          )}

          {!isLoading && heldOrders && heldOrders.length > 0 && (
            <div className="space-y-3 px-4 py-2">
              {heldOrders.map((order) => (
                <HeldOrderCard
                  key={order.id}
                  order={order}
                  onRecall={handleRecall}
                  onDiscard={handleDiscard}
                  isRecalling={recallMutation.isPending}
                  isDiscarding={discardMutation.isPending}
                />
              ))}
            </div>
          )}
        </div>
      </div>
    </>
  )
}
