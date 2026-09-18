import { describe, it, expect } from 'vitest';
import {
  companyField,
  formatLegalIdentifierLines,
  locationIsFiscallyComplete,
  resolveSellerIdentity,
  resolveSellerIdentityWithSource,
  type LocationFiscalFields,
} from '@/lib/fiscal/sellerIdentity';

/**
 * Atomic seller identity resolver (spec 2026-06-11 §4.6).
 *
 * FISCAL-SENSITIVE: the resolved values land in SIGNED canonical payloads
 * (SALE_RECEIPT / ACCOUNT_PAYMENT / ACCOUNT_CHARGE seller blocks). The
 * contract pinned here is: the location identity is used ONLY when fiscally
 * complete (tax_id AND street AND city AND postal code AND country), and is
 * then used WHOLESALE — never a branch tax number with a company address.
 */

const camelCompany = {
  id: 'company-1',
  name: 'Test Co',
  legalName: 'Test SA',
  taxId: 'COMPANY-FR-TAX',
  countryCode: 'FR',
  addressStreet: '1 Rue Compagnie',
  addressCity: 'Paris',
  addressPostalCode: '75001',
};

const snakeCompany = {
  id: 'company-1',
  name: 'Test Co',
  legal_name: 'Test SA',
  tax_id: 'COMPANY-FR-TAX',
  country_code: 'FR',
  address_street: '1 Rue Compagnie',
  address_city: 'Paris',
  address_postal_code: '75001',
};

const completeLocation: LocationFiscalFields = {
  tax_id: 'BRANCH-FR-TAX',
  address_street: '9 Rue Succursale',
  address_city: 'Lyon',
  address_postal_code: '69001',
  address_country: 'fr',
};

const companyIdentity = {
  name: 'Test SA',
  taxNumber: 'COMPANY-FR-TAX',
  countryCode: 'FR',
  street: '1 Rue Compagnie',
  city: 'Paris',
  postalCode: '75001',
};

describe('locationIsFiscallyComplete', () => {
  it('is true when tax_id and all four address fields are present', () => {
    expect(locationIsFiscallyComplete(completeLocation)).toBe(true);
  });

  it('is false for null/undefined locations', () => {
    expect(locationIsFiscallyComplete(null)).toBe(false);
    expect(locationIsFiscallyComplete(undefined)).toBe(false);
  });

  it.each([
    'tax_id',
    'address_street',
    'address_city',
    'address_postal_code',
    'address_country',
  ] as const)('is false when %s is missing', (field) => {
    expect(locationIsFiscallyComplete({ ...completeLocation, [field]: null })).toBe(false);
    expect(locationIsFiscallyComplete({ ...completeLocation, [field]: undefined })).toBe(false);
  });

  it.each([
    'tax_id',
    'address_street',
    'address_city',
    'address_postal_code',
    'address_country',
  ] as const)('treats empty / whitespace-only %s as missing', (field) => {
    expect(locationIsFiscallyComplete({ ...completeLocation, [field]: '' })).toBe(false);
    expect(locationIsFiscallyComplete({ ...completeLocation, [field]: '   ' })).toBe(false);
  });
});

describe('resolveSellerIdentity', () => {
  it('uses the location identity wholesale when fiscally complete (name stays the company legal name; country uppercased)', () => {
    expect(resolveSellerIdentity(camelCompany, completeLocation)).toEqual({
      name: 'Test SA',
      taxNumber: 'BRANCH-FR-TAX',
      countryCode: 'FR',
      street: '9 Rue Succursale',
      city: 'Lyon',
      postalCode: '69001',
    });
  });

  it.each([
    'tax_id',
    'address_street',
    'address_city',
    'address_postal_code',
    'address_country',
  ] as const)(
    'falls back WHOLESALE to the company when %s is missing (no mixing: tax number AND street are both company values)',
    (field) => {
      const identity = resolveSellerIdentity(camelCompany, {
        ...completeLocation,
        [field]: null,
      });
      expect(identity).toEqual(companyIdentity);
      // Explicit no-mixing pins: never a branch tax number with a company
      // address (or vice versa).
      expect(identity.taxNumber).toBe('COMPANY-FR-TAX');
      expect(identity.street).toBe('1 Rue Compagnie');
    },
  );

  it.each([
    'tax_id',
    'address_street',
    'address_city',
    'address_postal_code',
    'address_country',
  ] as const)('treats an empty-string %s as missing (wholesale company fallback)', (field) => {
    expect(resolveSellerIdentity(camelCompany, { ...completeLocation, [field]: '  ' }))
      .toEqual(companyIdentity);
  });

  it('returns the company identity for a null or undefined location', () => {
    expect(resolveSellerIdentity(camelCompany, null)).toEqual(companyIdentity);
    expect(resolveSellerIdentity(camelCompany, undefined)).toEqual(companyIdentity);
  });

  it('reads snake_case company fields (companyField duality preserved)', () => {
    expect(resolveSellerIdentity(snakeCompany, null)).toEqual(companyIdentity);
  });

  it('falls back to the company display name when no legal name is set', () => {
    const { legalName: _legalName, ...withoutLegalName } = camelCompany;
    expect(resolveSellerIdentity(withoutLegalName, null).name).toBe('Test Co');
    expect(resolveSellerIdentity(withoutLegalName, completeLocation).name).toBe('Test Co');
  });

  it('yields all-null company fields for a non-object company', () => {
    expect(resolveSellerIdentity(undefined, null)).toEqual({
      name: null,
      taxNumber: null,
      countryCode: null,
      street: null,
      city: null,
      postalCode: null,
    });
  });
});

describe('resolveSellerIdentityWithSource (FU-3)', () => {
  it("reports source 'location' and the location identity when fiscally complete", () => {
    const { identity, source } = resolveSellerIdentityWithSource(camelCompany, completeLocation);
    expect(source).toBe('location');
    expect(identity).toEqual(resolveSellerIdentity(camelCompany, completeLocation));
  });

  it("reports source 'company' and the company identity when the location is incomplete", () => {
    const { identity, source } = resolveSellerIdentityWithSource(camelCompany, {
      ...completeLocation,
      tax_id: null,
    });
    expect(source).toBe('company');
    expect(identity).toEqual(companyIdentity);
  });

  it("reports source 'company' for a null or undefined location", () => {
    expect(resolveSellerIdentityWithSource(camelCompany, null).source).toBe('company');
    expect(resolveSellerIdentityWithSource(camelCompany, undefined).source).toBe('company');
  });

  it('its identity matches resolveSellerIdentity across the completeness boundary (the decision is shared, not re-derived)', () => {
    const locations: (LocationFiscalFields | null)[] = [
      completeLocation,
      { ...completeLocation, address_city: '' },
      null,
    ];
    for (const loc of locations) {
      expect(resolveSellerIdentityWithSource(camelCompany, loc).identity).toEqual(
        resolveSellerIdentity(camelCompany, loc),
      );
    }
  });
});

describe('resolveSellerIdentity fiscal no-leak guard (FU-3)', () => {
  it('returns EXACTLY the signed SellerIdentity keys — the source discriminant must NEVER leak into the seller input', () => {
    // The 3 paymentStore sites assign resolveSellerIdentity(...) DIRECTLY as the
    // signed `seller` block. A stray `source` key would change the signed shape.
    const identity = resolveSellerIdentity(camelCompany, completeLocation);
    expect(Object.keys(identity).sort()).toEqual(
      ['city', 'countryCode', 'name', 'postalCode', 'street', 'taxNumber'].sort(),
    );
    expect('source' in identity).toBe(false);
  });
});

describe('companyField', () => {
  it('prefers the camelCase key, falls back to snake_case', () => {
    expect(companyField({ taxId: 'A', tax_id: 'B' }, 'taxId', 'tax_id')).toBe('A');
    expect(companyField({ tax_id: 'B' }, 'taxId', 'tax_id')).toBe('B');
  });

  it('returns null for missing, empty, whitespace-only or non-string values', () => {
    expect(companyField({}, 'taxId', 'tax_id')).toBeNull();
    expect(companyField({ taxId: '' }, 'taxId', 'tax_id')).toBeNull();
    expect(companyField({ taxId: '   ' }, 'taxId', 'tax_id')).toBeNull();
    expect(companyField({ taxId: 42 }, 'taxId', 'tax_id')).toBeNull();
    expect(companyField(null, 'taxId', 'tax_id')).toBeNull();
    expect(companyField('not-an-object', 'taxId', 'tax_id')).toBeNull();
  });
});

describe('formatLegalIdentifierLines', () => {
  it('formats string and numeric identifier entries as display lines', () => {
    expect(
      formatLegalIdentifierLines({ siret: '55210055400014', rcs_paris: 'B 552 100 554', nested: { x: 1 } }),
    ).toEqual(['SIRET: 55210055400014', 'RCS PARIS: B 552 100 554']);
  });

  it('returns null when there is nothing printable', () => {
    expect(formatLegalIdentifierLines(null)).toBeNull();
    expect(formatLegalIdentifierLines(undefined)).toBeNull();
    expect(formatLegalIdentifierLines({})).toBeNull();
    expect(formatLegalIdentifierLines({ siret: '   ' })).toBeNull();
  });

  // r2 device recette 2026-09-18 — the options added for the printed ticket.
  it('drops an identifier that repeats the ticket tax number (any case/spacing)', () => {
    expect(
      formatLegalIdentifierLines(
        { matricule_fiscal: ' 1234567/a/m/000 ', establishment_code: '002' },
        { taxNumber: '1234567/A/M/000' },
      ),
    ).toEqual(['ESTABLISHMENT CODE: 002']);
  });

  it('returns null when every identifier repeats the ticket tax number', () => {
    expect(
      formatLegalIdentifierLines({ matricule_fiscal: 'X-1' }, { taxNumber: 'x-1' }),
    ).toBeNull();
  });

  it('keeps every identifier when no tax number is supplied (unchanged default)', () => {
    expect(formatLegalIdentifierLines({ matricule_fiscal: 'X-1' })).toEqual([
      'MATRICULE FISCAL: X-1',
    ]);
  });

  it('uses the injected label per identifier type, colon included', () => {
    expect(
      formatLegalIdentifierLines(
        { establishment_code: '002' },
        { labelFor: (key) => (key === 'establishment_code' ? 'Code établissement :' : undefined) },
      ),
    ).toEqual(['Code établissement : 002']);
  });

  it('falls back to the legacy KEY: shape when the label is missing or blank', () => {
    expect(formatLegalIdentifierLines({ rcs_paris: 'B 552' }, { labelFor: () => undefined })).toEqual(
      ['RCS PARIS: B 552'],
    );
    expect(formatLegalIdentifierLines({ rcs_paris: 'B 552' }, { labelFor: () => '  ' })).toEqual([
      'RCS PARIS: B 552',
    ]);
  });
});
