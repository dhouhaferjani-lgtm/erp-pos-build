import { useQuery } from '@tanstack/react-query'
import { apiGet } from '@/lib/api'
import { useCompanyStore } from '@/stores/companyStore'

export interface CompanyPOSSettings {
  auto_print_receipts: boolean
  receipt_logo: string | null
  receipt_footer: string | null
}

/**
 * Hook to fetch company POS settings
 *
 * Fetches POS-specific settings including auto-print configuration.
 * Settings are cached for 10 minutes as they rarely change.
 *
 * @returns Company POS settings and loading state
 */
export function useCompanySettings() {
  const currentCompanyId = useCompanyStore((state) => state.currentCompanyId)

  const { data: settings, isLoading, error } = useQuery({
    queryKey: ['company', currentCompanyId, 'pos-settings'],
    queryFn: () => {
      if (!currentCompanyId) {
        throw new Error('Company ID is required')
      }
      return apiGet<CompanyPOSSettings>(`/companies/${currentCompanyId}/pos-settings`)
    },
    enabled: !!currentCompanyId, // Only fetch when company is selected
    staleTime: 10 * 60 * 1000, // Cache for 10 minutes
    retry: 1,
  })

  return {
    settings,
    isLoading,
    error,
    autoPrintReceipts: settings?.auto_print_receipts ?? false,
    receiptLogo: settings?.receipt_logo ?? null,
    receiptFooter: settings?.receipt_footer ?? null,
  }
}
