import axios from 'axios'

import { ERROR_TRANSLATION_KEYS } from './errorCodes'

type ImportErrorCode = App.Modules.Import.Domain.Enums.ImportErrorCode
type ImportErrorDetail = App.Modules.Import.Domain.Data.ImportErrorDetailData

/**
 * Narrowed to what `useTranslation('import')` hands back, so callers pass `t`
 * directly. The options argument is always passed (empty when there is nothing
 * to interpolate): `TFunction` does not accept an explicit `undefined` there
 * under `exactOptionalPropertyTypes`.
 */
type Translate = (key: string, options: Record<string, string>) => string

function isKnownErrorCode(code: string): code is ImportErrorCode {
  return code in ERROR_TRANSLATION_KEYS
}

/**
 * Codes whose catalogue copy is written for a ROW ("The row did not pass
 * validation."). A job-level failure with the same code is a whole-file event,
 * so it reads from `errors.job.<code>` instead — same code, right noun.
 */
const JOB_SCOPED_CODES: Partial<Record<ImportErrorCode, true>> = {
  validation_failed: true,
  internal_error: true,
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
 * `error_detail.missing_columns` is the one actionable, non-sensitive part of a header
 * failure, so it is interpolated rather than discarded with the raw message.
 *
 * Returns `null` when the job carries no failure at all, so callers render no alert.
 */
export function importJobErrorMessage(
  t: Translate,
  job: {
    error_code?: ImportErrorCode | null | undefined
    error_message?: string | null | undefined
    error_detail?: ImportErrorDetail | null | undefined
  },
): string | null {
  const code = job.error_code ?? null
  if (code === null && (job.error_message ?? null) === null) {
    return null
  }

  if (code === null || !isKnownErrorCode(code)) {
    return t('errors.unknown', {})
  }

  const missingColumns = job.error_detail?.missing_columns ?? null
  if (code === 'validation_failed' && missingColumns !== null && missingColumns.length > 0) {
    return t('errors.job.missing_columns', { columns: missingColumns.join(', ') })
  }

  return JOB_SCOPED_CODES[code] === true ? t(`errors.job.${code}`, {}) : t(`errors.${code}`, {})
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}

function readCode(body: unknown): string | null {
  if (!isRecord(body)) return null
  const envelope = body['error']
  if (!isRecord(envelope)) return null
  const code = envelope['code']

  return typeof code === 'string' && code !== '' ? code : null
}

function readMissingColumns(body: unknown): string[] | null {
  if (!isRecord(body)) return null
  const errors = body['errors']
  if (!isRecord(errors)) return null
  const columns = errors['missing_columns']
  if (!Array.isArray(columns)) return null
  const named = columns.filter((column): column is string => typeof column === 'string')

  return named.length > 0 ? named : null
}

/**
 * Operator-facing text for a REFUSED upload (`POST /imports`).
 *
 * The mutation used to toast axios's own `error.message` — "Request failed with
 * status code 422", untranslated (rule 11) — which also threw away the one
 * actionable fact that refusal carries: the columns still to map, i.e. exactly
 * what the wizard sent the operator back to the mapping step to fix. The
 * refusal is coded like every other import failure; an absent or unrecognised
 * code degrades to the generic upload sentence, never to raw transport text.
 */
export function importUploadErrorMessage(t: Translate, error: unknown): string {
  const body: unknown = axios.isAxiosError(error) ? error.response?.data : null
  const code = readCode(body)
  if (code === null || !isKnownErrorCode(code)) {
    return t('messages.uploadError', {})
  }

  const missingColumns = readMissingColumns(body)

  return importJobErrorMessage(t, {
    error_code: code,
    ...(missingColumns !== null ? { error_detail: { missing_columns: missingColumns } } : {}),
  }) ?? t('messages.uploadError', {})
}
