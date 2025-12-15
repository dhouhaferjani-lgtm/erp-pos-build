/**
 * Error Handling Utilities
 * Provides helpers for extracting and formatting API errors
 */

import type { TFunction } from 'i18next'

/**
 * API Error structure from backend
 */
interface ApiError {
  code?: string
  message?: string
  details?: Record<string, unknown>
}

/**
 * Error response structure from axios
 */
interface ErrorResponse {
  response?: {
    data?: {
      message?: string
      error?: ApiError
    }
  }
  message?: string
}

/**
 * Fiscal-related error codes from backend
 */
export const FiscalErrorCodes = {
  DOCUMENT_SEALED: 'DOCUMENT_SEALED',
  FISCAL_DOCUMENT_NOT_DELETABLE: 'FISCAL_DOCUMENT_NOT_DELETABLE',
} as const

export type FiscalErrorCode = (typeof FiscalErrorCodes)[keyof typeof FiscalErrorCodes]

/**
 * Extract error message from API error response
 */
export function extractErrorMessage(error: ErrorResponse, fallback: string = 'An error occurred'): string {
  return (
    error.response?.data?.error?.message ??
    error.response?.data?.message ??
    error.message ??
    fallback
  )
}

/**
 * Extract error code from API error response
 */
export function extractErrorCode(error: ErrorResponse): string | undefined {
  return error.response?.data?.error?.code
}

/**
 * Check if error is a fiscal error
 */
export function isFiscalError(error: ErrorResponse): boolean {
  const code = extractErrorCode(error)
  return code !== undefined && Object.values(FiscalErrorCodes).includes(code as FiscalErrorCode)
}

/**
 * Get localized error message for fiscal errors
 */
export function getFiscalErrorMessage(error: ErrorResponse, t: TFunction): string {
  const code = extractErrorCode(error)

  switch (code) {
    case FiscalErrorCodes.DOCUMENT_SEALED:
      return t('fiscal.sealed')
    case FiscalErrorCodes.FISCAL_DOCUMENT_NOT_DELETABLE:
      return t('fiscal.cannotDelete')
    default:
      return extractErrorMessage(error)
  }
}
