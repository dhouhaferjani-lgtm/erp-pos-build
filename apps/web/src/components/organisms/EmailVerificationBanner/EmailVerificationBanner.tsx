import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Mail, X, Loader2, CheckCircle } from 'lucide-react'
import { api } from '../../../lib/api'
import { useAuthStore } from '../../../stores/authStore'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

const BANNER_DISMISSED_KEY = 'email_verification_banner_dismissed'

export function EmailVerificationBanner() {
  const { t } = useTranslation(['auth'])
  const user = useAuthStore((state) => state.user)
  const [isDismissed, setIsDismissed] = useState(() => {
    return sessionStorage.getItem(BANNER_DISMISSED_KEY) === 'true'
  })
  const [showSuccess, setShowSuccess] = useState(false)

  const resendMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post('/auth/resend-verification')
      return response.data
    },
    onSuccess: () => {
      setShowSuccess(true)
      setTimeout(() => {
        setShowSuccess(false)
      }, 5000)
    },
  })

  const handleDismiss = () => {
    sessionStorage.setItem(BANNER_DISMISSED_KEY, 'true')
    setIsDismissed(true)
  }

  const handleResend = () => {
    resendMutation.mutate()
  }

  // Don't show if user is verified or banner is dismissed
  // Check for both null and undefined to handle old persisted state without email_verified_at
  const isVerified = user?.email_verified_at != null
  if (!user || isVerified || isDismissed) {
    return null
  }

  return (
    <div className={`${colorTokens.intent.caution.bgSubtle} border-b ${colorTokens.intent.caution.borderSubtle}`}>
      <div className="max-w-7xl mx-auto py-3 px-4 sm:px-6 lg:px-8">
        <div className="flex items-center justify-between flex-wrap gap-2">
          <div className="flex items-center flex-1 min-w-0">
            <span className={`flex p-2 rounded-lg ${colorTokens.intent.caution.bgSoft}`}>
              <Mail className={`h-5 w-5 ${colorTokens.intent.caution.text}`} aria-hidden="true" />
            </span>
            <p className={`ms-3 font-medium ${colorTokens.intent.caution.textStrong} text-sm truncate`}>
              {showSuccess ? (
                <span className={`flex items-center gap-2 ${colorTokens.intent.success.textStrong}`}>
                  <CheckCircle className="h-4 w-4" />
                  {t('verification.resent')}
                </span>
              ) : (
                <>
                  {t('verification.banner')}{' '}
                  <button
                    type="button"
                    onClick={handleResend}
                    disabled={resendMutation.isPending}
                    className={`inline-flex items-center gap-1 underline ${colorTokens.variants.hoverTextAmber800} disabled:opacity-50 disabled:cursor-not-allowed`}
                  >
                    {resendMutation.isPending ? (
                      <>
                        <Loader2 className="h-3 w-3 animate-spin" />
                        {t('verification.resending')}
                      </>
                    ) : (
                      t('verification.resend')
                    )}
                  </button>
                </>
              )}
            </p>
          </div>
          <button
            type="button"
            onClick={handleDismiss}
            className={`flex-shrink-0 rounded-md p-1.5 ${colorTokens.intent.caution.text} ${colorTokens.variants.hoverBgAmber100} focus:outline-none focus:ring-2 ${colorTokens.variants.focusRingAmber500}`}
            aria-label={t('common:dismiss', { defaultValue: 'Dismiss' })}
          >
            <X className="h-5 w-5" aria-hidden="true" />
          </button>
        </div>
      </div>
    </div>
  )
}
