#!/usr/bin/env node
// Lint-warning ratchet — M4.2, dev deferred backlog 2026-05-14.
//
// The web/pos lint backlogs are large (11k+ design-token warnings on web)
// and the policy is to burn them down incrementally, not in one pass. A raw
// `eslint .` gate is therefore useless — it is either always-red (fail on any
// warning) or always-green (ignore warnings). This script makes lint a
// RATCHET:
//
//   - It runs `pnpm --filter <pkg> lint` for each workspace app.
//   - It parses ESLint's summary line for the error / warning counts.
//   - ANY error fails immediately (errors are never ratcheted).
//   - The warning count is ratcheted against scripts/lint-warning-baseline.json:
//     it may shrink (good) or hold, but never grow.
//   - When the count drops it prints the --update-baseline command so the
//     gain is locked in.
//
// Usage:
//   node scripts/lint-ratchet.mjs
//   node scripts/lint-ratchet.mjs --update-baseline
//   pnpm lint:ratchet            (wired in root package.json)

import { execSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const repoRoot = join(dirname(fileURLToPath(import.meta.url)), '..');
const baselinePath = join(repoRoot, 'scripts', 'lint-warning-baseline.json');
const updateBaseline = process.argv.includes('--update-baseline');

// Workspace apps to ratchet. Add new lint-able apps here.
const apps = ['@autoerp/web', '@autoerp/pos'];

/**
 * Run `pnpm --filter <app> lint` and return { errors, warnings }.
 * ESLint exits 0 when only warnings are present and 1 when errors are
 * present; either way it prints a summary line we can parse. A crash
 * (exit 2, no parseable summary) is surfaced as a hard failure.
 */
function runLint(app) {
  let output = '';
  try {
    output = execSync(`pnpm --filter ${app} lint`, {
      cwd: repoRoot,
      encoding: 'utf8',
      stdio: ['ignore', 'pipe', 'pipe'],
      // ESLint's default formatter prints one line per problem; the web app
      // has 11k+ warnings (~2 MB). Give it generous headroom so the summary
      // line at the end is never truncated — a truncated buffer would make
      // the script silently under-count.
      maxBuffer: 64 * 1024 * 1024,
    });
  } catch (error) {
    if (error.code === 'ENOBUFS') {
      console.error(`lint-ratchet: ESLint output for ${app} exceeded maxBuffer — raise it.`);
      process.exit(1);
    }
    // Non-zero exit — expected when ESLint reports errors. Capture its output.
    output = `${error.stdout ?? ''}${error.stderr ?? ''}`;
  }

  const summary = output.match(/(\d+)\s+errors?,\s+(\d+)\s+warnings?/);
  if (summary) {
    return { errors: Number(summary[1]), warnings: Number(summary[2]) };
  }

  // No "N problems" summary line at all means a clean run (ESLint prints
  // nothing) OR a crash. Distinguish by looking for ESLint's crash banner.
  if (/Oops! Something went wrong|Cannot find module|TypeError:/.test(output)) {
    console.error(`lint-ratchet: ESLint failed to run for ${app}:\n${output}`);
    process.exit(1);
  }
  return { errors: 0, warnings: 0 };
}

const current = {};
for (const app of apps) {
  current[app] = runLint(app);
}

if (updateBaseline) {
  const payload = {
    generated_at: new Date().toISOString().slice(0, 10),
    note: 'Regenerate with: node scripts/lint-ratchet.mjs --update-baseline',
    apps: current,
  };
  writeFileSync(baselinePath, `${JSON.stringify(payload, null, 2)}\n`);
  console.log(`Baseline written to scripts/lint-warning-baseline.json`);
  for (const app of apps) {
    console.log(`  ${app}: ${current[app].warnings} warnings, ${current[app].errors} errors`);
  }
  process.exit(0);
}

let baseline;
try {
  baseline = JSON.parse(readFileSync(baselinePath, 'utf8'));
} catch {
  console.error('lint-ratchet: baseline not found. Generate it once with --update-baseline.');
  process.exit(1);
}

console.log('=== Lint-warning ratchet ===\n');
let failed = false;
let improved = false;

for (const app of apps) {
  const cur = current[app];
  const base = baseline.apps?.[app] ?? { errors: 0, warnings: 0 };
  let status;

  if (cur.errors > 0) {
    status = `FAIL — ${cur.errors} error(s) (errors are never ratcheted)`;
    failed = true;
  } else if (cur.warnings > base.warnings) {
    status = `FAIL — warnings rose ${base.warnings} → ${cur.warnings} (+${cur.warnings - base.warnings})`;
    failed = true;
  } else if (cur.warnings < base.warnings) {
    status = `improved — warnings fell ${base.warnings} → ${cur.warnings} (-${base.warnings - cur.warnings})`;
    improved = true;
  } else {
    status = `held — ${cur.warnings} warnings`;
  }

  console.log(`  ${app.padEnd(16)} baseline=${String(base.warnings).padStart(6)}  current=${String(cur.warnings).padStart(6)}  ${status}`);
}

console.log('');

if (improved && !failed) {
  console.log('Improvements detected. Lock them in:');
  console.log('  node scripts/lint-ratchet.mjs --update-baseline\n');
}

if (failed) {
  console.log('RESULT: FAIL — fix new errors / warnings, or justify a baseline bump.');
  process.exit(1);
}

console.log('RESULT: PASS — no lint-warning regression against baseline.');
process.exit(0);
