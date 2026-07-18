import { Bell, CheckCheck } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'

import { semanticColorTokens as colorTokens } from '@/lib/designTokens'
import { Button } from '@/components/atoms/Button'
import { formatCurrency } from '@/lib/format'

import type { AppNotification } from '../api/notificationsApi'
import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotificationsList,
} from '../hooks/useNotifications'

interface NotificationPanelProps {
  onClose?: () => void
}

const KNOWN_TYPES = new Set([
  'treasury.reconcile.drift',
  'treasury.reconcile.portfolio_drift',
  'treasury.instrument.maturity_alert',
  'treasury.maturity.outbound_due',
  'expense.recurring.generated',
])

function stringValue(value: unknown): string {
  return typeof value === 'string' ? value : ''
}

function countValue(value: unknown): string {
  return typeof value === 'number' || typeof value === 'string' ? String(value) : '0'
}

function numericCountValue(value: unknown): number {
  const count = typeof value === 'number' ? value : Number(value)
  return Number.isFinite(count) ? count : 0
}

function displayMessage(notification: AppNotification, t: ReturnType<typeof useTranslation>['t']): string {
  const legacyMessage = stringValue(notification.data['message'])
  if (legacyMessage) return legacyMessage

  switch (notification.type) {
    case 'treasury.reconcile.drift':
      return t('messages.treasury.reconcile.drift', {
        repositoryCode: stringValue(notification.data['repository_code']),
      })
    case 'treasury.reconcile.portfolio_drift':
      return t('messages.treasury.reconcile.portfolio_drift', {
        companyName: stringValue(notification.data['company_name']),
      })
    case 'treasury.instrument.maturity_alert':
      return t('messages.treasury.instrument.maturity_alert', {
        depositedCount: countValue(notification.data['deposited_overdue_count']),
        receivedCount: countValue(notification.data['received_due_count']),
      })
    case 'treasury.maturity.outbound_due':
      return t('messages.treasury.maturity.outbound_due', {
        count: numericCountValue(notification.data['outbound_due_count']),
      })
    case 'expense.recurring.generated': {
      const currency = stringValue(notification.data['currency']) || 'EUR'
      return t('messages.expense.recurring.generated', {
        name: stringValue(notification.data['template_name']),
        amount: formatCurrency(stringValue(notification.data['amount']), { currency }),
      })
    }
    default:
      return t('messages.generic')
  }
}

export function NotificationPanel({ onClose }: NotificationPanelProps) {
  const { t } = useTranslation('notifications')
  const navigate = useNavigate()
  const notificationsQuery = useNotificationsList(true)
  const markRead = useMarkNotificationRead()
  const markAllRead = useMarkAllNotificationsRead()
  const notifications = [...(notificationsQuery.data?.data ?? [])]
    .sort((left, right) => right.created_at.localeCompare(left.created_at))
    .slice(0, 15)

  const openNotification = async (notification: AppNotification) => {
    if (notification.read_at === null) {
      try {
        await markRead.mutateAsync(notification.id)
      } catch {
        return
      }
    }

    const deepLink = notification.data['deep_link']
    if (typeof deepLink === 'string') {
      void navigate(deepLink)
      onClose?.()
    }
  }

  return (
    <dialog
      open
      aria-label={t('title')}
      className={`absolute end-0 z-50 m-0 mt-2 w-[min(24rem,calc(100vw-2rem))] overflow-hidden rounded-xl border ${colorTokens.border.subtle} ${colorTokens.surface.base} p-0 shadow-xl`}
    >
      <div className={`flex items-center justify-between border-b ${colorTokens.border.subtle} px-4 py-3`}>
        <h2 className={`font-semibold ${colorTokens.text.primary}`}>{t('title')}</h2>
        <Button
          type="button"
          variant="ghost"
          size="xs"
          className={`gap-1.5 ${colorTokens.intent.primary.textStrong} ${colorTokens.intent.primary.bgHover}`}
          disabled={markAllRead.isPending || notifications.every((notification) => notification.read_at !== null)}
          onClick={() => { void markAllRead.mutateAsync() }}
        >
          <CheckCheck className="h-4 w-4" />
          {t('markAllRead')}
        </Button>
      </div>

      {notificationsQuery.isLoading && (
        <p role="status" className={`px-4 py-8 text-center text-sm ${colorTokens.text.subtle}`}>
          {t('loading')}
        </p>
      )}

      {notificationsQuery.isError && (
        <p role="alert" className={`px-4 py-8 text-center text-sm ${colorTokens.intent.danger.textStrong}`}>
          {t('error')}
        </p>
      )}

      {!notificationsQuery.isLoading && !notificationsQuery.isError && notifications.length === 0 && (
        <div className="flex flex-col items-center px-4 py-10 text-center">
          <Bell className={`mb-3 h-8 w-8 ${colorTokens.text.disabled}`} />
          <p className={`text-sm font-medium ${colorTokens.text.secondary}`}>{t('empty')}</p>
        </div>
      )}

      {notifications.length > 0 && (
        <ul className={`max-h-96 overflow-y-auto divide-y ${colorTokens.border.dividerSubtle}`}>
          {notifications.map((notification) => {
            const isUnread = notification.read_at === null
            const title = t(`types.${notification.type}`, { defaultValue: t('types.generic') })
            const message = displayMessage(notification, t)

            return (
              <li
                key={notification.id}
                data-read-state={isUnread ? 'unread' : 'read'}
                className={isUnread
                  ? `border-s-2 ${colorTokens.intent.primary.borderFocus} ${colorTokens.intent.primary.bgSubtleAlpha}`
                  : colorTokens.surface.base}
              >
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  className="!block w-full !px-4 !py-3 text-start"
                  aria-label={`${title}: ${message}${isUnread ? `, ${t('unread')}` : ''}`}
                  onClick={() => { void openNotification(notification) }}
                >
                  <span className={`block text-sm font-semibold ${colorTokens.text.primary}`}>{title}</span>
                  {!KNOWN_TYPES.has(notification.type) && (
                    <span className={`mt-0.5 block break-all text-xs ${colorTokens.text.subtle}`}>
                      {notification.type}
                    </span>
                  )}
                  <span className={`mt-1 block text-sm ${colorTokens.text.muted}`}>{message}</span>
                </Button>
              </li>
            )
          })}
        </ul>
      )}
    </dialog>
  )
}
