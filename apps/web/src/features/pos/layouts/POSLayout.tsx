import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Store, X, Monitor, Clock, DollarSign, Settings } from 'lucide-react'
import { getCurrentShift, getShiftBalance } from '../api/shiftApi'
import { useCurrency } from '@/hooks/useCurrency'
import { ShiftOperationsMenu } from './ShiftOperationsMenu'
import { CashOperationModal } from '../components/CashOperationModal'

interface POSLayoutProps {
  children: React.ReactNode
  onExitPOS: () => void
  terminalCode?: string | undefined
}

/**
 * POSLayout - Fullscreen Layout for POS System
 *
 * This component provides a dedicated fullscreen experience for the POS system,
 * removing all dashboard navigation (sidebar, topbar) and providing minimal
 * header with essential information.
 *
 * Features:
 * - Full viewport coverage (fixed inset-0 z-50)
 * - Minimal header (3.5rem) with terminal/shift info
 * - Exit button to return to dashboard
 * - Content area fills remaining height
 */
export function POSLayout({
  children,
  onExitPOS,
  terminalCode,
}: POSLayoutProps) {
  const { t } = useTranslation(['common'])
  const { format: formatMoney } = useCurrency()
  const [shiftDuration, setShiftDuration] = useState<string | null>(null)
  const [isOperationsMenuOpen, setIsOperationsMenuOpen] = useState(false)
  const [activeModal, setActiveModal] = useState<'deposit' | 'payout' | null>(null)

  // Fetch current shift data (auto-refreshing every 30 seconds)
  const { data: shift } = useQuery({
    queryKey: ['pos', 'shift', terminalCode],
    queryFn: () => (terminalCode ? getCurrentShift(terminalCode) : null),
    enabled: !!terminalCode,
    refetchInterval: 30000, // 30 seconds
  })

  // Fetch shift balance (auto-refreshing every 30 seconds)
  const { data: balance } = useQuery({
    queryKey: ['pos', 'shift-balance', shift?.id],
    queryFn: () => (shift?.id ? getShiftBalance(shift.id) : null),
    enabled: !!shift?.id,
    refetchInterval: 30000, // 30 seconds
  })

  // Calculate shift duration and update every second
  useEffect(() => {
    if (!shift?.opened_at) {
      setShiftDuration(null)
      return
    }

    const calculateDuration = () => {
      const now = new Date()
      const openedAt = new Date(shift.opened_at)
      const diffMs = now.getTime() - openedAt.getTime()
      const diffMins = Math.floor(diffMs / 60000)
      const hours = Math.floor(diffMins / 60)
      const minutes = diffMins % 60

      if (hours > 0) {
        return `${hours}h ${minutes}m`
      }
      return `${minutes}m`
    }

    // Calculate immediately
    setShiftDuration(calculateDuration())

    // Update every minute
    const interval = setInterval(() => {
      setShiftDuration(calculateDuration())
    }, 60000)

    return () => { clearInterval(interval); }
  }, [shift?.opened_at])

  return (
    <div className="fixed inset-0 z-50 flex flex-col bg-gray-900">
      {/* Enhanced Header with Shift Management */}
      <header className="flex h-14 items-center justify-between bg-gray-800 px-4 shadow-lg">
        {/* Left: Terminal, Shift, Duration, Expected Cash */}
        <div className="flex items-center gap-4">
          <Store className="h-6 w-6 text-white" />
          <span className="text-lg font-medium text-white">
            {t('common:pos.title', { defaultValue: 'Point of Sale' })}
          </span>

          {/* Terminal Badge */}
          {terminalCode && (
            <div className="flex items-center gap-2 rounded-md bg-gray-700 px-3 py-1">
              <Monitor className="h-4 w-4 text-gray-300" />
              <span className="text-sm font-semibold text-white">{terminalCode}</span>
            </div>
          )}

          {/* Shift Badge */}
          {shift && (
            <>
              <div className="flex items-center gap-2 rounded-md bg-green-700 px-3 py-1">
                <span className="text-xs font-medium text-green-200">
                  {t('common:pos.shift', { defaultValue: 'Shift' })}:
                </span>
                <span className="text-sm font-semibold text-white">
                  #{shift.shift_number}
                </span>
              </div>

              {/* Duration Badge */}
              {shiftDuration && (
                <div className="flex items-center gap-2 rounded-md bg-blue-700 px-3 py-1">
                  <Clock className="h-4 w-4 text-blue-200" />
                  <span className="text-sm font-semibold text-white">{shiftDuration}</span>
                </div>
              )}

              {/* Expected Cash Badge */}
              {balance && (
                <div className="flex items-center gap-2 rounded-md bg-yellow-700 px-3 py-1">
                  <DollarSign className="h-4 w-4 text-yellow-200" />
                  <span className="text-sm font-semibold text-white">
                    {formatMoney(parseFloat(balance.expected_cash))}
                  </span>
                </div>
              )}
            </>
          )}
        </div>

        {/* Right: Operations Menu + Exit */}
        <div className="flex items-center gap-3">
          {shift && (
            <button
              onClick={() => { setIsOperationsMenuOpen(!isOperationsMenuOpen); }}
              className="flex items-center gap-2 rounded-lg bg-blue-700 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 focus:ring-offset-gray-800"
              aria-label={t('common:pos.operations', { defaultValue: 'Operations' })}
            >
              <Settings className="h-4 w-4" />
              <span>{t('common:pos.operations', { defaultValue: 'Operations' })}</span>
            </button>
          )}

          <button
            onClick={onExitPOS}
            className="flex items-center gap-2 rounded-lg bg-gray-700 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 focus:ring-offset-gray-800"
            aria-label={t('common:pos.exitFullscreen', { defaultValue: 'Exit POS' })}
          >
            <X className="h-4 w-4" />
            <span>{t('common:pos.exitFullscreen', { defaultValue: 'Exit POS' })}</span>
          </button>
        </div>
      </header>

      {/* Shift Operations Menu */}
      {shift && terminalCode && (
        <ShiftOperationsMenu
          shift={shift}
          balance={balance}
          terminalCode={terminalCode}
          isOpen={isOperationsMenuOpen}
          onClose={() => { setIsOperationsMenuOpen(false); }}
          onOpenCashDeposit={() => {
            setActiveModal('deposit')
          }}
          onOpenCashPayout={() => {
            setActiveModal('payout')
          }}
        />
      )}

      {/* Cash Operation Modal (Deposit/Payout) */}
      {activeModal && shift && terminalCode && (
        <CashOperationModal
          isOpen={!!activeModal}
          onClose={() => { setActiveModal(null); }}
          type={activeModal}
          shiftId={shift.id}
          terminalCode={terminalCode}
        />
      )}

      {/* Content: 100% height minus header (3.5rem = 56px) */}
      <main className="h-[calc(100vh-3.5rem)] overflow-hidden">{children}</main>
    </div>
  )
}
