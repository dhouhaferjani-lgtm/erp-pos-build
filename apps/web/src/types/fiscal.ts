/**
 * Fiscal Compliance TypeScript Types
 * Document Module - Fiscal Status and Category
 */

// ============================================================================
// Constants (instead of enums for erasableSyntaxOnly compatibility)
// ============================================================================

/**
 * Fiscal category values - determines compliance requirements
 */
export const FiscalCategory = {
  NON_FISCAL: 'NON_FISCAL',
  FISCAL_RECEIPT: 'FISCAL_RECEIPT',
  TAX_INVOICE: 'TAX_INVOICE',
  CREDIT_NOTE: 'CREDIT_NOTE',
} as const;

export type FiscalCategory = (typeof FiscalCategory)[keyof typeof FiscalCategory];

/**
 * Fiscal status values - determines document mutability
 */
export const FiscalStatus = {
  DRAFT: 'DRAFT',
  SEALED: 'SEALED',
  VOIDED: 'VOIDED',
} as const;

export type FiscalStatus = (typeof FiscalStatus)[keyof typeof FiscalStatus];

// ============================================================================
// Labels for i18n
// ============================================================================

/**
 * Fiscal category labels for i18n
 */
export const FiscalCategoryLabels: Record<FiscalCategory, string> = {
  [FiscalCategory.NON_FISCAL]: 'fiscal.category.nonFiscal',
  [FiscalCategory.FISCAL_RECEIPT]: 'fiscal.category.fiscalReceipt',
  [FiscalCategory.TAX_INVOICE]: 'fiscal.category.taxInvoice',
  [FiscalCategory.CREDIT_NOTE]: 'fiscal.category.creditNote',
};

/**
 * Fiscal status labels for i18n
 */
export const FiscalStatusLabels: Record<FiscalStatus, string> = {
  [FiscalStatus.DRAFT]: 'fiscal.status.draft',
  [FiscalStatus.SEALED]: 'fiscal.status.sealed',
  [FiscalStatus.VOIDED]: 'fiscal.status.voided',
};

// ============================================================================
// Helper functions
// ============================================================================

/**
 * Check if a document is fiscally immutable (sealed or voided)
 */
export function isFiscallyImmutable(fiscalStatus: FiscalStatus): boolean {
  return fiscalStatus === FiscalStatus.SEALED || fiscalStatus === FiscalStatus.VOIDED;
}

/**
 * Check if a fiscal category requires compliance
 */
export function requiresFiscalCompliance(fiscalCategory: FiscalCategory): boolean {
  return fiscalCategory !== FiscalCategory.NON_FISCAL;
}

/**
 * Check if a fiscal category requires hash chain
 */
export function requiresHashChain(fiscalCategory: FiscalCategory): boolean {
  return (
    fiscalCategory === FiscalCategory.TAX_INVOICE ||
    fiscalCategory === FiscalCategory.CREDIT_NOTE ||
    fiscalCategory === FiscalCategory.FISCAL_RECEIPT
  );
}
