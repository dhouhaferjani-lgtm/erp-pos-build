#!/usr/bin/env node
// @ts-check
/**
 * Architecture Gate C: TanStack Query keys must include a tenant scope.
 *
 * Code is the source of truth. This scanner walks every TS/TSX file under
 * apps/web/src/, parses it with the TypeScript compiler API, and flags any
 * `useQuery({ ... })` / `useMutation({ ... })` / `queryClient.invalidateQueries({ ... })`
 * call whose `queryKey` does not include an approved tenant scope.
 *
 * Default-deny per Codex round-1 review T2 + N5: any unknown queryKey factory
 * triggers a flag. The approved scopes are:
 *   - `tenantScopedKey([...])` — the tenantScoped helper that prefixes
 *     the active company id (lands per cluster web.tanstack-keys).
 *   - Array entry equal to the bare identifier `tenantId`, `currentCompanyId`,
 *     or member access `companyStore.currentCompanyId`.
 *   - Array starting with the literal string `'admin'` or `'super-admin'`
 *     (super-admin namespace prefix).
 *
 * Marked informational — exits 0 today but prints a violation count to stderr
 * so the per-cluster sweep can verify reductions. Master plan Section 17 step
 * 17.5 swaps the exit policy to "fail on any violation" once the tactical
 * sweep completes.
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
  'useQueries',
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

/** @type {Array<{ file: string; line: number; column: number; reason: string }>} */
const violations = [];

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

/**
 * @param {ts.Node} expr
 * @returns {boolean}
 */
function isApprovedScopeExpression(expr) {
  if (ts.isCallExpression(expr) && ts.isIdentifier(expr.expression)) {
    if (expr.expression.text === 'tenantScopedKey') return true;
  }
  if (ts.isIdentifier(expr) && APPROVED_SCOPE_IDENTIFIERS.has(expr.text)) return true;
  if (ts.isPropertyAccessExpression(expr)) {
    const last = expr.name.text;
    if (APPROVED_SCOPE_IDENTIFIERS.has(last)) return true;
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
    if (expr.expression.text === 'tenantScopedKey') return true;
  }
  if (ts.isArrayLiteralExpression(expr)) return arrayHasApprovedScope(expr);
  // `as const` assertions wrap the array.
  if (ts.isAsExpression(expr) || ts.isTypeAssertionExpression(expr)) {
    return queryKeyExpressionIsApproved(expr.expression);
  }
  // Identifier or other expression — default-deny since we can't statically
  // verify the scope without resolving types.
  return false;
}

/**
 * @param {ts.SourceFile} sourceFile
 * @param {string} filePath
 */
function visit(sourceFile, filePath) {
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

      if (name && QUERY_FACTORY_NAMES.has(name)) {
        const arg = node.arguments[0];
        if (arg && ts.isObjectLiteralExpression(arg)) {
          const queryKeyProp = arg.properties.find(
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
              violations.push({
                file: relPath,
                line: line + 1,
                column: character + 1,
                reason: `${name}({ queryKey: ... }) lacks an approved tenant scope`,
              });
            }
          }
        }
      }
    }
    ts.forEachChild(node, inner);
  }

  inner(sourceFile);
}

const fileList = await walk(SRC_ROOT);
for (const file of fileList) {
  const code = await fs.readFile(file, 'utf8');
  const sourceFile = ts.createSourceFile(
    file,
    code,
    ts.ScriptTarget.ES2022,
    true,
    /\.tsx$/.test(file) ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
  );
  visit(sourceFile, file);
}

const total = violations.length;
process.stderr.write(
  `[sweep-progress] Gate C — useQuery/useMutation queryKeys without an approved tenant scope: ${total}\n`,
);

// Master plan Section 17 step 17.5: swap the exit code below from 0 (informational)
// to (total === 0 ? 0 : 1) once the tactical sweep is complete.
process.exit(0);
