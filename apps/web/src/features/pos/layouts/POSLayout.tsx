import { useTranslation } from 'react-i18next'
import { Store, X } from 'lucide-react'

interface POSLayoutProps {
  children: React.ReactNode
  onExitPOS: () => void
  terminalCode?: string
  shiftId?: string
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
  shiftId,
}: POSLayoutProps) {
  const { t } = useTranslation(['common'])

  return (
    <div className="fixed inset-0 z-50 flex flex-col bg-gray-900">
      {/* Header: Minimal info only */}
      <header className="flex h-14 items-center justify-between bg-gray-800 px-4 shadow-lg">
        {/* Left: POS Title and Info */}
        <div className="flex items-center gap-4">
          <Store className="h-6 w-6 text-white" />
          <span className="text-lg font-medium text-white">
            {t('common:pos.title', { defaultValue: 'Point of Sale' })}
          </span>

          {/* Terminal Badge */}
          {terminalCode && (
            <div className="flex items-center gap-2 rounded-md bg-gray-700 px-3 py-1">
              <span className="text-xs font-medium text-gray-300">
                {t('common:pos.terminal.code', { defaultValue: 'Terminal' })}:
              </span>
              <span className="text-sm font-semibold text-white">{terminalCode}</span>
            </div>
          )}

          {/* Shift Badge */}
          {shiftId && (
            <div className="flex items-center gap-2 rounded-md bg-green-700 px-3 py-1">
              <span className="text-xs font-medium text-green-200">
                {t('common:pos.shift', { defaultValue: 'Shift' })}:
              </span>
              <span className="text-sm font-semibold text-white">#{shiftId}</span>
            </div>
          )}
        </div>

        {/* Right: Exit Button */}
        <button
          onClick={onExitPOS}
          className="flex items-center gap-2 rounded-lg bg-gray-700 px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 focus:ring-offset-gray-800"
          aria-label={t('common:pos.exitFullscreen', { defaultValue: 'Exit POS' })}
        >
          <X className="h-4 w-4" />
          <span>{t('common:pos.exitFullscreen', { defaultValue: 'Exit POS' })}</span>
        </button>
      </header>

      {/* Content: 100% height minus header (3.5rem = 56px) */}
      <main className="h-[calc(100vh-3.5rem)] overflow-hidden">{children}</main>
    </div>
  )
}
