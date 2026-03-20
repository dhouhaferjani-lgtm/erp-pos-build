import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Pause } from 'lucide-react'
import { toast } from 'sonner'
import { Modal, ModalHeader, ModalContent, ModalFooter } from '@/components/organisms/Modal/Modal'
import { useHoldOrder } from '../hooks/useHeldOrders'
import type { CartSnapshot } from '../api/heldOrderApi'

export interface HoldOrderButtonProps {
  terminalId: string
  shiftId: string
  cartSnapshot: CartSnapshot | null
  disabled?: boolean
  onSuccess?: () => void
}

/**
 * HoldOrderButton - Button to hold the current cart.
 *
 * Opens a dialog asking for an optional label before holding the order.
 * The button is disabled when the cart is empty or not ready.
 */
export function HoldOrderButton({
  terminalId,
  shiftId,
  cartSnapshot,
  disabled = false,
  onSuccess,
}: HoldOrderButtonProps) {
  const { t } = useTranslation(['pos', 'common'])
  const [isDialogOpen, setIsDialogOpen] = useState(false)
  const [label, setLabel] = useState('')

  const holdMutation = useHoldOrder()

  const hasItems = cartSnapshot !== null && cartSnapshot.lines.length > 0
  const isButtonDisabled = disabled || !hasItems || holdMutation.isPending

  const handleOpenDialog = () => {
    setLabel('')
    setIsDialogOpen(true)
  }

  const handleHold = () => {
    if (!cartSnapshot || cartSnapshot.lines.length === 0) {
      return
    }

    holdMutation.mutate(
      {
        terminal_id: terminalId,
        shift_id: shiftId,
        label: label.trim() || null,
        cart_snapshot: cartSnapshot,
      },
      {
        onSuccess: () => {
          toast.success(t('pos:heldOrders.holdSuccess'))
          setIsDialogOpen(false)
          setLabel('')
          onSuccess?.()
        },
        onError: () => {
          toast.error(t('pos:heldOrders.holdFailed'))
        },
      },
    )
  }

  return (
    <>
      <button
        type="button"
        onClick={handleOpenDialog}
        disabled={isButtonDisabled}
        className="flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50"
      >
        <Pause className="h-4 w-4" />
        {t('pos:heldOrders.holdOrder')}
      </button>

      <Modal isOpen={isDialogOpen} onClose={() => { setIsDialogOpen(false); }}>
        <ModalHeader title={t('pos:heldOrders.holdOrder')} onClose={() => { setIsDialogOpen(false); }} />
        <ModalContent>
          <div className="space-y-4">
            <div>
              <label
                htmlFor="hold-order-label"
                className="mb-1 block text-sm font-medium text-gray-700"
              >
                {t('pos:heldOrders.label')}
              </label>
              <input
                id="hold-order-label"
                type="text"
                value={label}
                onChange={(e) => { setLabel(e.target.value); }}
                placeholder={t('pos:heldOrders.labelPlaceholder')}
                maxLength={255}
                className="w-full rounded-md border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500"
                autoFocus
                onKeyDown={(e) => {
                  if (e.key === 'Enter') {
                    handleHold()
                  }
                }}
              />
            </div>
          </div>
        </ModalContent>
        <ModalFooter>
          <button
            type="button"
            onClick={() => { setIsDialogOpen(false); }}
            className="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50"
          >
            {t('common:cancel')}
          </button>
          <button
            type="button"
            onClick={handleHold}
            disabled={holdMutation.isPending}
            className="rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
          >
            {holdMutation.isPending ? '...' : t('pos:heldOrders.holdOrder')}
          </button>
        </ModalFooter>
      </Modal>
    </>
  )
}
