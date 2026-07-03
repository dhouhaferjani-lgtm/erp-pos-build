import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import LanguageDetector from 'i18next-browser-languagedetector'

// Import translation files
import enCommon from '../locales/en/common.json'
import enAuth from '../locales/en/auth.json'
import enSales from '../locales/en/sales.json'
import enInventory from '../locales/en/inventory.json'
import enTreasury from '../locales/en/treasury.json'
import enValidation from '../locales/en/validation.json'
import enPricing from '../locales/en/pricing.json'
import enFinance from '../locales/en/finance.json'
import enExpenses from '../locales/en/expenses.json'
import enImport from '../locales/en/import.json'
import enSettings from '../locales/en/settings.json'
import enUom from '../locales/en/uom.json'
import enProducts from '../locales/en/products.json'
import enParapharmacy from '../locales/en/parapharmacy.json'
import enBatches from '../locales/en/batches.json'
import enPos from '../locales/en/pos.json'
import enCatalog from '../locales/en/catalog.json'
import enMenu from '../locales/en/menu.json'
import enPromotions from '../locales/en/promotions.json'
import enCoupons from '../locales/en/coupons.json'
import enCategories from '../locales/en/categories.json'
import enCrm from '../locales/en/crm.json'
import enPartsCatalog from '../locales/en/parts-catalog.json'
import enLoyalty from '../locales/en/loyalty.json'
import enCompliance from '../locales/en/compliance.json'
import enWithholding from '../locales/en/withholding.json'
import enMarketing from '../locales/en/marketing.json'
import enCountries from '../locales/en/countries.json'
import enProgression from '../locales/en/progression.json'
import enSmartPrompts from '../locales/en/smart-prompts.json'
import enEnrichment from '../locales/en/enrichment.json'
import enWorkshopTechnicians from '../locales/en/workshop-technicians.json'
import enWorkshopBundles from '../locales/en/workshop-bundles.json'
import enWorkshopWorkOrders from '../locales/en/workshop-work-orders.json'
import enScheduling from '../locales/en/scheduling.json'
import enVehicles from '../locales/en/vehicles.json'
import enVehicleOwnership from '../locales/en/vehicle-ownership.json'
import enPickers from '../locales/en/pickers.json'
import enDocuments from '../locales/en/documents.json'
import enVouchers from '../locales/en/vouchers.json'
import enRefundPolicies from '../locales/en/refund-policies.json'
import enDeposits from '../locales/en/deposits.json'
import enCustomerHistoryAudit from '../locales/en/customer-history-audit.json'
import enChannels from '../locales/en/channels.json'
import enReports from '../locales/en/reports.json'
import enStockTransfers from '../locales/en/stock-transfers.json'
import enAdmin from '../locales/en/admin.json'
import enPurchases from '../locales/en/purchases.json'

import frCommon from '../locales/fr/common.json'
import frAuth from '../locales/fr/auth.json'
import frSales from '../locales/fr/sales.json'
import frInventory from '../locales/fr/inventory.json'
import frTreasury from '../locales/fr/treasury.json'
import frValidation from '../locales/fr/validation.json'
import frPricing from '../locales/fr/pricing.json'
import frFinance from '../locales/fr/finance.json'
import frExpenses from '../locales/fr/expenses.json'
import frImport from '../locales/fr/import.json'
import frSettings from '../locales/fr/settings.json'
import frUom from '../locales/fr/uom.json'
import frProducts from '../locales/fr/products.json'
import frParapharmacy from '../locales/fr/parapharmacy.json'
import frBatches from '../locales/fr/batches.json'
import frPos from '../locales/fr/pos.json'
import frCatalog from '../locales/fr/catalog.json'
import frMenu from '../locales/fr/menu.json'
import frPromotions from '../locales/fr/promotions.json'
import frCoupons from '../locales/fr/coupons.json'
import frCategories from '../locales/fr/categories.json'
import frCrm from '../locales/fr/crm.json'
import frPartsCatalog from '../locales/fr/parts-catalog.json'
import frLoyalty from '../locales/fr/loyalty.json'
import frCompliance from '../locales/fr/compliance.json'
import frWithholding from '../locales/fr/withholding.json'
import frMarketing from '../locales/fr/marketing.json'
import frCountries from '../locales/fr/countries.json'
import frProgression from '../locales/fr/progression.json'
import frSmartPrompts from '../locales/fr/smart-prompts.json'
import frEnrichment from '../locales/fr/enrichment.json'
import frWorkshopTechnicians from '../locales/fr/workshop-technicians.json'
import frWorkshopBundles from '../locales/fr/workshop-bundles.json'
import frWorkshopWorkOrders from '../locales/fr/workshop-work-orders.json'
import frScheduling from '../locales/fr/scheduling.json'
import frVehicles from '../locales/fr/vehicles.json'
import frVehicleOwnership from '../locales/fr/vehicle-ownership.json'
import frPickers from '../locales/fr/pickers.json'
import frDocuments from '../locales/fr/documents.json'
import frVouchers from '../locales/fr/vouchers.json'
import frRefundPolicies from '../locales/fr/refund-policies.json'
import frDeposits from '../locales/fr/deposits.json'
import frCustomerHistoryAudit from '../locales/fr/customer-history-audit.json'
import frChannels from '../locales/fr/channels.json'
import frReports from '../locales/fr/reports.json'
import frStockTransfers from '../locales/fr/stock-transfers.json'
import frAdmin from '../locales/fr/admin.json'
import frPurchases from '../locales/fr/purchases.json'

// Arabic: fully translated AutoSpecs + shared foundations (🟠-4 Tunisia Go-Live).
// Other namespaces still fall back to the EN bundle below.
import arCommon from '../locales/ar/common.json'
import arInventory from '../locales/ar/inventory.json'
import arValidation from '../locales/ar/validation.json'
import arWorkshopBundles from '../locales/ar/workshop-bundles.json'
import arWorkshopTechnicians from '../locales/ar/workshop-technicians.json'
import arWorkshopWorkOrders from '../locales/ar/workshop-work-orders.json'
import arVehicles from '../locales/ar/vehicles.json'
import arVehicleOwnership from '../locales/ar/vehicle-ownership.json'
import arScheduling from '../locales/ar/scheduling.json'
import arPickers from '../locales/ar/pickers.json'
import arMenu from '../locales/ar/menu.json'
import arPartsCatalog from '../locales/ar/parts-catalog.json'
import arDocuments from '../locales/ar/documents.json'
import arPos from '../locales/ar/pos.json'
import arVouchers from '../locales/ar/vouchers.json'
import arChannels from '../locales/ar/channels.json'
import arReports from '../locales/ar/reports.json'
import arAdmin from '../locales/ar/admin.json'
import arPurchases from '../locales/ar/purchases.json'
import arProducts from '../locales/ar/products.json'
import arSales from '../locales/ar/sales.json'
import arSettings from '../locales/ar/settings.json'

export const languages = [
  { code: 'en', name: 'English', dir: 'ltr' },
  { code: 'fr', name: 'Français', dir: 'ltr' },
  { code: 'ar', name: 'العربية', dir: 'rtl' },
] as const

export type LanguageCode = (typeof languages)[number]['code']

const resources = {
  en: {
    common: enCommon,
    auth: enAuth,
    sales: enSales,
    inventory: enInventory,
    treasury: enTreasury,
    validation: enValidation,
    pricing: enPricing,
    finance: enFinance,
    expenses: enExpenses,
    import: enImport,
    settings: enSettings,
    uom: enUom,
    products: enProducts,
    parapharmacy: enParapharmacy,
    batches: enBatches,
    pos: enPos,
    catalog: enCatalog,
    menu: enMenu,
    promotions: enPromotions,
    coupons: enCoupons,
    categories: enCategories,
    crm: enCrm,
    'parts-catalog': enPartsCatalog,
    loyalty: enLoyalty,
    compliance: enCompliance,
    withholding: enWithholding,
    marketing: enMarketing,
    countries: enCountries,
    progression: enProgression,
    'smart-prompts': enSmartPrompts,
    enrichment: enEnrichment,
    'workshop-technicians': enWorkshopTechnicians,
    'workshop-bundles': enWorkshopBundles,
    'workshop-work-orders': enWorkshopWorkOrders,
    scheduling: enScheduling,
    vehicles: enVehicles,
    'vehicle-ownership': enVehicleOwnership,
    pickers: enPickers,
    documents: enDocuments,
    vouchers: enVouchers,
    'refund-policies': enRefundPolicies,
    deposits: enDeposits,
    'customer-history-audit': enCustomerHistoryAudit,
    channels: enChannels,
    reports: enReports,
    'stock-transfers': enStockTransfers,
    admin: enAdmin,
    purchases: enPurchases,
  },
  fr: {
    common: frCommon,
    auth: frAuth,
    sales: frSales,
    inventory: frInventory,
    treasury: frTreasury,
    validation: frValidation,
    pricing: frPricing,
    finance: frFinance,
    expenses: frExpenses,
    import: frImport,
    settings: frSettings,
    uom: frUom,
    products: frProducts,
    parapharmacy: frParapharmacy,
    batches: frBatches,
    pos: frPos,
    catalog: frCatalog,
    menu: frMenu,
    promotions: frPromotions,
    coupons: frCoupons,
    categories: frCategories,
    crm: frCrm,
    'parts-catalog': frPartsCatalog,
    loyalty: frLoyalty,
    compliance: frCompliance,
    withholding: frWithholding,
    marketing: frMarketing,
    countries: frCountries,
    progression: frProgression,
    'smart-prompts': frSmartPrompts,
    enrichment: frEnrichment,
    'workshop-technicians': frWorkshopTechnicians,
    'workshop-bundles': frWorkshopBundles,
    'workshop-work-orders': frWorkshopWorkOrders,
    scheduling: frScheduling,
    vehicles: frVehicles,
    'vehicle-ownership': frVehicleOwnership,
    pickers: frPickers,
    documents: frDocuments,
    vouchers: frVouchers,
    'refund-policies': frRefundPolicies,
    deposits: frDeposits,
    'customer-history-audit': frCustomerHistoryAudit,
    channels: frChannels,
    reports: frReports,
    'stock-transfers': frStockTransfers,
    admin: frAdmin,
    purchases: frPurchases,
  },
  ar: {
    // 🟠-4 Tunisia Go-Live: AutoSpecs namespaces + shared foundations are now
    // fully translated. Non-AutoSpecs namespaces still fall back to EN until
    // IziPOS localization closes those gaps (tracked separately).
    common: arCommon,
    auth: enAuth,
    sales: {
      ...enSales,
      ...arSales,
      documents: {
        ...enSales.documents,
        ...arSales.documents,
        statuses: {
          ...enSales.documents.statuses,
          ...arSales.documents.statuses,
        },
      },
      partners: {
        ...enSales.partners,
        ...arSales.partners,
        countLabels: {
          ...enSales.partners.countLabels,
          ...arSales.partners.countLabels,
        },
        empty: {
          ...enSales.partners.empty,
          ...arSales.partners.empty,
        },
        messages: {
          ...enSales.partners.messages,
          ...arSales.partners.messages,
        },
        types: {
          ...enSales.partners.types,
          ...arSales.partners.types,
        },
        validation: {
          ...enSales.partners.validation,
          ...arSales.partners.validation,
        },
      },
    },
    inventory: { ...enInventory, ...arInventory, products: { ...enInventory.products, ...arInventory.products } },
    treasury: enTreasury,
    validation: arValidation,
    pricing: enPricing,
    finance: enFinance,
    expenses: enExpenses,
    import: enImport,
    settings: {
      ...enSettings,
      ...arSettings,
      sections: { ...enSettings.sections, ...arSettings.sections },
    },
    uom: enUom,
    products: { ...enProducts, ...arProducts },
    parapharmacy: enParapharmacy,
    batches: enBatches,
    pos: {
      ...enPos,
      ...arPos,
      transactions: { ...enPos.transactions, ...arPos.transactions },
    },
    catalog: enCatalog,
    menu: arMenu,
    promotions: enPromotions,
    coupons: enCoupons,
    categories: enCategories,
    crm: enCrm,
    'parts-catalog': arPartsCatalog,
    loyalty: enLoyalty,
    compliance: enCompliance,
    withholding: enWithholding,
    marketing: enMarketing,
    countries: enCountries,
    progression: enProgression,
    'smart-prompts': enSmartPrompts,
    enrichment: enEnrichment,
    'workshop-technicians': arWorkshopTechnicians,
    'workshop-bundles': arWorkshopBundles,
    'workshop-work-orders': arWorkshopWorkOrders,
    scheduling: arScheduling,
    vehicles: arVehicles,
    'vehicle-ownership': arVehicleOwnership,
    pickers: arPickers,
    documents: arDocuments,
    vouchers: arVouchers,
    'refund-policies': enRefundPolicies,
    deposits: enDeposits,
    'customer-history-audit': enCustomerHistoryAudit,
    channels: arChannels,
    reports: arReports,
    'stock-transfers': enStockTransfers,
    admin: arAdmin,
    purchases: arPurchases,
  },
}

void i18n
  .use(LanguageDetector)
  .use(initReactI18next)
  .init({
    resources,
    fallbackLng: 'en',
    defaultNS: 'common',
    ns: ['common', 'auth', 'sales', 'inventory', 'treasury', 'validation', 'pricing', 'finance', 'expenses', 'import', 'settings', 'uom', 'products', 'parapharmacy', 'batches', 'pos', 'catalog', 'menu', 'promotions', 'coupons', 'categories', 'crm', 'parts-catalog', 'loyalty', 'compliance', 'withholding', 'marketing', 'countries', 'progression', 'smart-prompts', 'enrichment', 'workshop-bundles', 'workshop-technicians', 'workshop-work-orders', 'scheduling', 'vehicles', 'vehicle-ownership', 'pickers', 'documents', 'vouchers', 'refund-policies', 'deposits', 'customer-history-audit', 'channels', 'reports', 'stock-transfers', 'admin', 'purchases'],

    detection: {
      order: ['querystring', 'localStorage', 'navigator'],
      lookupQuerystring: 'lang',
      lookupLocalStorage: 'autoerp-language',
      caches: ['localStorage'],
    },

    interpolation: {
      escapeValue: false, // React already escapes values
    },

    react: {
      useSuspense: false,
    },
  })

export default i18n
