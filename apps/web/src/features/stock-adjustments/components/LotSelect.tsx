import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { Select } from '@/components/atoms/Select/Select'
import { api } from '@/lib/api'
import { textColors } from '@/lib/designTokens'
import { tenantScopedKey } from '@/lib/tenantScopedKey'
import { useAuthStore } from '@/stores/authStore'
import { useCompanyStore } from '@/stores/companyStore'

interface BatchOption {
  batch_uuid: string
  batch_number: string
}

interface Props {
  productId: string
  value: string
  required: boolean
  onChange: (batchUuid: string) => void
}

/**
 * The lot picker, shared by the create page and the quick modal.
 *
 * Fed by the same `/products/{id}/batch-stock` endpoint the batch write-off
 * screen uses, so the two surfaces cannot disagree about which lots exist. Kept
 * in ONE component because the backend refuses a negative line that does not
 * name a lot when one holds stock at that location — a refusal whose own message
 * instructs the operator to name a lot, which is unfollowable on any screen that
 * omits this field.
 */
export function LotSelect({ productId, value, required, onChange }: Props) {
  const { t } = useTranslation('stock-adjustments')
  const tenantId = useAuthStore((s) => s.user?.tenant_id ?? null)
  const companyId = useCompanyStore((s) => s.currentCompanyId)

  const { data } = useQuery({
    // Keyed under its OWN resource literal, not under `stock-adjustments`:
    // otherwise the feature's invalidation predicate refetches every lot list on
    // each create/post/cancel/correct.
    queryKey: tenantScopedKey(['batch-stock', productId]),
    queryFn: async () => {
      const response = await api.get<{ data: BatchOption[] }>(`/products/${productId}/batch-stock`)
      return response.data.data
    },
    enabled: !!tenantId && !!companyId && productId !== '',
  })

  const options = data ?? []

  if (options.length === 0) {
    return <span className={textColors.tertiary}>{t('line.lotEmpty')}</span>
  }

  return (
    <Select
      aria-label={t('line.lot')}
      value={value}
      onChange={(event) => {
        onChange(event.target.value)
      }}
      error={required && value === ''}
    >
      <option value="">—</option>
      {options.map((option) => (
        <option key={option.batch_uuid} value={option.batch_uuid}>
          {option.batch_number}
        </option>
      ))}
    </Select>
  )
}
