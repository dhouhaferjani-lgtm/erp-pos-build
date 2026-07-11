import { useTranslation } from 'react-i18next'
import { semanticColorTokens as colorTokens } from '@/lib/designTokens'

/**
 * Document status types from backend
 */
export type DocumentStatus = 'draft' | 'confirmed' | 'posted' | 'cancelled'

/**
 * Partner status types
 */
export type PartnerStatus = 'active' | 'inactive'

/**
 * General status types
 */
export type GeneralStatus = 'active' | 'inactive' | 'pending' | 'completed' | 'cancelled'

/**
 * Payment status types
 */
export type PaymentStatus = 'pending' | 'completed' | 'failed' | 'refunded' | 'cancelled'

/**
 * Mapping of status values to translation keys
 */
export const STATUS_TRANSLATION_KEYS: Record<string, string> = {
  // Document statuses
  draft: 'status.draft',
  confirmed: 'status.confirmed',
  posted: 'status.posted',
  cancelled: 'status.cancelled',

  // General statuses
  active: 'status.active',
  inactive: 'status.inactive',
  pending: 'status.pending',
  completed: 'status.completed',

  // Payment statuses
  failed: 'status.failed',
  refunded: 'status.refunded',
}

/**
 * Hook to get translated status label
 * @param status - The status value from the backend
 * @returns Translated status label
 */
export function useStatusLabel(status: string | null | undefined): string {
  const { t } = useTranslation()

  if (!status) return ''

  const key = STATUS_TRANSLATION_KEYS[status.toLowerCase()]
  return key ? t(key) : status
}

/**
 * Non-hook version for utilities and non-component contexts
 * Returns the translation key for a given status
 * @param status - The status value from the backend
 * @returns Translation key
 */
export function getStatusTranslationKey(status: string | null | undefined): string {
  if (!status) return ''
  return STATUS_TRANSLATION_KEYS[status.toLowerCase()] ?? status
}

/**
 * Get CSS classes for status badge styling
 * @param status - The status value
 * @returns Tailwind CSS classes for the badge
 */
export function getStatusBadgeClasses(status: string | null | undefined): string {
  if (!status) return `${colorTokens.surface.muted} ${colorTokens.text.strong}`

  const statusLower = status.toLowerCase()

  const colorMap: Record<string, string> = {
    draft: `${colorTokens.surface.muted} ${colorTokens.text.strong}`,
    pending: `${colorTokens.intent.warning.bgSoft} ${colorTokens.intent.warning.textStronger}`,
    active: `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`,
    confirmed: `${colorTokens.intent.primary.bgSoft} ${colorTokens.intent.primary.textStronger}`,
    completed: `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`,
    posted: `${colorTokens.intent.success.bgSoft} ${colorTokens.intent.success.textStronger}`,
    cancelled: `${colorTokens.intent.danger.bgSoft} ${colorTokens.intent.danger.textStronger}`,
    inactive: `${colorTokens.surface.muted} ${colorTokens.text.strong}`,
    failed: `${colorTokens.intent.danger.bgSoft} ${colorTokens.intent.danger.textStronger}`,
    refunded: `${colorTokens.intent.notice.bgSoft} ${colorTokens.intent.notice.textStronger}`,
  }

  return colorMap[statusLower] ?? `${colorTokens.surface.muted} ${colorTokens.text.strong}`
}
