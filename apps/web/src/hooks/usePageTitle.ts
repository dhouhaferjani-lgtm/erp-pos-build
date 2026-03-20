import { useEffect } from 'react'
import { useTranslation } from 'react-i18next'
import { useProductConfig } from '@/contexts/ProductConfigContext'

/**
 * Sets the document title for the current page.
 * Format: "Page Name | ProductName"
 */
export function usePageTitle(titleKey: string, ns?: string) {
  const { t } = useTranslation(ns ? [ns, 'common'] : ['common'])
  const { productName } = useProductConfig()

  useEffect(() => {
    const pageTitle = t(titleKey)
    document.title = `${pageTitle} | ${productName}`
    return () => {
      document.title = productName
    }
  }, [t, titleKey, productName])
}
