/**
 * Canary that proves the generated namespace is reachable from apps/web.
 * If this file stops type-checking, the TypeScript pipeline has regressed.
 */

const accountType: App.Modules.Accounting.Domain.Enums.AccountType = 'asset'
const invoiceStatus: App.Modules.Billing.Domain.Enums.InvoiceStatus = 'paid'
const vertical: App.Enums.Vertical = 'pharmacy'

export const __typesCanary = { accountType, invoiceStatus, vertical } as const
