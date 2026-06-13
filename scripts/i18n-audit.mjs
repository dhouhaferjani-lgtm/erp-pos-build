#!/usr/bin/env node
// i18n audit / tracker generator.
//
// Produces a single source of truth for the EN/FR translation sweep:
//
//   1. PARITY — for each app (web, pos) and namespace, compares the `en`
//      locale against `fr`: keys present in EN but missing from FR, keys whose
//      FR value is byte-identical to EN (candidate untranslated — filtered
//      through an allowlist of legitimately-identical tokens like "SKU",
//      "Email", ISO codes), and stray keys present in FR but not EN.
//
//   2. HARDCODED — scans every .tsx component for user-facing string literals
//      that bypass react-i18next `t()`: JSX text nodes and a small set of
//      user-facing attributes (placeholder/title/alt/aria-label/label).
//      Grouped into clusters (one per feature dir) so the sweep can be worked
//      and checkpointed cluster-by-cluster.
//
// Output: docs/i18n/i18n-tracker.yaml  (committed; the working checklist —
//   the `status:` field on each cluster is hand-maintained as clusters are
//   cleaned; everything else is regenerated). Also prints a console summary.
//
// Usage:
//   node scripts/i18n-audit.mjs                 # regenerate tracker + print summary
//   node scripts/i18n-audit.mjs --check         # exit 1 if any non-deferred cluster still has candidates (CI/report)
//
// The DEFERRED_CLUSTERS list (currently the internal super-admin panel) is
// excluded from --check so customer-facing progress can gate independently.

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { ESLint } from 'eslint';
import tseslint from 'typescript-eslint';
import noUntranslatedLiteral from '../apps/web/eslint-rules/no-untranslated-literal.js';

const repoRoot = path.join(path.dirname(fileURLToPath(import.meta.url)), '..');
const APPS = ['web', 'pos'];

// Clusters intentionally NOT translated yet (internal-only surfaces). Listed in
// the tracker as status: deferred and skipped by --check.
const DEFERRED_CLUSTERS = new Set(['web:admin']);

// ---------------------------------------------------------------------------
// PARITY
// ---------------------------------------------------------------------------

function flatten(obj, prefix = '', out = {}) {
  for (const [k, v] of Object.entries(obj)) {
    const key = prefix ? `${prefix}.${k}` : k;
    if (v && typeof v === 'object' && !Array.isArray(v)) flatten(v, key, out);
    else out[key] = v;
  }
  return out;
}

function loadJson(p) {
  try {
    return flatten(JSON.parse(fs.readFileSync(p, 'utf8')));
  } catch {
    return null;
  }
}

// FR values that are legitimately identical to EN (don't flag as untranslated).
function isLegitIdentical(value) {
  const s = String(value).trim();
  if (s.length <= 1) return true; // single char / symbol
  if (!/[A-Za-z]/.test(s)) return true; // numbers / punctuation / symbols only
  // Known loanwords / proper nouns / codes shared by EN and FR.
  const ALLOW = new Set([
    'Email', 'E-mail', 'SKU', 'EAN', 'UPC', 'ISBN', 'PIN', 'POS', 'TVA', 'IBAN',
    'BIC', 'SIRET', 'SIREN', 'RIB', 'URL', 'PDF', 'CSV', 'API', 'ID', 'UUID',
    'Stock', 'Standard', 'Total', 'Description', 'Notes', 'Note', 'Information',
    'Format', 'Type', 'Service', 'Options', 'Position', 'Configuration',
    'Documentation', 'Important', 'Date', 'Maximum', 'Minimum', 'Instructions',
    'OK', 'Status', 'Articles', 'Article', 'Question', 'Transactions', 'Transaction',
    'Distribution', 'Contact', 'Local', 'Application', 'Auto', 'Active',
  ]);
  if (ALLOW.has(s)) return true;
  // Brand / vertical names.
  if (/^(Otospex|IziPOS|Synerivia|Syneriva|AutoERP|TecDoc)$/i.test(s)) return true;
  return false;
}

function parityForApp(app) {
  const base = path.join(repoRoot, 'apps', app, 'src', 'locales');
  if (!fs.existsSync(path.join(base, 'en'))) return null;
  const namespaces = fs.readdirSync(path.join(base, 'en')).filter((f) => f.endsWith('.json'));
  const missing = {};
  const identical = {};
  const extra = {};
  let totals = { en_keys: 0, missing_fr: 0, identical_fr: 0, extra_fr: 0 };
  for (const f of namespaces) {
    const en = loadJson(path.join(base, 'en', f));
    const fr = loadJson(path.join(base, 'fr', f)) || {};
    if (!en) continue;
    const ns = f.replace(/\.json$/, '');
    const enKeys = Object.keys(en);
    totals.en_keys += enKeys.length;
    const miss = enKeys.filter((k) => !(k in fr));
    const ident = enKeys.filter(
      (k) => k in fr && typeof en[k] === 'string' && en[k] === fr[k] && !isLegitIdentical(en[k]),
    );
    const ex = Object.keys(fr).filter((k) => !(k in en));
    if (miss.length) missing[ns] = miss;
    if (ident.length) identical[ns] = ident;
    if (ex.length) extra[ns] = ex;
    totals.missing_fr += miss.length;
    totals.identical_fr += ident.length;
    totals.extra_fr += ex.length;
  }
  return { totals, missing, identical, extra };
}

// ---------------------------------------------------------------------------
// HARDCODED SCAN
// ---------------------------------------------------------------------------

// Map an absolute tsx path to a cluster id.
function clusterOf(file) {
  const rel = path.relative(repoRoot, file).replace(/\\/g, '/');
  if (rel.startsWith('apps/pos/')) return 'pos';
  const m = rel.match(/apps\/web\/src\/features\/([^/]+)\//);
  if (m) return `web:${m[1]}`;
  if (/apps\/web\/src\/components\//.test(rel)) return 'web:components';
  if (/apps\/web\/src\/pages\//.test(rel)) return 'web:pages';
  if (/apps\/web\/src\/layouts\//.test(rel)) return 'web:layouts';
  return 'web:other';
}

// Authoritative hardcoded-literal scan: drives the SAME ESLint rule that
// enforces regressions (local/no-untranslated-literal), via an isolated
// single-rule flat config (parser only, no type-checking → fast). This keeps
// the tracker's counts identical to what CI gates on, and inherits the rule's
// test-file exclusion and heuristics.
async function scanHardcoded() {
  const clusters = new Map(); // id -> { files: Map<rel,count>, total }
  for (const app of APPS) {
    const appDir = path.join(repoRoot, 'apps', app);
    if (!fs.existsSync(path.join(appDir, 'src'))) continue;
    const eslint = new ESLint({
      cwd: appDir,
      overrideConfigFile: true,
      overrideConfig: {
        files: ['**/*.tsx'],
        languageOptions: {
          parser: tseslint.parser,
          parserOptions: { ecmaFeatures: { jsx: true }, sourceType: 'module' },
        },
        plugins: { local: { rules: { 'no-untranslated-literal': noUntranslatedLiteral } } },
        rules: { 'local/no-untranslated-literal': 'warn' },
      },
    });
    const results = await eslint.lintFiles([path.join(appDir, 'src/**/*.tsx')]);
    for (const r of results) {
      const count = r.messages.filter((m) => m.ruleId === 'local/no-untranslated-literal').length;
      if (count === 0) continue;
      const id = clusterOf(r.filePath);
      if (!clusters.has(id)) clusters.set(id, { files: new Map(), total: 0 });
      const c = clusters.get(id);
      const rel = path.relative(repoRoot, r.filePath).replace(/\\/g, '/');
      c.files.set(rel, count);
      c.total += count;
    }
  }
  return clusters;
}

// ---------------------------------------------------------------------------
// YAML emit (minimal hand-rolled writer — values here are simple)
// ---------------------------------------------------------------------------

function yamlList(items, indent) {
  return items.map((i) => `${' '.repeat(indent)}- ${i}`).join('\n');
}

function buildYaml(parity, clusters, existingStatus) {
  const lines = [];
  lines.push('# i18n EN/FR sweep tracker — generated by scripts/i18n-audit.mjs');
  lines.push('# Regenerate: node scripts/i18n-audit.mjs');
  lines.push('# Only the `status:` field per cluster is hand-maintained; rerun to refresh counts.');
  lines.push('');
  lines.push('summary:');
  for (const app of APPS) {
    const p = parity[app];
    if (!p) continue;
    const totalHardcoded = [...clusters.entries()]
      .filter(([id]) => (app === 'pos' ? id === 'pos' : id.startsWith('web:')))
      .reduce((s, [, c]) => s + c.total, 0);
    lines.push(`  ${app}:`);
    lines.push(`    en_keys: ${p.totals.en_keys}`);
    lines.push(`    missing_fr: ${p.totals.missing_fr}`);
    lines.push(`    identical_fr: ${p.totals.identical_fr}`);
    lines.push(`    extra_fr: ${p.totals.extra_fr}`);
    lines.push(`    hardcoded_candidates: ${totalHardcoded}`);
  }
  lines.push('');

  // Clusters, sorted: active first by descending count, deferred last.
  const sorted = [...clusters.entries()].sort((a, b) => {
    const ad = DEFERRED_CLUSTERS.has(a[0]) ? 1 : 0;
    const bd = DEFERRED_CLUSTERS.has(b[0]) ? 1 : 0;
    if (ad !== bd) return ad - bd;
    return b[1].total - a[1].total;
  });
  lines.push('clusters:');
  for (const [id, c] of sorted) {
    const deferred = DEFERRED_CLUSTERS.has(id);
    const status = deferred ? 'deferred' : (existingStatus[id] ?? 'pending');
    lines.push(`  - id: ${id}`);
    lines.push(`    status: ${status}`);
    lines.push(`    candidates: ${c.total}`);
    lines.push(`    files:`);
    const files = [...c.files.entries()].sort((a, b) => b[1] - a[1]);
    for (const [rel, n] of files) {
      lines.push(`      - { count: ${n}, path: ${rel} }`);
    }
  }
  lines.push('');

  // Parity detail.
  lines.push('parity:');
  for (const app of APPS) {
    const p = parity[app];
    if (!p) continue;
    lines.push(`  ${app}:`);
    for (const [label, data] of [['missing_fr', p.missing], ['identical_fr', p.identical], ['extra_fr', p.extra]]) {
      lines.push(`    ${label}:`);
      const nss = Object.keys(data).sort();
      if (nss.length === 0) lines.push('      {}');
      for (const ns of nss) {
        lines.push(`      ${ns}:`);
        lines.push(yamlList(data[ns], 8));
      }
    }
  }
  lines.push('');
  return lines.join('\n');
}

// Preserve hand-set `status:` values across regenerations.
function readExistingStatus(trackerPath) {
  const status = {};
  if (!fs.existsSync(trackerPath)) return status;
  const txt = fs.readFileSync(trackerPath, 'utf8');
  const re = /-\s+id:\s*(\S+)\s*\n\s+status:\s*(\S+)/g;
  let m;
  while ((m = re.exec(txt))) status[m[1]] = m[2];
  return status;
}

// ---------------------------------------------------------------------------
// MAIN
// ---------------------------------------------------------------------------

const parity = {};
for (const app of APPS) parity[app] = parityForApp(app);
const clusters = await scanHardcoded();

const trackerPath = path.join(repoRoot, 'docs', 'i18n', 'i18n-tracker.yaml');
const existingStatus = readExistingStatus(trackerPath);
const yaml = buildYaml(parity, clusters, existingStatus);
fs.mkdirSync(path.dirname(trackerPath), { recursive: true });
fs.writeFileSync(trackerPath, yaml);

// Console summary.
console.log('=== i18n audit ===\n');
for (const app of APPS) {
  const p = parity[app];
  if (!p) continue;
  console.log(
    `${app.toUpperCase().padEnd(4)} parity: ${p.totals.en_keys} EN keys | missingFR=${p.totals.missing_fr} | untranslated?=${p.totals.identical_fr} | extraFR=${p.totals.extra_fr}`,
  );
}
console.log('\nHardcoded-string clusters (candidates, excluding tests/locales):');
const sorted = [...clusters.entries()].sort((a, b) => {
  const ad = DEFERRED_CLUSTERS.has(a[0]) ? 1 : 0;
  const bd = DEFERRED_CLUSTERS.has(b[0]) ? 1 : 0;
  if (ad !== bd) return ad - bd;
  return b[1].total - a[1].total;
});
let active = 0;
for (const [id, c] of sorted) {
  const tag = DEFERRED_CLUSTERS.has(id) ? ' [deferred]' : (existingStatus[id] ? ` [${existingStatus[id]}]` : '');
  if (!DEFERRED_CLUSTERS.has(id) && (existingStatus[id] ?? 'pending') !== 'done') active += c.total;
  console.log(`  ${String(c.total).padStart(4)}  ${id}${tag}`);
}
console.log(`\nActive (non-deferred, not-done) candidates remaining: ${active}`);
console.log(`Tracker written to docs/i18n/i18n-tracker.yaml`);

if (process.argv.includes('--check')) {
  if (active > 0) {
    console.log('\n--check: FAIL — active clusters still have hardcoded candidates.');
    process.exit(1);
  }
  console.log('\n--check: PASS');
}
