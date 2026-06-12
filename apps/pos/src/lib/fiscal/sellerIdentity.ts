/**
 * Atomic seller fiscal identity resolver (spec 2026-06-11 §4.6).
 *
 * FISCAL-SENSITIVE: the resolved values land in the SIGNED canonical payloads
 * (SALE_RECEIPT / ACCOUNT_PAYMENT / ACCOUNT_CHARGE seller blocks). The signed
 * seller SHAPE is unchanged (`name, taxNumber, countryCode, street, city,
 * postalCode`) — only the SOURCE of the values is decided here.
 *
 * Contract: the terminal's location (selling establishment) identity is used
 * ONLY when it is fiscally complete — `tax_id` AND all four address fields
 * present. It is then used WHOLESALE (tax number, street, city, postal code,
 * country all from the location; the name stays the company's registered
 * legal name — an establishment shares the registered name). Otherwise every
 * field sources from the company, exactly the pre-§4.6 company behavior.
 * NEVER mix — a branch tax number with a company address is legally
 * incoherent even when it passes `FiscalPayloadConstraintValidator`.
 */

export interface SellerIdentity {
  name: string | null;
  taxNumber: string | null;
  countryCode: string | null;
  street: string | null;
  city: string | null;
  postalCode: string | null;
}

/**
 * The location fields the resolver consults — matches the terminal payload's
 * `location` block (TerminalResource).
 */
export interface LocationFiscalFields {
  tax_id?: string | null;
  address_street?: string | null;
  address_city?: string | null;
  address_postal_code?: string | null;
  address_country?: string | null;
}

/**
 * Read a company field tolerating the camelCase/snake_case duality of the
 * auth payload (`Company` is camelCase from the API transformer, but cached /
 * legacy payloads carry snake_case). Empty and whitespace-only strings count
 * as absent.
 */
export function companyField(company: unknown, camel: string, snake: string): string | null {
  if (typeof company !== 'object' || company === null) return null;
  const record = company as Record<string, unknown>;
  const value = record[camel] ?? record[snake];
  return typeof value === 'string' && value.trim() !== '' ? value : null;
}

/** Raw string read (no trim-to-null) — preserves the historical `?? company?.name` fallback byte-for-byte. */
function rawCompanyString(company: unknown, key: string): string | null {
  if (typeof company !== 'object' || company === null) return null;
  const value = (company as Record<string, unknown>)[key];
  return typeof value === 'string' ? value : null;
}

function isPresent(value: string | null | undefined): value is string {
  return typeof value === 'string' && value.trim() !== '';
}

/**
 * A location can author its own fiscal identity only when `tax_id` AND the
 * full address (street, city, postal code, country) are all present
 * (trimmed-non-empty).
 */
export function locationIsFiscallyComplete(
  loc: LocationFiscalFields | null | undefined,
): boolean {
  if (!loc) return false;
  return (
    isPresent(loc.tax_id)
    && isPresent(loc.address_street)
    && isPresent(loc.address_city)
    && isPresent(loc.address_postal_code)
    && isPresent(loc.address_country)
  );
}

/** Which record authored the resolved seller identity. */
export type SellerIdentitySource = 'location' | 'company';

export interface ResolvedSellerIdentity {
  identity: SellerIdentity;
  /** The selling establishment (`location`) when fiscally complete, else `company`. */
  source: SellerIdentitySource;
}

/**
 * Resolve the seller identity AND report which record authored it.
 *
 * This is the single decision point for the location-vs-company choice
 * (spec §4.6). DISPLAY callers (printed receipt / Z header) consume `source`
 * instead of separately re-calling `locationIsFiscallyComplete` — so the
 * "show the location's vat_number / legal identifiers" display decision can
 * never drift from the identity actually resolved here.
 *
 * FISCAL-SENSITIVE callers must use {@link resolveSellerIdentity} instead,
 * which returns ONLY the bare `SellerIdentity` — the `source` discriminant
 * must never reach the signed seller block (it would change the signed shape).
 */
export function resolveSellerIdentityWithSource(
  company: unknown,
  location: LocationFiscalFields | null | undefined,
): ResolvedSellerIdentity {
  // The establishment shares the company's registered name — the name always
  // sources from the company (legal name preferred, display name fallback).
  const name = companyField(company, 'legalName', 'legal_name')
    ?? rawCompanyString(company, 'name');

  if (location && locationIsFiscallyComplete(location)) {
    return {
      source: 'location',
      identity: {
        name,
        // Values are passed through as stored (no trimming) — completeness was
        // checked trimmed, but the signed bytes must match the server record.
        taxNumber: location.tax_id ?? null,
        // Country codes are ISO-3166 alpha-2 uppercase in canonical payloads.
        countryCode: (location.address_country ?? '').trim().toUpperCase() || null,
        street: location.address_street ?? null,
        city: location.address_city ?? null,
        postalCode: location.address_postal_code ?? null,
      },
    };
  }

  return {
    source: 'company',
    identity: {
      name,
      taxNumber: companyField(company, 'taxId', 'tax_id'),
      countryCode: companyField(company, 'countryCode', 'country_code'),
      street: companyField(company, 'addressStreet', 'address_street'),
      city: companyField(company, 'addressCity', 'address_city'),
      postalCode: companyField(company, 'addressPostalCode', 'address_postal_code'),
    },
  };
}

/**
 * Resolve the seller identity for fiscal payload authoring and display
 * headers. See the module docblock for the atomicity contract.
 *
 * Returns ONLY the bare `SellerIdentity` (no `source` discriminant) so the
 * three paymentStore sites can assign it DIRECTLY as the signed seller block
 * without leaking an extra key. Delegates to
 * {@link resolveSellerIdentityWithSource} so the decision lives in one place.
 */
export function resolveSellerIdentity(
  company: unknown,
  location: LocationFiscalFields | null | undefined,
): SellerIdentity {
  return resolveSellerIdentityWithSource(company, location).identity;
}

/**
 * Display-only helper: flatten a location's `legal_identifiers` JSON object
 * ({ siret: '552…', rcs_paris: '…' }) into printable header lines
 * ("SIRET: 552…"). Keys are legal identifier codes (SIRET, RCS, …), not UI
 * copy — they print uppercased verbatim. Never part of the signed payload
 * (display may exceed the signed shape per spec §4.6).
 */
export function formatLegalIdentifierLines(
  identifiers: Record<string, unknown> | null | undefined,
): string[] | null {
  if (!identifiers) return null;
  const lines = Object.entries(identifiers)
    .filter((entry): entry is [string, string | number] => {
      const value = entry[1];
      return (typeof value === 'string' && value.trim() !== '') || typeof value === 'number';
    })
    .map(([key, value]) => `${key.replace(/_/g, ' ').toUpperCase()}: ${String(value)}`);
  return lines.length > 0 ? lines : null;
}
