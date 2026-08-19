#!/usr/bin/env node
// @ts-check
/**
 * i18n completeness gate (enforcement Package 2, deliverable 2(c)).
 *
 * WHY THIS CANNOT READ THE MERGED `resources` OBJECT (gate-r1 H-5)
 * ---------------------------------------------------------------
 * `src/lib/i18n.ts` builds its runtime `resources` graph two ways that destroy
 * provenance:
 *   * whole-namespace English aliasing — e.g. `auth: enAuth` under `ar`;
 *   * `{ ...enX, ...arX }` spreads — English keys sit underneath a partial
 *     Arabic object.
 * Combined with `fallbackLng: 'en'`, a scanner that imports `resources` sees
 * every aliased/spread-supplied English key as "present in ar" and reports
 * vacuous full parity. This audit therefore reads AUTHORED-LOCALE PROVENANCE:
 * the per-locale source translation files under `src/locales/<locale>/<ns>.json`.
 * A key English supplies on a non-English locale's behalf is UNTRANSLATED.
 *
 * WHAT IT CHECKS
 * --------------
 *   1. `aliased` — the namespace is WIRED to the English bundle for that locale
 *      (`catalog: enCatalog` under `ar`), so the runtime serves English no matter
 *      what sits in `locales/<locale>/`. One entry per (locale, namespace), never
 *      per key: per-key entries would make every NEW English key an instant CI
 *      failure in namespaces the locale does not cover at all. The entry can only
 *      be cleared by WIRING a real bundle — never by adding unwired JSON files,
 *      which is precisely the ratchet bypass this classification closes.
 *   2. `missing` — a key authored in `en/<ns>.json` with no counterpart in
 *      `<locale>/<ns>.json`, for namespaces the locale IS wired into (`own` or
 *      `{...en, ...partialLocale}` spread).
 *   3. `plural`  — per-locale CLDR plural-category completeness. A flat en↔fr
 *      key diff is structurally blind to this: the required categories come
 *      from CLDR per locale (French requires `many`, which English has no
 *      counterpart for; Arabic requires all six). Categories are DERIVED at
 *      runtime from `Intl.PluralRules(locale).resolvedOptions()`, never
 *      hardcoded. Only families the locale already authors ≥1 form of are
 *      checked — a family it authors none of is already a `missing` finding.
 *
 * STRUCTURAL failures (never baselined, always fatal): a namespace in the `ns`
 * array with no entry in a locale's `resources` block; a namespace wired under
 * `en` but absent from the `ns` array; a `locales/<locale>/` directory with no
 * parseable block in `resources` (a reformat that breaks the wiring parse must
 * not look like burn-down); a namespace with no English source file; or an
 * unparseable translation file.
 *
 * SURFACE-COVERAGE INVARIANT: every `locale|namespace` the PINNED protected
 * baseline references must still be inside the scanned surface. Stale baseline
 * entries are burn-down (a note, never a failure), so without this a vanished
 * locale or namespace would be reported as progress.
 *
 * RATCHET — TWO-PHASE PINNED BASELINE (gate-r2 R2-C-1, gate-r3 R3-C-1)
 * --------------------------------------------------------------------
 * `tools/i18n-completeness-baseline.json` is shrink-only: findings inside it
 * pass, NEW findings fail. The baseline file itself is candidate-editable, so
 * the anti-growth authority is NOT the file and NOT a branch name — it is the
 * OWNER-SET GitHub Actions repository variable `I18N_BASELINE_PROTECTED_BLOB`,
 * holding the git blob hash of the gate-reviewed seed baseline. The checker
 * fetches that blob (`git cat-file blob`), parses its key set, and fails on any
 * key ADDED relative to it — so the matched-growth tamper (plant a violation
 * AND add its baseline entry) fails by construction.
 *
 * FAIL CLOSED on: unset variable · unfetchable blob · progress-YAML mirror
 * drift (the non-authoritative mirror `i18n_baseline_protected_blob` must equal
 * the variable). Bootstrap and every re-pin are owner writes at promotion time.
 *
 * LOCAL runs must perform the authority setup first (brief §3 acceptance block):
 *   seed_blob=$(git rev-parse <i18n_baseline_seed_commit>:<baseline-path>)
 *   grep -q "$seed_blob" docs/handoff/progress/enforcement-p2.progress.yaml
 *   export I18N_BASELINE_PROTECTED_BLOB="$seed_blob"
 *
 * Run via `pnpm audit:i18n` (local lint-chain parity) — the CI wiring is an
 * explicit discrete step in the `frontend-lint` job, never the lint chain.
 */

import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const WEB_ROOT = path.resolve(__dirname, '..');
const REPO_ROOT = path.resolve(WEB_ROOT, '..', '..');

export const DEFAULT_ROOT = path.join(WEB_ROOT, 'src');
export const DEFAULT_BASELINE = path.join(__dirname, 'i18n-completeness-baseline.json');
export const DEFAULT_MIRROR = path.join(
  REPO_ROOT,
  'docs',
  'handoff',
  'progress',
  'enforcement-p2.progress.yaml',
);
export const PROTECTED_BLOB_ENV = 'I18N_BASELINE_PROTECTED_BLOB';

/** Every CLDR plural category name a key suffix may carry. */
export const PLURAL_CATEGORIES = ['zero', 'one', 'two', 'few', 'many', 'other'];

/**
 * Translation files that exist on disk with no namespace in the `ns` array.
 *
 * Each entry is a deliberate, reasoned exception — not a place to silence the
 * structural check. Anything not listed here FAILS, which is what stops a
 * fully-translated namespace being quietly unwired.
 *
 *   users — DEAD FILE, verified at the enforcement-p2 base: 4 keys, present in
 *           `en` and `fr`, imported by nothing (`grep -rn 'users.json' src/` →
 *           no hits), no `ns` entry, no `t('users:…')` or
 *           `useTranslation('users')` callsite anywhere. Deleting translation
 *           files is not this package's scope (guard-only), so it is recorded
 *           rather than removed.
 */
export const KNOWN_UNWIRED_LOCALE_FILES = new Set(['users']);

/* ------------------------------------------------------------------ parsing */

/** Flatten a translation object to sorted dotted leaf paths. */
export function flattenKeys(obj, prefix = '') {
  const out = [];
  for (const [k, v] of Object.entries(obj ?? {})) {
    const p = prefix ? `${prefix}.${k}` : k;
    if (v && typeof v === 'object' && !Array.isArray(v)) out.push(...flattenKeys(v, p));
    else out.push(p);
  }
  return out;
}

/** Flatten a translation object to a Map of dotted leaf path -> leaf value. */
export function flattenEntries(obj, prefix = '', out = new Map()) {
  for (const [k, v] of Object.entries(obj ?? {})) {
    const p = prefix ? `${prefix}.${k}` : k;
    if (v && typeof v === 'object' && !Array.isArray(v)) flattenEntries(v, p, out);
    else out.set(p, v);
  }
  return out;
}

/** CLDR plural categories required by a locale, derived at runtime. */
export function pluralCategoriesFor(locale) {
  return new Intl.PluralRules(locale).resolvedOptions().pluralCategories;
}

/**
 * Parse `<root>/lib/i18n.ts` for the namespace list (`ns: [...]`), the locale
 * list (the `resources` object's top-level keys) and, per (locale, namespace),
 * the raw assignment text classified by provenance:
 *   `own`             — references only same-locale imports
 *   `en-aliased`      — references only `en*` imports (whole-namespace alias)
 *   `english-spread`  — references both (`{...enX, ...arX}`)
 */
export function parseI18nWiring(root) {
  const file = path.join(root, 'lib', 'i18n.ts');
  const src = fs.readFileSync(file, 'utf8');

  const nsMatch = src.match(/\bns:\s*\[([\s\S]*?)\]/);
  if (!nsMatch) throw new Error(`could not find the i18next \`ns\` array in ${file}`);
  const namespaces = nsMatch[1]
    .split(',')
    .map((s) => s.trim().replace(/^['"]|['"]$/g, ''))
    .filter(Boolean);

  const lines = src.split('\n');
  const startIdx = lines.findIndex((l) => /^const resources\s*=\s*\{/.test(l));
  if (startIdx === -1) throw new Error(`could not find \`const resources = {\` in ${file}`);

  const locales = [];
  const assignments = {};
  let i = startIdx + 1;
  while (i < lines.length) {
    const line = lines[i];
    if (/^\}/.test(line)) break; // end of `resources`
    const localeOpen = line.match(/^ {2}(['"]?)([\w-]+)\1:\s*\{\s*$/);
    if (!localeOpen) {
      i += 1;
      continue;
    }
    const locale = localeOpen[2];
    locales.push(locale);
    assignments[locale] = {};
    i += 1;
    // Walk the locale block, brace-balancing multi-line namespace values.
    while (i < lines.length && !/^ {2}\},?\s*$/.test(lines[i])) {
      const nsOpen = lines[i].match(/^ {4}(['"]?)([\w-]+)\1:\s*(.*)$/);
      if (!nsOpen) {
        i += 1;
        continue;
      }
      const ns = nsOpen[2];
      let raw = nsOpen[3];
      let depth = countBraces(raw);
      while (depth > 0 && i + 1 < lines.length) {
        i += 1;
        raw += `\n${lines[i]}`;
        depth += countBraces(lines[i]);
      }
      assignments[locale][ns] = {
        raw,
        code: stripComments(raw),
        kind: classifyAssignment(locale, stripComments(raw)),
      };
      i += 1;
    }
  }

  return { namespaces, locales, assignments, file };
}

function countBraces(s) {
  let d = 0;
  for (const ch of s) {
    if (ch === '{') d += 1;
    else if (ch === '}') d -= 1;
  }
  return d;
}

/**
 * Remove `//…` and block comments before provenance classification.
 *
 * The classifier looks for locale-prefixed identifiers in the assignment text.
 * Reading comments makes a PROSE mention count as a wiring reference, so
 * `alpha: enAlpha, // TODO: swap to arAlpha once the bundle lands` reclassifies
 * an English-aliased namespace as `english-spread` — the audit then trusts the
 * locale file, the whole-namespace `aliased` entry drops into `stale`, and the
 * loss of the H-5 invariant is reported as burn-down PROGRESS. The realistic
 * arrival is a revert comment (`catalog: enCatalog, // reverted, RTL broken`),
 * after which the regression is invisible to the gate forever.
 */
function stripComments(raw) {
  return raw.replace(/\/\*[\s\S]*?\*\//g, ' ').replace(/\/\/[^\n]*/g, ' ');
}

function classifyAssignment(locale, raw) {
  const idents = raw.match(/\b[a-z]{2}[A-Z][A-Za-z0-9]*/g) ?? [];
  const prefix = locale.slice(0, 2);
  const own = idents.some((id) => id.startsWith(prefix));
  const english = idents.some((id) => id.startsWith('en'));
  if (locale === 'en') return 'own';
  if (own && english) return 'english-spread';
  if (own) return 'own';
  if (english) return 'en-aliased';
  return 'unknown';
}

/* ------------------------------------------------------------------- audit  */

function readNsFile(root, locale, ns) {
  const p = path.join(root, 'locales', locale, `${ns}.json`);
  if (!fs.existsSync(p)) return null;
  try {
    return JSON.parse(fs.readFileSync(p, 'utf8'));
  } catch (err) {
    const e = new Error(`unparseable translation file ${p}: ${err.message}`);
    e.structural = true;
    throw e;
  }
}

/**
 * Group a locale's authored keys into i18next plural families: base -> Set(category).
 *
 * A trailing `_one` / `_two` / `_few` … is NOT sufficient evidence on its own —
 * ordinary keys like `wizard.step_one` or `tier_two` end that way and are not
 * plurals. Demanding the full CLDR set for them would hard-fail CI for the lane
 * that adds one, with no escape short of an owner re-pin. A family therefore
 * qualifies only on real i18next evidence:
 *
 *   * at least one of its authored values interpolates `{{count}}` (the marker
 *     i18next itself keys plural resolution on), OR
 *   * the locale authors an `_other` form PLUS at least one other category.
 *     `_other` is i18next's mandatory fallback form for every plural key, so its
 *     presence alongside a sibling is real evidence; `step_one` + `step_two`
 *     (two categories, no `_other`) is not, and neither is a lone `tier_two`.
 *
 * Both signals are needed. `fr/sales.json` `partners.countLabels.customer_one` /
 * `_other` is a genuine family that never interpolates `{{count}}` (the number is
 * rendered separately), and `en/batches.json` `batchCount_other` is a genuine
 * family with only ONE suffixed form — each is caught by the other rule.
 * Verified against the seed baseline: byte-identical, so this removes a
 * false-positive class without weakening any live detection.
 *
 * @param {Map<string,string>} entries authored key -> value
 */
function pluralFamilies(entries) {
  const candidates = new Map();
  for (const [key, value] of entries) {
    const m = key.match(new RegExp(`^(.*)_(${PLURAL_CATEGORIES.join('|')})$`));
    if (!m) continue;
    if (!candidates.has(m[1])) candidates.set(m[1], { cats: new Set(), count: false });
    const fam = candidates.get(m[1]);
    fam.cats.add(m[2]);
    if (typeof value === 'string' && value.includes('{{count}}')) fam.count = true;
  }

  const fams = new Map();
  for (const [base, fam] of candidates) {
    if (fam.count || (fam.cats.has('other') && fam.cats.size >= 2)) fams.set(base, fam.cats);
  }

  return fams;
}

/** Stable identity of one finding, and of one baseline line. */
export function entryKey(f) {
  return `${f.locale}|${f.ns}|${f.type}|${f.key}`;
}

/**
 * Audit one i18n root (production: `apps/web/src`).
 * @returns {{wiring: object, findings: object[], structural: string[], stats: object}}
 */
export function auditRoot(root) {
  const wiring = parseI18nWiring(root);
  const structural = [];
  const findings = [];
  const stats = {
    keysPerLocale: {},
    namespaces: wiring.namespaces.length,
    aliasedNamespaces: {},
    keysBehindAliases: {},
  };

  for (const locale of wiring.locales) {
    stats.keysPerLocale[locale] = 0;
    stats.aliasedNamespaces[locale] = 0;
    stats.keysBehindAliases[locale] = 0;
  }

  // The parse is line-oriented; a locale block that stops matching would drop
  // that locale's entire finding set silently (and be reported as burn-down).
  // Every locale directory on disk must therefore be present in the parsed
  // `resources` graph — a reformat that hides one is STRUCTURAL, not progress.
  const localesDir = path.join(root, 'locales');
  if (fs.existsSync(localesDir)) {
    for (const entry of fs.readdirSync(localesDir, { withFileTypes: true })) {
      if (!entry.isDirectory() || entry.name.startsWith('__')) continue;
      if (!wiring.locales.includes(entry.name)) {
        structural.push(
          `locale "${entry.name}" has a locales/ directory but no parseable block in the \`resources\` object ` +
            `(parsed locales: ${wiring.locales.join(', ')}) — the wiring parse may have been broken by a reformat`,
        );
      }
    }
  }

  // A namespace wired under `en` but absent from the `ns` array would drop out
  // of the scanned surface entirely while its translations keep working.
  for (const ns of Object.keys(wiring.assignments.en ?? {})) {
    if (!wiring.namespaces.includes(ns)) {
      structural.push(
        `namespace "${ns}" is wired in the \`en\` resources block but is missing from the \`ns\` array — ` +
          'it would silently leave the audited surface',
      );
    }
  }

  // …and the same escape from the other side: dropping a namespace from the `ns`
  // array AND from the `resources` blocks leaves its translation files on disk,
  // unscanned, with nothing to complain. `missingScannedSurface` cannot see it
  // either when the namespace holds no pinned findings — which is exactly the
  // case for the fully-translated namespaces, i.e. the ones whose regression the
  // gate most wants to catch. The English locale directory is the backstop.
  const enLocaleDir = path.join(root, 'locales', 'en');
  if (fs.existsSync(enLocaleDir)) {
    for (const file of fs.readdirSync(enLocaleDir)) {
      if (!file.endsWith('.json')) continue;
      const ns = file.slice(0, -'.json'.length);
      if (wiring.namespaces.includes(ns)) continue;
      if (KNOWN_UNWIRED_LOCALE_FILES.has(ns)) continue;
      structural.push(
        `locale file locales/en/${file} has no namespace in the \`ns\` array — either wire it up, ` +
          'delete it, or record it in KNOWN_UNWIRED_LOCALE_FILES with a reason. An unscanned ' +
          'translation file is a namespace the gate cannot protect.',
      );
    }
  }

  for (const ns of wiring.namespaces) {
    for (const locale of wiring.locales) {
      if (!wiring.assignments[locale] || !(ns in wiring.assignments[locale])) {
        structural.push(
          `namespace "${ns}" is listed in the \`ns\` array but has no entry in the \`${locale}\` resources block`,
        );
      }
    }

    let enTree;
    try {
      enTree = readNsFile(root, 'en', ns);
    } catch (err) {
      structural.push(err.message);
      continue;
    }
    if (enTree === null) {
      structural.push(`namespace "${ns}" has no English source file (locales/en/${ns}.json)`);
      continue;
    }
    const enKeys = flattenKeys(enTree);
    stats.keysPerLocale.en += enKeys.length;

    for (const locale of wiring.locales) {
      let tree;
      try {
        tree = readNsFile(root, locale, ns);
      } catch (err) {
        structural.push(err.message);
        continue;
      }
      const authoredEntries = tree === null ? new Map() : flattenEntries(tree);
      const authored = [...authoredEntries.keys()];
      if (locale !== 'en') stats.keysPerLocale[locale] += authored.length;
      const authoredSet = new Set(authored);

      // PROVENANCE GATE (gate-r1 H-5). A namespace WIRED to the English bundle
      // serves English at runtime no matter what sits in locales/<locale>/.
      // Deciding coverage from the file alone would credit `ar/catalog.json`
      // (261 authored keys) as translated while `i18n.ts` wires
      // `catalog: enCatalog` under `ar` — and would let the Arabic baseline be
      // "burned down" by dropping unwired JSON files into the tree.
      // `unknown` is treated the same way: fail closed on an unrecognised shape.
      const kind = wiring.assignments[locale]?.[ns]?.kind ?? 'unknown';
      if (locale !== 'en' && (kind === 'en-aliased' || kind === 'unknown')) {
        // ONE entry per aliased (locale, namespace), not one per key. Per-key
        // entries would make every NEW English key an instant CI failure in the
        // 23 namespaces Arabic does not cover at all — an effective
        // full-parity-on-every-new-key policy the repo's en+fr posture does not
        // carry. The single entry can only be removed by WIRING a real bundle,
        // never by adding files, which is the invariant that matters.
        findings.push({ locale, ns, type: 'aliased', key: '*' });
        stats.aliasedNamespaces[locale] += 1;
        stats.keysBehindAliases[locale] += enKeys.length;
        continue;
      }

      if (locale !== 'en') {
        for (const k of enKeys) {
          if (!authoredSet.has(k)) findings.push({ locale, ns, type: 'missing', key: k });
        }
      }

      // Per-locale CLDR plural completeness over the families this locale authors.
      const required = pluralCategoriesFor(locale);
      for (const [base, cats] of pluralFamilies(authoredEntries)) {
        for (const cat of required) {
          if (!cats.has(cat)) {
            findings.push({ locale, ns, type: 'plural', key: `${base}_${cat}` });
          }
        }
      }
    }
  }

  findings.sort((a, b) => entryKey(a).localeCompare(entryKey(b)));
  return { wiring, findings, structural, stats };
}

/* ----------------------------------------------------------------- ratchet  */

/**
 * Split findings against a shrink-only baseline.
 * `fresh` = new findings (FAIL) · `covered` = baselined · `stale` = baseline
 * entries that no longer occur (burn-down; reported, never a failure).
 */
export function partitionByBaseline(findings, baselineEntries) {
  const base = new Set(baselineEntries);
  const seen = new Set();
  const fresh = [];
  const covered = [];
  for (const f of findings) {
    const k = entryKey(f);
    seen.add(k);
    if (base.has(k)) covered.push(k);
    else fresh.push(k);
  }
  const stale = baselineEntries.filter((k) => !seen.has(k));
  return { fresh: fresh.sort(), covered: covered.sort(), stale: stale.sort() };
}

/** Keys the working baseline ADDS relative to the pinned protected baseline. */
export function addedKeysAgainstProtected(workingEntries, protectedEntries) {
  const prot = new Set(protectedEntries);
  return workingEntries.filter((k) => !prot.has(k)).sort();
}

/**
 * SURFACE-COVERAGE INVARIANT.
 *
 * Every `locale|namespace` the PINNED protected baseline knows about must still
 * be inside the surface this run actually scanned. Without this, a locale block
 * the line-oriented parser stops recognising — or a namespace dropped from the
 * `ns` array — silently removes thousands of findings, and the shrink-only
 * comparison reports the loss as burn-down PROGRESS (stale entries are a
 * console note, never a failure). The protected baseline is the one description
 * of the surface no candidate can edit, so it is the right thing to check against.
 *
 * @returns {string[]} sorted "locale|ns" pairs that are no longer scanned
 */
export function missingScannedSurface(protectedEntries, locales, namespaces) {
  const localeSet = new Set(locales);
  const nsSet = new Set(namespaces);
  const gone = new Set();
  for (const entry of protectedEntries) {
    const [locale, ns] = entry.split('|');
    if (!localeSet.has(locale) || !nsSet.has(ns)) gone.add(`${locale}|${ns}`);
  }
  return [...gone].sort();
}

export function parseBaselineDocument(text, label) {
  let doc;
  try {
    doc = JSON.parse(text);
  } catch (err) {
    throw new Error(`${label}: not valid JSON (${err.message})`);
  }
  if (!doc || !Array.isArray(doc.entries)) {
    throw new Error(`${label}: expected an object with an "entries" array`);
  }
  return doc.entries;
}

/** Read the NON-AUTHORITATIVE mirror pin out of the package progress YAML. */
export function readMirrorPin(mirrorPath) {
  const text = fs.readFileSync(mirrorPath, 'utf8');
  const m = text.match(/^i18n_baseline_protected_blob:\s*(\S+)\s*$/m);
  if (!m || m[1] === 'null') return null;
  return m[1].replace(/^['"]|['"]$/g, '');
}

/* --------------------------------------------------------------------- CLI  */

function parseArgs(argv) {
  const opts = {
    root: DEFAULT_ROOT,
    baseline: DEFAULT_BASELINE,
    mirror: DEFAULT_MIRROR,
    write: false,
    json: false,
  };
  for (let i = 0; i < argv.length; i += 1) {
    switch (argv[i]) {
      case '--root': opts.root = path.resolve(argv[++i]); break;
      case '--baseline': opts.baseline = path.resolve(argv[++i]); break;
      case '--mirror': opts.mirror = path.resolve(argv[++i]); break;
      case '--write-baseline': opts.write = true; break;
      case '--json': opts.json = true; break;
      default: throw new Error(`unknown argument: ${argv[i]}`);
    }
  }
  return opts;
}

function main() {
  const opts = parseArgs(process.argv.slice(2));
  const { findings, structural, stats, wiring } = auditRoot(opts.root);

  if (structural.length > 0) {
    console.error('i18n completeness — STRUCTURAL failures (never baselined):');
    for (const s of structural) console.error(`  ✗ ${s}`);
    process.exit(1);
  }

  const entries = findings.map(entryKey);

  if (opts.write) {
    const doc = {
      note:
        'Shrink-only i18n completeness baseline. Entries are "<locale>|<namespace>|<type>|<key>". ' +
        'Keys may be REMOVED as translations land; adding any key fails CI against the owner-pinned ' +
        'protected blob (repository variable I18N_BASELINE_PROTECTED_BLOB). Regenerate with ' +
        '`node tools/audit-i18n-completeness.mjs --write-baseline`.',
      entries: [...entries].sort(),
    };
    fs.writeFileSync(opts.baseline, `${JSON.stringify(doc, null, 2)}\n`);
    console.log(`i18n completeness baseline written: ${doc.entries.length} entries → ${opts.baseline}`);
    process.exit(0);
  }

  if (!fs.existsSync(opts.baseline)) {
    console.error(`i18n completeness — baseline missing: ${opts.baseline}`);
    console.error('  bootstrap with: node tools/audit-i18n-completeness.mjs --write-baseline');
    process.exit(1);
  }
  const workingEntries = parseBaselineDocument(
    fs.readFileSync(opts.baseline, 'utf8'),
    opts.baseline,
  );

  let failed = false;

  // ---- anti-growth against the OWNER-PINNED protected blob (fail closed) ----
  // There is deliberately NO opt-out flag: a `--no-ratchet` escape hatch would
  // be a one-word bypass of the package's own trust anchor.
  {
    const pinned = process.env[PROTECTED_BLOB_ENV];
    if (!pinned) {
      console.error(
        `i18n completeness — FAIL CLOSED: ${PROTECTED_BLOB_ENV} is unset.\n` +
          '  The protected baseline revision is an OWNER-SET repository variable; the checker never\n' +
          '  trusts the working file or a branch name. In CI it is mapped from\n' +
          `  \${{ vars.${PROTECTED_BLOB_ENV} }}. Locally, run the authority setup documented at the\n` +
          '  top of this file.',
      );
      process.exit(1);
    }
    let mirror;
    try {
      mirror = readMirrorPin(opts.mirror);
    } catch (err) {
      console.error(`i18n completeness — FAIL CLOSED: cannot read the mirror pin: ${err.message}`);
      process.exit(1);
    }
    if (!mirror) {
      console.error(
        `i18n completeness — FAIL CLOSED: i18n_baseline_protected_blob is unset in ${opts.mirror}`,
      );
      process.exit(1);
    }
    if (mirror !== pinned) {
      console.error(
        'i18n completeness — FAIL CLOSED: MIRROR DRIFT.\n' +
          `  ${PROTECTED_BLOB_ENV} = ${pinned}\n` +
          `  progress-YAML mirror  = ${mirror}\n` +
          '  The YAML mirror is a paper trail, not the authority; a divergence means one of the two\n' +
          '  was changed without the other. Re-pin both together (owner, at promotion).',
      );
      process.exit(1);
    }
    let protectedEntries;
    try {
      const blob = execFileSync('git', ['cat-file', 'blob', pinned], {
        cwd: REPO_ROOT,
        encoding: 'utf8',
        maxBuffer: 64 * 1024 * 1024,
      });
      protectedEntries = parseBaselineDocument(blob, `protected blob ${pinned}`);
    } catch (err) {
      console.error(
        `i18n completeness — FAIL CLOSED: cannot read the protected baseline blob ${pinned}.\n` +
          `  ${err.message}\n` +
          '  CI must fetch the durable pin tag first (git fetch origin tag <i18n_baseline_pin_tag>).',
      );
      process.exit(1);
    }
    const goneSurface = missingScannedSurface(
      protectedEntries,
      wiring.locales,
      wiring.namespaces,
    );
    if (goneSurface.length > 0) {
      console.error(
        `i18n completeness — FAIL CLOSED: SCANNED SURFACE SHRANK. ${goneSurface.length} ` +
          'locale|namespace pair(s) present in the pinned protected baseline are no longer scanned:',
      );
      for (const k of goneSurface) console.error(`  ✗ ${k}`);
      console.error(
        '  A locale block the wiring parser stopped recognising, or a namespace dropped from the `ns`\n' +
          '  array, removes findings silently — and the shrink-only comparison would report the loss as\n' +
          '  burn-down PROGRESS. Fix the wiring, or regenerate + re-pin deliberately (owner).',
      );
      process.exit(1);
    }

    const added = addedKeysAgainstProtected(workingEntries, protectedEntries);
    if (added.length > 0) {
      failed = true;
      console.error(
        `i18n completeness — RATCHET GROWTH: ${added.length} baseline entr${added.length === 1 ? 'y' : 'ies'} ` +
          `added relative to the pinned protected baseline (${pinned}). The baseline is REMOVAL-ONLY.`,
      );
      for (const k of added) console.error(`  + ${k}`);
    }
  }

  // ---- shrink-only comparison of live findings against the working baseline ----
  const { fresh, covered, stale } = partitionByBaseline(findings, workingEntries);
  if (fresh.length > 0) {
    failed = true;
    console.error(`i18n completeness — ${fresh.length} NEW gap(s) not in the baseline:`);
    for (const k of fresh) console.error(`  ✗ ${k}`);
  }
  if (stale.length > 0) {
    console.log(
      `i18n completeness — ${stale.length} baseline entr${stale.length === 1 ? 'y' : 'ies'} now translated ` +
        '(burn-down; regenerate the baseline and re-pin to lock the gain in).',
    );
  }

  if (opts.json) {
    console.log(JSON.stringify({ stats, fresh, covered, stale, findings }, null, 2));
  } else if (!failed) {
    const perLocale = Object.entries(stats.keysPerLocale)
      .map(([l, n]) => `${l}=${n}`)
      .join(' ');
    const aliased = Object.entries(stats.aliasedNamespaces)
      .filter(([, n]) => n > 0)
      .map(([l, n]) => `${l}: ${n} ns / ${stats.keysBehindAliases[l]} keys served in English`)
      .join('; ');
    console.log(
      `i18n completeness OK — ${wiring.namespaces.length} namespaces, authored keys: ${perLocale}; ` +
        `${covered.length} known gap(s) held at the baseline.`,
    );
    if (aliased) console.log(`  English-aliased namespaces — ${aliased}`);
  }

  process.exit(failed ? 1 : 0);
}

const invokedDirectly =
  process.argv[1] && path.resolve(process.argv[1]) === path.resolve(fileURLToPath(import.meta.url));
if (invokedDirectly) main();
