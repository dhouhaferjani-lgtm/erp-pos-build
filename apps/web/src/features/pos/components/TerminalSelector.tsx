import { useTranslation } from 'react-i18next'
import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'
import { Monitor, MapPin, Loader2 } from 'lucide-react'
import { cn } from '@/lib/utils'
import { tokens, colors, textColors, borderColors } from '@/lib/designTokens'
import { PageHeaderTitle } from '@/components/molecules/PageHeader/PageHeader'

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
      <div className={cn('flex items-center justify-center h-screen', colors.neutral[50])}>
        <div className="text-center">
          <Loader2 className={cn('h-12 w-12 animate-spin mx-auto mb-4', textColors.brand)} />
          <p className={textColors.tertiary}>{t('pos:terminal.loadingTerminals')}</p>
        </div>
      </div>
    )
  }

  if (activeTerminals.length === 0) {
    return (
      <div className={cn('flex items-center justify-center h-screen', colors.neutral[50])}>
        <div className="text-center max-w-md">
          <Monitor className={cn('h-16 w-16 mx-auto mb-4', textColors.disabled)} />
          <h2 className={cn('text-xl font-bold mb-2', textColors.primary)}>
            {t('pos:terminal.noTerminals')}
          </h2>
          <p className={textColors.tertiary}>
            {t('pos:terminal.noTerminalsDescription')}
          </p>
        </div>
      </div>
    )
  }

  return (
    <div className={cn('flex items-center justify-center min-h-screen p-6', colors.neutral[50])}>
      <div className="max-w-3xl w-full">
        <div className="text-center mb-8">
          <PageHeaderTitle className={cn('text-2xl font-bold', textColors.primary)}>
            {t('pos:terminal.selectTitle')}
          </PageHeaderTitle>
          <p className={cn('mt-1', textColors.tertiary)}>
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
                  'relative flex flex-col items-center gap-3 rounded-xl border-2 p-6 transition-all hover:shadow-lg',
                  colors.white,
                  tokens.card.hoverPrimary,
                  isLastUsed
                    ? cn(borderColors.primary, 'shadow-md')
                    : borderColors.light
                )}
              >
                {isLastUsed && (
                  <span className={cn('absolute top-2 end-2 px-2 py-0.5 rounded-full text-xs font-medium', textColors.brand, colors.primary[50])}>
                    {t('pos:terminal.lastUsed')}
                  </span>
                )}
                <Monitor className={cn('h-10 w-10', isLastUsed ? textColors.brand : textColors.disabled)} />
                <div className="text-center">
                  <div className={cn('font-bold text-lg', textColors.primary)}>{terminal.name}</div>
                  <div className={cn('text-sm font-mono', textColors.tertiary)}>{terminal.code}</div>
                  {terminal.location_name && (
                    <div className={cn('flex items-center justify-center gap-1 mt-1 text-xs', textColors.disabled)}>
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
