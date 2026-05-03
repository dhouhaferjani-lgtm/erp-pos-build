#!/usr/bin/env node
// @ts-check
/**
 * Architecture Gate C: TanStack Query keys must include a tenant scope.
 *
 * Code is the source of truth. This scanner walks every TS/TSX file under
 * apps/web/src/, parses it with the TypeScript compiler API, and flags any
 * `useQuery({ ... })` / `useQueries({ queries: [{ ... }] })` /
 * queryClient.invalidateQueries({ ... }) (and friends) call whose
 * `queryKey` does not include an approved tenant scope.
 *
 * Default-deny per Codex round-1 review T2 + N5: any unknown queryKey
 * factory triggers a flag. Approved scopes:
 *   - `tenantScopedKey([...])` helper call.
 *   - Bare identifier `tenantId`, `currentCompanyId`, or `companyId`.
 *   - Member access `companyStore.<currentCompanyId|companyId|tenantId>`
 *     (Codex 2026-05-03 review C4: tightened from any property access ending
 *     in those names).
 *   - Array starting with the literal string `'admin'` or `'super-admin'`
 *     for the super-admin namespace.
 *
 * Codex 2026-05-03 review C4 fixes vs the prior version:
 *   - useQueries({ queries: [...] }) now descends into the queries array
 *     and audits each element's queryKey individually.
 *   - companyStore.<x> member access requires the explicit `companyStore`
 *     LHS; bare property access ending in `companyId` etc. no longer
 *     over-approves.
 *
 * Run via: pnpm test:arch
 */

import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import ts from 'typescript';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const WEB_ROOT = path.resolve(__dirname, '..');
const SRC_ROOT = path.join(WEB_ROOT, 'src');

const QUERY_FACTORY_NAMES = new Set([
  'useQuery',
  'useMutation',
  'useInfiniteQuery',
  'useSuspenseQuery',
  'invalidateQueries',
  'removeQueries',
  'resetQueries',
  'refetchQueries',
  'cancelQueries',
  'fetchQuery',
  'prefetchQuery',
]);

const APPROVED_SCOPE_IDENTIFIERS = new Set([
  'tenantId',
  'currentCompanyId',
  'companyId',
]);

const APPROVED_NAMESPACE_PREFIXES = new Set([
  'admin',
  'super-admin',
]);

const APPROVED_STORE_OBJECTS = new Set([
  'companyStore',
]);

const APPROVED_FACTORY_CALLS = new Set([
  'tenantScopedKey',
]);

/**
 * @param {ts.Node} expr
 * @returns {boolean}
 */
function isApprovedScopeExpression(expr) {
  if (ts.isCallExpression(expr) && ts.isIdentifier(expr.expression)) {
    if (APPROVED_FACTORY_CALLS.has(expr.expression.text)) return true;
  }
  if (ts.isIdentifier(expr) && APPROVED_SCOPE_IDENTIFIERS.has(expr.text)) return true;
  if (ts.isPropertyAccessExpression(expr)) {
    // Codex C4: require explicit approved store as the LHS, e.g.
    // `companyStore.currentCompanyId`. Bare `props.companyId`, `payload.tenantId`,
    // etc. no longer auto-approve.
    if (
      ts.isIdentifier(expr.expression) &&
      APPROVED_STORE_OBJECTS.has(expr.expression.text) &&
      APPROVED_SCOPE_IDENTIFIERS.has(expr.name.text)
    ) {
      return true;
    }
  }
  return false;
}

/**
 * @param {ts.ArrayLiteralExpression} arr
 * @returns {boolean}
 */
function arrayHasApprovedScope(arr) {
  if (arr.elements.length === 0) return false;

  const first = arr.elements[0];
  if (first && ts.isStringLiteralLike(first) && APPROVED_NAMESPACE_PREFIXES.has(first.text)) {
    return true;
  }
  for (const element of arr.elements) {
    if (isApprovedScopeExpression(element)) return true;
  }
  return false;
}

/**
 * @param {ts.Expression} expr
 * @returns {boolean}
 */
function queryKeyExpressionIsApproved(expr) {
  if (ts.isCallExpression(expr) && ts.isIdentifier(expr.expression)) {
    if (APPROVED_FACTORY_CALLS.has(expr.expression.text)) return true;
  }
  if (ts.isArrayLiteralExpression(expr)) return arrayHasApprovedScope(expr);
  if (ts.isAsExpression(expr) || ts.isTypeAssertionExpression(expr)) {
    return queryKeyExpressionIsApproved(expr.expression);
  }
  if (ts.isSatisfiesExpression(expr)) {
    return queryKeyExpressionIsApproved(expr.expression);
  }
  return false;
}

/**
 * @param {ts.ObjectLiteralExpression} options
 * @param {string} factoryName
 * @param {ts.SourceFile} sourceFile
 * @param {string} relPath
 * @param {Array<{file: string, line: number, column: number, reason: string}>} out
 */
function checkOptionsObject(options, factoryName, sourceFile, relPath, out) {
  const queryKeyProp = options.properties.find(
    (p) =>
      (ts.isPropertyAssignment(p) || ts.isShorthandPropertyAssignment(p)) &&
      p.name &&
      ts.isIdentifier(p.name) &&
      p.name.text === 'queryKey',
  );
  if (queryKeyProp && ts.isPropertyAssignment(queryKeyProp)) {
    const initializer = queryKeyProp.initializer;
    if (!queryKeyExpressionIsApproved(initializer)) {
      const { line, character } = sourceFile.getLineAndCharacterOfPosition(
        queryKeyProp.getStart(sourceFile),
      );
      out.push({
        file: relPath,
        line: line + 1,
        column: character + 1,
        reason: `${factoryName}({ queryKey: ... }) lacks an approved tenant scope`,
      });
    }
  }
}

/**
 * Codex C4: `useQueries({ queries: [ { queryKey: ... }, ... ] })` stores its
 * options under a `queries` array, not a top-level queryKey. Descend into
 * that array and audit each entry independently.
 *
 * @param {ts.ObjectLiteralExpression} options
 * @param {ts.SourceFile} sourceFile
 * @param {string} relPath
 * @param {Array<{file: string, line: number, column: number, reason: string}>} out
 */
function checkUseQueriesOptions(options, sourceFile, relPath, out) {
  const queriesProp = options.properties.find(
    (p) =>
      ts.isPropertyAssignment(p) &&
      p.name &&
      ts.isIdentifier(p.name) &&
      p.name.text === 'queries',
  );
  if (!queriesProp || !ts.isPropertyAssignment(queriesProp)) return;
  const arr = queriesProp.initializer;
  if (!ts.isArrayLiteralExpression(arr)) return;
  for (const element of arr.elements) {
    if (ts.isObjectLiteralExpression(element)) {
      checkOptionsObject(element, 'useQueries.queries[]', sourceFile, relPath, out);
    }
  }
}

/**
 * @param {ts.SourceFile} sourceFile
 * @param {string} filePath
 * @returns {Array<{file: string, line: number, column: number, reason: string}>}
 */
export function scanSource(sourceFile, filePath) {
  /** @type {Array<{file: string, line: number, column: number, reason: string}>} */
  const violations = [];
  const relPath = path.relative(WEB_ROOT, filePath);

  /**
   * @param {ts.Node} node
   */
  function inner(node) {
    if (ts.isCallExpression(node)) {
      const callee = node.expression;
      let name = null;
      if (ts.isIdentifier(callee)) name = callee.text;
      else if (ts.isPropertyAccessExpression(callee)) name = callee.name.text;

      const arg = node.arguments[0];
      if (name === 'useQueries' && arg && ts.isObjectLiteralExpression(arg)) {
        checkUseQueriesOptions(arg, sourceFile, relPath, violations);
      } else if (name && QUERY_FACTORY_NAMES.has(name) && arg && ts.isObjectLiteralExpression(arg)) {
        checkOptionsObject(arg, name, sourceFile, relPath, violations);
      }
    }
    ts.forEachChild(node, inner);
  }

  inner(sourceFile);
  return violations;
}

/**
 * Convenience wrapper for the unit test: parses a snippet of TS source and
 * returns the violations (with `filename` defaulting to '<inline>').
 *
 * @param {string} code
 * @param {string} [filename]
 * @returns {Array<{file: string, line: number, column: number, reason: string}>}
 */
export function scanCode(code, filename = '<inline>') {
  const sourceFile = ts.createSourceFile(
    filename,
    code,
    ts.ScriptTarget.ES2022,
    true,
    /\.tsx$/.test(filename) ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
  );
  return scanSource(sourceFile, filename);
}

/**
 * @param {string} dir
 * @returns {Promise<string[]>}
 */
async function walk(dir) {
  /** @type {string[]} */
  const out = [];
  const entries = await fs.readdir(dir, { withFileTypes: true });
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
  const fileList = await walk(SRC_ROOT);
  /** @type {Array<{file: string, line: number, column: number, reason: string}>} */
  const allViolations = [];
  for (const file of fileList) {
    const code = await fs.readFile(file, 'utf8');
    const sourceFile = ts.createSourceFile(
      file,
      code,
      ts.ScriptTarget.ES2022,
      true,
      /\.tsx$/.test(file) ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
    );
    allViolations.push(...scanSource(sourceFile, file));
  }
  process.stderr.write(
    `[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: ${allViolations.length}\n`,
  );
  // Master plan Section 17 step 17.5: swap the exit code below from 0
  // (informational) to (allViolations.length === 0 ? 0 : 1) once the
  // tactical sweep is complete.
  process.exit(0);
}
