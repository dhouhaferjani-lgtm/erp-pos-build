import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Clock3, LogOut, ShieldAlert } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/atoms/Button'
import { semanticColorTokens as tokens } from '@/lib/designTokens'
import { queryClient } from '@/lib/queryClient'
import { useAuthStore } from '@/stores/authStore'
import { exitImpersonationSession } from '../api/tenantSupportAccessApi'

export function ImpersonationBanner() {
  const { t } = useTranslation('support-access')
  const context = useAuthStore((state) => state.user?.impersonation)
  const logout = useAuthStore((state) => state.logout)
  const navigate = useNavigate()
  const [remaining, setRemaining] = useState(context?.remaining_seconds ?? 0)
  const [exiting, setExiting] = useState(false)

  useEffect(() => {
    setRemaining(context?.remaining_seconds ?? 0)
    if (!context) return
    const timer = window.setInterval(() => {
      setRemaining(Math.max(0, Math.floor((Date.parse(context.expires_at) - Date.now()) / 1000)))
    }, 1000)
    return () => { window.clearInterval(timer) }
  }, [context])

  useEffect(() => {
    if (!context || remaining > 0) return
    queryClient.clear()
    logout()
    void navigate('/admin/support-access')
  }, [context, logout, navigate, remaining])

  if (!context) return null
  const endingSoon = remaining <= 300
  const minutes = Math.floor(remaining / 60).toString().padStart(2, '0')
  const seconds = (remaining % 60).toString().padStart(2, '0')

  const exit = async () => {
    setExiting(true)
    try {
      await exitImpersonationSession(context.session_id)
    } catch {
      toast.error(t('errors.exit'))
      setExiting(false)
      return
    }
    queryClient.clear()
    logout()
    void navigate('/admin/support-access')
  }

  return (
    <div role="status" className={`border-b px-4 py-3 ${endingSoon ? tokens.intent.danger.bgStrong : tokens.intent.warning.bgStrong} ${endingSoon ? tokens.intent.danger.borderStrong : tokens.intent.warning.borderFocus}`}>
      <div className={`mx-auto flex max-w-[1600px] flex-wrap items-center gap-x-5 gap-y-2 ${tokens.text.inverse}`}>
        <ShieldAlert className="h-5 w-5 shrink-0" />
        <p className="font-semibold">{t('banner.active', { subject: context.subject_name })}</p>
        <p className={`text-sm ${tokens.text.inverseAlpha90}`}>{context.reason} · {context.ticket_ref}</p>
        <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${tokens.surface.inverseAlpha15}`}>{t(`access.${context.access_level}`)}</span>
        <span className="ms-auto inline-flex items-center gap-2 font-mono text-sm font-semibold"><Clock3 className="h-4 w-4" />{minutes}:{seconds}</span>
        {endingSoon && <span className="text-sm font-bold">{t('banner.endingSoon')}</span>}
        <Button type="button" variant="ghost" size="sm" disabled={exiting} onClick={() => { void exit() }} className={`gap-2 border ${tokens.border.inverseAlpha40} ${tokens.surface.inverseAlpha10} ${tokens.text.inverse} ${tokens.surface.hoverInverseAlpha20}`}><LogOut className="h-4 w-4" />{t('banner.exit')}</Button>
      </div>
    </div>
  )
}
