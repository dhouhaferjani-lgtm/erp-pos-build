import { AxiosError } from 'axios'
import { toast } from 'sonner'

type Translator = (key: string) => string

const ERROR_KEY_BY_CODE: Record<string, string> = {
  WORK_ORDER_NO_LINES: 'transitions.errors.work_order_no_lines',
  INVALID_TRANSITION: 'transitions.errors.invalid_transition',
  WORK_ORDER_STALE: 'transitions.errors.work_order_stale',
}

const GENERIC_ERROR_KEY = 'transitions.errors.generic'

/**
 * Narrow an unknown response body to a backend error code.
 *
 * Matches the shape produced by `WorkOrderNoLinesException`,
 * `WorkOrderTransitionController` (422 INVALID_TRANSITION) and
 * `StaleWorkOrderException` (409 WORK_ORDER_STALE).
 */
function extractErrorCode(data: unknown): string | null {
  if (typeof data !== 'object' || data === null) {
    return null
  }
  const error = (data as { error?: unknown }).error
  if (typeof error !== 'object' || error === null) {
    return null
  }
  const code = (error as { code?: unknown }).code
  return typeof code === 'string' ? code : null
}

/**
 * Build a `onError` handler that surfaces work-order mutation failures via
 * a sonner toast using the supplied translator.
 *
 * Only known backend error codes are mapped to friendly messages; everything
 * else (including non-Axios errors) falls back to the generic key and is
 * logged once via `console.error` for diagnostics.
 */
export function createMutationErrorHandler(
  t: Translator,
): (err: unknown) => void {
  return (err: unknown): void => {
    if (err instanceof AxiosError) {
      const code = extractErrorCode(err.response?.data)
      if (code !== null && code in ERROR_KEY_BY_CODE) {
        toast.error(t(ERROR_KEY_BY_CODE[code]))
        return
      }
    }
    console.error(err)
    toast.error(t(GENERIC_ERROR_KEY))
  }
}
