import { useTranslation } from 'react-i18next'
import { Plus } from 'lucide-react'
import { tokens } from '@/lib/designTokens'
import type { SupplierCandidate } from '../types'

interface SupplierPickerProps {
  candidates: readonly SupplierCandidate[]
  value: string
  onChange: (value: string) => void
  onCreateSupplier: () => void
}

export function SupplierPicker({ candidates, value, onChange, onCreateSupplier }: SupplierPickerProps) {
  const { t } = useTranslation(['documentIngestions'])

  return (
    <div>
      <label htmlFor="ingestion-supplier" className={tokens.label.base}>
        {t('review.supplier')}
      </label>
      <div className="mt-1 flex gap-2">
        <select
          id="ingestion-supplier"
          aria-label={t('review.supplier')}
          className={tokens.select.base}
          value={value}
          onChange={(event) => { onChange(event.target.value) }}
        >
          <option value="">{t('review.chooseSupplier')}</option>
          {candidates.map((candidate) => (
            <option key={candidate.id} value={candidate.id}>
              {candidate.name}
            </option>
          ))}
        </select>
        <button
          type="button"
          className={`${tokens.button.base} ${tokens.button.secondary} ${tokens.button.sizes.md}`}
          onClick={onCreateSupplier}
          aria-label={t('review.createSupplier')}
          title={t('review.createSupplier')}
        >
          <Plus className="h-4 w-4" aria-hidden="true" />
        </button>
      </div>
    </div>
  )
}
