import { describe, it, expect } from 'vitest'
import { AxiosError, AxiosHeaders, type AxiosResponse } from 'axios'
import { isApiError, getErrorMessage } from './api'

/**
 * Build an AxiosError carrying an arbitrary response body, the way a real
 * failed request would.
 */
function axiosErrorWith(status: number, data: unknown): AxiosError {
  const config = { headers: new AxiosHeaders() }
  const response = {
    status,
    statusText: '',
    data,
    headers: {},
    config,
  } as AxiosResponse

  return new AxiosError(
    `Request failed with status code ${String(status)}`,
    String(status),
    config,
    {},
    response,
  )
}

describe('API utilities', () => {
  describe('isApiError', () => {
    it('returns false for non-axios errors', () => {
      expect(isApiError(new Error('test'))).toBe(false)
    })

    it('returns false for null/undefined', () => {
      expect(isApiError(null)).toBe(false)
      expect(isApiError(undefined)).toBe(false)
    })
  })

  describe('getErrorMessage', () => {
    it('returns message for standard Error', () => {
      const error = new Error('Test error message')
      expect(getErrorMessage(error)).toBe('Test error message')
    })

    it('returns default message for unknown error types', () => {
      expect(getErrorMessage('string error')).toBe('An unexpected error occurred')
      expect(getErrorMessage(123)).toBe('An unexpected error occurred')
      expect(getErrorMessage(null)).toBe('An unexpected error occurred')
    })

    /**
     * BUG-005 / RCA B3 — a server failure with no typed handler used to render
     * Laravel's bare `{"message":"Server Error"}`. `isApiError` returns false
     * for that shape, so the whole envelope branch was skipped and the user saw
     * the raw axios string ("Request failed with status code 500") instead of
     * anything the server actually said.
     *
     * The fallback chain is `data?.error?.message ?? data?.message ??
     * error.message`.
     */
    it('prefers the typed error envelope message', () => {
      const error = axiosErrorWith(422, {
        error: { code: 'VALIDATION_ERROR', message: 'Le champ nom est requis.' },
      })

      expect(getErrorMessage(error)).toBe('Le champ nom est requis.')
    })

    it('falls back to a bare top-level message when there is no error envelope', () => {
      const error = axiosErrorWith(500, { message: 'Server Error' })

      expect(getErrorMessage(error)).toBe('Server Error')
    })

    it('falls back to the axios message when the body carries neither', () => {
      const error = axiosErrorWith(502, '<html>bad gateway</html>')

      expect(getErrorMessage(error)).toBe('Request failed with status code 502')
    })

    it('does not throw when the response body is null', () => {
      const error = axiosErrorWith(500, null)

      expect(() => getErrorMessage(error)).not.toThrow()
      expect(getErrorMessage(error)).toBe('Request failed with status code 500')
    })

    it('does not throw when the envelope has an error object with no message', () => {
      const error = axiosErrorWith(500, { error: { code: 'INTERNAL_ERROR' } })

      expect(() => getErrorMessage(error)).not.toThrow()
      expect(getErrorMessage(error)).toBe('Request failed with status code 500')
    })
  })
})
