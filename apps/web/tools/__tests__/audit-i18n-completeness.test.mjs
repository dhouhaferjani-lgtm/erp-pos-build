// @ts-check
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, it, expect } from 'vitest';

import {
  addedKeysAgainstProtected,
  auditRoot,
  entryKey,
  flattenKeys,
  parseI18nWiring,
  partitionByBaseline,
  pluralCategoriesFor,
} from '../audit-i18n-completeness.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const FIXTURE_ROOT = path.join(
  __dirname,
  '..',
  '__fixtures__',
  'i18n-completeness',
  'prod-shaped',
);

/**
 * 2(c) i18n completeness gate — the audit must classify coverage on AUTHORED
 * LOCALE PROVENANCE (the per-locale source translation files), never on the
 * merged i18next `resources` object, which lies in two production-shaped ways
 * (whole-namespace English aliasing, `{...en, ...partialAr}` spreads).
 */
describe('audit-i18n-completeness — wiring parse', () => {
  it('reads the namespace list from the `ns` array and the locales from `resources`', () => {
    const wiring = parseI18nWiring(FIXTURE_ROOT);
    expect(wiring.namespaces).toEqual(['alpha', 'beta']);
    expect(wiring.locales).toEqual(['en', 'fr', 'ar']);
  });

  it('classifies a whole-namespace English alias as en-aliased, not as coverage', () => {
    const wiring = parseI18nWiring(FIXTURE_ROOT);
    expect(wiring.assignments.ar.alpha.kind).toBe('en-aliased');
    expect(wiring.assignments.fr.alpha.kind).toBe('own');
  });

  it('classifies an `{...en, ...partialAr}` spread as english-spread', () => {
    const wiring = parseI18nWiring(FIXTURE_ROOT);
    expect(wiring.assignments.ar.beta.kind).toBe('english-spread');
  });
});

describe('audit-i18n-completeness — key flattening', () => {
  it('flattens nested objects to dotted leaf paths', () => {
    expect(flattenKeys({ a: 'x', b: { c: 'y', d: { e: 'z' } } })).toEqual([
      'a',
      'b.c',
      'b.d.e',
    ]);
  });
});

describe('audit-i18n-completeness — CLDR plural categories', () => {
  it('derives categories from Intl.PluralRules at runtime, per locale', () => {
    // Derived, never hardcoded in the scanner — asserted here against the same API.
    expect(pluralCategoriesFor('en')).toEqual(
      new Intl.PluralRules('en').resolvedOptions().pluralCategories,
    );
    expect(pluralCategoriesFor('fr')).toContain('many');
    expect(pluralCategoriesFor('en')).not.toContain('many');
  });
});

describe('audit-i18n-completeness — production-shaped alias/spread audit', () => {
  const findings = auditRoot(FIXTURE_ROOT).findings;
  const keys = findings.map(entryKey);

  it('counts the English-aliased namespace as fully UNTRANSLATED for ar', () => {
    expect(keys).toContain('ar|alpha|missing|title');
    expect(keys).toContain('ar|alpha|missing|subtitle');
    expect(keys).toContain('ar|alpha|missing|nested.a');
  });

  it('counts spread-supplied English keys as UNTRANSLATED for ar', () => {
    // `beta` merges `{...enBeta, ...arBeta}`; ar authors only `one`.
    expect(keys).toContain('ar|beta|missing|two');
    expect(keys).toContain('ar|beta|missing|items_one');
    expect(keys).toContain('ar|beta|missing|items_other');
    expect(keys).not.toContain('ar|beta|missing|one');
  });

  it('a scanner reading the MERGED object would see vacuous parity — the audit does not', () => {
    // The merged runtime shape the audit must NOT trust.
    const merged = { one: 'One', two: 'Two', items_one: 'x', items_other: 'y' };
    expect(Object.keys(merged)).toContain('two');
    // …yet `two` is a real Arabic gap:
    expect(keys).toContain('ar|beta|missing|two');
  });

  it('flags the per-locale CLDR plural-category gap a flat key diff is blind to', () => {
    // fr authors items_one + items_other but French also requires `many`.
    expect(keys).toContain('fr|beta|plural|items_many');
    // en requires only one/other and has both.
    expect(keys.filter((k) => k.startsWith('en|'))).toEqual([]);
  });

  it('does not raise plural findings for a family the locale authors no form of', () => {
    // ar authors no `items_*` at all — those are `missing`, not `plural`.
    expect(keys).not.toContain('ar|beta|plural|items_few');
  });
});

describe('audit-i18n-completeness — shrink-only baseline ratchet', () => {
  const baseline = ['ar|alpha|missing|title', 'ar|beta|missing|two'];

  it('passes findings that are already baselined and fails NEW ones', () => {
    const { fresh, covered } = partitionByBaseline(
      [
        { locale: 'ar', ns: 'alpha', type: 'missing', key: 'title' },
        { locale: 'ar', ns: 'beta', type: 'missing', key: 'brandNew' },
      ],
      baseline,
    );
    expect(covered).toEqual(['ar|alpha|missing|title']);
    expect(fresh).toEqual(['ar|beta|missing|brandNew']);
  });

  it('reports baseline entries that no longer occur as stale (burn-down is allowed)', () => {
    const { stale } = partitionByBaseline(
      [{ locale: 'ar', ns: 'alpha', type: 'missing', key: 'title' }],
      baseline,
    );
    expect(stale).toEqual(['ar|beta|missing|two']);
  });
});

describe('audit-i18n-completeness — anti-growth against the PINNED protected blob', () => {
  const protectedEntries = ['ar|alpha|missing|title', 'ar|beta|missing|two'];

  it('allows removal-only movement', () => {
    expect(addedKeysAgainstProtected(['ar|alpha|missing|title'], protectedEntries)).toEqual([]);
  });

  it('MATCHED GROWTH still fails: planting a violation AND adding its baseline key', () => {
    // The tamper: a new gap is introduced and the working baseline is edited to
    // cover it. Against a branch-name comparison this is green; against the
    // PINNED protected blob it is a growth and must fail.
    const tampered = [...protectedEntries, 'ar|beta|missing|items_one'];
    expect(addedKeysAgainstProtected(tampered, protectedEntries)).toEqual([
      'ar|beta|missing|items_one',
    ]);
  });
});
