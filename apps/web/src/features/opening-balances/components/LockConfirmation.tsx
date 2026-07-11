import { useTranslation } from 'react-i18next'
import { Lock, AlertTriangle, ArrowLeft, Loader2 } from 'lucide-react'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface LockConfirmationProps {
  onLock: () => void
  onBack: () => void
  isLocking: boolean
}

export function LockConfirmation({ onLock, onBack, isLocking }: LockConfirmationProps) {
  const { t } = useTranslation()

  return (
    <div className="space-y-6">
      <div>
        <h2 className={`text-lg font-semibold ${colorTokens.text.primary}`}>
          {t('openingBalances.wizard.lock.title')}
        </h2>
        <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>
          {t('openingBalances.wizard.lock.description')}
        </p>
      </div>

      {/* Warning box */}
      <div className={`rounded-lg border ${colorTokens.intent.danger.borderSubtle} ${colorTokens.intent.danger.bgSubtle} p-6`}>
        <div className="flex items-start gap-4">
          <div className={`rounded-full ${colorTokens.intent.danger.bgSoft} p-3`}>
            <AlertTriangle className={`h-6 w-6 ${colorTokens.intent.danger.text}`} />
          </div>
          <div>
            <h3 className={`font-medium ${colorTokens.intent.danger.textStronger}`}>
              {t('openingBalances.wizard.lock.warningTitle')}
            </h3>
            <ul className={`mt-2 space-y-1 text-sm ${colorTokens.intent.danger.textStrong}`}>
              <li>• {t('openingBalances.wizard.lock.warning1')}</li>
              <li>• {t('openingBalances.wizard.lock.warning2')}</li>
              <li>• {t('openingBalances.wizard.lock.warning3')}</li>
            </ul>
          </div>
        </div>
      </div>

      {/* Lock confirmation box */}
      <div className={`rounded-lg border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-6`}>
        <div className="flex items-center gap-4">
          <div className={`rounded-full ${colorTokens.intent.primary.bgSoft} p-3`}>
            <Lock className={`h-6 w-6 ${colorTokens.intent.primary.text}`} />
          </div>
          <div>
            <h3 className={`font-medium ${colorTokens.text.primary}`}>
              {t('openingBalances.wizard.lock.confirmTitle')}
            </h3>
            <p className={`mt-1 text-sm ${colorTokens.text.muted}`}>
              {t('openingBalances.wizard.lock.confirmDescription')}
            </p>
          </div>
        </div>
      </div>

      {/* Actions */}
      <div className={`flex items-center justify-between border-t ${colorTokens.border.subtle} pt-4`}>
        <button
          type="button"
          onClick={onBack}
          disabled={isLocking}
          className={`inline-flex items-center gap-2 text-sm ${colorTokens.text.muted} ${colorTokens.intent.neutral.textHoverStrongest} disabled:opacity-50`}
        >
          <ArrowLeft className="h-4 w-4" />
          {t('actions.back')}
        </button>
        <button
          type="button"
          onClick={onLock}
          disabled={isLocking}
          className={`inline-flex items-center gap-2 rounded-lg ${colorTokens.intent.primary.bgStrong} px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrongHover} disabled:cursor-not-allowed ${colorTokens.surface.disabledWhenDisabled}`}
        >
          {isLocking ? (
            <>
              <Loader2 className="h-4 w-4 animate-spin" />
              {t('openingBalances.wizard.lock.locking')}
            </>
          ) : (
            <>
              <Lock className="h-4 w-4" />
              {t('openingBalances.wizard.lock.lockButton')}
            </>
          )}
        </button>
      </div>
    </div>
  )
}
