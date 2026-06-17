import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { Store, X, Monitor, Clock, DollarSign, Settings } from 'lucide-react'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { getCurrentShift, getShiftBalance } from '../api/shiftApi'
import { useCurrency } from '@/hooks/useCurrency'
import { ShiftOperationsMenu } from './ShiftOperationsMenu'
import { CashOperationModal } from '../components/CashOperationModal'
import { usePosTenantScope } from '../hooks/usePosTenantScope'
import { cn } from '@/lib/utils'
import { colors, tokens, textColors, focusRing } from '@/lib/designTokens'

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
  const { hasTenantScope } = usePosTenantScope()

  // Fetch current shift data (auto-refreshing every 30 seconds)
  const { data: shift } = useQuery({
    queryKey: tenantScopedKey(['pos', 'shift', terminalCode]),
    queryFn: () => (terminalCode ? getCurrentShift(terminalCode) : null),
    enabled: !!terminalCode && hasTenantScope,
    refetchInterval: 30000, // 30 seconds
  })

  // Fetch shift balance (auto-refreshing every 30 seconds)
  const { data: balance } = useQuery({
    queryKey: tenantScopedKey(['pos', 'shift-balance', shift?.id]),
    queryFn: () => (shift?.id ? getShiftBalance(shift.id) : null),
    enabled: !!shift?.id && hasTenantScope,
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
    <div className={cn('fixed inset-0 z-50 flex flex-col', colors.neutral[900])}>
      {/* Enhanced Header with Shift Management — deliberate dark brand bar */}
      <header
        className={cn(
          'flex h-14 items-center justify-between px-4 shadow-lg',
          colors.neutral[800]
        )}
      >
        {/* Left: Terminal, Shift, Duration, Expected Cash */}
        <div className="flex items-center gap-4">
          <Store className={cn('h-6 w-6', textColors.inverse)} />
          <span className={cn('text-lg font-medium', textColors.inverse)}>
            {t('common:pos.title', { defaultValue: 'Point of Sale' })}
          </span>

          {/* Terminal Badge */}
          {terminalCode && (
            <span className={cn(tokens.badge.base, tokens.badge.gray)}>
              <Monitor className="mr-1.5 h-4 w-4" />
              {terminalCode}
            </span>
          )}

          {/* Shift Badge */}
          {shift && (
            <>
              <span className={cn(tokens.badge.base, tokens.badge.green)}>
                {t('common:pos.shift', { defaultValue: 'Shift' })}: #
                {shift.shift_number}
              </span>

              {/* Duration Badge */}
              {shiftDuration && (
                <span className={cn(tokens.badge.base, tokens.badge.blue)}>
                  <Clock className="mr-1.5 h-4 w-4" />
                  {shiftDuration}
                </span>
              )}

              {/* Expected Cash Badge */}
              {balance && (
                <span className={cn(tokens.badge.base, tokens.badge.yellow)}>
                  <DollarSign className="mr-1.5 h-4 w-4" />
                  {formatMoney(parseFloat(balance.expected_cash))}
                </span>
              )}
            </>
          )}
        </div>

        {/* Right: Operations Menu + Exit */}
        <div className="flex items-center gap-3">
          {shift && (
            <button
              onClick={() => { setIsOperationsMenuOpen(!isOperationsMenuOpen); }}
              className={cn(
                tokens.button.base,
                tokens.button.primary,
                tokens.button.sizes.md,
                'gap-2'
              )}
              aria-label={t('common:pos.operations', { defaultValue: 'Operations' })}
            >
              <Settings className="h-4 w-4" />
              <span>{t('common:pos.operations', { defaultValue: 'Operations' })}</span>
            </button>
          )}

          <button
            onClick={onExitPOS}
            className={cn(
              tokens.button.base,
              tokens.button.secondary,
              tokens.button.sizes.md,
              focusRing.default,
              'gap-2'
            )}
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
