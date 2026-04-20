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

import frCommon from '../locales/fr/common.json'
import frAuth from '../locales/fr/auth.json'
import frSales from '../locales/fr/sales.json'
import frInventory from '../locales/fr/inventory.json'
import frTreasury from '../locales/fr/treasury.json'
import frValidation from '../locales/fr/validation.json'
import frPricing from '../locales/fr/pricing.json'
import frFinance from '../locales/fr/finance.json'
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
  },
  ar: {
    // Arabic falls back to English - translations to be added later
    common: enCommon,
    auth: enAuth,
    sales: enSales,
    inventory: enInventory,
    treasury: enTreasury,
    validation: enValidation,
    pricing: enPricing,
    finance: enFinance,
    import: enImport,
    settings: enSettings, // Fallback to English
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
  },
}

void i18n
  .use(LanguageDetector)
  .use(initReactI18next)
  .init({
    resources,
    fallbackLng: 'en',
    defaultNS: 'common',
    ns: ['common', 'auth', 'sales', 'inventory', 'treasury', 'validation', 'pricing', 'finance', 'import', 'settings', 'uom', 'products', 'parapharmacy', 'batches', 'pos', 'catalog', 'menu', 'promotions', 'coupons', 'categories', 'crm', 'parts-catalog', 'loyalty', 'compliance', 'withholding', 'marketing', 'countries', 'progression', 'smart-prompts', 'enrichment', 'workshop-technicians'],

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
