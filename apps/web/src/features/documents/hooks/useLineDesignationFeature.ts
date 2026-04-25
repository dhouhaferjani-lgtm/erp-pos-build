import { useCompanyConfig } from '@/contexts/CompanyConfigContext'

export function useLineDesignationFeature(): boolean {
  const { config } = useCompanyConfig()
  return config?.line_designation_override_enabled ?? false
}
