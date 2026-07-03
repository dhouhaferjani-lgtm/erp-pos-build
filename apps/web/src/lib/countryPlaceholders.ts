/**
 * Country-aware form placeholders.
 *
 * Placeholders are EXAMPLES, not defaults — a wrong-country example (a Paris
 * postal code shown to a Tunisian retailer) is worse than none. Known launch
 * countries get realistic local examples; every other country gets neutral
 * (empty) placeholders, optionally with the country's phone prefix.
 */
export interface CountryPlaceholders {
  postalCode: string
  city: string
  phone: string
  taxId: string
  registrationNumber: string
  street: string
}

const COUNTRY_PLACEHOLDERS: Record<string, CountryPlaceholders> = {
  TN: {
    postalCode: '1000',
    city: 'Tunis',
    phone: '+216 71 123 456',
    taxId: '1234567AM000',
    registrationNumber: 'B011234562022',
    street: '10 Avenue Habib Bourguiba',
  },
  FR: {
    postalCode: '75001',
    city: 'Paris',
    phone: '+33 1 23 45 67 89',
    taxId: 'FR12345678901',
    registrationNumber: '123 456 789 00012',
    street: '10 Rue de Rivoli',
  },
  MA: {
    postalCode: '20000',
    city: 'Casablanca',
    phone: '+212 522 12 34 56',
    taxId: 'ICE 001234567000089',
    registrationNumber: 'RC 123456',
    street: '10 Boulevard Mohammed V',
  },
  DZ: {
    postalCode: '16000',
    city: 'Alger',
    phone: '+213 21 12 34 56',
    taxId: '000016001234567',
    registrationNumber: 'RC 16/00-1234567 B 26',
    street: '10 Rue Didouche Mourad',
  },
}

function neutralPlaceholders(phonePrefix?: string | null): CountryPlaceholders {
  return {
    postalCode: '',
    city: '',
    phone: phonePrefix ? `+${phonePrefix} …` : '',
    taxId: '',
    registrationNumber: '',
    street: '',
  }
}

/**
 * Placeholders for the given ISO country code. Unknown / missing codes get
 * neutral placeholders (phone built from `phonePrefix` when provided).
 */
export function getCountryPlaceholders(
  countryCode: string | null | undefined,
  phonePrefix?: string | null,
): CountryPlaceholders {
  const code = (countryCode ?? '').trim().toUpperCase()
  return COUNTRY_PLACEHOLDERS[code] ?? neutralPlaceholders(phonePrefix)
}
