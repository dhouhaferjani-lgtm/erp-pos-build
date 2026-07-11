import { useTranslation } from 'react-i18next'
import { useWebSocketConnection } from '../../hooks/useWebSocketConnection'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

/**
 * Subtle connection status dot for the TopBar.
 *
 * - Green (hidden by default): connected
 * - Amber + pulse: connecting
 * - Red (always visible): disconnected
 * - Hidden: after we give up reaching an unreachable realtime endpoint, so
 *   the dot never spins forever when WSS is down (e.g. local dev without Reverb).
 */
export function ConnectionStatusIndicator() {
  const { t } = useTranslation()
  const { isConnected, isConnecting, hasGivenUp } = useWebSocketConnection()

  if (isConnected || hasGivenUp) {
    return null
  }

  const statusText = isConnecting
    ? t('realtime.connecting')
    : t('realtime.disconnected')

  const dotClasses = isConnecting
    ? `h-2 w-2 rounded-full ${colorTokens.variants.bgAmber400} animate-pulse`
    : `h-2 w-2 rounded-full ${colorTokens.intent.danger.bg}`

  return (
    <div className="flex items-center" title={statusText}>
      <span className={dotClasses} />
    </div>
  )
}
