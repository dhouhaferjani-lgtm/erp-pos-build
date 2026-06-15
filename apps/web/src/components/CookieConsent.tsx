import { useState, useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { cn } from '../lib/utils'
import { colors, textColors, borderColors } from '../lib/designTokens'
import { Button } from './atoms'

const COOKIE_CONSENT_KEY = 'autoerp-cookie-consent'

export function CookieConsent() {
  const { t } = useTranslation('common')
  const [visible, setVisible] = useState(false)

  useEffect(() => {
    const consent = localStorage.getItem(COOKIE_CONSENT_KEY)
    if (!consent) {
      setVisible(true)
    }
  }, [])

  function handleAccept() {
    localStorage.setItem(COOKIE_CONSENT_KEY, 'accepted')
    setVisible(false)
  }

  if (!visible) {
    return null
  }

  return (
    <div className={cn('fixed inset-x-0 bottom-0 z-50 border-t p-4 shadow-lg', borderColors.light, colors.white)}>
      <div className="mx-auto flex max-w-4xl flex-col items-center gap-3 sm:flex-row sm:justify-between">
        <p className={cn('text-sm', textColors.tertiary)}>
          {t('legal.cookieConsent.message')}
        </p>
        <div className="flex shrink-0 items-center gap-3">
          <Link
            to="/privacy"
            className={cn('text-sm underline', textColors.disabled, textColors.hoverPrimary)}
          >
            {t('legal.cookieConsent.learnMore')}
          </Link>
          <Button type="button" onClick={handleAccept} size="sm">
            {t('legal.cookieConsent.accept')}
          </Button>
        </div>
      </div>
    </div>
  )
}
