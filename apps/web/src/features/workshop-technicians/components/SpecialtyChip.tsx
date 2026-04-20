import { useTranslation } from 'react-i18next'
import type { SpecialtyCode } from '../api/types'

interface SpecialtyChipProps {
  code: SpecialtyCode
}

/**
 * Specialty chip uses the neutral (slate) palette so it reads as metadata,
 * not a status signal. Slate sits outside the enforced palette in
 * eslint.config.js.
 */
export function SpecialtyChip({ code }: SpecialtyChipProps) {
  const { t } = useTranslation('workshop-technicians')
  return (
    <span className="inline-flex items-center rounded-md border border-slate-200 bg-white px-2 py-0.5 text-xs font-medium text-slate-700">
      {t(`specialties.${code}`)}
    </span>
  )
}
