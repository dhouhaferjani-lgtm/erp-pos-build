import { Component, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { AlertTriangle, RefreshCw, Home } from 'lucide-react'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

interface Props {
  children: ReactNode
  fallback?: ReactNode
}

interface State {
  hasError: boolean
  error: Error | null
}

function ErrorFallback({
  error,
  onRetry,
  onReload,
  onGoHome,
}: {
  error: Error | null
  onRetry: () => void
  onReload: () => void
  onGoHome: () => void
}) {
  const { t } = useTranslation()

  return (
    <div className={`min-h-screen flex items-center justify-center ${colorTokens.surface.page} p-4`}>
      <div className={`max-w-md w-full ${colorTokens.surface.base} rounded-lg shadow-lg p-8 text-center`}>
        <div className="flex justify-center mb-4">
          <div className={`w-16 h-16 rounded-full ${colorTokens.intent.danger.bgSoft} flex items-center justify-center`}>
            <AlertTriangle className={`w-8 h-8 ${colorTokens.intent.danger.text}`} />
          </div>
        </div>

        <h1 className={`text-xl font-semibold ${colorTokens.text.primary} mb-2`}>
          {t('errors.unexpectedError')}
        </h1>

        <p className={`${colorTokens.text.muted} mb-6`}>
          {t('errors.unexpectedErrorDetails')}
        </p>

        {import.meta.env.DEV && error && (
          <div className={`mb-6 p-4 ${colorTokens.intent.danger.bgSubtle} rounded-md text-start`}>
            <p className={`text-sm font-mono ${colorTokens.intent.danger.textStronger} break-all`}>
              {error.message}
            </p>
          </div>
        )}

        <div className="flex flex-col sm:flex-row gap-3 justify-center">
          <button
            onClick={onRetry}
            className={`inline-flex items-center justify-center px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.surface.base} border ${colorTokens.border.default} rounded-md ${colorTokens.variants.hoverBgGray50} focus:outline-none focus:ring-2 focus:ring-offset-2 ${colorTokens.focus.primaryRing}`}
          >
            <RefreshCw className="w-4 h-4 me-2" />
            {t('actions.tryAgain')}
          </button>

          <button
            onClick={onReload}
            className={`inline-flex items-center justify-center px-4 py-2 text-sm font-medium ${colorTokens.text.inverse} ${colorTokens.intent.primary.bgStrong} rounded-md ${colorTokens.variants.hoverBgBlue700} focus:outline-none focus:ring-2 focus:ring-offset-2 ${colorTokens.focus.primaryRing}`}
          >
            <RefreshCw className="w-4 h-4 me-2" />
            {t('actions.refreshPage')}
          </button>

          <button
            onClick={onGoHome}
            className={`inline-flex items-center justify-center px-4 py-2 text-sm font-medium ${colorTokens.text.secondary} ${colorTokens.surface.base} border ${colorTokens.border.default} rounded-md ${colorTokens.variants.hoverBgGray50} focus:outline-none focus:ring-2 focus:ring-offset-2 ${colorTokens.focus.primaryRing}`}
          >
            <Home className="w-4 h-4 me-2" />
            {t('actions.goHome')}
          </button>
        </div>
      </div>
    </div>
  )
}

/**
 * React Error Boundary that catches render errors in child components.
 * Prevents the entire app from crashing when a component fails.
 */
export class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props)
    this.state = { hasError: false, error: null }
  }

  static getDerivedStateFromError(error: Error): State {
    return { hasError: true, error }
  }

  componentDidCatch(error: Error, errorInfo: React.ErrorInfo): void {
    // Log error to console in development
    console.error('ErrorBoundary caught an error:', error, errorInfo)

    // In production, this would send to error tracking service (Sentry, etc.)
  }

  handleReload = (): void => {
    window.location.reload()
  }

  handleGoHome = (): void => {
    window.location.href = '/'
  }

  handleRetry = (): void => {
    this.setState({ hasError: false, error: null })
  }

  render(): ReactNode {
    if (this.state.hasError) {
      if (this.props.fallback) {
        return this.props.fallback
      }

      return (
        <ErrorFallback
          error={this.state.error}
          onRetry={this.handleRetry}
          onReload={this.handleReload}
          onGoHome={this.handleGoHome}
        />
      )
    }

    return this.props.children
  }
}
