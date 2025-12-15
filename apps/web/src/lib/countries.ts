/**
 * List of supported countries for the application.
 * Ordered by relevance to the business (North Africa, Europe, Gulf).
 */
export interface Country {
  code: string
  name: string
  nameEn: string
  nameFr: string
}

export const countries: Country[] = [
  // North Africa (Primary Markets)
  { code: 'TN', name: 'Tunisia', nameEn: 'Tunisia', nameFr: 'Tunisie' },
  { code: 'DZ', name: 'Algeria', nameEn: 'Algeria', nameFr: 'Algérie' },
  { code: 'MA', name: 'Morocco', nameEn: 'Morocco', nameFr: 'Maroc' },
  { code: 'LY', name: 'Libya', nameEn: 'Libya', nameFr: 'Libye' },
  { code: 'EG', name: 'Egypt', nameEn: 'Egypt', nameFr: 'Égypte' },

  // Europe
  { code: 'FR', name: 'France', nameEn: 'France', nameFr: 'France' },
  { code: 'IT', name: 'Italy', nameEn: 'Italy', nameFr: 'Italie' },
  { code: 'DE', name: 'Germany', nameEn: 'Germany', nameFr: 'Allemagne' },
  { code: 'ES', name: 'Spain', nameEn: 'Spain', nameFr: 'Espagne' },
  { code: 'GB', name: 'United Kingdom', nameEn: 'United Kingdom', nameFr: 'Royaume-Uni' },
  { code: 'BE', name: 'Belgium', nameEn: 'Belgium', nameFr: 'Belgique' },
  { code: 'NL', name: 'Netherlands', nameEn: 'Netherlands', nameFr: 'Pays-Bas' },
  { code: 'CH', name: 'Switzerland', nameEn: 'Switzerland', nameFr: 'Suisse' },
  { code: 'PT', name: 'Portugal', nameEn: 'Portugal', nameFr: 'Portugal' },
  { code: 'AT', name: 'Austria', nameEn: 'Austria', nameFr: 'Autriche' },
  { code: 'PL', name: 'Poland', nameEn: 'Poland', nameFr: 'Pologne' },

  // Gulf States
  { code: 'SA', name: 'Saudi Arabia', nameEn: 'Saudi Arabia', nameFr: 'Arabie Saoudite' },
  { code: 'AE', name: 'United Arab Emirates', nameEn: 'United Arab Emirates', nameFr: 'Émirats Arabes Unis' },
  { code: 'QA', name: 'Qatar', nameEn: 'Qatar', nameFr: 'Qatar' },
  { code: 'KW', name: 'Kuwait', nameEn: 'Kuwait', nameFr: 'Koweït' },
  { code: 'BH', name: 'Bahrain', nameEn: 'Bahrain', nameFr: 'Bahreïn' },
  { code: 'OM', name: 'Oman', nameEn: 'Oman', nameFr: 'Oman' },

  // Other Regions
  { code: 'TR', name: 'Turkey', nameEn: 'Turkey', nameFr: 'Turquie' },
  { code: 'US', name: 'United States', nameEn: 'United States', nameFr: 'États-Unis' },
  { code: 'CA', name: 'Canada', nameEn: 'Canada', nameFr: 'Canada' },
]

/**
 * Get country by code
 */
export function getCountryByCode(code: string): Country | undefined {
  return countries.find((c) => c.code === code)
}

/**
 * Get country name by code in the specified language
 */
export function getCountryName(code: string, locale: string = 'en'): string {
  const country = getCountryByCode(code)
  if (!country) return code
  return locale === 'fr' ? country.nameFr : country.nameEn
}
