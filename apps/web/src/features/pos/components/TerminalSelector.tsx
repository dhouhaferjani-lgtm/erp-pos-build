import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { Monitor, MapPin, Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'

interface Terminal {
  id: string
  type: 'web' | 'physical'
  code: string
  name: string
  location_name: string | null
  is_active: boolean
}

export interface TerminalSelectorProps {
  onSelect: (terminalCode: string) => void
  lastUsedCode?: string | null
}

/**
 * TerminalSelector - Grid of active terminals for POS session entry
 *
 * Shown when no terminal is selected. Displays active terminals
 * in a grid layout. Highlights last-used terminal if applicable.
 */
export function TerminalSelector({ onSelect, lastUsedCode }: TerminalSelectorProps) {
  const { t } = useTranslation(['pos'])
  const tenantId = useAuthStore((state) => state.user?.tenant_id ?? null)
  const companyId = useCompanyStore((state) => state.currentCompanyId ?? null)

  const { data: terminals = [], isLoading } = useQuery({
    queryKey: tenantScopedKey(['pos', 'terminals']),
    queryFn: () => apiGet<Terminal[]>('/pos/terminals'),
    enabled: tenantId !== null && companyId !== null,
  })

  const activeTerminals = terminals.filter((term) => term.is_active && term.type !== 'web')

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-screen bg-gray-50">
        <div className="text-center">
          <Loader2 className="h-12 w-12 animate-spin text-blue-600 mx-auto mb-4" />
          <p className="text-gray-600">{t('pos:terminal.loadingTerminals')}</p>
        </div>
      </div>
    )
  }

  if (activeTerminals.length === 0) {
    return (
      <div className="flex items-center justify-center h-screen bg-gray-50">
        <div className="text-center max-w-md">
          <Monitor className="h-16 w-16 text-gray-300 mx-auto mb-4" />
          <h2 className="text-xl font-bold text-gray-900 mb-2">
            {t('pos:terminal.noTerminals')}
          </h2>
          <p className="text-gray-500">
            {t('pos:terminal.noTerminalsDescription')}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className="flex items-center justify-center min-h-screen bg-gray-50 p-6">
      <div className="max-w-3xl w-full">
        <div className="text-center mb-8">
          <h1 className="text-2xl font-bold text-gray-900">
            {t('pos:terminal.selectTitle')}
          </h1>
          <p className="text-gray-500 mt-1">
            {t('pos:terminal.selectDescription')}
          </p>
        </div>

        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {activeTerminals.map((terminal) => {
            const isLastUsed = terminal.code === lastUsedCode
            return (
              <button
                key={terminal.id}
                type="button"
                onClick={() => { onSelect(terminal.code); }}
                className={cn(
                  'relative flex flex-col items-center gap-3 rounded-xl border-2 bg-white p-6 transition-all hover:shadow-lg hover:border-blue-400',
                  isLastUsed
                    ? 'border-blue-500 ring-2 ring-blue-200'
                    : 'border-gray-200'
                )}
              >
                {isLastUsed && (
                  <span className="absolute top-2 end-2 text-xs font-medium text-blue-600 bg-blue-50 px-2 py-0.5 rounded-full">
                    {t('pos:terminal.lastUsed')}
                  </span>
                )}
                <Monitor className={cn('h-10 w-10', isLastUsed ? 'text-blue-600' : 'text-gray-400')} />
                <div className="text-center">
                  <div className="font-bold text-gray-900 text-lg">{terminal.name}</div>
                  <div className="text-sm text-gray-500 font-mono">{terminal.code}</div>
                  {terminal.location_name && (
                    <div className="flex items-center justify-center gap-1 mt-1 text-xs text-gray-400">
                      <MapPin className="h-3 w-3" />
                      {terminal.location_name}
                    </div>
                  )}
                </div>
              </button>
            )
          })}
        </div>
      </div>
    </div>
  )
}
