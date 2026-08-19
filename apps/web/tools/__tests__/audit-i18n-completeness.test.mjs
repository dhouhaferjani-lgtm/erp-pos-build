// @ts-check
import { execFileSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { describe, it, expect } from 'vitest';

import {
  addedKeysAgainstProtected,
  auditRoot,
  entryKey,
  flattenKeys,
  KNOWN_UNWIRED_LOCALE_FILES,
  missingScannedSurface,
  parseI18nWiring,
  partitionByBaseline,
  pluralCategoriesFor,
} from '../audit-i18n-completeness.mjs';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SCRIPT = path.join(__dirname, '..', 'audit-i18n-completeness.mjs');
const REPO_ROOT = path.resolve(__dirname, '..', '..', '..', '..');
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

  // THE DISCRIMINATING CASE. `locales/ar/alpha.json` is COMPLETE on disk — every
  // English key is authored — but `i18n.ts` wires `alpha: enAlpha` under `ar`, so
  // the runtime serves English. This is the live `catalog` shape: `ar/catalog.json`
  // carries 261 authored keys while `i18n.ts:397` reads `catalog: enCatalog`.
  // A file-only scanner reports ZERO gaps here (verified against the pre-fix
  // commit 3615b103b), which is both the vacuous-parity failure gate-r1 H-5
  // forbids AND a live ratchet bypass: the Arabic baseline could be "burned
  // down" by dropping unwired JSON files into the tree.
  it('counts an English-ALIASED namespace as untranslated even when the locale file is COMPLETE', () => {
    const arAlphaFile = JSON.parse(
      readFileSync(path.join(FIXTURE_ROOT, 'locales', 'ar', 'alpha.json'), 'utf8'),
    );
    // Precondition: the fixture really does author every English key.
    expect(flattenKeys(arAlphaFile).sort()).toEqual(['nested.a', 'subtitle', 'title']);

    expect(keys).toContain('ar|alpha|aliased|*');
    // …and NOT credited as translated, nor exploded into per-key noise:
    expect(keys.filter((k) => k.startsWith('ar|alpha|missing'))).toEqual([]);
  });

  it('emits ONE aliased entry per namespace, so new English keys do not fail CI there', () => {
    // Per-key entries would make every new English key an instant failure in the
    // 23 namespaces Arabic does not cover at all — an effective
    // full-parity-on-every-new-key policy. The single entry is strictly stronger
    // as an invariant: it clears only when a real bundle is WIRED.
    expect(keys.filter((k) => k.startsWith('ar|alpha|'))).toEqual(['ar|alpha|aliased|*']);
  });

  it('raises no plural findings inside an aliased namespace', () => {
    expect(keys.filter((k) => k.startsWith('ar|alpha|plural'))).toEqual([]);
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

describe('audit-i18n-completeness — surface-coverage invariant', () => {
  const protectedEntries = [
    'ar|alpha|aliased|*',
    'ar|beta|missing|two',
    'fr|beta|plural|items_many',
  ];

  it('passes while every pinned locale|namespace is still scanned', () => {
    expect(missingScannedSurface(protectedEntries, ['en', 'fr', 'ar'], ['alpha', 'beta'])).toEqual(
      [],
    );
  });

  it('catches a LOCALE that dropped out of the parsed wiring', () => {
    // A prettier pass that collapses the `ar:` block onto one line makes the
    // line-oriented parser skip the locale. Every ar baseline entry then falls
    // into `stale`, which is a console note — the loss would read as burn-down.
    expect(missingScannedSurface(protectedEntries, ['en', 'fr'], ['alpha', 'beta'])).toEqual([
      'ar|alpha',
      'ar|beta',
    ]);
  });

  it('catches a NAMESPACE dropped from the `ns` array', () => {
    // Runtime translations keep working (resources is static), so nothing else notices.
    expect(missingScannedSurface(protectedEntries, ['en', 'fr', 'ar'], ['alpha'])).toEqual([
      'ar|beta',
      'fr|beta',
    ]);
  });
});

describe('audit-i18n-completeness — CLI fail-closed paths (the authority itself)', () => {
  /**
   * Runs the REAL script the CI step runs, against the fixture root, with a
   * genuine git blob written into the object store as the protected revision —
   * so `git cat-file blob` resolves exactly as it will in CI once the pin tag
   * has been fetched. Never throws: the exit status is the assertion.
   * @returns {{status: number, out: string}}
   */
  function runCli({ env = {}, baselineEntries, mirrorBlob, protectedEntries = baselineEntries }) {
    const dir = mkdtempSync(path.join(tmpdir(), 'i18n-cli-'));
    const baselinePath = path.join(dir, 'baseline.json');
    writeFileSync(baselinePath, JSON.stringify({ entries: baselineEntries }));

    const blob = execFileSync('git', ['hash-object', '-w', '--stdin'], {
      cwd: REPO_ROOT,
      input: JSON.stringify({ entries: protectedEntries }),
      encoding: 'utf8',
    }).trim();

    const mirrorPath = path.join(dir, 'mirror.yaml');
    writeFileSync(mirrorPath, `i18n_baseline_protected_blob: ${mirrorBlob ?? blob}\n`);

    try {
      const out = execFileSync(
        process.execPath,
        [SCRIPT, '--root', FIXTURE_ROOT, '--baseline', baselinePath, '--mirror', mirrorPath],
        {
          cwd: path.join(REPO_ROOT, 'apps', 'web'),
          encoding: 'utf8',
          env: { ...process.env, I18N_BASELINE_PROTECTED_BLOB: blob, ...env },
        },
      );
      return { status: 0, out };
    } catch (err) {
      return { status: err.status ?? 1, out: `${err.stdout ?? ''}${err.stderr ?? ''}` };
    }
  }

  const runCliSafe = runCli;

  const fixtureBaseline = [
    'ar|alpha|aliased|*',
    'ar|beta|missing|items_one',
    'ar|beta|missing|items_other',
    'ar|beta|missing|two',
    'fr|beta|plural|items_many',
  ];

  it('exits 0 when the pinned blob, the mirror and the working baseline all agree', () => {
    const r = runCliSafe({ baselineEntries: fixtureBaseline });
    expect(r.status).toBe(0);
    expect(r.out).toContain('i18n completeness OK');
  });

  it('FAILS CLOSED when the protected-blob variable is unset', () => {
    const r = runCliSafe({
      baselineEntries: fixtureBaseline,
      env: { I18N_BASELINE_PROTECTED_BLOB: '' },
    });
    expect(r.status).toBe(1);
    expect(r.out).toContain('is unset');
  });

  it('FAILS CLOSED on mirror drift (YAML pin !== variable)', () => {
    const r = runCliSafe({
      baselineEntries: fixtureBaseline,
      mirrorBlob: '0000000000000000000000000000000000000000',
    });
    expect(r.status).toBe(1);
    expect(r.out).toContain('MIRROR DRIFT');
  });

  it('FAILS CLOSED when the protected blob cannot be read', () => {
    const r = runCliSafe({
      baselineEntries: fixtureBaseline,
      env: { I18N_BASELINE_PROTECTED_BLOB: 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef' },
      mirrorBlob: 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
    });
    expect(r.status).toBe(1);
    expect(r.out).toContain('cannot read the protected baseline blob');
  });

  it('FAILS on MATCHED GROWTH — a planted gap plus its matching baseline entry', () => {
    const r = runCliSafe({
      baselineEntries: [...fixtureBaseline, 'ar|beta|missing|plantedTamperKey'],
      protectedEntries: fixtureBaseline,
    });
    expect(r.status).toBe(1);
    expect(r.out).toContain('RATCHET GROWTH');
  });

  it('FAILS on a NEW gap the working baseline does not cover', () => {
    const r = runCliSafe({
      baselineEntries: fixtureBaseline.filter((k) => k !== 'ar|beta|missing|two'),
    });
    expect(r.status).toBe(1);
    expect(r.out).toContain('NEW gap');
  });

  it('FAILS CLOSED when the pinned surface is no longer scanned', () => {
    const r = runCliSafe({
      baselineEntries: [...fixtureBaseline, 'de|alpha|aliased|*'],
      protectedEntries: [...fixtureBaseline, 'de|alpha|aliased|*'],
    });
    expect(r.status).toBe(1);
    expect(r.out).toContain('SCANNED SURFACE SHRANK');
  });

  it('has no --no-ratchet opt-out (a one-word bypass of the trust anchor)', () => {
    const src = readFileSync(SCRIPT, 'utf8');
    expect(src).not.toMatch(/case '--no-ratchet'/);
  });
});

describe('i18n baseline pin tag — ci.yml and the progress YAML must agree', () => {
  it('fetches exactly the pre-allocated tag named in the progress YAML', () => {
    // Tags are never reused, so a seed-changing fix round that allocates a new
    // name must edit BOTH places. Desync fails closed in CI (the new blob is
    // unreachable) but costs a whole gate round to discover.
    const ci = readFileSync(path.join(REPO_ROOT, '.github', 'workflows', 'ci.yml'), 'utf8');
    const yaml = readFileSync(
      path.join(REPO_ROOT, 'docs', 'handoff', 'progress', 'enforcement-p2.progress.yaml'),
      'utf8',
    );
    const pinned = yaml.match(/^i18n_baseline_pin_tag:\s*(\S+)\s*$/m);
    expect(pinned).not.toBeNull();
    expect(ci).toContain(`git fetch origin tag ${pinned[1]}`);
  });
});

describe('audit-i18n-completeness — provenance classifier ignores comments', () => {
  // A COMMENT is prose, not wiring. The classifier scans the assignment text for
  // locale-prefixed identifiers, so reading comments lets a single trailing
  // `// TODO: swap to arAlpha` reclassify an English-aliased namespace as
  // `english-spread` — the audit then trusts the locale file, the whole-namespace
  // `aliased` entry drops into `stale`, and `stale` is a console note, never a
  // failure. The loss of the H-5 invariant would be reported as burn-down PROGRESS.
  //
  // Verified against the pre-fix scanner at 29b6043bd on this exact fixture:
  //   kind ar.alpha = english-spread ; ar|alpha findings: []
  const TAMPER_ROOT = path.join(
    __dirname,
    '..',
    '__fixtures__',
    'i18n-completeness',
    'comment-tamper',
  );

  it('the fixture differs from prod-shaped by exactly one comment', () => {
    const tampered = readFileSync(path.join(TAMPER_ROOT, 'lib', 'i18n.ts'), 'utf8');
    expect(tampered).toContain('alpha: enAlpha, // TODO: swap to arAlpha once the bundle lands');
    // Nothing is actually wired — `arAlpha` is never imported or referenced in code.
    expect(tampered).not.toMatch(/import\s+arAlpha/);
  });

  it('still classifies the namespace as en-aliased and keeps the aliased finding', () => {
    const { wiring, findings } = auditRoot(TAMPER_ROOT);
    expect(wiring.assignments.ar.alpha.kind).toBe('en-aliased');
    expect(findings.map(entryKey)).toContain('ar|alpha|aliased|*');
  });

  it('classifies on comment-stripped code, and keeps the raw text for diagnostics', () => {
    const { wiring } = auditRoot(TAMPER_ROOT);
    expect(wiring.assignments.ar.alpha.raw).toContain('// TODO');
    expect(wiring.assignments.ar.alpha.code).not.toContain('// TODO');
  });
});

describe('audit-i18n-completeness — edge cases the pinned surface cannot see', () => {
  const EDGE_ROOT = path.join(__dirname, '..', '__fixtures__', 'i18n-completeness', 'edge-cases');
  const { structural, findings } = auditRoot(EDGE_ROOT);
  const keys = findings.map(entryKey);

  it('flags an English locale file with no namespace in the `ns` array', () => {
    // missingScannedSurface cannot catch this: a namespace holding no PINNED
    // finding has nothing to be missed. The 12 fully-translated production
    // namespaces are exactly the exposed ones — the files the gate most wants
    // to protect. The English locale directory is the backstop.
    expect(structural.join('\n')).toContain('locales/en/orphan.json');
    expect(structural.join('\n')).toContain('no namespace "orphan" in the `ns` array');
  });

  it('does NOT treat ordinary keys that merely end in a category name as plurals', () => {
    // `step_one` + `step_two` has two category suffixes and no `_other`; a lane
    // adding it would otherwise be told to author six Arabic forms, with no
    // escape short of an owner re-pin.
    expect(keys.filter((k) => k.includes('|step_'))).toEqual([]);
    expect(keys.filter((k) => k.includes('|tier_'))).toEqual([]);
  });

  it('DOES treat a `{{count}}` family with a single authored form as a plural', () => {
    // `files_one` interpolates {{count}} — real i18next plural evidence.
    expect(keys).toContain('en|gamma|plural|files_other');
    expect(keys).toContain('fr|gamma|plural|files_many');
  });

  it('DOES treat an `_one`/`_other` pair as a plural family', () => {
    expect(keys).toContain('fr|gamma|plural|items_many');
  });
});

describe('audit-i18n-completeness — spread ORDER decides who wins', () => {
  // `{ ...arBeta, ...enBeta }` applies English LAST, so English wins every key
  // and nothing the locale authored is ever served. A classifier that only asks
  // WHICH identifiers appear cannot see that — and would then trust the locale
  // file wholesale for a namespace rendered 100% in English.
  //
  // The fixture authors EVERY English key in locales/ar/beta.json, so a
  // file-based diff reports full parity. Measured against the pre-fix scanner
  // at 0e9e18540: kind = english-spread, zero `aliased` entry.
  //
  // On the production tree the same single-token edit
  // (`notifications: { ...arNotifications, ...enNotifications }`) produced
  // `kind = english-spread` and ZERO findings for a namespace that would then
  // be served entirely in English.
  const ORDER_ROOT = path.join(
    __dirname,
    '..',
    '__fixtures__',
    'i18n-completeness',
    'spread-order',
  );

  it('the fixture is English-last and the locale file is COMPLETE', () => {
    const src = readFileSync(path.join(ORDER_ROOT, 'lib', 'i18n.ts'), 'utf8');
    expect(src).toContain('beta: { ...arBeta, ...enBeta }');
    const arBeta = JSON.parse(
      readFileSync(path.join(ORDER_ROOT, 'locales', 'ar', 'beta.json'), 'utf8'),
    );
    const enBeta = JSON.parse(
      readFileSync(path.join(ORDER_ROOT, 'locales', 'en', 'beta.json'), 'utf8'),
    );
    // Nothing is missing from a file-diff point of view — that is the trap.
    expect(flattenKeys(enBeta).every((k) => flattenKeys(arBeta).includes(k))).toBe(true);
  });

  it('classifies English-last as en-aliased, not english-spread', () => {
    const { wiring } = auditRoot(ORDER_ROOT);
    expect(wiring.assignments.ar.beta.kind).toBe('en-aliased');
  });

  it('reports the namespace as untranslated despite the complete locale file', () => {
    const keys = auditRoot(ORDER_ROOT).findings.map(entryKey);
    expect(keys).toContain('ar|beta|aliased|*');
    expect(keys.filter((k) => k.startsWith('ar|beta|plural'))).toEqual([]);
  });

  it('keeps English-FIRST spreads meaningful (prod-shaped is unaffected)', () => {
    const { wiring } = auditRoot(FIXTURE_ROOT);
    expect(wiring.assignments.ar.beta.kind).toBe('english-spread');
  });
});

const SIBLING_ROOT = path.join(
  __dirname,
  '..',
  '__fixtures__',
  'i18n-completeness',
  'sibling-scope',
);

describe('audit-i18n-completeness — the orphan backstop cannot be quietly widened', () => {
  it('KNOWN_UNWIRED_LOCALE_FILES holds exactly the one reasoned exception', () => {
    // Adding a namespace to this Set disables the backstop for it permanently.
    // Per docs/conventions/08-DETECTOR-LIVENESS.md, the silencer needs a test
    // that goes red when it grows.
    expect([...KNOWN_UNWIRED_LOCALE_FILES].sort()).toEqual(['users']);
  });

  it('watches every locale directory, not just en (BEHAVIOURAL)', () => {
    // Deleting the English file along with the wiring would otherwise escape the
    // backstop. `locales/{ar,fr}/zeta.json` exist in the sibling-scope fixture
    // with NO `en` counterpart; an en-only readdirSync leaves this green, which
    // is the vacuous-liveness failure 08-DETECTOR-LIVENESS.md forbids.
    const { structural } = auditRoot(SIBLING_ROOT);
    const joined = structural.join('\n');
    expect(joined).toContain('locales/ar/zeta.json');
    expect(joined).toContain('locales/fr/zeta.json');
    expect(joined).toContain('no namespace "zeta"');
  });
});

describe('audit-i18n-completeness — spread order is keyed per OBJECT LITERAL, not per depth', () => {
  // Sibling literals inside one namespace share a brace depth. Depth-keying let a
  // later English-FIRST sibling overwrite the record of an earlier English-LAST
  // one, so reversing a nested subtree went silent — and that is the shape
  // production actually uses (`settings` has sections/company/locations siblings;
  // `finance.overview` has cash/upcoming/trend).
  //
  // Measured on a copy of the real tree, reversing ONLY `settings.sections`:
  //   PRE-FIX  (007b1a49c, depth-keyed) kind ar.settings = english-spread, no aliased entry
  //   POST-FIX (scope-keyed)            kind ar.settings = en-aliased, aliased entry present
  it('flags an English-LAST sibling even when a later sibling is English-first', () => {
    const { wiring, findings } = auditRoot(SIBLING_ROOT);
    expect(wiring.assignments.ar.beta.kind).toBe('en-aliased');
    expect(findings.map(entryKey)).toContain('ar|beta|aliased|*');
  });

  it('leaves an all-English-first namespace classified as english-spread', () => {
    expect(auditRoot(FIXTURE_ROOT).wiring.assignments.ar.beta.kind).toBe('english-spread');
  });
});
