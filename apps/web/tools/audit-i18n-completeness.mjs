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
 *   1. `missing` — a key authored in `en/<ns>.json` with no counterpart in
 *      `<locale>/<ns>.json` (a namespace with no file at all = every key missing).
 *   2. `plural`  — per-locale CLDR plural-category completeness. A flat en↔fr
 *      key diff is structurally blind to this: the required categories come
 *      from CLDR per locale (French requires `many`, which English has no
 *      counterpart for; Arabic requires all six). Categories are DERIVED at
 *      runtime from `Intl.PluralRules(locale).resolvedOptions()`, never
 *      hardcoded. Only families the locale already authors ≥1 form of are
 *      checked — a family it authors none of is already a `missing` finding.
 *
 * STRUCTURAL failures (never baselined, always fatal): a namespace in the `ns`
 * array with no entry in a locale's `resources` block, a namespace with no
 * English source file, or an unparseable translation file.
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
      assignments[locale][ns] = { raw, kind: classifyAssignment(locale, raw) };
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

/** Group a locale's authored keys into plural families: base -> Set(category). */
function pluralFamilies(keys) {
  const fams = new Map();
  for (const k of keys) {
    const m = k.match(new RegExp(`^(.*)_(${PLURAL_CATEGORIES.join('|')})$`));
    if (!m) continue;
    if (!fams.has(m[1])) fams.set(m[1], new Set());
    fams.get(m[1]).add(m[2]);
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
  const stats = { keysPerLocale: {}, namespaces: wiring.namespaces.length };

  for (const locale of wiring.locales) stats.keysPerLocale[locale] = 0;

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
      const authored = tree === null ? [] : flattenKeys(tree);
      if (locale !== 'en') stats.keysPerLocale[locale] += authored.length;
      const authoredSet = new Set(authored);

      if (locale !== 'en') {
        for (const k of enKeys) {
          if (!authoredSet.has(k)) findings.push({ locale, ns, type: 'missing', key: k });
        }
      }

      // Per-locale CLDR plural completeness over the families this locale authors.
      const required = pluralCategoriesFor(locale);
      for (const [base, cats] of pluralFamilies(authored)) {
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
    ratchet: true,
    json: false,
  };
  for (let i = 0; i < argv.length; i += 1) {
    switch (argv[i]) {
      case '--root': opts.root = path.resolve(argv[++i]); break;
      case '--baseline': opts.baseline = path.resolve(argv[++i]); break;
      case '--mirror': opts.mirror = path.resolve(argv[++i]); break;
      case '--write-baseline': opts.write = true; break;
      case '--no-ratchet': opts.ratchet = false; break;
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
  if (opts.ratchet) {
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
    console.log(
      `i18n completeness OK — ${wiring.namespaces.length} namespaces, authored keys: ${perLocale}; ` +
        `${covered.length} known gap(s) held at the baseline.`,
    );
  }

  process.exit(failed ? 1 : 0);
}

const invokedDirectly =
  process.argv[1] && path.resolve(process.argv[1]) === path.resolve(fileURLToPath(import.meta.url));
if (invokedDirectly) main();
