import type { Locator, Page } from '@playwright/test'

/**
 * Campaign selector authority.
 *
 * Near-miss warning: two partner tiles exist on the current product. The
 * balance-bearing route is import-tile-parties / Business partners; the plain
 * Partners tile silently drops opening balances. G-9 will retire that legacy
 * surface, so tile locators are testid-first with a label fallback.
 */
export const routes = {
  dashboard: '/dashboard',
  importDashboard: '/settings/import',
  importParties: '/settings/import/parties',
  importProducts: '/settings/import/products',
  login: '/login',
  register: '/register',
} as const

export const apiRoutes = {
  accounts: '/accounts',
  // GET /companies/{id}/accounts/purposes → data.accounts[] carries system_purpose (AccountData on GET /accounts does not).
  accountPurposes: (companyId: string) => `/companies/${companyId}/accounts/purposes`,
  batches: '/batches',
  batchStock: (productId: string) => `/products/${productId}/batch-stock`,
  companies: '/user/companies',
  companyCreate: '/companies',
  documents: '/documents',
  document: (documentId: string) => `/documents/${documentId}`,
  fiscalEvents: '/pos/sync/fiscal-events',
  journalEntries: '/journal-entries',
  locations: '/locations',
  openingBatches: (companyId: string) => `/companies/${companyId}/opening-batches`,
  openingBatch: (companyId: string, batchId: string) =>
    `/companies/${companyId}/opening-batches/${batchId}`,
  openingBatchImport: (companyId: string, batchId: string) =>
    `/companies/${companyId}/opening-batches/${batchId}/import`,
  openingBatchLock: (companyId: string, batchId: string) =>
    `/companies/${companyId}/opening-batches/${batchId}/lock`,
  openingBatchPost: (companyId: string, batchId: string) =>
    `/companies/${companyId}/opening-batches/${batchId}/post`,
  openingBatchValidate: (companyId: string, batchId: string) =>
    `/companies/${companyId}/opening-batches/${batchId}/validate`,
  partnerBalance: (companyId: string, partnerId: string) =>
    `/companies/${companyId}/partners/${partnerId}/balance`,
  partners: '/partners',
  paymentMethods: '/payment-methods',
  paymentRepositories: '/payment-repositories',
  paymentRepository: (repositoryId: string) => `/payment-repositories/${repositoryId}`,
  payments: '/payments',
  products: '/products',
  stockLevels: '/stock-levels',
  stockLevel: (productId: string, locationId: string) => `/stock-levels/${productId}/${locationId}`,
  terminalCreate: '/pos/terminals',
  terminalZChainState: (terminalId: string) => `/pos/terminals/${terminalId}/z-chain-state`,
  shifts: '/pos/shifts',
  shift: (shiftId: string) => `/pos/shifts/${shiftId}`,
  currentShift: (terminalCode: string) => `/pos/shifts/current/${encodeURIComponent(terminalCode)}`,
  zReports: '/pos/reports/z',
  zReport: (zNumber: number) => `/pos/reports/z/${String(zNumber)}`,
  receipts: '/pos/receipts',
  trialBalance: '/reports/trial-balance',
  units: '/uom/units',
} as const

const labels = {
  addCompany: /add company|ajouter.*soci|ajouter.*entreprise/i,
  companyName: /company name|nom de (la )?(société|entreprise)/i,
  country: /country|pays/i,
  createAccount: /create account|créer.*compte/i,
  email: /email/i,
  importExecute: /start import|lancer l'import/i,
  importNext: /next|suivant/i,
  importProceed: /proceed to import|procéder à l'import/i,
  importValidate: /validate data|valider les données/i,
  importViewResults: /view results|voir les résultats/i,
  loginSubmit: /sign in|log in|se connecter/i,
  name: /full name|nom complet|^name(\s*\*)?$|^nom(\s*\*)?$/i,
  // Required fields render their label as "Password *" (FormField required marker) — tolerate the suffix.
  password: /^(password|mot de passe)(\s*\*)?$/i,
  passwordConfirmation: /confirm password|confirmer.*mot de passe/i,
  profile: /profile|profil/i,
  registerNext: /^(continue|next|continuer|suivant)$/i,
  terms: /agree|accept|terms|conditions/i,
  vertical: /parapharmacy|parapharmacie/i,
} as const

export const testIds = {
  importCompleteWorkbook: 'import-complete-download-workbook',
  importDuplicateSummary: 'import-preview-duplicate-summary',
  importPolicySkip: 'import-preview-policy-skip',
  importTileParties: 'import-tile-parties',
  importTileProducts: 'import-tile-products',
  importWizardExecute: 'import-wizard-execute',
  importWizardNext: 'import-wizard-next',
  importWizardValidate: 'import-wizard-validate',
} as const

function testIdOrRole(page: Page, testId: string, roleLocator: Locator): Locator {
  return page.getByTestId(testId).or(roleLocator)
}

export function campaignSelectors(page: Page) {
  return {
    login: {
      email: page.getByLabel(labels.email),
      password: page.getByLabel(labels.password),
      submit: page.getByRole('button', { name: labels.loginSubmit }),
    },
    register: {
      name: page.getByLabel(labels.name),
      email: page.getByLabel(labels.email),
      password: page.getByLabel(labels.password),
      country: page.getByLabel(labels.country),
      vertical: page.getByRole('option', { name: labels.vertical }),
      companyName: page.getByLabel(labels.companyName),
      next: page.getByRole('button', { name: labels.registerNext }),
      terms: page.getByRole('checkbox', { name: labels.terms }).or(page.getByRole('checkbox')).first(),
      createAccount: page.getByRole('button', { name: labels.createAccount }),
    },
    shell: {
      profile: page.getByRole('button', { name: labels.profile }),
      companySwitcher: page.getByRole('button', { name: /select company|sélectionner.*soci/i }),
      addCompany: page.getByRole('button', { name: labels.addCompany }),
    },
    import: {
      partiesTile: testIdOrRole(
        page,
        testIds.importTileParties,
        page.getByRole('link', { name: /partenaires commerciaux|business partners|parties/i }),
      ),
      productsTile: testIdOrRole(
        page,
        testIds.importTileProducts,
        page.getByRole('link', { name: /products|produits/i }),
      ),
      // A file input has no ARIA role; the attribute selector is the semantic handle (FileUpload.tsx renders one hidden <input type="file">).
      fileInput: page.locator('input[type="file"]'),
      next: testIdOrRole(
        page,
        testIds.importWizardNext,
        page.getByRole('button', { name: labels.importNext }),
      ),
      validate: testIdOrRole(
        page,
        testIds.importWizardValidate,
        page.getByRole('button', { name: labels.importValidate }),
      ),
      proceed: page.getByRole('button', { name: labels.importProceed }),
      execute: testIdOrRole(
        page,
        testIds.importWizardExecute,
        page.getByRole('button', { name: labels.importExecute }),
      ),
      viewResults: page.getByRole('button', { name: labels.importViewResults }),
      completeWorkbook: testIdOrRole(
        page,
        testIds.importCompleteWorkbook,
        page.getByRole('button', { name: /download result workbook|télécharger.*(?:classeur|rapport.*import)/i }),
      ),
      duplicateSummary: page.getByTestId(testIds.importDuplicateSummary),
      duplicateSkip: page.getByTestId(testIds.importPolicySkip),
      step: {
        upload: page.getByTestId('import-wizard-step-upload').or(
          page.getByRole('heading', { name: /upload your file|téléverser.*fichier/i }),
        ),
        mapping: page.getByTestId('import-wizard-step-mapping').or(
        page.getByRole('heading', { name: /map your columns|associer.*colonnes|mappez.*colonnes/i }),
        ),
        options: page.getByTestId('import-wizard-step-options').or(
          page.getByRole('heading', { name: /stock location|emplacement.*stock|which price is authoritative|quel prix.*référence|quel prix.*foi/i }),
        ),
        preview: page.getByTestId('import-wizard-step-preview').or(
          page.getByRole('heading', { name: /review validation results|examiner.*validation|vérifi.*données/i }),
        ),
        execute: page.getByTestId('import-wizard-step-execute').or(
          page.getByRole('heading', { name: /import your data|importer.*données|importez.*données/i }),
        ),
        complete: page.getByTestId('import-wizard-step-complete').or(
          page.getByRole('heading', { name: /import complete|importation terminée/i }),
        ),
      },
      refusal: page.getByRole('alert'),
    },
  }
}
