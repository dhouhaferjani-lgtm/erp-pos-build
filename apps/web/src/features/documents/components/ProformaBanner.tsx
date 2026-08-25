/**
 * The PROFORMA marker band — C-F0w / SPEC §2.4 (F-13, F-64, F-95).
 *
 * The web counterpart of `documents/components/posting_marker.blade.php`, in the
 * same words (`documents.proforma.title` / `.detail`, verbatim the backend's copy —
 * `locales/__tests__/proformaCopyParity.test.ts` pins that they cannot drift). One
 * component for the three surfaces that render it, because the credit note already
 * hand-copied a marker once and the copy went stale.
 *
 * It is deliberately the ONLY thing on these pages that says what kind of document
 * this is: on a proforma the status badge and the "sealed" chip come off, because a
 * `posted`-but-never-sealed document showing both would contradict itself in front
 * of the customer.
 */
import { useTranslation } from 'react-i18next'
import { semanticColorTokens } from '@/lib/designTokens'

export function ProformaBanner({ className }: { className?: string }): React.JSX.Element {
  const { t } = useTranslation('sales')

  return (
    <div
      role="note"
      className={`rounded-lg border ${semanticColorTokens.intent.caution.borderSubtle} ${semanticColorTokens.intent.caution.bgSubtle} px-6 py-3 text-sm${className === undefined ? '' : ` ${className}`}`}
    >
      <strong
        className={`block font-semibold uppercase tracking-wide ${semanticColorTokens.intent.caution.textStronger}`}
      >
        {t('documents.proforma.title')}
      </strong>
      <span className={semanticColorTokens.intent.caution.textStrong}>
        {t('documents.proforma.detail')}
      </span>
    </div>
  )
}
