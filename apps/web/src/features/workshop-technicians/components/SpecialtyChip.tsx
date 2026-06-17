import { useTranslation } from 'react-i18next'
import type { SpecialtyCode } from '../api/types'
import { borderColors, textColors } from '@/lib/designTokens'

interface SpecialtyChipProps {
  code: SpecialtyCode
}

/**
 * Specialty chip uses the neutral (gray) design-token palette so it reads as
 * metadata, not a status signal. No off-theme palettes.
 */
export function SpecialtyChip({ code }: SpecialtyChipProps) {
  const { t } = useTranslation('workshop-technicians')
  return (
    <span
      className={`inline-flex items-center rounded-md border ${borderColors.light} bg-white px-2 py-0.5 text-xs font-medium ${textColors.secondary}`}
    >
      {t(`specialties.${code}`)}
    </span>
  )
}
