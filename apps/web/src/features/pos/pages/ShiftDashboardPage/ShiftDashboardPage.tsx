import { useState, useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '@/lib/utils'
import { POSButton, MoneyInput } from '../../atoms'

import { bcsub } from '@/lib/decimal'
import { useCurrency } from '@/hooks/useCurrency'
import type { XReportResponse } from '../../api/shiftApi'
import {
  Clock,
  DollarSign,
  FileText,
  LogOut,
  Printer,
  TrendingDown,
  TrendingUp,
  Loader2,
} from 'lucide-react'
import { formatDistanceToNow } from 'date-fns'

export interface Shift {
  id: string
  terminal_id: string
  shift_number: number
  cashier_id: string
  cashier_name: string
  opening_cash: string
  expected_cash: string
  opened_at: string
  status: 'OPEN' | 'CLOSED'
}

export interface Terminal {
  id: string
  code: string
  location_id: string
  location_name: string
}

export interface ShiftDashboardPageProps {
  currentShift: Shift | null
  terminal: Terminal
  onOpenShift: (openingBalance: string) => void
  onCloseShift: (actualCash: string) => void
  onGenerateXReport: () => void
  xReportData?: XReportResponse | null
  onCloseXReport?: () => void
  onCashDeposit: (data: { amount: string; reason: string }) => void
  onCashPayout: (data: { amount: string; reason: string }) => void
  isLoading?: boolean
  touchOptimized?: boolean
  className?: string
}

type ModalType = 'open' | 'close' | 'deposit' | 'payout' | null

export function ShiftDashboardPage({
  currentShift,
  terminal,
  onOpenShift,
  onCloseShift,
  onGenerateXReport,
  xReportData,
  onCloseXReport,
  onCashDeposit,
  onCashPayout,
  isLoading = false,
  touchOptimized = false,
  className,
}: ShiftDashboardPageProps) {
  const { t } = useTranslation(['pos', 'common'])
  const { decimals } = useCurrency()

  const [activeModal, setActiveModal] = useState<ModalType>(null)
  const [openingBalance, setOpeningBalance] = useState('')
  const [actualCash, setActualCash] = useState('')
  const [operationAmount, setOperationAmount] = useState('')
  const [operationReason, setOperationReason] = useState('')

  // Calculate shift duration
  const shiftDuration = useMemo(() => {
    if (!currentShift) return null
    return formatDistanceToNow(new Date(currentShift.opened_at), {
      addSuffix: false,
    })
  }, [currentShift])

  // Calculate variance for close shift.
  // Uses bcsub (big.js) instead of parseFloat to avoid IEEE 754 float drift
  // (e.g. TND: parseFloat('250.103') − parseFloat('250.100') === 0.0030000000000001355).
  // The result is a precise decimal string rendered directly in JSX.
  const cashVariance = useMemo(() => {
    if (!currentShift || !actualCash) return null
    try {
      return bcsub(actualCash, currentShift.expected_cash, decimals)
    } catch {
      // Invalid numeric input — suppress variance display.
      return null
    }
  }, [currentShift, actualCash, decimals])

  const handleOpenShift = () => {
    onOpenShift(openingBalance)
    setActiveModal(null)
    setOpeningBalance('')
  }

  const handleCloseShift = () => {
    onCloseShift(actualCash)
    setActiveModal(null)
    setActualCash('')
  }

  const handleCashOperation = (type: 'deposit' | 'payout') => {
    const handler = type === 'deposit' ? onCashDeposit : onCashPayout
    handler({
      amount: operationAmount,
      reason: operationReason,
    })
    setActiveModal(null)
    setOperationAmount('')
    setOperationReason('')
  }

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-screen">
        <div className="text-center">
          <Loader2 className="w-12 h-12 animate-spin text-blue-600 mx-auto mb-4" />
          <p className="text-gray-600">{t('common:loading')}</p>
        </div>
      </div>
    )
  }

  return (
    <div
      className={cn(
        'min-h-screen bg-gray-50',
        touchOptimized ? 'p-6' : 'p-4',
        className
      )}
    >
      {/* Header */}
      <div className="bg-white rounded-lg shadow-sm p-6 mb-6">
        <div className="flex items-center justify-between">
          <div>
            <h1
              className={cn(
                'font-bold text-gray-900',
                touchOptimized ? 'text-3xl' : 'text-2xl'
              )}
            >
              {terminal.code}
            </h1>
            <p className="text-gray-600 mt-1">{terminal.location_name}</p>
          </div>

          {currentShift && shiftDuration && (
            <div className="flex items-center gap-2 text-gray-600">
              <Clock className="w-5 h-5" />
              <span>{shiftDuration}</span>
            </div>
          )}
        </div>
      </div>

      {/* Current Shift Status */}
      {currentShift ? (
        <div className="space-y-6">
          {/* Shift Info Card */}
          <div className="bg-white rounded-lg shadow-sm p-6">
            <div className="flex items-center justify-between mb-4">
              <h2
                className={cn(
                  'font-semibold text-gray-900',
                  touchOptimized ? 'text-2xl' : 'text-xl'
                )}
              >
                {t('pos:shiftDashboard.shiftNumber', { number: currentShift.shift_number })}
              </h2>
              <span className="px-3 py-1 bg-green-100 text-green-800 rounded-full text-sm font-medium">
                {t('pos:shiftDashboard.statusOpen')}
              </span>
            </div>

            <div className="grid grid-cols-2 gap-4">
              <div>
                <p className="text-sm text-gray-600">{t('pos:shiftDashboard.cashier')}</p>
                <p className="font-medium text-gray-900">
                  {currentShift.cashier_name}
                </p>
              </div>
              <div>
                <p className="text-sm text-gray-600">{t('pos:shiftDashboard.openingBalance')}</p>
                <p className="font-medium text-gray-900">
                  {currentShift.opening_cash}
                </p>
              </div>
              <div>
                <p className="text-sm text-gray-600">{t('pos:shiftDashboard.expectedCash')}</p>
                <p className="font-medium text-gray-900">
                  {currentShift.expected_cash}
                </p>
              </div>
              <div>
                <p className="text-sm text-gray-600">{t('pos:shiftDashboard.duration')}</p>
                <p className="font-medium text-gray-900">{shiftDuration}</p>
              </div>
            </div>
          </div>

          {/* Actions Grid */}
          <div className="grid grid-cols-2 gap-4">
            <POSButton
              variant="secondary"
              size={touchOptimized ? 'lg' : 'md'}
              onClick={() => { setActiveModal('deposit'); }}
              icon={<TrendingDown className="w-5 h-5" />}
              fullWidth
              touchOptimized={touchOptimized}
            >
              {t('pos:shiftDashboard.cashDeposit')}
            </POSButton>

            <POSButton
              variant="secondary"
              size={touchOptimized ? 'lg' : 'md'}
              onClick={() => { setActiveModal('payout'); }}
              icon={<TrendingUp className="w-5 h-5" />}
              fullWidth
              touchOptimized={touchOptimized}
            >
              {t('pos:shiftDashboard.cashPayout')}
            </POSButton>

            <POSButton
              variant="primary"
              size={touchOptimized ? 'lg' : 'md'}
              onClick={onGenerateXReport}
              icon={<FileText className="w-5 h-5" />}
              fullWidth
              touchOptimized={touchOptimized}
            >
              {t('pos:shiftDashboard.xReport')}
            </POSButton>

            <POSButton
              variant="danger"
              size={touchOptimized ? 'lg' : 'md'}
              onClick={() => { setActiveModal('close'); }}
              icon={<LogOut className="w-5 h-5" />}
              fullWidth
              touchOptimized={touchOptimized}
            >
              {t('pos:shiftDashboard.closeShift')}
            </POSButton>
          </div>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow-sm p-12 text-center">
          <DollarSign className="w-16 h-16 text-gray-300 mx-auto mb-4" />
          <h2 className="text-xl font-semibold text-gray-900 mb-2">
            {t('pos:shiftDashboard.noActiveShift')}
          </h2>
          <p className="text-gray-600 mb-6">
            {t('pos:shiftDashboard.noActiveShiftDescription')}
          </p>
          <POSButton
            variant="primary"
            size={touchOptimized ? 'lg' : 'md'}
            onClick={() => { setActiveModal('open'); }}
            touchOptimized={touchOptimized}
          >
            {t('pos:shiftDashboard.openShift')}
          </POSButton>
        </div>
      )}

      {/* Modals */}
      {activeModal === 'open' && (
        <Modal
          title={t('pos:shiftDashboard.openShift')}
          onClose={() => { setActiveModal(null); }}
          touchOptimized={touchOptimized}
        >
          <MoneyInput
            label={t('pos:shiftDashboard.openingBalance')}
            value={openingBalance}
            onChange={setOpeningBalance}
            placeholder={t('pos:shiftDashboard.openingBalance')}
            touchOptimized={touchOptimized}
            autoFocus
          />
          <div className="flex gap-3 mt-6">
            <POSButton
              variant="secondary"
              onClick={() => { setActiveModal(null); }}
              fullWidth
            >
              {t('common:cancel')}
            </POSButton>
            <POSButton
              variant="primary"
              onClick={handleOpenShift}
              fullWidth
              disabled={!openingBalance}
            >
              {t('common:confirm')}
            </POSButton>
          </div>
        </Modal>
      )}

      {activeModal === 'close' && currentShift && (
        <Modal
          title={t('pos:shiftDashboard.closeShift')}
          onClose={() => { setActiveModal(null); }}
          touchOptimized={touchOptimized}
        >
          <div className="space-y-4">
            <div className="bg-gray-50 rounded-lg p-4">
              <p className="text-sm text-gray-600">{t('pos:shiftDashboard.expectedCash')}</p>
              <p className="text-xl font-bold text-gray-900">
                {currentShift.expected_cash}
              </p>
            </div>

            <MoneyInput
              label={t('pos:shiftDashboard.actualCashCount')}
              value={actualCash}
              onChange={setActualCash}
              placeholder={t('pos:shiftDashboard.actualCashCount')}
              touchOptimized={touchOptimized}
              autoFocus
            />

            {cashVariance && (
              <div
                className={cn(
                  'rounded-lg p-4',
                  parseFloat(cashVariance) === 0 && 'bg-green-50',
                  parseFloat(cashVariance) > 0 && 'bg-blue-50',
                  parseFloat(cashVariance) < 0 && 'bg-red-50'
                )}
              >
                <p className="text-sm text-gray-600">{t('pos:shiftDashboard.variance')}</p>
                <p
                  className={cn(
                    'text-xl font-bold',
                    parseFloat(cashVariance) === 0 && 'text-green-600',
                    parseFloat(cashVariance) > 0 && 'text-blue-600',
                    parseFloat(cashVariance) < 0 && 'text-red-600'
                  )}
                >
                  {cashVariance}
                </p>
              </div>
            )}
          </div>

          <div className="flex gap-3 mt-6">
            <POSButton
              variant="secondary"
              onClick={() => { setActiveModal(null); }}
              fullWidth
            >
              {t('common:cancel')}
            </POSButton>
            <POSButton
              variant="danger"
              onClick={handleCloseShift}
              fullWidth
              disabled={!actualCash}
            >
              {t('common:confirm')}
            </POSButton>
          </div>
        </Modal>
      )}

      {(activeModal === 'deposit' || activeModal === 'payout') && (
        <Modal
          title={activeModal === 'deposit' ? t('pos:shiftDashboard.cashDeposit') : t('pos:shiftDashboard.cashPayout')}
          onClose={() => { setActiveModal(null); }}
          touchOptimized={touchOptimized}
        >
          <div className="space-y-4">
            <MoneyInput
              label={t('pos:shiftDashboard.amount')}
              value={operationAmount}
              onChange={setOperationAmount}
              placeholder={t('pos:shiftDashboard.amount')}
              touchOptimized={touchOptimized}
              autoFocus
            />

            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">
                {t('pos:shiftDashboard.reason')}
              </label>
              <input
                type="text"
                value={operationReason}
                onChange={(e) => { setOperationReason(e.target.value); }}
                placeholder={t('pos:shiftDashboard.reason')}
                className="w-full px-4 py-2 rounded-lg border border-gray-300 focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </div>

          <div className="flex gap-3 mt-6">
            <POSButton
              variant="secondary"
              onClick={() => { setActiveModal(null); }}
              fullWidth
            >
              {t('common:cancel')}
            </POSButton>
            <POSButton
              variant="primary"
              onClick={() => { handleCashOperation(activeModal); }}
              fullWidth
              disabled={!operationAmount || !operationReason}
            >
              {t('common:confirm')}
            </POSButton>
          </div>
        </Modal>
      )}

      {/* X Report Modal */}
      {xReportData && onCloseXReport && (
        <Modal
          title={t('pos:xReport.title')}
          onClose={onCloseXReport}
          touchOptimized={touchOptimized}
        >
          <div className="x-report-print-area space-y-6">
            {/* Generated At */}
            <p className="text-sm text-gray-500">
              {t('pos:xReport.generatedAt')}: {new Date(xReportData.generated_at).toLocaleString()}
            </p>

            {/* Sales Summary */}
            <div>
              <h4 className="font-semibold text-gray-900 mb-3">{t('pos:xReport.salesSummary')}</h4>
              <div className="bg-gray-50 rounded-lg divide-y divide-gray-200">
                <div className="flex justify-between px-4 py-2">
                  <span className="text-gray-600">{t('pos:xReport.salesCount')}</span>
                  <span className="font-medium">{xReportData.sales_count}</span>
                </div>
                <div className="flex justify-between px-4 py-2">
                  <span className="text-gray-600">{t('pos:xReport.grossSales')}</span>
                  <span className="font-medium">{xReportData.gross_sales}</span>
                </div>
                <div className="flex justify-between px-4 py-2">
                  <span className="text-gray-600">{t('pos:xReport.netSales')}</span>
                  <span className="font-medium">{xReportData.net_sales}</span>
                </div>
                <div className="flex justify-between px-4 py-2">
                  <span className="text-gray-600">{t('pos:xReport.taxAmount')}</span>
                  <span className="font-medium">{xReportData.tax_amount}</span>
                </div>
                <div className="flex justify-between px-4 py-2">
                  <span className="text-gray-600">{t('pos:xReport.refundsCount')}</span>
                  <span className="font-medium">{xReportData.refunds_count}</span>
                </div>
              </div>
            </div>

            {/* VAT Breakdown */}
            {xReportData.vat_breakdown.length > 0 && (
              <div>
                <h4 className="font-semibold text-gray-900 mb-3">{t('pos:xReport.vatBreakdown')}</h4>
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-gray-500 border-b">
                      <th className="pb-2 font-medium">{t('pos:xReport.rate')}</th>
                      <th className="pb-2 font-medium text-right">{t('pos:xReport.net')}</th>
                      <th className="pb-2 font-medium text-right">{t('pos:xReport.vat')}</th>
                      <th className="pb-2 font-medium text-right">{t('pos:xReport.gross')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {xReportData.vat_breakdown.map((entry) => (
                      <tr key={entry.rate} className="border-b border-gray-100">
                        <td className="py-2">{entry.rate}</td>
                        <td className="py-2 text-right">{entry.net}</td>
                        <td className="py-2 text-right">{entry.vat}</td>
                        <td className="py-2 text-right">{entry.gross}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}

            {/* Payment Methods */}
            {xReportData.payment_methods.length > 0 && (
              <div>
                <h4 className="font-semibold text-gray-900 mb-3">{t('pos:xReport.paymentMethods')}</h4>
                <table className="w-full text-sm">
                  <thead>
                    <tr className="text-left text-gray-500 border-b">
                      <th className="pb-2 font-medium">{t('pos:xReport.method')}</th>
                      <th className="pb-2 font-medium text-right">{t('pos:xReport.count')}</th>
                      <th className="pb-2 font-medium text-right">{t('pos:xReport.amount')}</th>
                    </tr>
                  </thead>
                  <tbody>
                    {xReportData.payment_methods.map((entry) => (
                      <tr key={entry.method} className="border-b border-gray-100">
                        <td className="py-2">{entry.method}</td>
                        <td className="py-2 text-right">{entry.count}</td>
                        <td className="py-2 text-right">{entry.amount}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          <div className="flex gap-3 mt-6">
            <POSButton
              variant="secondary"
              onClick={() => { window.print(); }}
              icon={<Printer className="w-4 h-4" />}
              fullWidth
            >
              {t('pos:xReport.print')}
            </POSButton>
            <POSButton
              variant="primary"
              onClick={onCloseXReport}
              fullWidth
            >
              {t('common:close')}
            </POSButton>
          </div>
        </Modal>
      )}
    </div>
  )
}

// Modal Component
function Modal({
  title,
  children,
  onClose: _onClose,
  touchOptimized = false,
}: {
  title: string
  children: React.ReactNode
  onClose: () => void
  touchOptimized?: boolean
}) {
  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center p-4 z-50">
      <div
        className={cn(
          'bg-white rounded-lg shadow-xl w-full max-w-md',
          touchOptimized ? 'p-6' : 'p-4'
        )}
      >
        <h3
          className={cn(
            'font-bold text-gray-900 mb-4',
            touchOptimized ? 'text-2xl' : 'text-xl'
          )}
        >
          {title}
        </h3>
        {children}
      </div>
    </div>
  )
}
