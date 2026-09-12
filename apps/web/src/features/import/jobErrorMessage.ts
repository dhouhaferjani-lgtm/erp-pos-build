import { ERROR_TRANSLATION_KEYS } from './errorCodes'

type ImportErrorCode = App.Modules.Import.Domain.Enums.ImportErrorCode

/** Narrowed to what `useTranslation('import')` hands back, so callers pass `t` directly. */
type Translate = (key: string) => string

function isKnownErrorCode(code: string): code is ImportErrorCode {
  return code in ERROR_TRANSLATION_KEYS
}

/**
 * Operator-facing text for a terminal import failure.
 *
 * `import_jobs.error_message` is raw server text — `'Job failed: '.$exception->getMessage()`
 * or a bare finalize `getMessage()` — so it carries PHP class names, file paths and full
 * SQLSTATE strings including key values. It is a support/diagnosis channel and must never
 * reach a screen. The durable coded channel (`import_jobs.error_code`) is what operators
 * read; an absent or unrecognised code degrades to one generic sentence rather than to
 * the raw text.
 *
 * Returns `null` when the job carries no failure at all, so callers render no alert.
 */
export function importJobErrorMessage(
  t: Translate,
  job: { error_code?: ImportErrorCode | null | undefined; error_message?: string | null | undefined },
): string | null {
  const code = job.error_code ?? null
  if (code === null && (job.error_message ?? null) === null) {
    return null
  }

  return code !== null && isKnownErrorCode(code) ? t(`errors.${code}`) : t('errors.unknown')
}
