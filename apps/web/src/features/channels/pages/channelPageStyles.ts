import { borderColors, textColors, tokens } from '@/lib/designTokens'
import { cn } from '@/lib/utils'

export const page = 'space-y-6'
export const header = 'flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between'
export const title = `text-2xl font-bold ${textColors.primary}`
export const subtitle = `text-sm ${textColors.tertiary}`
export const panel = `rounded-lg border ${borderColors.light} bg-white p-6`
export const tableShell = `overflow-hidden rounded-lg border ${borderColors.light} bg-white`
export const table = `min-w-full divide-y ${borderColors.divideDefault}`
export const th = `px-6 py-3 text-start text-xs font-medium uppercase ${textColors.tertiary}`
export const td = `px-6 py-4 text-sm ${textColors.secondary}`
export const primaryButton = cn(tokens.button.base, tokens.button.primary, tokens.button.sizes.md, 'gap-2')
export const secondaryButton = cn(tokens.button.base, tokens.button.secondary, tokens.button.sizes.md, 'gap-2')
export const input = tokens.input.base
export const select = tokens.select.base
