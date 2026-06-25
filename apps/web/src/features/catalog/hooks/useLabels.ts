import { useQuery } from '@tanstack/react-query'
import { getLabelFormats } from '../api/labelApi'
import { tenantScopedKey } from '../../../lib/tenantScopedKey'
import { useAuthStore } from '../../../stores/authStore'
import { useCompanyStore } from '../../../stores/companyStore'

export const labelKeys = {
  all: ['catalogLabels'] as const,
  formats: () => [...labelKeys.all, 'formats'] as const,
}

function useScope() {
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId ?? null)
  return { tenantId, companyId }
}

/**
 * Fetch the catalog of printable label formats. Tenant-scoped and gated on an
 * authenticated/company context, mirroring the variant hooks.
 */
export function useLabelFormats() {
  const { tenantId, companyId } = useScope()
  return useQuery({
    queryKey: tenantScopedKey([...labelKeys.formats()]),
    queryFn: () => getLabelFormats(),
    enabled: tenantId !== null && companyId !== null,
  })
}
