import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { Toaster } from 'sonner'
import { AuthProvider } from './features/auth'
import { CompanyProvider } from './features/company/CompanyProvider'
import { LocationProvider } from './features/location/LocationProvider'
import { CompanyConfigProvider } from './contexts/CompanyConfigContext'
import { ProductConfigProvider } from './contexts/ProductConfigContext'
import { AppRoutes } from './routes'
import { languages } from './lib/i18n'
import { ErrorBoundary } from './components/ErrorBoundary'
import { CookieConsent } from './components/CookieConsent'

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
      <ProductConfigProvider>
        <AuthProvider>
          <CompanyProvider>
            <CompanyConfigProvider>
              <LocationProvider>
                <AppRoutes />
                <Toaster position="top-right" richColors />
                <CookieConsent />
              </LocationProvider>
            </CompanyConfigProvider>
          </CompanyProvider>
        </AuthProvider>
      </ProductConfigProvider>
    </ErrorBoundary>
  )
}

export default App
