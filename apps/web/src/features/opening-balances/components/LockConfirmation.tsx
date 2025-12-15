import { useTranslation } from 'react-i18next'
import { Lock, AlertTriangle, ArrowLeft, Loader2 } from 'lucide-react'

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
        <h2 className="text-lg font-semibold text-gray-900">
          {t('openingBalances.wizard.lock.title')}
        </h2>
        <p className="mt-1 text-sm text-gray-600">
          {t('openingBalances.wizard.lock.description')}
        </p>
      </div>

      {/* Warning box */}
      <div className="rounded-lg border border-red-200 bg-red-50 p-6">
        <div className="flex items-start gap-4">
          <div className="rounded-full bg-red-100 p-3">
            <AlertTriangle className="h-6 w-6 text-red-600" />
          </div>
          <div>
            <h3 className="font-medium text-red-800">
              {t('openingBalances.wizard.lock.warningTitle')}
            </h3>
            <ul className="mt-2 space-y-1 text-sm text-red-700">
              <li>• {t('openingBalances.wizard.lock.warning1')}</li>
              <li>• {t('openingBalances.wizard.lock.warning2')}</li>
              <li>• {t('openingBalances.wizard.lock.warning3')}</li>
            </ul>
          </div>
        </div>
      </div>

      {/* Lock confirmation box */}
      <div className="rounded-lg border border-gray-200 bg-white p-6">
        <div className="flex items-center gap-4">
          <div className="rounded-full bg-blue-100 p-3">
            <Lock className="h-6 w-6 text-blue-600" />
          </div>
          <div>
            <h3 className="font-medium text-gray-900">
              {t('openingBalances.wizard.lock.confirmTitle')}
            </h3>
            <p className="mt-1 text-sm text-gray-600">
              {t('openingBalances.wizard.lock.confirmDescription')}
            </p>
          </div>
        </div>
      </div>

      {/* Actions */}
      <div className="flex items-center justify-between border-t border-gray-200 pt-4">
        <button
          type="button"
          onClick={onBack}
          disabled={isLocking}
          className="inline-flex items-center gap-2 text-sm text-gray-600 hover:text-gray-900 disabled:opacity-50"
        >
          <ArrowLeft className="h-4 w-4" />
          {t('actions.back')}
        </button>
        <button
          type="button"
          onClick={onLock}
          disabled={isLocking}
          className="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-gray-300"
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
