import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Toaster } from 'sonner'
import { AuthProvider } from './features/auth'
import { CompanyProvider } from './features/company/CompanyProvider'
import { LocationProvider } from './features/location/LocationProvider'
import { useImportProgress } from './features/import/hooks/useImportProgress'
import { GlobalImportProgress } from './components/organisms/GlobalImportProgress/GlobalImportProgress'
import { AppRoutes } from './routes'
import { languages } from './lib/i18n'
import { ErrorBoundary } from './components/ErrorBoundary'

/**
 * Import Progress Subscriber
 *
 * Subscribes to real-time import progress WebSocket events.
 * Must be rendered inside AuthProvider and CompanyProvider.
 */
function ImportProgressSubscriber() {
  useImportProgress()
  return <GlobalImportProgress />
}

function App() {
  const { i18n } = useTranslation()

  useEffect(() => {
    const currentLang = languages.find((l) => l.code === i18n.language)
    const dir = currentLang?.dir ?? 'ltr'
    document.documentElement.dir = dir
    document.documentElement.lang = i18n.language
  }, [i18n.language])

  return (
    <ErrorBoundary>
      <AuthProvider>
        <CompanyProvider>
          <LocationProvider>
            <AppRoutes />
            <Toaster position="top-right" richColors />
            <ImportProgressSubscriber />
          </LocationProvider>
        </CompanyProvider>
      </AuthProvider>
    </ErrorBoundary>
  )
}

export default App
