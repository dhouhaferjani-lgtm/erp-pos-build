import { Bell } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'

import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { Button } from '@/components/atoms/Button'

import { useUnreadNotificationCount } from '../hooks/useNotifications'
import { NotificationPanel } from './NotificationPanel'

export function NotificationBell() {
  const { t } = useTranslation('notifications')
  const [isOpen, setIsOpen] = useState(false)
  const containerRef = useRef<HTMLDivElement>(null)
  const unreadCount = useUnreadNotificationCount().data?.count ?? 0

  useEffect(() => {
    if (!isOpen) return

    const handlePointerDown = (event: MouseEvent) => {
      if (event.target instanceof Node && !containerRef.current?.contains(event.target)) {
        setIsOpen(false)
      }
    }
    const handleEscape = (event: KeyboardEvent) => {
      if (event.key === 'Escape') setIsOpen(false)
    }

    document.addEventListener('mousedown', handlePointerDown)
    document.addEventListener('keydown', handleEscape)
    return () => {
      document.removeEventListener('mousedown', handlePointerDown)
      document.removeEventListener('keydown', handleEscape)
    }
  }, [isOpen])

  return (
    <div className="relative" ref={containerRef}>
      <Button
        type="button"
        variant="ghost"
        size="sm"
        className="relative !p-2"
        aria-label={unreadCount > 0
          ? t('ariaLabelWithCount', { count: unreadCount })
          : t('ariaLabel')}
        aria-expanded={isOpen}
        aria-haspopup="dialog"
        onClick={() => { setIsOpen((open) => !open) }}
      >
        <Bell className="h-5 w-5" />
        {unreadCount > 0 && (
          <span
            data-testid="notification-count"
            aria-hidden="true"
            className={`absolute -end-1 -top-1 flex min-h-5 min-w-5 items-center justify-center rounded-full px-1 text-[0.6875rem] font-semibold ${colorTokens.intent.danger.bgStrong} ${colorTokens.text.inverse}`}
          >
            {unreadCount}
          </span>
        )}
      </Button>

      {isOpen && <NotificationPanel onClose={() => { setIsOpen(false) }} />}
    </div>
  )
}
