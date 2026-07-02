import { describe, it, expect, vi, beforeEach } from 'vitest'
import { render } from '@testing-library/react'
import type { WebSocketConnectionState } from '../../hooks/useWebSocketConnection'
import { ConnectionStatusIndicator } from './ConnectionStatusIndicator'

vi.mock('react-i18next', () => ({
  useTranslation: () => ({ t: (key: string) => key }),
}))

const mockState: WebSocketConnectionState = {
  echo: null,
  isConnected: false,
  isConnecting: true,
  error: null,
  hasGivenUp: false,
}

vi.mock('../../hooks/useWebSocketConnection', () => ({
  useWebSocketConnection: () => mockState,
}))

function setState(next: Partial<WebSocketConnectionState>) {
  Object.assign(mockState, next)
}

describe('ConnectionStatusIndicator', () => {
  beforeEach(() => {
    setState({ echo: null, isConnected: false, isConnecting: true, error: null, hasGivenUp: false })
  })

  it('renders nothing when connected', () => {
    setState({ isConnected: true, isConnecting: false })
    const { container } = render(<ConnectionStatusIndicator />)
    expect(container.firstChild).toBeNull()
  })

  it('shows a pulsing dot while connecting', () => {
    setState({ isConnecting: true })
    const { container } = render(<ConnectionStatusIndicator />)
    expect(container.querySelector('.animate-pulse')).not.toBeNull()
  })

  it('renders nothing after the connection has given up (WSS unreachable locally)', () => {
    setState({ isConnecting: false, hasGivenUp: true })
    const { container } = render(<ConnectionStatusIndicator />)
    expect(container.firstChild).toBeNull()
  })
})
