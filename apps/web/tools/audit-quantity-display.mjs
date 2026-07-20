#!/usr/bin/env node
// @ts-check
/**
 * Guard 4 (UoM display precision): human-facing product quantities must render
 * through a canonical unit-precision formatter, never as raw scale-4 strings.
 *
 * Code is the source of truth. This scanner walks every TS/TSX file under BOTH
 * apps/web/src/ and apps/pos/src/ (single source of truth for the two apps),
 * parses each with the TypeScript compiler API (parse scaffolding mirrored from
 * tools/audit-tanstack-keys.mjs), and flags any JSX expression that renders a
 * raw product-quantity identifier/member without wrapping it in the canonical
 * `formatQuantity` helper.
 *
 * Detection contract (spec 2026-07-20 §3.4.4):
 *   INCLUDE terminal names — exact: requested_qty, suggested_qty, received_qty
 *     (flagged as bare identifier OR member); `quantity` — member-expression
 *     ONLY (bare `quantity` state vars are excluded).
 *   EXCLUDE terminal names — /^(total_|available_|reserved_|stock_|min_|max_|
 *     component_|required_).*|.*_count$/ (never flagged).
 *   EXEMPT — the flagged expression is (a) an argument of a call whose callee
 *     resolves to an import of `formatQuantity` from `lib/decimal` (web) /
 *     `lib/quantity` (pos), or (b) a `value` attribute on <QuantityInput>.
 *
 * The callee -> import-declaration -> module-specifier resolution is NET-NEW
 * logic here (audit-tanstack-keys.mjs does no import resolution).
 *
 * Baseline (tools/quantity-display-baseline.json) is a sorted array of
 * "file:identifier" entries (line-number-free so edits don't churn it).
 * Shrink-only ratchet: exit 1 on any NEW entry, exit 1 on any STALE entry
 * (baseline entry that no longer matches a current violation).
 *
 * Run via: pnpm audit:quantity (also chained from lint/preflight/CI).
 */

import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import ts from 'typescript';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const WEB_ROOT = path.resolve(__dirname, '..');
const REPO_ROOT = path.resolve(WEB_ROOT, '..', '..');
const SCAN_ROOTS = [
  path.join(REPO_ROOT, 'apps', 'web', 'src'),
  path.join(REPO_ROOT, 'apps', 'pos', 'src'),
];
const BASELINE_PATH = path.join(__dirname, 'quantity-display-baseline.json');

/** Terminal names flagged as bare identifier OR member expression. */
const INCLUDE_EXACT = new Set(['requested_qty', 'suggested_qty', 'received_qty']);
/** Terminal name flagged ONLY as a member expression (line/item chain). */
const INCLUDE_MEMBER_ONLY = new Set(['quantity']);

const EXCLUDE_REGEX = /^(total_|available_|reserved_|stock_|min_|max_|component_|required_).*|.*_count$/;

/**
 * Canonical module specifier per app: a call to `formatQuantity` imported from
 * this module is the approved unit-precision wrapper and exempts its arguments.
 *
 * @param {string} relPath repo-root-relative path (e.g. apps/pos/src/x.tsx)
 * @returns {RegExp}
 */
function canonicalModuleRegexFor(relPath) {
  return relPath.replaceAll('\\', '/').startsWith('apps/pos/')
    ? /(^|\/)lib\/quantity$/
    : /(^|\/)lib\/decimal$/;
}

/**
 * Strip parentheses / `as` / `<T>` / `satisfies` wrappers without changing an
 * expression's semantic shape (mirrors the tanstack-keys unwrap helper).
 *
 * @param {ts.Expression} expr
 * @returns {ts.Expression}
 */
function unwrapExpression(expr) {
  let inner = expr;
  while (
    ts.isParenthesizedExpression(inner) ||
    ts.isAsExpression(inner) ||
    ts.isTypeAssertionExpression(inner) ||
    ts.isSatisfiesExpression(inner)
  ) {
    inner = inner.expression;
  }
  return inner;
}

/**
 * Build a map of local import name -> { importedName, module } for every named
 * import in the file. Handles aliases: `import { formatQuantity as fq } from ...`
 * yields fq -> { importedName: 'formatQuantity', module: '...' }.
 *
 * @param {ts.SourceFile} sourceFile
 * @returns {Map<string, {importedName: string, module: string}>}
 */
function collectImports(sourceFile) {
  /** @type {Map<string, {importedName: string, module: string}>} */
  const map = new Map();
  for (const stmt of sourceFile.statements) {
    if (!ts.isImportDeclaration(stmt)) continue;
    if (!ts.isStringLiteral(stmt.moduleSpecifier)) continue;
    const module = stmt.moduleSpecifier.text;
    const bindings = stmt.importClause?.namedBindings;
    if (bindings && ts.isNamedImports(bindings)) {
      for (const element of bindings.elements) {
        const local = element.name.text;
        const imported = (element.propertyName ?? element.name).text;
        map.set(local, { importedName: imported, module });
      }
    }
  }
  return map;
}

/**
 * True when `expr` is a call whose callee resolves to the canonical
 * `formatQuantity` import for this app.
 *
 * @param {ts.Expression} expr
 * @param {Map<string, {importedName: string, module: string}>} imports
 * @param {RegExp} canonicalModule
 * @returns {boolean}
 */
function isCanonicalFormatQuantityCall(expr, imports, canonicalModule) {
  if (!ts.isCallExpression(expr)) return false;
  const callee = expr.expression;
  if (!ts.isIdentifier(callee)) return false;
  const resolved = imports.get(callee.text);
  if (!resolved) return false;
  return resolved.importedName === 'formatQuantity' && canonicalModule.test(resolved.module);
}

/**
 * Terminal identifier name of an identifier / property-access expression, plus
 * whether the node is a member expression.
 *
 * @param {ts.Expression} expr
 * @returns {{terminal: string, isMember: boolean} | null}
 */
function terminalName(expr) {
  if (ts.isIdentifier(expr)) return { terminal: expr.text, isMember: false };
  if (ts.isPropertyAccessExpression(expr)) return { terminal: expr.name.text, isMember: true };
  return null;
}

/**
 * @param {{terminal: string, isMember: boolean}} info
 * @returns {boolean}
 */
function terminalIsFlaggable(info) {
  if (EXCLUDE_REGEX.test(info.terminal)) return false;
  if (INCLUDE_EXACT.has(info.terminal)) return true;
  if (INCLUDE_MEMBER_ONLY.has(info.terminal) && info.isMember) return true;
  return false;
}

/**
 * Collect the "output" (rendered-value) leaf expressions of a JSX expression's
 * value. Conditionals recurse into their branches (not the condition); `&&`
 * recurses right; `||`/`??` recurse both sides; every other expression is a
 * leaf. Comparison/arithmetic binary expressions are leaves (they produce
 * booleans/derived values, never a raw rendered quantity) so their operands —
 * e.g. `line.requested_qty !== null` guards — are not flagged.
 *
 * @param {ts.Expression} expr
 * @param {ts.Expression[]} out
 */
function collectOutputs(expr, out) {
  const e = unwrapExpression(expr);
  if (ts.isConditionalExpression(e)) {
    collectOutputs(e.whenTrue, out);
    collectOutputs(e.whenFalse, out);
    return;
  }
  if (ts.isBinaryExpression(e)) {
    const op = e.operatorToken.kind;
    if (op === ts.SyntaxKind.AmpersandAmpersandToken) {
      collectOutputs(e.right, out);
      return;
    }
    if (op === ts.SyntaxKind.BarBarToken || op === ts.SyntaxKind.QuestionQuestionToken) {
      collectOutputs(e.left, out);
      collectOutputs(e.right, out);
      return;
    }
    out.push(e);
    return;
  }
  out.push(e);
}

/**
 * Inspect one rendered-value leaf and push any violation it produces.
 *
 * @param {ts.Expression} leaf
 * @param {ts.SourceFile} sourceFile
 * @param {string} relPath
 * @param {Map<string, {importedName: string, module: string}>} imports
 * @param {RegExp} canonicalModule
 * @param {Array<{file: string, line: number, identifier: string}>} out
 */
function inspectLeaf(leaf, sourceFile, relPath, imports, canonicalModule, out) {
  const e = unwrapExpression(leaf);

  if (ts.isCallExpression(e)) {
    // Canonical formatQuantity(...) wrap: fully exempt (its args are safe).
    if (isCanonicalFormatQuantityCall(e, imports, canonicalModule)) return;
    // Any other call: its arguments are still rendered raw — inspect them.
    for (const arg of e.arguments) {
      const nested = [];
      collectOutputs(arg, nested);
      for (const n of nested) inspectLeaf(n, sourceFile, relPath, imports, canonicalModule, out);
    }
    return;
  }

  const info = terminalName(e);
  if (!info || !terminalIsFlaggable(info)) return;
  const { line } = sourceFile.getLineAndCharacterOfPosition(e.getStart(sourceFile));
  out.push({
    file: relPath,
    line: line + 1,
    identifier: e.getText(sourceFile).replace(/\s+/g, ' ').trim(),
  });
}

/**
 * Resolve the JSX tag name for a JsxAttribute (its enclosing opening element).
 *
 * @param {ts.JsxAttribute} attr
 * @returns {string | null}
 */
function jsxAttributeTagName(attr) {
  const attributes = attr.parent; // JsxAttributes
  const opening = attributes.parent; // JsxOpeningElement | JsxSelfClosingElement
  if (ts.isJsxOpeningElement(opening) || ts.isJsxSelfClosingElement(opening)) {
    const tag = opening.tagName;
    if (ts.isIdentifier(tag)) return tag.text;
    if (ts.isPropertyAccessExpression(tag)) return tag.name.text;
  }
  return null;
}

/**
 * @param {ts.SourceFile} sourceFile
 * @param {string} relPath repo-root-relative path
 * @returns {Array<{file: string, line: number, identifier: string}>}
 */
export function scanSource(sourceFile, relPath) {
  /** @type {Array<{file: string, line: number, identifier: string}>} */
  const violations = [];
  const imports = collectImports(sourceFile);
  const canonicalModule = canonicalModuleRegexFor(relPath);

  /**
   * @param {ts.Node} node
   */
  function visit(node) {
    if (ts.isJsxExpression(node) && node.expression) {
      const parent = node.parent;
      let scan = false;
      if (ts.isJsxElement(parent) || ts.isJsxFragment(parent)) {
        // Rendered child expression.
        scan = true;
      } else if (ts.isJsxAttribute(parent) && parent.name.getText(sourceFile) === 'value') {
        // A `value` attribute — scanned, unless it is <QuantityInput value=...>.
        scan = jsxAttributeTagName(parent) !== 'QuantityInput';
      }
      if (scan) {
        const leaves = [];
        collectOutputs(node.expression, leaves);
        for (const leaf of leaves) {
          inspectLeaf(leaf, sourceFile, relPath, imports, canonicalModule, violations);
        }
      }
    }
    ts.forEachChild(node, visit);
  }

  visit(sourceFile);
  return violations;
}

/**
 * Parse a source snippet and return its violations. `filename` decides the app
 * (apps/pos/... => canonical lib/quantity; anything else => lib/decimal).
 *
 * @param {string} code
 * @param {string} [filename]
 * @returns {Array<{file: string, line: number, identifier: string}>}
 */
export function scanCode(code, filename = 'apps/web/src/inline.tsx') {
  const sourceFile = ts.createSourceFile(
    filename,
    code,
    ts.ScriptTarget.ES2022,
    true,
    /\.tsx$/.test(filename) ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
  );
  return scanSource(sourceFile, filename.replaceAll('\\', '/'));
}

/**
 * Baseline key for a violation: "file:identifier" (line-number-free).
 *
 * @param {{file: string, identifier: string}} violation
 * @returns {string}
 */
export function violationBaselineKey(violation) {
  return `${violation.file}:${violation.identifier}`;
}

/**
 * @param {Array<{file: string, line: number, identifier: string}>} violations
 * @param {Set<string>} baseline
 * @returns {{baselined: typeof violations, newViolations: typeof violations, staleBaselineEntries: string[]}}
 */
export function partitionViolationsByBaseline(violations, baseline) {
  const seen = new Set();
  const baselined = [];
  const newViolations = [];

  for (const violation of violations) {
    const key = violationBaselineKey(violation);
    if (baseline.has(key)) {
      seen.add(key);
      baselined.push(violation);
    } else {
      newViolations.push(violation);
    }
  }

  const staleBaselineEntries = [...baseline].filter((entry) => !seen.has(entry));
  return { baselined, newViolations, staleBaselineEntries };
}

/**
 * @param {string} dir
 * @returns {Promise<string[]>}
 */
async function walk(dir) {
  /** @type {string[]} */
  const out = [];
  let entries;
  try {
    entries = await fs.readdir(dir, { withFileTypes: true });
  } catch {
    return out;
  }
  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name === 'node_modules' || entry.name === '__tests__' || entry.name === '__mocks__') {
        continue;
      }
      out.push(...(await walk(full)));
      continue;
    }
    if (!entry.isFile()) continue;
    if (!/\.tsx?$/.test(entry.name)) continue;
    if (/\.(test|spec)\.tsx?$/.test(entry.name)) continue;
    out.push(full);
  }
  return out;
}

/**
 * @returns {Promise<Set<string>>}
 */
async function loadBaseline() {
  try {
    const raw = await fs.readFile(BASELINE_PATH, 'utf8');
    const parsed = JSON.parse(raw);
    if (Array.isArray(parsed)) return new Set(parsed);
    return new Set();
  } catch {
    return new Set();
  }
}

// ---------------------------------------------------------------------------
// CLI entrypoint — only runs when invoked directly (not when imported).
// ---------------------------------------------------------------------------

const isMain = (() => {
  if (typeof process === 'undefined' || !Array.isArray(process.argv) || process.argv.length < 2) {
    return false;
  }
  const invokedPath = path.resolve(process.argv[1] ?? '');
  const thisPath = fileURLToPath(import.meta.url);
  return invokedPath === thisPath;
})();

if (isMain) {
  const wantsWriteBaseline = process.argv.includes('--write-baseline');
  /** @type {Array<{file: string, line: number, identifier: string}>} */
  const allViolations = [];
  for (const root of SCAN_ROOTS) {
    const fileList = await walk(root);
    for (const file of fileList) {
      const code = await fs.readFile(file, 'utf8');
      const sourceFile = ts.createSourceFile(
        file,
        code,
        ts.ScriptTarget.ES2022,
        true,
        /\.tsx$/.test(file) ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
      );
      const relPath = path.relative(REPO_ROOT, file).replaceAll('\\', '/');
      allViolations.push(...scanSource(sourceFile, relPath));
    }
  }

  if (wantsWriteBaseline) {
    const keys = [...new Set(allViolations.map(violationBaselineKey))].sort();
    await fs.writeFile(BASELINE_PATH, JSON.stringify(keys, null, 2) + '\n', 'utf8');
    process.stderr.write(`[audit-quantity] wrote ${keys.length} baseline entries to ${path.relative(REPO_ROOT, BASELINE_PATH)}\n`);
    process.exit(0);
  }

  const baseline = await loadBaseline();
  const { baselined, newViolations, staleBaselineEntries } = partitionViolationsByBaseline(allViolations, baseline);
  process.stderr.write(
    `[audit-quantity] raw quantity display sites: ${allViolations.length} total ` +
      `(${baselined.length} baselined, ${newViolations.length} new, ${staleBaselineEntries.length} stale baseline entries)\n`,
  );

  if (newViolations.length > 0) {
    process.stderr.write('\nNew raw quantity-display violations (wrap in canonical formatQuantity or add to baseline):\n');
    for (const violation of newViolations) {
      process.stderr.write(`  ${violation.file}:${violation.line} ${violation.identifier}\n`);
    }
  }

  if (staleBaselineEntries.length > 0) {
    process.stderr.write('\nStale quantity-display baseline entries (shrink-only ratchet — remove them):\n');
    for (const entry of staleBaselineEntries) {
      process.stderr.write(`  ${entry}\n`);
    }
  }

  if (newViolations.length > 0 || staleBaselineEntries.length > 0) {
    process.exit(1);
  }

  process.exit(0);
}
